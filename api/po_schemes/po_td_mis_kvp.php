<?php
/**
 * WealthDash — Post Office: TD / MIS / SCSS / KVP extended API
 * Tasks   : t14 (TD sub-tenures) · t15 (MIS/SCSS payout) · t16 (KVP tenure/tax)
 * Actions : td_sub_tenures | td_row_summary
 *           mis_payout_summary | mis_payout_toggle
 *           scss_payout_summary | scss_payout_toggle
 *           kvp_tenure_calc | kvp_tax_summary
 *           po_payout_list | po_payout_generate
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser  = require_auth();
$userId       = (int) $currentUser['id'];
$portfolioId  = get_user_portfolio_id($userId);
$action       = $_POST['action'] ?? $_GET['action'] ?? '';

// ── TD rates by tenure (Q1 FY 2025-26) ──────────────────────────────────────
const TD_RATES = [
    1 => 6.9,
    2 => 7.0,
    3 => 7.1,
    5 => 7.5,
];

// ── KVP: announced tenure in months by rate ──────────────────────────────────
// GoI announces doubling tenure directly; stored as authoritative.
const KVP_TENURE_BY_RATE = [
    '7.5' => 115,  // Q1 FY25-26
    '7.2' => 120,
    '7.0' => 123,
];

// ────────────────────────────────────────────────────────────────────────────
switch ($action) {

    // ═══════════════════════════════════════════════════════════════════════
    // t14 — TD Sub-Tenures: return 4 tenure rows with computed interest
    // ═══════════════════════════════════════════════════════════════════════
    case 'td_sub_tenures':
        /**
         * Returns all TD entries grouped by sub-tenure (1/2/3/5 yr).
         * Each tenure is stored as a separate po_schemes row
         * (td_tenure_years column identifies which bucket).
         */
        $rows = DB::fetchAll(
            "SELECT
                po.id,
                po.holder_name,
                po.account_number,
                po.post_office,
                po.principal,
                po.interest_rate,
                po.td_tenure_years,
                po.td_interest_payout,
                po.td_annual_interest,
                po.opening_date,
                po.maturity_date,
                po.maturity_amount,
                po.status,
                po.notes,
                DATEDIFF(po.maturity_date, CURDATE()) AS days_left
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id      = ?
               AND po.scheme_type = 'td'
             ORDER BY po.td_tenure_years ASC, po.opening_date ASC",
            [$userId]
        );

        // Compute / enrich each row
        foreach ($rows as &$r) {
            $years   = (int)($r['td_tenure_years'] ?? 0);
            $rate    = (float)($r['interest_rate']  ?? TD_RATES[$years] ?? 0);
            $principal = (float)($r['principal'] ?? 0);

            // TD compounds quarterly
            $quarters     = $years * 4;
            $quarterRate  = $rate / 400;
            $maturityCalc = $principal * pow(1 + $quarterRate, $quarters);

            $r['years_label']          = $years ? "{$years} Year" . ($years > 1 ? 's' : '') : '—';
            $r['rate_display']         = $rate . '%';
            $r['principal_fmt']        = $principal;
            $r['maturity_calc']        = round($maturityCalc, 2);
            $r['interest_earned_calc'] = round($maturityCalc - $principal, 2);
            $r['annual_interest_calc'] = round($principal * $rate / 100, 2);
            $r['official_rate']        = TD_RATES[$years] ?? $rate;
        }
        unset($r);

        // Group by tenure
        $grouped = [];
        foreach (array_keys(TD_RATES) as $yr) {
            $tenureRows = array_values(array_filter($rows, fn($r) => (int)$r['td_tenure_years'] === $yr));
            $grouped[$yr] = [
                'tenure_years'   => $yr,
                'label'          => "{$yr} Year" . ($yr > 1 ? 's' : ''),
                'rate'           => TD_RATES[$yr],
                'count'          => count($tenureRows),
                'total_invested' => array_sum(array_column($tenureRows, 'principal_fmt')),
                'total_maturity' => array_sum(array_column($tenureRows, 'maturity_calc')),
                'total_interest' => array_sum(array_column($tenureRows, 'interest_earned_calc')),
                'rows'           => $tenureRows,
            ];
        }

        // Also include untagged TDs
        $untagged = array_values(array_filter($rows, fn($r) => empty($r['td_tenure_years'])));
        if ($untagged) {
            $grouped['untagged'] = [
                'tenure_years'   => null,
                'label'          => 'Untagged',
                'rate'           => null,
                'count'          => count($untagged),
                'rows'           => $untagged,
            ];
        }

        json_response(true, '', [
            'grouped'    => array_values($grouped),
            'all_rows'   => $rows,
            'td_rates'   => TD_RATES,
            'total_count'=> count($rows),
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // t14 — TD row add/update: save tenure tag + recalculate
    // ═══════════════════════════════════════════════════════════════════════
    case 'td_save_tenure':
        $id      = (int)($_POST['id'] ?? 0);
        $tenure  = (int)($_POST['tenure_years'] ?? 0);
        $payout  = sanitize($_POST['interest_payout'] ?? 'on_maturity');

        if (!$id || !in_array($tenure, [1, 2, 3, 5], true)) {
            json_response(false, 'Invalid TD id or tenure_years (must be 1/2/3/5)');
        }

        // Verify ownership
        $row = DB::fetchOne(
            "SELECT po.id, po.principal, po.interest_rate, po.opening_date
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE po.id = ? AND p.user_id = ? AND po.scheme_type = 'td'",
            [$id, $userId]
        );
        if (!$row) json_response(false, 'TD entry not found or access denied');

        $rate      = TD_RATES[$tenure] ?? (float)$row['interest_rate'];
        $principal = (float)$row['principal'];
        $quarters  = $tenure * 4;
        $qRate     = $rate / 400;
        $maturity  = round($principal * pow(1 + $qRate, $quarters), 2);
        $annualInt = round($principal * $rate / 100, 2);
        $matDate   = date('Y-m-d', strtotime($row['opening_date'] . " +{$tenure} years"));

        DB::run(
            "UPDATE po_schemes
             SET td_tenure_years = ?, td_interest_payout = ?, td_annual_interest = ?,
                 interest_rate = ?, maturity_amount = ?, maturity_date = ?, updated_at = NOW()
             WHERE id = ?",
            [$tenure, $payout, $annualInt, $rate, $maturity, $matDate, $id]
        );

        json_response(true, "TD tenure saved — {$tenure} yr @ {$rate}%", [
            'id'             => $id,
            'tenure_years'   => $tenure,
            'rate'           => $rate,
            'maturity_amount'=> $maturity,
            'maturity_date'  => $matDate,
            'annual_interest'=> $annualInt,
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // t15 — MIS Payout Summary
    // ═══════════════════════════════════════════════════════════════════════
    case 'mis_payout_summary':
        /**
         * MIS: Principal stays; monthly interest paid out separately.
         * At maturity principal returned as-is.
         */
        $mis = DB::fetchAll(
            "SELECT
                po.id, po.holder_name, po.account_number, po.post_office,
                po.principal, po.interest_rate, po.opening_date, po.maturity_date,
                po.monthly_payout_amount, po.total_payout_received, po.last_payout_date,
                po.status,
                DATEDIFF(po.maturity_date, CURDATE())        AS days_left,
                PERIOD_DIFF(DATE_FORMAT(CURDATE(),'%Y%m'), DATE_FORMAT(po.opening_date,'%Y%m')) AS months_elapsed,
                -- Recompute monthly payout from current principal
                ROUND(po.principal * po.interest_rate / 1200, 2)  AS computed_monthly_payout,
                -- Max total interest over full tenure (5yr MIS = 60 months)
                ROUND(po.principal * po.interest_rate / 1200 * 60, 2) AS total_interest_5yr
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id      = ?
               AND po.scheme_type = 'mis'
             ORDER BY po.opening_date ASC",
            [$userId]
        );

        $totalPrincipal       = 0;
        $totalMonthlyPayout   = 0;
        $totalPayoutReceived  = 0;

        foreach ($mis as &$m) {
            $m['monthly_payout_amount'] = $m['monthly_payout_amount'] ?? $m['computed_monthly_payout'];
            $m['annual_payout']         = round((float)$m['monthly_payout_amount'] * 12, 2);
            $m['payout_status']         = _mis_payout_status($m);
            $m['maturity_returns_only_principal'] = true; // MIS design: principal returned at maturity
            $totalPrincipal      += (float)$m['principal'];
            $totalMonthlyPayout  += (float)$m['monthly_payout_amount'];
            $totalPayoutReceived += (float)$m['total_payout_received'];
        }
        unset($m);

        // Payout log for the current month
        $thisMonth  = date('Y-m-01');
        $payoutLogs = DB::fetchAll(
            "SELECT ppl.* FROM po_payout_log ppl
             JOIN po_schemes po ON po.id = ppl.po_scheme_id
             JOIN portfolios p  ON p.id  = po.portfolio_id
             WHERE p.user_id = ? AND po.scheme_type = 'mis'
               AND ppl.payout_date = ?",
            [$userId, $thisMonth]
        );
        $payoutMap = array_column($payoutLogs, null, 'po_scheme_id');

        foreach ($mis as &$m) {
            $m['this_month_received'] = isset($payoutMap[$m['id']]) && $payoutMap[$m['id']]['is_received'];
        }
        unset($m);

        json_response(true, '', [
            'schemes'               => $mis,
            'summary' => [
                'total_principal'      => $totalPrincipal,
                'total_monthly_payout' => round($totalMonthlyPayout, 2),
                'annual_income'        => round($totalMonthlyPayout * 12, 2),
                'total_payout_received'=> round($totalPayoutReceived, 2),
            ],
            'note' => 'MIS mein principal safe rehta hai — maturity pe waapis milta hai. Interest alag monthly payout hai.',
            'max_investment_limit' => 900000, // Single: 9L, Joint: 15L (FY25-26)
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // t15 — SCSS Payout Summary
    // ═══════════════════════════════════════════════════════════════════════
    case 'scss_payout_summary':
        /**
         * SCSS: Quarterly interest payout (April/July/Oct/Jan).
         * Principal returned at maturity (5yr, extendable by 3yr).
         * Max investment: ₹30L (enhanced from FY24).
         */
        $scss = DB::fetchAll(
            "SELECT
                po.id, po.holder_name, po.account_number, po.post_office,
                po.principal, po.interest_rate, po.opening_date, po.maturity_date,
                po.quarterly_payout_amount, po.total_payout_received, po.last_payout_date,
                po.status,
                DATEDIFF(po.maturity_date, CURDATE())        AS days_left,
                ROUND(po.principal * po.interest_rate / 400, 2)  AS computed_quarterly_payout,
                ROUND(po.principal * po.interest_rate / 100, 2)  AS annual_payout
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id      = ?
               AND po.scheme_type = 'scss'
             ORDER BY po.opening_date ASC",
            [$userId]
        );

        $totalPrincipal       = 0;
        $totalQuarterlyPayout = 0;

        foreach ($scss as &$s) {
            $s['quarterly_payout_amount'] = $s['quarterly_payout_amount'] ?? $s['computed_quarterly_payout'];
            // Next payout date: 1st of next payout month (Apr/Jul/Oct/Jan)
            $s['next_payout_date']        = _scss_next_payout_date();
            $s['maturity_returns_only_principal'] = true;
            $s['extension_eligible']      = $s['status'] === 'active' &&
                                            $s['days_left'] !== null &&
                                            (int)$s['days_left'] <= 90;
            $totalPrincipal       += (float)$s['principal'];
            $totalQuarterlyPayout += (float)$s['quarterly_payout_amount'];
        }
        unset($s);

        json_response(true, '', [
            'schemes'  => $scss,
            'summary'  => [
                'total_principal'        => $totalPrincipal,
                'total_quarterly_payout' => round($totalQuarterlyPayout, 2),
                'annual_income'          => round($totalQuarterlyPayout * 4, 2),
            ],
            'note'                 => 'SCSS mein principal maturity pe milta hai. Quarterly interest alag account mein credit hota hai.',
            'max_investment_limit' => 3000000, // ₹30L (FY24 enhancement)
            'scss_payout_months'   => [1, 4, 7, 10], // Jan/Apr/Jul/Oct
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // t15 — Toggle payout received for MIS/SCSS
    // ═══════════════════════════════════════════════════════════════════════
    case 'po_payout_toggle':
        $schemeId    = (int)($_POST['scheme_id']   ?? 0);
        $payoutDate  = sanitize($_POST['payout_date'] ?? date('Y-m-01'));
        $payoutType  = sanitize($_POST['payout_type'] ?? 'monthly');
        $isReceived  = (int)($_POST['is_received']  ?? 0);

        if (!$schemeId) json_response(false, 'scheme_id required');

        // Verify ownership
        $scheme = DB::fetchOne(
            "SELECT po.id, po.principal, po.monthly_payout_amount, po.quarterly_payout_amount,
                    po.scheme_type
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE po.id = ? AND p.user_id = ?
               AND po.scheme_type IN ('mis','scss')",
            [$schemeId, $userId]
        );
        if (!$scheme) json_response(false, 'Scheme not found');

        $amount = $payoutType === 'quarterly'
            ? (float)($scheme['quarterly_payout_amount'] ?? 0)
            : (float)($scheme['monthly_payout_amount']   ?? 0);

        DB::run(
            "INSERT INTO po_payout_log (po_scheme_id, portfolio_id, payout_type, payout_date, amount, is_received)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_received = VALUES(is_received), amount = VALUES(amount)",
            [$schemeId, $portfolioId, $payoutType, $payoutDate, $amount, $isReceived]
        );

        // Update running total on po_schemes
        $newTotal = (float) DB::fetchVal(
            "SELECT COALESCE(SUM(amount),0) FROM po_payout_log
             WHERE po_scheme_id = ? AND is_received = 1",
            [$schemeId]
        );
        $lastDate = DB::fetchVal(
            "SELECT MAX(payout_date) FROM po_payout_log
             WHERE po_scheme_id = ? AND is_received = 1",
            [$schemeId]
        );

        DB::run(
            "UPDATE po_schemes SET total_payout_received = ?, last_payout_date = ?, updated_at = NOW() WHERE id = ?",
            [$newTotal, $lastDate, $schemeId]
        );

        json_response(true, $isReceived ? 'Payout received marked ✅' : 'Payout unmarked', [
            'scheme_id'             => $schemeId,
            'payout_date'           => $payoutDate,
            'is_received'           => $isReceived,
            'total_payout_received' => $newTotal,
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // t15 — Generate payout schedule for a scheme
    // ═══════════════════════════════════════════════════════════════════════
    case 'po_payout_generate':
        $schemeId = (int)($_GET['scheme_id'] ?? $_POST['scheme_id'] ?? 0);
        if (!$schemeId) json_response(false, 'scheme_id required');

        $scheme = DB::fetchOne(
            "SELECT po.*
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE po.id = ? AND p.user_id = ?
               AND po.scheme_type IN ('mis','scss')",
            [$schemeId, $userId]
        );
        if (!$scheme) json_response(false, 'Scheme not found');

        $openDate    = new DateTime($scheme['opening_date']);
        $maturityDt  = new DateTime($scheme['maturity_date'] ?? '+5 years');
        $isMis       = $scheme['scheme_type'] === 'mis';
        $amount      = $isMis
            ? (float)($scheme['monthly_payout_amount'] ?? round((float)$scheme['principal'] * (float)$scheme['interest_rate'] / 1200, 2))
            : (float)($scheme['quarterly_payout_amount'] ?? round((float)$scheme['principal'] * (float)$scheme['interest_rate'] / 400, 2));
        $payoutType  = $isMis ? 'monthly' : 'quarterly';
        $interval    = $isMis ? 'P1M' : 'P3M';

        // Fetch existing log
        $existing = DB::fetchAll(
            "SELECT payout_date, is_received, amount FROM po_payout_log
             WHERE po_scheme_id = ? AND payout_type = ?",
            [$schemeId, $payoutType]
        );
        $existingMap = array_column($existing, null, 'payout_date');

        $schedule = [];
        $current  = clone $openDate;
        $current->modify('+1 ' . ($isMis ? 'month' : '3 months'));
        // SCSS: snap to first of payout months (Apr/Jul/Oct/Jan)
        if (!$isMis) {
            $current = _scss_snap_to_quarter($current);
        }

        while ($current <= $maturityDt) {
            $key  = $current->format('Y-m-d');
            $log  = $existingMap[$key] ?? null;
            $today = new DateTime();
            $schedule[] = [
                'payout_date'  => $key,
                'amount'       => $log ? (float)$log['amount'] : $amount,
                'is_received'  => $log ? (int)$log['is_received'] : 0,
                'is_past'      => $current < $today,
                'is_this_month'=> $current->format('Y-m') === date('Y-m'),
            ];
            $current->add(new DateInterval($interval));
        }

        json_response(true, '', [
            'scheme_id'   => $schemeId,
            'scheme_type' => $scheme['scheme_type'],
            'payout_type' => $payoutType,
            'amount'      => $amount,
            'schedule'    => $schedule,
            'total_payouts'      => count($schedule),
            'received_count'     => count(array_filter($schedule, fn($s) => $s['is_received'])),
            'total_received_amt' => array_sum(array_column(array_filter($schedule, fn($s) => $s['is_received']), 'amount')),
        ]);
        break;

    // ═══════════════════════════════════════════════════════════════════════
    // t16 — KVP Tenure Calculator + Annual Tax Summary
    // ═══════════════════════════════════════════════════════════════════════
    case 'kvp_tenure_calc':
        /**
         * KVP doubles money. GoI announces exact tenure in months.
         * Interest is taxable yearly under IFOS (no TDS, self-declare in ITR).
         */
        $rate           = (float)($_GET['rate']     ?? 7.5);
        $tenureMonths   = (int)  ($_GET['tenure']   ?? KVP_TENURE_BY_RATE[(string)$rate] ?? 115);
        $principal      = (float)($_GET['principal']?? 0);
        $openDateStr    = sanitize($_GET['open_date'] ?? date('Y-m-d'));

        if ($rate <= 0 || $tenureMonths <= 0) {
            json_response(false, 'Invalid rate or tenure');
        }

        $openDate    = new DateTime($openDateStr);
        $maturityDt  = clone $openDate;
        $maturityDt->add(new DateInterval("P{$tenureMonths}M"));

        // KVP interest: compounds half-yearly by post office
        // Maturity = 2 × principal (double money)
        $maturityAmt = $principal * 2;

        // Year-wise accrual (for tax declaration in ITR)
        $yearlySchedule = [];
        if ($principal > 0) {
            $halfYearRate = $rate / 200; // KVP compounds half-yearly
            $balance      = $principal;
            $prevBalance  = $principal;
            $start        = clone $openDate;

            for ($h = 1; $h <= ceil($tenureMonths / 6); $h++) {
                $balance *= (1 + $halfYearRate);
                if ($h % 2 === 0 || $h === (int)ceil($tenureMonths / 6)) {
                    // Every 2 half-years = 1 year
                    $yr       = (int)ceil($h / 2);
                    $annInt   = round($balance - $prevBalance, 2);
                    $yearEnd  = clone $start;
                    $yearEnd->add(new DateInterval('P1Y'));
                    $fy       = _get_fy_from_date($yearEnd->format('Y-m-d'));
                    $yearlySchedule[] = [
                        'year'            => $yr,
                        'fy'              => $fy,
                        'balance_start'   => round($prevBalance, 2),
                        'balance_end'     => round($balance, 2),
                        'taxable_interest'=> $annInt,
                        'tax_30pct_slab'  => null, // User's slab — show in UI
                    ];
                    $prevBalance = $balance;
                    $start->add(new DateInterval('P1Y'));
                }
            }
        }

        json_response(true, '', [
            'rate'                   => $rate,
            'tenure_months'          => $tenureMonths,
            'tenure_years_months'    => floor($tenureMonths / 12) . 'y ' . ($tenureMonths % 12) . 'm',
            'open_date'              => $openDateStr,
            'maturity_date'          => $maturityDt->format('Y-m-d'),
            'principal'              => $principal,
            'maturity_amount'        => round($maturityAmt, 2),
            'total_interest'         => round($maturityAmt - $principal, 2),
            'yearly_tax_schedule'    => $yearlySchedule,
            'tax_note'               => 'KVP interest: har saal ITR mein "Income from Other Sources" mein declare karna hoga. Koi TDS nahi hota.',
            'known_tenures'          => KVP_TENURE_BY_RATE,
        ]);
        break;

    case 'kvp_tax_summary':
        /**
         * FY-wise taxable interest summary for all user's KVP holdings.
         */
        $fy = sanitize($_GET['fy'] ?? '');
        [$fyStart, $fyEnd] = _get_fy_dates($fy);

        $kvpRows = DB::fetchAll(
            "SELECT po.id, po.holder_name, po.principal, po.interest_rate,
                    po.opening_date, po.maturity_date, po.kvp_tenure_months,
                    po.kvp_annual_taxable_interest, po.status
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id = ? AND po.scheme_type = 'kvp'
               AND po.opening_date <= ?
               AND (po.maturity_date IS NULL OR po.maturity_date >= ?)
             ORDER BY po.opening_date ASC",
            [$userId, $fyEnd, $fyStart]
        );

        $totalTaxableInterest = 0;
        foreach ($kvpRows as &$k) {
            $rate     = (float)($k['interest_rate'] ?? 7.5);
            $principal = (float)($k['principal'] ?? 0);
            // Annual taxable = accrual based on half-yearly compounding
            $halfRate  = $rate / 200;
            $openDate  = new DateTime($k['opening_date']);
            $fyStartDt = new DateTime($fyStart);
            $fyEndDt   = new DateTime($fyEnd);

            // Half-year periods elapsed until FY start
            $monthsToFyStart = max(0, (int)$openDate->diff($fyStartDt)->m +
                               ($openDate->diff($fyStartDt)->y * 12));
            $hlStart = floor($monthsToFyStart / 6);
            $hlEnd   = ceil(min(
                $openDate->diff($fyEndDt)->m + $openDate->diff($fyEndDt)->y * 12,
                (float)($k['kvp_tenure_months'] ?? 115)
            ) / 6);

            $balStart = $principal * pow(1 + $halfRate, $hlStart);
            $balEnd   = $principal * pow(1 + $halfRate, $hlEnd);
            $fyInterest = round($balEnd - $balStart, 2);

            $k['fy_taxable_interest']  = $fyInterest;
            $k['fy_balance_at_start']  = round($balStart, 2);
            $k['fy_balance_at_end']    = round($balEnd, 2);
            $totalTaxableInterest     += $fyInterest;
        }
        unset($k);

        json_response(true, '', [
            'fy'                    => $fy ?: _current_fy_label(),
            'fy_start'              => $fyStart,
            'fy_end'                => $fyEnd,
            'kvp_holdings'          => $kvpRows,
            'total_taxable_interest'=> round($totalTaxableInterest, 2),
            'tax_note'              => 'Ye interest IFOS mein taxable hai. Apne slab ke hisaab se tax calculate karo.',
        ]);
        break;

    default:
        json_response(false, "Unknown action: {$action}");
}

// ────────────────────────────────────────────────────────────────────────────
// HELPER FUNCTIONS
// ────────────────────────────────────────────────────────────────────────────

function _mis_payout_status(array $m): string {
    if (empty($m['last_payout_date'])) return 'pending_first';
    $monthsElapsed = max(1, (int)($m['months_elapsed'] ?? 1));
    $expected = (float)($m['monthly_payout_amount'] ?? 0) * $monthsElapsed;
    $received = (float)($m['total_payout_received'] ?? 0);
    if ($received >= $expected * 0.95) return 'up_to_date';
    if ($received > 0) return 'partial';
    return 'no_payouts';
}

function _scss_next_payout_date(): string {
    $today = new DateTime();
    $month = (int)$today->format('n');
    // SCSS pays in Jan(1), Apr(4), Jul(7), Oct(10)
    $payMonths = [1, 4, 7, 10];
    foreach ($payMonths as $pm) {
        if ($pm > $month) {
            return $today->format('Y') . '-' . str_pad((string)$pm, 2, '0', STR_PAD_LEFT) . '-01';
        }
    }
    return ($today->format('Y') + 1) . '-01-01';
}

function _scss_snap_to_quarter(DateTime $dt): DateTime {
    $month = (int)$dt->format('n');
    // Snap to next payout month: Jan/Apr/Jul/Oct
    $payMonths = [1, 4, 7, 10];
    foreach ($payMonths as $pm) {
        if ($pm >= $month) {
            $dt->setDate((int)$dt->format('Y'), $pm, 1);
            return $dt;
        }
    }
    $dt->setDate((int)$dt->format('Y') + 1, 1, 1);
    return $dt;
}

function _get_fy_from_date(string $date): string {
    $y = (int)date('Y', strtotime($date));
    $m = (int)date('n', strtotime($date));
    $fy_start = $m >= 4 ? $y : $y - 1;
    return $fy_start . '-' . ($fy_start + 1);
}

function _current_fy_label(): string {
    $y = (int)date('Y');
    $m = (int)date('n');
    $fy_start = $m >= 4 ? $y : $y - 1;
    return $fy_start . '-' . ($fy_start + 1);
}

function _get_fy_dates(string $fy = ''): array {
    if ($fy && preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
        return [$m[1] . '-04-01', $m[2] . '-03-31'];
    }
    $y = (int)date('Y');
    $mo = (int)date('n');
    $fys = $mo >= 4 ? $y : $y - 1;
    return [$fys . '-04-01', ($fys + 1) . '-03-31'];
}
