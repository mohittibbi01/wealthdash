<?php
/**
 * WealthDash — t315: Crypto Holdings — Full Portfolio Tracking
 *
 * Actions handled (routed from router.php):
 *   GET  crypto_portfolio_stats  — exchange breakdown, category pie, summary
 *   GET  crypto_staking_list     — staking / yield rewards list + totals
 *   POST crypto_staking_add      — add staking reward entry
 *   POST crypto_staking_delete   — delete staking reward
 *   POST crypto_edit_holding     — edit exchange / wallet / category / notes
 *   GET  crypto_wl_list          — watchlist with live prices + alert flags
 *   POST crypto_wl_add           — add / upsert watchlist entry
 *   POST crypto_wl_delete        — remove watchlist entry
 *   GET  crypto_tax_year_summary — FY-wise VDA tax snapshot (30% flat)
 *
 * Router note (add to router.php case block ~line 795):
 *   case 'crypto_portfolio_stats':
 *   case 'crypto_staking_list':
 *   case 'crypto_staking_add':
 *   case 'crypto_staking_delete':
 *   case 'crypto_edit_holding':
 *   case 'crypto_wl_list':
 *   case 'crypto_wl_add':
 *   case 'crypto_wl_delete':
 *   case 'crypto_tax_year_summary':
 *       require APP_ROOT . '/api/crypto/crypto_holdings.php'; exit;
 *
 * DB deps: crypto_holdings, crypto_transactions, crypto_price_cache,
 *          crypto_staking_rewards (t315_migration.sql),
 *          crypto_watchlist (t315_migration.sql), portfolios
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$isAdmin     = (bool)($currentUser['is_admin'] ?? false);
$db          = DB::pdo();

$action      = $_GET['action'] ?? $_POST['action'] ?? '';
$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$pWhere      = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

// ── Shared: get live prices for a coin list ───────────────────────────────────
function t315_prices(PDO $db, array $coinIds): array
{
    if (empty($coinIds)) return [];
    $ph     = implode(',', array_fill(0, count($coinIds), '?'));
    $cached = $db->prepare(
        "SELECT coin_id, price_inr, price_usd, change_24h
         FROM crypto_price_cache
         WHERE coin_id IN ($ph) AND fetched_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $cached->execute($coinIds);
    $prices = [];
    foreach ($cached->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $prices[$r['coin_id']] = $r;
    }

    $missing = array_values(array_diff($coinIds, array_keys($prices)));
    if (!empty($missing)) {
        $idsParam = implode(',', array_map('rawurlencode', $missing));
        $url      = "https://api.coingecko.com/api/v3/simple/price"
                  . "?ids={$idsParam}&vs_currencies=inr,usd"
                  . "&include_24hr_change=true&include_market_cap=true";
        $ctx  = stream_context_create(['http' => ['timeout' => 7, 'header' => "User-Agent: WealthDash/1.0\r\n"]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $data = $raw ? json_decode($raw, true) : [];

        if (is_array($data)) {
            $ups = $db->prepare(
                "INSERT INTO crypto_price_cache (coin_id, price_inr, price_usd, change_24h, market_cap)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE price_inr=VALUES(price_inr), price_usd=VALUES(price_usd),
                     change_24h=VALUES(change_24h), market_cap=VALUES(market_cap), fetched_at=NOW()"
            );
            foreach ($missing as $cid) {
                $r = $data[$cid] ?? null;
                if (!$r) continue;
                $pInr = (float)($r['inr'] ?? 0);
                $pUsd = (float)($r['usd'] ?? 0);
                $chg  = $r['inr_24h_change'] ?? $r['usd_24h_change'] ?? null;
                $mcap = isset($r['inr_market_cap']) ? (int)$r['inr_market_cap'] : null;
                $ups->execute([$cid, $pInr, $pUsd, $chg, $mcap]);
                $prices[$cid] = ['coin_id' => $cid, 'price_inr' => $pInr,
                                 'price_usd' => $pUsd, 'change_24h' => $chg];
            }
        }
    }
    return $prices;
}

// ── Auto-categorise a coin_id ─────────────────────────────────────────────────
function t315_category(string $coinId, string $symbol): string
{
    $btc  = ['bitcoin'];
    $eth  = ['ethereum', 'ethereum-2-0'];
    $stable = ['tether', 'usd-coin', 'binance-usd', 'dai', 'true-usd', 'frax',
               'usdc', 'usdt', 'busd', 'tusd'];
    $defi = ['uniswap', 'aave', 'compound-governance-token', 'maker', 'curve-dao-token',
              'sushiswap', '1inch', 'yearn-finance', 'balancer', 'synthetix-network-token'];
    if (in_array($coinId, $btc))    return 'Bitcoin';
    if (in_array($coinId, $eth))    return 'Ethereum';
    if (in_array($coinId, $stable)) return 'Stablecoin';
    if (in_array($coinId, $defi))   return 'DeFi';
    if (in_array(strtolower($symbol), ['bnb','sol','ada','xrp','dot','avax','matic','trx','ltc','link']))
        return 'Large-cap';
    return 'Altcoin';
}

// ════════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ════════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── PORTFOLIO STATS: exchange & category breakdown ────────────────────────
    case 'crypto_portfolio_stats': {

        $rows = DB::fetchAll(
            "SELECT ch.coin_id, ch.coin_symbol, ch.coin_name,
                    ch.quantity, ch.total_invested, ch.avg_buy_price,
                    ch.exchange, ch.category, ch.wallet_tag,
                    p.name AS portfolio_name
             FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id
             WHERE ch.quantity > 0 {$pWhere}
             ORDER BY ch.total_invested DESC"
        );

        $coinIds = array_unique(array_column($rows, 'coin_id'));
        $prices  = t315_prices($db, $coinIds);

        // Per-row enrichment
        $totalInvested = 0.0; $totalCurrent = 0.0;
        $byExchange    = []; $byCategory = [];
        $enriched      = [];

        foreach ($rows as $r) {
            $p          = $prices[$r['coin_id']] ?? null;
            $priceInr   = (float)($p['price_inr'] ?? 0);
            $curVal     = $priceInr * (float)$r['quantity'];
            $inv        = (float)$r['total_invested'];
            $gain       = $curVal - $inv;
            $gainPct    = $inv > 0 ? ($gain / $inv * 100) : 0;
            $cat        = $r['category'] ?: t315_category($r['coin_id'], $r['coin_symbol']);
            $exch       = $r['exchange'] ?: 'Unknown';

            $totalInvested += $inv;
            $totalCurrent  += $curVal;

            // Exchange rollup
            if (!isset($byExchange[$exch])) $byExchange[$exch] = ['exchange' => $exch, 'invested' => 0, 'current' => 0, 'coins' => 0];
            $byExchange[$exch]['invested'] += $inv;
            $byExchange[$exch]['current']  += $curVal;
            $byExchange[$exch]['coins']++;

            // Category rollup
            if (!isset($byCategory[$cat])) $byCategory[$cat] = ['category' => $cat, 'invested' => 0, 'current' => 0, 'coins' => 0];
            $byCategory[$cat]['invested'] += $inv;
            $byCategory[$cat]['current']  += $curVal;
            $byCategory[$cat]['coins']++;

            $enriched[] = [
                'coin_id'        => $r['coin_id'],
                'coin_symbol'    => $r['coin_symbol'],
                'coin_name'      => $r['coin_name'],
                'quantity'       => (float)$r['quantity'],
                'avg_buy_price'  => round((float)$r['avg_buy_price'], 4),
                'total_invested' => round($inv, 2),
                'price_inr'      => round($priceInr, 4),
                'price_usd'      => round((float)($p['price_usd'] ?? 0), 6),
                'change_24h'     => $p['change_24h'] ?? null,
                'current_value'  => round($curVal, 2),
                'gain_inr'       => round($gain, 2),
                'gain_pct'       => round($gainPct, 2),
                'exchange'       => $r['exchange'],
                'wallet_tag'     => $r['wallet_tag'],
                'category'       => $cat,
                'portfolio_name' => $r['portfolio_name'],
                'allocation_pct' => 0, // filled below
            ];
        }

        // Allocation % (by current value)
        foreach ($enriched as &$e) {
            $e['allocation_pct'] = $totalCurrent > 0
                ? round($e['current_value'] / $totalCurrent * 100, 2)
                : 0;
        }
        unset($e);

        // Add gain% to exchange/category breakdowns
        foreach ($byExchange as &$ex) {
            $ex['gain']      = round($ex['current'] - $ex['invested'], 2);
            $ex['gain_pct']  = $ex['invested'] > 0 ? round(($ex['current'] - $ex['invested']) / $ex['invested'] * 100, 2) : 0;
            $ex['alloc_pct'] = $totalCurrent > 0 ? round($ex['current'] / $totalCurrent * 100, 2) : 0;
        }
        unset($ex);
        foreach ($byCategory as &$bc) {
            $bc['gain']      = round($bc['current'] - $bc['invested'], 2);
            $bc['gain_pct']  = $bc['invested'] > 0 ? round(($bc['current'] - $bc['invested']) / $bc['invested'] * 100, 2) : 0;
            $bc['alloc_pct'] = $totalCurrent > 0 ? round($bc['current'] / $totalCurrent * 100, 2) : 0;
        }
        unset($bc);

        // Total staking income (all time)
        $stakingTotal = (float)(DB::fetchOne(
            "SELECT COALESCE(SUM(sr.value_inr),0) AS tot
             FROM crypto_staking_rewards sr
             JOIN portfolios p ON p.id = sr.portfolio_id
             WHERE 1=1 {$pWhere}"
        )['tot'] ?? 0);

        // Txn count
        $txnCount = (int)(DB::fetchOne(
            "SELECT COUNT(*) AS c FROM crypto_transactions ct
             JOIN portfolios p ON p.id = ct.portfolio_id WHERE 1=1 {$pWhere}"
        )['c'] ?? 0);

        $totalGain    = $totalCurrent - $totalInvested;
        $totalGainPct = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0;

        json_response(true, '', [
            'summary' => [
                'total_invested'   => round($totalInvested, 2),
                'total_current'    => round($totalCurrent, 2),
                'total_gain'       => round($totalGain, 2),
                'total_gain_pct'   => $totalGainPct,
                'coin_count'       => count($rows),
                'txn_count'        => $txnCount,
                'staking_income'   => round($stakingTotal, 2),
                'prices_at'        => date('H:i:s'),
            ],
            'holdings'      => $enriched,
            'by_exchange'   => array_values($byExchange),
            'by_category'   => array_values($byCategory),
        ]);
        break;
    }

    // ── EDIT HOLDING (exchange / wallet / category / notes) ──────────────────
    case 'crypto_edit_holding': {
        $id       = (int)($_POST['id'] ?? 0);
        $exchange = clean($_POST['exchange']   ?? '');
        $wallet   = clean($_POST['wallet_address'] ?? '');
        $walletTag= clean($_POST['wallet_tag'] ?? '');
        $category = clean($_POST['category']   ?? '');
        $notes    = clean($_POST['notes']      ?? '');

        if (!$id) json_response(false, 'Holding ID required.');

        // Ownership check
        $row = DB::fetchOne(
            "SELECT ch.id, p.user_id FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id WHERE ch.id=? LIMIT 1",
            [$id]
        );
        if (!$row || ((int)$row['user_id'] !== $userId && !$isAdmin))
            json_response(false, 'Not found or access denied.');

        $allowed_categories = ['Bitcoin','Ethereum','Stablecoin','DeFi','Large-cap','Altcoin','NFT','Other'];
        if ($category && !in_array($category, $allowed_categories))
            json_response(false, 'Invalid category.');

        $sets   = [];
        $params = [];
        if ($exchange !== '')  { $sets[] = 'exchange=?';        $params[] = $exchange ?: null; }
        if ($wallet !== '')    { $sets[] = 'wallet_address=?';  $params[] = $wallet ?: null; }
        if ($walletTag !== '') { $sets[] = 'wallet_tag=?';      $params[] = $walletTag ?: null; }
        if ($category !== '')  { $sets[] = 'category=?';        $params[] = $category; }
        if ($notes !== '')     { $sets[] = 'notes=?';           $params[] = $notes ?: null; }

        if (empty($sets)) json_response(false, 'Nothing to update.');

        $params[] = $id;
        DB::execute("UPDATE crypto_holdings SET " . implode(', ', $sets) . ", updated_at=NOW() WHERE id=?", $params);
        audit_log('crypto_edit_holding', 'crypto_holdings', $id);
        json_response(true, 'Holding updated.');
        break;
    }

    // ── STAKING REWARDS: LIST ─────────────────────────────────────────────────
    case 'crypto_staking_list': {
        $coinFilter = clean($_GET['coin_id'] ?? '');
        $coinCond   = $coinFilter ? "AND sr.coin_id = " . $db->quote($coinFilter) : '';

        $rows = DB::fetchAll(
            "SELECT sr.*
             FROM crypto_staking_rewards sr
             JOIN portfolios p ON p.id = sr.portfolio_id
             WHERE 1=1 {$pWhere} {$coinCond}
             ORDER BY sr.reward_date DESC, sr.id DESC
             LIMIT 500"
        );

        // Live value of staking tokens in portfolio
        $coinIds = array_unique(array_column($rows, 'coin_id'));
        $prices  = t315_prices($db, $coinIds);

        $totalValueAtReceipt = 0.0;
        $totalCurrentValue   = 0.0;
        $byType              = [];

        foreach ($rows as &$r) {
            $p         = $prices[$r['coin_id']] ?? null;
            $curPrice  = (float)($p['price_inr'] ?? 0);
            $curVal    = $curPrice * (float)$r['quantity'];
            $r['current_price_inr'] = round($curPrice, 4);
            $r['current_value_inr'] = round($curVal, 2);
            $r['unrealised_gain']   = round($curVal - (float)$r['value_inr'], 2);

            $totalValueAtReceipt += (float)$r['value_inr'];
            $totalCurrentValue   += $curVal;

            $t = $r['reward_type'];
            if (!isset($byType[$t])) $byType[$t] = ['type' => $t, 'value_inr' => 0, 'count' => 0];
            $byType[$t]['value_inr'] += (float)$r['value_inr'];
            $byType[$t]['count']++;
        }
        unset($r);

        json_response(true, '', [
            'rewards'               => $rows,
            'total_value_at_receipt'=> round($totalValueAtReceipt, 2),
            'total_current_value'   => round($totalCurrentValue, 2),
            'by_type'               => array_values($byType),
        ]);
        break;
    }

    // ── STAKING REWARDS: ADD ──────────────────────────────────────────────────
    case 'crypto_staking_add': {
        $pid        = (int)($_POST['portfolio_id'] ?? 0) ?: get_user_portfolio_id($userId);
        $coinId     = clean($_POST['coin_id']     ?? '');
        $symbol     = strtoupper(clean($_POST['coin_symbol'] ?? ''));
        $rewardType = clean($_POST['reward_type'] ?? 'STAKING');
        $qty        = (float)($_POST['quantity']  ?? 0);
        $priceInr   = (float)($_POST['price_inr'] ?? 0);
        $platform   = clean($_POST['platform']    ?? '');
        $rewardDate = clean($_POST['reward_date'] ?? date('Y-m-d'));
        $notes      = clean($_POST['notes']       ?? '');

        if (!$pid)    json_response(false, 'Portfolio required.');
        if (!$coinId) json_response(false, 'Coin ID required.');
        if (!$symbol) json_response(false, 'Coin symbol required.');
        if ($qty <= 0) json_response(false, 'Quantity must be positive.');

        $allowed = ['STAKING','YIELD','AIRDROP','MINING','INTEREST'];
        if (!in_array($rewardType, $allowed)) json_response(false, 'Invalid reward type.');

        if (!can_access_portfolio($pid, $userId, $isAdmin)) json_response(false, 'Access denied.');

        // If price not supplied, try cache
        if ($priceInr <= 0) {
            $cached = DB::fetchOne("SELECT price_inr FROM crypto_price_cache WHERE coin_id=?", [$coinId]);
            $priceInr = $cached ? (float)$cached['price_inr'] : 0;
        }
        $valueInr = round($qty * $priceInr, 2);

        $id = DB::insert(
            "INSERT INTO crypto_staking_rewards
             (portfolio_id, coin_id, coin_symbol, reward_type, quantity, price_inr, value_inr, platform, reward_date, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$pid, $coinId, $symbol, $rewardType, $qty, $priceInr, $valueInr, $platform ?: null, $rewardDate, $notes ?: null]
        );

        audit_log('crypto_staking_add', 'crypto_staking_rewards', (int)$id);
        json_response(true, "Staking reward recorded (₹" . number_format($valueInr, 2) . " on {$rewardDate}).",
            ['id' => $id, 'value_inr' => $valueInr]);
        break;
    }

    // ── STAKING REWARDS: DELETE ───────────────────────────────────────────────
    case 'crypto_staking_delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'ID required.');

        $row = DB::fetchOne(
            "SELECT sr.id, p.user_id FROM crypto_staking_rewards sr
             JOIN portfolios p ON p.id = sr.portfolio_id WHERE sr.id=? LIMIT 1",
            [$id]
        );
        if (!$row || ((int)$row['user_id'] !== $userId && !$isAdmin))
            json_response(false, 'Not found or access denied.');

        DB::execute("DELETE FROM crypto_staking_rewards WHERE id=?", [$id]);
        audit_log('crypto_staking_delete', 'crypto_staking_rewards', $id);
        json_response(true, 'Staking reward deleted.');
        break;
    }

    // ── WATCHLIST: LIST with live prices ─────────────────────────────────────
    case 'crypto_wl_list': {
        $rows = DB::fetchAll(
            "SELECT * FROM crypto_watchlist WHERE user_id=? ORDER BY created_at DESC",
            [$userId]
        );

        $coinIds = array_column($rows, 'coin_id');
        $prices  = t315_prices($db, $coinIds);

        foreach ($rows as &$r) {
            $p = $prices[$r['coin_id']] ?? null;
            $priceInr         = (float)($p['price_inr'] ?? 0);
            $r['price_inr']   = round($priceInr, 4);
            $r['price_usd']   = round((float)($p['price_usd'] ?? 0), 6);
            $r['change_24h']  = $p['change_24h'] ?? null;
            $r['alert_high']  = $r['alert_high'] ? (float)$r['alert_high'] : null;
            $r['alert_low']   = $r['alert_low']  ? (float)$r['alert_low']  : null;
            // Trigger flags
            $r['alert_high_triggered'] = $r['alert_high'] && $priceInr >= (float)$r['alert_high'];
            $r['alert_low_triggered']  = $r['alert_low']  && $priceInr <= (float)$r['alert_low'];
        }
        unset($r);

        json_response(true, '', $rows);
        break;
    }

    // ── WATCHLIST: ADD / UPSERT ────────────────────────────────────────────────
    case 'crypto_wl_add': {
        $coinId  = clean($_POST['coin_id']     ?? '');
        $symbol  = strtoupper(clean($_POST['coin_symbol'] ?? ''));
        $name    = clean($_POST['coin_name']   ?? $symbol);
        $high    = strlen($_POST['alert_high'] ?? '') > 0 ? (float)$_POST['alert_high'] : null;
        $low     = strlen($_POST['alert_low']  ?? '') > 0 ? (float)$_POST['alert_low']  : null;
        $notes   = clean($_POST['notes']       ?? '');

        if (!$coinId) json_response(false, 'Coin ID required.');
        if (!$symbol) json_response(false, 'Coin symbol required.');

        DB::execute(
            "INSERT INTO crypto_watchlist (user_id, coin_id, coin_symbol, coin_name, alert_high, alert_low, notes)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE alert_high=VALUES(alert_high), alert_low=VALUES(alert_low),
                                     notes=VALUES(notes)",
            [$userId, $coinId, $symbol, $name, $high, $low, $notes ?: null]
        );
        json_response(true, "{$name} added to watchlist.");
        break;
    }

    // ── WATCHLIST: DELETE ─────────────────────────────────────────────────────
    case 'crypto_wl_delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'ID required.');

        $row = DB::fetchOne("SELECT id FROM crypto_watchlist WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$row) json_response(false, 'Not found or access denied.');

        DB::execute("DELETE FROM crypto_watchlist WHERE id=?", [$id]);
        json_response(true, 'Removed from watchlist.');
        break;
    }

    // ── FY TAX YEAR SUMMARY ────────────────────────────────────────────────────
    case 'crypto_tax_year_summary': {
        $fy      = (int)($_GET['fy'] ?? ((int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1));
        $fyStart = "{$fy}-04-01";
        $fyEnd   = ($fy + 1) . "-03-31";

        // Sell txns this FY
        $sells = DB::fetchAll(
            "SELECT ct.coin_id, ct.coin_symbol, ct.quantity,
                    ct.price_inr, ct.amount_inr, ct.tds_deducted, ct.txn_date
             FROM crypto_transactions ct
             JOIN portfolios p ON p.id = ct.portfolio_id
             WHERE ct.txn_type = 'SELL' AND ct.txn_date BETWEEN ? AND ? {$pWhere}
             ORDER BY ct.txn_date",
            [$fyStart, $fyEnd]
        );

        // Avg buy prices from holdings
        $avgMap = [];
        $avgs = DB::fetchAll(
            "SELECT ch.coin_id, ch.avg_buy_price FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id WHERE 1=1 {$pWhere}"
        );
        foreach ($avgs as $a) $avgMap[$a['coin_id']] = (float)$a['avg_buy_price'];

        $totalSale = 0; $totalCost = 0; $totalGain = 0; $totalTds = 0;
        $breakdown = [];

        foreach ($sells as $s) {
            $saleVal   = (float)$s['amount_inr'];
            $costBasis = ($avgMap[$s['coin_id']] ?? 0) * (float)$s['quantity'];
            $gain      = $saleVal - $costBasis;
            $tax30     = max(0, $gain * 0.30);
            $tds       = (float)$s['tds_deducted'];

            $totalSale += $saleVal; $totalCost += $costBasis;
            $totalGain += $gain;   $totalTds  += $tds;

            $breakdown[] = [
                'date'         => $s['txn_date'],
                'coin'         => $s['coin_symbol'],
                'qty'          => (float)$s['quantity'],
                'sale_price'   => round((float)$s['price_inr'], 4),
                'sale_value'   => round($saleVal, 2),
                'cost_basis'   => round($costBasis, 2),
                'gain'         => round($gain, 2),
                'tax_30pct'    => round($tax30, 2),
                'tds_deducted' => round($tds, 2),
                'net_tax_due'  => round(max(0, $tax30 - $tds), 2),
            ];
        }

        // Staking income (fully taxable as income)
        $stakingIncome = (float)(DB::fetchOne(
            "SELECT COALESCE(SUM(sr.value_inr),0) AS tot
             FROM crypto_staking_rewards sr
             JOIN portfolios p ON p.id = sr.portfolio_id
             WHERE sr.reward_date BETWEEN ? AND ? {$pWhere}",
            [$fyStart, $fyEnd]
        )['tot'] ?? 0);

        $totalTaxPayable = max(0, $totalGain * 0.30);
        $netTaxDue       = max(0, $totalTaxPayable - $totalTds);

        json_response(true, '', [
            'fy'                 => "FY {$fy}-" . ($fy + 1),
            'fy_start'           => $fyStart,
            'fy_end'             => $fyEnd,
            'total_sale_value'   => round($totalSale, 2),
            'total_cost_basis'   => round($totalCost, 2),
            'total_gain'         => round($totalGain, 2),
            'total_tax_payable'  => round($totalTaxPayable, 2),
            'total_tds_deducted' => round($totalTds, 2),
            'net_tax_due'        => round($netTaxDue, 2),
            'staking_income'     => round($stakingIncome, 2),
            'staking_tax_note'   => 'Staking/airdrop income is taxable as income at slab rates (CBDT circular)',
            'loss_offset_note'   => 'Crypto losses CANNOT be offset against any other income (Section 115BBH)',
            'breakdown'          => $breakdown,
            'txn_count'          => count($breakdown),
        ]);
        break;
    }

    default:
        json_response(false, "Unknown crypto_holdings action: {$action}");
}
