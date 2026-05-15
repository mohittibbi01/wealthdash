-- WealthDash Migration t40: Crypto Holdings + CoinGecko Integration
-- Task: t40

CREATE TABLE IF NOT EXISTS `crypto_master` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `coingecko_id`      VARCHAR(100) NOT NULL UNIQUE,
    `symbol`            VARCHAR(20)  NOT NULL,
    `name`              VARCHAR(150) NOT NULL,
    `logo_url`          VARCHAR(500) DEFAULT NULL,
    `current_price_inr` DECIMAL(20,8) DEFAULT 0,
    `price_change_24h`  DECIMAL(10,4) DEFAULT 0,
    `market_cap_inr`    DECIMAL(22,4) DEFAULT 0,
    `volume_24h_inr`    DECIMAL(22,4) DEFAULT 0,
    `ath_inr`           DECIMAL(20,8) DEFAULT NULL,
    `atl_inr`           DECIMAL(20,8) DEFAULT NULL,
    `circulating_supply`DECIMAL(22,4) DEFAULT NULL,
    `total_supply`      DECIMAL(22,4) DEFAULT NULL,
    `rank`              SMALLINT UNSIGNED DEFAULT NULL,
    `price_updated_at`  DATETIME DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cm_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_holdings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`     INT UNSIGNED NOT NULL,
    `coin_id`          INT UNSIGNED NOT NULL,
    `quantity`         DECIMAL(28,10) NOT NULL DEFAULT 0,
    `avg_buy_price`    DECIMAL(20,8) DEFAULT 0,
    `total_invested`   DECIMAL(18,4) DEFAULT 0,
    `current_value`    DECIMAL(18,4) DEFAULT 0,
    `first_buy_date`   DATE DEFAULT NULL,
    `wallet`           VARCHAR(100) DEFAULT NULL,
    `notes`            TEXT DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ch_portfolio_coin` (`portfolio_id`, `coin_id`),
    INDEX `idx_ch_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_transactions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`  INT UNSIGNED NOT NULL,
    `coin_id`       INT UNSIGNED NOT NULL,
    `type`          ENUM('buy','sell','transfer_in','transfer_out','staking','airdrop','mining') NOT NULL DEFAULT 'buy',
    `quantity`      DECIMAL(28,10) NOT NULL,
    `price_inr`     DECIMAL(20,8) DEFAULT 0,
    `total_inr`     DECIMAL(18,4) DEFAULT 0,
    `fee_inr`       DECIMAL(12,4) DEFAULT 0,
    `exchange_name` VARCHAR(50) DEFAULT NULL,
    `wallet`        VARCHAR(100) DEFAULT NULL,
    `txn_hash`      VARCHAR(100) DEFAULT NULL,
    `note`          TEXT DEFAULT NULL,
    `txn_date`      DATE NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ct_portfolio` (`portfolio_id`),
    INDEX `idx_ct_coin`      (`coin_id`),
    INDEX `idx_ct_date`      (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_watchlist` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `coin_id`          INT UNSIGNED NOT NULL,
    `alert_price_low`  DECIMAL(20,8) DEFAULT NULL,
    `alert_price_high` DECIMAL(20,8) DEFAULT NULL,
    `notes`            VARCHAR(500) DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cwl_user_coin` (`user_id`, `coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't40_migration: Crypto tables ready' AS status;
