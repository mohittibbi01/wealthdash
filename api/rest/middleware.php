<?php
/**
 * WealthDash REST API — Auth Middleware
 * Task: t504 · Worker: ID-M
 *
 * Include at top of every REST endpoint file.
 * Validates Bearer token, enforces rate limit, returns $apiUser + $apiKey.
 */
defined('WEALTHDASH') or die();

/**
 * Authenticate a REST API request via Bearer token.
 * Sets $apiUser (users row) and $apiKey (api_keys row) in caller scope.
 * Terminates with 401/429 on failure.
 */
function rest_auth(string $requiredScope = 'portfolio:read'): array {
    $t0 = microtime(true);

    // Extract Bearer token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader && function_exists('apache_request_headers')) {
        $headers    = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!str_starts_with($authHeader, 'Bearer ')) {
        _rest_error(401, 'Missing or invalid Authorization header. Use: Bearer <api_key>');
    }

    $rawKey    = substr($authHeader, 7);
    $hashedKey = hash('sha256', $rawKey);

    // Look up key
    $keyRow = DB::fetchOne(
        "SELECT k.*, u.id AS uid, u.name, u.email, u.role
         FROM api_keys k
         JOIN users u ON u.id = k.user_id
         WHERE k.api_key = ? AND k.is_active = 1",
        [$hashedKey]
    );

    if (!$keyRow) {
        _rest_error(401, 'Invalid or inactive API key.');
    }

    // Expiry check
    if ($keyRow['expires_at'] && strtotime($keyRow['expires_at']) < time()) {
        _rest_error(401, 'API key has expired.');
    }

    // Scope check
    $allowedScopes = array_map('trim', explode(',', $keyRow['scopes']));
    $hasScope      = in_array($requiredScope, $allowedScopes, true)
                  || in_array('all:read', $allowedScopes, true);
    if (!$hasScope) {
        _rest_error(403, "Scope '{$requiredScope}' not allowed for this API key.");
    }

    // Rate limit (per-minute sliding window)
    _rest_check_rate_limit((int)$keyRow['id'], (int)$keyRow['rate_limit']);

    // Update last_used
    DB::run(
        "UPDATE api_keys SET last_used_at=NOW(), last_ip=? WHERE id=?",
        [$_SERVER['REMOTE_ADDR'] ?? null, $keyRow['id']]
    );

    return ['key' => $keyRow, 'user_id' => (int)$keyRow['uid'], 'timer_start' => $t0];
}

/**
 * Log a REST request (call at end of each endpoint).
 */
function rest_log(array $ctx, int $statusCode, string $endpoint): void {
    $ms = (int)round((microtime(true) - $ctx['timer_start']) * 1000);
    DB::run(
        "INSERT INTO api_request_log (api_key_id, user_id, method, endpoint, status_code, response_ms, ip_address)
         VALUES (?,?,?,?,?,?,?)",
        [
            (int)$ctx['key']['id'],
            $ctx['user_id'],
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $endpoint,
            $statusCode,
            $ms,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}

/**
 * Send a REST JSON error and exit.
 */
function _rest_error(int $code, string $message): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => $message, 'code' => $code]);
    exit;
}

/**
 * REST JSON success response helper.
 */
function rest_response(array $data, int $code = 200, array $meta = []): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    $payload = ['success' => true, 'data' => $data];
    if ($meta) $payload['meta'] = $meta;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Rate-limit check using per-minute window.
 */
function _rest_check_rate_limit(int $keyId, int $limit): void {
    $window = date('Y-m-d H:i:00');  // current minute window

    // Upsert counter
    DB::run(
        "INSERT INTO api_rate_limit (api_key_id, window_start, request_count)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE request_count = request_count + 1",
        [$keyId, $window]
    );

    $count = (int)DB::fetchVal(
        "SELECT request_count FROM api_rate_limit WHERE api_key_id=? AND window_start=?",
        [$keyId, $window]
    );

    if ($count > $limit) {
        header('Retry-After: 60');
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: 0');
        _rest_error(429, "Rate limit exceeded. Max {$limit} requests/minute.");
    }

    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $limit - $count));

    // Purge old windows (housekeeping, ~1% of requests)
    if (random_int(1, 100) === 1) {
        DB::run("DELETE FROM api_rate_limit WHERE window_start < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    }
}
