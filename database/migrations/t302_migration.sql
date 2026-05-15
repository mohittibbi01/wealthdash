-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t302: Groww Portfolio Import
-- Migration: t302_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `groww_import_sessions` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `import_type`     ENUM('csv','api','manual') NOT NULL DEFAULT 'csv',
    `filename`        VARCHAR(255) DEFAULT NULL,
    `status`          ENUM('pending','processing','done','failed','partial') NOT NULL DEFAULT 'pending',
    `total_rows`      INT UNSIGNED DEFAULT 0,
    `imported`        INT UNSIGNED DEFAULT 0,
    `skipped`         INT UNSIGNED DEFAULT 0,
    `errors`          INT UNSIGNED DEFAULT 0,
    `mf_count`        INT UNSIGNED DEFAULT 0,
    `stock_count`     INT UNSIGNED DEFAULT 0,
    `error_log`       TEXT DEFAULT NULL,
    `raw_response`    MEDIUMTEXT DEFAULT NULL COMMENT 'Raw JSON/CSV for audit',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`      (`user_id`),
    INDEX `idx_portfolio` (`portfolio_id`),
    INDEX `idx_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_fund_map` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `groww_name`      VARCHAR(300) NOT NULL COMMENT 'Scheme name as Groww exports it',
    `fund_id`         INT UNSIGNED DEFAULT NULL COMMENT 'Resolved WealthDash fund id',
    `scheme_code`     VARCHAR(20)  DEFAULT NULL,
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `is_confirmed`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_groww_name` (`groww_name`(200)),
    INDEX `idx_fund_id` (`fund_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
