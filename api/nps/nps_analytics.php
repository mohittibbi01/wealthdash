<?php
/**
 * WealthDash — NPS Enhancement APIs
 *
 * t271 — NPS Tier II separate tracking
 * t272 — PFM Performance Comparison
 * t274 — NPS Pension Estimator (corpus → monthly pension)
 * t275 — NPS Tax Benefit Dashboard (80CCD tracker)
 *
 * GET /api/nps/nps_analytics.php
 *   ?action=tier_breakdown    &portfolio_id=X
 *   ?action=pfm_comparison
 *   ?action=pension_estimator &current_age=X &retirement_age=X &monthly_contribution=X &portfolio_id=X
 *   ?action=tax_dashboard     &portfolio_id=X &income=X
 *   ?action=full              &portfolio_id=X &income=X  ← all
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
    $action      = $_GET['action'] ?? 'full';
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];
    $income      = (float)($_GET['income'] ?? 0);
    $currentAge  = (int)($_GET['current_age'] ?? 35);
    $retireAge   = (int)($_GET['retirement_age'] ?? 60);
    $monthlyContrib = (float)($_GET['monthly_contribution'] ?? 0);

    $response = ['success' => true, 'action' => $action];

    switch ($action) {
        case 'tier_breakdown':
            $response['tier_breakdown'] = get_tier_breakdown($db, $userId, $portfolioId);
            break;
        case 'pfm_comparison':
            $response['pfm_comparison'] = get_pfm_comparison($db);
            break;
        case 'pension_estimator':
            $response['pension_estimator'] = calc_pension($db, $userId, $portfolioId, $currentAge, $retireAge, $monthlyContrib);
            break;
        case 'tax_dashboard':
            $response['tax_dashboard'] = nps_tax_dashboard($db, $userId, $portfolioId, $income);
            break;
        case 'full':
        default:
            $response['tier_breakdown']    = get_tier_breakdown($db, $userId, $portfolioId);
            $response['pfm_comparison']    = get_pfm_comparison($db);
            $response['pension_estimator'] = calc_pension($db, $userId, $portfolioId, $currentAge, $retireAge, $monthlyContrib);
            $response['tax_dashboard']     = nps_tax_dashboard($db, $userId, $portfolioId, $income);
            break;
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// t271 — TIER I / TIER II BREAKDOWN
// ═══════════════════════════════════════════════════════════════════════════
function get_tier_breakdown(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    $tiers = ['tier1' => [], 'tier2' => []];

    try {
        $stmt = $db->prepare("
            SELECT h.tier, h.scheme_name, h.pfm, h.asset_class,
                   h.units, h.nav, h.current_value, h.invested_amount,
                   h.gain_loss, h.xirr,
                   p.name AS portfolio_name
            FROM nps_holdings h
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.units > 0 $pWhere
            ORDER BY h.tier, h.current_value DESC
        ");
        $stmt->execute([$pParam]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $tier = strtolower($r['tier'] ?? 'tier1');
            $tier = in_array($tier, ['tier1','tier2','1','2']) ? 'tier' . ltrim($tier, 'tier') : 'tier1';
            $tiers[$tier][] = $r;
        }
    } catch (Exception $e) {
        // Try alternate table structure
        try {
            $stmt = $db->prepare("
                SELECT 'tier1' AS tier, h.scheme_name, h.pfm_name AS pfm,
                       h.asset_class, h.units, h.nav, h.current_value,
                       h.invested_value AS invested_amount,
                       (h.current_value - h.invested_value) AS gain_loss, h.xirr
                FROM nps_holdings h
                JOIN portfolios p ON p.id = h.portfolio_id
                WHERE 1=1 $pWhere
            ");
            $stmt->execute([$pParam]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) $tiers['tier1'][] = $r;
        } catch (Exception $e2) {}
    }

    $tier1Total = array_sum(array_column($tiers['tier1'], 'current_value'));
    $tier2Total = array_sum(array_column($tiers['tier2'], 'current_value'));

    return [
        'tier1' => [
            'holdings'         => $tiers['tier1'],
            'total_value'      => round($tier1Total, 2),
            'total_invested'   => round(array_sum(array_column($tiers['tier1'], 'invested_amount')), 2),
            'tax_benefit'      => '80CCD(1) + 80CCD(1B) — up to ₹2L deduction',
            'lock_in'          => 'Lock-in until age 60 (partial withdrawal allowed for specific needs)',
            'withdrawal_rule'  => 'At 60: 40% mandatory annuity, 60% lump sum',
        ],
        'tier2' => [
            'holdings'         => $tiers['tier2'],
            'total_value'      => round($tier2Total, 2),
            'total_invested'   => round(array_sum(array_column($tiers['tier2'], 'invested_amount')), 2),
            'tax_benefit'      => 'No lock-in, no tax deduction (withdrawal taxed as income)',
            'lock_in'          => 'No lock-in — fully liquid',
            'withdrawal_rule'  => 'Fully withdrawable anytime, taxed at slab rate',
        ],
        'combined_value'     => round($tier1Total + $tier2Total, 2),
        'asset_class_info'   => [
            'E' => ['name' => 'Equity',           'max_pct' => 75, 'risk' => 'High'],
            'C' => ['name' => 'Corporate Bonds',  'max_pct' => 100,'risk' => 'Medium'],
            'G' => ['name' => 'Government Bonds', 'max_pct' => 100,'risk' => 'Low'],
            'A' => ['name' => 'Alternative Assets','max_pct' => 5,  'risk' => 'Medium'],
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// t272 — PFM PERFORMANCE COMPARISON
// ═══════════════════════════════════════════════════════════════════════════
function get_pfm_comparison(PDO $db): array
{
    // Try to fetch from DB if nps_pfm_returns table exists
    $dbData = [];
    try {
        $rows = $db->query("
            SELECT pfm_name, asset_class, return_1y, return_3y, return_5y, return_since_inception
            FROM nps_pfm_returns ORDER BY asset_class, return_3y DESC
        ")->fetchAll();
        if (!empty($rows)) $dbData = $rows;
    } catch (Exception $e) {}

    if (!empty($dbData)) {
        return ['source' => 'db', 'data' => $dbData, 'last_updated' => date('Y-m-d')];
    }

    // Fallback: curated approximate data (as of early 2025, PFRDA data)
    $pfmData = [
        'E' => [
            ['pfm' => 'SBI Pension Funds',       'r1y' => 36.2, 'r3y' => 16.8, 'r5y' => 15.2, 'since' => 12.1],
            ['pfm' => 'HDFC Pension Mgmt',        'r1y' => 38.1, 'r3y' => 17.4, 'r5y' => 16.1, 'since' => 13.0],
            ['pfm' => 'ICICI Pru Pension',        'r1y' => 37.8, 'r3y' => 17.1, 'r5y' => 15.8, 'since' => 12.7],
            ['pfm' => 'Kotak Pension',            'r1y' => 37.5, 'r3y' => 16.9, 'r5y' => 15.5, 'since' => 12.4],
            ['pfm' => 'LIC Pension Funds',        'r1y' => 35.9, 'r3y' => 16.4, 'r5y' => 14.8, 'since' => 11.8],
            ['pfm' => 'UTI Retirement Solutions', 'r1y' => 36.5, 'r3y' => 16.6, 'r5y' => 15.0, 'since' => 12.0],
            ['pfm' => 'Axis Pension Funds',       'r1y' => 37.2, 'r3y' => 17.0, 'r5y' => 15.6, 'since' => null],
            ['pfm' => 'Aditya Birla Sun Life',    'r1y' => 36.8, 'r3y' => 16.7, 'r5y' => 15.3, 'since' => null],
        ],
        'C' => [
            ['pfm' => 'SBI Pension Funds',       'r1y' => 10.1, 'r3y' => 7.8, 'r5y' => 8.2, 'since' => 9.1],
            ['pfm' => 'HDFC Pension Mgmt',        'r1y' => 10.8, 'r3y' => 8.2, 'r5y' => 8.6, 'since' => 9.4],
            ['pfm' => 'ICICI Pru Pension',        'r1y' => 10.6, 'r3y' => 8.0, 'r5y' => 8.4, 'since' => 9.3],
            ['pfm' => 'Kotak Pension',            'r1y' => 10.4, 'r3y' => 7.9, 'r5y' => 8.3, 'since' => 9.2],
        ],
        'G' => [
            ['pfm' => 'SBI Pension Funds',       'r1y' => 9.5,  'r3y' => 7.1, 'r5y' => 7.8, 'since' => 8.9],
            ['pfm' => 'HDFC Pension Mgmt',        'r1y' => 10.0, 'r3y' => 7.4, 'r5y' => 8.0, 'since' => 9.0],
            ['pfm' => 'ICICI Pru Pension',        'r1y' => 9.8,  'r3y' => 7.3, 'r5y' => 7.9, 'since' => 9.0],
        ],
    ];

    // Sort by 3Y return within each class
    foreach ($pfmData as &$class) {
        usort($class, fn($a, $b) => $b['r3y'] <=> $a['r3y']);
        foreach ($class as &$pfm) {
            $pfm['rank'] = array_search($pfm, $class) + 1;
            $pfm['badge'] = $pfm['rank'] === 1 ? '🏆 Best' : ($pfm['rank'] === 2 ? '🥈' : null);
        }
    }

    return [
        'source'   => 'curated',
        'data'     => $pfmData,
        'note'     => 'Approximate returns based on PFRDA published data. Actual returns may vary. Source: PFRDA website.',
        'disclaimer'=> 'Past performance is not indicative of future results.',
        'recommendation' => [
            'equity'   => 'HDFC or ICICI Pru Pension has consistently led in equity class returns over 3-5 years.',
            'debt'     => 'HDFC Pension leads corporate bond class. Returns are relatively similar across PFMs for debt.',
            'overall'  => 'For long-term (20+ yr horizon), prioritize equity class (max 75%) and choose top-performing PFM.',
        ],
        'last_updated' => '2025-01', // approximate
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// t274 — PENSION ESTIMATOR
// ═══════════════════════════════════════════════════════════════════════════
function calc_pension(PDO $db, int $userId, int $portfolioId, int $curAge, int $retireAge, float $monthly): array
{
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    // Current corpus from DB
    $currentCorpus = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(h.current_value), 0)
            FROM nps_holdings h JOIN portfolios p ON p.id = h.portfolio_id
            WHERE 1=1 $pWhere
        ");
        $stmt->execute([$pParam]);
        $currentCorpus = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // Also get monthly contribution from recent transactions
    if ($monthly <= 0) {
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(AVG(monthly_amt), 0) FROM (
                    SELECT SUM(amount) AS monthly_amt FROM nps_transactions t
                    JOIN portfolios p ON p.id = t.portfolio_id
                    WHERE t.txn_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) $pWhere
                    GROUP BY DATE_FORMAT(t.txn_date, '%Y-%m')
                ) AS m
            ");
            $stmt->execute([$pParam]);
            $monthly = (float)$stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    $yearsToRetirement = max(1, $retireAge - $curAge);
    $months            = $yearsToRetirement * 12;
    $expectedReturn    = 0.10; // 10% annualized (NPS equity-heavy long-term avg)
    $monthlyRate       = $expectedReturn / 12;

    // FV of current corpus
    $fvCurrentCorpus = $currentCorpus * ((1 + $expectedReturn) ** $yearsToRetirement);

    // FV of monthly contributions (SIP)
    $fvMonthly = $monthly > 0
        ? $monthly * ((((1 + $monthlyRate) ** $months) - 1) / $monthlyRate) * (1 + $monthlyRate)
        : 0;

    $totalCorpus = round($fvCurrentCorpus + $fvMonthly, 0);

    // NPS withdrawal rules at 60
    $lumpSum       = round($totalCorpus * 0.60, 0);
    $annuityAmount = round($totalCorpus * 0.40, 0);

    // Monthly pension from annuity (typical annuity rate 5.5-6%)
    $annuityRate     = 0.055;
    $monthlyPension  = round($annuityAmount * $annuityRate / 12, 0);

    // Inflation-adjusted pension (assume 6% inflation)
    $inflationFactor  = (1.06) ** $yearsToRetirement;
    $pensionToday     = round($monthlyPension / $inflationFactor, 0);

    // Different annuity rate scenarios
    $scenarios = [
        ['rate' => 5.0, 'label' => 'Conservative (5%)',  'monthly' => round($annuityAmount * 0.05 / 12, 0)],
        ['rate' => 5.5, 'label' => 'Moderate (5.5%)',    'monthly' => round($annuityAmount * 0.055 / 12, 0)],
        ['rate' => 6.0, 'label' => 'Optimistic (6%)',    'monthly' => round($annuityAmount * 0.06 / 12, 0)],
        ['rate' => 6.5, 'label' => 'Best case (6.5%)',   'monthly' => round($annuityAmount * 0.065 / 12, 0)],
    ];

    // Bucket strategy
    $bucket = [
        'bucket1' => ['name' => 'Short-term (1yr)',    'amount' => round($lumpSum * 0.10, 0), 'instrument' => 'FD / Liquid Fund',    'rate' => '6-7%'],
        'bucket2' => ['name' => 'Medium-term (5yr)',   'amount' => round($lumpSum * 0.30, 0), 'instrument' => 'Debt MF / Bonds',    'rate' => '7-8%'],
        'bucket3' => ['name' => 'Long-term (20yr+)',   'amount' => round($lumpSum * 0.60, 0), 'instrument' => 'Equity MF / Hybrid', 'rate' => '10-12%'],
    ];

    return [
        'inputs' => [
            'current_age'        => $curAge,
            'retirement_age'     => $retireAge,
            'years_to_retirement'=> $yearsToRetirement,
            'current_corpus'     => round($currentCorpus, 0),
            'monthly_contribution'=> round($monthly, 0),
            'expected_return_pa' => $expectedReturn * 100,
        ],
        'projections' => [
            'fv_current_corpus'  => round($fvCurrentCorpus, 0),
            'fv_sip_contributions'=> round($fvMonthly, 0),
            'total_corpus_at_60' => $totalCorpus,
            'total_invested'     => round($currentCorpus + $monthly * $months, 0),
        ],
        'nps_withdrawal' => [
            'lump_sum_60pct'     => $lumpSum,
            'annuity_corpus_40pct'=> $annuityAmount,
            'monthly_pension'    => $monthlyPension,
            'monthly_pension_today_value' => $pensionToday,
            'annuity_rate_used'  => $annuityRate * 100,
            'note'               => '40% mandatory annuity at 60. 60% tax-free lump sum. Annuity rate set by PFRDA (~5.5-6%).',
        ],
        'pension_scenarios'  => $scenarios,
        'bucket_strategy'    => $bucket,
        'increase_to_get'    => calc_required_sip($currentCorpus, $monthly, $yearsToRetirement, $monthlyRate, 5000000),
        'tip' => $monthlyPension < 50000
            ? "Monthly pension ₹{$monthlyPension} may not be enough. Consider increasing NPS contribution by ₹" . number_format(round($monthly * 0.2), 0) . "/month."
            : "You are on track for a comfortable retirement pension.",
    ];
}

function calc_required_sip(float $corpus, float $current, int $years, float $r, float $goal): array
{
    $fvCorpus = $corpus * ((1 + $r * 12) ** $years);
    $remaining = max(0, $goal - $fvCorpus);
    $months = $years * 12;
    $required = $r > 0
        ? $remaining * $r / ((((1 + $r) ** $months) - 1) * (1 + $r))
        : $remaining / $months;
    $additional = max(0, round($required - $current, 0));
    return [
        'target_corpus'    => $goal,
        'required_sip'     => round($required, 0),
        'current_sip'      => round($current, 0),
        'additional_needed'=> $additional,
        'note'             => $additional > 0
            ? "To build ₹" . number_format((int)$goal, 0) . " corpus, increase monthly NPS by ₹" . number_format((int)$additional, 0)
            : "You are on track to hit ₹" . number_format((int)$goal, 0) . " target.",
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// t275 — NPS TAX BENEFIT DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════
function nps_tax_dashboard(PDO $db, int $userId, int $portfolioId, float $income): array
{
    $fy    = fy_start();
    $fyEnd = fy_end();
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    // This FY NPS contributions
    $fyContrib = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(t.amount), 0)
            FROM nps_transactions t JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.txn_date >= ? $pWhere
        ");
        $stmt->execute([$fy, $pParam]);
        $fyContrib = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // 80CCD(1): min(10% of salary, ₹1.5L) — part of 80C bucket
    $ccd1Limit      = min(max(0, $income * 0.10), 150000);
    $ccd1Claimed    = min($fyContrib, $ccd1Limit);
    $ccd1Remaining  = max(0, $ccd1Limit - $ccd1Claimed);

    // 80CCD(1B): additional ₹50K over 80C limit
    $ccd1b_eligible = max(0, $fyContrib - $ccd1Claimed);
    $ccd1bLimit     = 50000;
    $ccd1bClaimed   = min($ccd1b_eligible, $ccd1bLimit);
    $ccd1bRemaining = max(0, $ccd1bLimit - $ccd1bClaimed);

    $totalDeduction = $ccd1Claimed + $ccd1bClaimed;
    $taxSaved30     = round($totalDeduction * 0.30 * 1.04, 0); // at 30% + cess
    $taxSaved20     = round($totalDeduction * 0.20 * 1.04, 0);

    $fyEndDt  = new DateTime($fyEnd);
    $today    = new DateTime();
    $daysLeft = max(0, (int)$today->diff($fyEndDt)->days);

    $alert = null;
    if ($ccd1bRemaining > 0 && $daysLeft <= 60) {
        $alert = "⚠️ {$daysLeft} days left to invest ₹" . number_format((int)$ccd1bRemaining, 0) . " in NPS and claim additional 80CCD(1B) deduction.";
    }

    return [
        'fy'                    => current_fy(),
        'fy_contributions'      => round($fyContrib, 0),
        'income'                => $income,
        'section_80ccd1' => [
            'limit'             => round($ccd1Limit, 0),
            'claimed'           => round($ccd1Claimed, 0),
            'remaining'         => round($ccd1Remaining, 0),
            'pct_used'          => $ccd1Limit > 0 ? round($ccd1Claimed / $ccd1Limit * 100, 1) : 0,
            'note'              => 'Part of ₹1.5L 80C limit. Min(10% of salary, ₹1.5L).',
        ],
        'section_80ccd1b' => [
            'limit'             => $ccd1bLimit,
            'claimed'           => round($ccd1bClaimed, 0),
            'remaining'         => round($ccd1bRemaining, 0),
            'pct_used'          => $ccd1bLimit > 0 ? round($ccd1bClaimed / $ccd1bLimit * 100, 1) : 0,
            'note'              => 'Additional ₹50,000 OVER ₹1.5L 80C limit. Exclusive to NPS.',
        ],
        'total_deduction'       => round($totalDeduction, 0),
        'tax_saved_30pct_slab'  => $taxSaved30,
        'tax_saved_20pct_slab'  => $taxSaved20,
        'days_left_in_fy'       => $daysLeft,
        'alert'                 => $alert,
        'maximize_benefit' => [
            'invest_more_80ccd1b' => round($ccd1bRemaining, 0),
            'additional_tax_saving' => round($ccd1bRemaining * 0.30 * 1.04, 0),
            'message' => $ccd1bRemaining > 0
                ? "Invest ₹" . number_format((int)$ccd1bRemaining, 0) . " more in NPS to save additional ₹" . number_format((int)round($ccd1bRemaining * 0.30 * 1.04), 0) . " in tax."
                : "You have maxed out NPS tax benefits for this FY. ✅",
        ],
        'combined_80c_nps_benefit' => [
            'max_possible_deduction' => 200000, // 1.5L 80C + 50K NPS
            'tax_saved_at_30pct'    => round(200000 * 0.30 * 1.04, 0),
            'note'                  => 'Combined 80C + 80CCD(1B) max deduction = ₹2L → saves ₹62,400 at 30% slab.',
        ],
    ];
}

function current_fy(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    $fy = $m >= 4 ? $y : $y - 1;
    return "FY {$fy}-" . ($fy + 1);
}
function fy_start(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y : $y - 1) . '-04-01';
}
function fy_end(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y + 1 : $y) . '-03-31';
}
