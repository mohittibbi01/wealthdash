-- ============================================================
-- WealthDash — t336: Data Versioning — Undo Import
-- Migration: database/migrations/t336_migration.sql
-- Worker: ID-M
-- ============================================================

-- Import version snapshots
CREATE TABLE IF NOT EXISTS `import_versions` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `portfolio_id`  INT UNSIGNED    NOT NULL,
    `import_type`   VARCHAR(40)     NOT NULL COMMENT 'mf_csv, stocks_csv, fd_manual, cas_pdf, etc.',
    `label`         VARCHAR(120)    NOT NULL,
    `snapshot_data` LONGTEXT        NOT NULL COMMENT 'JSON snapshot of affected rows before import',
    `affected_table`VARCHAR(60)     NOT NULL,
    `affected_ids`  JSON            NOT NULL COMMENT 'Array of inserted/modified row IDs',
    `rows_added`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `rows_modified` INT UNSIGNED    NOT NULL DEFAULT 0,
    `rows_deleted`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `file_name`     VARCHAR(255)    DEFAULT NULL,
    `file_hash`     VARCHAR(64)     DEFAULT NULL COMMENT 'SHA256 of original file',
    `status`        ENUM('active','undone','partial') NOT NULL DEFAULT 'active',
    `undone_at`     DATETIME        DEFAULT NULL,
    `undone_by`     INT UNSIGNED    DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_iv_user`       (`user_id`),
    KEY `idx_iv_portfolio`  (`portfolio_id`),
    KEY `idx_iv_type`       (`import_type`),
    KEY `idx_iv_status`     (`status`),
    CONSTRAINT `fk_iv_user`      FOREIGN KEY (`user_id`)      REFERENCES `users`(`id`)      ON DELETE CASCADE,
    CONSTRAINT `fk_iv_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Row-level change log (granular undo)
CREATE TABLE IF NOT EXISTS `import_row_changes` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version_id`    INT UNSIGNED    NOT NULL,
    `table_name`    VARCHAR(60)     NOT NULL,
    `row_id`        INT UNSIGNED    NOT NULL,
    `change_type`   ENUM('insert','update','delete') NOT NULL,
    `old_data`      JSON            DEFAULT NULL,
    `new_data`      JSON            DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_irc_version` (`version_id`),
    KEY `idx_irc_table_row` (`table_name`, `row_id`),
    CONSTRAINT `fk_irc_version` FOREIGN KEY (`version_id`) REFERENCES `import_versions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
