-- ============================================================
-- WealthDash — Migration t16
-- Task   : KVP — tenure dynamic (115 months), annual tax
-- Tables : po_schemes (ALTER)
-- Run    : php database/migrate.php t16
-- ============================================================

-- ── KVP tenure & tax columns ─────────────────────────────────
ALTER TABLE `po_schemes`
    ADD COLUMN IF NOT EXISTS `kvp_tenure_months` SMALLINT UNSIGNED NULL DEFAULT 115
        COMMENT 'KVP: doubling tenure in months — dynamic based on rate (7.5% → 115 months)'
        AFTER `maturity_date`,

    ADD COLUMN IF NOT EXISTS `kvp_annual_taxable_interest` DECIMAL(14,2) NULL DEFAULT NULL
        COMMENT 'KVP: accrual interest per year — taxable under Income from Other Sources (IFOS); no TDS but must declare in ITR'
        AFTER `kvp_tenure_months`,

    ADD COLUMN IF NOT EXISTS `kvp_rate_locked_at` DATE NULL DEFAULT NULL
        COMMENT 'KVP: rate-lock date (= opening_date). Rate fixed at issue, does not change with quarterly revisions.'
        AFTER `kvp_annual_taxable_interest`;

-- ── KVP tenure formula (reference, computed in PHP):
-- months = CEIL( LN(2) / LN(1 + rate/100) ) × 12
-- At 7.5%: CEIL(LN(2)/LN(1.075)) × 12 = CEIL(9.585) × 12 = 10 × 12 = 120? 
-- Actually PO uses simple doubling not compound for KVP announcement:
-- GoI notifies tenure directly. As of Q1 FY25-26 = 115 months @ 7.5%.
-- Store the announced tenure in kvp_tenure_months.

-- ── Backfill existing KVP rows ───────────────────────────────
UPDATE `po_schemes`
SET
    `kvp_tenure_months`           = 115,
    `kvp_rate_locked_at`          = `opening_date`,
    `kvp_annual_taxable_interest` = ROUND(`principal` * `interest_rate` / 100, 2)
WHERE `scheme_type` = 'kvp'
  AND `principal` > 0
  AND `kvp_tenure_months` IS NULL;

-- ── Set maturity_date for KVP rows where it's NULL ───────────
UPDATE `po_schemes`
SET `maturity_date` = DATE_ADD(`opening_date`, INTERVAL `kvp_tenure_months` MONTH)
WHERE `scheme_type` = 'kvp'
  AND `kvp_tenure_months` IS NOT NULL
  AND `opening_date` IS NOT NULL
  AND `maturity_date` IS NULL;

-- ── Verify maturity_amount = 2 × principal for KVP ──────────
UPDATE `po_schemes`
SET `maturity_amount` = `principal` * 2
WHERE `scheme_type` = 'kvp'
  AND `principal` > 0
  AND (`maturity_amount` IS NULL OR `maturity_amount` = 0);

SELECT 'Migration t16 complete — KVP dynamic tenure + annual taxable interest columns added' AS status;
