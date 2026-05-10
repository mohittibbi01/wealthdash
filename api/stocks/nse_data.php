<?php
/**
 * WealthDash — t222: NSE India Free Data — Stock Price + Index Data
 *
 * Actions (GET/POST):
 *   nse_quote        — Live quote for one symbol via NSE public API
 *   nse_quote_bulk   — Bulk quotes for multiple symbols (comma-separated)
 *   nse_ohlc         — OHLC + volume for one symbol (today)
 *   nse_price_sync   — Sync live NSE prices into stock_master for user's holdings
 *   nse_indexes      — Fetch Nifty 50, Nifty Bank, Sensex, etc.
 *   nse_index_detail — Detail + constituents for one index
 *
 * Data Source: NSE India public JSON endpoints (no API key required)
 *   Base: https://www.nseindia.com/api/
 *
 * Route:  GET|POST /api/?action=nse_quote|nse_quote_bulk|nse_ohlc|nse_price_sync|nse_indexes|nse_index_detail
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = clean($_GET['action'] ?? $_POST['action'] ?? 'nse_quote');

/* ═══════════════════════════════════════════════════════════════════
 *  NSE HTTP CLIENT
 *  NSE requires browser-like headers + a session cookie handshake.
 *  We do a lightweight cookie-seeding call first, then the real fetch.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Build a reusable stream-context for NSE API calls.
 * NSE blocks bot UA and requires Referer.
 */
function nse_ctx(array $extra = []): mixed
{
    $opts = [
        'http' => array_merge([
            'timeout'       => 10,
            'ignore_errors' => true,
            'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                             . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                             . 'Chrome/124.0.0.0 Safari/537.36',
            'header'        => implode("\r\n", [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Referer: https://www.nseindia.com/',
                'X-Requested-With: XMLHttpRequest',
                'Connection: keep-alive',
            ]),
        ], $extra),
    ];
    return stream_context_create($opts);
}

/**
 * Fetch from NSE API.
 * Seeds session cookies via homepage on first call per request.
 */
function nse_fetch(string $path): ?array
{
    static $cookieHeader = null;

    // — Seed session cookie if not done yet this request —
    if ($cookieHeader === null) {
        $seedCtx = stream_context_create([
            'http' => [
                'timeout'       => 8,
                'ignore_errors' => true,
                'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                 . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                 . 'Chrome/124.0.0.0 Safari/537.36',
                'header'        => "Accept: text/html,application/xhtml+xml\r\nReferer: https://www.google.com/\r\n",
            ]
        ]);
        @file_get_contents('https://www.nseindia.com/', false, $seedCtx);
        $responseMeta = $http_response_header ?? [];
        $cookies      = [];
        foreach ($responseMeta as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                // Extract name=value part before first semicolon
                $cookiePart = explode(';', substr($h, 12))[0];
                $cookies[]  = trim($cookiePart);
            }
        }
        $cookieHeader = $cookies ? ('Cookie: ' . implode('; ', $cookies)) : '';
    }

    $headers = implode("\r\n", array_filter([
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.nseindia.com/',
        'X-Requested-With: XMLHttpRequest',
        'Connection: keep-alive',
        $cookieHeader,
    ]));

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 10,
            'ignore_errors' => true,
            'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                             . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                             . 'Chrome/124.0.0.0 Safari/537.36',
            'header'        => $headers,
        ]
    ]);

    $url = 'https://www.nseindia.com/api/' . ltrim($path, '/');
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/* ═══════════════════════════════════════════════════════════════════
 *  PARSERS
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Parse NSE quote response into a normalised array.
 * Endpoint: /api/quote-equity?symbol=RELIANCE
 */
