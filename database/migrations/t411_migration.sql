-- ============================================================
-- WealthDash — t411: Automated Test Suite
-- Migration: database/migrations/t411_migration.sql
-- Worker: ID-M
-- ============================================================

CREATE TABLE IF NOT EXISTS `test_run_log` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `run_id`       VARCHAR(36)     NOT NULL COMMENT 'UUID per run',
    `suite`        VARCHAR(60)     NOT NULL COMMENT 'unit | integration | e2e | perf',
    `test_name`    VARCHAR(120)    NOT NULL,
    `status`       ENUM('pass','fail','skip','error') NOT NULL,
    `duration_ms`  FLOAT           DEFAULT NULL,
    `message`      TEXT            DEFAULT NULL,
    `triggered_by` INT UNSIGNED    DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_trl_run`     (`run_id`),
    KEY `idx_trl_suite`   (`suite`),
    KEY `idx_trl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
