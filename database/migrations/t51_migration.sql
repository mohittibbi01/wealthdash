-- ============================================================
-- WealthDash — t51: System Health Dashboard
-- Migration: database/migrations/t51_migration.sql
-- Worker: ID-M
-- ============================================================

-- System health snapshots (for trend chart)
CREATE TABLE IF NOT EXISTS `system_health_log` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snap_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `php_memory`   INT UNSIGNED DEFAULT NULL COMMENT 'bytes',
    `db_size_mb`   DECIMAL(10,2) DEFAULT NULL,
    `slow_queries` INT UNSIGNED DEFAULT NULL,
    `cache_hits`   INT UNSIGNED DEFAULT NULL,
    `cache_misses` INT UNSIGNED DEFAULT NULL,
    `active_users` INT UNSIGNED DEFAULT NULL,
    `error_count`  INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_shl_snap` (`snap_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
