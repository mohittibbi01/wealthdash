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
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='returns_1y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `returns_1y` DECIMAL(8,4) DEFAULT NULL COMMENT '1-Year CAGR % (calculated by cron)'", "SELECT 'returns_1y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='returns_3y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `returns_3y` DECIMAL(8,4) DEFAULT NULL COMMENT '3-Year CAGR %'", "SELECT 'returns_3y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='returns_5y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `returns_5y` DECIMAL(8,4) DEFAULT NULL COMMENT '5-Year CAGR %'", "SELECT 'returns_5y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='returns_10y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `returns_10y` DECIMAL(8,4) DEFAULT NULL COMMENT '10-Year CAGR %'", "SELECT 'returns_10y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- Risk metrics
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='sharpe_ratio');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `sharpe_ratio` DECIMAL(8,4) DEFAULT NULL COMMENT 'Annualised Sharpe Ratio (Rf=6.5%)'", "SELECT 'sharpe_ratio already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='max_drawdown');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `max_drawdown` DECIMAL(8,4) DEFAULT NULL COMMENT 'Max Drawdown % (all-time peak to trough)'", "SELECT 'max_drawdown already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='max_drawdown_date');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `max_drawdown_date` DATE DEFAULT NULL COMMENT 'Date of maximum drawdown trough'", "SELECT 'max_drawdown_date already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- Category peer averages (for alpha calculation in screener)
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='category_avg_1y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `category_avg_1y` DECIMAL(8,4) DEFAULT NULL COMMENT 'Category average 1Y return %'", "SELECT 'category_avg_1y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='category_avg_3y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `category_avg_3y` DECIMAL(8,4) DEFAULT NULL COMMENT 'Category average 3Y return %'", "SELECT 'category_avg_3y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- Timestamp for freshness check
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='returns_updated_at');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `returns_updated_at` DATETIME DEFAULT NULL COMMENT 'Last time returns were recalculated'", "SELECT 'returns_updated_at already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- ── Index for screener sort performance ──────────────────────────────
-- Screener pe ret1y/ret3y/ret5y/sharpe se sort hota hai frequently
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_returns_1y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_returns_1y` (`returns_1y`)", "SELECT 'idx_funds_returns_1y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_returns_3y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_returns_3y` (`returns_3y`)", "SELECT 'idx_funds_returns_3y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_returns_5y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_returns_5y` (`returns_5y`)", "SELECT 'idx_funds_returns_5y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_sharpe');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_sharpe` (`sharpe_ratio`)", "SELECT 'idx_funds_sharpe already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_max_drawdown');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_max_drawdown` (`max_drawdown`)", "SELECT 'idx_funds_max_drawdown already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


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
