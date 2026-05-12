-- ============================================================
-- t211: Admin — DB Backup & Restore UI
-- Migration: Creates db_backups table to track backup history
-- ============================================================

CREATE TABLE IF NOT EXISTS `db_backups` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `filename`      VARCHAR(255)    NOT NULL,
    `file_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `tables_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `rows_count`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `backup_type`   ENUM('full','schema_only','data_only') NOT NULL DEFAULT 'full',
    `status`        ENUM('in_progress','completed','failed') NOT NULL DEFAULT 'in_progress',
    `error_msg`     TEXT            NULL,
    `created_by`    INT UNSIGNED    NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`  DATETIME        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='t211: DB Backup history log';

-- Restore log
CREATE TABLE IF NOT EXISTS `db_restore_log` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `backup_id`     INT UNSIGNED    NULL COMMENT 'NULL if uploaded file restore',
    `filename`      VARCHAR(255)    NOT NULL,
    `tables_restored` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`        ENUM('in_progress','completed','failed') NOT NULL DEFAULT 'in_progress',
    `error_msg`     TEXT            NULL,
    `restored_by`   INT UNSIGNED    NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`  DATETIME        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='t211: DB Restore history log';
