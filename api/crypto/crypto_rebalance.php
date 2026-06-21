<?php
/**
 * WealthDash — tc004: Portfolio Rebalancing — Crypto
 * Actions: crypto_rebal_targets_load, crypto_rebal_targets_save,
 *          crypto_rebal_calculate, crypto_rebal_history,
 *          crypto_rebal_history_save
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = clean($_GET['action'] ?? $_POST['action'] ?? '');

function cr_portfolio(int $userId): ?int
{
    $pid = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, is_admin())) return $pid;
    return get_user_portfolio_id($userId) ?: null;
}

switch ($action) {

    // ─── LOAD TARGETS ─────────────────────────────────────────────────────────
    case 'crypto_rebal_targets_load':
        $pid = cr_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $targets = DB::fetchAll("
            SELECT crt.*, cm.symbol, cm.name, cm.logo_url, cm.current_price_inr,
                   ch.quantity, ch.avg_buy_price,
                   ROUND(ch.quantity * cm.current_price_inr, 2) AS current_value
            FROM crypto_rebal_targets crt
            JOIN crypto_master cm ON cm.id = crt.coin_id
            LEFT JOIN crypto_holdings ch ON ch.coin_id = crt.coin_id AND ch.portfolio_id = ?
            WHERE crt.portfolio_id = ? AND crt.is_active = 1
            ORDER BY crt.target_pct DESC
        ", [$pid, $pid]);

        json_response(true, '', ['targets' => $targets]);


    // ─── SAVE TARGETS ─────────────────────────────────────────────────────────
    case 'crypto_rebal_targets_save':
        $pid     = cr_portfolio($userId);
        $targets = json_decode($_POST['targets'] ?? '[]', true);
        if (!$pid || empty($targets)) { json_response(false, 'portfolio_id and targets[] required.'); }

        // Validate sum ~= 100
        $sum = array_sum(array_column($targets, 'target_pct'));
        if (abs($sum - 100) > 0.5) {
            json_response(false, "Target percentages must sum to 100 (got {$sum}).");
        }

        // Deactivate old targets
        DB::run("UPDATE crypto_rebal_targets SET is_active=0 WHERE portfolio_id=?", [$pid]);

        foreach ($targets as $t) {
            $coinId    = (int)($t['coin_id']    ?? 0);
            $targetPct = (float)($t['target_pct'] ?? 0);
            if (!$coinId || $targetPct < 0) continue;

            DB::run("
                INSERT INTO crypto_rebal_targets (portfolio_id, coin_id, target_pct, is_active, updated_at)
                VALUES (?,?,?,1,NOW())
                ON DUPLICATE KEY UPDATE target_pct=VALUES(target_pct), is_active=1, updated_at=NOW()
            ", [$pid, $coinId, $targetPct]);
        }

        json_response(true, 'Targets saved.');


    // ─── CALCULATE REBALANCING ────────────────────────────────────────────────
    case 'crypto_rebal_calculate':
        $pid = cr_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        // Get targets
        $targets = DB::fetchAll("
            SELECT crt.coin_id, crt.target_pct,
                   cm.symbol, cm.name, cm.logo_url, cm.current_price_inr,
                   COALESCE(ch.quantity, 0)                                       AS quantity,
                   COALESCE(ch.quantity * cm.current_price_inr, 0)                AS current_value
            FROM crypto_rebal_targets crt
            JOIN crypto_master cm ON cm.id = crt.coin_id
            LEFT JOIN crypto_holdings ch ON ch.coin_id = crt.coin_id AND ch.portfolio_id = ?
            WHERE crt.portfolio_id = ? AND crt.is_active = 1
        ", [$pid, $pid]);

        if (empty($targets)) { json_response(false, 'No rebalancing targets set.'); }

        // Total portfolio value
        $totalValue = DB::fetchOne("
            SELECT COALESCE(SUM(ch.quantity * cm.current_price_inr), 0) AS total
            FROM crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            WHERE ch.portfolio_id=? AND ch.quantity > 0
        ", [$pid])['total'] ?? 0;

        $actions = [];
        $drift   = [];

        foreach ($targets as $t) {
            $targetPct    = (float)$t['target_pct'];
            $currentVal   = (float)$t['current_value'];
            $currentPct   = $totalValue > 0 ? ($currentVal / $totalValue) * 100 : 0;
            $targetVal    = ($targetPct / 100) * $totalValue;
            $driftVal     = $currentVal - $targetVal;
            $driftPct     = $currentPct - $targetPct;
            $price        = (float)$t['current_price_inr'];

            $unitsToTrade = $price > 0 ? abs($driftVal) / $price : 0;
            $tradeType    = $driftVal > 0 ? 'sell' : ($driftVal < 0 ? 'buy' : 'hold');

            $drift[] = [
                'coin_id'       => $t['coin_id'],
                'symbol'        => $t['symbol'],
                'name'          => $t['name'],
                'logo_url'      => $t['logo_url'],
                'current_price' => $price,
                'current_value' => round($currentVal, 2),
                'current_pct'   => round($currentPct, 2),
                'target_pct'    => $targetPct,
                'target_value'  => round($targetVal, 2),
                'drift_value'   => round($driftVal, 2),
                'drift_pct'     => round($driftPct, 2),
                'trade_type'    => $tradeType,
                'units_to_trade'=> round($unitsToTrade, 8),
                'trade_value'   => round(abs($driftVal), 2),
                'is_overweight' => $driftPct > 5,
                'is_underweight'=> $driftPct < -5,
            ];
        }

        // Sort: biggest drift first
        usort($drift, fn($a, $b) => abs($b['drift_pct']) <=> abs($a['drift_pct']));

        $totalBuy  = array_sum(array_column(array_filter($drift, fn($d) => $d['trade_type'] === 'buy'), 'trade_value'));
        $totalSell = array_sum(array_column(array_filter($drift, fn($d) => $d['trade_type'] === 'sell'), 'trade_value'));

        json_response(true, '', [
            'portfolio_value' => round((float)$totalValue, 2),
            'actions'         => $drift,
            'total_to_buy'    => round($totalBuy, 2),
            'total_to_sell'   => round($totalSell, 2),
            'net_trade'       => round($totalBuy - $totalSell, 2),
            'rebalanced_at'   => date('Y-m-d H:i:s'),
        ]);


    // ─── SAVE REBALANCE HISTORY ───────────────────────────────────────────────
    case 'crypto_rebal_history_save':
        $pid     = cr_portfolio($userId);
        $actions = json_decode($_POST['actions'] ?? '[]', true);
        $note    = clean($_POST['note'] ?? '');
        if (!$pid || empty($actions)) { json_response(false, 'Actions required.'); }

        DB::run("
            INSERT INTO crypto_rebal_history (portfolio_id, actions_json, note, rebalanced_at)
            VALUES (?,?,?,NOW())
        ", [$pid, json_encode($actions), $note ?: null]);

        json_response(true, 'Rebalance recorded.', ['id' => (int)$db->lastInsertId()]);


    // ─── REBALANCE HISTORY ────────────────────────────────────────────────────
    case 'crypto_rebal_history':
        $pid = cr_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rows = DB::fetchAll("
            SELECT id, note, rebalanced_at,
                   JSON_LENGTH(actions_json) AS action_count
            FROM crypto_rebal_history
            WHERE portfolio_id=?
            ORDER BY rebalanced_at DESC LIMIT 20
        ", [$pid]);

        json_response(true, '', ['data' => $rows]);


    default:
        json_response(false, "Unknown rebalancing action: {$action}", [], 400);
}
