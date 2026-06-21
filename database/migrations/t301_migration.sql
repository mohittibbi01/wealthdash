-- WealthDash Migration t301: Zerodha Kite API — Real-time Stock Holdings Sync
-- Task  : t301
-- Run ONCE — idempotent via IF NOT EXISTS / ON DUPLICATE KEY

-- ─── zerodha_credentials ─────────────────────────────────────────────────────
-- Stores per-user Kite API credentials (encrypted at app level before insert)
CREATE TABLE IF NOT EXISTS `zerodha_credentials` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `api_key`         VARCHAR(255) NOT NULL                    COMMENT 'Kite API key (encrypted)',
    `api_secret`      VARCHAR(255) NOT NULL                    COMMENT 'Kite API secret (encrypted)',
    `access_token`    VARCHAR(512) DEFAULT NULL                COMMENT 'Session access token (refreshed daily)',
    `request_token`   VARCHAR(255) DEFAULT NULL                COMMENT 'Last OAuth request token used',
    `token_expiry`    DATETIME     DEFAULT NULL                COMMENT 'Access token valid until (usually next 6am IST)',
    `kite_user_id`    VARCHAR(30)  DEFAULT NULL                COMMENT 'Zerodha client ID e.g. AB1234',
    `kite_user_name`  VARCHAR(100) DEFAULT NULL,
    `login_time`      DATETIME     DEFAULT NULL,
    `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
    `last_sync_at`    DATETIME     DEFAULT NULL,
    `last_sync_status`ENUM('success','failed','pending') DEFAULT NULL,
    `last_sync_error` TEXT         DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zerodha_user` (`user_id`),
    INDEX `idx_zerodha_active` (`is_active`, `token_expiry`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── zerodha_sync_log ─────────────────────────────────────────────────────────
-- Every sync attempt logged for audit / debugging
CREATE TABLE IF NOT EXISTS `zerodha_sync_log` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `portfolio_id`    INT UNSIGNED DEFAULT NULL,
    `triggered_by`    ENUM('manual','cron','webhook') NOT NULL DEFAULT 'manual',
    `holdings_fetched`INT UNSIGNED DEFAULT 0,
    `holdings_added`  INT UNSIGNED DEFAULT 0,
    `holdings_updated`INT UNSIGNED DEFAULT 0,
    `positions_fetched`INT UNSIGNED DEFAULT 0,
    `status`          ENUM('success','failed','partial') NOT NULL DEFAULT 'success',
    `error_message`   TEXT         DEFAULT NULL,
    `api_calls_made`  TINYINT UNSIGNED DEFAULT 0,
    `duration_ms`     INT UNSIGNED DEFAULT NULL,
    `synced_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_zsync_user`   (`user_id`),
    INDEX `idx_zsync_at`     (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── zerodha_holdings_raw ─────────────────────────────────────────────────────
-- Raw snapshot from Kite GET /portfolio/holdings; upserted on each sync
CREATE TABLE IF NOT EXISTS `zerodha_holdings_raw` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `tradingsymbol`   VARCHAR(50)  NOT NULL,
    `exchange`        VARCHAR(10)  NOT NULL DEFAULT 'NSE',
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `quantity`        INT          NOT NULL DEFAULT 0,
    `t1_quantity`     INT          NOT NULL DEFAULT 0          COMMENT 'T+1 settlement qty',
    `average_price`   DECIMAL(12,4) NOT NULL DEFAULT 0,
    `last_price`      DECIMAL(12,4) DEFAULT NULL,
    `close_price`     DECIMAL(12,4) DEFAULT NULL,
    `pnl`             DECIMAL(14,4) DEFAULT NULL,
    `day_change`      DECIMAL(12,4) DEFAULT NULL,
    `day_change_pct`  DECIMAL(8,4)  DEFAULT NULL,
    `product`         VARCHAR(10)  DEFAULT NULL                COMMENT 'CNC / MIS',
    `collateral_qty`  INT          DEFAULT 0,
    `collateral_type` VARCHAR(30)  DEFAULT NULL,
    `used_quantity`   INT          DEFAULT 0,
    `realised_quantity` INT        DEFAULT 0,
    `authorised_date` DATE         DEFAULT NULL,
    `raw_json`        JSON         DEFAULT NULL                COMMENT 'Full Kite holding object',
    `synced_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zh_user_symbol` (`user_id`, `tradingsymbol`, `exchange`),
    INDEX `idx_zh_user`   (`user_id`),
    INDEX `idx_zh_isin`   (`isin`),
    INDEX `idx_zh_sync`   (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── zerodha_positions_raw ────────────────────────────────────────────────────
-- Raw snapshot from Kite GET /portfolio/positions (day + net)
CREATE TABLE IF NOT EXISTS `zerodha_positions_raw` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `position_type`   ENUM('day','net') NOT NULL DEFAULT 'net',
    `tradingsymbol`   VARCHAR(50)  NOT NULL,
    `exchange`        VARCHAR(10)  NOT NULL DEFAULT 'NSE',
    `product`         VARCHAR(10)  DEFAULT NULL,
    `quantity`        INT          NOT NULL DEFAULT 0,
    `overnight_quantity` INT       DEFAULT 0,
    `buy_quantity`    INT          DEFAULT 0,
    `sell_quantity`   INT          DEFAULT 0,
    `average_price`   DECIMAL(12,4) DEFAULT 0,
    `last_price`      DECIMAL(12,4) DEFAULT NULL,
    `pnl`             DECIMAL(14,4) DEFAULT NULL,
    `realised`        DECIMAL(14,4) DEFAULT NULL,
    `unrealised`      DECIMAL(14,4) DEFAULT NULL,
    `m2m`             DECIMAL(14,4) DEFAULT NULL,
    `raw_json`        JSON         DEFAULT NULL,
    `synced_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zpos_user_sym` (`user_id`, `position_type`, `tradingsymbol`, `exchange`, `product`),
    INDEX `idx_zpos_user` (`user_id`),
    INDEX `idx_zpos_sync` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't301_migration: Zerodha Kite sync tables ready' AS status;
