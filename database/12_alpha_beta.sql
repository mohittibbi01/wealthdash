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
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `alpha`               DECIMAL(8,4) DEFAULT NULL
    COMMENT 'Jensen Alpha % — excess return over CAPM-predicted (Rf=6.5%, benchmark=category avg)',
  ADD COLUMN IF NOT EXISTS `beta`                DECIMAL(8,4) DEFAULT NULL
    COMMENT 'Beta — correlation of fund daily return to benchmark (Nifty 50 proxy). <1 = low risk, >1 = aggressive',
  ADD COLUMN IF NOT EXISTS `standard_deviation`  DECIMAL(8,4) DEFAULT NULL
    COMMENT 'Annualised standard deviation of daily returns (%) — volatility measure',
  ADD COLUMN IF NOT EXISTS `r_squared`           DECIMAL(5,2) DEFAULT NULL
    COMMENT 'R-Squared (0-100) — how closely fund tracks benchmark. >75 = high correlation',
  ADD COLUMN IF NOT EXISTS `alpha_updated_at`    DATETIME     DEFAULT NULL
    COMMENT 'Last time alpha/beta were recalculated';

-- ── Indexes for screener sort/filter ─────────────────────────
ALTER TABLE `funds`
  ADD INDEX IF NOT EXISTS `idx_funds_alpha`        (`alpha`),
  ADD INDEX IF NOT EXISTS `idx_funds_beta`         (`beta`),
  ADD INDEX IF NOT EXISTS `idx_funds_std_dev`      (`standard_deviation`),
  ADD INDEX IF NOT EXISTS `idx_funds_r_squared`    (`r_squared`);

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
