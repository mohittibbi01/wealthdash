<?php
/**
 * WealthDash — Cron Logger Helper (t309)
 * Logs cron job start/end to cron_run_log table
 *
 * Usage:
 *   $log = new CronLogger('update_nav_daily');
 *   $log->start();
 *   // ... do work ...
 *   $log->finish('success', 'Updated 1234 NAVs', 1234);
 *   // On error:
 *   $log->finish('failed', $e->getMessage());
 */

class CronLogger
{
    private PDO    $db;
    private string $jobName;
    private int    $runId   = 0;
    private float  $startTime;

    public function __construct(string $jobName)
    {
        $this->jobName   = $jobName;
        $this->startTime = microtime(true);

        try {
            $this->db = DB::conn();
            $this->ensureTable();
        } catch (Throwable $e) {
            // Silently fail — cron logging must never break the actual job
        }
    }

    public function start(): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO cron_run_log (job_name, started_at, status) VALUES (?, NOW(), 'running')"
            );
            $stmt->execute([$this->jobName]);
            $this->runId = (int) $this->db->lastInsertId();
        } catch (Throwable $e) { /* silent */ }
    }

    /**
     * @param string $status  'success' | 'warning' | 'failed'
     * @param string $message Summary message
     * @param int    $records Number of records processed
     */
    public function finish(string $status = 'success', string $message = '', int $records = 0): void
    {
        $ms = (int) round((microtime(true) - $this->startTime) * 1000);

        try {
            if ($this->runId > 0) {
                $stmt = $this->db->prepare("
                    UPDATE cron_run_log
                    SET finished_at = NOW(),
                        status      = ?,
                        duration_ms = ?,
                        records     = ?,
                        message     = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $ms, $records, $message, $this->runId]);
            } else {
                // start() was never called or failed — insert a complete record
                $stmt = $this->db->prepare("
                    INSERT INTO cron_run_log
                      (job_name, started_at, finished_at, status, duration_ms, records, message)
                    VALUES (?, DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), ?, ?, ?, ?)
                ");
                $secs = (int) round($ms / 1000);
                $stmt->execute([$this->jobName, $secs, $status, $ms, $records, $message]);
            }
        } catch (Throwable $e) { /* silent */ }
    }

    private function ensureTable(): void
    {
        $this->db->exec("
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
    }
}
