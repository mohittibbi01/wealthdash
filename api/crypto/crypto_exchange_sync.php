<?php
/**
 * WealthDash — tc005: Exchange Sync (Binance / WazirX API)
 * Stores encrypted API keys, pulls live trade history, syncs holdings.
 *
 * Actions (router.php must route these):
 *   POST exchange_keys_save     — add / update API key pair for an exchange
 *   GET  exchange_keys_list     — list saved exchanges for current user (keys masked)
 *   POST exchange_keys_delete   — remove a key pair
 *   POST exchange_sync_run      — trigger a live sync from exchange API
 *   GET  exchange_sync_log      — recent sync history
 *
 * Router cases (for Master merge):
 *   case 'exchange_keys_save':
 *   case 'exchange_keys_list':
 *   case 'exchange_keys_delete':
 *   case 'exchange_sync_run':
 *   case 'exchange_sync_log':
 *       require APP_ROOT . '/api/crypto/crypto_exchange_sync.php'; exit;
 *
 * Security:
 *   - API keys are AES-256-GCM encrypted at rest using APP_KEY from config.php.
 *   - Only the owning user can read/delete/sync their keys.
 *   - Binance: HMAC-SHA256 signed requests, read-only scope (no withdrawal).
 *   - WazirX:  HMAC-SHA256 signed requests.
 *
 * DB deps (tc005_migration.sql):
 *   crypto_exchange_keys, crypto_sync_log
 * Also writes:
 *   crypto_transactions (import_source='BINANCE'/'WAZIRX', import_batch=UUID)
 *   crypto_holdings     (upsert)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// Include t317 parsing/persist helpers (same file set, loaded once)
// If crypto_import.php is not already included, include it now (function-only parse)
if (!function_exists('t317_resolve_coin')) {
    require_once __DIR__ . '/crypto_import.php';
}

// ── Encryption helpers ─────────────────────────────────────────────────────────

/**
 * Derive a 32-byte key from APP_KEY constant (defined in config.php).
 */
function tc005_enc_key(): string {
    $appKey = defined('APP_KEY') ? APP_KEY : 'wealthdash-default-key-change-me!';
    return hash('sha256', $appKey, true);
}

