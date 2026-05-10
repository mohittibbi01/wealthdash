<?php
/**
 * WealthDash — NAV Auto-Update Cron Script
 * t05: Admin Panel — NAV auto-update, no manual click needed.
 *
 * Schedule via system cron (e.g. daily at 22:00 IST after AMFI publishes):
 *   0 22 * * * php /path/to/wealthdash/cron/nav_auto_update.php >> /var/log/wealthdash_nav.log 2>&1
 *
 * Or via WealthDash admin cron scheduler (admin_cron_status / admin_cron_history).
 */
define('WEALTHDASH', true);
define('CLI_MODE',   true);

require_once __DIR__ . '/../bootstrap.php';

// ── Safety: only run from CLI or trusted admin request ──────
if (PHP_SAPI !== 'cli' && !is_admin()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Forbidden']));
}

$log = function(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('NAV auto-update started (t05)');

// ── Check if already ran today ───────────────────────────────
$today     = date('Y-m-d');
$lastRunKey = 'nav_auto_update_last_run';
$lastRun   = db_get_setting($lastRunKey);

if ($lastRun === $today) {
    $log('Already updated today (' . $today . '). Skipping.');
    exit(0);
}

// ── Trigger AMFI NAV update (reuse existing update_amfi endpoint logic) ──
try {
    require_once APP_ROOT . '/api/nav/update_amfi.php';
} catch (Throwable $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}

// ── Mark as done for today ───────────────────────────────────
db_set_setting($lastRunKey, $today);
$log('NAV auto-update completed successfully for ' . $today);
exit(0);
