-- t466: Net Worth Including Real Estate
-- t465: Physical Gold & Jewelry
-- Creates real_estate and physical_gold tables

-- ============================================================
-- TABLE: real_estate (t466)
-- ============================================================
CREATE TABLE IF NOT EXISTS `real_estate` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`        int(10) UNSIGNED NOT NULL,
  `property_name`       varchar(150) NOT NULL COMMENT 'e.g. 2BHK Malviya Nagar',
  `property_type`       enum('residential','commercial','plot','agricultural','other') NOT NULL DEFAULT 'residential',
  `address`             text DEFAULT NULL,
  `city`                varchar(80) DEFAULT NULL,
  `state`               varchar(80) DEFAULT NULL,
  `purchase_date`       date NOT NULL,
  `purchase_price`      decimal(18,2) NOT NULL COMMENT 'Total cost including stamp duty, registration',
  `current_value`       decimal(18,2) NOT NULL COMMENT 'Self-assessed current market value',
  `last_valued_date`    date DEFAULT NULL COMMENT 'When was current_value last updated',
  `is_self_occupied`    tinyint(1) NOT NULL DEFAULT 0,
  `monthly_rental`      decimal(12,2) DEFAULT NULL COMMENT 'Monthly rental income (if let out)',
  `annual_expenses`     decimal(12,2) DEFAULT NULL COMMENT 'Annual maintenance, property tax, etc.',
  `outstanding_loan`    decimal(18,2) DEFAULT NULL COMMENT 'Linked home loan outstanding (manual)',
  `loan_account_id`     int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to loan_accounts if available',
  `ownership_pct`       decimal(5,2) NOT NULL DEFAULT 100.00 COMMENT 'Your ownership percentage',
  `notes`               text DEFAULT NULL,
  `is_active`           tinyint(1) NOT NULL DEFAULT 1,
  `created_at`          datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`          datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_re_portfolio`   (`portfolio_id`),
  KEY `idx_re_type`        (`property_type`),
  KEY `idx_re_active`      (`is_active`),
  CONSTRAINT `fk_re_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='t466: Real estate holdings';

-- ============================================================
-- TABLE: physical_gold (t465)
-- ============================================================
CREATE TABLE IF NOT EXISTS `physical_gold` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`    int(10) UNSIGNED NOT NULL,
  `description`     varchar(150) NOT NULL COMMENT 'e.g. 22K Necklace, Gold Coin 10g',
  `gold_type`       enum('jewellery','coin','bar','biscuit','other') NOT NULL DEFAULT 'jewellery',
  `purity_karat`    tinyint(3) UNSIGNED NOT NULL DEFAULT 22 COMMENT '14,18,22,24',
  `weight_grams`    decimal(10,3) NOT NULL COMMENT 'Net gold weight in grams',
  `purchase_date`   date DEFAULT NULL,
  `purchase_price`  decimal(14,2) DEFAULT NULL COMMENT 'Total cost paid',
  `purchase_rate`   decimal(10,2) DEFAULT NULL COMMENT 'Rate per gram at purchase',
  `current_rate`    decimal(10,2) DEFAULT NULL COMMENT 'Current rate per gram (manual update)',
  `current_value`   decimal(14,2) DEFAULT NULL COMMENT 'Computed or manual; weight * current_rate',
  `storage`         enum('home','locker','bank','other') NOT NULL DEFAULT 'home',
  `is_insured`      tinyint(1) NOT NULL DEFAULT 0,
  `notes`           text DEFAULT NULL,
  `is_active`       tinyint(1) NOT NULL DEFAULT 1,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pg_portfolio` (`portfolio_id`),
  KEY `idx_pg_active`    (`is_active`),
  CONSTRAINT `fk_pg_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='t465: Physical gold and jewelry holdings';
