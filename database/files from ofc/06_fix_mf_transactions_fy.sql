-- ============================================================
-- WealthDash — Migration 006: Fix corrupted investment_fy in mf_transactions
-- Run ONCE after deploying the holding_calculator.php substr(-2) fix.
--
-- Root cause:
--   holding_calculator.php line ~494 used substr((string)($fy+1), 2)
--   instead of substr((string)($fy+1), -2), producing values like
--   "10-", "19-", "5-" instead of "2019-20", "2020-21", etc.
--
-- This migration re-derives investment_fy from txn_date for all rows
-- whose investment_fy does not match the expected "YYYY-YY" pattern.
-- ============================================================

-- Fix corrupted investment_fy in mf_transactions
UPDATE mf_transactions
SET investment_fy = CONCAT(
    CASE
        WHEN MONTH(txn_date) >= 4
            THEN YEAR(txn_date)
        ELSE YEAR(txn_date) - 1
    END,
    '-',
    SUBSTRING(
        CAST(
            CASE
                WHEN MONTH(txn_date) >= 4
                    THEN YEAR(txn_date) + 1
                ELSE YEAR(txn_date)
            END
        AS CHAR),
        3, 2
    )
)
WHERE investment_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$'
   OR investment_fy IS NULL;

-- Verify: the following query should return 0 rows after the fix
-- SELECT id, txn_date, investment_fy
-- FROM mf_transactions
-- WHERE investment_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$' OR investment_fy IS NULL;
