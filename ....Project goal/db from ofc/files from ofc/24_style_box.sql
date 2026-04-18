-- ============================================================
-- WealthDash — Migration 024: Style Box
-- Task: t179 — Morningstar-style 3×3 Style Box
-- Large/Mid/Small × Value/Blend/Growth
-- ============================================================

-- ── style_box ENUM on funds table ───────────────────────────────────────
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='style_size');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `style_size` ENUM('large','mid','small') DEFAULT NULL COMMENT 'Size axis: large/mid/small cap classification'", "SELECT 'style_size already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='style_value');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `style_value` ENUM('value','blend','growth') DEFAULT NULL COMMENT 'Style axis: value/blend/growth classification'", "SELECT 'style_value already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='style_box');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `style_box` VARCHAR(20) GENERATED ALWAYS AS ( CASE WHEN style_size IS NOT NULL AND style_value IS NOT NULL THEN CONCAT(style_size, '_', style_value) ELSE NULL END ) STORED COMMENT 'Combined style box: e.g. large_growth, mid_value'", "SELECT 'style_box already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='style_drift_note');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `style_drift_note` VARCHAR(500) DEFAULT NULL COMMENT 'Style drift description, e.g. \"Says Large Cap but 40% Mid Cap exposure\"'", "SELECT 'style_drift_note already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='avg_market_cap_cr');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `avg_market_cap_cr` DECIMAL(14,2) DEFAULT NULL COMMENT 'Avg market cap of holdings in crores (from fund_holdings)'", "SELECT 'avg_market_cap_cr already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='avg_pe_ratio');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `avg_pe_ratio` DECIMAL(8,2) DEFAULT NULL COMMENT 'Weighted avg P/E ratio from holdings'", "SELECT 'avg_pe_ratio already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='style_updated_at');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `style_updated_at` DATETIME DEFAULT NULL", "SELECT 'style_updated_at already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_style_size');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_style_size` (`style_size`)", "SELECT 'idx_funds_style_size already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_style_value');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_style_value` (`style_value`)", "SELECT 'idx_funds_style_value already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_style_box');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_style_box` (`style_box`)", "SELECT 'idx_funds_style_box already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- ── Market cap thresholds (SEBI definitions) ────────────────────────────
-- Large Cap: Top 100 companies by full market cap
-- Mid Cap:   101-250
-- Small Cap: 251+
-- Stored as avg_market_cap_cr ranges (approx, updates quarterly)

-- ── Style drift detection (PHP cron pseudo-code) ────────────────────────
/*
  After fetch_fund_holdings.php runs:

  SELECT fund_id,
         SUM(CASE WHEN market_cap_category='large' THEN weight_pct ELSE 0 END) AS large_pct,
         SUM(CASE WHEN market_cap_category='mid'   THEN weight_pct ELSE 0 END) AS mid_pct,
         SUM(CASE WHEN market_cap_category='small' THEN weight_pct ELSE 0 END) AS small_pct,
         AVG(pe_ratio) AS avg_pe,
         AVG(pb_ratio) AS avg_pb
  FROM fund_holdings_detail
  GROUP BY fund_id

  -- Size classification
  if large_pct >= 60: style_size = 'large'
  elseif mid_pct >= 40: style_size = 'mid'
  else: style_size = 'small'

  -- Growth classification (P/E based)
  if avg_pe > 30: style_value = 'growth'
  elseif avg_pe < 15: style_value = 'value'
  else: style_value = 'blend'

  -- Drift detection
  if fund.category LIKE '%Large Cap%' AND large_pct < 60:
      style_drift_note = "Says Large Cap but only {large_pct}% large cap exposure"
*/

-- ── Verify ────────────────────────────────────────────────────────────────
SELECT 'style_box columns added ✅' AS status;
SELECT
  style_size,
  style_value,
  COUNT(*) AS fund_count
FROM funds
WHERE style_size IS NOT NULL
GROUP BY style_size, style_value
ORDER BY style_size, style_value;
-- ============================================================