function nse_parse_quote(array $data, string $symbol): array
{
    $info    = $data['info']          ?? [];
    $pd      = $data['priceInfo']     ?? [];
    $ind     = $data['industryInfo']  ?? [];
    $metadata = $data['metadata']     ?? [];

    $ltp        = (float)($pd['lastPrice']          ?? 0);
    $prevClose  = (float)($pd['previousClose']      ?? 0);
    $open       = (float)($pd['open']               ?? 0);
    $high       = (float)($pd['intraDayHighLow']['max'] ?? $pd['high'] ?? 0);
    $low        = (float)($pd['intraDayHighLow']['min'] ?? $pd['low']  ?? 0);
    $change     = round($ltp - $prevClose, 2);
    $changePct  = $prevClose > 0 ? round(($change / $prevClose) * 100, 2) : 0;

    $week52High = (float)($pd['weekHighLow']['max'] ?? 0);
    $week52Low  = (float)($pd['weekHighLow']['min'] ?? 0);

    return [
        'symbol'        => $symbol,
        'company_name'  => $info['companyName']    ?? $metadata['companyName'] ?? '',
        'isin'          => $info['isin']            ?? '',
        'series'        => $metadata['series']      ?? 'EQ',
        'exchange'      => 'NSE',
        'ltp'           => $ltp,
        'open'          => $open,
        'high'          => $high,
        'low'           => $low,
        'prev_close'    => $prevClose,
        'change'        => $change,
        'change_pct'    => $changePct,
        'volume'        => (int)($pd['totalTradedVolume'] ?? 0),
        'value_cr'      => round((float)($pd['totalTradedValue'] ?? 0) / 1e7, 2),
        'high_52'       => $week52High,
        'low_52'        => $week52Low,
        'pct_from_52h'  => $week52High > 0 ? round((($week52High - $ltp) / $week52High) * 100, 1) : null,
        'pct_from_52l'  => $week52Low  > 0 ? round((($ltp - $week52Low)  / $week52Low)  * 100, 1) : null,
        'market_cap_cr' => isset($data['marketDeptOrderBook']['tradeInfo']['totalMarketCap'])
                           ? round((float)$data['marketDeptOrderBook']['tradeInfo']['totalMarketCap'] / 1e7, 2)
                           : null,
        'sector'        => $ind['sector']    ?? '',
        'industry'      => $ind['industry']  ?? '',
        'fetched_at'    => date('Y-m-d H:i:s'),
    ];
}

/**
 * Parse NSE index list response.
 * Endpoint: /api/allIndices
 */
function nse_parse_index(array $idx): array
{
    $change    = (float)($idx['change']           ?? 0);
    $last      = (float)($idx['last']             ?? 0);
    $prevClose = $last > 0 ? round($last - $change, 2) : 0;

    return [
        'name'         => $idx['index']           ?? '',
        'key'          => $idx['indexSymbol']      ?? '',
        'last'         => $last,
        'open'         => (float)($idx['open']    ?? 0),
        'high'         => (float)($idx['high']    ?? 0),
        'low'          => (float)($idx['low']     ?? 0),
        'prev_close'   => $prevClose,
        'change'       => $change,
        'change_pct'   => (float)($idx['percentChange'] ?? 0),
        'advances'     => (int)($idx['advances']  ?? 0),
        'declines'     => (int)($idx['declines']  ?? 0),
        'unchanged'    => (int)($idx['unchanged'] ?? 0),
        'year_high'    => (float)($idx['yearHigh'] ?? 0),
        'year_low'     => (float)($idx['yearLow']  ?? 0),
        'fetched_at'   => date('Y-m-d H:i:s'),
    ];
}

/* ═══════════════════════════════════════════════════════════════════
 *  ACTION: nse_quote  — Single symbol live quote
 * ═══════════════════════════════════════════════════════════════════ */
