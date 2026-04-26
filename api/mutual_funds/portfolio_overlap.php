<?php
/**
 * WealthDash — Portfolio Overlap Checker
 * Task t233: Duplicate stocks across mutual funds — fund_holdings table
 * Actions: portfolio_overlap | overlap_detail
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'portfolio_overlap';
$db          = DB::conn();

switch ($action) {

case 'portfolio_overlap':
    $pid = (int)($_POST['portfolio_id'] ?? 0);
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

    // Get user's active holdings
    $holdStmt = $db->prepare("
        SELECT mh.id AS holding_id, f.id AS fund_id, f.fund_name, f.category,
               mh.latest_value, mh.units
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.units > 0.001
        ORDER BY mh.latest_value DESC
    ");
    $holdStmt->execute([$pid]);
    $holdings = $holdStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($holdings) < 2) {
        echo json_encode(['success'=>true,'data'=>[
            'message' => 'Add at least 2 funds to check overlap.',
            'holdings' => $holdings,
            'overlapping_stocks' => [],
            'overlap_pct' => 0,
        ]]);
        break;
    }

    $fundIds = array_column($holdings, 'fund_id');
    $placeholders = implode(',', array_fill(0, count($fundIds), '?'));

    // Get top holdings for each fund from fund_holdings table
    $stockStmt = $db->prepare("
        SELECT
          fh.fund_id,
          fh.stock_name,
          fh.isin,
          fh.sector,
          fh.weight_pct,
          f.fund_name
        FROM fund_holdings fh
        JOIN funds f ON f.id = fh.fund_id
        WHERE fh.fund_id IN ($placeholders)
        ORDER BY fh.fund_id, fh.weight_pct DESC
    ");
    $stockStmt->execute($fundIds);
    $allStocks = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allStocks)) {
        echo json_encode(['success'=>true,'data'=>[
            'message' => 'Fund holdings data not available. Run the holdings fetch cron to populate.',
            'holdings' => array_map(fn($h)=>['fund_name'=>$h['fund_name'],'fund_id'=>$h['fund_id']], $holdings),
            'overlapping_stocks' => [],
        ]]);
        break;
    }

    // Group by stock (ISIN or name)
    $stockMap = []; // stockKey => [fund_id => weight_pct]
    $stockMeta = [];
    foreach ($allStocks as $row) {
        $key = $row['isin'] ?: strtolower(trim($row['stock_name']));
        if (!$key) continue;
        $stockMap[$key][$row['fund_id']] = (float)$row['weight_pct'];
        $stockMeta[$key] = ['name'=>$row['stock_name'],'isin'=>$row['isin'],'sector'=>$row['sector']];
    }

    // Find stocks appearing in 2+ funds
    $overlapping = [];
    foreach ($stockMap as $key => $fundWeights) {
        if (count($fundWeights) >= 2) {
            $fundNames = [];
            $totalWeight = 0;
            foreach ($fundWeights as $fid => $w) {
                $fname = '';
                foreach ($holdings as $h) {
                    if ((int)$h['fund_id'] === (int)$fid) { $fname = $h['fund_name']; break; }
                }
                $fundNames[] = ['fund_id'=>$fid,'fund_name'=>$fname,'weight_pct'=>$w];
                $totalWeight += $w;
            }
            $overlapping[] = [
                'stock_name'   => $stockMeta[$key]['name'],
                'isin'         => $stockMeta[$key]['isin'],
                'sector'       => $stockMeta[$key]['sector'],
                'fund_count'   => count($fundWeights),
                'funds'        => $fundNames,
                'avg_weight'   => round($totalWeight / count($fundWeights), 2),
                'combined_exposure' => round($totalWeight, 2),
            ];
        }
    }

    // Sort by combined exposure
    usort($overlapping, fn($a,$b) => $b['combined_exposure'] <=> $a['combined_exposure']);

    // Overall overlap score
    $totalStocksInFunds = count($stockMap);
    $overlapPct = $totalStocksInFunds > 0 ? round(count($overlapping) / $totalStocksInFunds * 100, 1) : 0;

    // Fund-vs-fund overlap matrix
    $matrix = [];
    foreach ($holdings as $h1) {
        foreach ($holdings as $h2) {
            if ($h1['fund_id'] >= $h2['fund_id']) continue;
            $common = 0; $total1 = 0; $total2 = 0;
            foreach ($stockMap as $key => $fw) {
                if (isset($fw[$h1['fund_id']])) $total1++;
                if (isset($fw[$h2['fund_id']])) $total2++;
                if (isset($fw[$h1['fund_id']]) && isset($fw[$h2['fund_id']])) $common++;
            }
            $pct = $total1 > 0 ? round($common / $total1 * 100, 1) : 0;
            if ($common > 0) {
                $matrix[] = [
                    'fund1_name' => $h1['fund_name'],
                    'fund2_name' => $h2['fund_name'],
                    'common_stocks' => $common,
                    'overlap_pct' => $pct,
                    'severity' => $pct > 60 ? 'high' : ($pct > 30 ? 'medium' : 'low'),
                ];
            }
        }
    }
    usort($matrix, fn($a,$b) => $b['overlap_pct'] <=> $a['overlap_pct']);

    echo json_encode(['success'=>true,'data'=>[
        'fund_count'         => count($holdings),
        'total_stocks'       => $totalStocksInFunds,
        'overlapping_count'  => count($overlapping),
        'overlap_pct'        => $overlapPct,
        'overlapping_stocks' => array_slice($overlapping, 0, 30),
        'fund_matrix'        => $matrix,
        'holdings'           => array_map(fn($h) => [
            'fund_id'      => $h['fund_id'],
            'fund_name'    => $h['fund_name'],
            'category'     => $h['category'],
            'latest_value' => $h['latest_value'],
        ], $holdings),
        'recommendation' => $overlapPct > 50
            ? 'High overlap detected! Consider consolidating funds to reduce redundancy.'
            : ($overlapPct > 25
                ? 'Moderate overlap. Review if all funds are adding diversification.'
                : 'Low overlap. Portfolio is well diversified across funds.'),
    ]]);
    break;

default:
    echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
}
