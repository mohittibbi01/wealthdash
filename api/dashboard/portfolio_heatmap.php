<?php
/**
 * WealthDash — t300: Portfolio Heatmap API
 * Returns all holdings (MF + Stocks) as a flat list for treemap/heatmap rendering.
 *
 * GET /api/router.php?action=portfolio_heatmap&group=asset_class|sector|fund_house
 *
 * Response: { cells: [...], total_value, max_gain_pct, min_gain_pct }
 * Each cell: { id, name, short, value, weight_pct, gain_pct, gain_abs, asset_type, group, color }
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$groupBy = in_array($_GET['group'] ?? 'asset_class', ['asset_class', 'sector', 'fund_house'])
    ? ($_GET['group'] ?? 'asset_class') : 'asset_class';
$portfolioId = (int)($_GET['portfolio_id'] ?? 0);

// ── Cache (tp001) ─────────────────────────────────────────────────────────
$cacheKey = "portfolio_heatmap:{$userId}:{$groupBy}:{$portfolioId}";
$cached   = WdCache::get($cacheKey);
if ($cached) {
    json_response(true, '', array_merge($cached, ['_cached' => true]));
}

// ── Portfolio filter ───────────────────────────────────────────────────────
$pids = [];
$pidRows = DB::fetchAll("SELECT id FROM portfolios WHERE user_id = ?", [$userId]);
foreach ($pidRows as $r) $pids[] = (int)$r['id'];
if ($portfolioId && in_array($portfolioId, $pids)) $pids = [$portfolioId];
if (!$pids) json_response(true, '', ['cells' => [], 'total_value' => 0]);
$pidList = implode(',', $pids);

$cells = [];

// ── 1. MF Holdings ────────────────────────────────────────────────────────
$mfRows = DB::fetchAll(
    "SELECT f.scheme_name, f.fund_house, f.category, f.sub_category,
            mh.current_value, mh.invested_value, mh.units,
            mh.current_nav, mh.prev_nav,
            COALESCE(mh.xirr, 0) AS xirr
     FROM mf_holdings mh
     JOIN funds f ON f.id = mh.fund_id
     WHERE mh.portfolio_id IN ($pidList)
       AND mh.units > 0 AND mh.current_value > 0
     ORDER BY mh.current_value DESC",
    []
);
foreach ($mfRows as $r) {
    $currVal   = (float)$r['current_value'];
    $investVal = (float)$r['invested_value'];
    $gainAbs   = $currVal - $investVal;
    $gainPct   = $investVal > 0 ? round($gainAbs / $investVal * 100, 2) : 0;
    $dayChgPct = ($r['prev_nav'] > 0 && $r['current_nav'] > 0)
        ? round(($r['current_nav'] - $r['prev_nav']) / $r['prev_nav'] * 100, 2) : 0;

    $group = match ($groupBy) {
        'fund_house' => $r['fund_house'] ?: 'Other',
        'sector'     => $r['sub_category'] ?: $r['category'] ?: 'Diversified',
        default      => 'Mutual Funds',
    };

    $short = _heatmapShort($r['scheme_name']);
    $cells[] = [
        'id'         => 'mf-' . md5($r['scheme_name']),
        'name'       => $r['scheme_name'],
        'short'      => $short,
        'value'      => round($currVal, 2),
        'invested'   => round($investVal, 2),
        'gain_abs'   => round($gainAbs, 2),
        'gain_pct'   => $gainPct,
        'day_chg'    => $dayChgPct,
        'xirr'       => round((float)$r['xirr'], 2),
        'asset_type' => 'MF',
        'group'      => $group,
        'color'      => _heatColor($gainPct),
    ];
}

// ── 2. Stock Holdings ─────────────────────────────────────────────────────
$stkRows = DB::fetchAll(
    "SELECT s.symbol, s.company_name, s.sector, s.current_price,
            sh.quantity, sh.avg_buy_price, sh.current_value, sh.invested_value,
            sh.unrealised_pnl, sh.prev_close
     FROM stock_holdings sh
     JOIN stocks s ON s.id = sh.stock_id
     WHERE sh.portfolio_id IN ($pidList)
       AND sh.quantity > 0 AND sh.current_value > 0
     ORDER BY sh.current_value DESC",
    []
);
foreach ($stkRows as $r) {
    $currVal   = (float)$r['current_value'];
    $investVal = (float)$r['invested_value'];
    $gainAbs   = (float)$r['unrealised_pnl'];
    $gainPct   = $investVal > 0 ? round($gainAbs / $investVal * 100, 2) : 0;
    $dayChgPct = ($r['prev_close'] > 0 && $r['current_price'] > 0)
        ? round(($r['current_price'] - $r['prev_close']) / $r['prev_close'] * 100, 2) : 0;

    $group = match ($groupBy) {
        'sector'     => $r['sector'] ?: 'Other',
        'fund_house' => $r['sector'] ?: 'Other',
        default      => 'Stocks',
    };

    $cells[] = [
        'id'         => 'stk-' . $r['symbol'],
        'name'       => $r['company_name'] ?: $r['symbol'],
        'short'      => $r['symbol'],
        'value'      => round($currVal, 2),
        'invested'   => round($investVal, 2),
        'gain_abs'   => round($gainAbs, 2),
        'gain_pct'   => $gainPct,
        'day_chg'    => $dayChgPct,
        'xirr'       => 0,
        'asset_type' => 'Stock',
        'group'      => $group,
        'color'      => _heatColor($gainPct),
    ];
}

// ── Totals ────────────────────────────────────────────────────────────────
$totalValue   = array_sum(array_column($cells, 'value'));
$totalInvested= array_sum(array_column($cells, 'invested'));

// Add weight_pct to each cell
foreach ($cells as &$c) {
    $c['weight_pct'] = $totalValue > 0 ? round($c['value'] / $totalValue * 100, 2) : 0;
}
unset($c);

// Sort by value desc
usort($cells, fn($a, $b) => $b['value'] <=> $a['value']);

$gainPcts   = array_column($cells, 'gain_pct');
$payload    = [
    'cells'         => $cells,
    'total_value'   => round($totalValue, 2),
    'total_invested'=> round($totalInvested, 2),
    'total_gain'    => round($totalValue - $totalInvested, 2),
    'total_gain_pct'=> $totalInvested > 0 ? round(($totalValue - $totalInvested) / $totalInvested * 100, 2) : 0,
    'max_gain_pct'  => $gainPcts ? max($gainPcts) : 0,
    'min_gain_pct'  => $gainPcts ? min($gainPcts) : 0,
    'count'         => count($cells),
    'group_by'      => $groupBy,
];

WdCache::set($cacheKey, $payload, ttl: 300, tags: ["user:{$userId}"]);
json_response(true, '', $payload);

// ── Helpers ───────────────────────────────────────────────────────────────
function _heatmapShort(string $name): string {
    // "Mirae Asset Large Cap Fund - Direct - Growth" → "Mirae Large Cap"
    $name = preg_replace('/\s*-\s*(Direct|Regular|Growth|IDCW|Dividend|Option|Plan|Fund)\b.*/i', '', $name);
    $parts = explode(' ', trim($name));
    return implode(' ', array_slice($parts, 0, 3));
}

function _heatColor(float $pct): string {
    // Returns a CSS color string: red for losses, green for gains
    if ($pct >= 30)  return '#15803d';  // deep green
    if ($pct >= 15)  return '#16a34a';
    if ($pct >= 7)   return '#22c55e';
    if ($pct >= 3)   return '#4ade80';
    if ($pct >= 0)   return '#86efac';  // light green
    if ($pct >= -3)  return '#fca5a5';  // light red
    if ($pct >= -7)  return '#f87171';
    if ($pct >= -15) return '#ef4444';
    return '#b91c1c';                   // deep red
}
