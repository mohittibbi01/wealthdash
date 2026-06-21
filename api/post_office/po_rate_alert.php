<?php
/**
 * WealthDash — Post Office Rate Change Alert (t320)
 * Shows current quarter rates, detects rate changes vs user's booked rates,
 * alerts when new rates are notified by government.
 * GET /api/?action=po_rate_alert
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

/* ─── Current official rates (Q1 FY2025-26, Apr-Jun 2025) ───────────────── */
// Source: Ministry of Finance / India Post quarterly notification
// Updated: April 2025
$currentRates = [
    'ppf'  => ['rate' => 7.1,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 7.1],
    'ssy'  => ['rate' => 8.2,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 8.2],
    'scss' => ['rate' => 8.2,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 8.2],
    'mis'  => ['rate' => 7.4,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 7.4],
    'nsc'  => ['rate' => 7.7,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 7.7],
    'kvp'  => ['rate' => 7.5,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 7.5],
    'td'   => ['rate' => 7.5,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 7.5, 'note' => '5yr TD; 1yr=6.9%, 2yr=7.0%, 3yr=7.1%'],
    'rd'   => ['rate' => 6.7,  'quarter' => 'Q1 FY26 (Apr–Jun 2025)', 'effective' => '2025-04-01', 'change' => 0.0,  'prev_rate' => 6.7],
];

// Rate history (last 4 quarters for trend)
$rateHistory = [
    'ppf'  => [['q'=>'Q4 FY25','rate'=>7.1],['q'=>'Q3 FY25','rate'=>7.1],['q'=>'Q2 FY25','rate'=>7.1],['q'=>'Q1 FY25','rate'=>7.1]],
    'ssy'  => [['q'=>'Q4 FY25','rate'=>8.2],['q'=>'Q3 FY25','rate'=>8.2],['q'=>'Q2 FY25','rate'=>8.2],['q'=>'Q1 FY25','rate'=>8.2]],
    'scss' => [['q'=>'Q4 FY25','rate'=>8.2],['q'=>'Q3 FY25','rate'=>8.2],['q'=>'Q2 FY25','rate'=>8.2],['q'=>'Q1 FY25','rate'=>8.2]],
    'mis'  => [['q'=>'Q4 FY25','rate'=>7.4],['q'=>'Q3 FY25','rate'=>7.4],['q'=>'Q2 FY25','rate'=>7.4],['q'=>'Q1 FY25','rate'=>7.4]],
    'nsc'  => [['q'=>'Q4 FY25','rate'=>7.7],['q'=>'Q3 FY25','rate'=>7.7],['q'=>'Q2 FY25','rate'=>7.7],['q'=>'Q1 FY25','rate'=>7.7]],
    'kvp'  => [['q'=>'Q4 FY25','rate'=>7.5],['q'=>'Q3 FY25','rate'=>7.5],['q'=>'Q2 FY25','rate'=>7.5],['q'=>'Q1 FY25','rate'=>7.5]],
    'td'   => [['q'=>'Q4 FY25','rate'=>7.5],['q'=>'Q3 FY25','rate'=>7.5],['q'=>'Q2 FY25','rate'=>7.5],['q'=>'Q1 FY25','rate'=>7.5]],
    'rd'   => [['q'=>'Q4 FY25','rate'=>6.7],['q'=>'Q3 FY25','rate'=>6.7],['q'=>'Q2 FY25','rate'=>6.7],['q'=>'Q1 FY25','rate'=>6.7]],
];

$labels = [
    'ppf'=>'PPF','ssy'=>'SSY','scss'=>'SCSS','mis'=>'MIS',
    'nsc'=>'NSC','kvp'=>'KVP','td'=>'Post Office TD','rd'=>'PO RD',
];
$icons = [
    'ppf'=>'🛡️','ssy'=>'👧','scss'=>'👴','mis'=>'💰',
    'nsc'=>'📜','kvp'=>'🌾','td'=>'📅','rd'=>'🔄',
];

/* ─── User's holdings — compare booked rate vs current rate ──────────────── */
$userHoldings = DB::fetchAll(
    "SELECT id, scheme_type, holder_name, principal, interest_rate,
            open_date, maturity_date, status, current_value
     FROM po_schemes
     WHERE portfolio_id = ? AND status = 'active'
     ORDER BY scheme_type, open_date",
    [$portfolioId]
);

$alerts    = [];
$holdingsCmp = [];

foreach ($userHoldings as $h) {
    $stype    = $h['scheme_type'];
    $booked   = (float)$h['interest_rate'];
    $current  = $currentRates[$stype]['rate'] ?? null;
    if ($current === null) continue;

    $diff = round($current - $booked, 2);
    $holdingsCmp[] = [
        'id'           => (int)$h['id'],
        'scheme'       => $stype,
        'scheme_label' => $labels[$stype] ?? $stype,
        'icon'         => $icons[$stype] ?? '📋',
        'holder'       => $h['holder_name'],
        'principal'    => (float)$h['principal'],
        'booked_rate'  => $booked,
        'current_rate' => $current,
        'rate_diff'    => $diff,
        'open_date'    => $h['open_date'],
        'maturity_date'=> $h['maturity_date'],
        'status'       => $h['status'],
    ];

    if (abs($diff) >= 0.1) {
        $alerts[] = [
            'scheme'    => $stype,
            'label'     => $labels[$stype] ?? $stype,
            'icon'      => $icons[$stype] ?? '📋',
            'holder'    => $h['holder_name'],
            'booked'    => $booked,
            'current'   => $current,
            'diff'      => $diff,
            'type'      => $diff > 0 ? 'rate_up' : 'rate_down',
            'message'   => $diff > 0
                ? "New {$labels[$stype]} rate is {$current}%, your booked rate {$booked}% is lower — consider reinvestment on maturity."
                : "New {$labels[$stype]} rate is {$current}%, your booked rate {$booked}% is higher — your existing investment earns more.",
        ];
    }
}

/* ─── Next rate revision estimate ────────────────────────────────────────── */
// India Post revises rates quarterly: Apr 1, Jul 1, Oct 1, Jan 1
$today = date('Y-m-d');
$month = (int)date('m');
$year  = (int)date('Y');
$revisionDates = [
    "{$year}-04-01", "{$year}-07-01", "{$year}-10-01", "{$year}-01-01",
    ($year+1)."-01-01", ($year+1)."-04-01",
];
$nextRevision = null;
foreach ($revisionDates as $d) {
    if ($d > $today) { $nextRevision = $d; break; }
}
$daysToRevision = $nextRevision ? (int)((strtotime($nextRevision) - time()) / 86400) : null;

json_response(true, 'PO rate alert data loaded.', [
    'current_rates'   => $currentRates,
    'rate_history'    => $rateHistory,
    'labels'          => $labels,
    'icons'           => $icons,
    'alerts'          => $alerts,
    'holdings_cmp'    => $holdingsCmp,
    'next_revision'   => $nextRevision,
    'days_to_revision'=> $daysToRevision,
    'as_of'           => date('d M Y'),
    'total_holdings'  => count($userHoldings),
    'alert_count'     => count($alerts),
]);
