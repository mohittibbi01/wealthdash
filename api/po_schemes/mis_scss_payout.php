<?php
/**
 * WealthDash — t206: Post Office MIS/SCSS — Monthly Payout Tracker
 *
 * MIS Rules (Post Office Monthly Income Scheme):
 *  - Tenure: 5 years | Monthly interest payout
 *  - Rate: 7.4% p.a. (FY2025-26) | Principal returned at maturity
 *  - Max: ₹9L single / ₹15L joint
 *  - No TDS if invested at post office (non-bank)
 *  - Interest taxable as IFOS; add to income; slab rate
 *
 * SCSS Rules (Senior Citizen Savings Scheme):
 *  - Tenure: 5 years (extendable by 3yr) | Quarterly payout: Jan/Apr/Jul/Oct
 *  - Rate: 8.2% p.a. (FY2025-26) | Principal returned at maturity
 *  - Max: ₹30L | TDS if interest > ₹50,000/yr (194A)
 *  - Age: 60+ (55+ if VRS/superannuation)
 *  - 80C benefit on principal in purchase year
 *
 * Actions (read — CSRF exempt):
 *   mis_payout_calendar    — month-by-month payout calendar with received status
 *   scss_payout_calendar   — quarter-by-quarter calendar
 *   payout_income_fy       — total payout income for a given FY (ITR filing)
 *   payout_upcoming        — next 3 months expected payouts (all schemes)
 *   mis_payout_summary     — per-account MIS summary (re-exported from po_td_mis_kvp compat)
 *   scss_payout_summary    — per-account SCSS summary
 *
 * Actions (write — CSRF required):
 *   payout_mark_received   — mark one payout as received (single or bulk)
 *   payout_mark_pending    — unmark (toggle back to pending)
 *   payout_bulk_generate   — generate expected payout rows for a scheme for N months
 *   payout_tds_log_save    — log TDS deducted on SCSS payout
 *   payout_tds_log_delete  — remove a TDS log entry
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'payout_upcoming';

// ── CONSTANTS ──────────────────────────────────────────────────────────────
const MIS_TENURE_MONTHS       = 60;    // 5 years
const SCSS_TENURE_MONTHS      = 60;    // 5 years (base)
const SCSS_TDS_THRESHOLD      = 50000; // TDS if annual interest > ₹50,000
const MIS_MAX_SINGLE          = 900000;
const MIS_MAX_JOINT           = 1500000;
const SCSS_MAX                = 3000000;
const SCSS_PAYOUT_MONTHS      = [1, 4, 7, 10]; // Jan, Apr, Jul, Oct

// ── HELPERS ────────────────────────────────────────────────────────────────

function _po_fy_dates(string $fy = ''): array {
    if ($fy && preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
        return [$m[1] . '-04-01', $m[2] . '-03-31', $fy];
    }
    $yr = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    return [$yr . '-04-01', ($yr + 1) . '-03-31', $yr . '-' . ($yr + 1)];
}

function _po_scheme_owned(int $userId, int $schemeId, string $type = ''): ?array {
    $typeClause = $type ? "AND LOWER(po.scheme_type)=?" : "";
    $params     = $type ? [$schemeId, $userId, $type] : [$schemeId, $userId];
    return DB::fetchOne(
        "SELECT po.* FROM po_schemes po
         JOIN portfolios p ON p.id = po.portfolio_id
         WHERE po.id=? AND p.user_id=? {$typeClause}",
        $params
    ) ?: null;
}

/**
 * Get all user MIS schemes with computed fields
 */
