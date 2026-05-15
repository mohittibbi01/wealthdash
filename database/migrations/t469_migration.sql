-- ============================================================
-- WealthDash — Migration t469: EPF Tax Tracker
-- Task: Taxable EPF contribution alert (Budget 2021)
-- Run ONCE — idempotent
-- ============================================================

-- EPF Tax Rules (Budget 2021, effective FY2021-22 onwards):
--  - Employee contribution (EPF+VPF) > ₹2,50,000/yr → interest on EXCESS is taxable
--  - Tax on excess interest at slab rate (IFOS)
--  - Government employee threshold: ₹5,00,000/yr (no employer contribution)
--  - Employer contribution > ₹7,50,000/yr (EPF+NPS+Gratuity combined) → taxable as perquisite

-- Annual EPF tax summary per account per FY
CREATE TABLE IF NOT EXISTS `epf_tax_log` (
  `id`                      int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`          int(10) UNSIGNED NOT NULL,
  `fy_year`                 smallint(4)      NOT NULL COMMENT 'FY start year e.g. 2024 for FY24-25',
  `annual_ee_contribution`  decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Total employee EPF+VPF contribution this FY',
  `annual_er_contribution`  decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Total employer EPF contribution this FY',
  `threshold_ee`            decimal(14,2)    NOT NULL DEFAULT 250000.00
    COMMENT '2.5L normal / 5L for govt employees',
  `taxable_ee_excess`       decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Employee contribution above threshold',
  `epf_interest_fy`         decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Total interest credited this FY',
  `taxable_interest`        decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Interest on excess taxable contribution',
  `estimated_tax_30pct`     decimal(12,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Estimated tax @ 30% slab (worst case)',
  `is_govt_employee`        tinyint(1)       NOT NULL DEFAULT 0
    COMMENT '1 = govt employee, threshold is 5L',
  `notes`                   varchar(255)     DEFAULT NULL,
  `created_at`              datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`              datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_tax_fy` (`epf_account_id`, `fy_year`),
  KEY `idx_epf_tax_account` (`epf_account_id`),
  CONSTRAINT `fk_epf_tax_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't469 EPF Tax Tracker migration complete ✅' AS status;
