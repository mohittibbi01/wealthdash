<?php
/**
 * WealthDash — t176: Fund Holdings & Overlap API
 *
 * GET /api/mutual_funds/fund_holdings.php?action=overlap&fund_ids=1,2,3
 *   → Returns pairwise overlap data for user's portfolio funds
 *
 * GET /api/mutual_funds/fund_holdings.php?action=holdings&fund_id=123
 *   → Returns top stock holdings for a single fund
 *
 * GET /api/mutual_funds/fund_holdings.php?action=matrix
 *   → Full overlap matrix for all user-held funds
 *
 * GET /api/mutual_funds/fund_holdings.php?action=status
 *   → Data freshness & coverage stats
 */

declare(strict_types=1);
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$user   = require_auth();
$userId = (int)$user['id'];

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'matrix';

// ── Check if tables exist ─────────────────────────────────────────────────
try {
    DB::conn()->query("SELECT 1 FROM fund_stock_holdings LIMIT 1");
} catch (Exception $e) {
    json_response(true, '', [
        'holdings_available' => false,
        'message'            => 'Holdings data not yet synced. Run migration 028 and cron/fetch_fund_holdings.php first.',
    ]);
    exit;
}

$latestMonth = DB::fetchVal("SELECT MAX(month_year) FROM fund_stock_holdings");

switch ($action) {
    case 'status':            handle_status($userId, $latestMonth); break;
    case 'holdings':          handle_holdings((int)($_GET['fund_id'] ?? 0), $latestMonth); break;
    case 'overlap':           handle_overlap($_GET['fund_ids'] ?? '', $latestMonth); break;
    case 'matrix':            handle_matrix($userId, $latestMonth); break;
    case 'sector_allocation': handle_sector_allocation($userId, $latestMonth); break; // t177
    case 'top_holdings':      handle_top_holdings((int)($_GET['fund_id'] ?? 0), $latestMonth); break; // t178
    default:
        json_response(false, 'Unknown action');
}

// ════════════════════════════════════════════════════════════════════════════

function handle_status(int $userId, ?string $latestMonth): void
{
    $totalFunds    = (int)DB::fetchVal("SELECT COUNT(*) FROM funds WHERE is_active=1");
    $fundsWithData = (int)DB::fetchVal(
        "SELECT COUNT(DISTINCT fund_id) FROM fund_stock_holdings" .
        ($latestMonth ? " WHERE month_year=?" : ""),
        $latestMonth ? [$latestMonth] : []
    );
    $overlapPairs  = (int)DB::fetchVal(
        "SELECT COUNT(*) FROM fund_overlap_cache" .
        ($latestMonth ? " WHERE month_year=?" : ""),
        $latestMonth ? [$latestMonth] : []
    );

    json_response(true, '', [
        'holdings_available' => $fundsWithData > 0,
        'latest_month'       => $latestMonth,
        'total_funds'        => $totalFunds,
        'funds_with_data'    => $fundsWithData,
        'coverage_pct'       => $totalFunds > 0 ? round($fundsWithData / $totalFunds * 100, 1) : 0,
        'overlap_pairs'      => $overlapPairs,
        'next_refresh'       => date('Y-m-10', strtotime('first day of next month')),
    ]);
}

function handle_holdings(int $fundId, ?string $latestMonth): void
{
    if (!$fundId) {
        json_response(false, 'fund_id required');
        return;
    }
    if (!$latestMonth) {
        json_response(true, '', ['holdings' => [], 'month' => null]);
        return;
    }

    $rows = DB::fetchAll(
        "SELECT stock_name, isin, sector, weight_pct, market_cap
         FROM fund_stock_holdings
         WHERE fund_id = ? AND month_year = ?
         ORDER BY weight_pct DESC
         LIMIT 50",
        [$fundId, $latestMonth]
    );

    json_response(true, '', [
        'fund_id'  => $fundId,
        'month'    => $latestMonth,
        'holdings' => $rows,
    ]);
}

