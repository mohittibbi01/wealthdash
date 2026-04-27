<?php
/**
 * WealthDash — t309: Cron Job Dashboard API
 * Actions:
 *   admin_cron_status   — live status of all known jobs + last run info
 *   admin_cron_history  — paginated run history for a specific job
 *   admin_cron_trigger  — manually trigger a cron job (exec via CLI)
 *   admin_cron_clear    — purge log entries older than N days
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

if (!$isAdmin) {
    json_response(false, 'Admin only', [], 403);
}

$db = DB::conn();

// Ensure table exists (idempotent)
$db->exec("
    CREATE TABLE IF NOT EXISTS `cron_run_log` (
      `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `job_name`    VARCHAR(80)  NOT NULL,
      `started_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `finished_at` DATETIME     NULL,
      `status`      ENUM('running','success','warning','failed') NOT NULL DEFAULT 'running',
      `duration_ms` INT UNSIGNED NULL,
      `records`     INT UNSIGNED NULL,
      `message`     TEXT         NULL,
      PRIMARY KEY (`id`),
      KEY `idx_cron_job_started` (`job_name`, `started_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Known cron jobs registry
$KNOWN_JOBS = [
    'update_nav_daily'      => ['label' => 'Daily NAV Update',        'schedule' => 'Daily 7:00 PM',   'file' => 'update_nav_daily.php'],
    'populate_nav_history'  => ['label' => 'NAV History Populate',    'schedule' => 'On-demand',        'file' => 'populate_nav_history.php'],
    'calculate_returns'     => ['label' => 'Returns Calculator',      'schedule' => 'Daily 8:00 PM',   'file' => 'calculate_returns.php'],
    'update_stocks_daily'   => ['label' => 'Stocks Price Update',     'schedule' => 'Weekdays 6:30 PM','file' => 'update_stocks_daily.php'],
    'fd_maturity_alert'     => ['label' => 'FD Maturity Alerts',      'schedule' => 'Daily 9:00 AM',   'file' => 'fd_maturity_alert.php'],
    'fd_rate_updater'       => ['label' => 'FD Rate Updater',         'schedule' => 'Weekly Sunday',   'file' => 'fd_rate_updater.php'],
    'nps_nav_scraper'       => ['label' => 'NPS NAV Scraper',         'schedule' => 'Daily 8:00 PM',   'file' => 'nps_nav_scraper.php'],
    'fetch_fund_holdings'   => ['label' => 'Fund Holdings Fetch',     'schedule' => 'Monthly 1st',     'file' => 'fetch_fund_holdings.php'],
    'check_price_alerts'    => ['label' => 'Price Alerts Check',      'schedule' => 'Every 15 min',    'file' => 'check_price_alerts.php'],
    'send_scheduled_reports'=> ['label' => 'Scheduled Reports Send',  'schedule' => 'Daily 8:00 AM',   'file' => 'send_scheduled_reports.php'],
    'ai_weekly_digest'      => ['label' => 'AI Weekly Digest',        'schedule' => 'Weekly Monday',   'file' => 'ai_weekly_digest.php'],
    'monthly_summary'       => ['label' => 'Monthly Summary',         'schedule' => 'Monthly 1st',     'file' => 'monthly_summary.php'],
];

switch ($action) {

    // ── Status: all jobs + last run ──────────────────────────────────────
    case 'admin_cron_status': {
        $rows = $db->query("
            SELECT job_name,
                   MAX(started_at) AS last_run,
                   MAX(CASE WHEN id = (SELECT MAX(id) FROM cron_run_log c2 WHERE c2.job_name = c1.job_name) THEN status END) AS last_status,
                   MAX(CASE WHEN id = (SELECT MAX(id) FROM cron_run_log c2 WHERE c2.job_name = c1.job_name) THEN duration_ms END) AS last_duration_ms,
                   MAX(CASE WHEN id = (SELECT MAX(id) FROM cron_run_log c2 WHERE c2.job_name = c1.job_name) THEN records END) AS last_records,
                   MAX(CASE WHEN id = (SELECT MAX(id) FROM cron_run_log c2 WHERE c2.job_name = c1.job_name) THEN message END) AS last_message,
                   COUNT(*) AS total_runs,
                   SUM(status = 'success') AS success_count,
                   SUM(status = 'failed') AS fail_count
            FROM cron_run_log c1
            GROUP BY job_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $logIndex = [];
        foreach ($rows as $r) $logIndex[$r['job_name']] = $r;

        $result = [];
        foreach ($KNOWN_JOBS as $key => $meta) {
            $log = $logIndex[$key] ?? null;
            $result[] = [
                'job_name'       => $key,
                'label'          => $meta['label'],
                'schedule'       => $meta['schedule'],
                'file'           => $meta['file'],
                'last_run'       => $log['last_run'] ?? null,
                'last_status'    => $log['last_status'] ?? 'never',
                'last_duration_ms'=> (int)($log['last_duration_ms'] ?? 0),
                'last_records'   => (int)($log['last_records'] ?? 0),
                'last_message'   => $log['last_message'] ?? '',
                'total_runs'     => (int)($log['total_runs'] ?? 0),
                'success_count'  => (int)($log['success_count'] ?? 0),
                'fail_count'     => (int)($log['fail_count'] ?? 0),
                'success_rate'   => $log && $log['total_runs'] > 0
                                    ? round($log['success_count'] / $log['total_runs'] * 100)
                                    : null,
            ];
        }

        // Also grab aggregate summary
        $summary = $db->query("
            SELECT
              COUNT(*) AS total_runs,
              SUM(status='success') AS successes,
              SUM(status='failed')  AS failures,
              SUM(status='running') AS running,
              ROUND(AVG(duration_ms)) AS avg_duration_ms
            FROM cron_run_log
            WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ")->fetch(PDO::FETCH_ASSOC);

        json_response(true, 'ok', ['jobs' => $result, 'summary' => $summary]);
    }

    // ── History for a specific job ────────────────────────────────────────
    case 'admin_cron_history': {
        $jobName = clean($_POST['job_name'] ?? '');
        $page    = max(1, (int)($_POST['page'] ?? 1));
        $limit   = 20;
        $offset  = ($page - 1) * $limit;

        if (!$jobName || !isset($KNOWN_JOBS[$jobName])) {
            json_response(false, 'Invalid job name');
        }

        $stmt = $db->prepare("
            SELECT id, started_at, finished_at, status, duration_ms, records, message
            FROM cron_run_log
            WHERE job_name = ?
            ORDER BY started_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$jobName, $limit, $offset]);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = (int)$db->prepare("SELECT COUNT(*) FROM cron_run_log WHERE job_name = ?")
            ->execute([$jobName]) ? $db->query("SELECT COUNT(*) FROM cron_run_log WHERE job_name = '$jobName'")->fetchColumn() : 0;

        json_response(true, 'ok', [
            'job'   => $KNOWN_JOBS[$jobName],
            'runs'  => $runs,
            'total' => $total,
            'page'  => $page,
            'pages' => ceil($total / $limit),
        ]);
    }

    // ── Trigger a job manually via CLI ───────────────────────────────────
    case 'admin_cron_trigger': {
        $jobName = clean($_POST['job_name'] ?? '');

        if (!$jobName || !isset($KNOWN_JOBS[$jobName])) {
            json_response(false, 'Invalid job name');
        }

        $file    = $KNOWN_JOBS[$jobName]['file'];
        $cronDir = APP_ROOT . '/cron/' . $file;

        if (!file_exists($cronDir)) {
            json_response(false, "Cron file not found: cron/{$file}");
        }

        // Find PHP binary
        $php = PHP_BINARY ?: 'php';

        // Run async in background
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B {$php} " . escapeshellarg($cronDir) . " > NUL 2>&1", 'r'));
        } else {
            exec($php . ' ' . escapeshellarg($cronDir) . ' > /dev/null 2>&1 &');
        }

        json_response(true, "Job '{$KNOWN_JOBS[$jobName]['label']}' triggered. Check logs in a moment.", [
            'job_name' => $jobName,
        ]);
    }

    // ── Clear old log entries ─────────────────────────────────────────────
    case 'admin_cron_clear': {
        $days = max(1, (int)($_POST['days'] ?? 30));
        $stmt = $db->prepare("DELETE FROM cron_run_log WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        $deleted = $stmt->rowCount();
        json_response(true, "Deleted {$deleted} log entries older than {$days} days.", ['deleted' => $deleted]);
    }

    default:
        json_response(false, 'Unknown action');
}
