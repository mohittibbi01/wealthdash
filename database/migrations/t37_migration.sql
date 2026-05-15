-- WealthDash Migration t37: ETF — Separate Module

CREATE TABLE IF NOT EXISTS `etf_master` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `symbol`              VARCHAR(20)  NOT NULL,
    `exchange`            VARCHAR(10)  NOT NULL DEFAULT 'NSE',
    `isin`                VARCHAR(20)  DEFAULT NULL,
    `scheme_name`         VARCHAR(200) NOT NULL,
    `amc`                 VARCHAR(100) DEFAULT NULL,
    `category`            VARCHAR(100) DEFAULT NULL  COMMENT 'Equity, Debt, Gold, International, etc.',
    `sub_category`        VARCHAR(100) DEFAULT NULL  COMMENT 'Nifty 50, Banking, IT, etc.',
    `underlying_index`    VARCHAR(100) DEFAULT NULL,
    `benchmark`           VARCHAR(100) DEFAULT NULL,
    `latest_price`        DECIMAL(14,4) DEFAULT 0,
    `nav`                 DECIMAL(14,4) DEFAULT NULL COMMENT 'Declared NAV if different from price',
    `price_change_1d`     DECIMAL(10,4) DEFAULT NULL,
    `price_change_1d_pct` DECIMAL(8,4)  DEFAULT NULL,
    `expense_ratio`       DECIMAL(6,4)  DEFAULT NULL COMMENT 'Annual TER %',
    `tracking_error`      DECIMAL(8,4)  DEFAULT NULL,
    `aum_cr`              DECIMAL(14,4) DEFAULT NULL COMMENT 'AUM in INR crores',
    `avg_volume`          BIGINT UNSIGNED DEFAULT NULL,
    `high_52`             DECIMAL(14,4) DEFAULT NULL,
    `low_52`              DECIMAL(14,4) DEFAULT NULL,
    `dividend_yield`      DECIMAL(8,4)  DEFAULT NULL,
    `returns_1y`          DECIMAL(8,4)  DEFAULT NULL,
    `returns_3y`          DECIMAL(8,4)  DEFAULT NULL,
    `returns_5y`          DECIMAL(8,4)  DEFAULT NULL,
    `is_gold_etf`         TINYINT(1)    DEFAULT 0,
    `is_international`    TINYINT(1)    DEFAULT 0,
    `price_updated_at`    DATETIME      DEFAULT NULL,
    `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_etf_symbol` (`symbol`, `exchange`),
    INDEX `idx_etf_isin`     (`isin`),
    INDEX `idx_etf_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `etf_holdings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`     INT UNSIGNED NOT NULL,
    `etf_id`           INT UNSIGNED NOT NULL,
    `quantity`         DECIMAL(16,4) NOT NULL DEFAULT 0,
    `avg_buy_price`    DECIMAL(14,4) NOT NULL DEFAULT 0,
    `total_invested`   DECIMAL(18,4) DEFAULT 0,
    `current_value`    DECIMAL(18,4) DEFAULT 0,
    `first_buy_date`   DATE          DEFAULT NULL,
    `broker`           VARCHAR(50)   DEFAULT NULL,
    `notes`            TEXT          DEFAULT NULL,
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_eh_portfolio_etf` (`portfolio_id`, `etf_id`),
    INDEX `idx_eh_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `etf_transactions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`  INT UNSIGNED NOT NULL,
    `etf_id`        INT UNSIGNED NOT NULL,
    `type`          ENUM('buy','sell','dividend') NOT NULL DEFAULT 'buy',
    `quantity`      DECIMAL(16,4) NOT NULL,
    `price`         DECIMAL(14,4) NOT NULL DEFAULT 0,
    `total_value`   DECIMAL(18,4) DEFAULT 0,
    `brokerage`     DECIMAL(10,4) DEFAULT 0,
    `broker`        VARCHAR(50)   DEFAULT NULL,
    `txn_date`      DATE          NOT NULL,
    `notes`         TEXT          DEFAULT NULL,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_et_portfolio` (`portfolio_id`),
    INDEX `idx_et_etf`       (`etf_id`),
    INDEX `idx_et_date`      (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ETF SIP tracker
CREATE TABLE IF NOT EXISTS `etf_sip` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `etf_id`          INT UNSIGNED NOT NULL,
    `monthly_amount`  DECIMAL(12,4) NOT NULL,
    `sip_date`        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Day of month 1-28',
    `start_date`      DATE          NOT NULL,
    `end_date`        DATE          DEFAULT NULL,
    `broker`          VARCHAR(50)   DEFAULT NULL,
    `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
    `last_executed`   DATE          DEFAULT NULL,
    `total_invested`  DECIMAL(18,4) DEFAULT 0,
    `installments`    INT UNSIGNED  DEFAULT 0,
    `notes`           TEXT          DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_esip_portfolio` (`portfolio_id`),
    INDEX `idx_esip_active`    (`is_active`, `sip_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't37_migration: ETF module tables ready' AS status;
