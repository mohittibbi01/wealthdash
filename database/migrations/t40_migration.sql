-- ============================================================
-- WealthDash — Migration t40: CoinGecko Search Cache
-- Task: t40 — Crypto holdings + CoinGecko full integration
-- Run: php database/migrate.php t40
-- ============================================================

CREATE TABLE IF NOT EXISTS `coingecko_search_cache` (
    `cache_key`  VARCHAR(120)  NOT NULL,
    `payload`    MEDIUMTEXT    NOT NULL,
    `fetched_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cache_key`),
    KEY `idx_fetched` (`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CoinGecko API response cache — search, trending, market data';

-- Extend crypto_price_cache with USD price (may already exist, safe ALTER)
ALTER TABLE `crypto_price_cache`
    MODIFY COLUMN `price_usd` DECIMAL(20,8) NOT NULL DEFAULT 0;

SELECT 'CoinGecko search cache table created (t40)' AS status;
