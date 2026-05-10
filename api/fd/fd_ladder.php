<?php
/**
 * WealthDash — FD Laddering Visualization API (t44)
 *
 * GET /api/?action=fd_ladder&portfolio_id=X
 *   Returns:
 *     fds[]          — all active FDs with timeline data
 *     timeline[]     — sorted list of maturity events by date
 *     yearly_summary — principal + maturity grouped by calendar year
 *     monthly_summary— principal + maturity grouped by month (next 24m)
 *     ladder_score   — how well-laddered the portfolio is (0–100)
 *     gaps[]         — periods with no maturity (liquidity gap analysis)
 *     reinvest_plan  — suggested reinvestment strategy
 *     totals         — aggregate stats
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$userId      = (int)$currentUser['id'];
$today       = new DateTimeImmutable('today');

/* ── 1. Fetch active FDs ─────────────────────────────────────── */
$portCond = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

$fds = DB::fetchAll(
    "SELECT fa.*,
            fa.principal_amount AS principal,
            fa.interest_rate    AS rate,
            p.name AS portfolio_name,
            DATEDIFF(fa.maturity_date, CURDATE()) AS days_left,
            ROUND(fa.maturity_amount - fa.principal_amount, 2) AS interest_earned,
            TIMESTAMPDIFF(MONTH, fa.start_date, fa.maturity_date) AS tenure_months
     FROM fd_accounts fa
     JOIN portfolios p ON p.id = fa.portfolio_id
     WHERE fa.status = 'active' {$portCond}
     ORDER BY fa.maturity_date ASC"
);

if (empty($fds)) {
    json_response(true, '', [
        'fds'            => [],
        'timeline'       => [],
        'yearly_summary' => [],
        'monthly_summary'=> [],
        'ladder_score'   => 0,
        'gaps'           => [],
        'reinvest_plan'  => [],
        'totals'         => ['count' => 0, 'principal' => 0, 'maturity' => 0, 'interest' => 0],
    ]);
}

/* ── 2. Enrich FDs with color + position data ───────────────── */
$palette = ['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#14b8a6','#f97316','#6366f1'];
$ci = 0;

$minDate = new DateTimeImmutable($fds[0]['start_date']);
$maxDate = new DateTimeImmutable(end($fds)['maturity_date']);
$totalDays = max(1, (int)$minDate->diff($maxDate)->days + 30);

foreach ($fds as &$fd) {
    $start    = new DateTimeImmutable($fd['start_date']);
    $maturity = new DateTimeImmutable($fd['maturity_date']);
    $tenure   = max(1, (int)$start->diff($maturity)->days);
    $offsetDays = max(0, (int)$minDate->diff($start)->days);

    $fd['color']        = $palette[$ci % count($palette)];
    $fd['left_pct']     = round($offsetDays / $totalDays * 100, 2);
    $fd['width_pct']    = round($tenure / $totalDays * 100, 2);
    $fd['tenure_days']  = $tenure;
    $fd['days_left']    = (int)$fd['days_left'];
    $fd['principal']    = (float)$fd['principal'];
    $fd['maturity_amount'] = (float)$fd['maturity_amount'];
    $fd['interest_earned'] = (float)$fd['interest_earned'];
    $fd['rate']         = (float)$fd['rate'];
    $fd['tenure_months']= (int)$fd['tenure_months'];

    // Maturity quarter label
    $matY  = (int)$maturity->format('Y');
    $matQ  = ceil((int)$maturity->format('n') / 3);
    $fd['maturity_quarter'] = "Q{$matQ} FY" . ($maturity->format('n') >= 4 ? $matY.'-'.substr($matY+1,2) : ($matY-1).'-'.substr($matY,2));
    $fd['maturity_month_label'] = $maturity->format('M Y');

    $ci++;
}
unset($fd);

/* ── 3. Timeline events ──────────────────────────────────────── */
$timeline = array_map(fn($fd) => [
    'id'            => $fd['id'],
    'bank_name'     => $fd['bank_name'],
    'maturity_date' => $fd['maturity_date'],
    'maturity_amount'=> $fd['maturity_amount'],
    'principal'     => $fd['principal'],
    'interest'      => $fd['interest_earned'],
    'days_left'     => $fd['days_left'],
    'color'         => $fd['color'],
    'rate'          => $fd['rate'],
    'tenure_months' => $fd['tenure_months'],
], $fds);

/* ── 4. Yearly summary (next 5 years) ────────────────────────── */
$yearlyMap = [];
foreach ($fds as $fd) {
    $yr = (int)(new DateTimeImmutable($fd['maturity_date']))->format('Y');
    if (!isset($yearlyMap[$yr])) {
        $yearlyMap[$yr] = ['year' => $yr, 'count' => 0, 'principal' => 0.0, 'maturity' => 0.0, 'interest' => 0.0, 'fds' => []];
    }
    $yearlyMap[$yr]['count']++;
    $yearlyMap[$yr]['principal'] += $fd['principal'];
    $yearlyMap[$yr]['maturity']  += $fd['maturity_amount'];
    $yearlyMap[$yr]['interest']  += $fd['interest_earned'];
    $yearlyMap[$yr]['fds'][]     = $fd['bank_name'];
}
ksort($yearlyMap);
$yearlySummary = array_values($yearlyMap);

