-- ============================================================
-- WealthDash — Migration t315: Crypto Holdings Full Tracking
-- Task   : t315 — Crypto Holdings — Full portfolio tracking
-- Tables : crypto_staking_rewards, crypto_watchlist
-- Run    : php database/migrate.php t315
-- ============================================================

-- ── Staking / Yield rewards tracker ─────────────────────────
CREATE TABLE IF NOT EXISTS `crypto_staking_rewards` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `portfolio_id`   INT UNSIGNED      NOT NULL,
    `coin_id`        VARCHAR(60)       NOT NULL,           -- CoinGecko ID
    `coin_symbol`    VARCHAR(20)       NOT NULL,           -- BTC, ETH …
    `reward_type`    ENUM('STAKING','YIELD','AIRDROP','MINING','INTEREST') NOT NULL DEFAULT 'STAKING',
    `quantity`       DECIMAL(24,8)     NOT NULL DEFAULT 0,
    `price_inr`      DECIMAL(20,4)     NOT NULL DEFAULT 0, -- INR price at reward date
    `value_inr`      DECIMAL(20,2)     NOT NULL DEFAULT 0, -- quantity × price_inr
    `platform`       VARCHAR(80)       NULL,               -- Binance Earn, WazirX etc.
    `reward_date`    DATE              NOT NULL,
    `notes`          TEXT              NULL,
    `created_at`     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio` (`portfolio_id`),
    KEY `idx_coin`      (`coin_id`),
    KEY `idx_date`      (`reward_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Crypto price watchlist ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `crypto_watchlist` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED      NOT NULL,
    `coin_id`        VARCHAR(60)       NOT NULL,
    `coin_symbol`    VARCHAR(20)       NOT NULL,
    `coin_name`      VARCHAR(100)      NOT NULL,
    `alert_high`     DECIMAL(20,4)     NULL,               -- alert when price > this (INR)
    `alert_low`      DECIMAL(20,4)     NULL,               -- alert when price < this (INR)
    `notes`          VARCHAR(255)      NULL,
    `created_at`     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_coin` (`user_id`, `coin_id`),
    KEY `idx_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Add wallet_tag column if not present (schema guard) ──────
ALTER TABLE `crypto_holdings`
    ADD COLUMN IF NOT EXISTS `wallet_tag` VARCHAR(60) NULL COMMENT 'e.g. Hardware, Exchange, DeFi'
        AFTER `wallet_address`;

-- ── Add category column (BTC/ETH/Stablecoin/Altcoin/DeFi) ───
ALTER TABLE `crypto_holdings`
    ADD COLUMN IF NOT EXISTS `category` VARCHAR(30) NULL DEFAULT 'Altcoin'
        AFTER `wallet_tag`;

SELECT 'Migration t315 complete — staking_rewards + crypto_watchlist tables created' AS status;
