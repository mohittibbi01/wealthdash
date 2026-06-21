<?php
/**
 * WealthDash — NPS Contribution SIP Tracker (t197)
 * Tracks monthly NPS contributions like a SIP, shows streaks, targets, FY summary
 * GET /api/?action=nps_sip_tracker
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

$today = date('Y-m-d');
$year  = (int)($_GET['year'] ?? date('Y'));
$tier  = clean($_GET['tier'] ?? '');   // '' = all, 'tier1', 'tier2'

/* ─── Determine FY range ──────────────────────────────────────────────────── */
// Indian FY: Apr 1 – Mar 31
function fy_range(int $year): array {
    return ["{$year}-04-01", ($year+1)."-03-31"];
}
function fy_label(int $year): string {
    return "FY " . substr($year,2) . "-" . substr($year+1,2);
}

$fyStart = "{$year}-04-01";
$fyEnd   = ($year+1)."-03-31";
$tierCond = $tier ? "AND t.tier = " . DB::pdo()->quote($tier) : '';

/* ─── Monthly contribution data for the FY ───────────────────────────────── */
$monthly = DB::fetchAll(
    "SELECT DATE_FORMAT(t.txn_date,'%Y-%m') AS ym,
            MONTH(t.txn_date) AS mo,
            YEAR(t.txn_date)  AS yr,
            t.contribution_type,
            t.tier,
            COALESCE(SUM(t.amount),0) AS total_amount,
            COUNT(*)                   AS txn_count
     FROM nps_transactions t
     WHERE t.portfolio_id = ?
       AND t.txn_date BETWEEN ? AND ?
       AND t.txn_type = 'purchase'
       {$tierCond}
     GROUP BY ym, t.contribution_type, t.tier
     ORDER BY ym ASC",
    [$portfolioId, $fyStart, $fyEnd]
);

/* ─── Build month-by-month grid (Apr→Mar) ────────────────────────────────── */
$monthGrid = [];
for ($m = 4; $m <= 15; $m++) {
    $mo = $m <= 12 ? $m : $m - 12;
    $yr = $m <= 12 ? $year : $year + 1;
    $ym = sprintf('%04d-%02d', $yr, $mo);
    $monthGrid[$ym] = [
        'ym'       => $ym,
        'month'    => date('M Y', mktime(0,0,0,$mo,1,$yr)),
        'self'     => 0.0,
        'employer' => 0.0,
        'total'    => 0.0,
        'txns'     => 0,
        'future'   => $ym > substr($today,0,7),
    ];
}

foreach ($monthly as $r) {
    $ym = $r['ym'];
    if (!isset($monthGrid[$ym])) continue;
    $amt = (float)$r['total_amount'];
    if ($r['contribution_type'] === 'EMPLOYER') {
        $monthGrid[$ym]['employer'] += $amt;
    } else {
        $monthGrid[$ym]['self'] += $amt;
    }
    $monthGrid[$ym]['total'] += $amt;
    $monthGrid[$ym]['txns']  += (int)$r['txn_count'];
}

/* ─── Streak calculation ──────────────────────────────────────────────────── */
$currentStreak = 0;
$longestStreak = 0;
$streak        = 0;
$currentYm     = substr($today, 0, 7);
// Iterate months in reverse from current month
$gridArr = array_values($monthGrid);
$pastMonths = array_filter($gridArr, fn($r) => !$r['future'] && $r['ym'] <= $currentYm);
$pastMonths = array_reverse(array_values($pastMonths));

foreach ($pastMonths as $i => $r) {
    if ($r['total'] > 0) {
        $streak++;
        if ($i === 0) $currentStreak = $streak; // still counting from latest
        $longestStreak = max($longestStreak, $streak);
    } else {
        if ($i === 0) $currentStreak = 0;
        $streak = 0;
    }
}

/* ─── FY summary ──────────────────────────────────────────────────────────── */
$fySelf     = array_sum(array_column(array_values($monthGrid), 'self'));
$fyEmployer = array_sum(array_column(array_values($monthGrid), 'employer'));
$fyTotal    = $fySelf + $fyEmployer;

