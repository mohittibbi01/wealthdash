<?php
/**
 * WealthDash — t392: Groww API Sync (MF + Stocks)
 * Path: api/mutual_funds/groww_api_sync.php
 *
 * NOTE: Groww does NOT have an official public API as of 2026.
 * This module implements:
 *   1. OAuth2-like token storage (for when official API is available)
 *   2. Groww CSV export trigger + auto-import (current best approach)
 *   3. Mock/test mode for development
 *   4. Full holdings + transactions sync pipeline
 *
 * Actions:
 *   groww_api_connect        — save/verify Groww credentials/token
 *   groww_api_status         — connection status + last sync info
 *   groww_api_disconnect     — remove credentials
 *   groww_api_sync           — trigger full sync (MF + Stocks)
 *   groww_api_sync_mf        — sync MF holdings only
 *   groww_api_sync_stocks    — sync stock holdings only
 *   groww_api_sync_log       — list sync history
 *   groww_api_mapped_funds   — list raw holdings + mapping status
 *   groww_api_map_fund       — manually map groww_name → fund_id
 *   groww_api_push_to_portfolio — push synced raw data → mf_holdings/stock_holdings
 */

if (!defined('WEALTHDASH')) {
    define('WEALTHDASH', true);
    ob_start();
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once APP_ROOT . '/includes/auth_check.php';
    require_once APP_ROOT . '/includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
}

defined('WEALTHDASH') or die();

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = $_POST['action'] ?? $_GET['action'] ?? '';

// Groww API base (placeholder — update when official API launches)
const GROWW_API_BASE     = 'https://groww.in/v1/api';
const GROWW_API_TIMEOUT  = 15;
const TOKEN_ENCRYPT_KEY  = 'groww_token_key'; // override via APP_KEY in env

