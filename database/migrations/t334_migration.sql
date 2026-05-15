-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t334: Bulk Import (Excel Template, 50 fields)
-- Migration: t334_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `bulk_import_sessions` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `import_source`   VARCHAR(50)  NOT NULL DEFAULT 'excel' COMMENT 'excel|csv|api',
    `asset_type`      ENUM('mf','stocks','fd','gold','reits','smallcase','mixed') NOT NULL DEFAULT 'mf',
    `filename`        VARCHAR(255) DEFAULT NULL,
    `status`          ENUM('pending','validating','importing','done','failed','partial') DEFAULT 'pending',
    `total_rows`      INT UNSIGNED DEFAULT 0,
    `valid_rows`      INT UNSIGNED DEFAULT 0,
    `imported`        INT UNSIGNED DEFAULT 0,
    `skipped`         INT UNSIGNED DEFAULT 0,
    `error_count`     INT UNSIGNED DEFAULT 0,
    `validation_log`  MEDIUMTEXT DEFAULT NULL COMMENT 'JSON array of validation errors',
    `import_log`      MEDIUMTEXT DEFAULT NULL COMMENT 'JSON array of import results',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`      (`user_id`),
    INDEX `idx_portfolio` (`portfolio_id`),
    INDEX `idx_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which template version was used (for future compatibility)
CREATE TABLE IF NOT EXISTS `bulk_import_templates` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `version`         VARCHAR(20)  NOT NULL UNIQUE,
    `asset_type`      VARCHAR(50)  NOT NULL,
    `field_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `fields_json`     MEDIUMTEXT   NOT NULL COMMENT 'JSON array of field definitions',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: MF template v1 with 50 fields
INSERT IGNORE INTO `bulk_import_templates` (version, asset_type, field_count, fields_json)
VALUES ('mf_v1', 'mf', 50, '["fund_name","scheme_code","isin","amc","category","sub_category","plan_type","option_type","folio_number","portfolio_name","transaction_type","txn_date","units","nav","amount","stamp_duty","exit_load","stt","gst","brokerage","platform","advisor","bank_account","payment_mode","cheque_number","utr_number","sip_id","sip_frequency","sip_day","sip_start_date","sip_end_date","lumpsum_flag","switch_from_fund","switch_to_fund","switch_units","redemption_bank","redemption_ifsc","redemption_account","dividend_type","dividend_amount","dividend_date","xirr","investment_fy","cost_of_acquisition","indexed_cost","capital_gain_type","stcg_amount","ltcg_amount","grandfathered_nav","notes"]');
