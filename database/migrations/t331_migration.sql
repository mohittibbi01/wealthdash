-- WealthDash — t331: AI SIP Optimizer Migration
-- No new tables required.
-- Depends on:
--   ✅ mf_sips         (existing)
--   ✅ mf_holdings     (existing)
--   ✅ mf_nav_latest   (existing)
--   ✅ finance_profiles (from ti001_migration.sql)

-- rate_limit_buckets (if not already created)
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
  bucket_key   VARCHAR(180) NOT NULL PRIMARY KEY,
  requests     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL,
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
