-- ============================================================
-- WealthDash — Migration t46: EPF Monthly Contribution Tracker
-- Task: Monthly EPF entry log + salary change history
-- Run ONCE — idempotent
-- Depends on: t467_migration.sql (epf_monthly_log must exist)
-- ============================================================

-- 1. EPF salary change log (for tracking increments + VPF changes)
CREATE TABLE IF NOT EXISTS `epf_salary_log` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`   int(10) UNSIGNED NOT NULL,
  `basic_salary`     decimal(14,2)    NOT NULL,
  `effective_date`   date             NOT NULL,
  `vpf_rate`         decimal(5,2)     NOT NULL DEFAULT 0.00,
  `notes`            varchar(255)     DEFAULT NULL,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salary_log_date` (`epf_account_id`, `effective_date`),
  KEY `idx_sl_account` (`epf_account_id`),
  CONSTRAINT `fk_sl_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure epf_monthly_log.total_credit column exists (in case t467 not yet run)
CREATE TABLE IF NOT EXISTS `epf_monthly_log` (
  `id`                    int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`        int(10) UNSIGNED NOT NULL,
  `log_month`             date             NOT NULL COMMENT 'YYYY-MM-01',
  `basic_salary`          decimal(14,2)    NOT NULL DEFAULT 0.00,
  `employee_contribution` decimal(10,2)    NOT NULL DEFAULT 0.00,
  `employer_contribution` decimal(10,2)    NOT NULL DEFAULT 0.00,
  `eps_contribution`      decimal(10,2)    NOT NULL DEFAULT 0.00,
  `vpf_contribution`      decimal(10,2)    NOT NULL DEFAULT 0.00,
  `total_credit`          decimal(12,2)    NOT NULL DEFAULT 0.00,
  `balance_after`         decimal(16,2)    DEFAULT NULL,
  `interest_credited`     decimal(12,2)    NOT NULL DEFAULT 0.00,
  `source`                enum('manual','epfo_sync','import') NOT NULL DEFAULT 'manual',
  `notes`                 varchar(255)     DEFAULT NULL,
  `created_at`            datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`            datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_log_month` (`epf_account_id`, `log_month`),
  KEY `idx_epfl_account` (`epf_account_id`),
  KEY `idx_epfl_month`   (`log_month`),
  CONSTRAINT `fk_epfl_t46_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure required columns on epf_accounts (idempotent)
ALTER TABLE `epf_accounts`
  ADD COLUMN IF NOT EXISTS `basic_salary`          decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `employer_name`,
  ADD COLUMN IF NOT EXISTS `employee_contribution` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `basic_salary`,
  ADD COLUMN IF NOT EXISTS `employer_contribution` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `employee_contribution`,
  ADD COLUMN IF NOT EXISTS `eps_contribution`      decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `employer_contribution`,
  ADD COLUMN IF NOT EXISTS `vpf_rate`              decimal(5,2)  NOT NULL DEFAULT 0.00 AFTER `eps_contribution`,
  ADD COLUMN IF NOT EXISTS `joining_date`          date DEFAULT NULL AFTER `vpf_rate`,
  ADD COLUMN IF NOT EXISTS `current_balance`       decimal(16,2) NOT NULL DEFAULT 0.00 AFTER `joining_date`,
  ADD COLUMN IF NOT EXISTS `eps_balance`           decimal(16,2) NOT NULL DEFAULT 0.00 AFTER `current_balance`,
  ADD COLUMN IF NOT EXISTS `is_active`             tinyint(1) NOT NULL DEFAULT 1 AFTER `eps_balance`,
  ADD COLUMN IF NOT EXISTS `last_updated`          date DEFAULT NULL AFTER `is_active`;

SELECT 't46 EPF Monthly Tracker migration complete ✅' AS status;
