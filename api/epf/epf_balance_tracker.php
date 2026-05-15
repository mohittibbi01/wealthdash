<?php
/**
 * WealthDash — t467: EPF Balance Tracker
 * Monthly contribution log + balance history + FY summary
 *
 * Actions (read — CSRF exempt):
 *   epf_balance_summary     — all EPF accounts with current balances + FY stats
 *   epf_monthly_log_list    — month-wise contribution log for one account
 *   epf_fy_summary          — FY-wise contribution + interest summary
 *   epf_balance_history     — balance history chart data
 *   epf_contribution_calc   — compute employee/employer/EPS breakdown for a salary
 *
 * Actions (write — CSRF required):
 *   epf_monthly_log_save    — add/update one month's contribution entry
 *   epf_monthly_log_delete  — remove a month entry
 *   epf_fy_snapshot_save    — save FY opening/closing balance snapshot
 *   epf_balance_update      — update current EPF balance (quick update)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'epf_balance_summary';

// ── CONSTANTS ──────────────────────────────────────────────────────────────
const EPF_RATE_CURRENT    = 8.25;   // FY2024-25
const EPF_EE_PCT          = 12.0;   // Employee: 12% of basic
const EPF_ER_EPF_PCT      = 3.67;   // Employer EPF portion
const EPF_ER_EPS_PCT      = 8.33;   // Employer EPS portion (max ₹15K base)
const EPF_EPS_MAX_BASE    = 15000;  // EPS salary ceiling
const EPF_TAX_THRESHOLD   = 250000; // Budget 2021: ee contrib > ₹2.5L → interest taxable
const EPF_GOVT_THRESHOLD  = 500000; // Govt employee threshold

// Historical EPFO rates for interest projections
const EPF_HISTORICAL_RATES = [
    '2019-20' => 8.50, '2020-21' => 8.50, '2021-22' => 8.10,
    '2022-23' => 8.15, '2023-24' => 8.25, '2024-25' => 8.25,
];

// ── HELPERS ────────────────────────────────────────────────────────────────

function _epf_fy(string $date = ''): array {
    $ts  = $date ? strtotime($date) : time();
    $yr  = (int)date('Y', $ts);
    $mo  = (int)date('n', $ts);
    $fys = $mo >= 4 ? $yr : $yr - 1;
    return [
        'fy_year'  => $fys,
        'fy_start' => $fys . '-04-01',
        'fy_end'   => ($fys + 1) . '-03-31',
        'fy_label' => $fys . '-' . substr((string)($fys + 1), 2),
    ];
}

function _epf_owned(int $userId, int $accountId): ?array {
    return DB::fetchOne(
        "SELECT ea.* FROM epf_accounts ea
         JOIN portfolios p ON p.id = ea.portfolio_id
         WHERE ea.id=? AND p.user_id=?",
        [$accountId, $userId]
    ) ?: null;
}

function _epf_accounts(int $userId): array {
    return DB::fetchAll(
        "SELECT ea.*, p.name AS portfolio_name,
                TIMESTAMPDIFF(YEAR, ea.joining_date, CURDATE()) AS years_of_service
         FROM epf_accounts ea
         JOIN portfolios p ON p.id = ea.portfolio_id
         WHERE p.user_id=? AND ea.is_active=1
         ORDER BY ea.joining_date ASC",
        [$userId]
    );
}

/**
 * Compute EPF breakdown from basic salary
 */
function _epf_calc(float $basic, float $vpfPct = 0): array {
    $eeEpf  = round($basic * EPF_EE_PCT / 100, 2);
    $vpf    = round($basic * $vpfPct / 100, 2);
    $epsBase = min($basic, EPF_EPS_MAX_BASE);
    $eps    = round($epsBase * EPF_ER_EPS_PCT / 100, 2);
    $erEpf  = round($basic * EPF_ER_EPF_PCT / 100, 2);
    return [
        'employee_epf'        => $eeEpf,
        'vpf'                 => $vpf,
        'total_employee'      => round($eeEpf + $vpf, 2),
        'employer_epf'        => $erEpf,
        'eps'                 => $eps,
        'total_employer'      => round($erEpf + $eps, 2),
        'total_credit'        => round($eeEpf + $vpf + $erEpf, 2), // EPS goes to pension fund, not EPF balance
        'annual_ee_total'     => round(($eeEpf + $vpf) * 12, 2),
        'annual_er_epf'       => round($erEpf * 12, 2),
        'tax_alert'           => ($eeEpf + $vpf) * 12 > EPF_TAX_THRESHOLD,
    ];
}

