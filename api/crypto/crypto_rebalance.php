<?php
/**
 * WealthDash — tc004: Portfolio Rebalancing — Crypto
 *
 * Set target allocation percentages per coin and get intelligent
 * buy/sell suggestions to rebalance to target.
 *
 * Actions handled (routed from router.php):
 *   GET  crypto_rebalance_targets — get saved targets + current actual allocation
 *   POST crypto_rebalance_set     — set/update target % for a coin
 *   POST crypto_rebalance_delete  — remove target for a coin
 *   GET  crypto_rebalance_suggest — calculate exact trades needed to rebalance
 *
 * Router note (add to router.php after crypto block ~line 832):
 *   case 'crypto_rebalance_targets':
 *   case 'crypto_rebalance_set':
 *   case 'crypto_rebalance_delete':
 *   case 'crypto_rebalance_suggest':
 *       require APP_ROOT . '/api/crypto/crypto_rebalance.php'; exit;
 *
 * DB deps: crypto_rebalance_targets (tc004_migration.sql)
 *          crypto_holdings, crypto_price_cache (existing)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$isAdmin     = (bool)($currentUser['is_admin'] ?? false);
$db          = DB::pdo();
$action      = $_GET['action'] ?? $_POST['action'] ?? '';
$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);

$pWhere = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

$pWhereH = $portfolioId
    ? "AND ch.portfolio_id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

// ── Get live holdings with prices ─────────────────────────────────────────────
function tc004_get_holdings(PDO $db, int $userId, int $portfolioId): array
{
    $pW = $portfolioId
        ? "AND ch.portfolio_id = {$portfolioId} AND p.user_id = {$userId}"
        : "AND p.user_id = {$userId}";

    $rows = DB::fetchAll(
        "SELECT ch.id, ch.coin_id, ch.coin_symbol, ch.coin_name,
                ch.quantity, ch.avg_buy_price, ch.total_invested,
                ch.exchange, ch.portfolio_id,
                p.name AS portfolio_name,
                pc.price_inr, pc.change_24h
         FROM crypto_holdings ch
         JOIN portfolios p ON p.id = ch.portfolio_id
         LEFT JOIN crypto_price_cache pc ON pc.coin_id = ch.coin_id
         WHERE ch.quantity > 0 {$pW}
         ORDER BY ch.total_invested DESC"
    );

    // Refresh stale prices via CoinGecko
    $stale = [];
    foreach ($rows as $r) {
        if (!$r['price_inr'] || !$r['change_24h']) {
            $stale[] = $r['coin_id'];
        }
    }

    if (!empty($stale)) {
        $idsParam = implode(',', array_map('rawurlencode', $stale));
        $url = "https://api.coingecko.com/api/v3/simple/price"
             . "?ids={$idsParam}&vs_currencies=inr,usd&include_24hr_change=true";
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: WealthDash/1.0\r\n"]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $data = $raw ? json_decode($raw, true) : [];

        if (is_array($data)) {
            $ups = $db->prepare(
                "INSERT INTO crypto_price_cache (coin_id, price_inr, price_usd, change_24h)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE price_inr=VALUES(price_inr), price_usd=VALUES(price_usd),
                     change_24h=VALUES(change_24h), fetched_at=NOW()"
            );
            foreach ($stale as $cid) {
                $r = $data[$cid] ?? null;
                if (!$r) continue;
                $ups->execute([$cid, (float)($r['inr'] ?? 0), (float)($r['usd'] ?? 0),
                               $r['inr_24h_change'] ?? $r['usd_24h_change'] ?? null]);
            }
            // Re-attach fresh prices to rows
            foreach ($rows as &$row) {
                if (in_array($row['coin_id'], $stale) && isset($data[$row['coin_id']])) {
                    $row['price_inr']  = (float)($data[$row['coin_id']]['inr'] ?? 0);
                    $row['change_24h'] = $data[$row['coin_id']]['inr_24h_change'] ?? null;
                }
            }
            unset($row);
        }
    }

    // Compute current value for each holding
    $totalCurrent = 0.0;
    foreach ($rows as &$r) {
        $price    = (float)($r['price_inr'] ?? 0);
        $curVal   = $price * (float)$r['quantity'];
        $r['price_inr']     = $price;
        $r['current_value'] = round($curVal, 2);
        $totalCurrent      += $curVal;
    }
    unset($r);

    foreach ($rows as &$r) {
        $r['actual_pct'] = $totalCurrent > 0
            ? round($r['current_value'] / $totalCurrent * 100, 2)
            : 0;
    }
    unset($r);

    return ['holdings' => $rows, 'total_current' => round($totalCurrent, 2)];
}

// ════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── GET TARGETS + ACTUAL ALLOCATION ──────────────────────────────────
    case 'crypto_rebalance_targets': {
        // Saved targets
        $targets = DB::fetchAll(
            "SELECT rt.*
             FROM crypto_rebalance_targets rt
             WHERE rt.user_id = ?
             ORDER BY rt.target_pct DESC",
            [$userId]
        );

        $targetMap = array_column($targets, null, 'coin_id');

        // Current holdings with live prices
        $holdData    = tc004_get_holdings($db, $userId, $portfolioId);
        $holdings    = $holdData['holdings'];
        $totalCurrent= $holdData['total_current'];

        $totalTargetPct = round(array_sum(array_column($targets, 'target_pct')), 2);

        // Merge targets with actual allocation
        // Coins in holdings but not in targets get 0% target (= sell everything)
        $allCoinIds = array_unique(array_merge(
            array_column($targets,  'coin_id'),
            array_column($holdings, 'coin_id')
        ));

        $merged = [];
        foreach ($allCoinIds as $cid) {
            $h = null;
            foreach ($holdings as $hold) {
                if ($hold['coin_id'] === $cid) { $h = $hold; break; }
            }
            $t = $targetMap[$cid] ?? null;

            $actualPct  = $h ? (float)$h['actual_pct'] : 0;
            $targetPct  = $t ? (float)$t['target_pct'] : 0;
            $drift      = round($actualPct - $targetPct, 2);
            $curValue   = $h ? (float)$h['current_value'] : 0;

            $merged[] = [
                'coin_id'        => $cid,
                'coin_symbol'    => $h ? $h['coin_symbol'] : ($t['coin_symbol'] ?? ''),
                'coin_name'      => $h ? $h['coin_name']   : ($t['coin_name']   ?? ''),
                'target_pct'     => $targetPct,
                'actual_pct'     => $actualPct,
                'drift_pct'      => $drift,
                'drift_abs'      => abs($drift),
                'current_value'  => $curValue,
                'in_holdings'    => (bool)$h,
                'has_target'     => (bool)$t,
                'target_id'      => $t['id'] ?? null,
            ];
        }

        // Sort by drift abs descending (most out-of-balance first)
        usort($merged, fn($a, $b) => $b['drift_abs'] <=> $a['drift_abs']);

        json_response(true, '', [
            'targets'         => $merged,
            'total_target_pct'=> $totalTargetPct,
            'is_valid'        => abs($totalTargetPct - 100) < 0.01 || $totalTargetPct === 0.0,
            'total_current'   => $totalCurrent,
        ]);
        break;
    }

    // ── SET TARGET ─────────────────────────────────────────────────────────
    case 'crypto_rebalance_set': {
        $coinId    = clean($_POST['coin_id']     ?? '');
        $coinSym   = strtoupper(clean($_POST['coin_symbol'] ?? ''));
        $coinName  = clean($_POST['coin_name']   ?? $coinSym);
        $targetPct = round((float)($_POST['target_pct'] ?? 0), 2);

        if (!$coinId)           json_response(false, 'coin_id required.');
        if (!$coinSym)          json_response(false, 'coin_symbol required.');
        if ($targetPct < 0 || $targetPct > 100)
            json_response(false, 'target_pct must be 0–100.');

        // Check total won't exceed 100%
        $existingTotal = (float)(DB::fetchOne(
            "SELECT COALESCE(SUM(target_pct),0) AS t
             FROM crypto_rebalance_targets
             WHERE user_id = ? AND coin_id != ?",
            [$userId, $coinId]
        )['t'] ?? 0);

        if (round($existingTotal + $targetPct, 2) > 100.01) {
            json_response(false, sprintf(
                'Total target would be %.2f%% (>100%%). Current others total: %.2f%%.',
                $existingTotal + $targetPct, $existingTotal
            ));
        }

        DB::execute(
            "INSERT INTO crypto_rebalance_targets
             (user_id, coin_id, coin_symbol, coin_name, target_pct)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               coin_symbol=VALUES(coin_symbol), coin_name=VALUES(coin_name),
               target_pct=VALUES(target_pct), updated_at=NOW()",
            [$userId, $coinId, $coinSym, $coinName, $targetPct]
        );

        $newTotal = round($existingTotal + $targetPct, 2);
        json_response(true, "Target set: {$coinSym} → {$targetPct}%", [
            'coin_id'       => $coinId,
            'coin_symbol'   => $coinSym,
            'target_pct'    => $targetPct,
            'total_allocated'=> $newTotal,
            'remaining_pct' => max(0, round(100 - $newTotal, 2)),
        ]);
        break;
    }

    // ── DELETE TARGET ───────────────────────────────────────────────────────
    case 'crypto_rebalance_delete': {
        $coinId = clean($_POST['coin_id'] ?? '');
        $id     = (int)($_POST['id'] ?? 0);

        if (!$coinId && !$id) json_response(false, 'coin_id or id required.');

        $where  = $id ? "id = ? AND user_id = ?" : "coin_id = ? AND user_id = ?";
        $param1 = $id ?: $coinId;
        DB::execute("DELETE FROM crypto_rebalance_targets WHERE {$where}", [$param1, $userId]);

        json_response(true, 'Target removed.');
        break;
    }

    // ── SUGGEST REBALANCING TRADES ─────────────────────────────────────────
    case 'crypto_rebalance_suggest': {
        $budget = (float)($_GET['budget_inr'] ?? 0); // Optional: fresh capital to deploy

        // Load targets
        $targets = DB::fetchAll(
            "SELECT coin_id, coin_symbol, coin_name, target_pct
             FROM crypto_rebalance_targets WHERE user_id = ?
             ORDER BY target_pct DESC",
            [$userId]
        );

        if (empty($targets)) {
            json_response(false, 'No targets set. Please set target allocations first.');
        }

        $totalTargetPct = round(array_sum(array_column($targets, 'target_pct')), 2);

        // Get current holdings
        $holdData     = tc004_get_holdings($db, $userId, $portfolioId);
        $holdings     = $holdData['holdings'];
        $totalCurrent = $holdData['total_current'];

        // Add fresh capital if provided
        $rebalancePool = $totalCurrent + $budget;

        // Build target values in INR
        $targetMap = array_column($targets, null, 'coin_id');
        $holdMap   = array_column($holdings, null, 'coin_id');

        $suggestions = [];
        $totalBuy    = 0.0;
        $totalSell   = 0.0;

        foreach ($targets as $t) {
            $cid        = $t['coin_id'];
            $targetPct  = (float)$t['target_pct'];
            $targetVal  = $rebalancePool * ($targetPct / 100);

            $hold       = $holdMap[$cid] ?? null;
            $curVal     = $hold ? (float)$hold['current_value'] : 0;
            $priceInr   = $hold ? (float)$hold['price_inr'] : 0;
            $qty        = $hold ? (float)$hold['quantity'] : 0;
            $actualPct  = $rebalancePool > 0 ? round($curVal / $rebalancePool * 100, 2) : 0;

            $diff       = $targetVal - $curVal;  // positive = buy, negative = sell
            $diffPct    = $rebalancePool > 0 ? round($diff / $rebalancePool * 100, 2) : 0;

            $action_type = 'HOLD';
            $qtyToTrade  = 0;

            if (abs($diff) > 100) { // Only suggest if difference > ₹100
                if ($diff > 0) {
                    $action_type = 'BUY';
                    $qtyToTrade  = $priceInr > 0 ? round($diff / $priceInr, 8) : 0;
                    $totalBuy   += $diff;
                } else {
                    $action_type = 'SELL';
                    $qtyToTrade  = $priceInr > 0 ? round(abs($diff) / $priceInr, 8) : 0;
                    $totalSell  += abs($diff);
                }
            }

            $suggestions[] = [
                'coin_id'         => $cid,
                'coin_symbol'     => $t['coin_symbol'],
                'coin_name'       => $t['coin_name'],
                'target_pct'      => $targetPct,
                'actual_pct'      => $actualPct,
                'drift_pct'       => round($actualPct - $targetPct, 2),
                'current_value'   => round($curVal, 2),
                'target_value'    => round($targetVal, 2),
                'diff_inr'        => round($diff, 2),
                'diff_pct'        => $diffPct,
                'action'          => $action_type,
                'qty_to_trade'    => $qtyToTrade,
                'price_inr'       => $priceInr,
                'current_qty'     => $qty,
            ];
        }

        // Coins in holdings NOT in targets (consider selling)
        foreach ($holdings as $h) {
            if (!isset($targetMap[$h['coin_id']]) && (float)$h['current_value'] > 100) {
                $suggestions[] = [
                    'coin_id'         => $h['coin_id'],
                    'coin_symbol'     => $h['coin_symbol'],
                    'coin_name'       => $h['coin_name'],
                    'target_pct'      => 0,
                    'actual_pct'      => (float)$h['actual_pct'],
                    'drift_pct'       => (float)$h['actual_pct'],
                    'current_value'   => (float)$h['current_value'],
                    'target_value'    => 0.0,
                    'diff_inr'        => -(float)$h['current_value'],
                    'diff_pct'        => -(float)$h['actual_pct'],
                    'action'          => 'SELL',
                    'qty_to_trade'    => (float)$h['quantity'],
                    'price_inr'       => (float)$h['price_inr'],
                    'current_qty'     => (float)$h['quantity'],
                    '_note'           => 'Not in rebalance targets — consider selling',
                ];
                $totalSell += (float)$h['current_value'];
            }
        }

        // Sort: SELL first (generate cash), then BUY, then HOLD
        usort($suggestions, function ($a, $b) {
            $order = ['SELL' => 0, 'BUY' => 1, 'HOLD' => 2];
            return ($order[$a['action']] ?? 2) <=> ($order[$b['action']] ?? 2);
        });

        // Drift threshold warning
        $maxDrift = count($suggestions) > 0
            ? max(array_map(fn($s) => abs($s['drift_pct']), $suggestions))
            : 0;

        json_response(true, '', [
            'suggestions'      => $suggestions,
            'summary' => [
                'total_portfolio'    => round($rebalancePool, 2),
                'current_portfolio'  => round($totalCurrent,  2),
                'fresh_capital'      => round($budget, 2),
                'total_target_pct'   => $totalTargetPct,
                'total_buy_value'    => round($totalBuy,  2),
                'total_sell_value'   => round($totalSell, 2),
                'net_cash_needed'    => round($totalBuy - $totalSell, 2), // >0 need cash, <0 have surplus
                'max_drift_pct'      => round($maxDrift, 2),
                'is_balanced'        => $maxDrift < 2.0,
            ],
            'notes' => [
                'tax_warning'    => 'SELL transactions par 30% VDA tax + 1% TDS lagega (Section 115BBH + 194S).',
                'recommendation' => $totalBuy > 0 && $totalSell < 10
                    ? 'Fresh capital deploy karke rebalance karo — tax avoid hoga.'
                    : 'Sell transactions consider karte waqt VDA tax impact zaroor calculate karo.',
                'drift_threshold'=> '2% se kum drift ko ignore karna better hai (over-trading se bachne ke liye).',
            ],
        ]);
        break;
    }

    default:
        json_response(false, "Unknown rebalance action: {$action}");
}
