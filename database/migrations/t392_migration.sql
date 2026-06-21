-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t392: Groww API Sync (MF + Stocks)
-- Migration: t392_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `groww_api_credentials` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL UNIQUE,
    `access_token`    TEXT         DEFAULT NULL COMMENT 'Encrypted bearer token',
    `refresh_token`   TEXT         DEFAULT NULL COMMENT 'Encrypted refresh token',
    `token_expires_at` DATETIME    DEFAULT NULL,
    `linked_email`    VARCHAR(200) DEFAULT NULL,
    `linked_mobile`   VARCHAR(15)  DEFAULT NULL,
    `scope`           VARCHAR(200) DEFAULT NULL COMMENT 'mf,stocks,profile',
    `status`          ENUM('active','expired','revoked','error') NOT NULL DEFAULT 'active',
    `last_sync_at`    DATETIME     DEFAULT NULL,
    `last_sync_type`  VARCHAR(50)  DEFAULT NULL,
    `error_msg`       VARCHAR(500) DEFAULT NULL,
    `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`   (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_sync_log` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `sync_type`       ENUM('mf_holdings','mf_transactions','stock_holdings','stock_transactions','full') NOT NULL,
    `status`          ENUM('running','done','failed','partial') NOT NULL DEFAULT 'running',
    `mf_synced`       INT UNSIGNED DEFAULT 0,
    `stock_synced`    INT UNSIGNED DEFAULT 0,
    `errors`          INT UNSIGNED DEFAULT 0,
    `error_detail`    TEXT DEFAULT NULL,
    `api_calls`       INT UNSIGNED DEFAULT 0,
    `started_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at`    DATETIME DEFAULT NULL,
    INDEX `idx_user`  (`user_id`),
    INDEX `idx_date`  (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_mf_holdings_raw` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `sync_log_id`     INT UNSIGNED NOT NULL,
    `groww_folio`     VARCHAR(100) DEFAULT NULL,
    `groww_scheme_id` VARCHAR(100) DEFAULT NULL,
    `scheme_name`     VARCHAR(300) NOT NULL,
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `units`           DECIMAL(14,4) DEFAULT NULL,
    `nav`             DECIMAL(12,4) DEFAULT NULL,
    `current_value`   DECIMAL(14,2) DEFAULT NULL,
    `invested_value`  DECIMAL(14,2) DEFAULT NULL,
    `fund_id`         INT UNSIGNED DEFAULT NULL COMMENT 'Resolved WD fund_id',
    `is_mapped`       TINYINT(1)   NOT NULL DEFAULT 0,
    `synced_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user`  (`user_id`),
    INDEX `idx_sync`  (`sync_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_stock_holdings_raw` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `sync_log_id`     INT UNSIGNED NOT NULL,
    `symbol`          VARCHAR(30)  NOT NULL,
    `company_name`    VARCHAR(200) DEFAULT NULL,
    `exchange`        VARCHAR(10)  DEFAULT 'NSE',
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `quantity`        DECIMAL(14,4) DEFAULT NULL,
    `avg_price`       DECIMAL(12,4) DEFAULT NULL,
    `ltp`             DECIMAL(12,4) DEFAULT NULL,
    `invested_value`  DECIMAL(14,2) DEFAULT NULL,
    `current_value`   DECIMAL(14,2) DEFAULT NULL,
    `pnl`             DECIMAL(14,2) DEFAULT NULL,
    `synced_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user`  (`user_id`),
    INDEX `idx_sync`  (`sync_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
