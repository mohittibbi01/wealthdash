-- WealthDash — t446: Portfolio Health Heatmap Migration
-- No new tables required.
-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals (existing)
-- NOTE: Uses h.first_purchase_date if it exists, falls back to h.created_at.
-- If mf_holdings has neither column, run:
--   ALTER TABLE mf_holdings ADD COLUMN IF NOT EXISTS first_purchase_date DATE NULL;
SELECT 1;
