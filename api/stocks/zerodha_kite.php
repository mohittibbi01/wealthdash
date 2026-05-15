<?php
/**
 * WealthDash — t391: Zerodha Kite Connect — Full Integration
 * Extends t301 zerodha_sync.php with:
 *   quotes, orders, margins, instruments, GTT, portfolio-to-WD sync
 *
 * Actions: zerodha_quotes, zerodha_orders, zerodha_orders_sync,
 *          zerodha_margins, zerodha_instruments_refresh,
 *          zerodha_gtt_list, zerodha_gtt_sync,
 *          zerodha_full_sync, zerodha_profile
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = clean($_GET['action'] ?? $_POST['action'] ?? '');

// ── Shared helpers (mirrors t301) ─────────────────────────────────────────────
define('KITE_API', 'https://api.kite.trade');

function kz_cred(int $userId): ?array
{
    $row = DB::fetchOne(
        "SELECT * FROM zerodha_credentials WHERE user_id=? AND is_active=1",
        [$userId]
    );
    return $row ?: null;
}

function kz_decrypt(string $val): string
{
    if (defined('ENCRYPT_KEY') && ENCRYPT_KEY) {
        $decoded = base64_decode($val);
        $iv      = substr($decoded, 0, 16);
        $cipher  = substr($decoded, 16);
        return openssl_decrypt($cipher, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv) ?: $val;
    }
    return base64_decode($val) ?: $val;
}

function kz_req(string $method, string $path, array $params, string $apiKey, string $token): ?array
{
    $ch = curl_init();
    $headers = [
        'X-Kite-Version: 3',
        'Authorization: token ' . $apiKey . ':' . $token,
    ];

    if ($method === 'GET') {
        $qs = $params ? '?' . http_build_query($params) : '';
        curl_setopt($ch, CURLOPT_URL, KITE_API . $path . $qs);
    } else {
        curl_setopt($ch, CURLOPT_URL, KITE_API . $path);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body) return null;
    $data = json_decode($body, true);
    return $data ?: null;
}

function kz_auth(int $userId): ?array
{
    $cred = kz_cred($userId);
    if (!$cred) return null;
    if (!$cred['token_expiry'] || strtotime($cred['token_expiry']) < time()) return null;
    return [
        'api_key'      => kz_decrypt($cred['api_key']),
        'access_token' => kz_decrypt($cred['access_token']),
        'cred'         => $cred,
    ];
}

// ── Routes ────────────────────────────────────────────────────────────────────
switch ($action) {

    // ─── PROFILE ──────────────────────────────────────────────────────────────
    case 'zerodha_profile':
        $auth = kz_auth($userId);
        if (!$auth) { json_response(false, 'Not connected or token expired.'); }

        $resp = kz_req('GET', '/user/profile', [], $auth['api_key'], $auth['access_token']);
        if (!$resp || $resp['status'] !== 'success') {
            json_response(false, 'Failed to fetch profile.');
        }

        $cred = $auth['cred'];
        json_response(true, '', [
            'profile'       => $resp['data'],
            'last_sync_at'  => $cred['last_sync_at'],
            'token_expiry'  => $cred['token_expiry'],
        ]);


    // ─── LIVE QUOTES ──────────────────────────────────────────────────────────
    case 'zerodha_quotes':
        $auth = kz_auth($userId);
        if (!$auth) { json_response(false, 'Not connected or token expired.'); }

        // Symbols from POST or fetch from portfolio
        $symbolsRaw = $_POST['symbols'] ?? $_GET['symbols'] ?? '';
        if ($symbolsRaw) {
            $symbols = array_filter(array_map('trim', explode(',', $symbolsRaw)));
        } else {
            // Auto-fetch all holdings symbols
            $rows = DB::fetchAll(
                "SELECT CONCAT(exchange,':',tradingsymbol) AS sym FROM zerodha_holdings_raw WHERE user_id=? AND quantity>0",
                [$userId]
            );
            $symbols = array_column($rows, 'sym');
        }

        if (empty($symbols)) { json_response(false, 'No symbols to quote.'); }

        // Kite accepts max 500 symbols per call; chunk if needed
        $allQuotes = [];
        foreach (array_chunk($symbols, 200) as $chunk) {
            $resp = kz_req('GET', '/quote', ['i' => $chunk], $auth['api_key'], $auth['access_token']);
            if ($resp && $resp['status'] === 'success') {
                $allQuotes = array_merge($allQuotes, $resp['data']);
            }
        }

        // Cache snapshots
        foreach ($allQuotes as $sym => $q) {
            [$exchange, $tsym] = array_pad(explode(':', $sym, 2), 2, '');
            $lastPrice = (float)($q['last_price'] ?? 0);
            $close     = (float)($q['ohlc']['close'] ?? 0);
            $chg       = $lastPrice - $close;
            $chgPct    = $close > 0 ? ($chg / $close) * 100 : 0;

            DB::run("
                INSERT INTO zerodha_quote_snapshots
                (user_id, tradingsymbol, exchange, last_price, net_change, net_change_pct,
                 volume, oi, high, low, open, close, raw_json, fetched_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                  last_price=VALUES(last_price), net_change=VALUES(net_change),
                  net_change_pct=VALUES(net_change_pct), volume=VALUES(volume),
                  oi=VALUES(oi), high=VALUES(high), low=VALUES(low),
                  open=VALUES(open), close=VALUES(close), raw_json=VALUES(raw_json), fetched_at=NOW()
            ", [$userId, $tsym, $exchange, $lastPrice, round($chg,4), round($chgPct,4),
                (int)($q['volume'] ?? 0), (int)($q['oi'] ?? 0),
                (float)($q['ohlc']['high'] ?? 0), (float)($q['ohlc']['low'] ?? 0),
                (float)($q['ohlc']['open'] ?? 0), $close,
                json_encode($q)]);

            // Also update zerodha_holdings_raw last_price
            DB::run("
                UPDATE zerodha_holdings_raw SET last_price=?, synced_at=NOW()
                WHERE user_id=? AND tradingsymbol=? AND exchange=?
            ", [$lastPrice, $userId, $tsym, $exchange]);
        }

        json_response(true, '', ['quotes' => $allQuotes, 'fetched' => count($allQuotes)]);


    // ─── ORDERS LIST (from DB) ─────────────────────────────────────────────────
    case 'zerodha_orders':
        $rows = DB::fetchAll("
            SELECT * FROM zerodha_orders WHERE user_id=?
            ORDER BY order_timestamp DESC LIMIT 100
        ", [$userId]);
        json_response(true, '', ['data' => $rows]);


    // ─── SYNC ORDERS from Kite ────────────────────────────────────────────────
    case 'zerodha_orders_sync':
        $auth = kz_auth($userId);
        if (!$auth) { json_response(false, 'Not connected or token expired.'); }

        $resp = kz_req('GET', '/orders', [], $auth['api_key'], $auth['access_token']);
        if (!$resp || $resp['status'] !== 'success') {
            json_response(false, 'Orders fetch failed.');
        }

        $orders  = $resp['data'] ?? [];
        $synced  = 0;

        foreach ($orders as $o) {
            $orderId = $o['order_id'] ?? '';
            if (!$orderId) continue;

            DB::run("
                INSERT INTO zerodha_orders
                (user_id, order_id, exchange_order_id, parent_order_id, status, status_message,
                 tradingsymbol, exchange, instrument_token, order_type, transaction_type, product,
                 validity, quantity, pending_quantity, filled_quantity, price, trigger_price,
                 average_price, placed_by, variety, order_timestamp, exchange_timestamp, raw_json, synced_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                  status=VALUES(status), status_message=VALUES(status_message),
                  filled_quantity=VALUES(filled_quantity), pending_quantity=VALUES(pending_quantity),
                  average_price=VALUES(average_price), raw_json=VALUES(raw_json), synced_at=NOW()
            ", [
                $userId, $orderId,
                $o['exchange_order_id'] ?? null,
                $o['parent_order_id']   ?? null,
                $o['status']            ?? null,
                $o['status_message']    ?? null,
                $o['tradingsymbol']     ?? '',
                $o['exchange']          ?? null,
                $o['instrument_token']  ?? null,
                $o['order_type']        ?? null,
                $o['transaction_type']  ?? null,
                $o['product']           ?? null,
                $o['validity']          ?? null,
                (int)($o['quantity']          ?? 0),
                (int)($o['pending_quantity']  ?? 0),
                (int)($o['filled_quantity']   ?? 0),
                (float)($o['price']           ?? 0),
                (float)($o['trigger_price']   ?? 0),
                (float)($o['average_price']   ?? 0),
                $o['placed_by']         ?? null,
                $o['variety']           ?? null,
                !empty($o['order_timestamp'])  ? date('Y-m-d H:i:s', strtotime($o['order_timestamp']))  : null,
                !empty($o['exchange_timestamp'])? date('Y-m-d H:i:s', strtotime($o['exchange_timestamp'])): null,
                json_encode($o),
            ]);
            $synced++;
        }

        json_response(true, "Orders synced: {$synced}.", ['synced' => $synced]);


    // ─── MARGINS ──────────────────────────────────────────────────────────────
    case 'zerodha_margins':
        $auth = kz_auth($userId);
        if (!$auth) { json_response(false, 'Not connected or token expired.'); }

        $resp = kz_req('GET', '/user/margins', [], $auth['api_key'], $auth['access_token']);
        if (!$resp || $resp['status'] !== 'success') {
            json_response(false, 'Margins fetch failed.');
        }

        foreach ($resp['data'] as $segment => $m) {
            DB::run("
                INSERT INTO zerodha_margins (user_id, segment, net, available, used, payin, payout, raw_json, fetched_at)
                VALUES (?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                  net=VALUES(net), available=VALUES(available), used=VALUES(used),
                  payin=VALUES(payin), payout=VALUES(payout), raw_json=VALUES(raw_json), fetched_at=NOW()
            ", [
                $userId, $segment,
                (float)($m['net']                     ?? 0),
                (float)($m['available']['live_balance']?? 0),
                (float)($m['utilised']['debits']       ?? 0),
                (float)($m['available']['opening_balance'] ?? 0),
                (float)($m['available']['opening_balance'] ?? 0),
                json_encode($m),
            ]);
        }

        json_response(true, '', ['data' => $resp['data']]);


    // ─── INSTRUMENTS REFRESH ──────────────────────────────────────────────────
    // Downloads CSV from Kite and caches key EQ instruments in DB
    case 'zerodha_instruments_refresh':
        $auth     = kz_auth($userId);
        $exchange = clean($_POST['exchange'] ?? 'NSE');
        if (!in_array($exchange, ['NSE','BSE','NFO','CDS','MCX'])) {
            json_response(false, 'Invalid exchange.');
        }

        // Kite instruments endpoint returns CSV (no auth needed for NSE/BSE)
        $csvUrl = 'https://api.kite.trade/instruments/' . $exchange;
        $ctx    = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'WealthDash/2.0']]);
        $raw    = @file_get_contents($csvUrl, false, $ctx);

        if (!$raw) { json_response(false, 'Could not fetch instruments CSV.'); }

        $lines  = explode("\n", trim($raw));
        $header = str_getcsv(array_shift($lines));
        $hIdx   = array_flip($header);

        $inserted = 0;
        $batch    = [];

        foreach ($lines as $line) {
            if (!trim($line)) continue;
            $row = str_getcsv($line);
            if (count($row) < count($header)) continue;

            // Only cache EQ segment to avoid millions of derivatives rows
            $instrType = $row[$hIdx['instrument_type']] ?? '';
            if ($exchange === 'NSE' && $instrType !== 'EQ') continue;

            $batch[] = [
                'instrument_token' => (int)($row[$hIdx['instrument_token']] ?? 0),
                'exchange_token'   => (int)($row[$hIdx['exchange_token']]   ?? 0),
                'tradingsymbol'    => $row[$hIdx['tradingsymbol']]  ?? '',
                'name'             => $row[$hIdx['name']]           ?? '',
                'last_price'       => (float)($row[$hIdx['last_price']] ?? 0),
                'expiry'           => !empty($row[$hIdx['expiry']]) ? $row[$hIdx['expiry']] : null,
                'strike'           => isset($hIdx['strike']) ? (float)$row[$hIdx['strike']] : null,
                'tick_size'        => (float)($row[$hIdx['tick_size']]  ?? 0),
                'lot_size'         => (int)($row[$hIdx['lot_size']]     ?? 1),
                'instrument_type'  => $instrType,
                'segment'          => $row[$hIdx['segment']] ?? $exchange,
                'exchange'         => $exchange,
                'isin'             => isset($hIdx['isin']) ? ($row[$hIdx['isin']] ?? null) : null,
            ];

            if (count($batch) >= 500) {
                kz_batch_insert_instruments($batch);
                $inserted += count($batch);
                $batch     = [];
            }
        }

        if ($batch) {
            kz_batch_insert_instruments($batch);
            $inserted += count($batch);
        }

        DB::run("UPDATE zerodha_instruments SET refreshed_at=NOW() WHERE exchange=?", [$exchange]);

        json_response(true, "Instruments refreshed: {$inserted} rows.", [
            'inserted' => $inserted,
            'exchange' => $exchange,
        ]);


    // ─── GTT SYNC ─────────────────────────────────────────────────────────────
    case 'zerodha_gtt_sync':
        $auth = kz_auth($userId);
        if (!$auth) { json_response(false, 'Not connected or token expired.'); }

        $resp = kz_req('GET', '/gtt/triggers', [], $auth['api_key'], $auth['access_token']);
        if (!$resp || $resp['status'] !== 'success') {
            json_response(false, 'GTT fetch failed.');
        }

        $synced = 0;
        foreach ($resp['data'] as $g) {
            DB::run("
                INSERT INTO zerodha_gtt
                (user_id, gtt_id, status, type, tradingsymbol, exchange, trigger_values,
                 last_price, condition, orders, created_at, updated_at, expires_at, synced_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                  status=VALUES(status), trigger_values=VALUES(trigger_values),
                  last_price=VALUES(last_price), orders=VALUES(orders),
                  updated_at=VALUES(updated_at), synced_at=NOW()
            ", [
                $userId, $g['id'], $g['status'] ?? null, $g['type'] ?? null,
                $g['condition']['tradingsymbol'] ?? '', $g['condition']['exchange'] ?? null,
                json_encode($g['trigger_values'] ?? []),
                (float)($g['condition']['last_price'] ?? 0),
                json_encode($g['condition'] ?? []),
                json_encode($g['orders']    ?? []),
                !empty($g['created_at']) ? date('Y-m-d H:i:s', strtotime($g['created_at'])) : null,
                !empty($g['updated_at']) ? date('Y-m-d H:i:s', strtotime($g['updated_at'])) : null,
                !empty($g['expires_at']) ? date('Y-m-d H:i:s', strtotime($g['expires_at'])) : null,
            ]);
            $synced++;
        }

        json_response(true, "GTT synced: {$synced}.", ['synced' => $synced]);


    // ─── FULL SYNC (holdings + positions + orders + margins + quotes) ─────────
    case 'zerodha_full_sync':
        $auth = kz_auth($userId);
        if (!$auth) { json_response(false, 'Not connected or token expired.'); }

        $results = [];
        $errors  = [];

        // Holdings
        $hResp = kz_req('GET', '/portfolio/holdings', [], $auth['api_key'], $auth['access_token']);
        if ($hResp && $hResp['status'] === 'success') {
            $count = count($hResp['data'] ?? []);
            foreach ($hResp['data'] ?? [] as $h) {
                DB::run("
                    INSERT INTO zerodha_holdings_raw
                    (user_id, tradingsymbol, exchange, isin, quantity, t1_quantity,
                     average_price, last_price, pnl, product, raw_json, synced_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE
                      quantity=VALUES(quantity), t1_quantity=VALUES(t1_quantity),
                      average_price=VALUES(average_price), last_price=VALUES(last_price),
                      pnl=VALUES(pnl), raw_json=VALUES(raw_json), synced_at=NOW()
                ", [
                    $userId, $h['tradingsymbol'], $h['exchange'] ?? 'NSE',
                    $h['isin'] ?? null, (int)($h['quantity'] ?? 0),
                    (int)($h['t1_quantity'] ?? 0), (float)($h['average_price'] ?? 0),
                    (float)($h['last_price'] ?? 0), (float)($h['pnl'] ?? 0),
                    $h['product'] ?? null, json_encode($h),
                ]);
            }
            $results['holdings'] = $count;
        } else {
            $errors[] = 'Holdings: ' . ($hResp['message'] ?? 'failed');
        }

        // Positions
        $pResp = kz_req('GET', '/portfolio/positions', [], $auth['api_key'], $auth['access_token']);
        if ($pResp && $pResp['status'] === 'success') {
            $posCount = 0;
            foreach (['day','net'] as $pType) {
                foreach ($pResp['data'][$pType] ?? [] as $p) {
                    DB::run("
                        INSERT INTO zerodha_positions_raw
                        (user_id,position_type,tradingsymbol,exchange,product,quantity,
                         average_price,last_price,pnl,realised,unrealised,m2m,raw_json,synced_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                        ON DUPLICATE KEY UPDATE
                          quantity=VALUES(quantity),last_price=VALUES(last_price),
                          pnl=VALUES(pnl),m2m=VALUES(m2m),synced_at=NOW()
                    ", [
                        $userId, $pType, $p['tradingsymbol'], $p['exchange'] ?? 'NSE',
                        $p['product'] ?? null, (int)($p['quantity'] ?? 0),
                        (float)($p['average_price'] ?? 0), (float)($p['last_price'] ?? 0),
                        (float)($p['pnl'] ?? 0), (float)($p['realised'] ?? 0),
                        (float)($p['unrealised'] ?? 0), (float)($p['m2m'] ?? 0),
                        json_encode($p),
                    ]);
                    $posCount++;
                }
            }
            $results['positions'] = $posCount;
        } else {
            $errors[] = 'Positions: ' . ($pResp['message'] ?? 'failed');
        }

        // Margins
        $mResp = kz_req('GET', '/user/margins', [], $auth['api_key'], $auth['access_token']);
        if ($mResp && $mResp['status'] === 'success') {
            foreach ($mResp['data'] as $seg => $m) {
                DB::run("INSERT INTO zerodha_margins (user_id,segment,net,available,used,raw_json,fetched_at)
                         VALUES (?,?,?,?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE net=VALUES(net),available=VALUES(available),used=VALUES(used),fetched_at=NOW()",
                    [$userId, $seg, (float)($m['net'] ?? 0),
                     (float)($m['available']['live_balance'] ?? 0),
                     (float)($m['utilised']['debits'] ?? 0), json_encode($m)]);
            }
            $results['margins'] = count($mResp['data']);
        }

        $status = empty($errors) ? 'success' : 'partial';
        DB::run("UPDATE zerodha_credentials SET last_sync_at=NOW(), last_sync_status=? WHERE user_id=?",
            [$status, $userId]);

        json_response($status !== 'failed', 'Full sync ' . $status . '.', [
            'results' => $results,
            'errors'  => $errors,
        ]);


    default:
        json_response(false, "Unknown Kite action: {$action}", [], 400);
}

// ── Batch insert helper ────────────────────────────────────────────────────────
function kz_batch_insert_instruments(array $batch): void
{
    if (empty($batch)) return;
    $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'));
    $params = [];
    foreach ($batch as $b) {
        array_push($params,
            $b['instrument_token'], $b['exchange_token'], $b['tradingsymbol'], $b['name'],
            $b['last_price'], $b['expiry'], $b['strike'], $b['tick_size'], $b['lot_size'],
            $b['instrument_type'], $b['segment'], $b['exchange'], $b['isin']
        );
    }
    DB::run("
        INSERT INTO zerodha_instruments
        (instrument_token, exchange_token, tradingsymbol, name, last_price, expiry, strike,
         tick_size, lot_size, instrument_type, segment, exchange, isin, refreshed_at)
        VALUES {$placeholders}
        ON DUPLICATE KEY UPDATE
          name=VALUES(name), last_price=VALUES(last_price), refreshed_at=VALUES(refreshed_at)
    ", $params);
}
