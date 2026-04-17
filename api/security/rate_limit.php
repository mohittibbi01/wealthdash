<?php
/**
 * WealthDash — t390: API Rate Limiting
 * Protects all API endpoints from abuse using a token-bucket algorithm.
 * Include this file at the top of api/index.php or any API router.
 *
 * Usage: RateLimit::check('action_name');
 *        RateLimit::checkIp();
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

class RateLimit {

    /**
     * Per-user rate limits per action (requests per window)
     * Format: 'action' => [max_requests, window_seconds]
     */
    const LIMITS = [
        // Auth — strict
        'login'              => [5,   300],   // 5 attempts / 5 min
        'register'           => [3,   600],   // 3 attempts / 10 min
        '2fa_verify_login'   => [5,   300],
        '2fa_setup'          => [3,   600],
        'send_otp'           => [3,   300],

        // AI — expensive, throttle hard
        'ai_chat'            => [20,  3600],  // 20 AI calls / hr
        'ai_portfolio_review'=> [5,   3600],
        'ai_tax_optimize'    => [5,   3600],
        'ai_anomaly'         => [10,  3600],
        'ai_goal_advice'     => [10,  3600],

        // Data writes — moderate
        'add_transaction'    => [60,  60],    // 60/min
        'bulk_import'        => [5,   300],
        'csv_import'         => [5,   300],
        'admin_db_truncate'  => [3,   600],

        // API reads — generous
        'nav_fetch'          => [200, 60],
        'crypto_prices'      => [100, 60],
        'stock_price'        => [200, 60],

        // Default fallback
        '_default'           => [120, 60],    // 120 req/min per user
    ];

    /**
     * IP-level limits (before auth — for login page etc.)
     * Format: [max_requests, window_seconds]
     */
    const IP_LIMITS = [
        'login'    => [10, 300],  // 10 login attempts / 5 min per IP
        '_default' => [300, 60],  // 300 req/min per IP
    ];

    private static ?object $db = null;

    /** Ensure rate_limit_buckets table exists */
    static function ensureTable(): void {
        if (!self::$db) self::$db = DB::conn();
        try {
            self::$db->exec("
                CREATE TABLE IF NOT EXISTS rate_limit_buckets (
                    bucket_key   VARCHAR(180) NOT NULL PRIMARY KEY,
                    requests     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    window_start INT UNSIGNED NOT NULL,
                    INDEX idx_window (window_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) { /* already exists */ }
    }

    /**
     * Check rate limit for authenticated user + action.
     * Calls json_response(false, ..., 429) and exits on breach.
     */
    static function check(string $action, ?int $userId = null): void {
        self::ensureTable();

        if ($userId === null) $userId = (int) ($_SESSION['user_id'] ?? 0);
        if (!$userId) {
            self::checkIp($action);
            return;
        }

        [$max, $window] = self::LIMITS[$action] ?? self::LIMITS['_default'];
        $key   = 'u:' . $userId . ':' . $action;
        $now   = time();
        $start = (int) floor($now / $window) * $window;

        self::$db->exec("DELETE FROM rate_limit_buckets WHERE window_start < " . ($now - 3600));

        $row = self::$db->query(
            "SELECT requests, window_start FROM rate_limit_buckets WHERE bucket_key = " . self::$db->quote($key)
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['window_start'] < $start) {
            // New window
            self::$db->exec("
                INSERT INTO rate_limit_buckets (bucket_key, requests, window_start)
                VALUES (" . self::$db->quote($key) . ", 1, $start)
                ON DUPLICATE KEY UPDATE requests = 1, window_start = $start
            ");
            return;
        }

        if ($row['requests'] >= $max) {
            $retryAfter = ($row['window_start'] + $window) - $now;
            header('Retry-After: ' . max(1, $retryAfter));
            header('X-RateLimit-Limit: ' . $max);
            header('X-RateLimit-Remaining: 0');
            $mins = ceil($retryAfter / 60);
            json_response(false,
                "⚠️ Bahut zyada requests. {$mins} minute baad try karo.",
                ['retry_after' => $retryAfter],
                429
            );
        }

        $remaining = $max - $row['requests'] - 1;
        header('X-RateLimit-Limit: ' . $max);
        header('X-RateLimit-Remaining: ' . $remaining);

        self::$db->exec(
            "UPDATE rate_limit_buckets SET requests = requests + 1 WHERE bucket_key = " . self::$db->quote($key)
        );
    }

    /**
     * IP-level rate limit check (for login etc., no auth required)
     */
    static function checkIp(string $action = '_default'): void {
        self::ensureTable();

        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        [$max, $window] = self::IP_LIMITS[$action] ?? self::IP_LIMITS['_default'];
        $key   = 'ip:' . md5($ip) . ':' . $action;
        $now   = time();
        $start = (int) floor($now / $window) * $window;

        self::$db = self::$db ?? DB::conn();
        self::$db->exec("DELETE FROM rate_limit_buckets WHERE window_start < " . ($now - 3600));

        $row = self::$db->query(
            "SELECT requests, window_start FROM rate_limit_buckets WHERE bucket_key = " . self::$db->quote($key)
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row || $row['window_start'] < $start) {
            self::$db->exec("
                INSERT INTO rate_limit_buckets (bucket_key, requests, window_start)
                VALUES (" . self::$db->quote($key) . ", 1, $start)
                ON DUPLICATE KEY UPDATE requests = 1, window_start = $start
            ");
            return;
        }

        if ($row['requests'] >= $max) {
            $retryAfter = ($row['window_start'] + $window) - $now;
            header('Retry-After: ' . max(1, $retryAfter));
            json_response(false,
                "Bahut zyada requests is IP se. Kuch der baad try karo.",
                ['retry_after' => $retryAfter],
                429
            );
        }

        self::$db->exec(
            "UPDATE rate_limit_buckets SET requests = requests + 1 WHERE bucket_key = " . self::$db->quote($key)
        );
    }

    /** Get current usage stats for an action (for debug/admin) */
    static function getStats(string $action, ?int $userId = null): array {
        self::ensureTable();
        $userId = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
        [$max, $window] = self::LIMITS[$action] ?? self::LIMITS['_default'];
        $key   = 'u:' . $userId . ':' . $action;
        $now   = time();
        $start = (int) floor($now / $window) * $window;

        $row = self::$db->query(
            "SELECT requests, window_start FROM rate_limit_buckets WHERE bucket_key = " . self::$db->quote($key)
        )->fetch(\PDO::FETCH_ASSOC);

        $used = ($row && $row['window_start'] >= $start) ? (int) $row['requests'] : 0;
        return [
            'action'    => $action,
            'used'      => $used,
            'limit'     => $max,
            'remaining' => max(0, $max - $used),
            'window'    => $window,
            'resets_in' => ($start + $window) - $now,
        ];
    }
}
