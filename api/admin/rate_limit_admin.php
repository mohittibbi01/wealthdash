<?php
/**
 * WealthDash — t310: Rate Limiter Admin API
 * Provides stats, flush, and per-bucket reset for the admin UI.
 *
 * Actions:
 *   admin_rl_stats         — GET: live bucket stats + summary
 *   admin_rl_flush         — POST: flush all rate limit buckets
 *   admin_rl_reset_bucket  — POST: reset a single bucket by key
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
if (empty($currentUser['is_admin'])) {
    json_response(false, 'Admin access required.', [], 403);
}

$db     = DB::conn();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Ensure table exists (RateLimit class might not be loaded here)
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS rate_limit_buckets (
            bucket_key   VARCHAR(180) NOT NULL PRIMARY KEY,
            requests     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            window_start INT UNSIGNED NOT NULL,
            INDEX idx_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── admin_rl_stats ────────────────────────────────────────────────────────
if ($action === 'admin_rl_stats') {
    $now = time();

    // Clean old buckets first (> 2h)
    $db->exec("DELETE FROM rate_limit_buckets WHERE window_start < " . ($now - 7200));

    // All active buckets (current window only — last 1h)
    $buckets = $db->query(
        "SELECT bucket_key, requests, window_start
         FROM rate_limit_buckets
         WHERE window_start >= " . ($now - 3600) . "
         ORDER BY requests DESC
         LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Summary stats
    $totalBuckets  = count($buckets);
    $totalRequests = array_sum(array_column($buckets, 'requests'));

    // Limits reference for blocked calculation
    $limits = [
        'login'               => [5,   300],
        'register'            => [3,   600],
        '2fa_verify_login'    => [5,   300],
        '2fa_setup'           => [3,   600],
        'send_otp'            => [3,   300],
        'ai_chat'             => [20,  3600],
        'ai_portfolio_review' => [5,   3600],
        'ai_tax_optimize'     => [5,   3600],
        'ai_anomaly'          => [10,  3600],
        'ai_goal_advice'      => [10,  3600],
        'add_transaction'     => [60,  60],
        'bulk_import'         => [5,   300],
        'csv_import'          => [5,   300],
        'admin_db_truncate'   => [3,   600],
        'nav_fetch'           => [200, 60],
        'crypto_prices'       => [100, 60],
        'stock_price'         => [200, 60],
        '_default'            => [120, 60],
    ];

    $blockedCount = 0;
    foreach ($buckets as $b) {
        $parts  = explode(':', $b['bucket_key']);
        $action2= implode(':', array_slice($parts, 2));
        $max    = $limits[$action2][0] ?? $limits['_default'][0];
        if ((int)$b['requests'] >= $max) $blockedCount++;
    }

    // Top action by requests
    $actionGroups = [];
    foreach ($buckets as $b) {
        $parts  = explode(':', $b['bucket_key']);
        $a      = implode(':', array_slice($parts, 2)) ?: '_default';
        $actionGroups[$a] = ($actionGroups[$a] ?? 0) + (int)$b['requests'];
    }
    arsort($actionGroups);
    $topAction = array_key_first($actionGroups) ?? '—';

    json_response(true, 'Rate limit stats.', [
        'buckets'         => $buckets,
        'total_buckets'   => $totalBuckets,
        'total_requests'  => $totalRequests,
        'blocked_count'   => $blockedCount,
        'top_action'      => $topAction,
        'generated_at'    => date('Y-m-d H:i:s'),
    ]);
}

// ── admin_rl_flush ────────────────────────────────────────────────────────
elseif ($action === 'admin_rl_flush') {
    $stmt = $db->exec("DELETE FROM rate_limit_buckets");
    $deleted = is_int($stmt) ? $stmt : 0;
    // Log to audit if available
    try {
        $db->prepare(
            "INSERT INTO audit_log (user_id, action, details, ip_address, created_at)
             VALUES (?,?,?,?,NOW())"
        )->execute([
            $currentUser['id'],
            'admin_rl_flush',
            "Flushed $deleted rate limit buckets",
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {}
    json_response(true, "Flushed {$deleted} rate limit buckets.", ['deleted' => $deleted]);
}

// ── admin_rl_reset_bucket ─────────────────────────────────────────────────
elseif ($action === 'admin_rl_reset_bucket') {
    $key = trim($_POST['bucket_key'] ?? '');
    if (!$key) json_response(false, 'bucket_key required.');

    $stmt = $db->prepare("DELETE FROM rate_limit_buckets WHERE bucket_key = ?");
    $stmt->execute([$key]);
    $deleted = $stmt->rowCount();

    try {
        $db->prepare(
            "INSERT INTO audit_log (user_id, action, details, ip_address, created_at)
             VALUES (?,?,?,?,NOW())"
        )->execute([
            $currentUser['id'],
            'admin_rl_reset_bucket',
            "Reset bucket: $key",
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {}

    if ($deleted) {
        json_response(true, "Bucket reset.", ['key' => $key]);
    } else {
        json_response(false, 'Bucket not found (may have expired).', ['key' => $key]);
    }
}

else {
    json_response(false, 'Unknown rate limit admin action: ' . htmlspecialchars($action));
}
