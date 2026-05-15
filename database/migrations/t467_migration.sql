-- ============================================================
-- WealthDash — Migration t467: EPF Balance Tracker
-- Task: Monthly contribution log + balance history
-- Run ONCE — idempotent (IF NOT EXISTS + ALTER IF NOT EXISTS)
-- ============================================================

-- 1. Extend epf_accounts with fields used by epf_list.php + t467
ALTER TABLE `epf_accounts`
  ADD COLUMN IF NOT EXISTS `basic_salary`           decimal(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Current basic salary (EPF contribution base)'
    AFTER `employer_name`,
  ADD COLUMN IF NOT EXISTS `employee_contribution`  decimal(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Monthly employee EPF contribution (12% of basic)'
    AFTER `basic_salary`,
  ADD COLUMN IF NOT EXISTS `employer_contribution`  decimal(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Monthly employer EPF contribution (3.67% of basic)'
    AFTER `employee_contribution`,
  ADD COLUMN IF NOT EXISTS `eps_contribution`       decimal(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Monthly EPS contribution (8.33% of basic, max ₹15K base)'
    AFTER `employer_contribution`,
  ADD COLUMN IF NOT EXISTS `vpf_rate`               decimal(5,2)  NOT NULL DEFAULT 0.00
    COMMENT 'VPF % of basic (voluntary, over 12%)'
    AFTER `eps_contribution`,
  ADD COLUMN IF NOT EXISTS `joining_date`           date DEFAULT NULL
    COMMENT 'Date of joining employer (for service years)'
    AFTER `vpf_rate`,
  ADD COLUMN IF NOT EXISTS `current_balance`        decimal(16,2) NOT NULL DEFAULT 0.00
    COMMENT 'Latest known EPF balance (sync with EPFO passbook)'
    AFTER `joining_date`,
  ADD COLUMN IF NOT EXISTS `eps_balance`            decimal(16,2) NOT NULL DEFAULT 0.00
    COMMENT 'EPS balance (pension component)'
    AFTER `current_balance`,
  ADD COLUMN IF NOT EXISTS `is_active`              tinyint(1)    NOT NULL DEFAULT 1
    AFTER `eps_balance`;

-- 2. Monthly contribution log (passbook-style)
CREATE TABLE IF NOT EXISTS `epf_monthly_log` (
  `id`                   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`       int(10) UNSIGNED NOT NULL,
  `log_month`            date             NOT NULL COMMENT 'YYYY-MM-01',
  `basic_salary`         decimal(14,2)    NOT NULL DEFAULT 0.00,
  `employee_contribution`decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'EPF employee share',
  `employer_contribution`decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'Employer EPF (3.67%)',
  `eps_contribution`     decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'EPS share (8.33%)',
  `vpf_contribution`     decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'VPF (voluntary)',
  `total_credit`         decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Employee+Employer+VPF',
  `balance_after`        decimal(16,2)    DEFAULT NULL           COMMENT 'EPF balance after this month (if known)',
  `interest_credited`    decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Interest credited this month (March credit)',
  `source`               enum('manual','epfo_sync','import') NOT NULL DEFAULT 'manual',
  `notes`                varchar(255)     DEFAULT NULL,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`           datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_log_month` (`epf_account_id`, `log_month`),
  KEY `idx_epfl_account` (`epf_account_id`),
  KEY `idx_epfl_month`   (`log_month`),
  CONSTRAINT `fk_epfl_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Annual FY balance snapshots (for graph + interest reconciliation)
CREATE TABLE IF NOT EXISTS `epf_fy_snapshot` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`   int(10) UNSIGNED NOT NULL,
  `fy_year`          smallint(4)      NOT NULL COMMENT 'FY start year e.g. 2024 for FY24-25',
  `opening_balance`  decimal(16,2)    NOT NULL DEFAULT 0.00,
  `closing_balance`  decimal(16,2)    NOT NULL DEFAULT 0.00,
  `total_ee_contrib` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `total_er_contrib` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `total_vpf`        decimal(14,2)    NOT NULL DEFAULT 0.00,
  `interest_credited`decimal(14,2)    NOT NULL DEFAULT 0.00,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_snap_fy` (`epf_account_id`, `fy_year`),
  KEY `idx_epfs_account` (`epf_account_id`),
  CONSTRAINT `fk_epfs_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't467 EPF Balance Tracker migration complete ✅' AS status;
