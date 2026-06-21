-- ============================================================
-- WealthDash — Migration 038: Crypto Holdings (t24)
-- Task: t24 — Crypto tracking basic module
-- Run: php database/migrate.php 38
-- ============================================================

CREATE TABLE IF NOT EXISTS `crypto_holdings` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `portfolio_id`    INT UNSIGNED     NOT NULL,
    `coin_id`         VARCHAR(60)      NOT NULL,          -- CoinGecko ID e.g. "bitcoin"
    `coin_symbol`     VARCHAR(20)      NOT NULL,          -- BTC, ETH, etc.
    `coin_name`       VARCHAR(100)     NOT NULL,          -- Bitcoin, Ethereum
    `quantity`        DECIMAL(24,8)    NOT NULL DEFAULT 0,
    `avg_buy_price`   DECIMAL(20,4)    NOT NULL DEFAULT 0, -- INR per coin at purchase
    `total_invested`  DECIMAL(20,2)    NOT NULL DEFAULT 0, -- quantity × avg_buy_price
    `exchange`        VARCHAR(60)      NULL,               -- WazirX, Binance, CoinDCX
    `wallet_address`  VARCHAR(200)     NULL,
    `notes`           TEXT             NULL,
    `created_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio` (`portfolio_id`),
    KEY `idx_coin`      (`coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_transactions` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `portfolio_id` INT UNSIGNED     NOT NULL,
    `coin_id`      VARCHAR(60)      NOT NULL,
    `coin_symbol`  VARCHAR(20)      NOT NULL,
    `txn_type`     ENUM('BUY','SELL','TRANSFER_IN','TRANSFER_OUT') NOT NULL DEFAULT 'BUY',
    `quantity`     DECIMAL(24,8)    NOT NULL,
    `price_inr`    DECIMAL(20,4)    NOT NULL DEFAULT 0,    -- price per coin in INR at time of txn
    `amount_inr`   DECIMAL(20,2)    NOT NULL DEFAULT 0,    -- total txn value in INR
    `tds_deducted` DECIMAL(10,2)    NOT NULL DEFAULT 0,    -- 1% TDS on sell
    `txn_date`     DATE             NOT NULL,
    `exchange`     VARCHAR(60)      NULL,
    `notes`        TEXT             NULL,
    `created_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio` (`portfolio_id`),
    KEY `idx_coin`      (`coin_id`),
    KEY `idx_date`      (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price cache table (CoinGecko API responses)
CREATE TABLE IF NOT EXISTS `crypto_price_cache` (
    `coin_id`      VARCHAR(60)   NOT NULL,
    `price_inr`    DECIMAL(20,4) NOT NULL DEFAULT 0,
    `price_usd`    DECIMAL(20,4) NOT NULL DEFAULT 0,
    `change_24h`   DECIMAL(8,4)  NULL,
    `market_cap`   BIGINT        NULL,
    `fetched_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Crypto Holdings tables created (t24)' AS status;
