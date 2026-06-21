-- ============================================================
-- WealthDash — t53: DB Manager Tab
-- Migration: database/migrations/t53_migration.sql
-- Worker: ID-M
-- ============================================================

-- DB Manager query history
CREATE TABLE IF NOT EXISTS `db_query_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    NOT NULL,
    `query_text`  TEXT            NOT NULL,
    `rows_affected` INT DEFAULT NULL,
    `exec_ms`     FLOAT DEFAULT NULL,
    `is_success`  TINYINT(1) NOT NULL DEFAULT 1,
    `error_msg`   TEXT DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dql_user` (`user_id`),
    KEY `idx_dql_created` (`created_at`),
    CONSTRAINT `fk_dql_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DB backups log
CREATE TABLE IF NOT EXISTS `db_backup_log` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename`    VARCHAR(255) NOT NULL,
    `size_bytes`  BIGINT DEFAULT NULL,
    `tables`      INT DEFAULT NULL,
    `triggered_by` INT UNSIGNED DEFAULT NULL,
    `method`      ENUM('manual','cron','auto') NOT NULL DEFAULT 'manual',
    `status`      ENUM('completed','failed','in_progress') NOT NULL DEFAULT 'completed',
    `error_msg`   TEXT DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dbl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
