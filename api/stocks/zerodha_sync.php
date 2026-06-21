<?php
/**
 * WealthDash — Zerodha Kite API Sync
 * Task   : t301
 * Routes : zerodha_connect, zerodha_callback, zerodha_sync,
 *          zerodha_holdings, zerodha_positions, zerodha_status,
 *          zerodha_disconnect, zerodha_import
 *
 * Requires Kite Connect PHP client or raw HTTP calls to api.kite.trade
 * Docs: https://kite.trade/docs/connect/v3/
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action  = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId  = (int) $_SESSION['user_id'];
$isAdmin = is_admin();

// ── Constants ─────────────────────────────────────────────────────────────────
define('KITE_BASE', 'https://api.kite.trade');
define('KITE_LOGIN', 'https://kite.zerodha.com/connect/login');

// ── Helpers ───────────────────────────────────────────────────────────────────

function kite_cred(int $userId): ?array
{
    $row = DB::fetchOne(
        "SELECT * FROM zerodha_credentials WHERE user_id=? AND is_active=1",
        [$userId]
    );
    return $row ?: null;
}

function kite_decrypt(string $val): string
{
    // In production: use openssl_decrypt with APP_KEY
    // Fallback: base64 if encryption not configured
    if (defined('ENCRYPT_KEY') && ENCRYPT_KEY) {
        $decoded = base64_decode($val);
        $iv      = substr($decoded, 0, 16);
        $cipher  = substr($decoded, 16);
        return openssl_decrypt($cipher, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv) ?: $val;
    }
    return base64_decode($val) ?: $val;
}

function kite_encrypt(string $val): string
{
    if (defined('ENCRYPT_KEY') && ENCRYPT_KEY) {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($val, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
        return base64_encode($iv . $cipher);
    }
    return base64_encode($val);
}

/**
 * Make authenticated request to Kite API.
 * Returns decoded JSON body or null on error.
 */
function kite_request(string $method, string $endpoint, array $params, string $apiKey, string $accessToken): ?array
{
    $url = KITE_BASE . $endpoint;
    $ch  = curl_init();

    $headers = [
        'X-Kite-Version: 3',
        'Authorization: token ' . $apiKey . ':' . $accessToken,
        'Content-Type: application/x-www-form-urlencoded',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_URL,        $url);
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } else {
        $qs = $params ? '?' . http_build_query($params) : '';
        curl_setopt($ch, CURLOPT_URL, $url . $qs);
    }

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body) return null;
    $decoded = json_decode($body, true);
    return $decoded ?: null;
}

/**
 * Generate Kite session access token from request_token.
 * SHA256(api_key + request_token + api_secret)
 */
function kite_generate_access_token(string $apiKey, string $apiSecret, string $requestToken): ?array
{
    $checksum = hash('sha256', $apiKey . $requestToken . $apiSecret);
    $ch = curl_init(KITE_BASE . '/session/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'api_key'       => $apiKey,
            'request_token' => $requestToken,
            'checksum'      => $checksum,
        ]),
        CURLOPT_HTTPHEADER     => ['X-Kite-Version: 3'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;
    $data = json_decode($body, true);
    return ($data && $data['status'] === 'success') ? $data['data'] : null;
}

// ── Log sync ──────────────────────────────────────────────────────────────────
function kite_log_sync(int $userId, int $portfolioId, array $stats, string $trigger = 'manual'): void
{
    DB::run(
        "INSERT INTO zerodha_sync_log
         (user_id, portfolio_id, triggered_by, holdings_fetched, holdings_added,
          holdings_updated, positions_fetched, status, error_message, api_calls_made, duration_ms)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        [$userId, $portfolioId, $trigger,
         $stats['holdings_fetched'] ?? 0,
         $stats['holdings_added']   ?? 0,
         $stats['holdings_updated'] ?? 0,
         $stats['positions_fetched']?? 0,
         $stats['status']           ?? 'success',
         $stats['error']            ?? null,
         $stats['api_calls']        ?? 0,
         $stats['duration_ms']      ?? null]
    );
}

// ── Routes ────────────────────────────────────────────────────────────────────

