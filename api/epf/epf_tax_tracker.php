<?php
/**
 * WealthDash — t469: EPF Tax Tracker
 * Taxable contribution alert & interest tax calculator (Budget 2021)
 *
 * Budget 2021 Rule (effective FY2021-22):
 *  - Employee EPF+VPF contribution > ₹2,50,000/yr: interest on EXCESS is taxable (IFOS)
 *  - For government employees (no employer contribution): threshold is ₹5,00,000/yr
 *  - Employer contribution > ₹7,50,000/yr (EPF+NPS+Gratuity combined): perquisite tax
 *  - Tax deducted at source at time of interest credit by EPFO (notified but largely self-declare)
 *
 * Actions (read — CSRF exempt):
 *   epf_tax_summary         — current FY tax exposure across all EPF accounts
 *   epf_tax_fy_detail       — detailed FY tax breakdown for one account
 *   epf_tax_projection      — project tax for rest of FY based on current salary + VPF
 *   epf_tax_log_list        — past FY tax log entries
 *
 * Actions (write — CSRF required):
 *   epf_tax_log_save        — save FY tax record (for ITR filing)
 *   epf_tax_log_delete      — remove a log entry
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'epf_tax_summary';

// ── CONSTANTS ──────────────────────────────────────────────────────────────
const ETAX_THRESHOLD_NORMAL = 250000;  // ₹2.5L — private/corporate employees
const ETAX_THRESHOLD_GOVT   = 500000;  // ₹5.0L — government employees (no employer contribution)
const ETAX_ER_THRESHOLD     = 750000;  // ₹7.5L — employer contribution perquisite threshold
const ETAX_EPF_RATE         = 8.25;    // FY2024-25

// ── HELPERS ────────────────────────────────────────────────────────────────

function _etax_fy(string $fy = ''): array {
    if ($fy && preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
        return [$m[1] . '-04-01', $m[2] . '-03-31', $fy, (int)$m[1]];
    }
    $yr  = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    $lbl = $yr . '-' . ($yr + 1);
    return [$yr . '-04-01', ($yr + 1) . '-03-31', $lbl, $yr];
}

function _etax_account_owned(int $userId, int $accountId): ?array {
    return DB::fetchOne(
        "SELECT ea.* FROM epf_accounts ea
         JOIN portfolios p ON p.id = ea.portfolio_id
         WHERE ea.id=? AND p.user_id=?",
        [$accountId, $userId]
    ) ?: null;
}

/**
 * Core tax calculation
 * Returns detailed tax breakdown for given FY contributions and balance
 */
function _etax_calc(
    float $annualEeContrib,
    float $annualErContrib,
    float $openingBalance,
    float $interestRate,
    bool  $isGovt = false
): array {
    $threshold = $isGovt ? ETAX_THRESHOLD_GOVT : ETAX_THRESHOLD_NORMAL;

    // Split employee contribution into taxable/non-taxable portion
    $taxableEeExcess    = max(0, $annualEeContrib - $threshold);
    $nonTaxableEeContrib = min($annualEeContrib, $threshold);

    // Proportion of balance that is "taxable bucket" vs "non-taxable bucket"
    // Simplified: calculate interest on full corpus, then apply ratio
    $totalAnnualCredit   = $annualEeContrib + $annualErContrib;
    $midYearBalance      = $openingBalance + $totalAnnualCredit / 2;
    $estimatedInterest   = round($midYearBalance * $interestRate / 100, 2);

    // Interest on excess portion (proportional)
    $taxableInterest = 0;
    if ($taxableEeExcess > 0 && $annualEeContrib > 0) {
        $excessRatio     = $taxableEeExcess / $annualEeContrib;
        $taxableInterest = round($estimatedInterest * $excessRatio, 2);
    }

    // Employer contribution perquisite check
    $erPerquisite = max(0, $annualErContrib - ETAX_ER_THRESHOLD);

    // Estimated taxes (approximate — actual slab depends on total income)
    $taxAt20   = round($taxableInterest * 0.20, 2);
    $taxAt30   = round($taxableInterest * 0.30, 2);

    $hasAlert = $taxableEeExcess > 0 || $erPerquisite > 0;

    return [
        'threshold'              => $threshold,
        'is_govt_employee'       => $isGovt,
        'annual_ee_contribution' => round($annualEeContrib, 2),
        'annual_er_contribution' => round($annualErContrib, 2),
        'non_taxable_ee_contrib' => round($nonTaxableEeContrib, 2),
        'taxable_ee_excess'      => round($taxableEeExcess, 2),
        'opening_balance'        => round($openingBalance, 2),
        'estimated_total_interest'=> $estimatedInterest,
        'taxable_interest'       => $taxableInterest,
        'non_taxable_interest'   => round($estimatedInterest - $taxableInterest, 2),
        'er_perquisite_excess'   => round($erPerquisite, 2),
        'has_er_perquisite_tax'  => $erPerquisite > 0,
        'estimated_tax_20pct'    => $taxAt20,
        'estimated_tax_30pct'    => $taxAt30,
        'has_tax_alert'          => $hasAlert,
        'alert_level'            => $taxableEeExcess > 100000 ? 'high' : ($taxableEeExcess > 0 ? 'medium' : 'none'),
    ];
}