/* ── 5. Monthly summary (next 24 months) ─────────────────────── */
$cutoff = $today->modify('+24 months');
$monthlyMap = [];
foreach ($fds as $fd) {
    $matDate = new DateTimeImmutable($fd['maturity_date']);
    if ($matDate > $cutoff) continue;
    $key = $matDate->format('Y-m');
    if (!isset($monthlyMap[$key])) {
        $monthlyMap[$key] = [
            'month'    => $key,
            'label'    => $matDate->format('M Y'),
            'count'    => 0,
            'principal'=> 0.0,
            'maturity' => 0.0,
            'interest' => 0.0,
            'fds'      => [],
        ];
    }
    $monthlyMap[$key]['count']++;
    $monthlyMap[$key]['principal'] += $fd['principal'];
    $monthlyMap[$key]['maturity']  += $fd['maturity_amount'];
    $monthlyMap[$key]['interest']  += $fd['interest_earned'];
    $monthlyMap[$key]['fds'][]     = $fd['bank_name'];
}
ksort($monthlyMap);
$monthlySummary = array_values($monthlyMap);

/* ── 6. Gap analysis — identify liquidity dry-spells ─────────── */
$gaps = [];
if (count($fds) >= 2) {
    for ($i = 0; $i < count($fds) - 1; $i++) {
        $prev = new DateTimeImmutable($fds[$i]['maturity_date']);
        $next = new DateTimeImmutable($fds[$i + 1]['maturity_date']);
        $gapDays = (int)$prev->diff($next)->days;
        if ($gapDays > 90) { // flag gaps > 3 months
            $gaps[] = [
                'from'     => $fds[$i]['maturity_date'],
                'to'       => $fds[$i + 1]['maturity_date'],
                'gap_days' => $gapDays,
                'gap_label'=> round($gapDays / 30, 1) . ' months',
            ];
        }
    }
}

/* ── 7. Ladder Score (0–100) ─────────────────────────────────── */
// Score is based on:
// a) Distribution evenness across time
// b) Number of rungs (FDs) — more is better up to 5+
// c) Tenure diversity
// d) No large gaps
$score = 50; // base

$numFds = count($fds);
if ($numFds >= 5) $score += 20;
elseif ($numFds >= 3) $score += 12;
elseif ($numFds >= 2) $score += 5;

// Tenure diversity
$tenures = array_unique(array_column($fds, 'tenure_months'));
$diversity = count($tenures);
if ($diversity >= 4) $score += 15;
elseif ($diversity >= 2) $score += 8;

// Penalise for large gaps
$bigGaps = count(array_filter($gaps, fn($g) => $g['gap_days'] > 180));
$score -= $bigGaps * 10;

// Even distribution across calendar years
$spreadYears = count($yearlyMap);
if ($spreadYears >= 3) $score += 15;
elseif ($spreadYears >= 2) $score += 8;

$score = max(0, min(100, $score));

// Ladder level label
$ladderLevel = match(true) {
    $score >= 80 => ['label' => 'Excellent', 'color' => '#16a34a'],
    $score >= 60 => ['label' => 'Good',      'color' => '#2563eb'],
    $score >= 40 => ['label' => 'Fair',       'color' => '#d97706'],
    default      => ['label' => 'Weak',       'color' => '#dc2626'],
};

/* ── 8. Reinvestment plan (simple suggestion) ─────────────────── */
$reinvestPlan = [];
foreach ($fds as $fd) {
    $daysLeft = $fd['days_left'];
    if ($daysLeft > 365) continue; // only upcoming 1yr
    $suggestion = match(true) {
        $daysLeft <= 0  => 'Already matured — reinvest immediately',
        $daysLeft <= 30 => 'Maturing soon — decide: renew or redeploy to liquid fund',
        $daysLeft <= 90 => 'Maturing in 3 months — start researching best rates',
        default         => 'Maturing in ' . round($daysLeft/30) . ' months — monitor rates',
    };
    $reinvestPlan[] = [
        'fd_id'      => $fd['id'],
        'bank'       => $fd['bank_name'],
        'maturity_date'=> $fd['maturity_date'],
        'maturity_amount'=> $fd['maturity_amount'],
        'days_left'  => $daysLeft,
        'suggestion' => $suggestion,
        'color'      => $fd['color'],
    ];
}

/* ── 9. Totals ───────────────────────────────────────────────── */
$totals = [
    'count'     => count($fds),
    'principal' => array_sum(array_column($fds, 'principal')),
    'maturity'  => array_sum(array_column($fds, 'maturity_amount')),
    'interest'  => array_sum(array_column($fds, 'interest_earned')),
    'avg_rate'  => count($fds) > 0 ? round(array_sum(array_column($fds, 'rate')) / count($fds), 2) : 0,
    'timeline_start' => $fds[0]['start_date'] ?? null,
    'timeline_end'   => end($fds)['maturity_date'] ?? null,
    'total_days'     => $totalDays,
];

json_response(true, '', [
    'fds'            => $fds,
    'timeline'       => $timeline,
    'yearly_summary' => $yearlySummary,
    'monthly_summary'=> $monthlySummary,
    'ladder_score'   => $score,
    'ladder_level'   => $ladderLevel,
    'gaps'           => $gaps,
    'reinvest_plan'  => $reinvestPlan,
    'totals'         => $totals,
]);
