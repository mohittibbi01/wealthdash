<?php
/**
 * WealthDash — Sunburst Chart Data API
 * Task t484: Portfolio hierarchical view — Asset Class → Category → Fund
 * Action: sunburst_data
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// Get portfolio
$pid = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$pid) {
    $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $r->execute([$userId]);
    $pid = (int)($r->fetchColumn() ?: 0);
}
if ($pid) {
    $chk = $db->prepare("SELECT id FROM portfolios WHERE id=? AND user_id=?");
    $chk->execute([$pid, $userId]);
    if (!$chk->fetch()) $pid = 0;
}

if (!$pid) {
    echo json_encode(['success' => false, 'error' => 'No portfolio found']);
    return;
}

// Fetch holdings with fund details
$stmt = $db->prepare("
    SELECT
      f.fund_name,
      f.category,
      f.sub_category,
      f.asset_class,
      mh.latest_value,
      mh.invested_value,
      ROUND((mh.latest_value - mh.invested_value) / NULLIF(mh.invested_value,0)*100, 2) AS gain_pct
    FROM mf_holdings mh
    JOIN funds f ON f.id = mh.fund_id
    WHERE mh.portfolio_id = ?
      AND mh.units > 0.001
      AND mh.latest_value > 0
    ORDER BY f.asset_class, f.category, mh.latest_value DESC
");
$stmt->execute([$pid]);
$holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$holdings) {
    echo json_encode(['success' => true, 'data' => ['total' => 0, 'nodes' => [], 'children' => []]]);
    return;
}

$totalValue = array_sum(array_column($holdings, 'latest_value'));

// ── Build 3-level hierarchy: Asset Class → Category → Fund ────────────
$tree = [];
foreach ($holdings as $h) {
    $assetClass = trim($h['asset_class'] ?: 'Other');
    $category   = trim($h['category']    ?: 'Uncategorised');
    $fundName   = trim($h['fund_name']);
    $val        = (float)$h['latest_value'];

    if (!isset($tree[$assetClass])) {
        $tree[$assetClass] = ['value' => 0, 'categories' => []];
    }
    $tree[$assetClass]['value'] += $val;

    if (!isset($tree[$assetClass]['categories'][$category])) {
        $tree[$assetClass]['categories'][$category] = ['value' => 0, 'funds' => []];
    }
    $tree[$assetClass]['categories'][$category]['value'] += $val;
    $tree[$assetClass]['categories'][$category]['funds'][] = [
        'name'      => $fundName,
        'value'     => round($val, 2),
        'gain_pct'  => (float)$h['gain_pct'],
        'pct_total' => $totalValue > 0 ? round($val / $totalValue * 100, 2) : 0,
    ];
}

// Asset class colour palette
$assetColors = [
    'Equity'       => '#3b82f6',
    'Debt'         => '#10b981',
    'Hybrid'       => '#8b5cf6',
    'Gold'         => '#f59e0b',
    'International'=> '#06b6d4',
    'Other'        => '#6b7280',
    'Liquid'       => '#14b8a6',
    'ELSS'         => '#f97316',
];

// Build D3-compatible JSON structure
$buildNode = function(string $name, float $value, array $children = [], string $color = '') use ($totalValue): array {
    return [
        'name'    => $name,
        'value'   => round($value, 2),
        'pct'     => $totalValue > 0 ? round($value / $totalValue * 100, 1) : 0,
        'color'   => $color,
        'children'=> $children,
    ];
};

$rootChildren = [];
arsort($tree); // biggest asset class first

$colorIdx = 0;
$defaultColors = ['#3b82f6','#10b981','#8b5cf6','#f59e0b','#06b6d4','#f97316','#ec4899','#6b7280'];

foreach ($tree as $assetClass => $assetData) {
    $color = $assetColors[$assetClass] ?? $defaultColors[$colorIdx % count($defaultColors)];
    $colorIdx++;

    $catChildren = [];
    arsort($assetData['categories']);
    foreach ($assetData['categories'] as $category => $catData) {
        $fundChildren = [];
        usort($catData['funds'], fn($a,$b) => $b['value'] <=> $a['value']);
        foreach ($catData['funds'] as $fund) {
            $fundChildren[] = $buildNode($fund['name'], $fund['value'], [], $color);
        }
        $catChildren[] = $buildNode($category, $catData['value'], $fundChildren, $color . 'aa');
    }
    $rootChildren[] = $buildNode($assetClass, $assetData['value'], $catChildren, $color);
}

// Summary stats per asset class
$summary = [];
foreach ($tree as $assetClass => $assetData) {
    $summary[] = [
        'asset_class' => $assetClass,
        'value'       => round($assetData['value'], 2),
        'pct'         => $totalValue > 0 ? round($assetData['value'] / $totalValue * 100, 1) : 0,
        'color'       => $assetColors[$assetClass] ?? $defaultColors[0],
        'fund_count'  => array_sum(array_map(fn($c) => count($c['funds']), $assetData['categories'])),
    ];
}
usort($summary, fn($a,$b) => $b['value'] <=> $a['value']);

echo json_encode([
    'success' => true,
    'data'    => [
        'total_value' => round($totalValue, 2),
        'fund_count'  => count($holdings),
        'summary'     => $summary,
        'hierarchy'   => [
            'name'     => 'Portfolio',
            'value'    => round($totalValue, 2),
            'children' => $rootChildren,
        ],
    ]
]);
