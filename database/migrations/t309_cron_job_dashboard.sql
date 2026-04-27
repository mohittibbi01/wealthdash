-- WealthDash — t309: Cron Job Dashboard
-- Creates cron_run_log table to track cron job executions

CREATE TABLE IF NOT EXISTS `cron_run_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_name`    VARCHAR(80)  NOT NULL COMMENT 'e.g. update_nav_daily',
  `started_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME     NULL,
  `status`      ENUM('running','success','warning','failed') NOT NULL DEFAULT 'running',
  `duration_ms` INT UNSIGNED NULL     COMMENT 'Execution time in milliseconds',
  `records`     INT UNSIGNED NULL     COMMENT 'Records processed',
  `message`     TEXT         NULL     COMMENT 'Summary / error message',
  PRIMARY KEY (`id`),
  KEY `idx_cron_job_started` (`job_name`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
