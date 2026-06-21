<?php
/**
 * WealthDash — t300: Portfolio Heatmap (FIXED)
 * File: api/portfolio/heatmap.php
 * Removed: mf_funds JOIN (table doesn't exist)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'portfolio_heatmap') { json_response(false,'Unknown action.',[],400); }

$period   = clean($_GET['period'] ?? '1m');
$days     = match($period) { '1d'=>1,'1w'=>7,'1m'=>30,'3m'=>90,'6m'=>180,'1y'=>365, default=>30 };
$fromDate = date('Y-m-d', strtotime("-{$days} days"));

// No mf_funds JOIN — category guessed from fund_name
function _guessCatHM(string $name): string {
    $n = strtolower($name);
    if (str_contains($n,'liquid')||str_contains($n,'overnight')||str_contains($n,'money market')) return 'Liquid';
    if (str_contains($n,'debt')||str_contains($n,'bond')||str_contains($n,'gilt')) return 'Debt';
    if (str_contains($n,'small cap')||str_contains($n,'smallcap')) return 'Small Cap';
    if (str_contains($n,'mid cap')||str_contains($n,'midcap')) return 'Mid Cap';
    if (str_contains($n,'large cap')||str_contains($n,'largecap')||str_contains($n,'bluechip')) return 'Large Cap';
    if (str_contains($n,'hybrid')||str_contains($n,'balanced')) return 'Hybrid';
    if (str_contains($n,'gold')) return 'Gold';
    if (str_contains($n,'international')||str_contains($n,'global')||str_contains($n,'us ')) return 'International';
    if (str_contains($n,'elss')||str_contains($n,'tax')) return 'ELSS';
    return 'Equity';
}

$holdings = DB::fetchAll(
    "SELECT h.mf_id, h.fund_name, h.units,
            h.avg_cost_per_unit AS avg_cost,
            COALESCE(n.nav, h.avg_cost_per_unit) AS current_nav,
            h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS current_value,
            h.units * h.avg_cost_per_unit AS invested_value
     FROM mf_holdings h
     LEFT JOIN mf_nav_latest n ON n.mf_id = h.mf_id
     WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0
     ORDER BY current_value DESC",
    [$userId, $portfolioId]
);

$totalValue = array_sum(array_column($holdings, 'current_value'));
$cells = [];

foreach ($holdings as $h) {
    $currentValue  = (float)$h['current_value'];
    $investedValue = (float)$h['invested_value'];
    $gain          = $currentValue - $investedValue;
    $gainPct       = $investedValue > 0 ? ($gain / $investedValue) * 100 : 0;
    $weightPct     = $totalValue > 0 ? ($currentValue / $totalValue) * 100 : 0;

    // Historical NAV for period return
    $navFrom = DB::fetchVal(
        "SELECT nav FROM mf_nav_history WHERE mf_id=? AND nav_date<=? ORDER BY nav_date DESC LIMIT 1",
        [$h['mf_id'], $fromDate]
    );
    $periodReturn = ($navFrom && (float)$navFrom > 0)
        ? (((float)$h['current_nav'] - (float)$navFrom) / (float)$navFrom) * 100
        : null;

    $cells[] = [
        'mf_id'          => (int)$h['mf_id'],
        'fund_name'      => $h['fund_name'],
        'category'       => _guessCatHM($h['fund_name']),
        'current_value'  => round($currentValue, 2),
        'invested_value' => round($investedValue, 2),
        'gain'           => round($gain, 2),
        'gain_pct'       => round($gainPct, 2),
        'weight_pct'     => round($weightPct, 2),
        'period_return'  => $periodReturn !== null ? round($periodReturn, 2) : null,
        'display_return' => $periodReturn !== null ? $periodReturn : $gainPct,
    ];
}

usort($cells, fn($a,$b) => $b['display_return'] <=> $a['display_return']);

json_response(true,'ok',[
    'cells'      => $cells,
    'total_value'=> round($totalValue,2),
    'period'     => $period,
    'from_date'  => $fromDate,
    'count'      => count($cells),
]);
