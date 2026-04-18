-- ============================================================
-- WealthDash — PREREQUISITE FIX
-- Ye file PEHLE run karo, baad mein 04, 05, 06, 22 chalana
--
-- Kya fix hai:
--   1. mf_holdings → 6 missing columns add karta hai
--      (investment_fy, withdrawable_fy, withdrawable_date,
--       ltcg_date, lock_in_date, first_purchase_date)
--   2. funds → 'category' column missing tha
--   3. nav_download_progress → 'from_date' column missing tha
--   4. registration_open setting missing tha → register page blocked
--
-- Double-run safe: already existing columns skip ho jaenge
-- MySQL 8.0 + MariaDB dono pe kaam karta hai
-- ============================================================

-- ══════════════════════════════════════════════════════════════
-- PART 1: mf_holdings — 6 missing columns
-- File 04 inhe use karta hai — bina inke error aata hai
-- ══════════════════════════════════════════════════════════════

-- 1a. first_purchase_date
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mf_holdings' AND COLUMN_NAME='first_purchase_date');
SET @s=IF(@c=0,"ALTER TABLE `mf_holdings` ADD COLUMN `first_purchase_date` DATE DEFAULT NULL COMMENT 'Date of very first purchase in this holding'","SELECT 'first_purchase_date already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 1b. investment_fy (e.g. "2023-24")
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mf_holdings' AND COLUMN_NAME='investment_fy');
SET @s=IF(@c=0,"ALTER TABLE `mf_holdings` ADD COLUMN `investment_fy` VARCHAR(10) DEFAULT NULL COMMENT 'FY of first purchase e.g. 2023-24'","SELECT 'investment_fy already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 1c. ltcg_date
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mf_holdings' AND COLUMN_NAME='ltcg_date');
SET @s=IF(@c=0,"ALTER TABLE `mf_holdings` ADD COLUMN `ltcg_date` DATE DEFAULT NULL COMMENT 'Date from which LTCG applies (first_purchase_date + 365 days for equity)'","SELECT 'ltcg_date already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 1d. lock_in_date (ELSS = 3 years, others = NULL)
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mf_holdings' AND COLUMN_NAME='lock_in_date');
SET @s=IF(@c=0,"ALTER TABLE `mf_holdings` ADD COLUMN `lock_in_date` DATE DEFAULT NULL COMMENT 'Lock-in expiry date (ELSS: +3yr, NULL for others)'","SELECT 'lock_in_date already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 1e. withdrawable_date (later of ltcg_date and lock_in_date)
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mf_holdings' AND COLUMN_NAME='withdrawable_date');
SET @s=IF(@c=0,"ALTER TABLE `mf_holdings` ADD COLUMN `withdrawable_date` DATE DEFAULT NULL COMMENT 'Earliest date units can be fully withdrawn (LTCG + lock-in both satisfied)'","SELECT 'withdrawable_date already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 1f. withdrawable_fy
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mf_holdings' AND COLUMN_NAME='withdrawable_fy');
SET @s=IF(@c=0,"ALTER TABLE `mf_holdings` ADD COLUMN `withdrawable_fy` VARCHAR(10) DEFAULT NULL COMMENT 'FY in which withdrawable_date falls e.g. 2024-25'","SELECT 'withdrawable_fy already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Also add min_ltcg_days to funds (needed by file 04 step 4)
SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='min_ltcg_days');
SET @s=IF(@c=0,"ALTER TABLE `funds` ADD COLUMN `min_ltcg_days` INT NOT NULL DEFAULT 365 COMMENT 'Min holding days for LTCG: Equity=365, ELSS/Debt=1095'","SELECT 'min_ltcg_days already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ══════════════════════════════════════════════════════════════
-- PART 2: funds — 'category' column missing
-- File 05 isko UPDATE karta hai — bina iske error aata hai
-- Note: funds.scheme_category alag hai (AMFI raw value)
--       funds.category = simplified (Equity/Debt/ELSS/Hybrid/Index/Liquid)
-- ══════════════════════════════════════════════════════════════

SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='category');
SET @s=IF(@c=0,"ALTER TABLE `funds` ADD COLUMN `category` VARCHAR(50) DEFAULT NULL COMMENT 'Simplified category: Equity/Debt/ELSS/Hybrid/Index/Liquid'","SELECT 'category already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- Category index for screener performance
SET @i=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND INDEX_NAME='idx_funds_category');
SET @s=IF(@i=0,"ALTER TABLE `funds` ADD INDEX `idx_funds_category` (`category`)","SELECT 'idx_funds_category already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ══════════════════════════════════════════════════════════════
-- PART 3: nav_download_progress — 'from_date' column missing
-- File 22 isko SET karta hai — bina iske error aata hai
-- ══════════════════════════════════════════════════════════════

SET @c=(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nav_download_progress' AND COLUMN_NAME='from_date');
SET @s=IF(@c=0,"ALTER TABLE `nav_download_progress` ADD COLUMN `from_date` DATE DEFAULT NULL COMMENT 'Earliest NAV date downloaded for this fund' AFTER `scheme_code`","SELECT 'from_date already exists' AS info");
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- ══════════════════════════════════════════════════════════════
-- PART 4: registration_open — missing from app_settings seed
-- Register page "Registration is currently closed" fix
-- ══════════════════════════════════════════════════════════════

INSERT INTO app_settings (setting_key, setting_val)
VALUES ('registration_open', '1')
ON DUPLICATE KEY UPDATE setting_val = '1';

-- ══════════════════════════════════════════════════════════════
-- VERIFY — Ye result dikhna chahiye
-- ══════════════════════════════════════════════════════════════

SELECT 'mf_holdings columns' AS checking,
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mf_holdings' AND COLUMN_NAME IN ('first_purchase_date','investment_fy','ltcg_date','lock_in_date','withdrawable_date','withdrawable_fy')) AS added_count,
  '6 expected' AS expected;

SELECT 'funds.category' AS checking,
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='category') AS added_count,
  '1 expected' AS expected;

SELECT 'nav_download_progress.from_date' AS checking,
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nav_download_progress' AND COLUMN_NAME='from_date') AS added_count,
  '1 expected' AS expected;

SELECT 'registration_open' AS checking,
  setting_val AS value,
  'should be 1' AS expected
FROM app_settings WHERE setting_key = 'registration_open';

-- ══════════════════════════════════════════════════════════════
-- AB YE ORDER MEIN CHALAO:
--   04_fix_corrupted_data.sql
--   05_backfill_fund_categories.sql
--   06_fix_mf_transactions_fy.sql
--   22_NAV_fix_run_only_if_needed.sql  (optional)
-- ══════════════════════════════════════════════════════════════
