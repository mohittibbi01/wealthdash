-- ============================================================
-- WealthDash — 03_data_fixes.sql
-- STEP 3: One-Time Data Fixes
-- ============================================================
-- Run ONCE on existing databases to fix corrupted data.
-- Safe to skip on fresh installs (no data to fix).
-- ============================================================
-- HOW TO RUN:
--   phpMyAdmin → wealthdash DB → SQL tab → paste → Go
--   OR: mysql -u root -p wealthdash < 03_data_fixes.sql
-- ============================================================

-- ============================================================
-- FIX 001: Corrupted investment_fy in mf_holdings (t4)
-- Root cause: substr($fy+1, -2) bug in holding_calculator.php
-- Pattern "10-" or "19-" instead of "2019-20"
-- ============================================================
UPDATE mf_holdings
SET investment_fy = CONCAT(
    CASE
        WHEN MONTH(first_purchase_date) >= 4 THEN YEAR(first_purchase_date)
        ELSE YEAR(first_purchase_date) - 1
    END,
    '-',
    SUBSTRING(
        CAST(
            CASE
                WHEN MONTH(first_purchase_date) >= 4 THEN YEAR(first_purchase_date) + 1
                ELSE YEAR(first_purchase_date)
            END
        AS CHAR),
        3, 2
    )
)
WHERE investment_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$'
  AND first_purchase_date IS NOT NULL;

SELECT CONCAT('FIX 001 done: ', ROW_COUNT(), ' mf_holdings rows corrected') AS status;

-- ============================================================
-- FIX 002: Corrupted investment_fy in mf_transactions (t6)
-- Same substr bug — re-derives from txn_date
-- ============================================================
UPDATE mf_transactions
SET investment_fy = CONCAT(
    CASE
        WHEN MONTH(txn_date) >= 4 THEN YEAR(txn_date)
        ELSE YEAR(txn_date) - 1
    END,
    '-',
    SUBSTRING(
        CAST(
            CASE
                WHEN MONTH(txn_date) >= 4 THEN YEAR(txn_date) + 1
                ELSE YEAR(txn_date)
            END
        AS CHAR),
        3, 2
    )
)
WHERE investment_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$'
  AND txn_date IS NOT NULL;

SELECT CONCAT('FIX 002 done: ', ROW_COUNT(), ' mf_transactions rows corrected') AS status;

-- ============================================================
-- FIX 003: Backfill NULL fund categories (t5)
-- ============================================================

-- ELSS
UPDATE funds SET category = 'ELSS'
WHERE category IS NULL AND scheme_name REGEXP '\\belss\\b';

-- Liquid / Overnight / Short-term money market
UPDATE funds SET category = 'Liquid'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(liquid|overnight|ultra short|low dur|short dur|money market)\\b';

-- Debt (broad)
UPDATE funds SET category = 'Debt'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(debt|gilt|floater|banking and psu|banking & psu|credit risk|medium dur|long dur|dynamic bond|corporate bond|psu debt|short term|medium term)\\b';

-- Hybrid
UPDATE funds SET category = 'Hybrid'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(hybrid|balanced|aggressive|conservative|multi asset|arbitrage|equity savings)\\b';

-- Index / ETF
UPDATE funds SET category = 'Index'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(index|nifty|sensex|bse|nse|etf|exchange traded)\\b';

-- Equity (catch-all for remaining equity funds)
UPDATE funds SET category = 'Equity'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(equity|flexi|large|mid|small|multi|thematic|sectoral|dividend yield|contra|value|focused|pms)\\b';

SELECT CONCAT('FIX 003 done: ', ROW_COUNT(), ' fund categories backfilled') AS status;

-- ============================================================
-- FIX 004: nav_download_progress — fix NULL from_date (t22)
-- Sets from_date from minimum nav_history date for completed entries
-- ============================================================
UPDATE nav_download_progress p
JOIN funds f ON f.scheme_code = p.scheme_code
JOIN (
    SELECT fund_id, MIN(nav_date) AS min_date
    FROM nav_history
    GROUP BY fund_id
) nh ON nh.fund_id = f.id
SET p.from_date = nh.min_date
WHERE p.from_date IS NULL AND p.status = 'completed';

SELECT CONCAT('FIX 004 done: ', ROW_COUNT(), ' nav_download_progress from_date fixed') AS status;

-- ============================================================
-- FIX 005: Seed nav_download_progress for all active funds
-- ============================================================
INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status)
SELECT f.scheme_code, f.id, 'pending'
FROM funds f
WHERE f.is_active = 1;

SELECT CONCAT('FIX 005 done: ', ROW_COUNT(), ' funds seeded into nav_download_progress') AS status;

SELECT '✅ All data fixes complete' AS result;