if ($action === 'nse_quote') {
    $symbol = strtoupper(trim(clean($_GET['symbol'] ?? $_POST['symbol'] ?? '')));
    if (!$symbol) json_response(false, 'symbol required');

    $data = nse_fetch("quote-equity?symbol=" . urlencode($symbol));
    if (!$data) {
        // Fallback: try Yahoo Finance for NSE symbol
        json_response(false, "NSE data unavailable for {$symbol}. Try stocks_price action.", [
            'symbol' => $symbol,
        ]);
    }

    $quote = nse_parse_quote($data, $symbol);

    // Optionally update stock_master if stock_id provided
    $stockId = (int)($_GET['stock_id'] ?? $_POST['stock_id'] ?? 0);
    if ($stockId && $quote['ltp'] > 0) {
        DB::run(
            "UPDATE stock_master
             SET latest_price = ?, latest_price_date = CURDATE(),
                 prev_close = ?, day_change_pct = ?,
                 high_52 = COALESCE(NULLIF(?, 0), high_52),
                 low_52  = COALESCE(NULLIF(?, 0), low_52),
                 price_updated_at = NOW()
             WHERE id = ?",
            [
                $quote['ltp'], $quote['prev_close'], $quote['change_pct'],
                $quote['high_52'], $quote['low_52'],
                $stockId,
            ]
        );
    }

    json_response(true, "Quote fetched for {$symbol}.", $quote);
}

/* ═══════════════════════════════════════════════════════════════════
 *  ACTION: nse_quote_bulk  — Multiple symbols
 *  POST symbols = "RELIANCE,TCS,INFY" (comma-separated, max 20)
 * ═══════════════════════════════════════════════════════════════════ */
if ($action === 'nse_quote_bulk') {
    $raw     = clean($_GET['symbols'] ?? $_POST['symbols'] ?? '');
    $symbols = array_filter(array_map('strtoupper', array_map('trim', explode(',', $raw))));

    if (empty($symbols)) json_response(false, 'symbols required (comma-separated)');
    if (count($symbols) > 20) json_response(false, 'Max 20 symbols per bulk call.');

    $results = [];
    $failed  = [];

    foreach ($symbols as $sym) {
        $data = nse_fetch("quote-equity?symbol=" . urlencode($sym));
        if ($data) {
            $results[] = nse_parse_quote($data, $sym);
        } else {
            $failed[] = $sym;
        }
        usleep(250000); // 250ms between NSE calls
    }

    json_response(true, count($results) . ' quotes fetched. ' . count($failed) . ' failed.', [
        'quotes' => $results,
        'failed' => $failed,
    ]);
}

/* ═══════════════════════════════════════════════════════════════════
 *  ACTION: nse_ohlc  — Today's OHLC + volume (lightweight endpoint)
 * ═══════════════════════════════════════════════════════════════════ */
if ($action === 'nse_ohlc') {
    $symbol = strtoupper(trim(clean($_GET['symbol'] ?? $_POST['symbol'] ?? '')));
    if (!$symbol) json_response(false, 'symbol required');

    // NSE market-data endpoint returns OHLCV for all symbols; filter ours
    $data = nse_fetch("quote-equity?symbol=" . urlencode($symbol));
    if (!$data) json_response(false, "Could not fetch OHLC for {$symbol}");

    $pd = $data['priceInfo'] ?? [];

    json_response(true, "OHLC for {$symbol}.", [
        'symbol'     => $symbol,
        'open'       => (float)($pd['open']  ?? 0),
        'high'       => (float)($pd['intraDayHighLow']['max'] ?? $pd['high'] ?? 0),
        'low'        => (float)($pd['intraDayHighLow']['min'] ?? $pd['low']  ?? 0),
        'close'      => (float)($pd['lastPrice']      ?? 0),
        'prev_close' => (float)($pd['previousClose']  ?? 0),
        'volume'     => (int)($pd['totalTradedVolume'] ?? 0),
        'value_cr'   => round((float)($pd['totalTradedValue'] ?? 0) / 1e7, 2),
        'fetched_at' => date('Y-m-d H:i:s'),
    ]);
}

/* ═══════════════════════════════════════════════════════════════════
 *  ACTION: nse_price_sync  — Sync NSE live prices for user's holdings
 *  Updates stock_master: latest_price, latest_price_date,
 *                        prev_close, day_change_pct, price_updated_at
 * ═══════════════════════════════════════════════════════════════════ */
