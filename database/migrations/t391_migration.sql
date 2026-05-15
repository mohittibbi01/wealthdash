-- WealthDash Migration t391: Zerodha Kite Connect — Full Integration
-- Extends t301 with OAuth flow, instruments cache, order tracking

-- Already created by t301: zerodha_credentials, zerodha_sync_log,
--   zerodha_holdings_raw, zerodha_positions_raw
-- This migration adds: instruments cache, orders, margins, quote snapshots

CREATE TABLE IF NOT EXISTS `zerodha_instruments` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `instrument_token`INT UNSIGNED NOT NULL,
    `exchange_token`  INT UNSIGNED DEFAULT NULL,
    `tradingsymbol`   VARCHAR(50)  NOT NULL,
    `name`            VARCHAR(200) DEFAULT NULL,
    `last_price`      DECIMAL(14,4) DEFAULT 0,
    `expiry`          DATE          DEFAULT NULL,
    `strike`          DECIMAL(14,4) DEFAULT NULL,
    `tick_size`       DECIMAL(10,4) DEFAULT NULL,
    `lot_size`        INT           DEFAULT 1,
    `instrument_type` VARCHAR(10)  DEFAULT NULL  COMMENT 'EQ,FUT,CE,PE,MF',
    `segment`         VARCHAR(20)  DEFAULT NULL  COMMENT 'NSE,BSE,NFO,CDS',
    `exchange`        VARCHAR(10)  DEFAULT NULL,
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `refreshed_at`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zi_token` (`instrument_token`),
    INDEX `idx_zi_symbol` (`tradingsymbol`, `exchange`),
    INDEX `idx_zi_isin`   (`isin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zerodha_orders` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `order_id`        VARCHAR(30)  NOT NULL,
    `exchange_order_id` VARCHAR(30) DEFAULT NULL,
    `parent_order_id` VARCHAR(30)  DEFAULT NULL,
    `status`          VARCHAR(30)  DEFAULT NULL,
    `status_message`  VARCHAR(255) DEFAULT NULL,
    `tradingsymbol`   VARCHAR(50)  NOT NULL,
    `exchange`        VARCHAR(10)  DEFAULT NULL,
    `instrument_token`INT UNSIGNED DEFAULT NULL,
    `order_type`      VARCHAR(20)  DEFAULT NULL  COMMENT 'MARKET,LIMIT,SL,SL-M',
    `transaction_type`VARCHAR(5)   DEFAULT NULL  COMMENT 'BUY,SELL',
    `product`         VARCHAR(10)  DEFAULT NULL  COMMENT 'CNC,MIS,NRML',
    `validity`        VARCHAR(5)   DEFAULT NULL  COMMENT 'DAY,IOC',
    `quantity`        INT          DEFAULT 0,
    `pending_quantity`INT          DEFAULT 0,
    `filled_quantity` INT          DEFAULT 0,
    `price`           DECIMAL(14,4) DEFAULT 0,
    `trigger_price`   DECIMAL(14,4) DEFAULT 0,
    `average_price`   DECIMAL(14,4) DEFAULT 0,
    `placed_by`       VARCHAR(10)  DEFAULT NULL,
    `variety`         VARCHAR(10)  DEFAULT NULL,
    `order_timestamp` DATETIME     DEFAULT NULL,
    `exchange_timestamp` DATETIME  DEFAULT NULL,
    `raw_json`        JSON         DEFAULT NULL,
    `synced_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zo_order` (`user_id`, `order_id`),
    INDEX `idx_zo_user`   (`user_id`),
    INDEX `idx_zo_symbol` (`tradingsymbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zerodha_margins` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `segment`     VARCHAR(10)  NOT NULL,
    `net`         DECIMAL(14,4) DEFAULT 0,
    `available`   DECIMAL(14,4) DEFAULT 0,
    `used`        DECIMAL(14,4) DEFAULT 0,
    `payin`       DECIMAL(14,4) DEFAULT 0,
    `payout`      DECIMAL(14,4) DEFAULT 0,
    `raw_json`    JSON          DEFAULT NULL,
    `fetched_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zm_user_seg` (`user_id`, `segment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zerodha_quote_snapshots` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `tradingsymbol`   VARCHAR(50)  NOT NULL,
    `exchange`        VARCHAR(10)  NOT NULL,
    `last_price`      DECIMAL(14,4) DEFAULT 0,
    `net_change`      DECIMAL(14,4) DEFAULT 0,
    `net_change_pct`  DECIMAL(8,4)  DEFAULT 0,
    `volume`          BIGINT UNSIGNED DEFAULT 0,
    `oi`              BIGINT UNSIGNED DEFAULT 0,
    `high`            DECIMAL(14,4) DEFAULT 0,
    `low`             DECIMAL(14,4) DEFAULT 0,
    `open`            DECIMAL(14,4) DEFAULT 0,
    `close`           DECIMAL(14,4) DEFAULT 0,
    `raw_json`        JSON          DEFAULT NULL,
    `fetched_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zqs_user_sym` (`user_id`, `tradingsymbol`, `exchange`),
    INDEX `idx_zqs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add GTT table for GTT orders (Good Till Triggered)
CREATE TABLE IF NOT EXISTS `zerodha_gtt` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `gtt_id`          INT UNSIGNED NOT NULL,
    `status`          VARCHAR(20)  DEFAULT NULL,
    `type`            VARCHAR(20)  DEFAULT NULL  COMMENT 'single,two-leg',
    `tradingsymbol`   VARCHAR(50)  NOT NULL,
    `exchange`        VARCHAR(10)  DEFAULT NULL,
    `trigger_values`  JSON         DEFAULT NULL,
    `last_price`      DECIMAL(14,4) DEFAULT 0,
    `condition`       JSON         DEFAULT NULL,
    `orders`          JSON         DEFAULT NULL,
    `created_at`      DATETIME     DEFAULT NULL,
    `updated_at`      DATETIME     DEFAULT NULL,
    `expires_at`      DATETIME     DEFAULT NULL,
    `synced_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zgtt_user` (`user_id`, `gtt_id`),
    INDEX `idx_zgtt_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't391_migration: Zerodha full integration tables ready' AS status;
