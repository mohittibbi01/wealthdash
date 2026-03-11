-- ============================================================
-- WealthDash — Migration 005: Backfill NULL fund categories
-- Run once in phpMyAdmin to fix existing funds that have
-- category = NULL (imported before the category fix).
-- ============================================================

-- Step 1: ELSS
UPDATE funds SET category = 'ELSS'
WHERE category IS NULL
  AND scheme_name REGEXP '\\belss\\b';

-- Step 2: Liquid / Overnight / Ultra Short
UPDATE funds SET category = 'Liquid'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(liquid|overnight|ultra short|low dur|short dur|money market)\\b';

-- Step 3: Debt
UPDATE funds SET category = 'Debt'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(debt|gilt|floater|banking and psu|banking & psu|credit risk|medium dur|long dur|dynamic bond|corporate bond|psu debt|short term|medium term)\\b';

-- Step 4: Hybrid
UPDATE funds SET category = 'Hybrid'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(hybrid|balanced|aggressive|conservative|multi asset|arbitrage|equity savings)\\b';

-- Step 5: Index / ETF
UPDATE funds SET category = 'Index'
WHERE category IS NULL
  AND scheme_name REGEXP '\\b(index|nifty|sensex|bse|nse|etf|exchange traded)\\b';

-- Step 6: Everything else = Equity
UPDATE funds SET category = 'Equity'
WHERE category IS NULL;

-- Verify result
SELECT category, COUNT(*) as total
FROM funds
GROUP BY category
ORDER BY total DESC;
