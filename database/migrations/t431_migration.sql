-- WealthDash Migration t431: Stock Fundamental Data — Full
-- P/E, P/B, Market Cap, EPS, ROE, ROCE, Debt-to-Equity, promoter holding etc.

-- Extend stock_master with fundamental columns (idempotent)
ALTER TABLE `stock_master`
    ADD COLUMN IF NOT EXISTS `pe_ratio`               DECIMAL(12,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `pb_ratio`               DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `ps_ratio`               DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `ev_ebitda`              DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `market_cap`             DECIMAL(22,4)  DEFAULT NULL  COMMENT 'INR crores',
    ADD COLUMN IF NOT EXISTS `enterprise_value`       DECIMAL(22,4)  DEFAULT NULL  COMMENT 'INR crores',
    ADD COLUMN IF NOT EXISTS `eps_ttm`                DECIMAL(12,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `eps_growth_yoy`         DECIMAL(8,4)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `revenue_ttm`            DECIMAL(22,4)  DEFAULT NULL  COMMENT 'INR crores',
    ADD COLUMN IF NOT EXISTS `net_profit_ttm`         DECIMAL(22,4)  DEFAULT NULL  COMMENT 'INR crores',
    ADD COLUMN IF NOT EXISTS `roe`                    DECIMAL(8,4)   DEFAULT NULL  COMMENT 'Return on Equity %',
    ADD COLUMN IF NOT EXISTS `roce`                   DECIMAL(8,4)   DEFAULT NULL  COMMENT 'Return on Capital Employed %',
    ADD COLUMN IF NOT EXISTS `roa`                    DECIMAL(8,4)   DEFAULT NULL  COMMENT 'Return on Assets %',
    ADD COLUMN IF NOT EXISTS `debt_to_equity`         DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `current_ratio`          DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `quick_ratio`            DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `interest_coverage`      DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `dividend_yield`         DECIMAL(8,4)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `dividend_payout_ratio`  DECIMAL(8,4)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `book_value`             DECIMAL(14,4)  DEFAULT NULL  COMMENT 'Per share',
    ADD COLUMN IF NOT EXISTS `face_value`             DECIMAL(10,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `high_52`                DECIMAL(14,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `low_52`                 DECIMAL(14,4)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `beta`                   DECIMAL(8,4)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `avg_volume_30d`         BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `shares_outstanding`     BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `float_shares`           BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `promoter_holding_pct`   DECIMAL(6,3)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `fii_holding_pct`        DECIMAL(6,3)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `dii_holding_pct`        DECIMAL(6,3)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `public_holding_pct`     DECIMAL(6,3)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `sector`                 VARCHAR(100)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `industry`               VARCHAR(100)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `fundamentals_source`    VARCHAR(30)    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `fundamentals_updated_at`DATETIME       DEFAULT NULL;

-- Historical fundamentals snapshots (quarterly)
CREATE TABLE IF NOT EXISTS `stock_fundamentals_history` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `stock_id`         INT UNSIGNED NOT NULL  COMMENT 'FK stock_master.id',
    `period`           VARCHAR(10)  NOT NULL  COMMENT 'e.g. Q3FY25, FY2024',
    `period_type`      ENUM('quarterly','annual') NOT NULL DEFAULT 'quarterly',
    `pe_ratio`         DECIMAL(12,4) DEFAULT NULL,
    `pb_ratio`         DECIMAL(10,4) DEFAULT NULL,
    `market_cap`       DECIMAL(22,4) DEFAULT NULL,
    `eps`              DECIMAL(12,4) DEFAULT NULL,
    `revenue`          DECIMAL(22,4) DEFAULT NULL,
    `net_profit`       DECIMAL(22,4) DEFAULT NULL,
    `roe`              DECIMAL(8,4)  DEFAULT NULL,
    `roce`             DECIMAL(8,4)  DEFAULT NULL,
    `debt_to_equity`   DECIMAL(10,4) DEFAULT NULL,
    `promoter_holding` DECIMAL(6,3)  DEFAULT NULL,
    `fii_holding`      DECIMAL(6,3)  DEFAULT NULL,
    `source`           VARCHAR(30)   DEFAULT NULL,
    `fetched_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sfh_stock_period` (`stock_id`, `period`, `period_type`),
    INDEX `idx_sfh_stock` (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock screener saved filters
CREATE TABLE IF NOT EXISTS `stock_screener_filters` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `name`        VARCHAR(100) NOT NULL,
    `filter_json` JSON         NOT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ssf_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't431_migration: Fundamentals columns + history table ready' AS status;
