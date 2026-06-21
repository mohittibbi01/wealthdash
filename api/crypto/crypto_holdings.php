<?php
/**
 * WealthDash — t40: Crypto Holdings + CoinGecko Full Integration
 * Actions: crypto_list, crypto_add, crypto_edit_holding, crypto_delete,
 *          crypto_txn_add, crypto_txns, crypto_prices, crypto_summary,
 *          crypto_portfolio_stats, crypto_coingecko_search,
 *          crypto_coingecko_coin, crypto_refresh_prices,
 *          crypto_wl_list, crypto_wl_add, crypto_wl_delete
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = clean($_GET['action'] ?? $_POST['action'] ?? '');

// ── CoinGecko helpers ─────────────────────────────────────────────────────────

define('CG_BASE', 'https://api.coingecko.com/api/v3');

function cg_get(string $path, array $params = []): ?array
{
    $qs  = $params ? '?' . http_build_query($params) : '';
    $url = CG_BASE . $path . $qs;
    $ctx = stream_context_create(['http' => [
        'timeout'       => 10,
        'user_agent'    => 'WealthDash/2.0',
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;
    return json_decode($raw, true) ?: null;
}

/**
 * Fetch prices for multiple coin IDs from CoinGecko.
 * Returns ['bitcoin' => ['inr' => 4500000, 'inr_24h_change' => 1.2], ...]
 */
function cg_prices(array $coinIds, string $currency = 'inr'): ?array
{
    if (empty($coinIds)) return [];
    return cg_get('/simple/price', [
        'ids'                       => implode(',', $coinIds),
        'vs_currencies'             => $currency,
        'include_24hr_change'       => 'true',
        'include_24hr_vol'          => 'true',
        'include_market_cap'        => 'true',
        'include_last_updated_at'   => 'true',
    ]);
}

/**
 * Get portfolio ID for user; optionally verify access.
 */
function crypto_portfolio(int $userId): ?int
{
    $pid = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, is_admin())) return $pid;
    return get_user_portfolio_id($userId) ?: null;
}

