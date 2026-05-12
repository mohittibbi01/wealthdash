-- ============================================================
-- WealthDash — tc001: Live Crypto Price Stream
-- Migration: crypto_price_cache (SSE price store)
-- ============================================================

CREATE TABLE IF NOT EXISTS `crypto_price_cache` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `coin_id`     VARCHAR(80)   NOT NULL COMMENT 'CoinGecko ID e.g. bitcoin',
    `price_inr`   DECIMAL(20,4) NOT NULL DEFAULT 0,
    `price_usd`   DECIMAL(20,8) NOT NULL DEFAULT 0,
    `change_24h`  DECIMAL(10,4) NOT NULL DEFAULT 0,
    `market_cap`  BIGINT        NOT NULL DEFAULT 0,
    `vol_24h`     BIGINT        NOT NULL DEFAULT 0,
    `fetched_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_coin` (`coin_id`),
    KEY `idx_fetched` (`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='tc001 — CoinGecko price cache (refreshed every 30s via SSE)';

-- ── Router routes to NOTE (Master will merge) ────────────────────
-- case 'crypto_price_stream':
--     require APP_ROOT . '/api/crypto/crypto_price_stream.php'; exit;
--
-- Add to $csrfExempt:
--     'crypto_price_stream',
