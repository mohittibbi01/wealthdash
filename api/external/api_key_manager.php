<?php
/**
 * WealthDash — External API Key Manager [t335]
 * File: api/external/api_key_manager.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
require_auth();

$actingUser   = $_SESSION['user_id'] ? (int)$_SESSION['user_id'] : 0;
$isAdmin      = is_admin();

// Available scopes
const API_SCOPES = [
    'portfolio:read'      => 'Read portfolio summary & net worth',
    'mf:read'             => 'Read mutual fund holdings & NAV',
    'stocks:read'         => 'Read stock holdings & prices',
    'fd:read'             => 'Read FD accounts & interest',
    'savings:read'        => 'Read savings accounts',
    'banks:read'          => 'Read bank accounts & balances',
    'nps:read'            => 'Read NPS holdings',
    'tax:read'            => 'Read tax summary & LTCG/STCG',
    'goals:read'          => 'Read investment goals',
    'networth:read'       => 'Read net worth history',
];

// ── Helpers ──────────────────────────────────────────────────────────────────
function gen_api_key(): array {
    $raw    = 'wdx_' . bin2hex(random_bytes(20));   // wdx_ + 40 hex chars
    $prefix = substr($raw, 0, 12);                   // wdx_abcd1234
    $hash   = password_hash($raw, PASSWORD_BCRYPT);
    return ['raw' => $raw, 'prefix' => $prefix, 'hash' => $hash];
}

function verify_scope_list(array $scopes): array {
    return array_values(array_intersect($scopes, array_keys(API_SCOPES)));
}

function get_key_or_fail(int $id, int $userId, bool $adminOk = true): array {
    $where  = $adminOk && is_admin() ? 'WHERE id = ?' : 'WHERE id = ? AND user_id = ?';
    $params = $adminOk && is_admin() ? [$id] : [$id, $userId];
    $row    = DB::fetchOne("SELECT * FROM external_api_keys $where", $params);
    if (!$row) json_response(false, 'API key not found.', [], 404);
    return $row;
}

function rate_check(int $keyId, int $limit): bool {
    $window = date('Y-m-d H:i:00');
    DB::run(
        'INSERT INTO external_api_rate (key_id, window, hits) VALUES (?,?,1)
         ON DUPLICATE KEY UPDATE hits = hits + 1',
        [$keyId, $window]
    );
    $hits = (int) DB::fetchVal(
        'SELECT hits FROM external_api_rate WHERE key_id=? AND window=?',
        [$keyId, $window]
    );
    return $hits <= $limit;
}

// ── Routing ──────────────────────────────────────────────────────────────────
switch ($action) {

    // ── LIST KEYS ─────────────────────────────────────────────────────────────
    case 'extapi_list': {
        $where  = $isAdmin ? 'WHERE 1=1' : 'WHERE eak.user_id = ?';
        $params = $isAdmin ? [] : [$actingUser];

        $rows = DB::fetchAll(
            "SELECT eak.*,
                    u.name  AS owner_name,
                    u.email AS owner_email,
                    (SELECT COUNT(*) FROM external_api_log eal WHERE eal.key_id = eak.id) AS log_count
             FROM external_api_keys eak
             JOIN users u ON u.id = eak.user_id
             $where
             ORDER BY eak.created_at DESC",
            $params
        );

        // Parse scopes JSON
        foreach ($rows as &$r) {
            $r['scopes']   = json_decode($r['scopes'], true) ?? [];
            $r['key_hash'] = '[hidden]';
        }
        unset($r);

        json_response(true, '', ['keys' => $rows, 'available_scopes' => API_SCOPES]);
    }

    // ── CREATE KEY ────────────────────────────────────────────────────────────
    case 'extapi_create': {
        csrf_verify();

        $name       = clean($_POST['name'] ?? '');
        $scopesRaw  = $_POST['scopes'] ?? [];
        $rateLimit  = max(1, min((int)($_POST['rate_limit'] ?? 60), 600));
        $expiresAt  = ($_POST['expires_at'] ?? '') !== '' ? date_to_db($_POST['expires_at']) : null;

        if (!$name) json_response(false, 'Key name is required.');
        if (!is_array($scopesRaw) || empty($scopesRaw)) json_response(false, 'At least one scope is required.');

        $scopes = verify_scope_list($scopesRaw);
        if (empty($scopes)) json_response(false, 'No valid scopes selected.');

        // Max 10 keys per user
        $existing = (int) DB::fetchVal('SELECT COUNT(*) FROM external_api_keys WHERE user_id=? AND is_active=1', [$actingUser]);
        if (!$isAdmin && $existing >= 10) json_response(false, 'Maximum 10 active API keys allowed per user.');

        $key = gen_api_key();

        $id = DB::insert(
            'INSERT INTO external_api_keys (user_id, name, key_prefix, key_hash, scopes, rate_limit, expires_at)
             VALUES (?,?,?,?,?,?,?)',
            [$actingUser, $name, $key['prefix'], $key['hash'],
             json_encode($scopes), $rateLimit, $expiresAt]
        );

        // Return raw key ONCE — not stored in DB again
        json_response(true, 'API key created. Copy it now — it will not be shown again.', [
            'id'         => (int)$id,
            'key'        => $key['raw'],   // shown once
            'key_prefix' => $key['prefix'],
            'name'       => $name,
            'scopes'     => $scopes,
        ]);
    }

    // ── TOGGLE ACTIVE ─────────────────────────────────────────────────────────
    case 'extapi_toggle': {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        $row = get_key_or_fail($id, $actingUser);
        $new = $row['is_active'] ? 0 : 1;
        DB::run('UPDATE external_api_keys SET is_active=? WHERE id=?', [$new, $id]);
        json_response(true, 'Key ' . ($new ? 'activated' : 'deactivated') . '.', ['is_active' => $new]);
    }

    // ── DELETE KEY ────────────────────────────────────────────────────────────
    case 'extapi_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        get_key_or_fail($id, $actingUser);
        DB::run('DELETE FROM external_api_keys WHERE id=?', [$id]);
        json_response(true, 'API key deleted.');
    }

    // ── REGENERATE KEY ────────────────────────────────────────────────────────
    case 'extapi_regenerate': {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        get_key_or_fail($id, $actingUser);
        $key = gen_api_key();
        DB::run(
            'UPDATE external_api_keys SET key_prefix=?, key_hash=?, use_count=0, last_used_at=NULL WHERE id=?',
            [$key['prefix'], $key['hash'], $id]
        );
        json_response(true, 'Key regenerated. Copy it now.', [
            'key'        => $key['raw'],
            'key_prefix' => $key['prefix'],
        ]);
    }

    // ── USAGE LOG ─────────────────────────────────────────────────────────────
    case 'extapi_log': {
        $keyId  = (int)($_GET['key_id'] ?? 0);
        $limit  = min((int)($_GET['limit'] ?? 100), 500);
        $where  = 'WHERE eal.key_id = ?';
        $params = [$keyId];

        // Verify ownership
        if ($keyId) get_key_or_fail($keyId, $actingUser);

        $rows = DB::fetchAll(
            "SELECT eal.* FROM external_api_log eal $where ORDER BY eal.created_at DESC LIMIT ?",
            array_merge($params, [$limit])
        );

        $stats = DB::fetchOne(
            "SELECT COUNT(*) as total,
                    SUM(status>=400) as errors,
                    ROUND(AVG(duration_ms),1) as avg_ms,
                    MAX(created_at) as last_call
             FROM external_api_log eal $where",
            $params
        );

        json_response(true, '', ['log' => $rows, 'stats' => $stats]);
    }

    // ── SCOPES LIST ───────────────────────────────────────────────────────────
    case 'extapi_scopes': {
        json_response(true, '', ['scopes' => API_SCOPES]);
    }

    // ── VERIFY KEY (internal — called by external REST gateway) ───────────────
    case 'extapi_verify': {
        // This is called internally by api/external/rest_gateway.php
        $rawKey = clean($_POST['api_key'] ?? '');
        if (!$rawKey || !str_starts_with($rawKey, 'wdx_')) {
            json_response(false, 'Invalid API key format.', [], 401);
        }

        $prefix = substr($rawKey, 0, 12);
        $row    = DB::fetchOne(
            'SELECT * FROM external_api_keys WHERE key_prefix=? AND is_active=1',
            [$prefix]
        );

        if (!$row || !password_verify($rawKey, $row['key_hash'])) {
            json_response(false, 'Invalid or inactive API key.', [], 401);
        }
        if ($row['expires_at'] && $row['expires_at'] < date('Y-m-d')) {
            json_response(false, 'API key has expired.', [], 401);
        }
        if (!rate_check((int)$row['id'], (int)$row['rate_limit'])) {
            json_response(false, 'Rate limit exceeded.', [], 429);
        }

        // Update last used
        DB::run(
            'UPDATE external_api_keys SET last_used_at=NOW(), last_ip=?, use_count=use_count+1 WHERE id=?',
            [$_SERVER['REMOTE_ADDR'] ?? null, $row['id']]
        );

        $row['scopes'] = json_decode($row['scopes'], true) ?? [];
        unset($row['key_hash']);
        json_response(true, '', ['key' => $row]);
    }

    default:
        json_response(false, "Unknown action: {$action}", [], 400);
}