// ── Route ─────────────────────────────────────────────────────────────────────
switch ($action) {

    // ─── LIST HOLDINGS ────────────────────────────────────────────────────────
    case 'crypto_list':
        $pid = crypto_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $holdings = $db->prepare("
            SELECT ch.*,
                   cm.coingecko_id, cm.symbol, cm.name, cm.logo_url,
                   cm.current_price_inr, cm.price_change_24h, cm.market_cap_inr,
                   cm.price_updated_at,
                   ROUND(ch.quantity * cm.current_price_inr, 2)         AS current_value,
                   ROUND(ch.quantity * cm.current_price_inr
                         - ch.total_invested, 2)                         AS gain_loss,
                   ROUND(CASE WHEN ch.total_invested > 0
                         THEN ((ch.quantity * cm.current_price_inr - ch.total_invested)
                               / ch.total_invested) * 100
                         ELSE 0 END, 2)                                  AS gain_pct
            FROM crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            WHERE ch.portfolio_id = ? AND ch.quantity > 0
            ORDER BY (ch.quantity * cm.current_price_inr) DESC
        ");
        $holdings->execute([$pid]);
        $rows = $holdings->fetchAll();

        json_response(true, '', ['data' => $rows]);


    // ─── SUMMARY ──────────────────────────────────────────────────────────────
    case 'crypto_summary':
        $pid = crypto_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $row = $db->prepare("
            SELECT
                COUNT(DISTINCT ch.coin_id)                               AS total_coins,
                COALESCE(SUM(ch.total_invested), 0)                      AS total_invested,
                COALESCE(SUM(ch.quantity * cm.current_price_inr), 0)     AS total_current_value,
                COALESCE(SUM(ch.quantity * cm.current_price_inr
                             - ch.total_invested), 0)                    AS total_gain_loss,
                ROUND(CASE WHEN SUM(ch.total_invested) > 0
                      THEN ((SUM(ch.quantity * cm.current_price_inr)
                             - SUM(ch.total_invested)) / SUM(ch.total_invested)) * 100
                      ELSE 0 END, 2)                                     AS gain_pct
            FROM crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            WHERE ch.portfolio_id = ? AND ch.quantity > 0
        ");
        $row->execute([$pid]);
        $summary = $row->fetch();

        // Top gainer / loser
        $gl = $db->prepare("
            SELECT cm.symbol, cm.name,
                   ROUND(cm.price_change_24h, 2) AS change_24h
            FROM crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            WHERE ch.portfolio_id = ? AND ch.quantity > 0
            ORDER BY cm.price_change_24h DESC
        ");
        $gl->execute([$pid]);
        $gainers = $gl->fetchAll();
        $topGainer = $gainers[0]  ?? null;
        $topLoser  = end($gainers) ?: null;

        json_response(true, '', [
            'summary'    => $summary,
            'top_gainer' => $topGainer,
            'top_loser'  => $topLoser ?: null,
        ]);


    // ─── PORTFOLIO STATS ──────────────────────────────────────────────────────
    case 'crypto_portfolio_stats':
        $pid = crypto_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        // Allocation per coin
        $alloc = $db->prepare("
            SELECT cm.symbol, cm.name, cm.logo_url,
                   ROUND(ch.quantity * cm.current_price_inr, 2) AS value,
                   ROUND((ch.quantity * cm.current_price_inr) /
                         NULLIF(SUM(ch2.quantity * cm2.current_price_inr) OVER(), 0) * 100, 2) AS pct
            FROM crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            JOIN crypto_holdings ch2 ON ch2.portfolio_id = ch.portfolio_id AND ch2.quantity > 0
            JOIN crypto_master cm2 ON cm2.id = ch2.coin_id
            WHERE ch.portfolio_id = ? AND ch.quantity > 0
            GROUP BY ch.id
            ORDER BY value DESC
        ");
        $alloc->execute([$pid]);

        // P&L per coin
        $pnl = $db->prepare("
            SELECT cm.symbol, cm.name,
                   ch.total_invested,
                   ROUND(ch.quantity * cm.current_price_inr, 2) AS current_value,
                   ROUND(ch.quantity * cm.current_price_inr - ch.total_invested, 2) AS gain_loss,
                   ROUND(CASE WHEN ch.total_invested > 0
                         THEN ((ch.quantity * cm.current_price_inr - ch.total_invested)
                               / ch.total_invested) * 100
                         ELSE 0 END, 2) AS gain_pct
            FROM crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            WHERE ch.portfolio_id = ? AND ch.quantity > 0
            ORDER BY gain_loss DESC
        ");
        $pnl->execute([$pid]);

        json_response(true, '', [
            'allocation' => $alloc->fetchAll(),
            'pnl'        => $pnl->fetchAll(),
        ]);


    // ─── PRICES (batch refresh) ───────────────────────────────────────────────
    case 'crypto_prices':
    case 'crypto_refresh_prices':
        $pid = crypto_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        // Get all coingecko_ids in this portfolio
        $stmt = $db->prepare("
            SELECT DISTINCT cm.id, cm.coingecko_id
            FROM crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            WHERE ch.portfolio_id = ? AND ch.quantity > 0
        ");
        $stmt->execute([$pid]);
        $coins = $stmt->fetchAll();

        if (empty($coins)) { json_response(true, '', ['prices' => []]); }

        $cgIds   = array_column($coins, 'coingecko_id');
        $prices  = cg_prices($cgIds, 'inr');

        if (!$prices) { json_response(false, 'CoinGecko API unavailable. Showing cached prices.'); }

        $updated = 0;
        foreach ($prices as $cgId => $data) {
            $price   = (float)($data['inr']             ?? 0);
            $change  = (float)($data['inr_24h_change']  ?? 0);
            $mcap    = (float)($data['inr_market_cap']  ?? 0);
            $vol     = (float)($data['inr_24h_vol']     ?? 0);
            if ($price <= 0) continue;
            $db->prepare("
                UPDATE crypto_master
                SET current_price_inr=?, price_change_24h=?, market_cap_inr=?,
                    volume_24h_inr=?, price_updated_at=NOW()
                WHERE coingecko_id=?
            ")->execute([$price, $change, $mcap, $vol, $cgId]);
            $updated++;
        }

        // Also refresh holdings current_value
        $db->prepare("
            UPDATE crypto_holdings ch
            JOIN crypto_master cm ON cm.id = ch.coin_id
            SET ch.current_value = ROUND(ch.quantity * cm.current_price_inr, 2)
            WHERE ch.portfolio_id = ?
        ")->execute([$pid]);

        json_response(true, "Prices updated for {$updated} coins.", ['updated' => $updated, 'prices' => $prices]);


    // ─── ADD HOLDING ──────────────────────────────────────────────────────────
    case 'crypto_add':
        $pid       = crypto_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $coinId    = (int)  ($_POST['coin_id']    ?? 0);
        $cgId      = clean  ($_POST['coingecko_id']?? '');
        $symbol    = strtoupper(clean($_POST['symbol'] ?? ''));
        $name      = clean  ($_POST['name']       ?? '');
        $quantity  = (float)($_POST['quantity']   ?? 0);
        $buyPrice  = (float)($_POST['buy_price']  ?? 0);
        $buyDate   = clean  ($_POST['buy_date']   ?? date('Y-m-d'));
        $exchange  = clean  ($_POST['exchange']   ?? '');
        $wallet    = clean  ($_POST['wallet']      ?? '');
        $notes     = clean  ($_POST['notes']       ?? '');

        if ($quantity <= 0 || $buyPrice <= 0) {
            json_response(false, 'Quantity and buy price are required.');
        }

        // Ensure coin in master
        if (!$coinId && ($cgId || $symbol)) {
            $existing = $db->prepare("SELECT id FROM crypto_master WHERE coingecko_id=? OR symbol=? LIMIT 1");
            $existing->execute([$cgId ?: '', $symbol]);
            $cm = $existing->fetch();
            if ($cm) {
                $coinId = (int)$cm['id'];
            } else {
                // Fetch from CoinGecko to populate master
                $cgData = $cgId ? cg_get("/coins/{$cgId}", ['localization'=>'false','tickers'=>'false','community_data'=>'false']) : null;
                $currentPrice = $cgData['market_data']['current_price']['inr'] ?? 0;
                $db->prepare("
                    INSERT INTO crypto_master (coingecko_id, symbol, name, logo_url, current_price_inr, price_updated_at)
                    VALUES (?,?,?,?,?,NOW())
                ")->execute([
                    $cgId   ?: strtolower($symbol),
                    $symbol,
                    $name   ?: ($cgData['name'] ?? $symbol),
                    $cgData['image']['thumb'] ?? null,
                    $currentPrice,
                ]);
                $coinId = (int)$db->lastInsertId();
            }
        }

        if (!$coinId) { json_response(false, 'Could not resolve coin. Provide coin_id or coingecko_id.'); }

        $totalInvested = round($quantity * $buyPrice, 8);

        // Check existing holding for this coin in portfolio
        $existing = $db->prepare("SELECT id, quantity, total_invested, avg_buy_price FROM crypto_holdings WHERE portfolio_id=? AND coin_id=?");
        $existing->execute([$pid, $coinId]);
        $holding = $existing->fetch();

        if ($holding) {
            // Weighted avg price
            $newQty    = $holding['quantity'] + $quantity;
            $newTotal  = $holding['total_invested'] + $totalInvested;
            $newAvg    = $newQty > 0 ? $newTotal / $newQty : 0;
            $db->prepare("
                UPDATE crypto_holdings SET quantity=?, total_invested=?, avg_buy_price=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$newQty, $newTotal, $newAvg, $holding['id']]);
            $holdingId = $holding['id'];
        } else {
            $db->prepare("
                INSERT INTO crypto_holdings (portfolio_id, coin_id, quantity, total_invested, avg_buy_price, first_buy_date, created_at)
                VALUES (?,?,?,?,?,?,NOW())
            ")->execute([$pid, $coinId, $quantity, $totalInvested, $buyPrice, $buyDate]);
            $holdingId = (int)$db->lastInsertId();
        }

        // Log transaction
        $db->prepare("
            INSERT INTO crypto_transactions (portfolio_id, coin_id, type, quantity, price_inr, total_inr, exchange_name, wallet, note, txn_date)
            VALUES (?,?,'buy',?,?,?,?,?,?,?)
        ")->execute([$pid, $coinId, $quantity, $buyPrice, $totalInvested, $exchange ?: null, $wallet ?: null, $notes ?: null, $buyDate]);

        json_response(true, 'Holding added.', ['holding_id' => $holdingId]);


    // ─── EDIT HOLDING ─────────────────────────────────────────────────────────
    case 'crypto_edit_holding':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = crypto_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }
        $h = $db->prepare("SELECT id FROM crypto_holdings WHERE id=? AND portfolio_id=?")->execute([$id, $pid]);

        $allowed = ['quantity','avg_buy_price','total_invested','first_buy_date','wallet','notes'];
        $sets = $params = [];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) { $sets[] = "`{$f}`=?"; $params[] = clean($_POST[$f]); }
        }
        if (!$sets) { json_response(false, 'Nothing to update.'); }
        $params[] = $id;
        $db->prepare("UPDATE crypto_holdings SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
        json_response(true, 'Holding updated.');


    // ─── DELETE HOLDING ───────────────────────────────────────────────────────
    case 'crypto_delete':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = crypto_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }
        $db->prepare("DELETE FROM crypto_holdings WHERE id=? AND portfolio_id=?")->execute([$id, $pid]);
        json_response(true, 'Holding removed.');


    // ─── TRANSACTION LIST ─────────────────────────────────────────────────────
    case 'crypto_txns':
        $pid    = crypto_portfolio($userId);
        $coinId = (int)($_GET['coin_id'] ?? 0);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }
        $where = $coinId ? 'AND t.coin_id=?' : '';
        $params = $coinId ? [$pid, $coinId] : [$pid];
        $stmt = $db->prepare("
            SELECT t.*, cm.symbol, cm.name, cm.logo_url
            FROM crypto_transactions t
            JOIN crypto_master cm ON cm.id = t.coin_id
            WHERE t.portfolio_id=? {$where}
            ORDER BY t.txn_date DESC, t.id DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        json_response(true, '', ['data' => $stmt->fetchAll()]);


    // ─── ADD TRANSACTION ──────────────────────────────────────────────────────
    case 'crypto_txn_add':
        $pid      = crypto_portfolio($userId);
        $coinId   = (int)  ($_POST['coin_id']  ?? 0);
        $type     = in_array($_POST['type'] ?? '', ['buy','sell','transfer_in','transfer_out','staking','airdrop','mining']) ? $_POST['type'] : 'buy';
        $quantity = (float)($_POST['quantity'] ?? 0);
        $price    = (float)($_POST['price_inr']?? 0);
        $fee      = (float)($_POST['fee_inr']  ?? 0);
        $txnDate  = clean  ($_POST['txn_date'] ?? date('Y-m-d'));

        if (!$pid || !$coinId || $quantity <= 0) {
            json_response(false, 'portfolio_id, coin_id, and quantity required.');
        }

        $total = round($quantity * $price, 8);
        $db->prepare("
            INSERT INTO crypto_transactions
            (portfolio_id, coin_id, type, quantity, price_inr, total_inr, fee_inr, exchange_name, wallet, note, txn_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$pid, $coinId, $type, $quantity, $price, $total, $fee,
            clean($_POST['exchange_name'] ?? ''), clean($_POST['wallet'] ?? ''),
            clean($_POST['note'] ?? ''), $txnDate]);

        // Update holding quantity
        $existH = $db->prepare("SELECT id, quantity, total_invested, avg_buy_price FROM crypto_holdings WHERE portfolio_id=? AND coin_id=?")->execute([$pid, $coinId]);
        $h = $db->prepare("SELECT * FROM crypto_holdings WHERE portfolio_id=? AND coin_id=?")->execute([$pid, $coinId]);

        // Recalculate holding from all transactions
        $reCalc = $db->prepare("
            SELECT
                SUM(CASE WHEN type IN ('buy','transfer_in','staking','airdrop','mining') THEN quantity ELSE -quantity END) AS net_qty,
                SUM(CASE WHEN type = 'buy' THEN total_inr ELSE 0 END) AS buy_total,
                SUM(CASE WHEN type = 'buy' THEN quantity ELSE 0 END)  AS buy_qty
            FROM crypto_transactions WHERE portfolio_id=? AND coin_id=?
        ");
        $reCalc->execute([$pid, $coinId]);
        $rc = $reCalc->fetch();
        $netQty   = max(0, (float)($rc['net_qty']  ?? 0));
        $avgPrice = ($rc['buy_qty'] ?? 0) > 0 ? (float)$rc['buy_total'] / (float)$rc['buy_qty'] : $price;

        $existH2 = $db->prepare("SELECT id FROM crypto_holdings WHERE portfolio_id=? AND coin_id=?");
        $existH2->execute([$pid, $coinId]);
        $hRow = $existH2->fetch();
        if ($hRow) {
            $db->prepare("UPDATE crypto_holdings SET quantity=?, avg_buy_price=?, total_invested=?, updated_at=NOW() WHERE id=?")
               ->execute([$netQty, $avgPrice, ($rc['buy_total'] ?? 0), $hRow['id']]);
        } elseif ($netQty > 0) {
            $db->prepare("INSERT INTO crypto_holdings (portfolio_id, coin_id, quantity, avg_buy_price, total_invested, first_buy_date) VALUES (?,?,?,?,?,?)")
               ->execute([$pid, $coinId, $netQty, $avgPrice, ($rc['buy_total'] ?? 0), $txnDate]);
        }
        json_response(true, 'Transaction logged.');


    // ─── COINGECKO SEARCH ─────────────────────────────────────────────────────
    case 'crypto_coingecko_search':
        $q = clean($_GET['q'] ?? '');
        if (strlen($q) < 2) { json_response(false, 'Query too short.'); }

        // Try cache in crypto_master first
        $stmt = $db->prepare("SELECT id, coingecko_id, symbol, name, logo_url, current_price_inr FROM crypto_master WHERE name LIKE ? OR symbol LIKE ? LIMIT 10");
        $stmt->execute(["%{$q}%", "%{$q}%"]);
        $cached = $stmt->fetchAll();

        // Also live search from CoinGecko
        $cgResults = cg_get('/search', ['query' => $q]);
        $liveCoins = array_slice($cgResults['coins'] ?? [], 0, 10);

        json_response(true, '', ['cached' => $cached, 'coingecko' => $liveCoins]);


    // ─── COINGECKO COIN DETAIL ────────────────────────────────────────────────
    case 'crypto_coingecko_coin':
        $cgId = clean($_GET['id'] ?? '');
        if (!$cgId) { json_response(false, 'id required.'); }

        $data = cg_get("/coins/{$cgId}", [
            'localization'   => 'false',
            'tickers'        => 'false',
            'community_data' => 'false',
            'developer_data' => 'false',
            'sparkline'      => 'false',
        ]);
        if (!$data) { json_response(false, 'CoinGecko unavailable.'); }

        // Cache / upsert in master
        $priceInr = (float)($data['market_data']['current_price']['inr'] ?? 0);
        $stmt = $db->prepare("SELECT id FROM crypto_master WHERE coingecko_id=?");
        $stmt->execute([$cgId]);
        $existing = $stmt->fetch();
        if ($existing) {
            $db->prepare("UPDATE crypto_master SET current_price_inr=?, name=?, logo_url=?, market_cap_inr=?, price_updated_at=NOW() WHERE coingecko_id=?")
               ->execute([$priceInr, $data['name'], $data['image']['small'] ?? null,
                    $data['market_data']['market_cap']['inr'] ?? 0, $cgId]);
        } else {
            $db->prepare("INSERT INTO crypto_master (coingecko_id, symbol, name, logo_url, current_price_inr, market_cap_inr, price_updated_at) VALUES (?,?,?,?,?,?,NOW())")
               ->execute([
                    $cgId,
                    strtoupper($data['symbol'] ?? $cgId),
                    $data['name'],
                    $data['image']['small'] ?? null,
                    $priceInr,
                    $data['market_data']['market_cap']['inr'] ?? 0,
               ]);
        }
        json_response(true, '', ['data' => $data]);


    // ─── WATCHLIST LIST ───────────────────────────────────────────────────────
    case 'crypto_wl_list':
        $stmt = $db->prepare("
            SELECT cw.*, cm.coingecko_id, cm.symbol, cm.name, cm.logo_url,
                   cm.current_price_inr, cm.price_change_24h, cm.market_cap_inr,
                   ROUND(((cm.current_price_inr - cw.alert_price_low)  / NULLIF(cw.alert_price_low, 0)) * 100, 2)  AS pct_from_low,
                   ROUND(((cw.alert_price_high - cm.current_price_inr) / NULLIF(cw.alert_price_high, 0)) * 100, 2) AS pct_from_high
            FROM crypto_watchlist cw
            JOIN crypto_master cm ON cm.id = cw.coin_id
            WHERE cw.user_id=?
            ORDER BY cw.created_at DESC
        ");
        $stmt->execute([$userId]);
        json_response(true, '', ['data' => $stmt->fetchAll()]);


    // ─── WATCHLIST ADD ────────────────────────────────────────────────────────
    case 'crypto_wl_add':
        $coinId  = (int)($_POST['coin_id']         ?? 0);
        $low     = isset($_POST['alert_price_low'])  ? (float)$_POST['alert_price_low']  : null;
        $high    = isset($_POST['alert_price_high']) ? (float)$_POST['alert_price_high'] : null;
        $notes   = clean($_POST['notes']            ?? '');
        if (!$coinId) { json_response(false, 'coin_id required.'); }

        $exists = $db->prepare("SELECT id FROM crypto_watchlist WHERE user_id=? AND coin_id=?");
        $exists->execute([$userId, $coinId]);
        if ($exists->fetch()) { json_response(false, 'Already in watchlist.'); }

        $db->prepare("INSERT INTO crypto_watchlist (user_id, coin_id, alert_price_low, alert_price_high, notes) VALUES (?,?,?,?,?)")
           ->execute([$userId, $coinId, $low, $high, $notes ?: null]);
        json_response(true, 'Added to watchlist.');


    // ─── WATCHLIST DELETE ─────────────────────────────────────────────────────
    case 'crypto_wl_delete':
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM crypto_watchlist WHERE id=? AND user_id=?")->execute([$id, $userId]);
        json_response(true, 'Removed from watchlist.');


    // ─── TAX YEAR SUMMARY ─────────────────────────────────────────────────────
    case 'crypto_tax_year_summary':
        $year = (int)($_GET['year'] ?? date('Y'));
        $pid  = crypto_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $stmt = $db->prepare("
            SELECT t.*, cm.symbol, cm.name
            FROM crypto_transactions t
            JOIN crypto_master cm ON cm.id = t.coin_id
            WHERE t.portfolio_id=?
              AND YEAR(t.txn_date) = ?
              AND t.type = 'sell'
            ORDER BY t.txn_date ASC
        ");
        $stmt->execute([$pid, $year]);
        $sells = $stmt->fetchAll();

        $totalProceeds = 0; $totalCost = 0;
        foreach ($sells as $s) {
            $totalProceeds += (float)$s['total_inr'];
            $totalCost     += (float)$s['quantity'] * (float)$s['price_inr'];
        }

        json_response(true, '', [
            'year'            => $year,
            'total_sells'     => count($sells),
            'total_proceeds'  => $totalProceeds,
            'total_cost'      => $totalCost,
            'vda_gain'        => $totalProceeds - $totalCost,
            'tax_rate_pct'    => 30,
            'estimated_tax'   => max(0, ($totalProceeds - $totalCost) * 0.30),
            'transactions'    => $sells,
        ]);


    default:
        json_response(false, "Unknown crypto action: {$action}", [], 400);
}