// 80CCD(1) limit = 10% of basic — we show user-configurable, default ₹1.5L
// 80CCD(1B) = 50k additional
$limit80CCD1  = 150000;
$limit80CCD1B = 50000;
$used80CCD1   = min($fySelf, $limit80CCD1);
$used80CCD1B  = min(max($fySelf - $limit80CCD1, 0), $limit80CCD1B);
$remaining1B  = max($limit80CCD1B - $used80CCD1B, 0);

/* ─── Monthly average & target tracking ──────────────────────────────────── */
$contributedMonths = count(array_filter($monthGrid, fn($r) => !$r['future'] && $r['total'] > 0));
$monthlyAvg        = $contributedMonths > 0 ? round($fyTotal / $contributedMonths, 2) : 0;
$monthsElapsed     = count(array_filter($monthGrid, fn($r) => !$r['future']));
$monthsRemaining   = 12 - $monthsElapsed;

/* ─── YoY comparison (last 3 FYs) ───────────────────────────────────────── */
$yoyData = [];
for ($fy = $year - 2; $fy <= $year; $fy++) {
    [$s, $e] = fy_range($fy);
    $row = DB::fetchRow(
        "SELECT COALESCE(SUM(CASE WHEN contribution_type='SELF' THEN amount ELSE 0 END),0) AS self_amt,
                COALESCE(SUM(CASE WHEN contribution_type='EMPLOYER' THEN amount ELSE 0 END),0) AS emp_amt,
                COALESCE(SUM(amount),0) AS total,
                COUNT(DISTINCT DATE_FORMAT(txn_date,'%Y-%m')) AS months_contrib
         FROM nps_transactions
         WHERE portfolio_id=? AND txn_date BETWEEN ? AND ? AND txn_type='purchase' {$tierCond}",
        [$portfolioId, $s, $e]
    );
    $yoyData[] = [
        'fy'              => fy_label($fy),
        'year'            => $fy,
        'self'            => round((float)$row['self_amt'], 2),
        'employer'        => round((float)$row['emp_amt'], 2),
        'total'           => round((float)$row['total'], 2),
        'months_contrib'  => (int)$row['months_contrib'],
    ];
}

/* ─── Available FYs ──────────────────────────────────────────────────────── */
$firstTxn = DB::fetchRow(
    "SELECT MIN(txn_date) AS d FROM nps_transactions WHERE portfolio_id=? AND txn_type='purchase'",
    [$portfolioId]
);
$firstYear = $firstTxn['d'] ? (int)date('Y', strtotime($firstTxn['d'])) : $year;
if ((int)date('m') < 4) $firstYear = min($firstYear, $year - 1);
$availFys = [];
for ($fy = $firstYear; $fy <= $year; $fy++) {
    $availFys[] = ['year' => $fy, 'label' => fy_label($fy)];
}

json_response(true, 'NPS SIP tracker loaded.', [
    'fy'              => fy_label($year),
    'fy_year'         => $year,
    'month_grid'      => array_values($monthGrid),
    'summary' => [
        'fy_self'            => round($fySelf, 2),
        'fy_employer'        => round($fyEmployer, 2),
        'fy_total'           => round($fyTotal, 2),
        'monthly_avg'        => $monthlyAvg,
        'months_elapsed'     => $monthsElapsed,
        'months_remaining'   => $monthsRemaining,
        'contributed_months' => $contributedMonths,
        'current_streak'     => $currentStreak,
        'longest_streak'     => $longestStreak,
    ],
    'tax_tracker' => [
        'used_80ccd1'      => round($used80CCD1, 2),
        'limit_80ccd1'     => $limit80CCD1,
        'used_80ccd1b'     => round($used80CCD1B, 2),
        'limit_80ccd1b'    => $limit80CCD1B,
        'remaining_1b'     => round($remaining1B, 2),
    ],
    'yoy'             => $yoyData,
    'available_fys'   => $availFys,
    'tier_filter'     => $tier ?: 'all',
]);
