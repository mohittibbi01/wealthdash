-- ============================================================
-- WealthDash — Migration 030: Expense Ratio History + Import Log
-- Tasks: t169 (Expense Ratio Trend), t190 (Import History)
-- ============================================================

-- ── t169: Expense Ratio History ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `expense_ratio_history` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `fund_id`        INT UNSIGNED  NOT NULL,
  `expense_ratio`  DECIMAL(6,4)  NOT NULL COMMENT 'TER in % (e.g. 1.05 = 1.05%)',
  `plan_type`      ENUM('direct','regular') NOT NULL DEFAULT 'direct',
  `recorded_date`  DATE          NOT NULL COMMENT 'First day of the month (monthly snapshot)',
  `source`         VARCHAR(100)  DEFAULT 'amfi_website',
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exp_fund_month` (`fund_id`, `plan_type`, `recorded_date`),
  KEY `idx_erh_fund` (`fund_id`),
  KEY `idx_erh_date` (`recorded_date`),
  CONSTRAINT `fk_erh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Monthly TER snapshot — track if expense ratio is rising or falling';

-- ── SEBI TER limits (for reference in PHP code) ──────────────────────────
-- Large Cap: max 1.05%  | Mid Cap: max 1.20%  | Small Cap: max 1.35%
-- Index Funds: max 0.50% | ETFs: max 0.25%
-- Source: SEBI Circular SEBI/HO/IMD/DF2/CIR/P/2018/137

-- ── t190: Import History Log ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `import_logs` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  NOT NULL,
  `filename`        VARCHAR(300)  DEFAULT NULL,
  `format`          ENUM('cams_cas','kfintech_cas','groww_csv','zerodha_csv','kuvera_csv','wealthdash_csv','other') NOT NULL,
  `imported_count`  INT           NOT NULL DEFAULT 0  COMMENT 'Successfully imported transactions',
  `failed_count`    INT           NOT NULL DEFAULT 0  COMMENT 'Transactions that failed validation',
  `skipped_count`   INT           NOT NULL DEFAULT 0  COMMENT 'Duplicates skipped',
  `status`          ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
  `error_log`       TEXT          DEFAULT NULL,
  `rollback_data`   LONGTEXT      DEFAULT NULL COMMENT 'JSON — undo last import ke liye',
  `imported_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_il_user`   (`user_id`),
  KEY `idx_il_format` (`format`),
  CONSTRAINT `fk_il_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Import history — kaunsa file kab import hua, kitne records, rollback data';

-- ── Cron schedule for TER snapshot (monthly) ─────────────────────────────
-- 0 3 1 * * php /var/www/html/wealthdash/cron/snapshot_expense_ratio.php >> /var/log/wd_ter.log 2>&1

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'expense_ratio_history table created ✅' AS e_status;
SELECT 'import_logs table created ✅'            AS i_status;
