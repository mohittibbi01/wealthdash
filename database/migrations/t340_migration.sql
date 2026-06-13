-- WealthDash t340: EPFO UAN Integration
-- Uses epf_monthly_log (t467). Adds last_updated to epf_accounts.
ALTER TABLE `epf_accounts`
  ADD COLUMN IF NOT EXISTS `last_updated` date DEFAULT NULL AFTER `is_active`;
CREATE TABLE IF NOT EXISTS `epf_monthly_log` (
  `id`                    int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`        int(10) UNSIGNED NOT NULL,
  `log_month`             date NOT NULL COMMENT 'YYYY-MM-01',
  `basic_salary`          decimal(14,2) NOT NULL DEFAULT 0.00,
  `employee_contribution` decimal(10,2) NOT NULL DEFAULT 0.00,
  `employer_contribution` decimal(10,2) NOT NULL DEFAULT 0.00,
  `eps_contribution`      decimal(10,2) NOT NULL DEFAULT 0.00,
  `vpf_contribution`      decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_credit`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_after`         decimal(16,2) DEFAULT NULL,
  `interest_credited`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `source`                enum('manual','epfo_sync','import') NOT NULL DEFAULT 'manual',
  `notes`                 varchar(255) DEFAULT NULL,
  `created_at`            datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`            datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_log_month` (`epf_account_id`,`log_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't340 EPFO UAN Integration migration complete ✅' AS status;