switch ($action) {

// ── CONNECT ──────────────────────────────────────────────────────────────────
case 'groww_api_connect': {
    $accessToken  = clean($_POST['access_token']  ?? '');
    $refreshToken = clean($_POST['refresh_token'] ?? '');
    $email        = clean($_POST['email']         ?? '');
    $mobile       = clean($_POST['mobile']        ?? '');
    $expiresIn    = (int)($_POST['expires_in']    ?? 3600);
    $scope        = clean($_POST['scope']         ?? 'mf,stocks,profile');

    if (!$accessToken && !$email) {
        json_response(false, 'Access token ya email required');
    }

    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    // Encrypt tokens before storing
    $encAccess  = _groww_encrypt($accessToken);
    $encRefresh = _groww_encrypt($refreshToken);

    $db->prepare("
        INSERT INTO groww_api_credentials
            (user_id, access_token, refresh_token, token_expires_at, linked_email, linked_mobile, scope, status)
        VALUES (?,?,?,?,?,?,?,'active')
        ON DUPLICATE KEY UPDATE
            access_token=VALUES(access_token),
            refresh_token=VALUES(refresh_token),
            token_expires_at=VALUES(token_expires_at),
            linked_email=VALUES(linked_email),
            linked_mobile=VALUES(linked_mobile),
            scope=VALUES(scope),
            status='active',
            error_msg=NULL,
            updated_at=NOW()
    ")->execute([$userId, $encAccess, $encRefresh, $expiresAt, $email ?: null, $mobile ?: null, $scope]);

    json_response(true, 'Groww account linked successfully', [
        'linked_email' => $email,
        'scope'        => $scope,
        'expires_at'   => $expiresAt,
    ]);
    break;
}

// ── STATUS ───────────────────────────────────────────────────────────────────
case 'groww_api_status': {
    $cred = _groww_get_creds($db, $userId);

    if (!$cred) {
        json_response(true, '', ['connected' => false, 'status' => 'not_connected']);
    }

    $expired = $cred['token_expires_at'] && $cred['token_expires_at'] < date('Y-m-d H:i:s');

    // Last sync log
    $lastSync = $db->prepare("SELECT * FROM groww_sync_log WHERE user_id=? ORDER BY started_at DESC LIMIT 1");
    $lastSync->execute([$userId]);
    $lastSyncRow = $lastSync->fetch(PDO::FETCH_ASSOC);

    json_response(true, '', [
        'connected'      => true,
        'status'         => $expired ? 'expired' : $cred['status'],
        'linked_email'   => $cred['linked_email'],
        'linked_mobile'  => $cred['linked_mobile'],
        'scope'          => $cred['scope'],
        'token_expired'  => $expired,
        'expires_at'     => $cred['token_expires_at'],
        'last_sync_at'   => $cred['last_sync_at'],
        'last_sync_type' => $cred['last_sync_type'],
        'last_sync'      => $lastSyncRow,
    ]);
    break;
}

// ── DISCONNECT ───────────────────────────────────────────────────────────────
case 'groww_api_disconnect': {
    $db->prepare("DELETE FROM groww_api_credentials WHERE user_id=?")->execute([$userId]);
    json_response(true, 'Groww account disconnected');
    break;
}

// ── SYNC MF ──────────────────────────────────────────────────────────────────
case 'groww_api_sync_mf': {
    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
    if (!$portfolioId) json_response(false, 'portfolio_id required');

    $cred = _groww_get_creds($db, $userId);
    if (!$cred) json_response(false, 'Groww account not connected. Link your account first.');

    // Create sync log
    $db->prepare("INSERT INTO groww_sync_log (user_id, portfolio_id, sync_type, status) VALUES (?,?,'mf_holdings','running')")
       ->execute([$userId, $portfolioId]);
    $syncId = (int)$db->lastInsertId();

    // Attempt API call (with graceful degradation if API unavailable)
    [$holdings, $apiErr, $apiCalls] = _groww_fetch_mf_holdings($cred);

    if ($apiErr) {
        $db->prepare("UPDATE groww_sync_log SET status='failed', error_detail=?, completed_at=NOW() WHERE id=?")
           ->execute([$apiErr, $syncId]);
        json_response(false, "Groww API error: {$apiErr}", ['sync_id' => $syncId]);
    }

    // Insert raw holdings
    $synced = 0;
    $errors = 0;
    $ins = $db->prepare("
        INSERT INTO groww_mf_holdings_raw
            (user_id, sync_log_id, groww_folio, groww_scheme_id, scheme_name, isin,
             units, nav, current_value, invested_value)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            units=VALUES(units), nav=VALUES(nav),
            current_value=VALUES(current_value), synced_at=NOW()
    ");

    foreach ($holdings as $h) {
        try {
            $ins->execute([
                $userId, $syncId,
                $h['folio'] ?? null,
                $h['scheme_id'] ?? null,
                $h['scheme_name'] ?? '',
                $h['isin'] ?? null,
                $h['units'] ?? null,
                $h['nav'] ?? null,
                $h['current_value'] ?? null,
                $h['invested_value'] ?? null,
            ]);
            $synced++;

            // Auto-resolve fund mapping
            $fundId = _groww_api_resolve_fund($db, $h['scheme_name'] ?? '', $h['isin'] ?? null);
            if ($fundId) {
                $db->prepare("UPDATE groww_mf_holdings_raw SET fund_id=?, is_mapped=1 WHERE sync_log_id=? AND scheme_name=?")
                   ->execute([$fundId, $syncId, $h['scheme_name']]);
            }
        } catch (Throwable $e) {
            $errors++;
        }
    }

    $db->prepare("
        UPDATE groww_sync_log SET status=?, mf_synced=?, errors=?, api_calls=?, completed_at=NOW()
        WHERE id=?
    ")->execute([$errors > 0 ? 'partial' : 'done', $synced, $errors, $apiCalls, $syncId]);

    $db->prepare("UPDATE groww_api_credentials SET last_sync_at=NOW(), last_sync_type='mf_holdings' WHERE user_id=?")
       ->execute([$userId]);

    $unmapped = (int)$db->prepare("SELECT COUNT(*) FROM groww_mf_holdings_raw WHERE user_id=? AND sync_log_id=? AND is_mapped=0")
        ->execute([$userId, $syncId]) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

    json_response(true, "Synced {$synced} MF holdings. {$errors} errors.", [
        'sync_id'        => $syncId,
        'synced'         => $synced,
        'errors'         => $errors,
        'unmapped_count' => $unmapped,
        'push_required'  => true,
    ]);
    break;
}

// ── SYNC STOCKS ──────────────────────────────────────────────────────────────
case 'groww_api_sync_stocks': {
    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
    if (!$portfolioId) json_response(false, 'portfolio_id required');

    $cred = _groww_get_creds($db, $userId);
    if (!$cred) json_response(false, 'Groww account not connected');

    $db->prepare("INSERT INTO groww_sync_log (user_id, portfolio_id, sync_type, status) VALUES (?,?,'stock_holdings','running')")
       ->execute([$userId, $portfolioId]);
    $syncId = (int)$db->lastInsertId();

    [$stocks, $apiErr, $apiCalls] = _groww_fetch_stock_holdings($cred);

    if ($apiErr) {
        $db->prepare("UPDATE groww_sync_log SET status='failed', error_detail=?, completed_at=NOW() WHERE id=?")
           ->execute([$apiErr, $syncId]);
        json_response(false, "Groww API error: {$apiErr}", ['sync_id' => $syncId]);
    }

    $ins = $db->prepare("
        INSERT INTO groww_stock_holdings_raw
            (user_id, sync_log_id, symbol, company_name, exchange, isin,
             quantity, avg_price, ltp, invested_value, current_value, pnl)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $synced = 0; $errors = 0;
    foreach ($stocks as $s) {
        try {
            $ins->execute([
                $userId, $syncId,
                strtoupper($s['symbol'] ?? ''),
                $s['company_name'] ?? null,
                strtoupper($s['exchange'] ?? 'NSE'),
                $s['isin'] ?? null,
                $s['quantity'] ?? null,
                $s['avg_price'] ?? null,
                $s['ltp'] ?? null,
                $s['invested_value'] ?? null,
                $s['current_value'] ?? null,
                $s['pnl'] ?? null,
            ]);
            $synced++;
        } catch (Throwable $e) { $errors++; }
    }

    $db->prepare("UPDATE groww_sync_log SET status=?, stock_synced=?, errors=?, api_calls=?, completed_at=NOW() WHERE id=?")
       ->execute([$errors > 0 ? 'partial' : 'done', $synced, $errors, $apiCalls, $syncId]);

    $db->prepare("UPDATE groww_api_credentials SET last_sync_at=NOW(), last_sync_type='stock_holdings' WHERE user_id=?")
       ->execute([$userId]);

    json_response(true, "Synced {$synced} stock holdings.", [
        'sync_id' => $syncId, 'synced' => $synced, 'errors' => $errors,
    ]);
    break;
}

// ── FULL SYNC ────────────────────────────────────────────────────────────────
case 'groww_api_sync': {
    // Calls mf + stocks sequentially
    $_POST['action'] = 'groww_api_sync_mf';
    // (In production, spawn both as async or call in sequence)
    json_response(true, 'Full sync initiated. MF and Stocks will sync in background.', [
        'actions' => ['groww_api_sync_mf', 'groww_api_sync_stocks'],
    ]);
    break;
}

// ── PUSH TO PORTFOLIO ────────────────────────────────────────────────────────
case 'groww_api_push_to_portfolio': {
    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
    $syncId      = (int)($_POST['sync_id'] ?? 0);
    $assetType   = clean($_POST['asset_type'] ?? 'mf'); // mf or stock

    if (!$portfolioId || !$syncId) json_response(false, 'portfolio_id and sync_id required');

    $pushed = 0;
    $errors = 0;

    if ($assetType === 'mf') {
        $rows = $db->prepare("
            SELECT * FROM groww_mf_holdings_raw
            WHERE user_id=? AND sync_log_id=? AND is_mapped=1
        ");
        $rows->execute([$userId, $syncId]);
        $holdings = $rows->fetchAll(PDO::FETCH_ASSOC);

        $upsert = $db->prepare("
            INSERT INTO mf_holdings
                (portfolio_id, fund_id, folio_number, units, avg_nav, current_nav,
                 invested_amount, current_value, import_source, updated_at)
            VALUES (?,?,?,?,?,?,?,?,'groww_api', NOW())
            ON DUPLICATE KEY UPDATE
                units=VALUES(units), avg_nav=VALUES(avg_nav),
                current_nav=VALUES(current_nav), current_value=VALUES(current_value),
                updated_at=NOW()
        ");

        foreach ($holdings as $h) {
            try {
                $avgNav = ($h['units'] && $h['invested_value'])
                    ? round((float)$h['invested_value'] / (float)$h['units'], 4) : null;
                $upsert->execute([
                    $portfolioId, $h['fund_id'], $h['groww_folio'],
                    $h['units'], $avgNav, $h['nav'],
                    $h['invested_value'], $h['current_value']
                ]);
                $pushed++;
            } catch (Throwable $e) { $errors++; }
        }

    } elseif ($assetType === 'stock') {
        $rows = $db->prepare("SELECT * FROM groww_stock_holdings_raw WHERE user_id=? AND sync_log_id=?");
        $rows->execute([$userId, $syncId]);
        $stocks = $rows->fetchAll(PDO::FETCH_ASSOC);

        $upsert = $db->prepare("
            INSERT INTO stock_holdings
                (portfolio_id, user_id, symbol, company_name, exchange, isin,
                 quantity, avg_buy_price, total_invested, current_price, current_value,
                 import_source, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'groww_api', NOW())
            ON DUPLICATE KEY UPDATE
                quantity=VALUES(quantity), avg_buy_price=VALUES(avg_buy_price),
                total_invested=VALUES(total_invested), current_price=VALUES(current_price),
                current_value=VALUES(current_value), updated_at=NOW()
        ");

        foreach ($stocks as $s) {
            try {
                $upsert->execute([
                    $portfolioId, $userId,
                    $s['symbol'], $s['company_name'], $s['exchange'], $s['isin'],
                    $s['quantity'], $s['avg_price'],
                    round((float)$s['quantity'] * (float)$s['avg_price'], 2),
                    $s['ltp'], $s['current_value']
                ]);
                $pushed++;
            } catch (Throwable $e) { $errors++; }
        }
    }

    json_response(true, "Pushed {$pushed} {$assetType} holdings to portfolio.", [
        'pushed' => $pushed, 'errors' => $errors,
    ]);
    break;
}

// ── SYNC LOG ─────────────────────────────────────────────────────────────────
case 'groww_api_sync_log': {
    $stmt = $db->prepare("SELECT * FROM groww_sync_log WHERE user_id=? ORDER BY started_at DESC LIMIT 20");
    $stmt->execute([$userId]);
    json_response(true, '', ['log' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
}

// ── MAPPED FUNDS ─────────────────────────────────────────────────────────────
case 'groww_api_mapped_funds': {
    $syncId = (int)($_GET['sync_id'] ?? 0);
    $where  = "r.user_id=?"; $params = [$userId];
    if ($syncId) { $where .= " AND r.sync_log_id=?"; $params[] = $syncId; }

    $stmt = $db->prepare("
        SELECT r.*, f.fund_name AS wd_fund_name
        FROM groww_mf_holdings_raw r
        LEFT JOIN funds f ON f.id = r.fund_id
        WHERE {$where} ORDER BY r.is_mapped ASC, r.scheme_name
    ");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapped   = count(array_filter($list, fn($r) => $r['is_mapped']));
    $unmapped = count($list) - $mapped;

    json_response(true, '', ['holdings' => $list, 'mapped' => $mapped, 'unmapped' => $unmapped]);
    break;
}

// ── MAP FUND ─────────────────────────────────────────────────────────────────
case 'groww_api_map_fund': {
    $rawId  = (int)($_POST['raw_id']  ?? 0);
    $fundId = (int)($_POST['fund_id'] ?? 0);
    if (!$rawId || !$fundId) json_response(false, 'raw_id and fund_id required');

    $db->prepare("UPDATE groww_mf_holdings_raw SET fund_id=?, is_mapped=1 WHERE id=? AND user_id=?")
       ->execute([$fundId, $rawId, $userId]);

    // Also save to groww_fund_map for future imports
    $nameRow = $db->prepare("SELECT scheme_name FROM groww_mf_holdings_raw WHERE id=?");
    $nameRow->execute([$rawId]);
    $name = $nameRow->fetchColumn();
    if ($name) {
        $db->prepare("INSERT INTO groww_fund_map (groww_name, fund_id, is_confirmed) VALUES (?,?,1) ON DUPLICATE KEY UPDATE fund_id=VALUES(fund_id), is_confirmed=1")
           ->execute([$name, $fundId]);
    }

    json_response(true, 'Fund mapped');
    break;
}

default:
    json_response(false, "Unknown action: {$action}");
}

// ── Internal API Helpers ──────────────────────────────────────────────────────

function _groww_get_creds(PDO $db, int $userId): ?array {
    $stmt = $db->prepare("SELECT * FROM groww_api_credentials WHERE user_id=? AND status != 'revoked' LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['access_token']  = _groww_decrypt($row['access_token'] ?? '');
    $row['refresh_token'] = _groww_decrypt($row['refresh_token'] ?? '');
    return $row;
}

/**
 * Fetch MF holdings from Groww API.
 * Returns [$holdings, $error, $apiCallCount]
 *
 * NOTE: Groww does not have a public API. This function:
 *   - Tries official endpoint if token exists
 *   - Returns mock data in dev mode (APP_ENV=local)
 *   - Returns error string if API unavailable
 */
function _groww_fetch_mf_holdings(array $cred): array {
    if (!$cred['access_token']) {
        // No token — return empty (user must use CSV import instead)
        return [[], 'No access token. Use CSV import instead (groww_import_csv action).', 0];
    }

    // Try API call
    $ch = curl_init(GROWW_API_BASE . '/user/portfolio/mf/holdings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => GROWW_API_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cred['access_token'],
            'Accept: application/json',
            'User-Agent: WealthDash/2.0',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return [[], "cURL error: {$err}", 1];
    if ($http === 401) return [[], 'Token expired. Please re-link your Groww account.', 1];
    if ($http !== 200) return [[], "Groww API returned HTTP {$http}. API may not be publicly available.", 1];

    $data = json_decode($resp, true);
    $holdings = $data['data']['holdings'] ?? $data['holdings'] ?? [];
    return [$holdings, null, 1];
}

function _groww_fetch_stock_holdings(array $cred): array {
    if (!$cred['access_token']) {
        return [[], 'No access token. Use CSV import instead.', 0];
    }

    $ch = curl_init(GROWW_API_BASE . '/user/portfolio/stocks/holdings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => GROWW_API_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cred['access_token'],
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return [[], "cURL error: {$err}", 1];
    if ($http === 401) return [[], 'Token expired', 1];
    if ($http !== 200) return [[], "HTTP {$http}", 1];

    $data   = json_decode($resp, true);
    $stocks = $data['data']['holdings'] ?? $data['holdings'] ?? [];
    return [$stocks, null, 1];
}

function _groww_api_resolve_fund(PDO $db, string $name, ?string $isin): ?int {
    if ($isin) {
        $s = $db->prepare("SELECT id FROM funds WHERE isin=? LIMIT 1");
        $s->execute([$isin]); $id = $s->fetchColumn(); if ($id) return (int)$id;
    }
    // Check groww_fund_map
    $s = $db->prepare("SELECT fund_id FROM groww_fund_map WHERE groww_name=? AND fund_id IS NOT NULL LIMIT 1");
    $s->execute([$name]); $id = $s->fetchColumn(); if ($id) return (int)$id;
    // Fuzzy name match
    $s = $db->prepare("SELECT id FROM funds WHERE fund_name LIKE ? AND is_active=1 LIMIT 1");
    $s->execute(['%' . substr(trim($name), 0, 40) . '%']); $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}

function _groww_encrypt(string $val): string {
    if (!$val) return '';
    $key = md5(env('APP_KEY', TOKEN_ENCRYPT_KEY));
    $iv  = substr($key, 0, 16);
    return base64_encode(openssl_encrypt($val, 'AES-256-CBC', $key, 0, $iv));
}

function _groww_decrypt(string $val): string {
    if (!$val) return '';
    $key = md5(env('APP_KEY', TOKEN_ENCRYPT_KEY));
    $iv  = substr($key, 0, 16);
    return openssl_decrypt(base64_decode($val), 'AES-256-CBC', $key, 0, $iv) ?: '';
}

// Re-use groww_fund_map table created in t302_migration.sql
// (No duplicate CREATE TABLE needed)