// ── ACTIONS ────────────────────────────────────────────────────────────────

switch ($action) {

    // ── epf_balance_summary ────────────────────────────────────────────────
    case 'epf_balance_summary': {
        $accounts = _epf_accounts($userId);
        $fy       = _epf_fy();

        $totalBalance     = 0;
        $totalEpsBalance  = 0;
        $totalMonthly     = 0;
        $totalAnnualEe    = 0;

        foreach ($accounts as &$a) {
            $basic   = (float)$a['basic_salary'];
            $vpfRate = (float)($a['vpf_rate'] ?? 0);
            $calc    = _epf_calc($basic, $vpfRate);

            $a['calc']             = $calc;
            $a['interest_rate']    = (float)($a['interest_rate'] ?? EPF_RATE_CURRENT);
            $a['est_annual_int']   = round((float)$a['current_balance'] * $a['interest_rate'] / 100, 2);
            $a['est_monthly_int']  = round($a['est_annual_int'] / 12, 2);

            // FY contribution from log
            $fyContrib = DB::fetchRow(
                "SELECT
                   COALESCE(SUM(employee_contribution),0) AS ee_total,
                   COALESCE(SUM(employer_contribution),0) AS er_total,
                   COALESCE(SUM(vpf_contribution),0)      AS vpf_total,
                   COALESCE(SUM(eps_contribution),0)      AS eps_total,
                   COALESCE(SUM(interest_credited),0)     AS interest_total,
                   COUNT(*) AS months_logged
                 FROM epf_monthly_log
                 WHERE epf_account_id=? AND log_month BETWEEN ? AND ?",
                [$a['id'], $fy['fy_start'], $fy['fy_end']]
            );

            $a['fy'] = array_merge($fy, [
                'ee_contributed'   => (float)($fyContrib['ee_total'] ?? 0),
                'er_contributed'   => (float)($fyContrib['er_total'] ?? 0),
                'vpf_contributed'  => (float)($fyContrib['vpf_total'] ?? 0),
                'eps_contributed'  => (float)($fyContrib['eps_total'] ?? 0),
                'interest_credited'=> (float)($fyContrib['interest_total'] ?? 0),
                'months_logged'    => (int)($fyContrib['months_logged'] ?? 0),
                'months_remaining' => max(0, 12 - (int)($fyContrib['months_logged'] ?? 0)),
            ]);

            $annualEe = (float)($fyContrib['ee_total'] ?? 0) + (float)($fyContrib['vpf_total'] ?? 0);
            $a['tax_alert']      = $annualEe > EPF_TAX_THRESHOLD;
            $a['tax_alert_pct']  = min(100, round($annualEe / EPF_TAX_THRESHOLD * 100, 1));
            $a['years_of_service'] = max(0, (int)($a['years_of_service'] ?? 0));

            // EPS pension estimate
            $serviceYrs  = max(1, (int)$a['years_of_service']);
            $a['eps_pension_estimate'] = round(
                min((float)$basic, EPF_EPS_MAX_BASE) * $serviceYrs / 70
            );

            // Tax-free withdrawal: >5 years service
            $a['tax_free_withdrawal'] = $a['years_of_service'] >= 5;

            $totalBalance    += (float)$a['current_balance'];
            $totalEpsBalance += (float)$a['eps_balance'];
            $totalMonthly    += $calc['total_credit'];
            $totalAnnualEe   += $calc['annual_ee_total'];
        }
        unset($a);

        json_response(true, '', [
            'accounts'    => $accounts,
            'count'       => count($accounts),
            'summary' => [
                'total_epf_balance'   => round($totalBalance, 2),
                'total_eps_balance'   => round($totalEpsBalance, 2),
                'total_balance'       => round($totalBalance + $totalEpsBalance, 2),
                'total_monthly_credit'=> round($totalMonthly, 2),
                'annual_ee_contrib'   => round($totalAnnualEe, 2),
                'tax_threshold'       => EPF_TAX_THRESHOLD,
                'tax_alert_overall'   => $totalAnnualEe > EPF_TAX_THRESHOLD,
                'current_rate'        => EPF_RATE_CURRENT,
            ],
            'current_fy' => $fy,
            'epf_rules' => [
                'ee_pct'            => '12% of basic',
                'er_epf_pct'        => '3.67% of basic (EPF)',
                'er_eps_pct'        => '8.33% of basic (EPS, max ₹15K base)',
                'vpf'               => 'VPF over 12% optional — same EPF interest rate',
                'tax_threshold'     => '₹2.5L/yr employee contribution cross karo to interest taxable',
                'tax_free_exit'     => '5+ years service pe withdrawal tax-free',
                'epfo_interest'     => 'Interest compound nahi hota — annual credit on Apr 1',
            ],
        ]);
    }

    // ── epf_monthly_log_list ───────────────────────────────────────────────
    case 'epf_monthly_log_list': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $fromMonth = clean($_GET['from'] ?? date('Y-01-01'));
        $toMonth   = clean($_GET['to']   ?? date('Y-12-31'));
        $fy        = clean($_GET['fy']   ?? '');

        if (!$accountId) json_response(false, 'account_id required.');
        if (!_epf_owned($userId, $accountId)) json_response(false, 'Account not found.', [], 404);

        // FY override
        if ($fy && preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
            $fromMonth = $m[1] . '-04-01';
            $toMonth   = $m[2] . '-03-31';
        }

        $logs = DB::fetchAll(
            "SELECT * FROM epf_monthly_log
             WHERE epf_account_id=? AND log_month BETWEEN ? AND ?
             ORDER BY log_month ASC",
            [$accountId, $fromMonth, $toMonth]
        );

        foreach ($logs as &$l) {
            $l['month_label'] = date('M Y', strtotime($l['log_month']));
            $l['net_credit']  = round(
                (float)$l['employee_contribution'] + (float)$l['employer_contribution'] + (float)$l['vpf_contribution'],
                2
            );
        }
        unset($l);

        // Totals
        $totals = [
            'employee'  => array_sum(array_column($logs, 'employee_contribution')),
            'employer'  => array_sum(array_column($logs, 'employer_contribution')),
            'eps'       => array_sum(array_column($logs, 'eps_contribution')),
            'vpf'       => array_sum(array_column($logs, 'vpf_contribution')),
            'interest'  => array_sum(array_column($logs, 'interest_credited')),
            'months'    => count($logs),
        ];
        $totals['total_credit'] = round($totals['employee'] + $totals['employer'] + $totals['vpf'], 2);

        json_response(true, '', [
            'account_id' => $accountId,
            'from'       => $fromMonth,
            'to'         => $toMonth,
            'logs'       => $logs,
            'totals'     => array_map('round_2', $totals),
        ]);
    }

    // ── epf_fy_summary ─────────────────────────────────────────────────────
    case 'epf_fy_summary': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $years     = min(10, max(1, (int)($_GET['years'] ?? 5)));

        if (!$accountId) json_response(false, 'account_id required.');
        $account = _epf_owned($userId, $accountId);
        if (!$account) json_response(false, 'Account not found.', [], 404);

        // FY snapshots from table
        $snaps = DB::fetchAll(
            "SELECT * FROM epf_fy_snapshot WHERE epf_account_id=? ORDER BY fy_year DESC LIMIT ?",
            [$accountId, $years]
        );

        // Also compute from monthly log for FYs not in snapshots
        $fyData = [];
        $currentFY = _epf_fy();

        for ($i = 0; $i < $years; $i++) {
            $fyYear  = $currentFY['fy_year'] - $i;
            $fyStart = $fyYear . '-04-01';
            $fyEnd   = ($fyYear + 1) . '-03-31';
            $fyLabel = $fyYear . '-' . substr((string)($fyYear + 1), 2);

            // Check snapshot first
            $snap = array_values(array_filter($snaps, fn($s) => $s['fy_year'] == $fyYear));
            if ($snap) {
                $s = $snap[0];
                $fyData[] = [
                    'fy_year'          => $fyYear,
                    'fy_label'         => $fyLabel,
                    'opening_balance'  => (float)$s['opening_balance'],
                    'closing_balance'  => (float)$s['closing_balance'],
                    'ee_contribution'  => (float)$s['total_ee_contrib'],
                    'er_contribution'  => (float)$s['total_er_contrib'],
                    'vpf_contribution' => (float)$s['total_vpf'],
                    'interest_credited'=> (float)$s['interest_credited'],
                    'source'           => 'snapshot',
                ];
            } else {
                // Compute from monthly log
                $row = DB::fetchRow(
                    "SELECT
                       COALESCE(SUM(employee_contribution),0) AS ee,
                       COALESCE(SUM(employer_contribution),0) AS er,
                       COALESCE(SUM(vpf_contribution),0)      AS vpf,
                       COALESCE(SUM(eps_contribution),0)      AS eps,
                       COALESCE(SUM(interest_credited),0)     AS interest,
                       COALESCE(MAX(balance_after),0)         AS closing
                     FROM epf_monthly_log
                     WHERE epf_account_id=? AND log_month BETWEEN ? AND ?",
                    [$accountId, $fyStart, $fyEnd]
                );
                if ($row) {
                    $fyData[] = [
                        'fy_year'          => $fyYear,
                        'fy_label'         => $fyLabel,
                        'opening_balance'  => null,
                        'closing_balance'  => (float)($row['closing'] ?? 0) ?: (float)$account['current_balance'],
                        'ee_contribution'  => (float)$row['ee'],
                        'er_contribution'  => (float)$row['er'],
                        'vpf_contribution' => (float)$row['vpf'],
                        'interest_credited'=> (float)$row['interest'],
                        'source'           => 'log',
                    ];
                }
            }
        }

        json_response(true, '', [
            'account_id'     => $accountId,
            'account_uan'    => $account['uan'],
            'employer_name'  => $account['employer_name'],
            'current_balance'=> (float)$account['current_balance'],
            'fy_data'        => $fyData,
            'interest_rate'  => (float)($account['interest_rate'] ?? EPF_RATE_CURRENT),
        ]);
    }

    // ── epf_balance_history ────────────────────────────────────────────────
    case 'epf_balance_history': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        $months    = min(60, max(6, (int)($_GET['months'] ?? 24)));

        if (!$accountId) json_response(false, 'account_id required.');
        if (!_epf_owned($userId, $accountId)) json_response(false, 'Account not found.', [], 404);

        $fromDate = date('Y-m-01', strtotime("-{$months} months"));

        $logs = DB::fetchAll(
            "SELECT log_month, balance_after, total_credit, interest_credited
             FROM epf_monthly_log
             WHERE epf_account_id=? AND log_month >= ? AND balance_after IS NOT NULL
             ORDER BY log_month ASC",
            [$accountId, $fromDate]
        );

        // Also fetch FY snapshots for closing balances (fill gaps)
        $snapBalances = DB::fetchAll(
            "SELECT CONCAT(fy_year+1, '-03-01') AS snap_date, closing_balance
             FROM epf_fy_snapshot WHERE epf_account_id=?
             ORDER BY fy_year ASC",
            [$accountId]
        );

        $chart = array_map(fn($l) => [
            'date'        => $l['log_month'],
            'label'       => date('M Y', strtotime($l['log_month'])),
            'balance'     => (float)$l['balance_after'],
            'credit'      => (float)$l['total_credit'],
            'interest'    => (float)$l['interest_credited'],
        ], $logs);

        json_response(true, '', [
            'account_id'    => $accountId,
            'chart_data'    => $chart,
            'snap_balances' => $snapBalances,
            'months'        => $months,
        ]);
    }

    // ── epf_contribution_calc ──────────────────────────────────────────────
    case 'epf_contribution_calc': {
        $basic   = (float)($_GET['basic'] ?? $_POST['basic'] ?? 0);
        $vpfPct  = (float)($_GET['vpf_rate'] ?? $_POST['vpf_rate'] ?? 0);

        if ($basic <= 0) json_response(false, 'basic salary required.');

        $calc = _epf_calc($basic, $vpfPct);
        $calc['basic'] = $basic;
        $calc['vpf_rate_pct'] = $vpfPct;
        $calc['tax_alert_msg'] = $calc['tax_alert']
            ? "⚠️ Annual employee contribution ₹" . number_format($calc['annual_ee_total'])
              . " exceeds ₹2.5L threshold. Interest on excess will be taxable."
            : "✅ Employee contribution within ₹2.5L tax-free limit.";
        $calc['eps_pension_rough'] = round(min($basic, EPF_EPS_MAX_BASE) * 10 / 70);  // 10yr example

        json_response(true, '', $calc);
    }

    // ── epf_monthly_log_save ───────────────────────────────────────────────
    case 'epf_monthly_log_save': {
        require_csrf();

        $accountId = (int)($_POST['account_id']   ?? 0);
        $logMonth  = clean($_POST['log_month']    ?? date('Y-m-01'));
        $basic     = (float)($_POST['basic_salary'] ?? 0);
        $eeCont    = (float)($_POST['employee_contribution'] ?? 0);
        $erCont    = (float)($_POST['employer_contribution'] ?? 0);
        $epsCont   = (float)($_POST['eps_contribution']      ?? 0);
        $vpfCont   = (float)($_POST['vpf_contribution']      ?? 0);
        $balAfter  = isset($_POST['balance_after']) ? (float)$_POST['balance_after'] : null;
        $interest  = (float)($_POST['interest_credited']     ?? 0);
        $notes     = substr(clean($_POST['notes'] ?? ''), 0, 255);
        $source    = in_array($_POST['source'] ?? '', ['manual','epfo_sync','import'])
            ? $_POST['source'] : 'manual';

        if (!$accountId) json_response(false, 'account_id required.');
        if (!_epf_owned($userId, $accountId)) json_response(false, 'Account not found.', [], 404);

        // Normalize log_month to first of month
        $logMonth = date('Y-m-01', strtotime($logMonth));

        // Auto-compute if not provided
        if ($basic > 0 && $eeCont == 0) {
            $calc  = _epf_calc($basic);
            $eeCont = $calc['employee_epf'];
            $erCont = $calc['employer_epf'];
            $epsCont = $calc['eps'];
        }

        $totalCredit = round($eeCont + $erCont + $vpfCont, 2);

        DB::run(
            "INSERT INTO epf_monthly_log
               (epf_account_id, log_month, basic_salary, employee_contribution,
                employer_contribution, eps_contribution, vpf_contribution,
                total_credit, balance_after, interest_credited, source, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               basic_salary=VALUES(basic_salary),
               employee_contribution=VALUES(employee_contribution),
               employer_contribution=VALUES(employer_contribution),
               eps_contribution=VALUES(eps_contribution),
               vpf_contribution=VALUES(vpf_contribution),
               total_credit=VALUES(total_credit),
               balance_after=VALUES(balance_after),
               interest_credited=VALUES(interest_credited),
               source=VALUES(source), notes=VALUES(notes)",
            [$accountId, $logMonth, $basic, $eeCont, $erCont, $epsCont,
             $vpfCont, $totalCredit, $balAfter, $interest, $source, $notes ?: null]
        );

        // Update epf_accounts current_balance if balance_after provided and this is latest month
        if ($balAfter !== null) {
            DB::run(
                "UPDATE epf_accounts SET current_balance=?, last_updated=?
                 WHERE id=? AND (last_updated IS NULL OR last_updated <= ?)",
                [$balAfter, $logMonth, $accountId, $logMonth]
            );
        }

        json_response(true, 'Monthly log saved ✅', [
            'account_id'  => $accountId,
            'log_month'   => $logMonth,
            'total_credit'=> $totalCredit,
        ]);
    }

    // ── epf_monthly_log_delete ─────────────────────────────────────────────
    case 'epf_monthly_log_delete': {
        require_csrf();

        $accountId = (int)($_POST['account_id'] ?? 0);
        $logMonth  = clean($_POST['log_month']  ?? '');

        if (!$accountId || !$logMonth) json_response(false, 'account_id and log_month required.');
        if (!_epf_owned($userId, $accountId))  json_response(false, 'Account not found.', [], 404);

        $logMonth = date('Y-m-01', strtotime($logMonth));
        $deleted  = DB::run(
            "DELETE FROM epf_monthly_log WHERE epf_account_id=? AND log_month=?",
            [$accountId, $logMonth]
        );

        json_response((bool)$deleted, $deleted ? 'Log entry deleted.' : 'Entry not found.');
    }

    // ── epf_fy_snapshot_save ───────────────────────────────────────────────
    case 'epf_fy_snapshot_save': {
        require_csrf();

        $accountId  = (int)($_POST['account_id']     ?? 0);
        $fyYear     = (int)($_POST['fy_year']        ?? date('Y'));
        $openingBal = (float)($_POST['opening_balance']  ?? 0);
        $closingBal = (float)($_POST['closing_balance']  ?? 0);
        $eeContrib  = (float)($_POST['total_ee_contrib'] ?? 0);
        $erContrib  = (float)($_POST['total_er_contrib'] ?? 0);
        $vpfTotal   = (float)($_POST['total_vpf']        ?? 0);
        $interest   = (float)($_POST['interest_credited'] ?? 0);

        if (!$accountId) json_response(false, 'account_id required.');
        if (!_epf_owned($userId, $accountId)) json_response(false, 'Account not found.', [], 404);

        DB::run(
            "INSERT INTO epf_fy_snapshot
               (epf_account_id, fy_year, opening_balance, closing_balance,
                total_ee_contrib, total_er_contrib, total_vpf, interest_credited)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               opening_balance=VALUES(opening_balance),
               closing_balance=VALUES(closing_balance),
               total_ee_contrib=VALUES(total_ee_contrib),
               total_er_contrib=VALUES(total_er_contrib),
               total_vpf=VALUES(total_vpf),
               interest_credited=VALUES(interest_credited)",
            [$accountId, $fyYear, $openingBal, $closingBal, $eeContrib, $erContrib, $vpfTotal, $interest]
        );

        json_response(true, 'FY snapshot saved.', ['fy_year' => $fyYear, 'closing_balance' => $closingBal]);
    }

    // ── epf_balance_update ─────────────────────────────────────────────────
    case 'epf_balance_update': {
        require_csrf();

        $accountId  = (int)($_POST['account_id']   ?? 0);
        $balance    = (float)($_POST['balance']    ?? -1);
        $epsBalance = (float)($_POST['eps_balance'] ?? -1);
        $asOfDate   = clean($_POST['as_of_date'] ?? date('Y-m-d'));

        if (!$accountId) json_response(false, 'account_id required.');
        if (!_epf_owned($userId, $accountId)) json_response(false, 'Account not found.', [], 404);

        $sets   = [];
        $params = [];

        if ($balance >= 0)    { $sets[] = 'current_balance=?'; $params[] = $balance; }
        if ($epsBalance >= 0) { $sets[] = 'eps_balance=?';     $params[] = $epsBalance; }

        if (!$sets) json_response(false, 'Kuch update karna to batao (balance ya eps_balance).');

        $sets[]   = 'last_updated=?';
        $params[] = $asOfDate;
        $params[] = $accountId;

        DB::run(
            "UPDATE epf_accounts SET " . implode(', ', $sets) . " WHERE id=?",
            $params
        );

        json_response(true, 'Balance updated ✅', [
            'account_id'  => $accountId,
            'balance'     => $balance >= 0 ? $balance : null,
            'eps_balance' => $epsBalance >= 0 ? $epsBalance : null,
            'as_of_date'  => $asOfDate,
        ]);
    }

    default:
        json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);
}

// ── LOCAL HELPER ───────────────────────────────────────────────────────────
function round_2($v): float { return is_numeric($v) ? round((float)$v, 2) : (float)$v; }