switch ($action) {

    // ─── STATUS — Check if user has Kite connected ────────────────────────────
    case 'zerodha_status':
        $cred = kite_cred($userId);
        if (!$cred) {
            json_response(true, '', ['connected' => false]);
        }

        $tokenValid = $cred['token_expiry'] && strtotime($cred['token_expiry']) > time();
        json_response(true, '', [
            'connected'       => true,
            'kite_user_id'    => $cred['kite_user_id'],
            'kite_user_name'  => $cred['kite_user_name'],
            'token_valid'     => $tokenValid,
            'token_expiry'    => $cred['token_expiry'],
            'last_sync_at'    => $cred['last_sync_at'],
            'last_sync_status'=> $cred['last_sync_status'],
        ]);


    // ─── CONNECT — Save API key/secret, return Kite login URL ────────────────
    case 'zerodha_connect':
        $apiKey    = trim($_POST['api_key']    ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        if (!$apiKey || !$apiSecret) {
            json_response(false, 'API key and secret are required.');
        }

        // Upsert credentials (encrypted)
        $existingCred = DB::fetchOne("SELECT id FROM zerodha_credentials WHERE user_id=?", [$userId]);
        if ($existingCred) {
            DB::run(
                "UPDATE zerodha_credentials
                 SET api_key=?, api_secret=?, access_token=NULL, token_expiry=NULL,
                     is_active=1, updated_at=NOW()
                 WHERE user_id=?",
                [kite_encrypt($apiKey), kite_encrypt($apiSecret), $userId]
            );
        } else {
            DB::run(
                "INSERT INTO zerodha_credentials (user_id, api_key, api_secret) VALUES (?,?,?)",
                [$userId, kite_encrypt($apiKey), kite_encrypt($apiSecret)]
            );
        }

        // Return Kite OAuth URL
        $redirectUri = APP_URL . '/api/index.php?action=zerodha_callback';
        $loginUrl    = KITE_LOGIN . '?api_key=' . urlencode($apiKey) . '&v=3';

        json_response(true, 'Credentials saved. Redirect user to Kite login.', [
            'login_url'    => $loginUrl,
            'redirect_uri' => $redirectUri,
        ]);


    // ─── CALLBACK — Exchange request_token for access_token ──────────────────
    case 'zerodha_callback':
        $requestToken = clean($_GET['request_token'] ?? '');
        $status       = clean($_GET['status']        ?? '');
        if ($status !== 'success' || !$requestToken) {
            json_response(false, 'Kite login failed or cancelled.');
        }

        $cred = kite_cred($userId);
        if (!$cred) { json_response(false, 'No Zerodha credentials found. Connect first.'); }

        $apiKey    = kite_decrypt($cred['api_key']);
        $apiSecret = kite_decrypt($cred['api_secret']);

        $session = kite_generate_access_token($apiKey, $apiSecret, $requestToken);
        if (!$session || empty($session['access_token'])) {
            json_response(false, 'Failed to generate access token. Check your API key/secret.');
        }

        // Token expires next day at 6am IST
        $expiry = date('Y-m-d 00:30:00', strtotime('+1 day')); // 6am IST = 00:30 UTC

        DB::run(
            "UPDATE zerodha_credentials
             SET access_token=?, request_token=?, token_expiry=?,
                 kite_user_id=?, kite_user_name=?, login_time=NOW(), updated_at=NOW()
             WHERE user_id=?",
            [
                kite_encrypt($session['access_token']),
                $requestToken,
                $expiry,
                $session['user_id']   ?? null,
                $session['user_name'] ?? null,
                $userId,
            ]
        );

        json_response(true, 'Zerodha connected successfully.', [
            'kite_user_id'  => $session['user_id']   ?? null,
            'kite_user_name'=> $session['user_name'] ?? null,
            'token_expiry'  => $expiry,
        ]);


    // ─── SYNC — Fetch holdings + positions from Kite and upsert ──────────────
    case 'zerodha_sync':
        $startTime   = microtime(true);
        $pid         = (int)($_POST['portfolio_id'] ?? 0);
        if (!$pid) $pid = (int)(get_user_portfolio_id($userId) ?? 0);
        if (!$pid)   { json_response(false, 'No portfolio found.'); }

        $cred = kite_cred($userId);
        if (!$cred)  { json_response(false, 'Zerodha not connected.'); }

        $tokenExpiry = $cred['token_expiry'] ? strtotime($cred['token_expiry']) : 0;
        if ($tokenExpiry < time()) {
            json_response(false, 'Access token expired. Please reconnect Zerodha.');
        }

        $apiKey     = kite_decrypt($cred['api_key']);
        $accessToken= kite_decrypt($cred['access_token']);

        $stats     = ['api_calls' => 0, 'status' => 'success'];
        $errors    = [];

        // ── Fetch Holdings ─────────────────────────────────────────────────
        $hResp = kite_request('GET', '/portfolio/holdings', [], $apiKey, $accessToken);
        $stats['api_calls']++;

        $holdingsAdded   = 0;
        $holdingsUpdated = 0;
        $holdingsFetched = 0;

        if ($hResp && $hResp['status'] === 'success' && !empty($hResp['data'])) {
            $holdings = $hResp['data'];
            $holdingsFetched = count($holdings);

            foreach ($holdings as $h) {
                $symbol    = $h['tradingsymbol'] ?? '';
                $exchange  = $h['exchange']      ?? 'NSE';
                $isin      = $h['isin']          ?? null;
                $existing  = DB::fetchOne(
                    "SELECT id FROM zerodha_holdings_raw WHERE user_id=? AND tradingsymbol=? AND exchange=?",
                    [$userId, $symbol, $exchange]
                );

                $fields = [
                    'isin'             => $isin,
                    'quantity'         => (int)($h['quantity']          ?? 0),
                    't1_quantity'      => (int)($h['t1_quantity']       ?? 0),
                    'average_price'    => (float)($h['average_price']   ?? 0),
                    'last_price'       => isset($h['last_price'])    ? (float)$h['last_price']    : null,
                    'close_price'      => isset($h['close_price'])   ? (float)$h['close_price']   : null,
                    'pnl'              => isset($h['pnl'])            ? (float)$h['pnl']           : null,
                    'day_change'       => isset($h['day_change'])     ? (float)$h['day_change']    : null,
                    'day_change_pct'   => isset($h['day_change_percentage']) ? (float)$h['day_change_percentage'] : null,
                    'product'          => $h['product']              ?? null,
                    'collateral_qty'   => (int)($h['collateral_quantity'] ?? 0),
                    'collateral_type'  => $h['collateral_type']      ?? null,
                    'used_quantity'    => (int)($h['used_quantity']   ?? 0),
                    'realised_quantity'=> (int)($h['realised_quantity']?? 0),
                    'raw_json'         => json_encode($h),
                    'synced_at'        => date('Y-m-d H:i:s'),
                ];

                if ($existing) {
                    $sets   = [];
                    $params = [];
                    foreach ($fields as $col => $val) {
                        $sets[]   = "`{$col}` = ?";
                        $params[] = $val;
                    }
                    $params[] = $existing['id'];
                    DB::run("UPDATE zerodha_holdings_raw SET " . implode(', ', $sets) . " WHERE id=?", $params);
                    $holdingsUpdated++;
                } else {
                    $fields['user_id']       = $userId;
                    $fields['tradingsymbol'] = $symbol;
                    $fields['exchange']      = $exchange;
                    $cols   = array_keys($fields);
                    $placeholders = implode(',', array_fill(0, count($cols), '?'));
                    DB::run(
                        "INSERT INTO zerodha_holdings_raw (" . implode(',', $cols) . ") VALUES ({$placeholders})",
                        array_values($fields)
                    );
                    $holdingsAdded++;
                }
            }
        } else {
            $errors[] = 'Holdings fetch failed: ' . ($hResp['message'] ?? 'Unknown error');
        }

        // ── Fetch Positions ────────────────────────────────────────────────
        $pResp = kite_request('GET', '/portfolio/positions', [], $apiKey, $accessToken);
        $stats['api_calls']++;

        $positionsFetched = 0;
        if ($pResp && $pResp['status'] === 'success' && !empty($pResp['data'])) {
            foreach (['day', 'net'] as $posType) {
                $positions = $pResp['data'][$posType] ?? [];
                foreach ($positions as $p) {
                    $positionsFetched++;
                    $sym      = $p['tradingsymbol'] ?? '';
                    $exchange = $p['exchange']      ?? 'NSE';
                    $product  = $p['product']       ?? '';

                    DB::run(
                        "INSERT INTO zerodha_positions_raw
                         (user_id, position_type, tradingsymbol, exchange, product,
                          quantity, overnight_quantity, buy_quantity, sell_quantity,
                          average_price, last_price, pnl, realised, unrealised, m2m, raw_json, synced_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE
                          quantity=VALUES(quantity), overnight_quantity=VALUES(overnight_quantity),
                          buy_quantity=VALUES(buy_quantity), sell_quantity=VALUES(sell_quantity),
                          average_price=VALUES(average_price), last_price=VALUES(last_price),
                          pnl=VALUES(pnl), realised=VALUES(realised), unrealised=VALUES(unrealised),
                          m2m=VALUES(m2m), raw_json=VALUES(raw_json), synced_at=NOW()",
                        [$userId, $posType, $sym, $exchange, $product,
                         (int)($p['quantity'] ?? 0),
                         (int)($p['overnight_quantity'] ?? 0),
                         (int)($p['buy_quantity']   ?? 0),
                         (int)($p['sell_quantity']  ?? 0),
                         (float)($p['average_price']?? 0),
                         isset($p['last_price'])   ? (float)$p['last_price']  : null,
                         isset($p['pnl'])          ? (float)$p['pnl']        : null,
                         isset($p['realised'])     ? (float)$p['realised']   : null,
                         isset($p['unrealised'])   ? (float)$p['unrealised'] : null,
                         isset($p['m2m'])          ? (float)$p['m2m']        : null,
                         json_encode($p)]
                    );
                }
            }
        }

        $durationMs = (int)(( microtime(true) - $startTime ) * 1000);
        $syncStatus = empty($errors) ? 'success' : ($holdingsFetched > 0 ? 'partial' : 'failed');

        // Update credentials sync time
        DB::run(
            "UPDATE zerodha_credentials
             SET last_sync_at=NOW(), last_sync_status=?, last_sync_error=? WHERE user_id=?",
            [$syncStatus, empty($errors) ? null : implode('; ', $errors), $userId]
        );

        kite_log_sync($userId, $pid, [
            'holdings_fetched'  => $holdingsFetched,
            'holdings_added'    => $holdingsAdded,
            'holdings_updated'  => $holdingsUpdated,
            'positions_fetched' => $positionsFetched,
            'status'            => $syncStatus,
            'error'             => empty($errors) ? null : implode('; ', $errors),
            'api_calls'         => $stats['api_calls'],
            'duration_ms'       => $durationMs,
        ]);

        json_response($syncStatus !== 'failed', 'Sync ' . $syncStatus . '.', [
            'holdings_fetched'  => $holdingsFetched,
            'holdings_added'    => $holdingsAdded,
            'holdings_updated'  => $holdingsUpdated,
            'positions_fetched' => $positionsFetched,
            'duration_ms'       => $durationMs,
            'errors'            => $errors,
        ]);


    // ─── HOLDINGS — Return synced holdings from DB ────────────────────────────
    case 'zerodha_holdings':
        $rows = DB::fetchAll(
            "SELECT h.*,
                    ROUND((h.last_price - h.average_price) * h.quantity, 2) AS unrealised_pnl,
                    ROUND(CASE WHEN h.average_price > 0
                               THEN ((h.last_price - h.average_price) / h.average_price) * 100
                               ELSE 0 END, 2) AS gain_pct,
                    ROUND(h.average_price * h.quantity, 2) AS invested_value,
                    ROUND(h.last_price    * h.quantity, 2) AS current_value
             FROM zerodha_holdings_raw h
             WHERE h.user_id=?
             ORDER BY ABS(h.pnl) DESC",
            [$userId]
        );

        $summary = DB::fetchOne(
            "SELECT
                COUNT(*)                                    AS total_holdings,
                COALESCE(SUM(average_price * quantity), 0) AS total_invested,
                COALESCE(SUM(last_price    * quantity), 0) AS total_current_value,
                COALESCE(SUM(pnl), 0)                      AS total_pnl
             FROM zerodha_holdings_raw WHERE user_id=?",
            [$userId]
        );

        json_response(true, '', ['data' => $rows, 'summary' => $summary]);


    // ─── POSITIONS — Return day/net positions ─────────────────────────────────
    case 'zerodha_positions':
        $posType = in_array($_GET['type'] ?? 'net', ['day','net']) ? ($_GET['type'] ?? 'net') : 'net';
        $rows = DB::fetchAll(
            "SELECT * FROM zerodha_positions_raw WHERE user_id=? AND position_type=? ORDER BY ABS(pnl) DESC",
            [$userId, $posType]
        );
        json_response(true, '', ['data' => $rows, 'type' => $posType]);


    // ─── IMPORT — Push Kite holdings into WealthDash stock_holdings table ─────
    case 'zerodha_import':
        $pid = (int)($_POST['portfolio_id'] ?? 0);
        if (!$pid) $pid = (int)(get_user_portfolio_id($userId) ?? 0);
        if (!$pid) { json_response(false, 'No portfolio found.'); }

        // Fetch from raw table
        $holdings = DB::fetchAll(
            "SELECT * FROM zerodha_holdings_raw WHERE user_id=? AND quantity > 0",
            [$userId]
        );

        if (empty($holdings)) {
            json_response(false, 'No holdings to import. Run sync first.');
        }

        $imported  = 0;
        $skipped   = 0;
        $errors    = [];

        foreach ($holdings as $h) {
            $symbol   = $h['tradingsymbol'];
            $exchange = $h['exchange'];
            $qty      = (int)$h['quantity'];
            $avgPrice = (float)$h['average_price'];

            // Look up stock_master
            $sm = DB::fetchOne(
                "SELECT id FROM stock_master WHERE symbol=? AND exchange=? LIMIT 1",
                [$symbol, $exchange]
            );

            if (!$sm) {
                // Try inserting a minimal stock_master record
                try {
                    DB::run(
                        "INSERT IGNORE INTO stock_master (symbol, exchange, company_name, isin, latest_price, created_at)
                         VALUES (?,?,?,?,?,NOW())",
                        [$symbol, $exchange, $symbol, $h['isin'] ?? null, $h['last_price'] ?? $avgPrice]
                    );
                    $smId = (int) DB::conn()->lastInsertId();
                    if (!$smId) {
                        $sm = DB::fetchOne("SELECT id FROM stock_master WHERE symbol=? AND exchange=?", [$symbol, $exchange]);
                        $smId = $sm ? (int)$sm['id'] : 0;
                    }
                } catch (Exception $e) {
                    $errors[] = "Cannot resolve stock_master for {$symbol}";
                    $skipped++;
                    continue;
                }
            } else {
                $smId = (int)$sm['id'];
            }

            // Check if holding already exists
            $existing = DB::fetchOne(
                "SELECT id FROM stock_holdings WHERE portfolio_id=? AND stock_master_id=?",
                [$pid, $smId]
            );

            if ($existing) {
                // Update quantity and avg price
                DB::run(
                    "UPDATE stock_holdings SET quantity=?, average_price=?, updated_at=NOW() WHERE id=?",
                    [$qty, $avgPrice, $existing['id']]
                );
            } else {
                DB::run(
                    "INSERT INTO stock_holdings
                     (portfolio_id, stock_master_id, quantity, average_price, buy_date, source, created_at)
                     VALUES (?,?,?,?,CURDATE(),'zerodha_import',NOW())",
                    [$pid, $smId, $qty, $avgPrice]
                );
            }
            $imported++;
        }

        json_response(true, "Import complete: {$imported} holdings imported, {$skipped} skipped.", [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);


    // ─── DISCONNECT ───────────────────────────────────────────────────────────
    case 'zerodha_disconnect':
        DB::run(
            "UPDATE zerodha_credentials
             SET is_active=0, access_token=NULL, token_expiry=NULL WHERE user_id=?",
            [$userId]
        );
        json_response(true, 'Zerodha disconnected.');


    default:
        json_response(false, "Unknown Zerodha action: {$action}", [], 400);
}
