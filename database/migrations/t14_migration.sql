-- ============================================================
-- WealthDash — Migration t14
-- Task   : TD — 4 sub-tenures (1/2/3/5 yr), har tenure ki alag row
-- Tables : po_schemes (ALTER)
-- Run    : php database/migrate.php t14
-- ============================================================

-- ── TD tenure tag column ──────────────────────────────────────
ALTER TABLE `po_schemes`
    ADD COLUMN IF NOT EXISTS `td_tenure_years` TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'TD only: 1 | 2 | 3 | 5 years — each tenure is a separate row'
        AFTER `interest_rate`,

    ADD COLUMN IF NOT EXISTS `td_interest_payout` ENUM('monthly','quarterly','on_maturity') NULL DEFAULT NULL
        COMMENT 'TD: how interest is paid out'
        AFTER `td_tenure_years`,

    ADD COLUMN IF NOT EXISTS `td_annual_interest` DECIMAL(14,2) NULL DEFAULT NULL
        COMMENT 'TD: computed annual interest amount for display'
        AFTER `td_interest_payout`;

-- ── Backfill existing TD rows from meta JSON if present ──────
UPDATE `po_schemes`
SET `td_tenure_years` = CAST(JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.tenure_years')) AS UNSIGNED)
WHERE `scheme_type` = 'td'
  AND `meta` IS NOT NULL
  AND JSON_VALID(`meta`) = 1
  AND JSON_EXTRACT(`meta`, '$.tenure_years') IS NOT NULL
  AND `td_tenure_years` IS NULL;

-- ── Rate lookup: official PO TD rates (Q1 FY 2025-26) ────────
-- 1yr = 6.9% | 2yr = 7.0% | 3yr = 7.1% | 5yr = 7.5%
-- Stored per-row in interest_rate column; td_tenure_years is the tag.

-- ── Add index for fast tenure-type queries ───────────────────
ALTER TABLE `po_schemes`
    ADD INDEX IF NOT EXISTS `idx_po_td_tenure` (`scheme_type`, `td_tenure_years`);

SELECT 'Migration t14 complete — TD sub-tenure columns added to po_schemes' AS status;
