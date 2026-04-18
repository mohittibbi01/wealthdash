-- ============================================================
-- WealthDash — Migration 013: Alpha & Beta (Task t93)
-- Alpha = excess return over benchmark
-- Beta  = sensitivity to benchmark (market) movement
-- Std Dev & R² as companion metrics
-- ============================================================
-- HOW TO RUN:
--   phpMyAdmin → wealthdash DB → SQL tab → paste → Go
--   OR: mysql -u root -p wealthdash < 013_alpha_beta.sql
--
-- After running, calculate values:
--   php cron/calculate_returns.php
-- ============================================================

-- ── New columns ──────────────────────────────────────────────
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='alpha');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `alpha` DECIMAL(8,4) DEFAULT NULL COMMENT 'Jensen Alpha % — excess return over CAPM-predicted (Rf=6.5%, benchmark=category avg)'", "SELECT 'alpha already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='beta');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `beta` DECIMAL(8,4) DEFAULT NULL COMMENT 'Beta — correlation of fund daily return to benchmark (Nifty 50 proxy). <1 = low risk, >1 = aggressive'", "SELECT 'beta already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='standard_deviation');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `standard_deviation` DECIMAL(8,4) DEFAULT NULL COMMENT 'Annualised standard deviation of daily returns (%) — volatility measure'", "SELECT 'standard_deviation already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='r_squared');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `r_squared` DECIMAL(5,2) DEFAULT NULL COMMENT 'R-Squared (0-100) — how closely fund tracks benchmark. >75 = high correlation'", "SELECT 'r_squared already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='alpha_updated_at');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `alpha_updated_at` DATETIME DEFAULT NULL COMMENT 'Last time alpha/beta were recalculated'", "SELECT 'alpha_updated_at already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- ── Indexes for screener sort/filter ─────────────────────────
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_alpha');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_alpha` (`alpha`)", "SELECT 'idx_funds_alpha already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_beta');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_beta` (`beta`)", "SELECT 'idx_funds_beta already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_std_dev');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_std_dev` (`standard_deviation`)", "SELECT 'idx_funds_std_dev already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_r_squared');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_r_squared` (`r_squared`)", "SELECT 'idx_funds_r_squared already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- ── Benchmark mapping reference (used in cron) ───────────────
-- Equity (Large Cap, Mid Cap, Small Cap, Flexi, Multi, ELSS, Index, Thematic, Sectoral) → Nifty 50 (proxy: avg equity fund return)
-- Hybrid → Nifty 50 60% weight
-- Debt (all debt categories) → Crisil Bond Index (proxy: avg debt fund return)
-- Commodity (Gold, Silver) → no alpha calculated
-- FoF/International → no alpha calculated

-- ── Verify ───────────────────────────────────────────────────
SELECT
  COUNT(*)                          AS total_funds,
  SUM(alpha              IS NOT NULL) AS has_alpha,
  SUM(beta               IS NOT NULL) AS has_beta,
  SUM(standard_deviation IS NOT NULL) AS has_std_dev,
  SUM(r_squared          IS NOT NULL) AS has_r_squared
FROM funds
WHERE is_active = 1;
