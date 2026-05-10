-- ============================================================
-- WealthDash — Migration t394: Sovereign Gold Bonds (SGB)
-- Task: t394 — RBI Gold Bond API (live price fetch for SGBs)
-- ============================================================

CREATE TABLE IF NOT EXISTS `sgb_holdings` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`       INT UNSIGNED NOT NULL,
  `series_name`        VARCHAR(100) NOT NULL COMMENT 'e.g. SGB 2019-20 Series I',
  `tranche_code`       VARCHAR(30)  DEFAULT NULL COMMENT 'RBI tranche code e.g. SGB2019-20S1',
  `issue_date`         DATE         NOT NULL,
  `maturity_date`      DATE         NOT NULL,
  `units`              DECIMAL(12,4) NOT NULL COMMENT 'Number of grams (1 unit = 1 gram)',
  `issue_price`        DECIMAL(10,2) NOT NULL COMMENT 'Price per unit at issue (₹)',
  `total_invested`     DECIMAL(14,2) NOT NULL COMMENT 'units × issue_price',
  `coupon_rate`        DECIMAL(5,2) NOT NULL DEFAULT 2.50 COMMENT 'Annual interest rate %',
  `current_nav`        DECIMAL(10,2) DEFAULT NULL COMMENT 'Current gold price per gram (₹)',
  `current_value`      DECIMAL(14,2) DEFAULT NULL COMMENT 'units × current_nav',
  `nav_updated_at`     DATETIME     DEFAULT NULL,
  `nse_symbol`         VARCHAR(30)  DEFAULT NULL COMMENT 'NSE trading symbol if listed',
  `nse_price`          DECIMAL(10,2) DEFAULT NULL COMMENT 'NSE market price per unit',
  `nse_updated_at`     DATETIME     DEFAULT NULL,
  `interest_payout`    ENUM('semi-annual','annual') DEFAULT 'semi-annual',
  `last_interest_date` DATE         DEFAULT NULL,
  `total_interest_received` DECIMAL(14,2) DEFAULT 0.00,
  `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`              TEXT         DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sgb_portfolio`  (`portfolio_id`),
  INDEX `idx_sgb_maturity`   (`maturity_date`),
  INDEX `idx_sgb_series`     (`series_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sovereign Gold Bond holdings — t394';

-- SGB interest payouts log
CREATE TABLE IF NOT EXISTS `sgb_interest_log` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sgb_id`       INT UNSIGNED NOT NULL,
  `payout_date`  DATE         NOT NULL,
  `units`        DECIMAL(12,4) NOT NULL,
  `rate_pct`     DECIMAL(5,2) NOT NULL,
  `amount`       DECIMAL(12,2) NOT NULL,
  `notes`        VARCHAR(255) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sgb_interest_sgb` (`sgb_id`),
  INDEX `idx_sgb_interest_date` (`payout_date`),
  CONSTRAINT `fk_sgb_interest_sgb` FOREIGN KEY (`sgb_id`) REFERENCES `sgb_holdings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='SGB semi-annual interest payout history';

-- Gold price cache (shared across gold modules)
CREATE TABLE IF NOT EXISTS `gold_price_cache` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`        VARCHAR(30)  NOT NULL DEFAULT 'ibja' COMMENT 'ibja|mcx|fallback',
  `price_24k_gram` DECIMAL(10,2) NOT NULL COMMENT 'Price per gram 24K (₹)',
  `price_22k_gram` DECIMAL(10,2) DEFAULT NULL,
  `price_18k_gram` DECIMAL(10,2) DEFAULT NULL,
  `fetched_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_for`      DATE     NOT NULL COMMENT 'Price date',
  PRIMARY KEY (`id`),
  INDEX `idx_gold_cache_date` (`date_for`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Gold spot price cache for SGBs and physical gold valuation';

SELECT 'SGB holdings + interest log + gold price cache tables created — t394' AS status;
