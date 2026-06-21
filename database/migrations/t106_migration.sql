-- ============================================================
-- WealthDash — Migration t106: NPS Contribution Auto-detect
-- Task: Bank statement import staging for NPS contributions
-- Depends on: t99 (nps_transactions.tier, contribution_type, investment_fy)
-- Run ONCE — idempotent
-- ============================================================

-- 1. Import session log
CREATE TABLE IF NOT EXISTS `nps_import_sessions` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`    int(10) UNSIGNED NOT NULL,
  `user_id`         int(10) UNSIGNED NOT NULL,
  `bank_name`       varchar(80)      DEFAULT NULL COMMENT 'Detected or user-selected bank',
  `statement_from`  date             DEFAULT NULL COMMENT 'Statement period start',
  `statement_to`    date             DEFAULT NULL COMMENT 'Statement period end',
  `raw_filename`    varchar(255)     DEFAULT NULL,
  `total_rows`      int UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'Total rows parsed',
  `detected_rows`   int UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'NPS rows detected',
  `confirmed_rows`  int UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'Rows imported to nps_transactions',
  `status`          enum('pending','reviewed','imported','dismissed') NOT NULL DEFAULT 'pending',
  `import_notes`    varchar(255)     DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nps_imp_portfolio` (`portfolio_id`),
  KEY `idx_nps_imp_user`      (`user_id`),
  KEY `idx_nps_imp_status`    (`status`),
  CONSTRAINT `fk_nps_imp_portfolio`
    FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Staging rows — one per detected NPS transaction
CREATE TABLE IF NOT EXISTS `nps_import_staging` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`        int(10) UNSIGNED NOT NULL,
  `portfolio_id`      int(10) UNSIGNED NOT NULL,

  -- Raw data from bank statement
  `raw_date`          varchar(30)      DEFAULT NULL,
  `raw_narration`     varchar(500)     DEFAULT NULL,
  `raw_debit`         varchar(30)      DEFAULT NULL,
  `raw_credit`        varchar(30)      DEFAULT NULL,
  `raw_balance`       varchar(30)      DEFAULT NULL,
  `raw_row_number`    int UNSIGNED     DEFAULT NULL COMMENT 'Row number in original file',

  -- Parsed / normalised
  `txn_date`          date             DEFAULT NULL,
  `amount`            decimal(16,2)    DEFAULT NULL,
  `detected_bank`     varchar(80)      DEFAULT NULL,
  `detected_pfm`      varchar(100)     DEFAULT NULL COMMENT 'Detected PFM from narration',
  `detected_tier`     enum('tier1','tier2') DEFAULT 'tier1',
  `detected_contrib`  enum('SELF','EMPLOYER') DEFAULT 'SELF',
  `confidence`        tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 match confidence',
  `match_keywords`    varchar(255)     DEFAULT NULL COMMENT 'Keywords that triggered detection',

  -- User-assigned (review step)
  `scheme_id`         int(10) UNSIGNED DEFAULT NULL,
  `tier`              enum('tier1','tier2') DEFAULT 'tier1',
  `contribution_type` enum('SELF','EMPLOYER') DEFAULT 'SELF',
  `units`             decimal(18,4)    DEFAULT NULL,
  `nav`               decimal(12,4)    DEFAULT NULL,
  `investment_fy`     varchar(10)      DEFAULT NULL,
  `notes`             varchar(255)     DEFAULT NULL,

  -- Import status
  `row_status`        enum('detected','accepted','rejected','imported','duplicate') NOT NULL DEFAULT 'detected',
  `imported_txn_id`   int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to nps_transactions.id after import',

  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_nps_stg_session`   (`session_id`),
  KEY `idx_nps_stg_portfolio` (`portfolio_id`),
  KEY `idx_nps_stg_status`    (`row_status`),
  KEY `idx_nps_stg_date`      (`txn_date`),
  CONSTRAINT `fk_nps_stg_session`
    FOREIGN KEY (`session_id`) REFERENCES `nps_import_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nps_stg_portfolio`
    FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure nps_transactions has required columns (added by t99; safe ALTER IF NOT EXISTS)
ALTER TABLE `nps_transactions`
  ADD COLUMN IF NOT EXISTS `tier`              enum('tier1','tier2') NOT NULL DEFAULT 'tier1' AFTER `scheme_id`,
  ADD COLUMN IF NOT EXISTS `contribution_type` enum('SELF','EMPLOYER') NOT NULL DEFAULT 'SELF'  AFTER `tier`,
  ADD COLUMN IF NOT EXISTS `investment_fy`     varchar(10) DEFAULT NULL                          AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `import_source`     enum('manual','bank_import','csv_upload') NOT NULL DEFAULT 'manual' AFTER `investment_fy`,
  ADD COLUMN IF NOT EXISTS `staging_id`        int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to nps_import_staging' AFTER `import_source`;

SELECT 't106 NPS Bank Import migration complete ✅' AS status;