function handle_overlap(string $fundIdsStr, ?string $latestMonth): void
{
    $ids = array_filter(array_map('intval', explode(',', $fundIdsStr)));
    if (count($ids) < 2) {
        json_response(false, 'At least 2 fund_ids required');
        return;
    }
    if (!$latestMonth) {
        json_response(true, '', ['pairs' => [], 'month' => null, 'data_available' => false]);
        return;
    }

    $pairs = [];
    $n     = count($ids);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $fA = min($ids[$i], $ids[$j]);
            $fB = max($ids[$i], $ids[$j]);

            $row = DB::fetchOne(
                "SELECT overlap_pct, common_stocks FROM fund_overlap_cache
                 WHERE fund_id_a = ? AND fund_id_b = ? AND month_year = ?",
                [$fA, $fB, $latestMonth]
            );

            if ($row) {
                // Get common stock names for tooltip
                $common = DB::fetchAll(
                    "SELECT a.stock_name, a.isin, a.weight_pct AS weight_a, b.weight_pct AS weight_b
                     FROM fund_stock_holdings a
                     JOIN fund_stock_holdings b
                       ON a.isin = b.isin
                      AND b.fund_id = ?
                      AND b.month_year = ?
                     WHERE a.fund_id = ? AND a.month_year = ?
                     ORDER BY LEAST(a.weight_pct, b.weight_pct) DESC
                     LIMIT 10",
                    [$fB, $latestMonth, $fA, $latestMonth]
                );

                $pairs[] = [
                    'fund_id_a'     => $fA,
                    'fund_id_b'     => $fB,
                    'overlap_pct'   => (float)$row['overlap_pct'],
                    'common_stocks' => (int)$row['common_stocks'],
                    'top_common'    => $common,
                    'risk_level'    => overlap_risk((float)$row['overlap_pct']),
                ];
            } else {
                // No precomputed data — compute on-the-fly (slower)
                $holdingsA = get_holdings_map($fA, $latestMonth);
                $holdingsB = get_holdings_map($fB, $latestMonth);
                if ($holdingsA && $holdingsB) {
                    $ov = compute_overlap_api($holdingsA, $holdingsB);
                    $pairs[] = [
                        'fund_id_a'     => $fA,
                        'fund_id_b'     => $fB,
                        'overlap_pct'   => $ov['pct'],
                        'common_stocks' => $ov['common_count'],
                        'top_common'    => [],
                        'risk_level'    => overlap_risk($ov['pct']),
                    ];
                }
            }
        }
    }

    usort($pairs, fn($a, $b) => $b['overlap_pct'] <=> $a['overlap_pct']);
    json_response(true, '', ['pairs' => $pairs, 'month' => $latestMonth, 'data_available' => true]);
}

