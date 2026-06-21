-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t490: CSV Importer v3 (Auto-Detect Any Format)
-- Migration: t490_migration.sql
-- NOTE: mf_import_csv_v3.php already exists in api/mutual_funds/
-- This migration adds the import_history tracking table
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `csv_import_v3_sessions` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`           INT UNSIGNED NOT NULL,
    `portfolio_id`      INT UNSIGNED DEFAULT NULL,
    `filename`          VARCHAR(255) DEFAULT NULL,
    `file_size`         INT UNSIGNED DEFAULT NULL,
    `detected_format`   VARCHAR(50)  DEFAULT NULL,
    `format_label`      VARCHAR(100) DEFAULT NULL,
    `confidence`        TINYINT UNSIGNED DEFAULT 0,
    `col_mapping_json`  TEXT         DEFAULT NULL COMMENT 'JSON of column index map',
    `header_row_index`  TINYINT UNSIGNED DEFAULT 0,
    `total_data_rows`   INT UNSIGNED DEFAULT 0,
    `preview_json`      MEDIUMTEXT   DEFAULT NULL COMMENT 'First 10 rows preview',
    `action`            ENUM('detect','import','preview') DEFAULT 'detect',
    `imported`          INT UNSIGNED DEFAULT 0,
    `skipped`           INT UNSIGNED DEFAULT 0,
    `errors`            INT UNSIGNED DEFAULT 0,
    `error_rows_json`   MEDIUMTEXT   DEFAULT NULL,
    `status`            ENUM('detected','previewed','imported','failed') DEFAULT 'detected',
    `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`       (`user_id`),
    INDEX `idx_portfolio`  (`portfolio_id`),
    INDEX `idx_format`     (`detected_format`),
    INDEX `idx_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-saved custom column mappings (for recurring imports from same source)
CREATE TABLE IF NOT EXISTS `csv_column_mapping_presets` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `name`        VARCHAR(100) NOT NULL COMMENT 'e.g. My CAMS Format',
    `format_hint` VARCHAR(50)  DEFAULT NULL,
    `mapping_json`TEXT         NOT NULL COMMENT 'JSON column mapping',
    `use_count`   INT UNSIGNED DEFAULT 0,
    `last_used`   DATETIME     DEFAULT NULL,
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
