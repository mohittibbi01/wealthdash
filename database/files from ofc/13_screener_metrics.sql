-- ============================================================
-- WealthDash — Migration 026: Screener Level 3 Metrics
-- Tasks: t164 (Sharpe already done), t165 (Sortino), t166 (Max Drawdown), t167 (Category Avg)
-- ============================================================
-- NOTE: returns_1y/3y/5y, sharpe_ratio, max_drawdown already
--       exist (007_fund_returns.sql se). Ye sirf NEW columns add karta hai.
-- ============================================================

-- t165: Sortino Ratio
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='sortino_ratio');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `sortino_ratio` DECIMAL(8,4) DEFAULT NULL COMMENT 'Sortino Ratio — only downside volatility (Rf=6.5%)'", "SELECT 'sortino_ratio already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- t166: Max Drawdown (refined — already max_drawdown hai, adding peak reference)
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='max_drawdown_from_nav');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `max_drawdown_from_nav` DECIMAL(12,4) DEFAULT NULL COMMENT 'NAV at peak before worst drawdown'", "SELECT 'max_drawdown_from_nav already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- t167: Category averages (already added in 007, adding 5Y avg)
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='category_avg_5y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `category_avg_5y` DECIMAL(8,4) DEFAULT NULL COMMENT 'Category average 5Y return %'", "SELECT 'category_avg_5y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='category_rank_1y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `category_rank_1y` SMALLINT DEFAULT NULL COMMENT 'Rank within category by 1Y return (1=best)'", "SELECT 'category_rank_1y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='category_total');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `category_total` SMALLINT DEFAULT NULL COMMENT 'Total funds in same category (for rank display)'", "SELECT 'category_total already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- ── Indexes for screener sort performance ────────────────────────────────
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_sortino');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_sortino` (`sortino_ratio`)", "SELECT 'idx_funds_sortino already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_cat_avg_1y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_cat_avg_1y` (`category_avg_1y`)", "SELECT 'idx_funds_cat_avg_1y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_cat_avg_3y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_cat_avg_3y` (`category_avg_3y`)", "SELECT 'idx_funds_cat_avg_3y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_cat_rank_1y');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD INDEX `idx_funds_cat_rank_1y` (`category_rank_1y`)", "SELECT 'idx_funds_cat_rank_1y already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- ── calculate_returns.php mein add karo (after sharpe calculation) ────────
/*
  -- Sortino formula (PHP pseudo-code):
  $negReturns = array_filter($dailyReturns, fn($r) => $r < 0);
  $downsideDev = sqrt(array_sum(array_map(fn($r) => $r**2, $negReturns)) / count($dailyReturns)) * sqrt(252);
  $sortino = $annualReturn > 0 && $downsideDev > 0
      ? ($annualReturn - 0.065) / $downsideDev
      : null;

  -- Category rank:
  UPDATE funds f
  JOIN (
    SELECT fund_id,
           RANK() OVER (PARTITION BY category ORDER BY returns_1y DESC) AS cat_rank,
           COUNT(*) OVER (PARTITION BY category) AS cat_total
    FROM funds WHERE returns_1y IS NOT NULL AND is_active = 1
  ) r ON r.fund_id = f.id
  SET f.category_rank_1y = r.cat_rank, f.category_total = r.cat_total;
*/

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT
  COUNT(*) AS total_funds,
  SUM(sortino_ratio    IS NOT NULL) AS has_sortino,
  SUM(category_avg_1y  IS NOT NULL) AS has_cat_avg_1y,
  SUM(category_rank_1y IS NOT NULL) AS has_cat_rank
FROM funds WHERE is_active = 1;
