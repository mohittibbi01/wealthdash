<?php
/**
 * WealthDash — API Key Management
 * Task: t504
 * Worker: ID-M
 *
 * Actions (via router.php):
 *   api_keys_list    — list user's API keys (masked)
 *   api_key_create   — generate a new API key
 *   api_key_delete   — revoke/delete an API key
 *   api_key_toggle   — enable/disable without deleting
 *   api_usage_stats  — per-key request stats
 */
defined('WEALTHDASH') or die();

switch ($action) {

    // ── List keys (masked) ───────────────────────────────────────
    case 'api_keys_list':
        $keys = DB::fetchAll(
            "SELECT id, key_name, key_prefix, scopes, rate_limit,
                    last_used_at, last_ip, is_active, expires_at, created_at
             FROM api_keys WHERE user_id=? ORDER BY created_at DESC",
            [$userId]
        );
        json_response(true, '', ['keys' => $keys]);

    // ── Create a new API key ─────────────────────────────────────
    case 'api_key_create':
        $keyName   = clean($_POST['key_name'] ?? '');
        $scopes    = clean($_POST['scopes'] ?? 'portfolio:read');
        $expiresAt = clean($_POST['expires_at'] ?? '');
        $rateLimit = min(1000, max(1, (int)($_POST['rate_limit'] ?? 60)));

        if (!$keyName) json_response(false, 'key_name is required.');

        $validScopes = ['portfolio:read','transactions:read','net_worth:read','holdings:read','all:read'];
        $requestedScopes = array_filter(
            array_map('trim', explode(',', $scopes)),
            fn($s) => in_array($s, $validScopes)
        );
        if (empty($requestedScopes)) {
            json_response(false, 'No valid scopes provided. Valid: ' . implode(', ', $validScopes));
        }

        // Check limit: max 10 keys per user
        $existingCount = (int)DB::fetchVal("SELECT COUNT(*) FROM api_keys WHERE user_id=? AND is_active=1", [$userId]);
        if ($existingCount >= 10) {
            json_response(false, 'Maximum 10 active API keys allowed. Delete or disable existing keys first.');
        }

        $rawKey    = bin2hex(random_bytes(32));  // 64-char hex
        $hashedKey = hash('sha256', $rawKey);
        $prefix    = substr($rawKey, 0, 8);
        $scopeStr  = implode(',', $requestedScopes);

        DB::run(
            "INSERT INTO api_keys (user_id, key_name, api_key, key_prefix, scopes, rate_limit, expires_at)
             VALUES (?,?,?,?,?,?,?)",
            [
                $userId, $keyName, $hashedKey, $prefix, $scopeStr, $rateLimit,
                $expiresAt ?: null,
            ]
        );
        $keyId = (int)DB::conn()->lastInsertId();
        audit_log('api_key_create', 'api_keys', $keyId, [], ['name' => $keyName, 'scopes' => $scopeStr]);

        // Return raw key ONCE — never stored in plaintext
        json_response(true, 'API key created. Save it now — it will NOT be shown again.', [
            'id'         => $keyId,
            'api_key'    => $rawKey,   // full key — shown only here
            'key_prefix' => $prefix,
            'scopes'     => $scopeStr,
            'rate_limit' => $rateLimit,
            'expires_at' => $expiresAt ?: null,
        ]);

    // ── Delete a key ─────────────────────────────────────────────
    case 'api_key_delete':
        $keyId = (int)($_POST['key_id'] ?? 0);
        if (!$keyId) json_response(false, 'key_id required.');
        $key = DB::fetchOne("SELECT * FROM api_keys WHERE id=? AND user_id=?", [$keyId, $userId]);
        if (!$key) json_response(false, 'Key not found.');
        DB::run("DELETE FROM api_keys WHERE id=?", [$keyId]);
        audit_log('api_key_delete', 'api_keys', $keyId, $key, []);
        json_response(true, 'API key deleted.');

    // ── Toggle active ─────────────────────────────────────────────
    case 'api_key_toggle':
        $keyId = (int)($_POST['key_id'] ?? 0);
        if (!$keyId) json_response(false, 'key_id required.');
        $key = DB::fetchOne("SELECT * FROM api_keys WHERE id=? AND user_id=?", [$keyId, $userId]);
        if (!$key) json_response(false, 'Key not found.');
        DB::run("UPDATE api_keys SET is_active = NOT is_active WHERE id=?", [$keyId]);
        json_response(true, 'API key ' . ($key['is_active'] ? 'disabled' : 'enabled') . '.');

    // ── Usage stats ───────────────────────────────────────────────
    case 'api_usage_stats':
        $keyId = (int)($_POST['key_id'] ?? 0);
        if ($keyId) {
            // Verify ownership
            $key = DB::fetchOne("SELECT id FROM api_keys WHERE id=? AND user_id=?", [$keyId, $userId]);
            if (!$key) json_response(false, 'Key not found.');
            $stats = DB::fetchAll(
                "SELECT DATE(requested_at) AS day, COUNT(*) AS requests, AVG(response_ms) AS avg_ms
                 FROM api_request_log
                 WHERE api_key_id=? AND requested_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY DATE(requested_at)
                 ORDER BY day DESC",
                [$keyId]
            );
            $totals = DB::fetchOne(
                "SELECT COUNT(*) AS total, AVG(response_ms) AS avg_ms,
                        SUM(status_code >= 400) AS errors
                 FROM api_request_log WHERE api_key_id=?",
                [$keyId]
            );
        } else {
            $stats  = [];
            $totals = DB::fetchOne(
                "SELECT COUNT(*) AS total, AVG(response_ms) AS avg_ms,
                        SUM(status_code >= 400) AS errors
                 FROM api_request_log
                 WHERE user_id=?",
                [$userId]
            );
        }
        json_response(true, '', ['daily' => $stats, 'totals' => $totals]);

    default:
        json_response(false, 'Unknown action.', [], 400);
}