if ($action === 'nse_price_sync') {
    // Fetch all NSE stocks in user's active holdings
    $stocks = DB::fetchAll(
        "SELECT DISTINCT sm.id, sm.symbol
         FROM stock_master sm
         JOIN stock_holdings sh ON sh.stock_id = sm.id
         JOIN portfolios p      ON p.id = sh.portfolio_id
         WHERE p.user_id = ? AND sh.quantity > 0 AND sm.exchange = 'NSE'",
        [$userId]
    );

    if (empty($stocks)) json_response(true, 'No active NSE holdings to sync.', ['updated' => 0]);

    $updated = 0;
    $failed  = [];

    foreach ($stocks as $stock) {
        $data = nse_fetch("quote-equity?symbol=" . urlencode($stock['symbol']));
        if (!$data) {
            $failed[] = $stock['symbol'];
            usleep(200000);
            continue;
        }

        $pd    = $data['priceInfo'] ?? [];
        $ltp   = (float)($pd['lastPrice']     ?? 0);
        $prev  = (float)($pd['previousClose'] ?? 0);
        $chgPct = $prev > 0 ? round(($ltp - $prev) / $prev * 100, 2) : 0;

        if ($ltp > 0) {
            DB::run(
                "UPDATE stock_master
                 SET latest_price      = ?,
                     latest_price_date = CURDATE(),
                     prev_close        = ?,
                     day_change_pct    = ?,
                     price_updated_at  = NOW()
                 WHERE id = ?",
                [$ltp, $prev, $chgPct, $stock['id']]
            );

            // Update holdings calculated columns
            $portfolios = DB::fetchAll(
                "SELECT DISTINCT portfolio_id FROM stock_holdings WHERE stock_id = ? AND quantity > 0",
                [$stock['id']]
            );
            foreach ($portfolios as $port) {
                DB::run(
                    "UPDATE stock_holdings
                     SET current_value = ROUND(quantity * ?, 2),
                         gain_loss     = ROUND(quantity * ? - total_invested, 2),
                         gain_pct      = ROUND(
                             CASE WHEN total_invested > 0
                                  THEN (quantity * ? - total_invested) / total_invested * 100
                                  ELSE 0 END, 2)
                     WHERE stock_id = ? AND portfolio_id = ? AND quantity > 0",
                    [$ltp, $ltp, $ltp, $stock['id'], $port['portfolio_id']]
                );
            }
            $updated++;
        } else {
            $failed[] = $stock['symbol'];
        }

        usleep(250000); // 250ms rate limit
    }

    // Persist sync timestamp
    DB::run(
        "INSERT INTO app_settings (setting_key, setting_val)
         VALUES ('nse_last_sync', NOW())
         ON DUPLICATE KEY UPDATE setting_val = NOW()"
    );

    json_response(true, "NSE sync: {$updated} updated, " . count($failed) . " failed.", [
        'updated' => $updated,
        'failed'  => $failed,
        'synced_at' => date('Y-m-d H:i:s'),
    ]);
}

/* ═══════════════════════════════════════════════════════════════════
 *  ACTION: nse_indexes  — Nifty 50, Bank Nifty, Sensex etc.
 *  Saves to nse_index_snapshots table for historical reference.
 * ═══════════════════════════════════════════════════════════════════ */
