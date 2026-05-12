-- WealthDash Migration t113: Sovereign Gold Bonds (SGB) Tracker
-- Task: t113 — Full SGB tracker with interest log and gold price cache
-- Run ONCE — idempotent via IF NOT EXISTS / ON DUPLICATE KEY

-- ─── sgb_holdings ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sgb_holdings` (
    `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`            INT UNSIGNED NOT NULL,
    `series_name`             VARCHAR(100) NOT NULL              COMMENT 'e.g. SGB 2019-20 Series II',
    `tranche_code`            VARCHAR(30)  DEFAULT NULL,
    `isin`                    VARCHAR(20)  DEFAULT NULL,
    `nse_symbol`              VARCHAR(30)  DEFAULT NULL,
    `issue_date`              DATE         NOT NULL,
    `maturity_date`           DATE         NOT NULL              COMMENT '8 years from issue_date',
    `units`                   DECIMAL(12,4) NOT NULL,
    `issue_price`             DECIMAL(10,2) NOT NULL             COMMENT 'INR per gram at issue',
    `total_invested`          DECIMAL(14,2) NOT NULL,
    `coupon_rate`             DECIMAL(5,2) NOT NULL DEFAULT 2.50 COMMENT '% p.a. on issue price',
    `interest_payout`         ENUM('semi-annual','annual') NOT NULL DEFAULT 'semi-annual',
    `last_interest_date`      DATE         DEFAULT NULL,
    `next_interest_date`      DATE         DEFAULT NULL,
    `total_interest_received` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `current_nav`             DECIMAL(10,2) DEFAULT NULL         COMMENT 'Gold price per gram today',
    `current_value`           DECIMAL(14,2) DEFAULT NULL,
    `nav_updated_at`          DATETIME     DEFAULT NULL,
    `nse_price`               DECIMAL(10,2) DEFAULT NULL         COMMENT 'Exchange-traded price',
    `nse_updated_at`          DATETIME     DEFAULT NULL,
    `tax_exemption`           TINYINT(1)   NOT NULL DEFAULT 1    COMMENT '1=LTCG exempt on maturity',
    `is_active`               TINYINT(1)   NOT NULL DEFAULT 1,
    `notes`                   TEXT         DEFAULT NULL,
    `created_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sgb_portfolio`  (`portfolio_id`),
    INDEX `idx_sgb_maturity`   (`maturity_date`),
    INDEX `idx_sgb_series`     (`series_name`),
    INDEX `idx_sgb_active`     (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── sgb_interest_log ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sgb_interest_log` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sgb_id`       INT UNSIGNED NOT NULL,
    `portfolio_id` INT UNSIGNED NOT NULL,
    `payout_date`  DATE         NOT NULL,
    `period`       VARCHAR(20)  DEFAULT NULL COMMENT 'e.g. HY1 2024-25',
    `units`        DECIMAL(12,4) NOT NULL,
    `rate_pct`     DECIMAL(5,2) NOT NULL,
    `amount`       DECIMAL(12,2) NOT NULL,
    `notes`        VARCHAR(255) DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sgb_interest_sgb`  (`sgb_id`),
    INDEX `idx_sgb_interest_port` (`portfolio_id`),
    INDEX `idx_sgb_interest_date` (`payout_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── gold_price_cache ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `gold_price_cache` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source`         VARCHAR(30)  NOT NULL DEFAULT 'ibja',
    `price_24k_gram` DECIMAL(10,2) NOT NULL,
    `price_22k_gram` DECIMAL(10,2) DEFAULT NULL,
    `price_18k_gram` DECIMAL(10,2) DEFAULT NULL,
    `fetched_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_for`       DATE         NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_gold_cache_date` (`date_for`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't113_migration: SGB tracker tables ready' AS status;
