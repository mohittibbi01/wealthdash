<?php
/**
 * WealthDash — t456: US Stock Holdings — NYSE/NASDAQ Portfolio Tracker
 * Actions: us_list, us_add, us_edit, us_delete, us_txn_add, us_txns,
 *          us_summary, us_prices_refresh, us_search,
 *          us_lrs_status, us_lrs_add, us_dividends,
 *          us_fundamentals, us_tax_report
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = clean($_GET['action'] ?? $_POST['action'] ?? '');

// ── Helpers ───────────────────────────────────────────────────────────────────

function us_portfolio(int $userId): ?int
{
    $pid = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, is_admin())) return $pid;
    return get_user_portfolio_id($userId) ?: null;
}

/**
 * Fetch USD/INR rate. Cache in DB for 4 hours.
 */
function us_usd_inr_rate(): float
{
    $today = date('Y-m-d');
    $cached = DB::fetchOne(
        "SELECT rate FROM usd_inr_rate_cache WHERE date_for=? AND fetched_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)",
        [$today]
    );
    if ($cached) return (float)$cached['rate'];

    // Try free exchange rate API
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $raw = @file_get_contents('https://open.er-api.com/v6/latest/USD', false, $ctx);
    $rate = 84.0; // fallback

    if ($raw) {
        $data = json_decode($raw, true);
        if (!empty($data['rates']['INR'])) {
            $rate = (float)$data['rates']['INR'];
        }
    }

    DB::run("INSERT INTO usd_inr_rate_cache (rate, source, date_for) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE rate=VALUES(rate), fetched_at=NOW()",
        [$rate, 'open.er-api', $today]);

    return $rate;
}

/**
 * Fetch US stock price + basic data from Yahoo Finance.
 */
function us_fetch_price(string $symbol): ?array
{
    $ctx = stream_context_create(['http' => [
        'timeout'    => 8,
        'user_agent' => 'Mozilla/5.0 (compatible; WealthDash/2.0)',
        'ignore_errors' => true,
    ]]);

    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=1d";
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;

    $data   = json_decode($raw, true);
    $meta   = $data['chart']['result'][0]['meta'] ?? null;
    if (!$meta) return null;

    return [
        'price'         => (float)($meta['regularMarketPrice']            ?? 0),
        'prev_close'    => (float)($meta['chartPreviousClose']            ?? 0),
        'change_pct'    => isset($meta['regularMarketPrice'], $meta['chartPreviousClose']) && $meta['chartPreviousClose'] > 0
                           ? round(($meta['regularMarketPrice'] - $meta['chartPreviousClose']) / $meta['chartPreviousClose'] * 100, 4)
                           : 0,
        'currency'      => $meta['currency']  ?? 'USD',
        'exchange'      => $meta['exchangeName'] ?? '',
        'short_name'    => $meta['shortName']    ?? $symbol,
    ];
}

/**
 * Fetch fundamentals for a US stock from Yahoo Finance.
 */
function us_fetch_fundamentals(string $symbol): ?array
{
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0', 'ignore_errors' => true]]);
    $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$symbol}?modules=summaryDetail,defaultKeyStatistics,price,assetProfile";
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    $res  = $data['quoteSummary']['result'][0] ?? [];
    $sd   = $res['summaryDetail']        ?? [];
    $ks   = $res['defaultKeyStatistics'] ?? [];
    $pr   = $res['price']                ?? [];
    $ap   = $res['assetProfile']         ?? [];

    return [
        'pe_ratio'       => ((float)($sd['trailingPE']['raw'] ?? 0)) ?: null,
        'pb_ratio'       => ((float)($ks['priceToBook']['raw'] ?? 0)) ?: null,
        'eps_ttm'        => ((float)($ks['trailingEps']['raw'] ?? 0)) ?: null,
        'market_cap_usd' => ((float)($pr['marketCap']['raw'] ?? 0)) ?: null,
        'dividend_yield' => ((float)($sd['dividendYield']['raw'] ?? 0)) ?: null,
        'high_52_usd'    => ((float)($sd['fiftyTwoWeekHigh']['raw'] ?? 0)) ?: null,
        'low_52_usd'     => ((float)($sd['fiftyTwoWeekLow']['raw'] ?? 0)) ?: null,
        'beta'           => ((float)($sd['beta']['raw'] ?? 0)) ?: null,
        'sector'         => $ap['sector']   ?? null,
        'industry'       => $ap['industry'] ?? null,
        'company_name'   => $pr['longName'] ?? $pr['shortName'] ?? null,
    ];
}

