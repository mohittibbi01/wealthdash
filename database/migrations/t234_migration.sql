-- ════════════════════════════════════════════════════════════════════════════
-- WealthDash — Migration: t234 (SIP vs Lumpsum Historical Backtest)
-- Depends on: nav_history table (t160), funds table
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. Backtest cache table ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mf_backtest_cache` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `fund_id`        INT UNSIGNED      NOT NULL,
    `period`         VARCHAR(5)        NOT NULL COMMENT '1Y,3Y,5Y,10Y,etc.',
    `monthly_amount` DECIMAL(12,2)     NOT NULL DEFAULT 5000.00,
    `sip_day`        TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `result_json`    MEDIUMTEXT        NOT NULL COMMENT 'Cached JSON response',
    `nav_count`      INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'nav_history rows at cache time',
    `computed_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_backtest_cache` (`fund_id`, `period`, `monthly_amount`, `sip_day`),
    INDEX `idx_backtest_fund`     (`fund_id`),
    INDEX `idx_backtest_computed` (`computed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cache for SIP vs Lumpsum backtest results (t234)';

-- ── 2. Ensure nav_history index for fast fund+date range queries ─────────────
ALTER TABLE `nav_history`
    ADD INDEX IF NOT EXISTS `idx_navhist_fund_date` (`fund_id`, `nav_date`);

-- ── 3. Record migration ──────────────────────────────────────────────────────
INSERT IGNORE INTO `migration_log` (`filename`, `checksum`, `batch`, `notes`)
VALUES ('t234_migration.sql', SHA2('t234', 256), 1, 'SIP vs Lumpsum Historical Backtest — cache table + nav_history index');
