-- WealthDash t45: Recurring Deposit (RD) Tracker
CREATE TABLE IF NOT EXISTS `rd_accounts` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`        int(10) UNSIGNED NOT NULL,
  `bank_name`           varchar(100)     NOT NULL,
  `account_number`      varchar(30)      DEFAULT NULL,
  `monthly_installment` decimal(12,2)    NOT NULL,
  `interest_rate`       decimal(5,2)     NOT NULL,
  `tenure_months`       smallint         NOT NULL,
  `start_date`          date             NOT NULL,
  `maturity_date`       date             NOT NULL,
  `maturity_amount`     decimal(16,2)    DEFAULT NULL,
  `status`              enum('active','matured','closed') NOT NULL DEFAULT 'active',
  `nominee`             varchar(100)     DEFAULT NULL,
  `notes`               varchar(255)     DEFAULT NULL,
  `created_at`          datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`          datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rd_portfolio` (`portfolio_id`),
  KEY `idx_rd_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rd_installments` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rd_account_id`  int(10) UNSIGNED NOT NULL,
  `due_date`       date             NOT NULL,
  `paid_date`      date             DEFAULT NULL,
  `amount`         decimal(12,2)    NOT NULL,
  `status`         enum('paid','pending','missed') NOT NULL DEFAULT 'pending',
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_due` (`rd_account_id`,`due_date`),
  KEY `idx_rdi_account` (`rd_account_id`),
  CONSTRAINT `fk_rdi_account` FOREIGN KEY (`rd_account_id`) REFERENCES `rd_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't45 RD Tracker migration complete ✅' AS status;
