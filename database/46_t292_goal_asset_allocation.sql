-- ============================================================
-- WealthDash — Migration 046: t292 Goal-Based Asset Allocation
-- Ensures investment_goals has JSON columns for linked assets
-- (columns already present in schema 01 but added here for
--  any older installs that may be missing them)
-- ============================================================

ALTER TABLE `investment_goals`
  MODIFY COLUMN `linked_fund_ids`  LONGTEXT
    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    CHECK (json_valid(`linked_fund_ids`)),
  MODIFY COLUMN `linked_stock_ids` LONGTEXT
    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    CHECK (json_valid(`linked_stock_ids`)),
  MODIFY COLUMN `linked_fd_ids`    LONGTEXT
    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    CHECK (json_valid(`linked_fd_ids`));

-- Seed empty JSON arrays for any NULLs to avoid parse errors
UPDATE `investment_goals`
SET
  `linked_fund_ids`  = COALESCE(`linked_fund_ids`,  '[]'),
  `linked_stock_ids` = COALESCE(`linked_stock_ids`, '[]'),
  `linked_fd_ids`    = COALESCE(`linked_fd_ids`,    '[]');

SELECT 't292 Goal-Based Asset Allocation — migration complete' AS status;
