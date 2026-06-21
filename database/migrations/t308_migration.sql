-- ============================================================
-- WealthDash — t308: System Performance Monitor
-- Migration: database/migrations/t308_migration.sql
-- Worker: ID-M
-- ============================================================

-- API request performance log
CREATE TABLE IF NOT EXISTS `perf_request_log` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`       VARCHAR(100)    NOT NULL,
    `user_id`      INT UNSIGNED    DEFAULT NULL,
    `duration_ms`  FLOAT           NOT NULL,
    `memory_bytes` INT UNSIGNED    DEFAULT NULL,
    `db_queries`   SMALLINT        DEFAULT NULL,
    `status_code`  SMALLINT        DEFAULT 200,
    `ip_address`   VARCHAR(45)     DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_prl_action`  (`action`),
    KEY `idx_prl_created` (`created_at`),
    KEY `idx_prl_duration`(`duration_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hourly aggregated performance snapshots
CREATE TABLE IF NOT EXISTS `perf_hourly_stats` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hour`          DATETIME     NOT NULL COMMENT 'Truncated to hour',
    `action`        VARCHAR(100) NOT NULL DEFAULT '__all__',
    `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `avg_ms`        FLOAT        DEFAULT NULL,
    `p95_ms`        FLOAT        DEFAULT NULL,
    `max_ms`        FLOAT        DEFAULT NULL,
    `error_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `avg_memory_mb` FLOAT        DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_phs_hour_action` (`hour`, `action`),
    KEY `idx_phs_hour` (`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Slow query alerts
CREATE TABLE IF NOT EXISTS `perf_slow_alerts` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`      VARCHAR(100) NOT NULL,
    `duration_ms` FLOAT        NOT NULL,
    `threshold_ms`FLOAT        NOT NULL,
    `user_id`     INT UNSIGNED DEFAULT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `context`     TEXT         DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_psa_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