function _mis_schemes(int $userId): array {
    return DB::fetchAll(
        "SELECT po.id, po.holder_name, po.account_number, po.post_office,
                po.principal, po.interest_rate, po.opening_date, po.maturity_date,
                po.status,
                ROUND(po.principal * po.interest_rate / 1200, 2) AS monthly_payout,
                ROUND(po.principal * po.interest_rate / 100, 2)  AS annual_payout,
                PERIOD_DIFF(DATE_FORMAT(CURDATE(),'%Y%m'), DATE_FORMAT(po.opening_date,'%Y%m')) AS months_elapsed,
                DATEDIFF(po.maturity_date, CURDATE()) AS days_left
         FROM po_schemes po
         JOIN portfolios p ON p.id = po.portfolio_id
         WHERE p.user_id=? AND LOWER(po.scheme_type)='mis' AND po.status='active'
         ORDER BY po.opening_date ASC",
        [$userId]
    );
}

/**
 * Get all user SCSS schemes with computed fields
 */
function _scss_schemes(int $userId): array {
    return DB::fetchAll(
        "SELECT po.id, po.holder_name, po.account_number, po.post_office,
                po.principal, po.interest_rate, po.opening_date, po.maturity_date,
                po.status,
                ROUND(po.principal * po.interest_rate / 400, 2) AS quarterly_payout,
                ROUND(po.principal * po.interest_rate / 100, 2) AS annual_payout,
                DATEDIFF(po.maturity_date, CURDATE()) AS days_left
         FROM po_schemes po
         JOIN portfolios p ON p.id = po.portfolio_id
         WHERE p.user_id=? AND LOWER(po.scheme_type)='scss' AND po.status='active'
         ORDER BY po.opening_date ASC",
        [$userId]
    );
}

/**
 * Next SCSS payout date (1st of next payout month)
 */
function _scss_next_payout(): string {
    $m = (int)date('n');
    $y = (int)date('Y');
    foreach (SCSS_PAYOUT_MONTHS as $pm) {
        if ($pm > $m) return sprintf('%04d-%02d-01', $y, $pm);
    }
    return sprintf('%04d-01-01', $y + 1);
}

/**
 * Generate list of MIS payout dates for a scheme (month-start dates from open_date)
 */
function _mis_payout_dates(string $openDate, int $tenure = MIS_TENURE_MONTHS): array {
    $dates = [];
    $dt    = new DateTime($openDate);
    $dt->modify('+1 month');
    for ($i = 0; $i < $tenure; $i++) {
        $dates[] = $dt->format('Y-m-01');
        $dt->modify('+1 month');
    }
    return $dates;
}

/**
 * Generate list of SCSS payout dates (quarterly: Jan/Apr/Jul/Oct) for a scheme
 */
function _scss_payout_dates(string $openDate, string $maturityDate): array {
    $dates   = [];
    $openDt  = new DateTime($openDate);
    $matDt   = new DateTime($maturityDate);
    $cur     = clone $openDt;
    $cur->modify('+1 month');

    $end = new DateTime(min($maturityDate, date('Y-m-d', strtotime('+24 months'))));

    while ($cur <= $end) {
        $m = (int)$cur->format('n');
        if (in_array($m, SCSS_PAYOUT_MONTHS)) {
            $dates[] = $cur->format('Y-m-01');
        }
        $cur->modify('+1 month');
    }
    return array_unique($dates);
}

// ── ACTIONS ────────────────────────────────────────────────────────────────

