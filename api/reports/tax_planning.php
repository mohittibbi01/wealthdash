<?php
/**
 * WealthDash — Tax Planning Dashboard API
 *
 * tv001 — Old vs New Tax Regime Comparator
 * tv002 — Advance Tax Calculator (quarterly installments)
 * tv003 — Section 87A Rebate Tracker
 * tv005 — 80C Dashboard (₹1.5L utilization)
 *
 * GET /api/reports/tax_planning.php
 *   ?action=regime_compare   &income=X [&deductions JSON]
 *   ?action=advance_tax      &portfolio_id=X
 *   ?action=rebate_87a       &income=X
 *   ?action=section_80c      &portfolio_id=X
 *   ?action=full_dashboard   &portfolio_id=X &income=X  ← all in one
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

$currentUser = require_auth();

set_exception_handler(function (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
});

try {
    $db          = DB::conn();
    $action      = $_GET['action'] ?? 'full_dashboard';
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];
    $income      = (float)($_GET['income'] ?? 0);

    // Auto-pull deductions from WealthDash data
    $autoDeductions = pull_auto_deductions($db, $userId, $portfolioId);

    $response = ['success' => true, 'action' => $action, 'fy' => current_fy()];

    switch ($action) {
        case 'regime_compare':
            $manual = json_decode($_GET['deductions'] ?? '{}', true) ?: [];
            $deductions = array_merge($autoDeductions, $manual);
            $response['regime_compare'] = compare_tax_regimes($income, $deductions);
            break;

        case 'advance_tax':
            $response['advance_tax'] = calc_advance_tax($db, $userId, $portfolioId, $income);
            break;

        case 'rebate_87a':
            $response['rebate_87a'] = check_87a_rebate($income, $autoDeductions);
            break;

        case 'section_80c':
            $response['section_80c'] = calc_80c_dashboard($db, $userId, $portfolioId);
            break;

        case 'full_dashboard':
        default:
            $deductions = $autoDeductions;
            $response['auto_deductions']  = $deductions;
            $response['regime_compare']   = compare_tax_regimes($income, $deductions);
            $response['advance_tax']      = calc_advance_tax($db, $userId, $portfolioId, $income);
            $response['rebate_87a']       = check_87a_rebate($income, $deductions);
            $response['section_80c']      = calc_80c_dashboard($db, $userId, $portfolioId);
            break;
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// AUTO-PULL DEDUCTIONS FROM WEALTHDASH DATA
// ═══════════════════════════════════════════════════════════════════════════
function pull_auto_deductions(PDO $db, int $userId, int $portfolioId): array
{
    $fy     = fy_start();
    $deductions = [
        'elss_sip'       => 0,
        'elss_lumpsum'   => 0,
        'nps_80ccd1'     => 0,
        'nps_80ccd1b'    => 0,
        'epf'            => 0,
        'lic_premium'    => 0,
        'fd_interest'    => 0,
        'mf_ltcg'        => 0,
        'mf_stcg'        => 0,
        'savings_interest' => 0,
    ];

    // ELSS SIP investments this FY
    try {
        $pWhere = $portfolioId > 0 ? 'AND t.portfolio_id = ?' : 'AND p.user_id = ?';
        $pParam = $portfolioId > 0 ? $portfolioId : $userId;
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(t.value_at_cost), 0) AS total
            FROM mf_transactions t
            JOIN funds f ON f.id = t.fund_id
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.transaction_type IN ('buy','sip')
              AND t.txn_date >= ?
              AND (LOWER(f.category) LIKE '%elss%' OR LOWER(f.category) LIKE '%tax sav%')
              $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $deductions['elss_sip'] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // NPS contributions this FY (from nps_transactions if exists)
    try {
        $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
        $pParam = $portfolioId > 0 ? $portfolioId : $userId;
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(t.amount), 0) AS total
            FROM nps_transactions t
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.txn_date >= ? $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $npsTotal = (float)$stmt->fetchColumn();
        $deductions['nps_80ccd1']  = min($npsTotal, 150000); // part of 80C limit
        $deductions['nps_80ccd1b'] = min(max(0, $npsTotal - 150000), 50000); // additional
    } catch (Exception $e) {}

    // FD interest earned this FY
    try {
        $pWhere = $portfolioId > 0 ? 'AND fa.portfolio_id = ?' : 'AND p.user_id = ?';
        $pParam = $portfolioId > 0 ? $portfolioId : $userId;
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(
                ROUND(fa.principal * (fa.interest_rate/100) *
                DATEDIFF(LEAST(fa.maturity_date, CURDATE()), GREATEST(fa.start_date, ?)) / 365, 2)
            ), 0) AS total
            FROM fd_accounts fa
            JOIN portfolios p ON p.id = fa.portfolio_id
            WHERE fa.status = 'active' $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $deductions['fd_interest'] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // EPF from epf_accounts
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(employee_contribution), 0) AS total
            FROM epf_accounts WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $deductions['epf'] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    return $deductions;
}

// ═══════════════════════════════════════════════════════════════════════════
// tv001 — OLD vs NEW REGIME COMPARATOR
// ═══════════════════════════════════════════════════════════════════════════
function compare_tax_regimes(float $income, array $deductions): array
{
    if ($income <= 0) {
        return ['message' => 'Please provide your annual income (?income=XXXXXX)', 'income' => 0];
    }

    // ── OLD REGIME ────────────────────────────────────────────────────────
    $deductible80C  = min(
        ($deductions['elss_sip'] ?? 0) + ($deductions['elss_lumpsum'] ?? 0) +
        ($deductions['epf']      ?? 0) + ($deductions['lic_premium']   ?? 0) +
        ($deductions['nps_80ccd1'] ?? 0),
        150000
    );
    $deductible80CCD1B = min($deductions['nps_80ccd1b'] ?? 0, 50000);
    $deductible80D     = (float)($deductions['health_insurance'] ?? 25000); // default
    $deductible24b     = (float)($deductions['home_loan_interest'] ?? 0);
    $deductible80TTA   = min((float)($deductions['savings_interest'] ?? 0), 10000);
    $stdDeductionOld   = 50000; // salaried

    $totalDeductionOld = $stdDeductionOld + $deductible80C + $deductible80CCD1B +
                         $deductible80D + $deductible24b + $deductible80TTA;
    $taxableOld = max(0, $income - $totalDeductionOld);
    $taxOld     = calc_old_regime_tax($taxableOld);

    // ── NEW REGIME ────────────────────────────────────────────────────────
    $stdDeductionNew = 75000; // FY 2024-25 onwards
    $taxableNew  = max(0, $income - $stdDeductionNew);
    $taxNew      = calc_new_regime_tax($taxableNew);

    // ── 87A REBATE ────────────────────────────────────────────────────────
    if ($taxableOld <= 500000) $taxOld = 0;  // old: ₹5L limit
    if ($taxableNew <= 700000) $taxNew = 0;  // new: ₹7L limit (Budget 2023)

    // ── CESS (4%) ──────────────────────────────────────────────────────────
    $taxOldFinal = round($taxOld * 1.04, 0);
    $taxNewFinal = round($taxNew * 1.04, 0);

    $winner = $taxOldFinal <= $taxNewFinal ? 'old' : 'new';
    $saving = abs($taxOldFinal - $taxNewFinal);

    // Break-even deductions
    $breakEvenDeductions = calc_breakeven($income);

    return [
        'income'          => $income,
        'old_regime' => [
            'total_deductions' => round($totalDeductionOld, 0),
            'taxable_income'   => round($taxableOld, 0),
            'tax_before_cess'  => round($taxOld, 0),
            'total_tax'        => $taxOldFinal,
            'effective_rate'   => $income > 0 ? round($taxOldFinal / $income * 100, 2) : 0,
            'breakdown' => [
                'std_deduction'  => $stdDeductionOld,
                '80c'            => round($deductible80C, 0),
                '80ccd1b_nps'    => round($deductible80CCD1B, 0),
                '80d_health'     => round($deductible80D, 0),
                '24b_home_loan'  => round($deductible24b, 0),
                '80tta'          => round($deductible80TTA, 0),
            ],
        ],
        'new_regime' => [
            'total_deductions' => $stdDeductionNew,
            'taxable_income'   => round($taxableNew, 0),
            'tax_before_cess'  => round($taxNew, 0),
            'total_tax'        => $taxNewFinal,
            'effective_rate'   => $income > 0 ? round($taxNewFinal / $income * 100, 2) : 0,
            'note'             => 'Most deductions (80C, HRA, 80D) not available. Only standard deduction ₹75,000 allowed.',
        ],
        'winner'           => $winner,
        'savings'          => round($saving, 0),
        'recommendation'   => winner_text($winner, $saving, $taxOldFinal, $taxNewFinal),
        'break_even'       => $breakEvenDeductions,
        'slabs_old'        => old_regime_slabs(),
        'slabs_new'        => new_regime_slabs(),
    ];
}

function calc_old_regime_tax(float $income): float
{
    if ($income <= 250000) return 0;
    if ($income <= 500000) return ($income - 250000) * 0.05;
    if ($income <= 1000000) return 12500 + ($income - 500000) * 0.20;
    return 112500 + ($income - 1000000) * 0.30;
}

function calc_new_regime_tax(float $income): float
{
    // FY 2024-25 new regime slabs
    if ($income <= 300000)  return 0;
    if ($income <= 700000)  return ($income - 300000) * 0.05;
    if ($income <= 1000000) return 20000 + ($income - 700000) * 0.10;
    if ($income <= 1200000) return 50000 + ($income - 1000000) * 0.15;
    if ($income <= 1500000) return 80000 + ($income - 1200000) * 0.20;
    return 140000 + ($income - 1500000) * 0.30;
}

function winner_text(string $winner, float $saving, float $oldTax, float $newTax): string
{
    $savStr = '₹' . number_format((int)$saving, 0);
    if ($saving < 1000) return 'Both regimes are almost equal for your income profile. New regime is simpler.';
    return $winner === 'old'
        ? "Old regime saves you $savStr (₹{$oldTax} vs ₹{$newTax}). Claim all deductions carefully."
        : "New regime saves you $savStr (₹{$newTax} vs ₹{$oldTax}). Simpler filing, no deduction tracking needed.";
}

function calc_breakeven(float $income): array
{
    // At what total deductions does old regime = new regime?
    $stdNew    = 75000;
    $taxableNew= max(0, $income - $stdNew);
    $taxNew    = calc_new_regime_tax($taxableNew) * 1.04;

    // Binary search for breakeven deductions
    $lo = 75000; $hi = 500000;
    for ($i = 0; $i < 30; $i++) {
        $mid = ($lo + $hi) / 2;
        $taxable = max(0, $income - $mid);
        $tax = calc_old_regime_tax($taxable) * 1.04;
        if ($tax > $taxNew) $lo = $mid;
        else $hi = $mid;
    }
    $be = round(($lo + $hi) / 2, 0);
    return [
        'amount' => $be,
        'message' => "If your total old-regime deductions exceed ₹" . number_format((int)$be, 0) . ", old regime is better.",
    ];
}

function old_regime_slabs(): array {
    return [
        ['range' => 'Up to ₹2.5L',     'rate' => '0%'],
        ['range' => '₹2.5L – ₹5L',     'rate' => '5%'],
        ['range' => '₹5L – ₹10L',      'rate' => '20%'],
        ['range' => 'Above ₹10L',       'rate' => '30%'],
    ];
}
function new_regime_slabs(): array {
    return [
        ['range' => 'Up to ₹3L',        'rate' => '0%'],
        ['range' => '₹3L – ₹7L',        'rate' => '5%'],
        ['range' => '₹7L – ₹10L',       'rate' => '10%'],
        ['range' => '₹10L – ₹12L',      'rate' => '15%'],
        ['range' => '₹12L – ₹15L',      'rate' => '20%'],
        ['range' => 'Above ₹15L',        'rate' => '30%'],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// tv002 — ADVANCE TAX CALCULATOR
// ═══════════════════════════════════════════════════════════════════════════
function calc_advance_tax(PDO $db, int $userId, int $portfolioId, float $income): array
{
    $fy   = fy_start();
    $year = (int)substr($fy, 0, 4);

    // Estimate income from WealthDash sources
    $sources = [];

    // MF Capital Gains
    $pWhere = $portfolioId > 0 ? 'AND t.portfolio_id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;
    try {
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN t.gain_type='LTCG' AND t.gain_loss_amount > 0 THEN t.gain_loss_amount ELSE 0 END) AS ltcg,
                SUM(CASE WHEN t.gain_type='STCG' AND t.gain_loss_amount > 0 THEN t.gain_loss_amount ELSE 0 END) AS stcg
            FROM mf_transactions t JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.transaction_type IN ('sell','redeem') AND t.txn_date >= ? $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $gains = $stmt->fetch();
        if ($gains['ltcg'] || $gains['stcg']) {
            $sources['mf_ltcg'] = (float)($gains['ltcg'] ?? 0);
            $sources['mf_stcg'] = (float)($gains['stcg'] ?? 0);
        }
    } catch (Exception $e) {}

    // FD Interest
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(
                fa.principal * (fa.interest_rate/100) *
                DATEDIFF(LEAST(fa.maturity_date, CURDATE()), GREATEST(fa.start_date, ?)) / 365
            ), 0) AS interest
            FROM fd_accounts fa JOIN portfolios p ON p.id = fa.portfolio_id
            WHERE fa.status = 'active' $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $fdInt = (float)$stmt->fetchColumn();
        if ($fdInt > 0) $sources['fd_interest'] = $fdInt;
    } catch (Exception $e) {}

    $salary        = max(0, $income);
    $ltcg          = $sources['mf_ltcg'] ?? 0;
    $stcg          = $sources['mf_stcg'] ?? 0;
    $fdInterest    = $sources['fd_interest'] ?? 0;
    $otherIncome   = $fdInterest;

    // Tax estimate
    $taxableSalary = max(0, $salary - 75000); // new regime std deduction
    $taxOnSalary   = calc_new_regime_tax($taxableSalary) * 1.04;
    $ltcgExempt    = max(0, $ltcg - 125000);
    $taxOnLtcg     = $ltcgExempt * 0.125 * 1.04;
    $taxOnStcg     = $stcg * 0.20 * 1.04;
    $taxOnFd       = $fdInterest * 0.30 * 1.04; // assume 30% slab

    $totalTax = round($taxOnSalary + $taxOnLtcg + $taxOnStcg + $taxOnFd, 0);
    $tdsEstimate = round($salary * 0.10, 0); // rough TDS on salary

    $netTaxPayable = max(0, $totalTax - $tdsEstimate);

    // 4 installments: 15 Jun / 15 Sep / 15 Dec / 15 Mar
    $installments = [
        ['due_date' => "{$year}-06-15",     'pct' => 15, 'cumulative_pct' => 15,  'label' => 'Q1 (Jun 15)'],
        ['due_date' => "{$year}-09-15",     'pct' => 30, 'cumulative_pct' => 45,  'label' => 'Q2 (Sep 15)'],
        ['due_date' => "{$year}-12-15",     'pct' => 30, 'cumulative_pct' => 75,  'label' => 'Q3 (Dec 15)'],
        ['due_date' => ($year+1) . '-03-15','pct' => 25, 'cumulative_pct' => 100, 'label' => 'Q4 (Mar 15)'],
    ];

    $today = new DateTime();
    foreach ($installments as &$inst) {
        $due = new DateTime($inst['due_date']);
        $inst['amount']     = round($netTaxPayable * $inst['pct'] / 100, 0);
        $inst['cumulative'] = round($netTaxPayable * $inst['cumulative_pct'] / 100, 0);
        $inst['days_left']  = (int)$today->diff($due)->days * ($due > $today ? 1 : -1);
        $inst['is_past']    = $due < $today;
        $inst['is_next']    = !$inst['is_past'] && empty($nextSet);
        if ($inst['is_next']) $nextSet = true;
    }

    // 234B/234C interest (rough)
    $interest234C = $netTaxPayable > 10000 ? round($netTaxPayable * 0.01 * 3, 0) : 0;

    return [
        'fy'                 => current_fy(),
        'income_sources'     => $sources,
        'salary_income'      => $salary,
        'total_tax_estimate' => $totalTax,
        'tds_estimate'       => $tdsEstimate,
        'net_tax_payable'    => $netTaxPayable,
        'advance_tax_required' => $netTaxPayable > 10000,
        'threshold_note'     => 'Advance tax required only if total tax liability > ₹10,000',
        'installments'       => $installments,
        'penalty_234c'       => $interest234C,
        'note'               => 'These are estimates based on your WealthDash data. Consult your CA for exact figures.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// tv003 — SECTION 87A REBATE TRACKER
// ═══════════════════════════════════════════════════════════════════════════
function check_87a_rebate(float $income, array $deductions): array
{
    if ($income <= 0) {
        return ['message' => 'Provide income via ?income=XXXXXX'];
    }

    // New regime: taxable income ≤ ₹7L → zero tax
    $taxableNew = max(0, $income - 75000);
    $qualifiesNew = $taxableNew <= 700000;
    $taxNew = $qualifiesNew ? 0 : calc_new_regime_tax($taxableNew) * 1.04;

    // Old regime: taxable income ≤ ₹5L → zero tax
    $stdOld = 50000;
    $elss = min(($deductions['elss_sip'] ?? 0) + ($deductions['elss_lumpsum'] ?? 0) + ($deductions['epf'] ?? 0), 150000);
    $taxableOld = max(0, $income - $stdOld - $elss);
    $qualifiesOld = $taxableOld <= 500000;
    $taxOld = $qualifiesOld ? 0 : calc_old_regime_tax($taxableOld) * 1.04;

    // Borderline advisory
    $gapNew = max(0, $taxableNew - 700000);
    $gapOld = max(0, $taxableOld - 500000);

    $advisory = null;
    if (!$qualifiesNew && $gapNew <= 50000) {
        $advisory = "You are ₹" . number_format((int)$gapNew, 0) . " above the ₹7L new-regime rebate limit. " .
                    "Consider ₹" . number_format((int)$gapNew, 0) . " more in NPS 80CCD(1B) to reduce taxable income below ₹7L → Zero tax!";
    } elseif (!$qualifiesOld && $gapOld <= 50000) {
        $advisory = "You are ₹" . number_format((int)$gapOld, 0) . " above the ₹5L old-regime rebate limit. " .
                    "Invest ₹" . number_format((int)$gapOld, 0) . " more in ELSS/PPF/NPS to qualify for zero-tax status.";
    }

    // LTCG warning: Section 87A rebate does NOT cover special rate tax (STCG/LTCG)
    return [
        'income'               => $income,
        'new_regime' => [
            'taxable_income'    => round($taxableNew, 0),
            'qualifies_rebate'  => $qualifiesNew,
            'rebate_amount'     => $qualifiesNew ? 25000 : 0,
            'tax_after_rebate'  => round($taxNew, 0),
            'limit'             => 700000,
            'gap_to_qualify'    => $qualifiesNew ? 0 : round($gapNew, 0),
            'status_badge'      => $qualifiesNew ? '🟢 Zero Tax — 87A Rebate Applied' : '🔴 Does Not Qualify',
        ],
        'old_regime' => [
            'taxable_income'    => round($taxableOld, 0),
            'qualifies_rebate'  => $qualifiesOld,
            'rebate_amount'     => $qualifiesOld ? 12500 : 0,
            'tax_after_rebate'  => round($taxOld, 0),
            'limit'             => 500000,
            'gap_to_qualify'    => $qualifiesOld ? 0 : round($gapOld, 0),
            'status_badge'      => $qualifiesOld ? '🟢 Zero Tax — 87A Rebate Applied' : '🔴 Does Not Qualify',
        ],
        'advisory'             => $advisory,
        'ltcg_warning'         => 'Section 87A rebate does NOT apply to LTCG (Section 112A) or STCG (Section 111A). Special rate tax is payable even if total income < ₹7L.',
        'note'                 => 'FY 2024-25: New regime 87A = ₹25,000 (income ≤ ₹7L). Old regime 87A = ₹12,500 (income ≤ ₹5L).',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// tv005 — 80C DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════
function calc_80c_dashboard(PDO $db, int $userId, int $portfolioId): array
{
    $fy     = fy_start();
    $limit  = 150000;
    $fyEnd  = fy_end();

    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    $components = [];

    // ELSS SIPs this FY
    $elss = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(t.value_at_cost), 0) AS total
            FROM mf_transactions t JOIN funds f ON f.id = t.fund_id
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.transaction_type IN ('buy','sip') AND t.txn_date >= ?
              AND (LOWER(f.category) LIKE '%elss%' OR LOWER(f.category) LIKE '%tax sav%')
              $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $elss = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}
    if ($elss > 0) $components[] = ['name' => 'ELSS / Tax Saver MF', 'amount' => round($elss, 0), 'source' => 'auto', 'section' => '80C'];

    // EPF Employee Contribution
    $epf = 0;
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(employee_contribution), 0) FROM epf_accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $epf = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}
    if ($epf > 0) $components[] = ['name' => 'EPF (Employee Contribution)', 'amount' => round($epf, 0), 'source' => 'auto', 'section' => '80C'];

    // NPS 80CCD(1) — part of 80C
    $nps80c = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM nps_transactions t
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.txn_date >= ? $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $npsTotal = (float)$stmt->fetchColumn();
        $nps80c   = min($npsTotal, 150000);
    } catch (Exception $e) {}
    if ($nps80c > 0) $components[] = ['name' => 'NPS [80CCD(1)]', 'amount' => round($nps80c, 0), 'source' => 'auto', 'section' => '80C'];

    // Insurance premiums (from insurance table)
    $lic = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(annual_premium), 0) FROM insurance_policies ip
            JOIN portfolios p ON p.id = ip.portfolio_id
            WHERE ip.policy_type IN ('life','term','ulip') $pWhere
        ");
        $stmt->execute([$pParam]);
        $lic = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}
    if ($lic > 0) $components[] = ['name' => 'LIC / Life Insurance Premium', 'amount' => round($lic, 0), 'source' => 'auto', 'section' => '80C'];

    $total80C = min(array_sum(array_column($components, 'amount')), $limit);
    $remaining = max(0, $limit - $total80C);
    $pctUsed   = round($total80C / $limit * 100, 1);

    // Days left in FY
    $fyEndDt   = new DateTime($fyEnd);
    $today     = new DateTime();
    $daysLeft  = max(0, (int)$today->diff($fyEndDt)->days);

    // NPS 80CCD(1B) — additional ₹50K over 80C
    $nps1b = min(max(0, ($npsTotal ?? 0) - 150000), 50000);
    $nps1b_components = $nps1b > 0 ? [['name' => 'NPS [80CCD(1B)] — Additional', 'amount' => round($nps1b, 0), 'section' => '80CCD(1B)', 'limit' => 50000]] : [];

    return [
        'limit'           => $limit,
        'total_invested'  => round($total80C, 0),
        'remaining'       => round($remaining, 0),
        'pct_used'        => $pctUsed,
        'components'      => $components,
        'nps_80ccd1b'     => $nps1b_components,
        'fy'              => current_fy(),
        'fy_end'          => $fyEnd,
        'days_left'       => $daysLeft,
        'status'          => $pctUsed >= 100 ? 'maxed' : ($pctUsed >= 75 ? 'good' : ($pctUsed >= 50 ? 'partial' : 'low')),
        'status_badge'    => match (true) {
            $pctUsed >= 100 => '✅ 80C Maxed Out — Great!',
            $pctUsed >= 75  => '🟡 75%+ done — Add ₹' . number_format((int)$remaining, 0) . ' more',
            $remaining > 0  => '🔴 ₹' . number_format((int)$remaining, 0) . ' remaining — invest by Mar 31',
            default         => '⚪ No 80C investments tracked',
        },
        'tax_saved_at_30pct' => round($total80C * 0.30, 0),
        'alert'           => $remaining > 0 && $daysLeft <= 30
            ? "⚠️ Only {$daysLeft} days left! Invest ₹" . number_format((int)$remaining, 0) . " in ELSS/NPS/PPF to save ₹" . number_format((int)round($remaining * 0.30), 0) . " in tax."
            : null,
        'suggestions'     => $remaining > 0 ? [
            ['name' => 'ELSS MF (lock-in 3yr)', 'benefit' => 'Shortest lock-in among 80C options + market returns'],
            ['name' => 'PPF (15yr)', 'benefit' => 'Safest option, tax-free maturity, government-backed'],
            ['name' => 'NPS [80CCD(1B)]', 'benefit' => 'Additional ₹50K deduction OVER 80C limit'],
        ] : [],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════
function current_fy(): string
{
    $m = (int)date('n');
    $y = (int)date('Y');
    $fy = $m >= 4 ? $y : $y - 1;
    return "FY {$fy}-" . ($fy + 1);
}
function fy_start(): string
{
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y : $y - 1) . '-04-01';
}
function fy_end(): string
{
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y + 1 : $y) . '-03-31';
}
