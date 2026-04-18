<?php
/**
 * WealthDash — Crypto List (t24)
 * GET  /api/?action=crypto_list         — all holdings with live P&L
 * GET  /api/?action=crypto_prices       — refresh live prices from CoinGecko
 * GET  /api/?action=crypto_summary      — dashboard stat cards
 * GET  /api/?action=crypto_txns         — transaction history
 * POST /api/?action=crypto_add          — add holding
 * POST /api/?action=crypto_delete       — delete holding
 * POST /api/?action=crypto_txn_add      — add transaction (recalculates avg price)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$pWhere = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

// ── LIVE PRICES (CoinGecko free API) ────────────────────────────────────────
function crypto_fetch_prices(PDO $db, array $coinIds): array
{
    if (empty($coinIds)) return [];

    // Check cache (max 5 minutes old)
    $placeholders = implode(',', array_fill(0, count($coinIds), '?'));
    $cached = $db->prepare(
        "SELECT coin_id, price_inr, price_usd, change_24h
         FROM crypto_price_cache
         WHERE coin_id IN ($placeholders) AND fetched_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $cached->execute($coinIds);
    $prices = [];
    foreach ($cached->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $prices[$row['coin_id']] = $row;
    }

    // Fetch missing from CoinGecko
    $missing = array_diff($coinIds, array_keys($prices));
    if (!empty($missing)) {
        $ids_param = implode(',', array_map('rawurlencode', $missing));
        $url = "https://api.coingecko.com/api/v3/simple/price"
             . "?ids={$ids_param}&vs_currencies=inr,usd&include_24hr_change=true&include_market_cap=true";

        $ctx  = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: WealthDash/1.0\r\n"]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $data = $raw ? json_decode($raw, true) : [];

        if (is_array($data)) {
            $upsert = $db->prepare(
                "INSERT INTO crypto_price_cache (coin_id, price_inr, price_usd, change_24h, market_cap)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE price_inr=VALUES(price_inr), price_usd=VALUES(price_usd),
                                         change_24h=VALUES(change_24h), market_cap=VALUES(market_cap),
                                         fetched_at=NOW()"
            );
            foreach ($missing as $cid) {
                $row = $data[$cid] ?? null;
                if (!$row) continue;
                $priceInr = (float)($row['inr'] ?? 0);
                $priceUsd = (float)($row['usd'] ?? 0);
                $chg      = $row['inr_24h_change'] ?? $row['usd_24h_change'] ?? null;
                $mcap     = isset($row['inr_market_cap']) ? (int)$row['inr_market_cap'] : null;
                $upsert->execute([$cid, $priceInr, $priceUsd, $chg, $mcap]);
                $prices[$cid] = ['coin_id' => $cid, 'price_inr' => $priceInr,
                                 'price_usd' => $priceUsd, 'change_24h' => $chg];
            }
        }
    }
    return $prices;
}

// ── RECALCULATE HOLDING AVG FROM TRANSACTIONS ────────────────────────────────
function crypto_recalc_holding(PDO $db, int $portfolioId, string $coinId): void
{
    // Weighted avg cost (FIFO-like: only BUY txns for avg, net qty includes sells)
    $stmt = $db->prepare(
        "SELECT txn_type, quantity, price_inr, amount_inr FROM crypto_transactions
         WHERE portfolio_id = ? AND coin_id = ? ORDER BY txn_date ASC, id ASC"
    );
    $stmt->execute([$portfolioId, $coinId]);
    $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $qty = 0.0; $totalCost = 0.0;
    foreach ($txns as $t) {
        $q = (float)$t['quantity'];
        $amt = (float)$t['amount_inr'];
        if (in_array($t['txn_type'], ['BUY', 'TRANSFER_IN'])) {
            $qty       += $q;
            $totalCost += $amt;
        } else {
            // SELL / TRANSFER_OUT — reduce proportionally
            if ($qty > 0) {
                $ratio     = min($q / $qty, 1.0);
                $totalCost -= $totalCost * $ratio;
                $qty       -= $q;
            }
        }
    }
    $qty       = max(0, $qty);
    $totalCost = max(0, $totalCost);
    $avgPrice  = $qty > 0 ? $totalCost / $qty : 0;

    // Update or delete holding record
    if ($qty > 0) {
        $exists = $db->prepare(
            "SELECT id FROM crypto_holdings WHERE portfolio_id=? AND coin_id=? LIMIT 1"
        );
        $exists->execute([$portfolioId, $coinId]);
        $row = $exists->fetch();

        if ($row) {
            $db->prepare(
                "UPDATE crypto_holdings SET quantity=?, avg_buy_price=?, total_invested=?, updated_at=NOW()
                 WHERE portfolio_id=? AND coin_id=?"
            )->execute([$qty, $avgPrice, $totalCost, $portfolioId, $coinId]);
        }
        // (if doesn't exist, caller should have inserted it already)
    } else {
        // All sold — remove holding
        $db->prepare(
            "DELETE FROM crypto_holdings WHERE portfolio_id=? AND coin_id=?"
        )->execute([$portfolioId, $coinId]);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── LIST WITH LIVE P&L ───────────────────────────────────────────────
    case 'crypto_list': {
        $rows = DB::fetchAll(
            "SELECT ch.*, p.name AS portfolio_name, p.color AS portfolio_color
             FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id
             WHERE ch.quantity > 0 {$pWhere}
             ORDER BY ch.total_invested DESC"
        );

        $coinIds = array_unique(array_column($rows, 'coin_id'));
        $prices  = crypto_fetch_prices(DB::pdo(), $coinIds);

        $totalInvested = 0; $totalCurrent = 0;
        foreach ($rows as &$r) {
            $p = $prices[$r['coin_id']] ?? null;
            $priceInr       = (float)($p['price_inr'] ?? 0);
            $currentValue   = $priceInr * (float)$r['quantity'];
            $gain           = $currentValue - (float)$r['total_invested'];
            $gainPct        = $r['total_invested'] > 0 ? ($gain / $r['total_invested'] * 100) : 0;

            $r['price_inr']     = $priceInr;
            $r['price_usd']     = (float)($p['price_usd'] ?? 0);
            $r['change_24h']    = $p['change_24h'] ?? null;
            $r['current_value'] = round($currentValue, 2);
            $r['gain_inr']      = round($gain, 2);
            $r['gain_pct']      = round($gainPct, 2);
            $r['has_price']     = $priceInr > 0;

            $totalInvested += (float)$r['total_invested'];
            $totalCurrent  += $currentValue;
        }
        unset($r);

        json_response(true, '', [
            'holdings'        => $rows,
            'total_invested'  => round($totalInvested, 2),
            'total_current'   => round($totalCurrent, 2),
            'total_gain'      => round($totalCurrent - $totalInvested, 2),
            'total_gain_pct'  => $totalInvested > 0
                                   ? round(($totalCurrent - $totalInvested) / $totalInvested * 100, 2)
                                   : 0,
            'prices_updated'  => date('H:i:s'),
        ]);
        break;
    }

    // ── REFRESH LIVE PRICES ──────────────────────────────────────────────
    case 'crypto_prices': {
        $rows = DB::fetchAll(
            "SELECT DISTINCT ch.coin_id FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id
             WHERE ch.quantity > 0 {$pWhere}"
        );
        $coinIds = array_column($rows, 'coin_id');
        // Force-clear cache so we always get fresh
        if (!empty($coinIds)) {
            $ph = implode(',', array_fill(0, count($coinIds), '?'));
            DB::pdo()->prepare("DELETE FROM crypto_price_cache WHERE coin_id IN ($ph)")
                ->execute($coinIds);
        }
        $prices = crypto_fetch_prices(DB::pdo(), $coinIds);
        json_response(true, 'Prices refreshed.', ['prices' => $prices, 'count' => count($prices)]);
        break;
    }

    // ── SUMMARY (dashboard stat cards) ──────────────────────────────────
    case 'crypto_summary': {
        $rows = DB::fetchAll(
            "SELECT ch.coin_id, ch.quantity, ch.total_invested
             FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id
             WHERE ch.quantity > 0 {$pWhere}"
        );
        $coinIds = array_column($rows, 'coin_id');
        $prices  = crypto_fetch_prices(DB::pdo(), $coinIds);
        $totalInv = 0; $totalCur = 0;
        foreach ($rows as $r) {
            $totalInv += (float)$r['total_invested'];
            $totalCur += ((float)($prices[$r['coin_id']]['price_inr'] ?? 0)) * (float)$r['quantity'];
        }
        json_response(true, '', [
            'coin_count'     => count($rows),
            'total_invested' => round($totalInv, 2),
            'total_current'  => round($totalCur, 2),
            'total_gain'     => round($totalCur - $totalInv, 2),
            'gain_pct'       => $totalInv > 0 ? round(($totalCur - $totalInv) / $totalInv * 100, 2) : 0,
        ]);
        break;
    }

    // ── TRANSACTION HISTORY ──────────────────────────────────────────────
    case 'crypto_txns': {
        $coinFilter = clean($_GET['coin_id'] ?? '');
        $coinCond   = $coinFilter ? "AND ct.coin_id = " . DB::pdo()->quote($coinFilter) : '';
        $rows = DB::fetchAll(
            "SELECT ct.*, p.name AS portfolio_name
             FROM crypto_transactions ct
             JOIN portfolios p ON p.id = ct.portfolio_id
             WHERE 1=1 {$pWhere} {$coinCond}
             ORDER BY ct.txn_date DESC, ct.id DESC
             LIMIT 500"
        );
        json_response(true, '', $rows);
        break;
    }

    // ── ADD HOLDING (manual entry) ───────────────────────────────────────
    case 'crypto_add': {
        $pid      = (int)($_POST['portfolio_id'] ?? 0) ?: get_user_portfolio_id($userId);
        $coinId   = clean($_POST['coin_id']   ?? '');
        $symbol   = strtoupper(clean($_POST['coin_symbol'] ?? ''));
        $name     = clean($_POST['coin_name']  ?? $symbol);
        $qty      = (float)($_POST['quantity']   ?? 0);
        $price    = (float)($_POST['price_inr']  ?? 0);  // avg buy price per coin
        $exchange = clean($_POST['exchange']     ?? '');
        $txnDate  = clean($_POST['txn_date']     ?? date('Y-m-d'));
        $notes    = clean($_POST['notes']        ?? '');

        if (!$pid)    json_response(false, 'Portfolio required.');
        if (!$coinId) json_response(false, 'Coin ID required (e.g. bitcoin).');
        if (!$symbol) json_response(false, 'Coin symbol required (e.g. BTC).');
        if ($qty <= 0) json_response(false, 'Quantity must be positive.');
        if ($price <= 0) json_response(false, 'Buy price (INR) must be positive.');
        if (!can_access_portfolio($pid, $userId, $isAdmin)) json_response(false, 'Access denied.');

        $amountInr = $qty * $price;

        // Upsert holding
        $exists = DB::fetchOne(
            "SELECT id, quantity, avg_buy_price, total_invested FROM crypto_holdings
             WHERE portfolio_id=? AND coin_id=? LIMIT 1",
            [$pid, $coinId]
        );

        if ($exists) {
            // Weighted avg merge
            $newQty      = (float)$exists['quantity'] + $qty;
            $newInvested = (float)$exists['total_invested'] + $amountInr;
            $newAvg      = $newInvested / $newQty;
            DB::execute(
                "UPDATE crypto_holdings SET quantity=?, avg_buy_price=?, total_invested=?, updated_at=NOW()
                 WHERE id=?",
                [$newQty, $newAvg, $newInvested, $exists['id']]
            );
            $holdingId = $exists['id'];
        } else {
            $holdingId = DB::insert(
                "INSERT INTO crypto_holdings (portfolio_id, coin_id, coin_symbol, coin_name, quantity, avg_buy_price, total_invested, exchange, notes)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                [$pid, $coinId, $symbol, $name, $qty, $price, $amountInr, $exchange ?: null, $notes ?: null]
            );
        }

        // Record transaction
        DB::execute(
            "INSERT INTO crypto_transactions (portfolio_id, coin_id, coin_symbol, txn_type, quantity, price_inr, amount_inr, txn_date, exchange, notes)
             VALUES (?,?,?,'BUY',?,?,?,?,?,?)",
            [$pid, $coinId, $symbol, $qty, $price, $amountInr, $txnDate, $exchange ?: null, $notes ?: null]
        );

        audit_log('crypto_add', 'crypto_holdings', (int)$holdingId);
        json_response(true, "{$name} added successfully.", ['id' => $holdingId]);
        break;
    }

    // ── DELETE HOLDING ───────────────────────────────────────────────────
    case 'crypto_delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'ID required.');

        $row = DB::fetchOne(
            "SELECT ch.id, ch.coin_name, p.user_id FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id WHERE ch.id=?",
            [$id]
        );
        if (!$row || ((int)$row['user_id'] !== $userId && !$isAdmin))
            json_response(false, 'Not found or access denied.');

        DB::execute("DELETE FROM crypto_holdings WHERE id=?", [$id]);
        audit_log('crypto_delete', 'crypto_holdings', $id);
        json_response(true, $row['coin_name'] . ' holding deleted.');
        break;
    }

    // ── ADD TRANSACTION ──────────────────────────────────────────────────
    case 'crypto_txn_add': {
        $pid      = (int)($_POST['portfolio_id'] ?? 0) ?: get_user_portfolio_id($userId);
        $coinId   = clean($_POST['coin_id']   ?? '');
        $symbol   = strtoupper(clean($_POST['coin_symbol'] ?? ''));
        $name     = clean($_POST['coin_name']  ?? $symbol);
        $txnType  = clean($_POST['txn_type']   ?? 'BUY');
        $qty      = (float)($_POST['quantity']  ?? 0);
        $price    = (float)($_POST['price_inr'] ?? 0);
        $txnDate  = clean($_POST['txn_date']    ?? date('Y-m-d'));
        $exchange = clean($_POST['exchange']    ?? '');
        $notes    = clean($_POST['notes']       ?? '');

        if (!in_array($txnType, ['BUY','SELL','TRANSFER_IN','TRANSFER_OUT']))
            json_response(false, 'Invalid transaction type.');
        if ($qty <= 0)   json_response(false, 'Quantity must be positive.');
        if ($price < 0)  json_response(false, 'Price cannot be negative.');
        if (!can_access_portfolio($pid, $userId, $isAdmin)) json_response(false, 'Access denied.');

        $amountInr = $qty * $price;
        // TDS: 1% on SELL amount (Section 194S India)
        $tds = in_array($txnType, ['SELL']) ? round($amountInr * 0.01, 2) : 0;

        // Ensure holding record exists for BUY/TRANSFER_IN
        if (in_array($txnType, ['BUY','TRANSFER_IN'])) {
            $exists = DB::fetchOne(
                "SELECT id FROM crypto_holdings WHERE portfolio_id=? AND coin_id=?", [$pid, $coinId]
            );
            if (!$exists) {
                DB::execute(
                    "INSERT INTO crypto_holdings (portfolio_id, coin_id, coin_symbol, coin_name, quantity, avg_buy_price, total_invested, exchange)
                     VALUES (?,?,?,?,0,0,0,?)",
                    [$pid, $coinId, $symbol, $name, $exchange ?: null]
                );
            }
        }

        DB::execute(
            "INSERT INTO crypto_transactions (portfolio_id, coin_id, coin_symbol, txn_type, quantity, price_inr, amount_inr, tds_deducted, txn_date, exchange, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$pid, $coinId, $symbol, $txnType, $qty, $price, $amountInr, $tds, $txnDate, $exchange ?: null, $notes ?: null]
        );

        // Recalculate holding avg
        crypto_recalc_holding(DB::pdo(), $pid, $coinId);

        audit_log('crypto_txn_add', 'crypto_transactions', 0);
        json_response(true, "Transaction recorded. TDS: ₹{$tds}", ['tds_deducted' => $tds]);
        break;
    }

    default:
        json_response(false, "Unknown crypto action: {$action}");
}
