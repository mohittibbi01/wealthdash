<?php
/**
 * WealthDash — t485: Treemap — Portfolio by Value Visual
 * File: api/portfolio/treemap.php
 * Actions: portfolio_treemap
 *
 * DIFFERENT from t300 (heatmap colored by RETURN) and t446 (health score
 * heatmap). This treemap sizes/colors boxes purely by VALUE — classic
 * "which holdings make up my portfolio" visual, grouped by category
 * with squarified rectangle layout computed server-side.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'portfolio_treemap') { json_response(false,'Unknown action.',[],400); }

$groupBy = clean($_GET['group_by'] ?? 'category'); // category | fund

function _guessCatTM(string $name): string {
    $n = strtolower($name);
    if (str_contains($n,'small cap')) return 'Small Cap';
    if (str_contains($n,'mid cap'))   return 'Mid Cap';
    if (str_contains($n,'large cap')||str_contains($n,'bluechip')) return 'Large Cap';
    if (str_contains($n,'flexi')||str_contains($n,'multi cap'))    return 'Flexi Cap';
    if (str_contains($n,'debt')||str_contains($n,'bond')||str_contains($n,'gilt')||str_contains($n,'liquid')) return 'Debt';
    if (str_contains($n,'hybrid')||str_contains($n,'balanced'))    return 'Hybrid';
    if (str_contains($n,'gold'))    return 'Gold';
    if (str_contains($n,'elss')||str_contains($n,'tax'))           return 'ELSS';
    if (str_contains($n,'international')||str_contains($n,'global')) return 'International';
    return 'Equity';
}

$holdings = DB::fetchAll(
    "SELECT h.fund_name, h.units, h.units*COALESCE(n.nav,h.avg_cost_per_unit) AS value
     FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id
     WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0
     ORDER BY value DESC",
    [$userId, $portfolioId]
);

$totalValue = array_sum(array_column($holdings, 'value'));

if ($groupBy === 'category') {
    $grouped = [];
    foreach ($holdings as $h) {
        $cat = _guessCatTM($h['fund_name']);
        if (!isset($grouped[$cat])) $grouped[$cat] = ['name'=>$cat, 'value'=>0, 'count'=>0];
        $grouped[$cat]['value'] += (float)$h['value'];
        $grouped[$cat]['count']++;
    }
    $items = array_values($grouped);
} else {
    $items = array_map(fn($h) => ['name'=>$h['fund_name'], 'value'=>(float)$h['value'], 'count'=>1], $holdings);
}

// Sort descending by value
usort($items, fn($a,$b) => $b['value'] <=> $a['value']);

// Compute weight % and assign colors
$colors = ['#2563EB','#7C3AED','#059669','#DC2626','#D97706','#0891B2','#BE185D','#4338CA','#047857','#92400E'];
foreach ($items as $i => &$item) {
    $item['weight_pct'] = $totalValue > 0 ? round($item['value']/$totalValue*100, 2) : 0;
    $item['color'] = $colors[$i % count($colors)];
}
unset($item);

// Squarified treemap layout algorithm (simple version)
// Canvas dimensions assumed 100 x 60 units (frontend scales to actual px)
$layout = _squarify($items, 0, 0, 100, 60);

json_response(true,'ok',[
    'items'       => $items,
    'layout'      => $layout,
    'total_value' => round($totalValue, 2),
    'group_by'    => $groupBy,
]);

// ── Simple squarified treemap algorithm ───────────────────────────
function _squarify(array $items, float $x, float $y, float $w, float $h): array {
    if (empty($items)) return [];
    $total = array_sum(array_column($items, 'value'));
    if ($total <= 0) return [];

    $result = [];
    $remaining = $items;
    $rx = $x; $ry = $y; $rw = $w; $rh = $h;

    while (!empty($remaining)) {
        $remainingTotal = array_sum(array_column($remaining, 'value'));
        $isWide = $rw >= $rh;
        $rowLength = $isWide ? $rh : $rw;

        // Take items for this row (simple greedy: just take proportional chunk)
        $row = [];
        $rowValue = 0;
        $targetRowValue = $remainingTotal * (($isWide ? $rw : $rh) > 0 ? min(1, ($rowLength*$rowLength)/$remainingTotal*0.3+0.15) : 1);

        foreach ($remaining as $idx => $item) {
            $row[] = $item;
            $rowValue += $item['value'];
            unset($remaining[$idx]);
            if ($rowValue >= $targetRowValue && count($row) >= 1) break;
        }
        $remaining = array_values($remaining);

        if ($isWide) {
            $rowWidth = $rw * ($rowValue / $remainingTotal > 0 ? min(1,$rowValue/($rowValue+array_sum(array_column($remaining,'value'))?:1)) : 1);
            $rowWidth = $remainingTotal > 0 ? $rw * ($rowValue / ($rowValue + array_sum(array_column($remaining,'value')) ?: $rowValue)) : $rw;
            $cy = $ry;
            foreach ($row as $item) {
                $itemH = $rowValue > 0 ? $rh * ($item['value']/$rowValue) : 0;
                $result[] = ['name'=>$item['name'],'x'=>round($rx,2),'y'=>round($cy,2),'w'=>round($rowWidth,2),'h'=>round($itemH,2),'value'=>$item['value'],'color'=>$item['color']??'#2563EB','weight_pct'=>$item['weight_pct']??0];
                $cy += $itemH;
            }
            $rx += $rowWidth; $rw -= $rowWidth;
        } else {
            $rowHeight = $remainingTotal > 0 ? $rh * ($rowValue / ($rowValue + array_sum(array_column($remaining,'value')) ?: $rowValue)) : $rh;
            $cx = $rx;
            foreach ($row as $item) {
                $itemW = $rowValue > 0 ? $rw * ($item['value']/$rowValue) : 0;
                $result[] = ['name'=>$item['name'],'x'=>round($cx,2),'y'=>round($ry,2),'w'=>round($itemW,2),'h'=>round($rowHeight,2),'value'=>$item['value'],'color'=>$item['color']??'#2563EB','weight_pct'=>$item['weight_pct']??0];
                $cx += $itemW;
            }
            $ry += $rowHeight; $rh -= $rowHeight;
        }

        if ($rw <= 0.01 || $rh <= 0.01) break;
    }

    return $result;
}