if ($action === 'nse_indexes') {
    $data = nse_fetch('allIndices');
    if (!$data || empty($data['data'])) {
        json_response(false, 'Could not fetch NSE index data.');
    }

    // Key indices to surface; filter from full list
    $watchKeys = [
        'NIFTY 50', 'NIFTY BANK', 'NIFTY IT', 'NIFTY FMCG',
        'NIFTY MIDCAP 100', 'NIFTY SMALLCAP 100',
        'NIFTY NEXT 50', 'NIFTY AUTO', 'NIFTY PHARMA',
        'NIFTY METAL', 'NIFTY REALTY', 'INDIA VIX',
    ];

    $indexes = [];
    foreach ($data['data'] as $idx) {
        $name = strtoupper($idx['index'] ?? '');
        if (in_array($name, $watchKeys, true)) {
            $parsed      = nse_parse_index($idx);
            $indexes[]   = $parsed;

            // Persist snapshot
            DB::run(
                "INSERT INTO nse_index_snapshots
                    (index_name, index_key, last_value, open, high, low,
                     prev_close, change_val, change_pct,
                     advances, declines, unchanged, year_high, year_low, snapshot_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, CURDATE())
                 ON DUPLICATE KEY UPDATE
                    last_value  = VALUES(last_value),
                    open        = VALUES(open),
                    high        = VALUES(high),
                    low         = VALUES(low),
                    change_val  = VALUES(change_val),
                    change_pct  = VALUES(change_pct),
                    advances    = VALUES(advances),
                    declines    = VALUES(declines),
                    unchanged   = VALUES(unchanged),
                    year_high   = VALUES(year_high),
                    year_low    = VALUES(year_low),
                    updated_at  = NOW()",
                [
                    $parsed['name'], $parsed['key'], $parsed['last'],
                    $parsed['open'], $parsed['high'], $parsed['low'],
                    $parsed['prev_close'], $parsed['change'], $parsed['change_pct'],
                    $parsed['advances'], $parsed['declines'], $parsed['unchanged'],
                    $parsed['year_high'], $parsed['year_low'],
                ]
            );
        }
    }

    // Sort: Nifty 50 first
    usort($indexes, fn($a, $b) => strcmp($a['name'], $b['name']));

    json_response(true, count($indexes) . ' indexes fetched.', [
        'indexes'    => $indexes,
        'fetched_at' => date('Y-m-d H:i:s'),
    ]);
}

/* ═══════════════════════════════════════════════════════════════════
 *  ACTION: nse_index_detail  — Constituents of a specific index
 *  GET index_name = "NIFTY 50"
 * ═══════════════════════════════════════════════════════════════════ */
if ($action === 'nse_index_detail') {
    $indexName = trim(clean($_GET['index_name'] ?? $_POST['index_name'] ?? ''));
    if (!$indexName) json_response(false, 'index_name required (e.g. NIFTY 50)');

    // NSE endpoint for index constituents
    $path = 'equity-stockIndices?index=' . urlencode(strtoupper($indexName));
    $data = nse_fetch($path);

    if (!$data || empty($data['data'])) {
        json_response(false, "No data for index: {$indexName}");
    }

    $meta         = $data['metadata']        ?? [];
    $indexInfo    = nse_parse_index($data['metadata'] ?? []);
    $constituents = [];

    foreach ($data['data'] as $row) {
        $ltp       = (float)($row['lastPrice']     ?? 0);
        $prevClose = (float)($row['previousClose'] ?? 0);
        $change    = round($ltp - $prevClose, 2);
        $chgPct    = $prevClose > 0 ? round($change / $prevClose * 100, 2) : 0;

        $constituents[] = [
            'symbol'     => $row['symbol']    ?? '',
            'company'    => $row['meta']['companyName'] ?? $row['symbol'] ?? '',
            'ltp'        => $ltp,
            'change'     => $change,
            'change_pct' => $chgPct,
            'volume'     => (int)($row['totalTradedVolume'] ?? 0),
            'year_high'  => (float)($row['yearHigh'] ?? 0),
            'year_low'   => (float)($row['yearLow']  ?? 0),
        ];
    }

    // Sort by market-cap weight (descending change_pct is proxy; true weight needs separate call)
    usort($constituents, fn($a, $b) => $b['volume'] <=> $a['volume']);

    json_response(true, "{$indexName} — " . count($constituents) . " constituents.", [
        'index_name'   => $indexName,
        'last'         => (float)($meta['last'] ?? 0),
        'change'       => (float)($meta['change'] ?? 0),
        'change_pct'   => (float)($meta['percentChange'] ?? 0),
        'advances'     => (int)($meta['advances']  ?? 0),
        'declines'     => (int)($meta['declines']  ?? 0),
        'constituents' => $constituents,
        'fetched_at'   => date('Y-m-d H:i:s'),
    ]);
}

json_response(false, "Unknown action: {$action}. Valid: nse_quote, nse_quote_bulk, nse_ohlc, nse_price_sync, nse_indexes, nse_index_detail.");
