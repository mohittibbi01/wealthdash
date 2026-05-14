<?php
/**
 * WealthDash — t498: Investment Calendar 2025-26
 * File: api/tools/investment_calendar.php
 * Actions: inv_calendar_events, inv_calendar_month
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    // ── ALL events for a FY (static + dynamic) ───────────────────────
    case 'inv_calendar_events': {
        $fy = clean($_GET['fy'] ?? $_POST['fy'] ?? '2025-26');
        $events = _get_all_events($userId, $portfolioId, $fy);
        json_response(true, 'ok', ['events' => $events, 'fy' => $fy]);
        break;
    }

    // ── Events for a specific month ───────────────────────────────────
    case 'inv_calendar_month': {
        $month = clean($_GET['month'] ?? date('Y-m')); // YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            json_response(false, 'Invalid month format.');
        }
        $fy = _month_to_fy($month);
        $all = _get_all_events($userId, $portfolioId, $fy);
        $filtered = array_values(array_filter($all, fn($e) => substr($e['date'], 0, 7) === $month));
        json_response(true, 'ok', ['events' => $filtered, 'month' => $month]);
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}

// ── Merge static + dynamic events ────────────────────────────────────────
function _get_all_events(int $userId, int $portfolioId, string $fy): array {
    $events = array_merge(
        _static_tax_dates($fy),
        _sip_events($userId, $portfolioId, $fy),
        _fd_maturity_events($userId, $fy),
        _emi_events($userId, $fy),
        _insurance_events($userId, $fy),
        _goal_review_events($userId, $fy)
    );
    // Sort by date
    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $events;
}

// ── Static Indian tax / investment important dates for FY 2025-26 ─────────
function _static_tax_dates(string $fy): array {
    $y = (int)substr($fy, 0, 4);   // e.g. 2025
    $ny = $y + 1;                   // 2026

    return [
        // ── FY start
        ['date' => "$y-04-01", 'type' => 'tax', 'category' => 'fiscal',
         'title' => 'FY Start — ' . $fy,
         'desc'  => 'New financial year begins. Review asset allocation & tax-saving investments.',
         'priority' => 'info'],

        // ── Advance Tax
        ['date' => "$y-06-15", 'type' => 'tax', 'category' => 'advance_tax',
         'title' => 'Advance Tax — Q1 (15%)',
         'desc'  => '15% of estimated tax liability due for Q1 Apr–Jun.',
         'priority' => 'warning'],
        ['date' => "$y-09-15", 'type' => 'tax', 'category' => 'advance_tax',
         'title' => 'Advance Tax — Q2 (45%)',
         'desc'  => '45% cumulative of estimated tax liability due.',
         'priority' => 'warning'],
        ['date' => "$y-12-15", 'type' => 'tax', 'category' => 'advance_tax',
         'title' => 'Advance Tax — Q3 (75%)',
         'desc'  => '75% cumulative of estimated tax liability due.',
         'priority' => 'warning'],
        ['date' => "$ny-03-15", 'type' => 'tax', 'category' => 'advance_tax',
         'title' => 'Advance Tax — Q4 (100%)',
         'desc'  => '100% of advance tax must be paid by 15 Mar.',
         'priority' => 'danger'],

        // ── ITR Filing
        ['date' => "$ny-07-31", 'type' => 'tax', 'category' => 'itr',
         'title' => 'ITR Filing Deadline (non-audit)',
         'desc'  => 'Last date to file Income Tax Return for FY ' . $fy . ' (non-audit cases).',
         'priority' => 'danger'],
        ['date' => "$ny-10-31", 'type' => 'tax', 'category' => 'itr',
         'title' => 'ITR Filing Deadline (audit cases)',
         'desc'  => 'Last date for audit cases & partners of firms.',
         'priority' => 'danger'],
        ['date' => "$ny-12-31", 'type' => 'tax', 'category' => 'itr',
         'title' => 'Belated / Revised ITR Deadline',
         'desc'  => 'Last date to file belated or revised return for FY ' . $fy . '.',
         'priority' => 'warning'],

        // ── 80C / ELSS / PPF
        ['date' => "$ny-03-31", 'type' => 'investment', 'category' => '80c',
         'title' => '80C Investment Deadline',
         'desc'  => 'Last day to make 80C investments (ELSS, PPF, NSC, ULIP, etc.) for FY ' . $fy . '. Limit: ₹1.5L.',
         'priority' => 'danger'],
        ['date' => "$y-04-05", 'type' => 'investment', 'category' => 'ppf',
         'title' => 'PPF — Invest by 5th Apr for full-year interest',
         'desc'  => 'PPF interest calculated on minimum balance between 5th and last day of month. Invest before 5th.',
         'priority' => 'info'],

        // ── NPS
        ['date' => "$ny-03-31", 'type' => 'investment', 'category' => 'nps',
         'title' => 'NPS 80CCD(1B) — ₹50,000 extra deduction deadline',
         'desc'  => 'Additional ₹50,000 NPS deduction under 80CCD(1B) must be invested before FY end.',
         'priority' => 'warning'],

        // ── LTCG / STCG tax review
        ['date' => "$ny-01-31", 'type' => 'tax', 'category' => 'ltcg',
         'title' => 'LTCG Grandfathering Reference Date',
         'desc'  => 'Review equity holdings for LTCG. Jan 31 NAV/price used as cost for pre-Feb 2018 purchases.',
         'priority' => 'info'],

        // ── TDS
        ['date' => "$y-07-31", 'type' => 'tax', 'category' => 'tds',
         'title' => 'TDS Return Q1 Due (Form 24Q/26Q)',
         'desc'  => 'Q1 TDS return due for deductors.',
         'priority' => 'info'],
        ['date' => "$y-10-31", 'type' => 'tax', 'category' => 'tds',
         'title' => 'TDS Return Q2 Due',
         'desc'  => 'Q2 (Jul–Sep) TDS return filing deadline.',
         'priority' => 'info'],
        ['date' => "$ny-01-31", 'type' => 'tax', 'category' => 'tds',
         'title' => 'TDS Return Q3 Due',
         'desc'  => 'Q3 (Oct–Dec) TDS return filing deadline.',
         'priority' => 'info'],
        ['date' => "$ny-05-31", 'type' => 'tax', 'category' => 'tds',
         'title' => 'TDS Return Q4 Due',
         'desc'  => 'Q4 (Jan–Mar) TDS return filing deadline.',
         'priority' => 'info'],

        // ── Half-year investment review reminders
        ['date' => "$y-09-30", 'type' => 'review', 'category' => 'portfolio',
         'title' => 'Mid-Year Portfolio Review',
         'desc'  => 'Review portfolio performance, rebalance if drift > 5%, check SIP step-up.',
         'priority' => 'info'],
        ['date' => "$ny-03-01", 'type' => 'review', 'category' => 'portfolio',
         'title' => 'Year-End Portfolio Review',
         'desc'  => 'Final review before FY close. Tax-loss harvesting, 80C top-up, asset rebalancing.',
         'priority' => 'warning'],

        // ── FY end
        ['date' => "$ny-03-31", 'type' => 'tax', 'category' => 'fiscal',
         'title' => 'FY End — ' . $fy,
         'desc'  => 'Financial year closes. Ensure all 80C, 80D, NPS, HRA proofs submitted.',
         'priority' => 'danger'],
    ];
}

// ── SIP debit dates from DB ───────────────────────────────────────────────
function _sip_events(int $userId, int $portfolioId, string $fy): array {
    $sips = DB::fetchAll(
        "SELECT fund_name, sip_amount, sip_date, sip_frequency, start_date, end_date
         FROM mf_sips
         WHERE user_id=? AND portfolio_id=? AND status='active'",
        [$userId, $portfolioId]
    );
    [$fyStart, $fyEnd] = _fy_range($fy);

    $events = [];
    foreach ($sips as $s) {
        $d = new DateTime($fyStart);
        $end = new DateTime(min($fyEnd, $s['end_date'] ?? $fyEnd));
        while ($d <= $end) {
            $dom = (int)($s['sip_date'] ?? 1);
            $month = $d->format('Y-m');
            $dt = $month . '-' . str_pad($dom, 2, '0', STR_PAD_LEFT);
            try { $dt = (new DateTime($dt))->format('Y-m-d'); } catch (\Exception) { $d->modify('+1 month'); continue; }
            if ($dt >= $fyStart && $dt <= $fyEnd && $dt >= ($s['start_date'] ?? $fyStart)) {
                $events[] = [
                    'date'     => $dt,
                    'type'     => 'sip',
                    'category' => 'sip',
                    'title'    => 'SIP — ' . ($s['fund_name'] ?? 'Fund'),
                    'desc'     => 'SIP debit of ' . formatINR_php((float)$s['sip_amount']),
                    'amount'   => (float)$s['sip_amount'],
                    'priority' => 'sip',
                ];
            }
            // next occurrence based on frequency
            $freq = strtolower($s['sip_frequency'] ?? 'monthly');
            match($freq) {
                'weekly'      => $d->modify('+1 week'),
                'fortnightly' => $d->modify('+2 weeks'),
                'quarterly'   => $d->modify('+3 months'),
                'yearly'      => $d->modify('+1 year'),
                default       => $d->modify('+1 month'),
            };
        }
    }
    return $events;
}

// ── FD maturity dates ─────────────────────────────────────────────────────
function _fd_maturity_events(int $userId, string $fy): array {
    [$fyStart, $fyEnd] = _fy_range($fy);
    $rows = DB::fetchAll(
        "SELECT fd_name, principal_amount, maturity_date, bank_name
         FROM fixed_deposits
         WHERE user_id=? AND maturity_date BETWEEN ? AND ?
           AND status='active'",
        [$userId, $fyStart, $fyEnd]
    );
    return array_map(fn($r) => [
        'date'     => $r['maturity_date'],
        'type'     => 'fd',
        'category' => 'fd_maturity',
        'title'    => 'FD Maturity — ' . ($r['bank_name'] ?? $r['fd_name'] ?? 'FD'),
        'desc'     => 'FD of ' . formatINR_php((float)$r['principal_amount']) . ' matures today. Renew or redeploy.',
        'amount'   => (float)$r['principal_amount'],
        'priority' => 'warning',
    ], $rows);
}

// ── EMI debit dates ───────────────────────────────────────────────────────
function _emi_events(int $userId, string $fy): array {
    $loans = DB::fetchAll(
        "SELECT loan_name, loan_type, emi_amount, emi_date, start_date, end_date
         FROM loans WHERE user_id=? AND status='active'",
        [$userId]
    );
    [$fyStart, $fyEnd] = _fy_range($fy);
    $events = [];
    foreach ($loans as $l) {
        $d = new DateTime(max($fyStart, $l['start_date'] ?? $fyStart));
        $end = new DateTime(min($fyEnd, $l['end_date'] ?? $fyEnd));
        while ($d <= $end) {
            $dom = (int)($l['emi_date'] ?? 5);
            $dt = $d->format('Y-m') . '-' . str_pad($dom, 2, '0', STR_PAD_LEFT);
            try { $dt = (new DateTime($dt))->format('Y-m-d'); } catch (\Exception) { $d->modify('+1 month'); continue; }
            if ($dt >= $fyStart && $dt <= $fyEnd) {
                $events[] = [
                    'date'     => $dt,
                    'type'     => 'emi',
                    'category' => 'emi',
                    'title'    => 'EMI — ' . ($l['loan_name'] ?? ucfirst($l['loan_type'] ?? 'Loan')),
                    'desc'     => 'EMI of ' . formatINR_php((float)$l['emi_amount']) . ' due.',
                    'amount'   => (float)$l['emi_amount'],
                    'priority' => 'emi',
                ];
            }
            $d->modify('+1 month');
        }
    }
    return $events;
}

// ── Insurance premium due dates ───────────────────────────────────────────
function _insurance_events(int $userId, string $fy): array {
    [$fyStart, $fyEnd] = _fy_range($fy);
    $rows = DB::fetchAll(
        "SELECT policy_name, insurer, premium_amount, next_premium_date, premium_frequency
         FROM insurance_policies
         WHERE user_id=? AND status='active'
           AND next_premium_date BETWEEN ? AND ?",
        [$userId, $fyStart, $fyEnd]
    );
    return array_map(fn($r) => [
        'date'     => $r['next_premium_date'],
        'type'     => 'insurance',
        'category' => 'insurance_premium',
        'title'    => 'Insurance Premium — ' . ($r['policy_name'] ?? $r['insurer'] ?? 'Policy'),
        'desc'     => 'Premium of ' . formatINR_php((float)$r['premium_amount']) . ' due (' . ($r['premium_frequency'] ?? '') . ').',
        'amount'   => (float)$r['premium_amount'],
        'priority' => 'warning',
    ], $rows);
}

// ── Goal review reminders ─────────────────────────────────────────────────
function _goal_review_events(int $userId, string $fy): array {
    [$fyStart, $fyEnd] = _fy_range($fy);
    $rows = DB::fetchAll(
        "SELECT goal_name, target_amount, target_date
         FROM goals WHERE user_id=? AND status='active'
           AND target_date BETWEEN ? AND ?",
        [$userId, $fyStart, $fyEnd]
    );
    return array_map(fn($r) => [
        'date'     => $r['target_date'],
        'type'     => 'goal',
        'category' => 'goal',
        'title'    => 'Goal Target — ' . $r['goal_name'],
        'desc'     => 'Goal target of ' . formatINR_php((float)$r['target_amount']) . '. Review progress.',
        'amount'   => (float)$r['target_amount'],
        'priority' => 'info',
    ], $rows);
}

// ── Helpers ───────────────────────────────────────────────────────────────
function _fy_range(string $fy): array {
    preg_match('/^(\d{4})-(\d{2})$/', $fy, $m);
    $start = $m[1] . '-04-01';
    $end   = '20' . $m[2] . '-03-31';
    return [$start, $end];
}

function _month_to_fy(string $month): string {
    [$y, $mo] = explode('-', $month);
    if ((int)$mo >= 4) return $y . '-' . substr($y+1, -2);
    return ($y-1) . '-' . substr($y, -2);
}

function formatINR_php(float $amount): string {
    $abs = abs($amount);
    $sign = $amount < 0 ? '-' : '';
    if ($abs >= 1e7) return $sign . '₹' . number_format($abs/1e7, 2) . ' Cr';
    if ($abs >= 1e5) return $sign . '₹' . number_format($abs/1e5, 2) . ' L';
    return $sign . '₹' . number_format($abs, 0);
}
