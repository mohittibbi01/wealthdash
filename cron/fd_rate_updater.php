<?php
/**
 * WealthDash — FD Rate Updater Cron (t421)
 *
 * Schedule: Weekly (Sunday midnight) — logs changes to fd_rate_history
 * Cron:  0 0 * * 0  /usr/bin/php /var/www/wealthdash/cron/fd_rate_updater.php
 *
 * Purpose:
 *   - Snapshots current fd_bank_rates into fd_rate_history for trend tracking
 *   - In future: can be extended to fetch rates from bank websites/APIs
 *   - Sends admin notification if rates seem stale (> 30 days)
 */
define('WEALTHDASH', true);
define('CRON_RUN', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/cron_logger.php';
$_cronLog = new CronLogger('fd_rate_updater');
$_cronLog->start();

require_once APP_ROOT . '/includes/helpers.php';

$start   = microtime(true);
$logFile = APP_ROOT . '/logs/fd_rate_updater.log';
$today   = date('Y-m-d');

function clog(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    if (PHP_SAPI === 'cli') echo $line;
}

clog("=== FD Rate Updater started ===");

try {
    $db = DB::conn();

    // ── 1. Snapshot current rates into history (weekly) ─────
    // Only snapshot if there are no history entries for this week
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $alreadySnapped = (int)$db->prepare(
        "SELECT COUNT(*) FROM fd_rate_history WHERE recorded_at >= ?"
    )->execute([$weekStart . ' 00:00:00']) ? $db->query(
        "SELECT COUNT(*) FROM fd_rate_history WHERE recorded_at >= '$weekStart 00:00:00'"
    )->fetchColumn() : 0;

    if ($alreadySnapped == 0) {
        $snapped = $db->exec("
            INSERT INTO fd_rate_history (bank_name, tenure_months, rate_regular, rate_senior, effective_date)
            SELECT bank_name, tenure_months, rate_regular, rate_senior, '$today'
            FROM fd_bank_rates
            WHERE is_active = 1
        ");
        clog("Snapshotted {$snapped} rates into history.");
    } else {
        clog("Already snapshotted this week — skipping.");
    }

    // ── 2. Stale-rate alert ─────────────────────────────────
    $staleCheck = $db->query("
        SELECT bank_name, tenure_months, updated_at,
               DATEDIFF(CURDATE(), updated_at) AS days_stale
        FROM fd_bank_rates
        WHERE is_active = 1 AND DATEDIFF(CURDATE(), updated_at) > 30
        ORDER BY days_stale DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($staleCheck) {
        $msg = "⚠ " . count($staleCheck) . " fd_bank_rates entries are >30 days old. ";
        $msg .= "Oldest: {$staleCheck[0]['bank_name']} ({$staleCheck[0]['days_stale']} days). ";
        $msg .= "Update rates via Admin → FD Rates.";
        clog($msg);

        // Optionally log to notifications table
        try {
            $db->prepare("
                INSERT IGNORE INTO notifications (user_id, type, title, body, is_read, created_at)
                SELECT id, 'fd_rates_stale', 'FD Rates Need Update',
                       ?, 0, NOW()
                FROM users WHERE is_admin = 1 LIMIT 1
            ")->execute([$msg]);
        } catch (Exception $e) { /* notifications table may not exist */ }
    } else {
        clog("All FD rates are up to date (within 30 days).");
    }

    // ── 3. Update app_settings timestamp ────────────────────
    try {
        $db->prepare("
            INSERT INTO app_settings (`key`, `value`) VALUES ('fd_rates_cron_last_run', ?)
            ON DUPLICATE KEY UPDATE `value` = ?
        ")->execute([$today, $today]);
    } catch (Exception $e) { /* app_settings may not exist */ }

    $elapsed = round(microtime(true) - $start, 2);
    clog("=== Done in {$elapsed}s ===");

} catch (Throwable $e) {
    clog("ERROR: " . $e->getMessage());
    exit(1);
}

\$_cronLog->finish('success', 'FD rates updated');
