-- ============================================================
-- t464: Home Loan EMI Tracker
-- Adds rate change history, prepayment log, EMI calendar data
-- ============================================================

-- Step 1: Add home-loan-specific columns to loan_accounts
ALTER TABLE `loan_accounts`
  ADD COLUMN IF NOT EXISTS `property_name`        VARCHAR(150)  NULL COMMENT 'Property / project name' AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `property_address`     TEXT          NULL AFTER `property_name`,
  ADD COLUMN IF NOT EXISTS `co_borrower`          VARCHAR(100)  NULL COMMENT 'Co-borrower name' AFTER `property_address`,
  ADD COLUMN IF NOT EXISTS `loan_sanction_date`   DATE          NULL AFTER `co_borrower`,
  ADD COLUMN IF NOT EXISTS `moratorium_months`    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Construction-linked moratorium period' AFTER `loan_sanction_date`,
  ADD COLUMN IF NOT EXISTS `base_rate_type`       ENUM('MCLR','RLLR','PLR','Fixed') NULL AFTER `moratorium_months`,
  ADD COLUMN IF NOT EXISTS `spread_pct`           DECIMAL(5,3)  NULL COMMENT 'Spread over base rate (%)' AFTER `base_rate_type`,
  ADD COLUMN IF NOT EXISTS `reset_date`           DATE          NULL COMMENT 'Next rate reset date' AFTER `spread_pct`,
  ADD COLUMN IF NOT EXISTS `total_prepaid`        DECIMAL(16,2) NOT NULL DEFAULT 0 COMMENT 'Cumulative prepayments made' AFTER `reset_date`,
  ADD COLUMN IF NOT EXISTS `section_24b_claimed`  DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Sec 24(b) interest claimed (FY)' AFTER `total_prepaid`,
  ADD COLUMN IF NOT EXISTS `section_80c_claimed`  DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Sec 80C principal claimed (FY)' AFTER `section_24b_claimed`;

-- Step 2: Rate change history (floating rate loan resets)
CREATE TABLE IF NOT EXISTS `loan_rate_history` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `loan_id`         INT UNSIGNED    NOT NULL,
  `effective_date`  DATE            NOT NULL,
  `old_rate`        DECIMAL(6,3)    NOT NULL,
  `new_rate`        DECIMAL(6,3)    NOT NULL,
  `new_emi`         DECIMAL(12,2)   NULL COMMENT 'New EMI after reset (if tenure fixed)',
  `new_tenure`      INT             NULL COMMENT 'New tenure after reset (if EMI fixed)',
  `base_rate`       DECIMAL(6,3)    NULL COMMENT 'RBI/bank base rate at this time',
  `reason`          VARCHAR(200)    NULL COMMENT 'RBI policy change, annual reset etc.',
  `notes`           TEXT            NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lrh_loan`  (`loan_id`),
  KEY `idx_lrh_date`  (`effective_date`),
  CONSTRAINT `fk_lrh_loan` FOREIGN KEY (`loan_id`) REFERENCES `loan_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Home loan interest rate change history (t464)';

-- Step 3: Prepayment log
CREATE TABLE IF NOT EXISTS `loan_prepayments` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `loan_id`         INT UNSIGNED    NOT NULL,
  `payment_date`    DATE            NOT NULL,
  `amount`          DECIMAL(14,2)   NOT NULL,
  `mode`            ENUM('full_prepayment','partial_prepayment','balance_transfer') NOT NULL DEFAULT 'partial_prepayment',
  `impact`          ENUM('reduce_tenure','reduce_emi') NOT NULL DEFAULT 'reduce_tenure',
  `emis_saved`      SMALLINT        NULL COMMENT 'Number of EMIs saved (computed)',
  `interest_saved`  DECIMAL(14,2)   NULL COMMENT 'Interest saved (computed)',
  `new_outstanding` DECIMAL(16,2)   NULL COMMENT 'Outstanding after prepayment',
  `new_tenure`      INT             NULL COMMENT 'Remaining tenure after prepayment',
  `new_emi`         DECIMAL(12,2)   NULL COMMENT 'New EMI (if emi was reduced)',
  `penalty_charged` DECIMAL(10,2)   NOT NULL DEFAULT 0,
  `source`          VARCHAR(100)    NULL COMMENT 'Source of funds: savings, bonus, etc.',
  `notes`           TEXT            NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lp_loan`   (`loan_id`),
  KEY `idx_lp_date`   (`payment_date`),
  CONSTRAINT `fk_lp_loan` FOREIGN KEY (`loan_id`) REFERENCES `loan_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Loan prepayment log (t464)';

-- Step 4: Annual tax deduction tracker per FY
CREATE TABLE IF NOT EXISTS `loan_tax_claims` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `loan_id`         INT UNSIGNED    NOT NULL,
  `fy`              CHAR(7)         NOT NULL COMMENT 'FY in format 2024-25',
  `interest_paid`   DECIMAL(14,2)   NOT NULL DEFAULT 0 COMMENT 'Total interest paid this FY',
  `principal_paid`  DECIMAL(14,2)   NOT NULL DEFAULT 0 COMMENT 'Total principal paid this FY',
  `sec_24b_claimed` DECIMAL(14,2)   NOT NULL DEFAULT 0 COMMENT 'Sec 24(b) deduction claimed',
  `sec_80c_claimed` DECIMAL(14,2)   NOT NULL DEFAULT 0 COMMENT 'Sec 80C deduction claimed (principal)',
  `under_construction` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Was property under construction this FY',
  `notes`           TEXT            NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ltc_loan_fy` (`loan_id`, `fy`),
  KEY `idx_ltc_loan` (`loan_id`),
  CONSTRAINT `fk_ltc_loan` FOREIGN KEY (`loan_id`) REFERENCES `loan_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Home loan annual tax claim tracker (t464)';
