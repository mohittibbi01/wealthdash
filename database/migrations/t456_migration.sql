-- WealthDash Migration t456: US Stock Holdings — NYSE/NASDAQ Portfolio Tracker

CREATE TABLE IF NOT EXISTS `us_stock_master` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `symbol`              VARCHAR(20)  NOT NULL,
    `exchange`            ENUM('NYSE','NASDAQ','AMEX','OTC') NOT NULL DEFAULT 'NASDAQ',
    `company_name`        VARCHAR(200) DEFAULT NULL,
    `isin`                VARCHAR(20)  DEFAULT NULL,
    `sector`              VARCHAR(100) DEFAULT NULL,
    `industry`            VARCHAR(100) DEFAULT NULL,
    `country`             VARCHAR(50)  DEFAULT 'US',
    `currency`            VARCHAR(5)   NOT NULL DEFAULT 'USD',
    `latest_price_usd`    DECIMAL(14,4) DEFAULT 0,
    `latest_price_inr`    DECIMAL(14,4) DEFAULT 0,
    `pe_ratio`            DECIMAL(12,4) DEFAULT NULL,
    `pb_ratio`            DECIMAL(10,4) DEFAULT NULL,
    `eps_ttm`             DECIMAL(12,4) DEFAULT NULL,
    `market_cap_usd`      DECIMAL(22,4) DEFAULT NULL,
    `high_52_usd`         DECIMAL(14,4) DEFAULT NULL,
    `low_52_usd`          DECIMAL(14,4) DEFAULT NULL,
    `dividend_yield`      DECIMAL(8,4)  DEFAULT NULL,
    `beta`                DECIMAL(8,4)  DEFAULT NULL,
    `price_change_24h_pct`DECIMAL(8,4)  DEFAULT NULL,
    `price_updated_at`    DATETIME      DEFAULT NULL,
    `fundamentals_updated_at` DATETIME  DEFAULT NULL,
    `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usm_symbol` (`symbol`, `exchange`),
    INDEX `idx_usm_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `us_stock_holdings` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `stock_id`        INT UNSIGNED NOT NULL  COMMENT 'FK us_stock_master.id',
    `quantity`        DECIMAL(16,6) NOT NULL DEFAULT 0  COMMENT 'Fractional shares supported',
    `avg_buy_price_usd`DECIMAL(14,4) NOT NULL DEFAULT 0,
    `avg_buy_price_inr`DECIMAL(14,4) DEFAULT NULL       COMMENT 'INR equivalent at time of buy',
    `total_invested_usd`DECIMAL(18,4) DEFAULT 0,
    `total_invested_inr`DECIMAL(18,4) DEFAULT 0,
    `current_value_usd` DECIMAL(18,4) DEFAULT 0,
    `current_value_inr` DECIMAL(18,4) DEFAULT 0,
    `broker`           VARCHAR(50)  DEFAULT NULL         COMMENT 'Groww, Vested, INDmoney, etc.',
    `account_type`     ENUM('LRS','GIFT_CITY','DOMESTIC_BROKER','OTHER') DEFAULT 'LRS',
    `first_buy_date`   DATE DEFAULT NULL,
    `notes`            TEXT DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ush_portfolio_stock` (`portfolio_id`, `stock_id`),
    INDEX `idx_ush_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `us_stock_transactions` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `stock_id`        INT UNSIGNED NOT NULL,
    `type`            ENUM('buy','sell','dividend','transfer_in','transfer_out') NOT NULL DEFAULT 'buy',
    `quantity`        DECIMAL(16,6) NOT NULL,
    `price_usd`       DECIMAL(14,4) NOT NULL DEFAULT 0,
    `price_inr`       DECIMAL(14,4) DEFAULT NULL,
    `total_usd`       DECIMAL(18,4) DEFAULT 0,
    `total_inr`       DECIMAL(18,4) DEFAULT 0,
    `usd_inr_rate`    DECIMAL(10,4) DEFAULT NULL         COMMENT 'Exchange rate at transaction time',
    `fee_usd`         DECIMAL(10,4) DEFAULT 0,
    `broker`          VARCHAR(50)   DEFAULT NULL,
    `account_type`    ENUM('LRS','GIFT_CITY','DOMESTIC_BROKER','OTHER') DEFAULT 'LRS',
    `txn_date`        DATE          NOT NULL,
    `notes`           TEXT          DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ust_portfolio` (`portfolio_id`),
    INDEX `idx_ust_stock`     (`stock_id`),
    INDEX `idx_ust_date`      (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LRS (Liberalised Remittance Scheme) tracking per financial year
CREATE TABLE IF NOT EXISTS `us_lrs_tracker` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `financial_year`  VARCHAR(10)  NOT NULL  COMMENT 'e.g. FY2024-25',
    `remitted_usd`    DECIMAL(14,4) NOT NULL DEFAULT 0,
    `remitted_inr`    DECIMAL(18,4) NOT NULL DEFAULT 0,
    `limit_usd`       DECIMAL(14,4) NOT NULL DEFAULT 250000  COMMENT 'RBI LRS limit: $250,000/yr',
    `notes`           TEXT DEFAULT NULL,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ulrs_user_fy` (`user_id`, `financial_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- USD/INR rate cache
CREATE TABLE IF NOT EXISTS `usd_inr_rate_cache` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rate`        DECIMAL(10,4) NOT NULL,
    `source`      VARCHAR(30)   NOT NULL,
    `date_for`    DATE          NOT NULL,
    `fetched_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_urc_date` (`date_for`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't456_migration: US Stock tables ready' AS status;