// ── ACTIONS ────────────────────────────────────────────────────────────────

switch ($action) {

    // ── epf_tax_summary ────────────────────────────────────────────────────
    case 'epf_tax_summary': {
        $fy = clean($_GET['fy'] ?? '');
        [$fyStart, $fyEnd, $fyLabel, $fyYear] = _etax_fy($fy);

        $accounts = DB::fetchAll(
            "SELECT ea.*, p.name AS portfolio_name
             FROM epf_accounts ea
             JOIN portfolios p ON p.id = ea.portfolio_id
             WHERE p.user_id=? AND ea.is_active=1
             ORDER BY ea.employer_name ASC",
            [$userId]
        );

        $totalTaxableInterest = 0;
        $totalTaxableExcess   = 0;
        $anyAlert             = false;
        $items                = [];

        foreach ($accounts as $a) {
            $isGovt = (bool)($a['is_govt_employee'] ?? 0);

            // Get actual contributions from monthly log if available
            $logData = DB::fetchRow(
                "SELECT
                   COALESCE(SUM(employee_contribution + vpf_contribution), 0) AS ee_total,
                   COALESCE(SUM(employer_contribution), 0) AS er_total
                 FROM epf_monthly_log
                 WHERE epf_account_id=? AND log_month BETWEEN ? AND ?",
                [$a['id'], $fyStart, $fyEnd]
            );

            // Fall back to annualised monthly from account fields
            $annualEe = (float)($logData['ee_total'] ?? 0);
            $annualEr = (float)($logData['er_total'] ?? 0);

            if ($annualEe == 0) {
                // Estimate from account fields
                $mo    = (float)($a['employee_contribution'] ?? 0) + (float)($a['vpf_rate'] ?? 0) * (float)($a['basic_salary'] ?? 0) / 100;
                $annualEe = $mo * 12;
                $annualEr = (float)($a['employer_contribution'] ?? 0) * 12;
            }

            $openingBal = (float)$a['current_balance'];
            $rate       = (float)($a['interest_rate'] ?? ETAX_EPF_RATE);

            $calc = _etax_calc($annualEe, $annualEr, $openingBal, $rate, $isGovt);

            // Check saved log
            $savedLog = DB::fetchOne(
                "SELECT * FROM epf_tax_log WHERE epf_account_id=? AND fy_year=?",
                [$a['id'], $fyYear]
            );

            $totalTaxableInterest += $calc['taxable_interest'];
            $totalTaxableExcess   += $calc['taxable_ee_excess'];
            if ($calc['has_tax_alert']) $anyAlert = true;

            $items[] = array_merge($calc, [
                'account_id'    => $a['id'],
                'employer_name' => $a['employer_name'],
                'uan'           => $a['uan'],
                'portfolio_name'=> $a['portfolio_name'],
                'saved_log'     => $savedLog,
                'data_source'   => $logData['ee_total'] > 0 ? 'monthly_log' : 'estimated',
            ]);
        }

        json_response(true, '', [
            'fy'                         => $fyLabel,
            'fy_start'                   => $fyStart,
            'fy_end'                     => $fyEnd,
            'accounts'                   => $items,
            'total_taxable_interest'     => round($totalTaxableInterest, 2),
            'total_taxable_ee_excess'    => round($totalTaxableExcess, 2),
            'any_tax_alert'              => $anyAlert,
            'itr_section'                => 'Schedule OS → Interest on EPF (taxable portion)',
            'tax_rule_summary' => [
                'rule'            => 'Budget 2021 — effective FY2021-22',
                'threshold'       => '₹2,50,000/yr employee contribution (EPF+VPF combined)',
                'govt_threshold'  => '₹5,00,000/yr for government employees',
                'er_threshold'    => '₹7,50,000/yr employer contribution (EPF+NPS+Gratuity)',
                'tax_section'     => 'Section 17(2)(via) — EPF interest taxable as salary/IFOS',
                'note'            => 'EPFO ne do separate PF accounts maintain karna shuru kiya — taxable bucket alag track hota hai',
            ],
        ]);
    }

    // ── epf_tax_fy_detail ──────────────────────────────────────────────────
    case 'epf_tax_fy_detail': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $fy        = clean($_GET['fy'] ?? '');

        if (!$accountId) json_response(false, 'account_id required.');
        $account = _etax_account_owned($userId, $accountId);
        if (!$account) json_response(false, 'Account not found.', [], 404);

        [$fyStart, $fyEnd, $fyLabel, $fyYear] = _etax_fy($fy);
        $isGovt = (bool)($account['is_govt_employee'] ?? 0);

        // Monthly breakdown
        $months = DB::fetchAll(
            "SELECT log_month, basic_salary, employee_contribution, employer_contribution,
                    vpf_contribution, eps_contribution, total_credit, balance_after, interest_credited
             FROM epf_monthly_log
             WHERE epf_account_id=? AND log_month BETWEEN ? AND ?
             ORDER BY log_month ASC",
            [$accountId, $fyStart, $fyEnd]
        );

        // Running cumulative for monthly tax tracking
        $cumulativeEe = 0;
        $threshold    = $isGovt ? ETAX_THRESHOLD_GOVT : ETAX_THRESHOLD_NORMAL;
        foreach ($months as &$m) {
            $monthlyEe    = (float)$m['employee_contribution'] + (float)$m['vpf_contribution'];
            $cumulativeEe += $monthlyEe;
            $monthExcess   = max(0, $cumulativeEe - $threshold);
            $prevExcess    = max(0, ($cumulativeEe - $monthlyEe) - $threshold);
            $m['cumulative_ee']       = round($cumulativeEe, 2);
            $m['monthly_taxable_ee']  = round($monthExcess - $prevExcess, 2);
            $m['over_threshold']      = $cumulativeEe > $threshold;
            $m['month_label']         = date('M Y', strtotime($m['log_month']));
        }
        unset($m);

        $annualEe = array_sum(array_column($months, 'employee_contribution'))
                  + array_sum(array_column($months, 'vpf_contribution'));
        $annualEr = array_sum(array_column($months, 'employer_contribution'));

        $openBal = (float)$account['current_balance'];
        $rate    = (float)($account['interest_rate'] ?? ETAX_EPF_RATE);
        $calc    = _etax_calc($annualEe ?: (float)($account['employee_contribution'] ?? 0) * 12,
                              $annualEr ?: (float)($account['employer_contribution'] ?? 0) * 12,
                              $openBal, $rate, $isGovt);

        // Saved log for this FY
        $savedLog = DB::fetchOne(
            "SELECT * FROM epf_tax_log WHERE epf_account_id=? AND fy_year=?",
            [$accountId, $fyYear]
        );

        json_response(true, '', [
            'account_id'    => $accountId,
            'employer_name' => $account['employer_name'],
            'uan'           => $account['uan'],
            'fy'            => $fyLabel,
            'is_govt'       => $isGovt,
            'threshold'     => $threshold,
            'monthly_breakdown' => $months,
            'tax_calc'      => $calc,
            'saved_log'     => $savedLog,
            'itr_instructions' => [
                'step1' => 'Total taxable interest = ₹' . number_format($calc['taxable_interest'], 2),
                'step2' => 'ITR-2 → Schedule OS → Interest income from EPF (taxable bucket)',
                'step3' => 'Slab rate lagega — koi special rate nahi',
                'step4' => 'Form 26AS mein check karo — EPFO TDS deducted hoga kya',
                'step5' => 'Employee contribution > ₹2.5L exceeded in: '
                         . ($calc['taxable_ee_excess'] > 0 ? 'YES' : 'NO'),
            ],
        ]);
    }

    // ── epf_tax_projection ─────────────────────────────────────────────────
    case 'epf_tax_projection': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $basic     = (float)($_GET['basic']    ?? 0);
        $vpfRate   = (float)($_GET['vpf_rate'] ?? 0);
        $isGovt    = (bool)($_GET['is_govt']   ?? 0);
        $balance   = (float)($_GET['balance']  ?? 0);
        $rate      = (float)($_GET['rate']     ?? ETAX_EPF_RATE);

        // If account_id given, use its data as defaults
        if ($accountId) {
            $account = _etax_account_owned($userId, $accountId);
            if ($account) {
                $basic     = $basic    ?: (float)$account['basic_salary'];
                $vpfRate   = $vpfRate  ?: (float)($account['vpf_rate'] ?? 0);
                $isGovt    = (bool)($account['is_govt_employee'] ?? 0);
                $balance   = $balance  ?: (float)$account['current_balance'];
                $rate      = $rate     ?: (float)($account['interest_rate'] ?? ETAX_EPF_RATE);
            }
        }

        if ($basic <= 0 && $balance <= 0) json_response(false, 'basic salary or account_id required.');

        $eeMonthly   = round($basic * 12 / 100, 2);
        $vpfMonthly  = round($basic * $vpfRate / 100, 2);
        $erMonthly   = round($basic * 3.67 / 100, 2);
        $annualEe    = ($eeMonthly + $vpfMonthly) * 12;
        $annualEr    = $erMonthly * 12;
        $threshold   = $isGovt ? ETAX_THRESHOLD_GOVT : ETAX_THRESHOLD_NORMAL;

        // Month-by-month simulation for current FY
        [$fyStart, $fyEnd, $fyLabel] = _etax_fy();
        $today    = date('Y-m-01');
        $monthsLeft = max(0, (int)floor((strtotime($fyEnd) - strtotime($today)) / (86400 * 30)));
        $monthsDone = 12 - $monthsLeft;

        $calc = _etax_calc($annualEe, $annualEr, $balance, $rate, $isGovt);

        // VPF reduction suggestion: how much to cut VPF to stay under threshold
        $safeVpfAnnual  = max(0, $threshold - $eeMonthly * 12);
        $safeVpfMonthly = round($safeVpfAnnual / 12, 2);
        $suggestedVpfPct = $basic > 0 ? round($safeVpfMonthly / $basic * 100, 2) : 0;

        json_response(true, '', [
            'fy'                => $fyLabel,
            'basic'             => $basic,
            'vpf_rate'          => $vpfRate,
            'is_govt'           => $isGovt,
            'threshold'         => $threshold,
            'annual_ee_total'   => round($annualEe, 2),
            'annual_er_total'   => round($annualEr, 2),
            'months_done'       => $monthsDone,
            'months_left'       => $monthsLeft,
            'tax_calc'          => $calc,
            'vpf_reduction_advice' => [
                'current_vpf_pct'    => $vpfRate,
                'current_vpf_annual' => round($vpfMonthly * 12, 2),
                'safe_total_ee_annual' => $threshold,
                'max_safe_vpf_monthly' => $safeVpfMonthly,
                'suggested_vpf_pct'    => $suggestedVpfPct,
                'action'            => $calc['taxable_ee_excess'] > 0
                    ? "VPF {$vpfRate}% se reduce karo to {$suggestedVpfPct}% — tax bacha sakte ho ₹"
                      . number_format($calc['estimated_tax_20pct']) . " to ₹"
                      . number_format($calc['estimated_tax_30pct'])
                    : "VPF contribution threshold ke andar hai — koi tax issue nahi",
            ],
            'alternative' => [
                'nps_80ccd1b' => 'NPS mein ₹50,000 extra dalo (80CCD1B) — VPF se zyada tax efficient hai post-threshold',
                'ppf_top_up'  => 'PPF ₹1.5L tak tax-free return milta hai — consider karo',
            ],
        ]);
    }

    // ── epf_tax_log_list ───────────────────────────────────────────────────
    case 'epf_tax_log_list': {
        $accountId = (int)($_GET['account_id'] ?? 0);

        $where  = $accountId ? "AND etl.epf_account_id=?" : "";
        $params = $accountId
            ? [$userId, $accountId]
            : [$userId];

        $logs = DB::fetchAll(
            "SELECT etl.*, ea.employer_name, ea.uan
             FROM epf_tax_log etl
             JOIN epf_accounts ea ON ea.id = etl.epf_account_id
             JOIN portfolios p    ON p.id  = ea.portfolio_id
             WHERE p.user_id=? {$where}
             ORDER BY etl.fy_year DESC, etl.epf_account_id ASC",
            $params
        );

        foreach ($logs as &$l) {
            $l['fy_label'] = $l['fy_year'] . '-' . substr((string)($l['fy_year'] + 1), 2);
        }
        unset($l);

        json_response(true, '', ['logs' => $logs, 'count' => count($logs)]);
    }

    // ── epf_tax_log_save ───────────────────────────────────────────────────
    case 'epf_tax_log_save': {
        require_csrf();

        $accountId   = (int)($_POST['account_id']           ?? 0);
        $fyYear      = (int)($_POST['fy_year']              ?? 0);
        $annualEe    = (float)($_POST['annual_ee_contribution'] ?? 0);
        $annualEr    = (float)($_POST['annual_er_contribution'] ?? 0);
        $threshold   = (float)($_POST['threshold']          ?? ETAX_THRESHOLD_NORMAL);
        $taxableExcess = (float)($_POST['taxable_ee_excess'] ?? 0);
        $epfInterest = (float)($_POST['epf_interest_fy']    ?? 0);
        $taxableInt  = (float)($_POST['taxable_interest']   ?? 0);
        $estTax      = (float)($_POST['estimated_tax_30pct'] ?? 0);
        $isGovt      = (int)(bool)($_POST['is_govt_employee'] ?? 0);
        $notes       = substr(clean($_POST['notes'] ?? ''), 0, 255);

        if (!$accountId || !$fyYear) json_response(false, 'account_id and fy_year required.');
        if (!_etax_account_owned($userId, $accountId)) json_response(false, 'Account not found.', [], 404);

        DB::run(
            "INSERT INTO epf_tax_log
               (epf_account_id, fy_year, annual_ee_contribution, annual_er_contribution,
                threshold_ee, taxable_ee_excess, epf_interest_fy, taxable_interest,
                estimated_tax_30pct, is_govt_employee, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               annual_ee_contribution=VALUES(annual_ee_contribution),
               annual_er_contribution=VALUES(annual_er_contribution),
               threshold_ee=VALUES(threshold_ee),
               taxable_ee_excess=VALUES(taxable_ee_excess),
               epf_interest_fy=VALUES(epf_interest_fy),
               taxable_interest=VALUES(taxable_interest),
               estimated_tax_30pct=VALUES(estimated_tax_30pct),
               is_govt_employee=VALUES(is_govt_employee),
               notes=VALUES(notes)",
            [$accountId, $fyYear, $annualEe, $annualEr, $threshold,
             $taxableExcess, $epfInterest, $taxableInt, $estTax, $isGovt, $notes ?: null]
        );

        json_response(true, 'Tax log saved ✅', ['account_id' => $accountId, 'fy_year' => $fyYear]);
    }

    // ── epf_tax_log_delete ─────────────────────────────────────────────────
    case 'epf_tax_log_delete': {
        require_csrf();

        $logId = (int)($_POST['log_id'] ?? 0);
        if (!$logId) json_response(false, 'log_id required.');

        $deleted = DB::run(
            "DELETE etl FROM epf_tax_log etl
             JOIN epf_accounts ea ON ea.id = etl.epf_account_id
             JOIN portfolios p    ON p.id  = ea.portfolio_id
             WHERE etl.id=? AND p.user_id=?",
            [$logId, $userId]
        );

        json_response((bool)$deleted, $deleted ? 'Log deleted.' : 'Log not found.');
    }

    default:
        json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);
}