switch ($action) {

    // ── mis_payout_calendar ────────────────────────────────────────────────
    case 'mis_payout_calendar': {
        $schemeId = (int)($_GET['scheme_id'] ?? 0);
        $fromDate = clean($_GET['from'] ?? date('Y-m-01', strtotime('-2 months')));
        $toDate   = clean($_GET['to']   ?? date('Y-m-01', strtotime('+6 months')));

        $schemes = $schemeId
            ? array_filter(_mis_schemes($userId), fn($s) => $s['id'] == $schemeId)
            : _mis_schemes($userId);

        if (!$schemes) json_response(false, 'No active MIS schemes found.', [], 404);

        $calendar = [];

        foreach (array_values($schemes) as $s) {
            $payoutDates = _mis_payout_dates($s['opening_date']);
            $filteredDates = array_filter($payoutDates, fn($d) => $d >= $fromDate && $d <= $toDate);

            // Load received log for this scheme
            $logs = DB::fetchAll(
                "SELECT payout_date, is_received, amount, tds_deducted, notes
                 FROM po_payout_log
                 WHERE po_scheme_id=? AND payout_date BETWEEN ? AND ?
                 ORDER BY payout_date ASC",
                [$s['id'], $fromDate, $toDate]
            );
            $logMap = array_column($logs, null, 'payout_date');

            $rows = [];
            foreach ($filteredDates as $pd) {
                $log    = $logMap[$pd] ?? null;
                $rows[] = [
                    'payout_date'   => $pd,
                    'month_label'   => date('M Y', strtotime($pd)),
                    'amount'        => (float)($log['amount'] ?? $s['monthly_payout']),
                    'expected'      => (float)$s['monthly_payout'],
                    'is_received'   => (bool)($log['is_received'] ?? false),
                    'tds_deducted'  => (float)($log['tds_deducted'] ?? 0),
                    'net_received'  => (float)($log['amount'] ?? $s['monthly_payout']) - (float)($log['tds_deducted'] ?? 0),
                    'notes'         => $log['notes'] ?? null,
                    'is_future'     => $pd > date('Y-m-01'),
                ];
            }

            $received     = array_filter($rows, fn($r) => $r['is_received']);
            $totalReceived = array_sum(array_column(array_values($received), 'net_received'));

            $calendar[] = [
                'scheme_id'        => $s['id'],
                'holder_name'      => $s['holder_name'],
                'account_number'   => $s['account_number'],
                'monthly_payout'   => (float)$s['monthly_payout'],
                'annual_payout'    => (float)$s['annual_payout'],
                'opening_date'     => $s['opening_date'],
                'maturity_date'    => $s['maturity_date'],
                'payouts'          => $rows,
                'received_count'   => count($received),
                'pending_count'    => count($rows) - count($received),
                'total_received'   => round($totalReceived, 2),
            ];
        }

        json_response(true, '', [
            'type'      => 'mis',
            'from'      => $fromDate,
            'to'        => $toDate,
            'calendar'  => $calendar,
            'mis_rules' => [
                'payout_freq'  => 'Monthly (1st of each month after opening)',
                'tds'          => 'Post office MIS par TDS nahi (bank MIS par ho sakta hai)',
                'tax'          => 'Interest → IFOS (Income from Other Sources) → slab rate',
                'max_single'   => MIS_MAX_SINGLE,
                'max_joint'    => MIS_MAX_JOINT,
            ],
        ]);
    }

    // ── scss_payout_calendar ───────────────────────────────────────────────
    case 'scss_payout_calendar': {
        $schemeId = (int)($_GET['scheme_id'] ?? 0);
        $fromDate = clean($_GET['from'] ?? date('Y-m-01', strtotime('-3 months')));
        $toDate   = clean($_GET['to']   ?? date('Y-m-01', strtotime('+12 months')));

        $schemes = $schemeId
            ? array_filter(_scss_schemes($userId), fn($s) => $s['id'] == $schemeId)
            : _scss_schemes($userId);

        if (!$schemes) json_response(false, 'No active SCSS schemes found.', [], 404);

        $calendar = [];

        foreach (array_values($schemes) as $s) {
            $payoutDates = _scss_payout_dates($s['opening_date'], $s['maturity_date']);
            $filtered    = array_filter($payoutDates, fn($d) => $d >= $fromDate && $d <= $toDate);

            $logs = DB::fetchAll(
                "SELECT payout_date, is_received, amount, tds_deducted, notes
                 FROM po_payout_log
                 WHERE po_scheme_id=? AND payout_date BETWEEN ? AND ?
                 ORDER BY payout_date ASC",
                [$s['id'], $fromDate, $toDate]
            );
            $logMap = array_column($logs, null, 'payout_date');

            // TDS this year (for threshold check)
            $tdsThisYr = (float)(DB::fetchVal(
                "SELECT COALESCE(SUM(tds_deducted),0) FROM po_payout_log
                 WHERE po_scheme_id=? AND YEAR(payout_date)=YEAR(CURDATE())",
                [$s['id']]
            ) ?? 0);

            $annualPayout = (float)$s['annual_payout'];
            $tdsApplicable = $annualPayout > SCSS_TDS_THRESHOLD;

            $rows = [];
            foreach (array_values($filtered) as $pd) {
                $log    = $logMap[$pd] ?? null;
                $qtrPay = (float)$s['quarterly_payout'];
                $rows[] = [
                    'payout_date'   => $pd,
                    'quarter_label' => 'Q' . ceil((int)date('n', strtotime($pd)) / 3) . ' ' . date('Y', strtotime($pd)),
                    'month_label'   => date('M Y', strtotime($pd)),
                    'amount'        => (float)($log['amount'] ?? $qtrPay),
                    'expected'      => $qtrPay,
                    'is_received'   => (bool)($log['is_received'] ?? false),
                    'tds_deducted'  => (float)($log['tds_deducted'] ?? 0),
                    'net_received'  => (float)($log['amount'] ?? $qtrPay) - (float)($log['tds_deducted'] ?? 0),
                    'notes'         => $log['notes'] ?? null,
                    'is_future'     => $pd > date('Y-m-01'),
                ];
            }

            $received     = array_filter($rows, fn($r) => $r['is_received']);
            $totalReceived = array_sum(array_column(array_values($received), 'net_received'));

            $calendar[] = [
                'scheme_id'           => $s['id'],
                'holder_name'         => $s['holder_name'],
                'account_number'      => $s['account_number'],
                'quarterly_payout'    => (float)$s['quarterly_payout'],
                'annual_payout'       => $annualPayout,
                'opening_date'        => $s['opening_date'],
                'maturity_date'       => $s['maturity_date'],
                'next_payout'         => _scss_next_payout(),
                'tds_applicable'      => $tdsApplicable,
                'tds_threshold'       => SCSS_TDS_THRESHOLD,
                'tds_deducted_this_yr'=> $tdsThisYr,
                'extension_eligible'  => (int)($s['days_left'] ?? 999) <= 90,
                'payouts'             => $rows,
                'received_count'      => count($received),
                'pending_count'       => count($rows) - count($received),
                'total_received'      => round($totalReceived, 2),
            ];
        }

        json_response(true, '', [
            'type'        => 'scss',
            'from'        => $fromDate,
            'to'          => $toDate,
            'calendar'    => $calendar,
            'scss_rules'  => [
                'payout_freq'    => 'Quarterly — Jan 1, Apr 1, Jul 1, Oct 1',
                'tds_threshold'  => '₹50,000/yr se zyada interest hoga to TDS (194A) katega',
                'tds_nil_form'   => '15G/15H form submit karo to avoid TDS if income below taxable limit',
                'extension'      => '5yr ke baad 3yr extension milta hai — fresh application required',
                '80c_principal'  => 'SCSS principal 80C eligible hai purchase year mein',
                'max'            => SCSS_MAX,
            ],
        ]);
    }

    // ── payout_income_fy ───────────────────────────────────────────────────
    case 'payout_income_fy': {
        $fy = clean($_GET['fy'] ?? '');
        [$fyStart, $fyEnd, $fyLabel] = _po_fy_dates($fy);

        // MIS income this FY
        $misIncome = DB::fetchAll(
            "SELECT po.id, po.holder_name, po.account_number,
                    COALESCE(SUM(pl.amount),0)        AS total_payout,
                    COALESCE(SUM(pl.tds_deducted),0)  AS total_tds,
                    COUNT(pl.id)                       AS payouts_received,
                    ROUND(po.principal * po.interest_rate / 100, 2) AS expected_annual,
                    po.interest_rate
             FROM po_schemes po
             JOIN portfolios p  ON p.id  = po.portfolio_id
             LEFT JOIN po_payout_log pl
               ON pl.po_scheme_id = po.id AND pl.is_received=1
               AND pl.payout_date BETWEEN ? AND ?
             WHERE p.user_id=? AND LOWER(po.scheme_type)='mis'
             GROUP BY po.id",
            [$fyStart, $fyEnd, $userId]
        );

        // SCSS income this FY
        $scssIncome = DB::fetchAll(
            "SELECT po.id, po.holder_name, po.account_number,
                    COALESCE(SUM(pl.amount),0)        AS total_payout,
                    COALESCE(SUM(pl.tds_deducted),0)  AS total_tds,
                    COUNT(pl.id)                       AS payouts_received,
                    ROUND(po.principal * po.interest_rate / 100, 2) AS expected_annual,
                    po.interest_rate
             FROM po_schemes po
             JOIN portfolios p  ON p.id  = po.portfolio_id
             LEFT JOIN po_payout_log pl
               ON pl.po_scheme_id = po.id AND pl.is_received=1
               AND pl.payout_date BETWEEN ? AND ?
             WHERE p.user_id=? AND LOWER(po.scheme_type)='scss'
             GROUP BY po.id",
            [$fyStart, $fyEnd, $userId]
        );

        $totalMis   = array_sum(array_column($misIncome, 'total_payout'));
        $totalScss  = array_sum(array_column($scssIncome, 'total_payout'));
        $totalTds   = array_sum(array_column($scssIncome, 'total_tds'))
                    + array_sum(array_column($misIncome, 'total_tds'));
        $grandTotal = $totalMis + $totalScss;

        json_response(true, '', [
            'fy'                  => $fyLabel,
            'fy_start'            => $fyStart,
            'fy_end'              => $fyEnd,
            'mis_income'          => [
                'schemes'       => $misIncome,
                'total'         => round($totalMis, 2),
                'tds_deducted'  => round(array_sum(array_column($misIncome, 'total_tds')), 2),
            ],
            'scss_income'         => [
                'schemes'       => $scssIncome,
                'total'         => round($totalScss, 2),
                'tds_deducted'  => round(array_sum(array_column($scssIncome, 'total_tds')), 2),
            ],
            'grand_total_income'  => round($grandTotal, 2),
            'total_tds_deducted'  => round($totalTds, 2),
            'net_income'          => round($grandTotal - $totalTds, 2),
            'itr_note' => [
                'section'     => 'ITR-1/2 → Schedule OS (Income from Other Sources)',
                'tds_credit'  => 'TDS deducted → Form 26AS mein check karo → ITR mein credit claim karo',
                'tax_rate'    => 'Slab rate lagega — koi special rate nahi',
                'form_15gh'   => 'Agar income non-taxable hai to 15G (general) / 15H (senior) submit karo',
            ],
        ]);
    }

    // ── payout_upcoming ────────────────────────────────────────────────────
    case 'payout_upcoming': {
        $months = max(1, min(6, (int)($_GET['months'] ?? 3)));
        $toDate = date('Y-m-01', strtotime("+{$months} months"));
        $today  = date('Y-m-01');

        $upcoming = [];

        // MIS upcoming
        foreach (_mis_schemes($userId) as $s) {
            $dates = _mis_payout_dates($s['opening_date']);
            foreach ($dates as $d) {
                if ($d >= $today && $d <= $toDate) {
                    // Check if already logged
                    $log = DB::fetchOne(
                        "SELECT is_received FROM po_payout_log WHERE po_scheme_id=? AND payout_date=?",
                        [$s['id'], $d]
                    );
                    if (!($log['is_received'] ?? false)) {
                        $upcoming[] = [
                            'date'         => $d,
                            'type'         => 'MIS',
                            'scheme_id'    => $s['id'],
                            'holder_name'  => $s['holder_name'],
                            'amount'       => (float)$s['monthly_payout'],
                            'is_overdue'   => $d < date('Y-m-01'),
                            'days_until'   => max(0, (int)ceil((strtotime($d) - time()) / 86400)),
                        ];
                    }
                }
            }
        }

        // SCSS upcoming
        foreach (_scss_schemes($userId) as $s) {
            $dates = _scss_payout_dates($s['opening_date'], $s['maturity_date']);
            foreach ($dates as $d) {
                if ($d >= $today && $d <= $toDate) {
                    $log = DB::fetchOne(
                        "SELECT is_received FROM po_payout_log WHERE po_scheme_id=? AND payout_date=?",
                        [$s['id'], $d]
                    );
                    if (!($log['is_received'] ?? false)) {
                        $upcoming[] = [
                            'date'         => $d,
                            'type'         => 'SCSS',
                            'scheme_id'    => $s['id'],
                            'holder_name'  => $s['holder_name'],
                            'amount'       => (float)$s['quarterly_payout'],
                            'is_overdue'   => $d < date('Y-m-01'),
                            'days_until'   => max(0, (int)ceil((strtotime($d) - time()) / 86400)),
                        ];
                    }
                }
            }
        }

        usort($upcoming, fn($a, $b) => strcmp($a['date'], $b['date']));

        $totalUpcoming = array_sum(array_column($upcoming, 'amount'));

        json_response(true, '', [
            'upcoming'       => $upcoming,
            'count'          => count($upcoming),
            'total_amount'   => round($totalUpcoming, 2),
            'months_ahead'   => $months,
            'through_date'   => $toDate,
        ]);
    }

    // ── mis_payout_summary — compatibility alias ───────────────────────────
    case 'mis_payout_summary': {
        $schemes = _mis_schemes($userId);

        $totalPrincipal = 0;
        $totalMonthly   = 0;

        foreach ($schemes as &$s) {
            $thisMonth = date('Y-m-01');
            $log = DB::fetchOne(
                "SELECT is_received, amount FROM po_payout_log WHERE po_scheme_id=? AND payout_date=?",
                [$s['id'], $thisMonth]
            );
            $s['this_month_received'] = (bool)($log['is_received'] ?? false);
            $s['this_month_amount']   = (float)($log['amount'] ?? $s['monthly_payout']);

            // Months elapsed
            $elapsed  = max(0, (int)floor(
                (time() - strtotime($s['opening_date'])) / (86400 * 30)
            ));
            $received = (float)(DB::fetchVal(
                "SELECT COALESCE(SUM(amount),0) FROM po_payout_log WHERE po_scheme_id=? AND is_received=1",
                [$s['id']]
            ) ?? 0);
            $s['total_payout_received'] = $received;
            $s['months_elapsed']        = $elapsed;
            $s['payout_status']         = $received >= ($s['monthly_payout'] * $elapsed * 0.9) ? 'up_to_date' : 'partial';

            $totalPrincipal += (float)$s['principal'];
            $totalMonthly   += (float)$s['monthly_payout'];
        }
        unset($s);

        json_response(true, '', [
            'schemes' => $schemes,
            'summary' => [
                'total_principal'       => $totalPrincipal,
                'total_monthly_payout'  => round($totalMonthly, 2),
                'annual_income'         => round($totalMonthly * 12, 2),
            ],
            'max_investment_limit' => MIS_MAX_SINGLE,
        ]);
    }

    // ── scss_payout_summary — compatibility alias ──────────────────────────
    case 'scss_payout_summary': {
        $schemes = _scss_schemes($userId);

        $totalPrincipal  = 0;
        $totalQuarterly  = 0;

        foreach ($schemes as &$s) {
            $annualPayout      = (float)$s['annual_payout'];
            $tdsApplicable     = $annualPayout > SCSS_TDS_THRESHOLD;
            $s['tds_applicable']   = $tdsApplicable;
            $s['next_payout_date'] = _scss_next_payout();
            $s['extension_eligible'] = (int)($s['days_left'] ?? 999) <= 90;

            $received = (float)(DB::fetchVal(
                "SELECT COALESCE(SUM(amount),0) FROM po_payout_log WHERE po_scheme_id=? AND is_received=1",
                [$s['id']]
            ) ?? 0);
            $s['total_payout_received'] = $received;

            $totalPrincipal += (float)$s['principal'];
            $totalQuarterly += (float)$s['quarterly_payout'];
        }
        unset($s);

        json_response(true, '', [
            'schemes' => $schemes,
            'summary' => [
                'total_principal'        => $totalPrincipal,
                'total_quarterly_payout' => round($totalQuarterly, 2),
                'annual_income'          => round($totalQuarterly * 4, 2),
            ],
            'scss_payout_months'   => SCSS_PAYOUT_MONTHS,
            'max_investment_limit' => SCSS_MAX,
            'tds_threshold'        => SCSS_TDS_THRESHOLD,
        ]);
    }

    // ── payout_mark_received ───────────────────────────────────────────────
    case 'payout_mark_received': {
        require_csrf();

        $schemeId   = (int)($_POST['scheme_id']   ?? 0);
        $payoutDate = clean($_POST['payout_date'] ?? '');
        $amount     = (float)($_POST['amount']    ?? 0);
        $tds        = abs((float)($_POST['tds_deducted'] ?? 0));
        $notes      = substr(trim(clean($_POST['notes'] ?? '')), 0, 255);

        if (!$schemeId) json_response(false, 'scheme_id required.');
        if (!$payoutDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payoutDate)) {
            json_response(false, 'Valid payout_date (YYYY-MM-DD) required.');
        }
        if (!_po_scheme_owned($userId, $schemeId)) {
            json_response(false, 'Scheme not found.', [], 404);
        }
        if ($amount <= 0) json_response(false, 'Amount must be greater than 0.');
        if ($tds > $amount) json_response(false, 'TDS cannot exceed payout amount.');

        DB::run(
            "INSERT INTO po_payout_log
               (po_scheme_id, payout_date, payout_type, amount, tds_deducted, is_received, notes)
             VALUES (?,?,
               (SELECT CASE LOWER(scheme_type) WHEN 'mis' THEN 'monthly' ELSE 'quarterly' END
                FROM po_schemes WHERE id=?),
               ?,?,1,?)
             ON DUPLICATE KEY UPDATE
               is_received=1, amount=VALUES(amount), tds_deducted=VALUES(tds_deducted), notes=VALUES(notes)",
            [$schemeId, $payoutDate, $schemeId, $amount, $tds, $notes ?: null]
        );

        json_response(true, 'Payout marked received ✅', [
            'scheme_id'   => $schemeId,
            'payout_date' => $payoutDate,
            'amount'      => $amount,
            'net'         => round($amount - $tds, 2),
        ]);
    }

    // ── payout_mark_pending ────────────────────────────────────────────────
    case 'payout_mark_pending': {
        require_csrf();

        $schemeId   = (int)($_POST['scheme_id']   ?? 0);
        $payoutDate = clean($_POST['payout_date'] ?? '');

        if (!$schemeId || !$payoutDate) json_response(false, 'scheme_id and payout_date required.');
        if (!_po_scheme_owned($userId, $schemeId)) json_response(false, 'Scheme not found.', [], 404);

        DB::run(
            "UPDATE po_payout_log SET is_received=0 WHERE po_scheme_id=? AND payout_date=?",
            [$schemeId, $payoutDate]
        );

        json_response(true, 'Payout marked as pending.');
    }

    // ── payout_bulk_generate ───────────────────────────────────────────────
    case 'payout_bulk_generate': {
        require_csrf();

        $schemeId  = (int)($_POST['scheme_id'] ?? 0);
        $fromDate  = clean($_POST['from'] ?? date('Y-01-01'));
        $toDate    = clean($_POST['to']   ?? date('Y-12-31'));

        if (!$schemeId) json_response(false, 'scheme_id required.');

        $scheme = _po_scheme_owned($userId, $schemeId);
        if (!$scheme) json_response(false, 'Scheme not found.', [], 404);

        $type = strtolower($scheme['scheme_type']);

        if ($type === 'mis') {
            $dates   = _mis_payout_dates($scheme['opening_date']);
            $monthly = round((float)$scheme['principal'] * (float)$scheme['interest_rate'] / 1200, 2);
            $pType   = 'monthly';
            $amt     = $monthly;
        } elseif ($type === 'scss') {
            $dates  = _scss_payout_dates($scheme['opening_date'], $scheme['maturity_date']);
            $qAmt   = round((float)$scheme['principal'] * (float)$scheme['interest_rate'] / 400, 2);
            $pType  = 'quarterly';
            $amt    = $qAmt;
        } else {
            json_response(false, 'Only MIS/SCSS supported for bulk generate.');
        }

        $filtered  = array_filter($dates, fn($d) => $d >= $fromDate && $d <= $toDate);
        $inserted  = 0;
        $skipped   = 0;

        foreach ($filtered as $d) {
            // Only insert if not already logged
            $exists = DB::fetchVal(
                "SELECT id FROM po_payout_log WHERE po_scheme_id=? AND payout_date=?",
                [$schemeId, $d]
            );
            if (!$exists) {
                DB::insert(
                    "INSERT INTO po_payout_log (po_scheme_id, payout_date, payout_type, amount, is_received)
                     VALUES (?,?,?,?,0)",
                    [$schemeId, $d, $pType, $amt]
                );
                $inserted++;
            } else {
                $skipped++;
            }
        }

        json_response(true, "Generated {$inserted} payout rows ({$skipped} skipped, already exist).", [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'scheme_id'=> $schemeId,
        ]);
    }

    // ── payout_tds_log_save ────────────────────────────────────────────────
    case 'payout_tds_log_save': {
        require_csrf();

        $schemeId   = (int)($_POST['scheme_id']   ?? 0);
        $payoutDate = clean($_POST['payout_date'] ?? '');
        $tdsAmt     = abs((float)($_POST['tds_amount'] ?? 0));
        $tan        = substr(clean($_POST['tan'] ?? ''), 0, 20);
        $notes      = substr(clean($_POST['notes'] ?? ''), 0, 255);

        if (!$schemeId || !$payoutDate) json_response(false, 'scheme_id and payout_date required.');
        if (!_po_scheme_owned($userId, $schemeId)) json_response(false, 'Scheme not found.', [], 404);

        DB::run(
            "UPDATE po_payout_log
             SET tds_deducted=?, tds_tan=?, notes=COALESCE(?,notes)
             WHERE po_scheme_id=? AND payout_date=?",
            [$tdsAmt, $tan ?: null, $notes ?: null, $schemeId, $payoutDate]
        );

        json_response(true, 'TDS log updated.', [
            'scheme_id'   => $schemeId,
            'payout_date' => $payoutDate,
            'tds_amount'  => $tdsAmt,
        ]);
    }

    // ── payout_tds_log_delete ──────────────────────────────────────────────
    case 'payout_tds_log_delete': {
        require_csrf();

        $schemeId   = (int)($_POST['scheme_id']   ?? 0);
        $payoutDate = clean($_POST['payout_date'] ?? '');

        if (!$schemeId || !$payoutDate) json_response(false, 'scheme_id and payout_date required.');
        if (!_po_scheme_owned($userId, $schemeId)) json_response(false, 'Scheme not found.', [], 404);

        DB::run(
            "UPDATE po_payout_log SET tds_deducted=0, tds_tan=NULL WHERE po_scheme_id=? AND payout_date=?",
            [$schemeId, $payoutDate]
        );

        json_response(true, 'TDS log cleared.');
    }

    default:
        json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);
}