function handle_matrix(int $userId, ?string $latestMonth): void
{
    if (!$latestMonth) {
        json_response(true, '', [
            'matrix'         => [],
            'funds'          => [],
            'month'          => null,
            'data_available' => false,
            'message'        => 'No holdings data. Run cron/fetch_fund_holdings.php to sync AMFI data.',
        ]);
        return;
    }

    // Get user's held fund IDs
    $userFunds = DB::fetchAll(
        "SELECT DISTINCT f.id, f.scheme_name, f.category, f.fund_house
         FROM mf_holdings h
         JOIN funds f ON f.id = h.fund_id
         WHERE h.user_id = ? AND h.is_active = 1 AND h.units > 0",
        [$userId]
    );

    if (count($userFunds) < 2) {
        json_response(true, '', [
            'matrix'         => [],
            'funds'          => $userFunds,
            'month'          => $latestMonth,
            'data_available' => false,
            'message'        => 'Need at least 2 active fund holdings.',
        ]);
        return;
    }

    $fundIds  = array_column($userFunds, 'id');
    $fundById = [];
    foreach ($userFunds as $f) {
        $fundById[(int)$f['id']] = $f;
    }

    // Build N×N matrix
    $matrix = [];
    $n      = count($fundIds);
    for ($i = 0; $i < $n; $i++) {
        $fA = (int)$fundIds[$i];
        $matrix[$fA] = [];
        for ($j = 0; $j < $n; $j++) {
            $fB = (int)$fundIds[$j];
            if ($fA === $fB) {
                $matrix[$fA][$fB] = ['overlap_pct' => 100.0, 'common_stocks' => null, 'self' => true];
                continue;
            }
            $kA = min($fA, $fB);
            $kB = max($fA, $fB);
            $row = DB::fetchOne(
                "SELECT overlap_pct, common_stocks FROM fund_overlap_cache
                 WHERE fund_id_a=? AND fund_id_b=? AND month_year=?",
                [$kA, $kB, $latestMonth]
            );
            if ($row) {
                $matrix[$fA][$fB] = [
                    'overlap_pct'   => (float)$row['overlap_pct'],
                    'common_stocks' => (int)$row['common_stocks'],
                    'risk_level'    => overlap_risk((float)$row['overlap_pct']),
                ];
            } else {
                $matrix[$fA][$fB] = ['overlap_pct' => null, 'common_stocks' => null, 'no_data' => true];
            }
        }
    }

    // Coverage check
    $fundsWithHoldings = DB::fetchAll(
        "SELECT DISTINCT fund_id FROM fund_stock_holdings WHERE month_year=? AND fund_id IN (" .
        implode(',', array_fill(0, $n, '?')) . ")",
        array_merge([$latestMonth], $fundIds)
    );
    $coveredIds = array_column($fundsWithHoldings, 'fund_id');
    $coverage   = $n > 0 ? round(count($coveredIds) / $n * 100) : 0;

    json_response(true, '', [
        'matrix'         => $matrix,
        'funds'          => $userFunds,
        'fund_ids'       => $fundIds,
        'month'          => $latestMonth,
        'data_available' => true,
        'coverage_pct'   => $coverage,
    ]);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function get_holdings_map(int $fundId, string $month): array
{
    $rows = DB::fetchAll(
        "SELECT isin, weight_pct FROM fund_stock_holdings
         WHERE fund_id=? AND month_year=? AND isin IS NOT NULL",
        [$fundId, $month]
    );
    $map = [];
    foreach ($rows as $r) {
        $map[$r['isin']] = (float)$r['weight_pct'];
    }
    return $map;
}

function compute_overlap_api(array $mapA, array $mapB): array
{
    $commonIsins = array_intersect_key($mapA, $mapB);
    $weight      = 0.0;
    foreach ($commonIsins as $isin => $wA) {
        $weight += min($wA, $mapB[$isin]);
    }
    return ['pct' => round($weight, 2), 'common_count' => count($commonIsins)];
}

function overlap_risk(float $pct): string
{
    if ($pct >= 50) return 'high';
    if ($pct >= 25) return 'medium';
    return 'low';
}

// ════════════════════════════════════════════════════════════════════════════
// t177: Sector Allocation v2 — Real AMFI holdings data
// ════════════════════════════════════════════════════════════════════════════

function handle_sector_allocation(int $userId, ?string $latestMonth): void
{
    if (!$latestMonth) {
        json_response(true, '', ['sectors' => [], 'as_of' => null, 'funds_covered' => 0]);
        return;
    }

    $userFunds = DB::fetchAll(
        "SELECT h.fund_id, h.value_now, f.fund_name
         FROM mf_holdings h
         JOIN funds f ON f.id = h.fund_id
         WHERE h.user_id = ? AND h.value_now > 0",
        [$userId]
    );

    if (empty($userFunds)) {
        json_response(true, '', ['sectors' => [], 'as_of' => $latestMonth, 'funds_covered' => 0]);
        return;
    }

    $totalPortfolioValue = array_sum(array_column($userFunds, 'value_now'));
    $fundIds    = array_column($userFunds, 'fund_id');
    $fundValMap = array_column($userFunds, 'value_now', 'fund_id');

    $ph = implode(',', array_fill(0, count($fundIds), '?'));
    $hasData = DB::fetchAll(
        "SELECT DISTINCT fund_id FROM fund_stock_holdings
         WHERE fund_id IN ({$ph}) AND disclosure_month = ?",
        [...$fundIds, $latestMonth]
    );
    $covered = array_column($hasData, 'fund_id');

    if (empty($covered)) {
        json_response(true, '', [
            'sectors' => [], 'as_of' => $latestMonth, 'funds_covered' => 0,
            'fallback' => true,
            'message'  => 'AMFI holdings not yet fetched. Run: php cron/fetch_fund_holdings.php'
        ]);
        return;
    }

    $ph2    = implode(',', array_fill(0, count($covered), '?'));
    $stocks = DB::fetchAll(
        "SELECT fund_id, sector, SUM(weight_pct) AS fund_sector_weight
         FROM fund_stock_holdings
         WHERE fund_id IN ({$ph2}) AND disclosure_month = ?
         GROUP BY fund_id, sector",
        [...$covered, $latestMonth]
    );

    $coveredValue  = (float)array_sum(array_map(fn($fid) => $fundValMap[$fid] ?? 0, $covered));
    $sectorWeights = [];
    foreach ($stocks as $row) {
        $fid  = $row['fund_id'];
        $fw   = $coveredValue > 0 ? (float)($fundValMap[$fid] ?? 0) / $coveredValue : 0;
        $sec  = $row['sector'] ?: 'Others';
        $sectorWeights[$sec] = ($sectorWeights[$sec] ?? 0) + (float)$row['fund_sector_weight'] * $fw;
    }
    arsort($sectorWeights);

    $sectors = array_map(
        fn($name, $w) => ['sector' => $name, 'weight' => round($w, 2)],
        array_keys($sectorWeights), array_values($sectorWeights)
    );

    json_response(true, '', [
        'sectors'         => array_values($sectors),
        'as_of'           => $latestMonth,
        'funds_covered'   => count($covered),
        'funds_total'     => count($fundIds),
        'covered_value'   => round($coveredValue, 2),
        'portfolio_value' => round($totalPortfolioValue, 2),
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// t178: Top Holdings Disclosure — Fund ke top 10 stocks
// ════════════════════════════════════════════════════════════════════════════

function handle_top_holdings(int $fundId, ?string $latestMonth): void
{
    if (!$fundId || !$latestMonth) {
        json_response(false, 'fund_id and disclosure month required');
        return;
    }

    $rows = DB::fetchAll(
        "SELECT stock_name, isin, sector, weight_pct,
                disclosure_month AS as_of
         FROM fund_stock_holdings
         WHERE fund_id = ? AND disclosure_month = ?
         ORDER BY weight_pct DESC
         LIMIT 10",
        [$fundId, $latestMonth]
    );

    // Also: which other user-held funds share these stocks (overlap insight)
    $fundIds_sharing = [];
    if (!empty($rows)) {
        $isins = array_filter(array_column($rows, 'isin'));
        if ($isins) {
            $isinPh = implode(',', array_fill(0, count($isins), '?'));
            $sharing = DB::fetchAll(
                "SELECT DISTINCT fsh.fund_id, f.fund_name, fsh.stock_name, fsh.weight_pct
                 FROM fund_stock_holdings fsh
                 JOIN funds f ON f.id = fsh.fund_id
                 WHERE fsh.isin IN ({$isinPh})
                   AND fsh.disclosure_month = ?
                   AND fsh.fund_id != ?
                 ORDER BY fsh.fund_id, fsh.weight_pct DESC",
                [...$isins, $latestMonth, $fundId]
            );
            foreach ($sharing as $s) {
                $fundIds_sharing[$s['fund_id']]['fund_name'] = $s['fund_name'];
                $fundIds_sharing[$s['fund_id']]['shared_stocks'][] = [
                    'stock' => $s['stock_name'], 'weight' => $s['weight_pct']
                ];
            }
        }
    }

    json_response(true, '', [
        'holdings'       => $rows,
        'as_of'          => $latestMonth,
        'other_funds'    => array_values($fundIds_sharing),
    ]);
}
