<?php
/**
 * WealthDash — t23: Debug Health Check
 * debug_check.php — Root-level quick health check wrapper
 *
 * Lightweight alternative to debug/runner.php
 * Checks: DB connection, required tables, API endpoints, file permissions,
 *         cron status, PHP extensions, config validity.
 *
 * Usage:
 *   http://localhost/wealthdash/debug_check.php
 *   http://localhost/wealthdash/debug_check.php?format=json
 *   http://localhost/wealthdash/debug_check.php?suite=db,files,api
 *
 * Output: HTML report (default) or JSON (?format=json)
 *
 * Security: Only accessible from localhost. Exits immediately on remote IP.
 *
 * TODO: implement DB check — test connection, required tables list
 * TODO: implement files check — APP_ROOT existence, key PHP files readable
 * TODO: implement API check — ping each router action, expect valid JSON
 * TODO: implement cron check — last_run timestamps from cron log
 * TODO: implement config check — .env keys present, non-empty
 * TODO: implement HTML report — color-coded pass/warn/fail table
 * TODO: implement JSON output — ?format=json for task tracker integration
 */

// ── Security: localhost only ──────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
    http_response_code(403);
    die('Access denied — localhost only.');
}

define('WEALTHDASH', true);
$BASE = __DIR__;
require_once $BASE . '/config/config.php';

header('Content-Type: text/html; charset=UTF-8');

echo '<!DOCTYPE html><html><body>';
echo '<h2>WealthDash Debug Check — t23</h2>';
echo '<p style="color:orange;">⚠️ Not yet implemented. Use <a href="debug/runner.php">debug/runner.php</a> for now.</p>';
echo '</body></html>';