function tc005_encrypt(string $plain): array {
    $key  = tc005_enc_key();
    $iv   = random_bytes(12);           // 96-bit IV for GCM
    $tag  = '';
    $enc  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($enc === false) throw new RuntimeException('Encryption failed');
    return [
        'enc' => base64_encode($enc),
        'iv'  => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
}

function tc005_decrypt(string $encB64, string $ivB64, string $tagB64): string {
    $key  = tc005_enc_key();
    $dec  = openssl_decrypt(
        base64_decode($encB64), 'aes-256-gcm',
        $key, OPENSSL_RAW_DATA,
        base64_decode($ivB64), base64_decode($tagB64)
    );
    if ($dec === false) throw new RuntimeException('Decryption failed — wrong key or tampered data');
    return $dec;
}

// ── Exchange API clients ───────────────────────────────────────────────────────

/**
 * Binance signed GET request.
 * Docs: https://binance-docs.github.io/apidocs/spot/en/
 */
function tc005_binance_request(string $apiKey, string $apiSecret, string $path, array $params = []): array {
    $params['timestamp']  = (int)(microtime(true) * 1000);
    $params['recvWindow'] = 5000;
    $query     = http_build_query($params);
    $signature = hash_hmac('sha256', $query, $apiSecret);
    $url       = 'https://api.binance.com' . $path . '?' . $query . '&signature=' . $signature;

    $ctx = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "X-MBX-APIKEY: {$apiKey}\r\nUser-Agent: WealthDash/1.0\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new RuntimeException('Binance API: no response');
    if (isset($data['code']) && $data['code'] < 0) {
        throw new RuntimeException('Binance error ' . $data['code'] . ': ' . ($data['msg'] ?? ''));
    }
    return $data;
}

/**
 * Fetch all Binance trades for every relevant pair.
 * Returns normalised rows (same shape as t317_parse_binance output).
 */
function tc005_binance_fetch_trades(string $apiKey, string $apiSecret): array {
    // Get all active trading pairs for the account
    $account = tc005_binance_request($apiKey, $apiSecret, '/api/v3/account');
    $balances = $account['balances'] ?? [];

    $symbols  = [];
    foreach ($balances as $b) {
        $asset = strtoupper($b['asset'] ?? '');
        $free  = (float)($b['free'] ?? 0);
        $locked= (float)($b['locked'] ?? 0);
        if (($free + $locked) > 0 && !in_array($asset, ['USDT','USDC','BUSD','BNB'], true)) {
            $symbols[] = $asset . 'USDT';
            $symbols[] = $asset . 'BTC';
            $symbols[] = $asset . 'BNB';
        }
    }
    // Always include BTC and ETH
    $symbols = array_unique(array_merge(['BTCUSDT','ETHUSDT','BNBUSDT'], $symbols));

    $allTrades = [];
    foreach ($symbols as $sym) {
        try {
            $trades = tc005_binance_request($apiKey, $apiSecret, '/api/v3/myTrades', [
                'symbol' => $sym,
                'limit'  => 500,
            ]);
            if (!is_array($trades)) continue;

            foreach ($trades as $t) {
                $side    = $t['isBuyer'] ? 'BUY' : 'SELL';
                $coin    = t317_resolve_coin($sym);
                $qty     = (float)($t['qty'] ?? 0);
                $price   = (float)($t['price'] ?? 0);
                $amtUsdt = round($qty * $price, 6);
                $fee     = (float)($t['commission'] ?? 0);
                $feeCur  = strtoupper($t['commissionAsset'] ?? 'BNB');
                $ts      = isset($t['time']) ? date('Y-m-d H:i:s', (int)($t['time'] / 1000)) : date('Y-m-d H:i:s');
                $extId   = 'BNX-' . ($t['id'] ?? md5(serialize($t)));

                $allTrades[] = [
                    'exchange'      => 'BINANCE',
                    'txn_type'      => $side,
                    'coin_id'       => $coin['coin_id'],
                    'coin_symbol'   => $coin['coin_symbol'],
                    'coin_name'     => $coin['coin_name'],
                    'quantity'      => $qty,
                    'price_usd'     => $price,
                    'price_inr'     => 0,    // resolved at persist time
                    'amount_inr'    => 0,
                    'fee_amount'    => $fee,
                    'fee_currency'  => $feeCur,
                    'trade_pair'    => $sym,
                    'tds_deducted'  => 0,
                    'txn_date'      => $ts,
                    'external_id'   => $extId,
                    'notes'         => "Binance sync: {$sym} {$side}",
                ];
            }
        } catch (Throwable $e) {
            // symbol not traded — skip silently
        }
        usleep(150000); // 150ms between calls — Binance rate limit
    }
    return $allTrades;
}

/**
 * WazirX signed GET request.
 * Docs: https://docs.wazirx.com/
 */
function tc005_wazirx_request(string $apiKey, string $apiSecret, string $path, array $params = []): array {
    $params['recvWindow'] = 10000;
    $params['timestamp']  = (int)(microtime(true) * 1000);
    $query     = http_build_query($params);
    $signature = hash_hmac('sha256', $query, $apiSecret);
    $url       = 'https://api.wazirx.com' . $path . '?' . $query . '&signature=' . $signature;

    $ctx = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "X-Api-Key: {$apiKey}\r\nUser-Agent: WealthDash/1.0\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) throw new RuntimeException('WazirX API: no response');
    if (isset($data['error'])) throw new RuntimeException('WazirX error: ' . $data['error']);
    return $data;
}

/**
 * Fetch WazirX trade history.
 */
function tc005_wazirx_fetch_trades(string $apiKey, string $apiSecret): array {
    // Get account funds to know which symbols to query
    $funds = tc005_wazirx_request($apiKey, $apiSecret, '/sapi/v1/funds');
    $assets = [];
    foreach ($funds as $f) {
        $asset = strtoupper($f['asset'] ?? '');
        $bal   = (float)($f['free'] ?? 0) + (float)($f['locked'] ?? 0);
        if ($bal > 0 && $asset !== 'INR') $assets[] = strtolower($asset) . 'inr';
    }
    $assets = array_unique(array_merge(['btcinr','ethinr','bnbinr','solinr'], $assets));

    $allTrades = [];
    foreach ($assets as $sym) {
        try {
            $trades = tc005_wazirx_request($apiKey, $apiSecret, '/sapi/v1/myTrades', [
                'symbol' => $sym,
                'limit'  => 500,
            ]);
            if (!is_array($trades)) continue;

            foreach ($trades as $t) {
                $side   = strtoupper($t['type'] ?? 'BUY');
                $typeMap = ['BUY' => 'BUY', 'SELL' => 'SELL'];
                $txnType = $typeMap[$side] ?? 'BUY';

                $baseSym = strtoupper(str_replace('inr', '', $sym));
                $coin    = t317_resolve_coin($baseSym);
                $qty     = (float)($t['qty'] ?? 0);
                $priceInr= (float)($t['price'] ?? 0);
                $amtInr  = round($qty * $priceInr, 2);
                $fee     = (float)($t['fee'] ?? 0);
                $tds     = (float)($t['tds'] ?? 0);
                $ts      = isset($t['time']) ? date('Y-m-d H:i:s', (int)($t['time'] / 1000)) : date('Y-m-d H:i:s');
                $extId   = 'WRX-' . ($t['id'] ?? md5(serialize($t)));

                $allTrades[] = [
                    'exchange'      => 'WAZIRX',
                    'txn_type'      => $txnType,
                    'coin_id'       => $coin['coin_id'],
                    'coin_symbol'   => $coin['coin_symbol'],
                    'coin_name'     => $coin['coin_name'],
                    'quantity'      => $qty,
                    'price_usd'     => 0,
                    'price_inr'     => $priceInr,
                    'amount_inr'    => $amtInr,
                    'fee_amount'    => $fee,
                    'fee_currency'  => 'INR',
                    'trade_pair'    => strtoupper($sym),
                    'tds_deducted'  => $tds,
                    'txn_date'      => $ts,
                    'external_id'   => $extId,
                    'notes'         => "WazirX sync: " . strtoupper($sym) . " {$txnType}",
                ];
            }
        } catch (Throwable $e) {
            // symbol not traded
        }
        usleep(200000); // 200ms between calls
    }
    return $allTrades;
}

// ── Action routing ─────────────────────────────────────────────────────────────

switch ($action) {

    // ── SAVE KEY ───────────────────────────────────────────────────────────────
    case 'exchange_keys_save': {
        $exchange  = strtoupper(clean($_POST['exchange'] ?? ''));
        $apiKey    = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');

        $allowed = ['BINANCE', 'WAZIRX'];
        if (!in_array($exchange, $allowed, true)) json_response(false, 'Exchange must be BINANCE or WAZIRX');
        if (strlen($apiKey) < 10)    json_response(false, 'API key too short');
        if (strlen($apiSecret) < 10) json_response(false, 'API secret too short');

        try {
            $encKey    = tc005_encrypt($apiKey);
            $encSecret = tc005_encrypt($apiSecret);
        } catch (Throwable $e) {
            json_response(false, 'Encryption error: ' . $e->getMessage());
        }

        // Combine IVs (key IV + secret IV stored separately per field)
        $stmt = $db->prepare(
            "INSERT INTO crypto_exchange_keys
             (user_id, exchange, api_key_enc, api_secret_enc, enc_iv, enc_tag, label, is_active)
             VALUES (?,?,?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE
               api_key_enc=VALUES(api_key_enc),
               api_secret_enc=VALUES(api_secret_enc),
               enc_iv=VALUES(enc_iv),
               enc_tag=VALUES(enc_tag),
               label=VALUES(label),
               is_active=1,
               updated_at=NOW()"
        );
        // Store: enc_iv = JSON of both IVs, enc_tag = JSON of both tags
        $ivJson  = json_encode(['k' => $encKey['iv'],  's' => $encSecret['iv']]);
        $tagJson = json_encode(['k' => $encKey['tag'], 's' => $encSecret['tag']]);
        $label   = clean($_POST['label'] ?? $exchange . ' account');
        $stmt->bind_param('issssss',
            $userId, $exchange,
            $encKey['enc'], $encSecret['enc'],
            $ivJson, $tagJson, $label
        );
        if (!$stmt->execute()) json_response(false, 'DB error: ' . $db->error);
        $stmt->close();

        json_response(true, "API key saved for {$exchange}.");
    }

    // ── LIST KEYS (masked) ────────────────────────────────────────────────────
    case 'exchange_keys_list': {
        $rows = $db->query(
            "SELECT id, exchange, label, is_active, last_synced, created_at
             FROM crypto_exchange_keys
             WHERE user_id = {$userId}
             ORDER BY exchange"
        );
        $keys = [];
        while ($r = $rows->fetch_assoc()) {
            $keys[] = [
                'id'          => $r['id'],
                'exchange'    => $r['exchange'],
                'label'       => $r['label'],
                'is_active'   => (bool)$r['is_active'],
                'last_synced' => $r['last_synced'],
                'created_at'  => $r['created_at'],
            ];
        }
        json_response(true, '', ['keys' => $keys]);
    }

    // ── DELETE KEY ────────────────────────────────────────────────────────────
    case 'exchange_keys_delete': {
        $keyId = (int)($_POST['key_id'] ?? 0);
        if (!$keyId) json_response(false, 'key_id required');

        $stmt = $db->prepare("DELETE FROM crypto_exchange_keys WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $keyId, $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if (!$affected) json_response(false, 'Key not found or not yours');
        json_response(true, 'Exchange key deleted.');
    }

    // ── SYNC RUN ──────────────────────────────────────────────────────────────
    case 'exchange_sync_run': {
        $exchange    = strtoupper(clean($_POST['exchange'] ?? ''));
        $portfolioId = (int)($_POST['portfolio_id'] ?? 0);

        if (!$portfolioId) json_response(false, 'portfolio_id required');

        // Verify portfolio ownership
        $pChk = $db->prepare("SELECT id FROM portfolios WHERE id=? AND user_id=? LIMIT 1");
        $pChk->bind_param('ii', $portfolioId, $userId);
        $pChk->execute();
        if (!$pChk->get_result()->fetch_assoc()) json_response(false, 'Portfolio not found');
        $pChk->close();

        // Load key
        $keyRow = $db->query(
            "SELECT api_key_enc, api_secret_enc, enc_iv, enc_tag
             FROM crypto_exchange_keys
             WHERE user_id={$userId} AND exchange='" . $db->escape_string($exchange) . "' AND is_active=1
             LIMIT 1"
        )->fetch_assoc();
        if (!$keyRow) json_response(false, "No active API key found for {$exchange}. Please add one first.");

        try {
            $ivs  = json_decode($keyRow['enc_iv'],  true);
            $tags = json_decode($keyRow['enc_tag'], true);
            $apiKey    = tc005_decrypt($keyRow['api_key_enc'],    $ivs['k'], $tags['k']);
            $apiSecret = tc005_decrypt($keyRow['api_secret_enc'], $ivs['s'], $tags['s']);
        } catch (Throwable $e) {
            json_response(false, 'Key decryption failed: ' . $e->getMessage());
        }

        // Fetch trades
        $status = 'OK';
        $errMsg = null;
        try {
            if ($exchange === 'BINANCE') {
                $trades = tc005_binance_fetch_trades($apiKey, $apiSecret);
            } elseif ($exchange === 'WAZIRX') {
                $trades = tc005_wazirx_fetch_trades($apiKey, $apiSecret);
            } else {
                json_response(false, "Unsupported exchange: {$exchange}");
            }
        } catch (Throwable $e) {
            $status = 'ERROR';
            $errMsg = $e->getMessage();
            $trades = [];
        }

        $fetched = count($trades);
        $result  = ['inserted' => 0, 'skipped' => 0];

        if ($fetched > 0) {
            $batchId  = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
            $usdToInr = t317_usd_to_inr($db);

            $db->begin_transaction();
            try {
                $result = t317_persist($db, $trades, $portfolioId, $userId, $batchId, $usdToInr);
                $db->commit();
                if ($result['skipped'] > 0 && $result['inserted'] === 0) $status = 'PARTIAL';
            } catch (Throwable $e) {
                $db->rollback();
                $status = 'ERROR';
                $errMsg = $e->getMessage();
            }
        }

        // Log sync
        $logStmt = $db->prepare(
            "INSERT INTO crypto_sync_log
             (user_id, portfolio_id, exchange, status, trades_fetched, trades_new, trades_skipped, error_msg)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $logStmt->bind_param('iissiiss',
            $userId, $portfolioId, $exchange, $status,
            $fetched, $result['inserted'], $result['skipped'], $errMsg
        );
        $logStmt->execute(); $logStmt->close();

        // Update last_synced
        $db->query("UPDATE crypto_exchange_keys SET last_synced=NOW() WHERE user_id={$userId} AND exchange='" . $db->escape_string($exchange) . "'");

        if ($status === 'ERROR') {
            json_response(false, "Sync failed: {$errMsg}", [
                'exchange' => $exchange,
                'fetched'  => $fetched,
            ]);
        }

        json_response(true, "Sync complete: {$result['inserted']} new trades, {$result['skipped']} already present.", [
            'exchange' => $exchange,
            'fetched'  => $fetched,
            'inserted' => $result['inserted'],
            'skipped'  => $result['skipped'],
            'status'   => $status,
        ]);
    }

    // ── SYNC LOG ──────────────────────────────────────────────────────────────
    case 'exchange_sync_log': {
        $rows = $db->query(
            "SELECT exchange, status, trades_fetched, trades_new, trades_skipped, error_msg, synced_at
             FROM crypto_sync_log
             WHERE user_id = {$userId}
             ORDER BY synced_at DESC
             LIMIT 50"
        );
        $log = [];
        while ($r = $rows->fetch_assoc()) $log[] = $r;
        json_response(true, '', ['log' => $log]);
    }

    default:
        json_response(false, 'Unknown action: ' . $action);
}
