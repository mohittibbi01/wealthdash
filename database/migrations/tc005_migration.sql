-- ============================================================
-- WealthDash — tc005: Exchange Sync (Binance / WazirX API)
-- Migration: exchange API key store + sync log
-- ============================================================

-- Encrypted API key store (one row per user+exchange)
CREATE TABLE IF NOT EXISTS `crypto_exchange_keys` (
    `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED   NOT NULL,
    `exchange`      VARCHAR(20)    NOT NULL  COMMENT 'BINANCE | WAZIRX',
    `api_key_enc`   TEXT           NOT NULL  COMMENT 'AES-256-GCM encrypted API key',
    `api_secret_enc`TEXT           NOT NULL  COMMENT 'AES-256-GCM encrypted secret',
    `enc_iv`        VARCHAR(64)    NOT NULL  COMMENT 'Base64 IV for decryption',
    `enc_tag`       VARCHAR(64)    NOT NULL  COMMENT 'Base64 GCM auth tag',
    `label`         VARCHAR(80)    NULL,
    `is_active`     TINYINT(1)     NOT NULL DEFAULT 1,
    `last_synced`   DATETIME       NULL,
    `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_exchange` (`user_id`, `exchange`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='tc005 — Encrypted exchange API keys';

-- Sync run log: one row per sync execution
CREATE TABLE IF NOT EXISTS `crypto_sync_log` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED  NOT NULL,
    `portfolio_id`   INT UNSIGNED  NOT NULL,
    `exchange`       VARCHAR(20)   NOT NULL,
    `status`         ENUM('OK','ERROR','PARTIAL') NOT NULL DEFAULT 'OK',
    `trades_fetched` INT           NOT NULL DEFAULT 0,
    `trades_new`     INT           NOT NULL DEFAULT 0,
    `trades_skipped` INT           NOT NULL DEFAULT 0,
    `error_msg`      TEXT          NULL,
    `synced_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user`  (`user_id`),
    KEY `idx_synced`(`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='tc005 — Exchange sync run history';

-- ── Router routes to NOTE (Master will merge) ────────────────────
-- case 'exchange_keys_save':
-- case 'exchange_keys_list':
-- case 'exchange_keys_delete':
-- case 'exchange_sync_run':
-- case 'exchange_sync_log':
--     require APP_ROOT . '/api/crypto/crypto_exchange_sync.php'; exit;
--
-- Add to $csrfExempt:
--     'exchange_keys_list', 'exchange_sync_log',
