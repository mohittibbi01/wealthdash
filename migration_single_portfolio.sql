-- ============================================================
-- WealthDash Migration: One Portfolio Per User
-- Run this ONCE on your existing database
-- ============================================================

-- STEP 1: For any user who has multiple portfolios,
-- keep only the default one (or the oldest one if no default).
-- All data in extra portfolios is moved to the kept portfolio
-- before the extras are deleted.

-- First, find which portfolio_id to KEEP for each user
-- (is_default=1 wins, otherwise lowest id)
CREATE TEMPORARY TABLE _keep_portfolio AS
SELECT user_id, 
       COALESCE(
           (SELECT id FROM portfolios p2 WHERE p2.user_id = p.user_id AND p2.is_default = 1 ORDER BY id LIMIT 1),
           (SELECT id FROM portfolios p3 WHERE p3.user_id = p.user_id ORDER BY id LIMIT 1)
       ) AS keep_id
FROM portfolios p
GROUP BY user_id;

-- STEP 2: For each asset table, re-point rows from extra portfolios
--         to the kept portfolio (only if user has duplicates)

UPDATE mf_holdings h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE mf_transactions h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE fd_accounts h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE stock_holdings h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE stock_transactions h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE nps_holdings h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE nps_transactions h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE savings_accounts h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

UPDATE po_schemes h
JOIN portfolios p ON p.id = h.portfolio_id
JOIN _keep_portfolio k ON k.user_id = p.user_id
SET h.portfolio_id = k.keep_id
WHERE h.portfolio_id <> k.keep_id;

-- STEP 3: Delete the extra portfolios (CASCADE will clean child rows)
DELETE p FROM portfolios p
JOIN _keep_portfolio k ON k.user_id = p.user_id
WHERE p.id <> k.keep_id;

DROP TEMPORARY TABLE _keep_portfolio;

-- STEP 4: Make sure every remaining portfolio has is_default = 1
UPDATE portfolios SET is_default = 1;

-- STEP 5: Add UNIQUE constraint so one user = one portfolio forever
ALTER TABLE portfolios
  ADD UNIQUE KEY uq_one_portfolio_per_user (user_id);

-- STEP 6: Drop portfolio_members table (sharing feature removed)
DROP TABLE IF EXISTS portfolio_members;

-- Done!
SELECT 'Migration complete. One portfolio per user enforced.' AS status;
