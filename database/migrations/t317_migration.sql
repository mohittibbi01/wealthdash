-- ============================================================
-- WealthDash — t317: Crypto Exchange P&L Import
-- Migration: import_source tracking on crypto_transactions
-- ============================================================

-- Track which transactions came from CSV import
ALTER TABLE `crypto_transactions`
    ADD COLUMN IF NOT EXISTS `import_source`  VARCHAR(20)  NULL DEFAULT NULL COMMENT 'BINANCE | WAZIRX | COINDCX | NULL=manual',
    ADD COLUMN IF NOT EXISTS `import_batch`   VARCHAR(36)  NULL DEFAULT NULL COMMENT 'UUID of the import batch',
    ADD COLUMN IF NOT EXISTS `trade_pair`     VARCHAR(20)  NULL DEFAULT NULL COMMENT 'e.g. BTCUSDT, BTC/INR',
    ADD COLUMN IF NOT EXISTS `fee_amount`     DECIMAL(20,8) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `fee_currency`   VARCHAR(10)  NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `tds_deducted`   DECIMAL(16,4) NULL DEFAULT 0   COMMENT 'TDS withheld (WazirX)',
    ADD COLUMN IF NOT EXISTS `external_id`    VARCHAR(80)  NULL DEFAULT NULL COMMENT 'Exchange order/trade ID';

-- Prevent duplicate imports (same exchange trade imported twice)
-- Note: portfolio_id not used in key — crypto_transactions uses user-level scope via portfolio FK
ALTER TABLE `crypto_transactions`
    ADD UNIQUE KEY IF NOT EXISTS `uq_external` (`import_source`(20), `external_id`(80));

-- Import log: one row per CSV upload
CREATE TABLE IF NOT EXISTS `crypto_import_log` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `batch_id`      VARCHAR(36)   NOT NULL COMMENT 'UUID',
    `portfolio_id`  INT UNSIGNED  NOT NULL,
    `user_id`       INT UNSIGNED  NOT NULL,
    `exchange`      VARCHAR(20)   NOT NULL COMMENT 'BINANCE | WAZIRX | COINDCX',
    `filename`      VARCHAR(255)  NOT NULL,
    `rows_parsed`   INT           NOT NULL DEFAULT 0,
    `rows_inserted` INT           NOT NULL DEFAULT 0,
    `rows_skipped`  INT           NOT NULL DEFAULT 0,
    `imported_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolio` (`portfolio_id`),
    KEY `idx_user`      (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='t317 — CSV import audit log';

-- ── Router routes to NOTE (Master will merge) ────────────────────
-- case 'crypto_import_preview':
-- case 'crypto_import_confirm':
-- case 'crypto_import_log':
--     require APP_ROOT . '/api/crypto/crypto_import.php'; exit;
--
-- Add to $csrfExempt:
--     'crypto_import_log',
