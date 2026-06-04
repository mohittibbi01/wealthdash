-- ============================================================
-- WealthDash — Migration tv09: Capital Gains Tax Preview
-- Task: Live unrealized CG on MF holdings
-- Run ONCE — idempotent
-- ============================================================

-- No new tables. Existing mf_holdings + funds tables used.
-- Add index for faster CG queries.

CREATE INDEX IF NOT EXISTS `idx_mf_holdings_active_fund`
  ON `mf_holdings` (`fund_id`, `units`);

CREATE INDEX IF NOT EXISTS `idx_funds_category`
  ON `funds` (`scheme_category`(50));

SELECT 'tv09 Capital Gains Preview migration complete ✅' AS status;
