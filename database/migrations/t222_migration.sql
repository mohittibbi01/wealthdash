-- ============================================================
-- WealthDash — Migration t222
-- Task   : t222 — NSE India Free Data — Stocks Price + Index Data
-- Tables : stock_master (ALTER), nse_index_snapshots (CREATE)
-- Run    : php database/migrate.php t222
-- ============================================================

-- ── stock_master: Add NSE-specific live price columns ────────
ALTER TABLE `stock_master`
    ADD COLUMN IF NOT EXISTS `latest_price`       DECIMAL(12,4)  NULL DEFAULT NULL
        COMMENT 'Last fetched live price (INR)'
        AFTER `pe_ratio`,
    ADD COLUMN IF NOT EXISTS `latest_price_date`  DATE           NULL DEFAULT NULL
        COMMENT 'Date of latest_price fetch'
        AFTER `latest_price`,
    ADD COLUMN IF NOT EXISTS `prev_close`         DECIMAL(12,4)  NULL DEFAULT NULL
        COMMENT 'Previous trading day close'
        AFTER `latest_price_date`,
    ADD COLUMN IF NOT EXISTS `day_change_pct`     DECIMAL(8,4)   NULL DEFAULT NULL
        COMMENT 'Day change % from prev_close'
        AFTER `prev_close`,
    ADD COLUMN IF NOT EXISTS `high_52`            DECIMAL(14,4)  NULL DEFAULT NULL
        COMMENT '52-week high (NSE)'
        AFTER `day_change_pct`,
    ADD COLUMN IF NOT EXISTS `low_52`             DECIMAL(14,4)  NULL DEFAULT NULL
        COMMENT '52-week low (NSE)'
        AFTER `high_52`,
    ADD COLUMN IF NOT EXISTS `price_updated_at`   DATETIME       NULL DEFAULT NULL
        COMMENT 'Timestamp of last price sync'
        AFTER `low_52`;

-- ── nse_index_snapshots: Daily index snapshots ───────────────
CREATE TABLE IF NOT EXISTS `nse_index_snapshots` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `index_name`    VARCHAR(100)    NOT NULL  COMMENT 'e.g. NIFTY 50',
  `index_key`     VARCHAR(60)     NOT NULL  COMMENT 'NSE indexSymbol key',
  `snapshot_date` DATE            NOT NULL  COMMENT 'Trading date',
  `last_value`    DECIMAL(12,2)   NOT NULL  DEFAULT 0.00,
  `open`          DECIMAL(12,2)   NULL DEFAULT NULL,
  `high`          DECIMAL(12,2)   NULL DEFAULT NULL,
  `low`           DECIMAL(12,2)   NULL DEFAULT NULL,
  `prev_close`    DECIMAL(12,2)   NULL DEFAULT NULL,
  `change_val`    DECIMAL(10,2)   NULL DEFAULT NULL,
  `change_pct`    DECIMAL(8,4)    NULL DEFAULT NULL,
  `advances`      SMALLINT        NULL DEFAULT NULL,
  `declines`      SMALLINT        NULL DEFAULT NULL,
  `unchanged`     SMALLINT        NULL DEFAULT NULL,
  `year_high`     DECIMAL(12,2)   NULL DEFAULT NULL,
  `year_low`      DECIMAL(12,2)   NULL DEFAULT NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_index_date` (`index_name`, `snapshot_date`),
  KEY `idx_nis_date`  (`snapshot_date`),
  KEY `idx_nis_name`  (`index_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily NSE index snapshots — Nifty 50, Bank Nifty, etc.';

-- ── Indexes for price lookups ─────────────────────────────────
ALTER TABLE `stock_master`
    ADD INDEX IF NOT EXISTS `idx_sm_price_date`  (`latest_price_date`),
    ADD INDEX IF NOT EXISTS `idx_sm_updated`     (`price_updated_at`);

SELECT 'Migration t222 complete — nse_index_snapshots created; stock_master: latest_price, latest_price_date, prev_close, day_change_pct, high_52, low_52, price_updated_at added' AS status;
