-- ============================================================
-- WealthDash — Migration 007: Fund Returns & Risk Columns
-- Task: t27 — 1Y/3Y/5Y returns + Sharpe + Max Drawdown
-- ============================================================
-- INSTALL KAISE KARO:
--   phpMyAdmin → wealthdash DB → SQL tab → paste → Go
--   YA: mysql -u root -p wealthdash < 007_fund_returns.sql
--
-- NOTE: Ye sirf columns ADD karta hai — koi data delete nahi hoga.
--       Actual values populate karne ke liye cron chalao:
--         php cron/calculate_returns.php
-- ============================================================

-- 1Y / 3Y / 5Y / 10Y CAGR returns (%)
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `returns_1y`         DECIMAL(8,4) DEFAULT NULL COMMENT '1-Year CAGR % (calculated by cron)',
  ADD COLUMN IF NOT EXISTS `returns_3y`         DECIMAL(8,4) DEFAULT NULL COMMENT '3-Year CAGR %',
  ADD COLUMN IF NOT EXISTS `returns_5y`         DECIMAL(8,4) DEFAULT NULL COMMENT '5-Year CAGR %',
  ADD COLUMN IF NOT EXISTS `returns_10y`        DECIMAL(8,4) DEFAULT NULL COMMENT '10-Year CAGR %';

-- Risk metrics
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `sharpe_ratio`       DECIMAL(8,4) DEFAULT NULL COMMENT 'Annualised Sharpe Ratio (Rf=6.5%)',
  ADD COLUMN IF NOT EXISTS `max_drawdown`       DECIMAL(8,4) DEFAULT NULL COMMENT 'Max Drawdown % (all-time peak to trough)',
  ADD COLUMN IF NOT EXISTS `max_drawdown_date`  DATE         DEFAULT NULL COMMENT 'Date of maximum drawdown trough';

-- Category peer averages (for alpha calculation in screener)
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `category_avg_1y`    DECIMAL(8,4) DEFAULT NULL COMMENT 'Category average 1Y return %',
  ADD COLUMN IF NOT EXISTS `category_avg_3y`    DECIMAL(8,4) DEFAULT NULL COMMENT 'Category average 3Y return %';

-- Timestamp for freshness check
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `returns_updated_at` DATETIME     DEFAULT NULL COMMENT 'Last time returns were recalculated';

-- ── Index for screener sort performance ──────────────────────────────
-- Screener pe ret1y/ret3y/ret5y/sharpe se sort hota hai frequently
ALTER TABLE `funds`
  ADD INDEX IF NOT EXISTS `idx_funds_returns_1y`    (`returns_1y`),
  ADD INDEX IF NOT EXISTS `idx_funds_returns_3y`    (`returns_3y`),
  ADD INDEX IF NOT EXISTS `idx_funds_returns_5y`    (`returns_5y`),
  ADD INDEX IF NOT EXISTS `idx_funds_sharpe`        (`sharpe_ratio`),
  ADD INDEX IF NOT EXISTS `idx_funds_max_drawdown`  (`max_drawdown`);

-- ── Verify — yeh result dikhega ──────────────────────────────────────
SELECT
  COUNT(*) AS total_funds,
  SUM(returns_1y     IS NOT NULL) AS has_1y_return,
  SUM(returns_3y     IS NOT NULL) AS has_3y_return,
  SUM(sharpe_ratio   IS NOT NULL) AS has_sharpe,
  SUM(max_drawdown   IS NOT NULL) AS has_mdd
FROM funds
WHERE is_active = 1;

-- ── Next step ────────────────────────────────────────────────────────
-- Migration ke baad ek baar manually chalao:
--   php cron/calculate_returns.php
--
-- Baad mein daily cron mein add karo (update_nav_daily.php ke BAAD):
--   30 2 * * * php /var/www/html/wealthdash/cron/calculate_returns.php >> /var/log/wd_returns.log 2>&1
