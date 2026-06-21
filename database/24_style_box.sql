-- ============================================================
-- WealthDash — Migration 024: Style Box
-- Task: t179 — Morningstar-style 3×3 Style Box
-- Large/Mid/Small × Value/Blend/Growth
-- ============================================================

-- ── style_box ENUM on funds table ───────────────────────────────────────
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `style_size`   ENUM('large','mid','small') DEFAULT NULL COMMENT 'Size axis: large/mid/small cap classification',
  ADD COLUMN IF NOT EXISTS `style_value`  ENUM('value','blend','growth') DEFAULT NULL COMMENT 'Style axis: value/blend/growth classification',
  ADD COLUMN IF NOT EXISTS `style_box`    VARCHAR(20) GENERATED ALWAYS AS (
      CASE WHEN style_size IS NOT NULL AND style_value IS NOT NULL
           THEN CONCAT(style_size, '_', style_value)
           ELSE NULL END
  ) STORED COMMENT 'Combined style box: e.g. large_growth, mid_value',
  ADD COLUMN IF NOT EXISTS `style_drift_note` VARCHAR(500) DEFAULT NULL COMMENT 'Style drift description, e.g. "Says Large Cap but 40% Mid Cap exposure"',
  ADD COLUMN IF NOT EXISTS `avg_market_cap_cr` DECIMAL(14,2) DEFAULT NULL COMMENT 'Avg market cap of holdings in crores (from fund_holdings)',
  ADD COLUMN IF NOT EXISTS `avg_pe_ratio`      DECIMAL(8,2)  DEFAULT NULL COMMENT 'Weighted avg P/E ratio from holdings',
  ADD COLUMN IF NOT EXISTS `style_updated_at`  DATETIME DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_funds_style_size`  (`style_size`),
  ADD INDEX IF NOT EXISTS `idx_funds_style_value` (`style_value`),
  ADD INDEX IF NOT EXISTS `idx_funds_style_box`   (`style_box`);

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
