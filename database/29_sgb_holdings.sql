-- ============================================================
-- WealthDash — Migration 029: Sovereign Gold Bonds (SGB)
-- Task: t394 — RBI Gold Bond API (live price fetch)
-- NOTE: Full schema is in database/migrations/t394_sgb_holdings.sql
-- ============================================================

-- sgb_holdings table
CREATE TABLE IF NOT EXISTS `sgb_holdings` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`       INT UNSIGNED NOT NULL,
  `series_name`        VARCHAR(100) NOT NULL,
  `tranche_code`       VARCHAR(30)  DEFAULT NULL,
  `issue_date`         DATE         NOT NULL,
  `maturity_date`      DATE         NOT NULL,
  `units`              DECIMAL(12,4) NOT NULL,
  `issue_price`        DECIMAL(10,2) NOT NULL,
  `total_invested`     DECIMAL(14,2) NOT NULL,
  `coupon_rate`        DECIMAL(5,2) NOT NULL DEFAULT 2.50,
  `current_nav`        DECIMAL(10,2) DEFAULT NULL,
  `current_value`      DECIMAL(14,2) DEFAULT NULL,
  `nav_updated_at`     DATETIME     DEFAULT NULL,
  `nse_symbol`         VARCHAR(30)  DEFAULT NULL,
  `nse_price`          DECIMAL(10,2) DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SGB interest log
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
  INDEX `idx_sgb_interest_sgb` (`sgb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gold price cache
CREATE TABLE IF NOT EXISTS `gold_price_cache` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`         VARCHAR(30)  NOT NULL DEFAULT 'ibja',
  `price_24k_gram` DECIMAL(10,2) NOT NULL,
  `price_22k_gram` DECIMAL(10,2) DEFAULT NULL,
  `price_18k_gram` DECIMAL(10,2) DEFAULT NULL,
  `fetched_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_for`       DATE     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_gold_cache_date` (`date_for`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'SGB holdings schema created — t394' AS status;