// ── Routes ────────────────────────────────────────────────────────────────────
switch ($action) {

    // ─── LIST HOLDINGS ────────────────────────────────────────────────────────
    case 'us_list':
        $pid = us_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rate = us_usd_inr_rate();
        $rows = DB::fetchAll("
            SELECT h.*,
                   m.symbol, m.company_name, m.exchange, m.sector,
                   m.latest_price_usd, m.latest_price_inr,
                   m.price_change_24h_pct, m.high_52_usd, m.low_52_usd,
                   m.pe_ratio, m.dividend_yield,
                   ROUND(h.quantity * m.latest_price_usd, 4)          AS current_value_usd,
                   ROUND(h.quantity * m.latest_price_inr, 2)          AS current_value_inr,
                   ROUND(h.quantity * m.latest_price_usd
                         - h.total_invested_usd, 4)                   AS gain_loss_usd,
                   ROUND(CASE WHEN h.total_invested_usd > 0
                         THEN ((h.quantity * m.latest_price_usd - h.total_invested_usd)
                               / h.total_invested_usd) * 100
                         ELSE 0 END, 2)                                AS gain_pct
            FROM us_stock_holdings h
            JOIN us_stock_master m ON m.id = h.stock_id
            WHERE h.portfolio_id = ?
              AND h.quantity > 0
            ORDER BY (h.quantity * m.latest_price_usd) DESC
        ", [$pid]);

        json_response(true, '', ['data' => $rows, 'usd_inr_rate' => $rate]);


    // ─── SUMMARY ──────────────────────────────────────────────────────────────
    case 'us_summary':
        $pid = us_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rate = us_usd_inr_rate();
        $sum  = DB::fetchOne("
            SELECT
                COUNT(DISTINCT h.stock_id)                          AS total_stocks,
                COALESCE(SUM(h.total_invested_usd), 0)              AS total_invested_usd,
                COALESCE(SUM(h.total_invested_inr), 0)              AS total_invested_inr,
                COALESCE(SUM(h.quantity * m.latest_price_usd), 0)   AS total_current_usd,
                COALESCE(SUM(h.quantity * m.latest_price_inr), 0)   AS total_current_inr
            FROM us_stock_holdings h
            JOIN us_stock_master m ON m.id = h.stock_id
            WHERE h.portfolio_id = ? AND h.quantity > 0
        ", [$pid]);

        $gainUsd = ($sum['total_current_usd'] ?? 0) - ($sum['total_invested_usd'] ?? 0);
        $gainPct = ($sum['total_invested_usd'] ?? 0) > 0
                   ? round($gainUsd / $sum['total_invested_usd'] * 100, 2) : 0;

        json_response(true, '', [
            'summary'       => $sum,
            'gain_loss_usd' => round($gainUsd, 4),
            'gain_pct'      => $gainPct,
            'usd_inr_rate'  => $rate,
        ]);


    // ─── ADD HOLDING ──────────────────────────────────────────────────────────
    case 'us_add':
        $pid      = us_portfolio($userId);
        $symbol   = strtoupper(clean($_POST['symbol']    ?? ''));
        $qty      = (float)($_POST['quantity']           ?? 0);
        $priceUsd = (float)($_POST['price_usd']          ?? 0);
        $buyDate  = clean($_POST['buy_date']             ?? date('Y-m-d'));
        $broker   = clean($_POST['broker']               ?? '');
        $acType   = in_array($_POST['account_type'] ?? '', ['LRS','GIFT_CITY','DOMESTIC_BROKER','OTHER'])
                    ? $_POST['account_type'] : 'LRS';
        $notes    = clean($_POST['notes']                ?? '');

        if (!$pid || !$symbol || $qty <= 0 || $priceUsd <= 0) {
            json_response(false, 'symbol, quantity, and price_usd required.');
        }

        $rate    = us_usd_inr_rate();
        $priceInr= round($priceUsd * $rate, 4);

        // Ensure stock in master
        $sm = DB::fetchOne("SELECT id FROM us_stock_master WHERE symbol=?", [$symbol]);
        if (!$sm) {
            // Fetch data from Yahoo
            $yData = us_fetch_price($symbol);
            $yFund = us_fetch_fundamentals($symbol);
            $latestPrice = $yData ? $yData['price'] : $priceUsd;

            DB::run("
                INSERT INTO us_stock_master
                (symbol, exchange, company_name, sector, industry, latest_price_usd, latest_price_inr,
                 pe_ratio, pb_ratio, eps_ttm, market_cap_usd, dividend_yield, high_52_usd, low_52_usd, beta,
                 price_change_24h_pct, price_updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ", [
                $symbol,
                clean($_POST['exchange'] ?? 'NASDAQ'),
                $yFund['company_name'] ?? $yData['short_name'] ?? $symbol,
                $yFund['sector']   ?? null,
                $yFund['industry'] ?? null,
                $latestPrice,
                round($latestPrice * $rate, 4),
                $yFund['pe_ratio']       ?? null,
                $yFund['pb_ratio']       ?? null,
                $yFund['eps_ttm']        ?? null,
                $yFund['market_cap_usd'] ?? null,
                $yFund['dividend_yield'] ?? null,
                $yFund['high_52_usd']    ?? null,
                $yFund['low_52_usd']     ?? null,
                $yFund['beta']           ?? null,
                $yData['change_pct']     ?? null,
            ]);
            $stockId = (int)$db->lastInsertId();
        } else {
            $stockId = (int)$sm['id'];
        }

        $totalUsd = round($qty * $priceUsd, 4);
        $totalInr = round($qty * $priceInr, 2);

        // Check existing holding
        $existing = DB::fetchOne("SELECT * FROM us_stock_holdings WHERE portfolio_id=? AND stock_id=?", [$pid, $stockId]);
        if ($existing) {
            $newQty      = $existing['quantity'] + $qty;
            $newTotalUsd = $existing['total_invested_usd'] + $totalUsd;
            $newTotalInr = $existing['total_invested_inr'] + $totalInr;
            $newAvgUsd   = $newQty > 0 ? $newTotalUsd / $newQty : 0;
            $newAvgInr   = $newQty > 0 ? $newTotalInr / $newQty : 0;
            DB::run("UPDATE us_stock_holdings SET quantity=?, avg_buy_price_usd=?, avg_buy_price_inr=?,
                     total_invested_usd=?, total_invested_inr=?, updated_at=NOW() WHERE id=?",
                [$newQty, round($newAvgUsd,4), round($newAvgInr,4), $newTotalUsd, $newTotalInr, $existing['id']]);
            $holdingId = $existing['id'];
        } else {
            DB::run("INSERT INTO us_stock_holdings
                (portfolio_id, stock_id, quantity, avg_buy_price_usd, avg_buy_price_inr,
                 total_invested_usd, total_invested_inr, broker, account_type, first_buy_date, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$pid, $stockId, $qty, $priceUsd, $priceInr,
                 $totalUsd, $totalInr, $broker ?: null, $acType, $buyDate, $notes ?: null]);
            $holdingId = (int)$db->lastInsertId();
        }

        // Log transaction
        DB::run("INSERT INTO us_stock_transactions
            (portfolio_id, stock_id, type, quantity, price_usd, price_inr, total_usd, total_inr,
             usd_inr_rate, broker, account_type, txn_date, notes)
            VALUES (?,?,'buy',?,?,?,?,?,?,?,?,?,?)",
            [$pid, $stockId, $qty, $priceUsd, $priceInr, $totalUsd, $totalInr,
             $rate, $broker ?: null, $acType, $buyDate, $notes ?: null]);

        json_response(true, 'Holding added.', ['holding_id' => $holdingId]);


    // ─── EDIT HOLDING ─────────────────────────────────────────────────────────
    case 'us_edit':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = us_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }

        $allowed = ['quantity','avg_buy_price_usd','avg_buy_price_inr',
                    'broker','account_type','first_buy_date','notes'];
        $sets = $params = [];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) { $sets[] = "`{$f}`=?"; $params[] = clean($_POST[$f]); }
        }
        if (!$sets) { json_response(false, 'Nothing to update.'); }

        // Recalculate totals if qty or price changed
        if (isset($_POST['quantity']) || isset($_POST['avg_buy_price_usd'])) {
            $h   = DB::fetchOne("SELECT * FROM us_stock_holdings WHERE id=? AND portfolio_id=?", [$id, $pid]);
            $qty = isset($_POST['quantity'])       ? (float)$_POST['quantity']       : (float)$h['quantity'];
            $avg = isset($_POST['avg_buy_price_usd']) ? (float)$_POST['avg_buy_price_usd'] : (float)$h['avg_buy_price_usd'];
            $sets[]   = '`total_invested_usd`=?';
            $params[] = round($qty * $avg, 4);
        }

        $params[] = $id;
        DB::run("UPDATE us_stock_holdings SET " . implode(',', $sets) . " WHERE id=? AND portfolio_id={$pid}", $params);
        json_response(true, 'Updated.');


    // ─── DELETE HOLDING ───────────────────────────────────────────────────────
    case 'us_delete':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = us_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }
        DB::run("DELETE FROM us_stock_holdings WHERE id=? AND portfolio_id=?", [$id, $pid]);
        json_response(true, 'Holding removed.');


    // ─── REFRESH PRICES ───────────────────────────────────────────────────────
    case 'us_prices_refresh':
        $pid = us_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rate    = us_usd_inr_rate();
        $stocks  = DB::fetchAll("
            SELECT DISTINCT m.id, m.symbol
            FROM us_stock_holdings h
            JOIN us_stock_master m ON m.id = h.stock_id
            WHERE h.portfolio_id=? AND h.quantity>0
        ", [$pid]);

        $updated = 0;
        foreach ($stocks as $s) {
            $data = us_fetch_price($s['symbol']);
            if (!$data || $data['price'] <= 0) continue;

            $priceInr = round($data['price'] * $rate, 4);
            DB::run("UPDATE us_stock_master SET
                     latest_price_usd=?, latest_price_inr=?, price_change_24h_pct=?, price_updated_at=NOW()
                     WHERE id=?",
                [$data['price'], $priceInr, $data['change_pct'], $s['id']]);
            $updated++;
            usleep(200000); // 200ms rate limit
        }

        // Update holding current_value columns
        DB::run("
            UPDATE us_stock_holdings h
            JOIN us_stock_master m ON m.id = h.stock_id
            SET h.current_value_usd = ROUND(h.quantity * m.latest_price_usd, 4),
                h.current_value_inr = ROUND(h.quantity * m.latest_price_inr, 2)
            WHERE h.portfolio_id = ?
        ", [$pid]);

        json_response(true, "Prices updated for {$updated} stocks.", [
            'updated'      => $updated,
            'usd_inr_rate' => $rate,
        ]);


    // ─── SEARCH US STOCKS ─────────────────────────────────────────────────────
    case 'us_search':
        $q = clean($_GET['q'] ?? '');
        if (strlen($q) < 1) { json_response(false, 'Query too short.'); }

        // Check local cache
        $local = DB::fetchAll("
            SELECT id, symbol, company_name, exchange, sector, latest_price_usd
            FROM us_stock_master WHERE symbol LIKE ? OR company_name LIKE ? LIMIT 10
        ", ["{$q}%", "%{$q}%"]);

        // Yahoo search
        $ctx = stream_context_create(['http' => ['timeout'=>6,'user_agent'=>'Mozilla/5.0','ignore_errors'=>true]]);
        $raw = @file_get_contents("https://query2.finance.yahoo.com/v1/finance/search?q=" . urlencode($q) . "&lang=en-US&region=US&quotesCount=8", false, $ctx);
        $yCoi = [];
        if ($raw) {
            $data = json_decode($raw, true);
            $yCoi = array_filter($data['quotes'] ?? [], fn($c) => in_array($c['typeDisp'] ?? '', ['Equity','ETF']));
            $yCoi = array_values(array_slice($yCoi, 0, 8));
        }

        json_response(true, '', ['local' => $local, 'yahoo' => $yCoi]);


    // ─── TRANSACTIONS ─────────────────────────────────────────────────────────
    case 'us_txns':
        $pid = us_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rows = DB::fetchAll("
            SELECT t.*, m.symbol, m.company_name
            FROM us_stock_transactions t
            JOIN us_stock_master m ON m.id = t.stock_id
            WHERE t.portfolio_id=?
            ORDER BY t.txn_date DESC, t.id DESC LIMIT 200
        ", [$pid]);

        json_response(true, '', ['data' => $rows]);


    // ─── ADD TRANSACTION ──────────────────────────────────────────────────────
    case 'us_txn_add':
        $pid      = us_portfolio($userId);
        $stockId  = (int)($_POST['stock_id'] ?? 0);
        $type     = in_array($_POST['type'] ?? '', ['buy','sell','dividend','transfer_in','transfer_out']) ? $_POST['type'] : 'buy';
        $qty      = (float)($_POST['quantity']  ?? 0);
        $priceUsd = (float)($_POST['price_usd'] ?? 0);
        $txnDate  = clean($_POST['txn_date']    ?? date('Y-m-d'));

        if (!$pid || !$stockId || $qty <= 0) { json_response(false, 'stock_id and quantity required.'); }

        $rate     = us_usd_inr_rate();
        $priceInr = round($priceUsd * $rate, 4);
        $totalUsd = round($qty * $priceUsd, 4);
        $totalInr = round($qty * $priceInr, 2);

        DB::run("INSERT INTO us_stock_transactions
            (portfolio_id, stock_id, type, quantity, price_usd, price_inr, total_usd, total_inr,
             usd_inr_rate, broker, account_type, txn_date, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$pid, $stockId, $type, $qty, $priceUsd, $priceInr, $totalUsd, $totalInr,
             $rate, clean($_POST['broker'] ?? ''), clean($_POST['account_type'] ?? 'LRS'),
             $txnDate, clean($_POST['notes'] ?? '')]);

        json_response(true, 'Transaction logged.');


    // ─── LRS STATUS ───────────────────────────────────────────────────────────
    case 'us_lrs_status':
        $fy = clean($_GET['fy'] ?? ('FY' . date('Y') . '-' . (date('Y') + 1)));

        $row = DB::fetchOne("SELECT * FROM us_lrs_tracker WHERE user_id=? AND financial_year=?",
            [$userId, $fy]);

        if (!$row) {
            // Auto-compute from transactions
            $txnSum = DB::fetchOne("
                SELECT COALESCE(SUM(t.total_usd), 0) AS total_usd,
                       COALESCE(SUM(t.total_inr), 0) AS total_inr
                FROM us_stock_transactions t
                JOIN us_stock_holdings h ON h.id = t.portfolio_id
                WHERE h.portfolio_id IN (
                    SELECT id FROM portfolios WHERE user_id=?
                ) AND t.type='buy'
                  AND t.txn_date BETWEEN ? AND ?
            ", [$userId, date('Y') . '-04-01', date('Y', strtotime('+1 year')) . '-03-31']);

            $row = [
                'financial_year' => $fy,
                'remitted_usd'   => $txnSum['total_usd'] ?? 0,
                'remitted_inr'   => $txnSum['total_inr'] ?? 0,
                'limit_usd'      => 250000,
            ];
        }

        $usedPct     = $row['limit_usd'] > 0 ? round($row['remitted_usd'] / $row['limit_usd'] * 100, 2) : 0;
        $remainingUsd= $row['limit_usd'] - $row['remitted_usd'];

        json_response(true, '', [
            'fy'            => $fy,
            'remitted_usd'  => $row['remitted_usd'],
            'remitted_inr'  => $row['remitted_inr'],
            'limit_usd'     => $row['limit_usd'],
            'remaining_usd' => max(0, $remainingUsd),
            'used_pct'      => $usedPct,
            'status'        => $usedPct >= 100 ? 'limit_reached' : ($usedPct >= 80 ? 'near_limit' : 'ok'),
        ]);


    // ─── LOG LRS REMITTANCE ───────────────────────────────────────────────────
    case 'us_lrs_add':
        $fy        = clean($_POST['financial_year'] ?? ('FY' . date('Y') . '-' . (date('Y') + 1)));
        $amountUsd = (float)($_POST['amount_usd']   ?? 0);
        $amountInr = (float)($_POST['amount_inr']   ?? 0);
        $notes     = clean($_POST['notes']           ?? '');

        if ($amountUsd <= 0) { json_response(false, 'amount_usd required.'); }

        DB::run("INSERT INTO us_lrs_tracker (user_id, financial_year, remitted_usd, remitted_inr, notes)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   remitted_usd = remitted_usd + VALUES(remitted_usd),
                   remitted_inr = remitted_inr + VALUES(remitted_inr),
                   notes = VALUES(notes), updated_at=NOW()",
            [$userId, $fy, $amountUsd, $amountInr, $notes ?: null]);

        json_response(true, 'LRS remittance logged.');


    // ─── FUNDAMENTALS ─────────────────────────────────────────────────────────
    case 'us_fundamentals':
        $symbol  = strtoupper(clean($_GET['symbol'] ?? ''));
        $stockId = (int)($_GET['stock_id'] ?? 0);
        if (!$symbol && !$stockId) { json_response(false, 'symbol or stock_id required.'); }

        if (!$symbol && $stockId) {
            $sm     = DB::fetchOne("SELECT symbol FROM us_stock_master WHERE id=?", [$stockId]);
            $symbol = $sm['symbol'] ?? '';
        }

        $cached = DB::fetchOne("SELECT * FROM us_stock_master WHERE symbol=?", [$symbol]);
        $stale  = !$cached || !$cached['fundamentals_updated_at']
                  || (time() - strtotime($cached['fundamentals_updated_at'])) > 86400;

        if (!$stale) { json_response(true, 'cached', $cached); }

        $f = us_fetch_fundamentals($symbol);
        if ($f && $cached) {
            DB::run("UPDATE us_stock_master SET pe_ratio=?,pb_ratio=?,eps_ttm=?,market_cap_usd=?,
                     dividend_yield=?,high_52_usd=?,low_52_usd=?,beta=?,sector=?,industry=?,
                     fundamentals_updated_at=NOW() WHERE id=?",
                [$f['pe_ratio'],$f['pb_ratio'],$f['eps_ttm'],$f['market_cap_usd'],
                 $f['dividend_yield'],$f['high_52_usd'],$f['low_52_usd'],$f['beta'],
                 $f['sector'],$f['industry'], $cached['id']]);
        }

        $updated = DB::fetchOne("SELECT * FROM us_stock_master WHERE symbol=?", [$symbol]);
        json_response(true, 'fetched', $updated ?: array_merge(['symbol' => $symbol], $f ?? []));


    // ─── TAX REPORT ───────────────────────────────────────────────────────────
    case 'us_tax_report':
        $pid  = us_portfolio($userId);
        $year = (int)($_GET['year'] ?? date('Y'));
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        // All sell transactions in the year
        $sells = DB::fetchAll("
            SELECT t.*, m.symbol, m.company_name
            FROM us_stock_transactions t
            JOIN us_stock_master m ON m.id = t.stock_id
            WHERE t.portfolio_id=? AND t.type='sell' AND YEAR(t.txn_date)=?
            ORDER BY t.txn_date ASC
        ", [$pid, $year]);

        // Dividends
        $dividends = DB::fetchAll("
            SELECT t.*, m.symbol, m.company_name
            FROM us_stock_transactions t
            JOIN us_stock_master m ON m.id = t.stock_id
            WHERE t.portfolio_id=? AND t.type='dividend' AND YEAR(t.txn_date)=?
        ", [$pid, $year]);

        $totalSaleUsd     = array_sum(array_column($sells, 'total_usd'));
        $totalDividendUsd = array_sum(array_column($dividends, 'total_usd'));
        $rate             = us_usd_inr_rate();

        json_response(true, '', [
            'year'               => $year,
            'sells'              => $sells,
            'dividends'          => $dividends,
            'total_sale_usd'     => round($totalSaleUsd, 4),
            'total_sale_inr'     => round($totalSaleUsd * $rate, 2),
            'total_dividend_usd' => round($totalDividendUsd, 4),
            'total_dividend_inr' => round($totalDividendUsd * $rate, 2),
            'usd_inr_rate'       => $rate,
            'tax_notes'          => [
                'capital_gains'  => 'US stocks held <24 months: taxed as STCG at slab rate. >=24 months: LTCG at 12.5% without indexation.',
                'dividends'      => 'Dividends taxed as "Income from Other Sources". US withholds 25% TDS; claim DTAA credit in Indian ITR.',
                'foreign_assets' => 'Schedule FA in ITR required for foreign assets. Declare in Schedule FSI for foreign income.',
                'lrs'            => 'Investments via LRS capped at $250,000/year. TCS @5% on remittance >₹7L (claimable as tax credit).',
            ],
        ]);


    default:
        json_response(false, "Unknown US stock action: {$action}", [], 400);
}
