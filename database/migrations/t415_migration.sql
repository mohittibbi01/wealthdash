-- ============================================================
-- WealthDash — t415: Load Testing
-- Migration: database/migrations/t415_migration.sql
-- Worker: ID-M
-- ============================================================

CREATE TABLE IF NOT EXISTS `load_test_runs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id`          VARCHAR(36)  NOT NULL,
    `scenario`        VARCHAR(80)  NOT NULL,
    `concurrency`     SMALLINT     NOT NULL DEFAULT 10,
    `duration_sec`    INT          NOT NULL,
    `total_requests`  INT UNSIGNED NOT NULL,
    `success_count`   INT UNSIGNED NOT NULL,
    `error_count`     INT UNSIGNED NOT NULL,
    `avg_ms`          FLOAT        DEFAULT NULL,
    `p50_ms`          FLOAT        DEFAULT NULL,
    `p95_ms`          FLOAT        DEFAULT NULL,
    `p99_ms`          FLOAT        DEFAULT NULL,
    `max_ms`          FLOAT        DEFAULT NULL,
    `rps`             FLOAT        DEFAULT NULL COMMENT 'Requests per second',
    `triggered_by`    INT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ltr_run`     (`run_id`),
    KEY `idx_ltr_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
