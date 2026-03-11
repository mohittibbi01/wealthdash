-- ============================================================
-- WealthDash — Migration 004: Fix corrupted holdings data
-- Run once after deploying the code fixes from this patch.
--
-- Root causes fixed in PHP code:
--   1. holding_calculator.php: substr($fy+1, -2) → substr($fy+1, 2)
--      caused investment_fy values like "10-" instead of "2019-20"
--   2. recalculate_mf_holdings(): withdrawable_date was never written
--      to DB (missing from both INSERT and UPDATE statements)
--   3. mf_edit.php: wrong require_once path (edit transactions were
--      silently failing with a fatal include error)
-- ============================================================

-- Step 1: Fix corrupted investment_fy values
-- Pattern "10-" means the substr(-2) bug fired during 2019-20 FY
-- Correct value is derivable from first_purchase_date
UPDATE mf_holdings
SET investment_fy = CONCAT(
    CASE
        WHEN MONTH(first_purchase_date) >= 4
            THEN YEAR(first_purchase_date)
        ELSE YEAR(first_purchase_date) - 1
    END,
    '-',
    SUBSTRING(
        CAST(
            CASE
                WHEN MONTH(first_purchase_date) >= 4
                    THEN YEAR(first_purchase_date) + 1
                ELSE YEAR(first_purchase_date)
            END
        AS CHAR),
        3, 2
    )
)
WHERE investment_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$'
  AND first_purchase_date IS NOT NULL;

-- Step 2: Fix withdrawable_fy values that were also corrupted by the same substr bug
UPDATE mf_holdings
SET withdrawable_fy = CONCAT(
    CASE
        WHEN MONTH(withdrawable_date) >= 4
            THEN YEAR(withdrawable_date)
        ELSE YEAR(withdrawable_date) - 1
    END,
    '-',
    SUBSTRING(
        CAST(
            CASE
                WHEN MONTH(withdrawable_date) >= 4
                    THEN YEAR(withdrawable_date) + 1
                ELSE YEAR(withdrawable_date)
            END
        AS CHAR),
        3, 2
    )
)
WHERE withdrawable_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$'
  AND withdrawable_date IS NOT NULL;

-- Step 3: Populate withdrawable_date where it is NULL but ltcg_date is known
-- withdrawable_date = later of (ltcg_date, lock_in_date)
UPDATE mf_holdings
SET withdrawable_date = CASE
    WHEN lock_in_date IS NOT NULL AND lock_in_date > ltcg_date
        THEN lock_in_date
    ELSE ltcg_date
END
WHERE withdrawable_date IS NULL
  AND ltcg_date IS NOT NULL;

-- Step 4: Derive withdrawable_date from first_purchase_date + min_ltcg_days
-- for any remaining rows where ltcg_date itself was also NULL
UPDATE mf_holdings h
JOIN funds f ON f.id = h.fund_id
SET h.ltcg_date = DATE_ADD(h.first_purchase_date, INTERVAL f.min_ltcg_days DAY),
    h.withdrawable_date = CASE
        WHEN h.lock_in_date IS NOT NULL
            THEN GREATEST(
                DATE_ADD(h.first_purchase_date, INTERVAL f.min_ltcg_days DAY),
                h.lock_in_date
            )
        ELSE DATE_ADD(h.first_purchase_date, INTERVAL f.min_ltcg_days DAY)
    END
WHERE h.ltcg_date IS NULL
  AND h.first_purchase_date IS NOT NULL;

-- Verify: Check for any remaining broken values
-- SELECT id, investment_fy, withdrawable_fy, withdrawable_date
-- FROM mf_holdings
-- WHERE investment_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$'
--    OR withdrawable_fy NOT REGEXP '^[0-9]{4}-[0-9]{2}$'
--    OR (first_purchase_date IS NOT NULL AND withdrawable_date IS NULL);
