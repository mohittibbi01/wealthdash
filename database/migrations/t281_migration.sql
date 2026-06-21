-- ============================================================
-- WealthDash — Migration t281
-- Task   : t281 — Stock Fundamental Data — P/E, P/B, Market Cap
-- Tables : stock_master (ALTER)
-- Run    : php database/migrate.php t281
-- ============================================================
-- Note: fundamentals.php (t281 API) uses ALTER TABLE IF NOT EXISTS
--       inline for idempotency; this migration makes the schema
--       authoritative and adds proper indexes + the sector mapping.
-- ============================================================

-- ── stock_master: Fundamental data columns ───────────────────
ALTER TABLE `stock_master`
    ADD COLUMN IF NOT EXISTS `pb_ratio`                DECIMAL(10,4) NULL DEFAULT NULL
        COMMENT 'Price-to-Book ratio (Yahoo Finance / BSE)'
        AFTER `pe_ratio`,
    ADD COLUMN IF NOT EXISTS `eps`                     DECIMAL(12,4) NULL DEFAULT NULL
        COMMENT 'Trailing EPS (INR)'
        AFTER `pb_ratio`,
    ADD COLUMN IF NOT EXISTS `dividend_yield`          DECIMAL(8,4)  NULL DEFAULT NULL
        COMMENT 'Annual dividend yield (%)'
        AFTER `eps`,
    ADD COLUMN IF NOT EXISTS `fundamentals_updated_at` DATETIME      NULL DEFAULT NULL
        COMMENT 'Timestamp of last fundamental refresh'
        AFTER `dividend_yield`;

-- ── Ensure high_52 / low_52 exist (shared with t222) ─────────
ALTER TABLE `stock_master`
    ADD COLUMN IF NOT EXISTS `high_52` DECIMAL(14,4) NULL DEFAULT NULL
        COMMENT '52-week high'
        AFTER `dividend_yield`,
    ADD COLUMN IF NOT EXISTS `low_52`  DECIMAL(14,4) NULL DEFAULT NULL
        COMMENT '52-week low'
        AFTER `high_52`;

-- ── stock_fundamental_history: Store periodic snapshots ───────
-- Allows P/E trend analysis over time.
CREATE TABLE IF NOT EXISTS `stock_fundamental_history` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `stock_id`       INT UNSIGNED    NOT NULL,
  `symbol`         VARCHAR(30)     NOT NULL,
  `snapshot_date`  DATE            NOT NULL  COMMENT 'Date of this snapshot',
  `pe_ratio`       DECIMAL(10,4)   NULL DEFAULT NULL,
  `pb_ratio`       DECIMAL(10,4)   NULL DEFAULT NULL,
  `eps`            DECIMAL(12,4)   NULL DEFAULT NULL,
  `market_cap`     DECIMAL(22,2)   NULL DEFAULT NULL COMMENT 'Market cap in INR',
  `dividend_yield` DECIMAL(8,4)    NULL DEFAULT NULL,
  `high_52`        DECIMAL(14,4)   NULL DEFAULT NULL,
  `low_52`         DECIMAL(14,4)   NULL DEFAULT NULL,
  `price_on_date`  DECIMAL(12,4)   NULL DEFAULT NULL COMMENT 'LTP at time of snapshot',
  `source`         VARCHAR(30)     NOT NULL DEFAULT 'yahoo'
                   COMMENT 'Data source: yahoo, screener, bse',
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sfh_stock_date`  (`stock_id`, `snapshot_date`),
  KEY `idx_sfh_symbol`            (`symbol`),
  KEY `idx_sfh_date`              (`snapshot_date`),
  KEY `idx_sfh_pe`                (`pe_ratio`),
  CONSTRAINT `fk_sfh_stock` FOREIGN KEY (`stock_id`)
      REFERENCES `stock_master` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historical snapshots of stock fundamental data';

-- ── Indexes on stock_master for fundamental queries ───────────
ALTER TABLE `stock_master`
    ADD INDEX IF NOT EXISTS `idx_sm_pe`           (`pe_ratio`),
    ADD INDEX IF NOT EXISTS `idx_sm_pb`           (`pb_ratio`),
    ADD INDEX IF NOT EXISTS `idx_sm_mktcap`       (`market_cap`),
    ADD INDEX IF NOT EXISTS `idx_sm_fund_updated` (`fundamentals_updated_at`);

-- ── Backfill: snapshot today's fundamentals for stocks that   ─
--   already have data (safe to run repeatedly; IGNORE skips dup) ─
INSERT IGNORE INTO `stock_fundamental_history`
    (stock_id, symbol, snapshot_date, pe_ratio, pb_ratio, eps,
     market_cap, dividend_yield, high_52, low_52, price_on_date, source)
SELECT
    id, symbol, CURDATE(),
    pe_ratio, pb_ratio, eps,
    market_cap, dividend_yield, high_52, low_52,
    COALESCE(latest_price, current_price),
    'yahoo'
FROM `stock_master`
WHERE pe_ratio IS NOT NULL
   OR pb_ratio IS NOT NULL
   OR eps      IS NOT NULL;

SELECT CONCAT(
    'Migration t281 complete — stock_master: pb_ratio, eps, dividend_yield, high_52, low_52, fundamentals_updated_at added; ',
    'stock_fundamental_history created; ',
    (SELECT COUNT(*) FROM stock_fundamental_history WHERE snapshot_date = CURDATE()),
    ' rows backfilled.'
) AS status;
