-- ============================================================
-- WealthDash — Migration t206: Post Office MIS/SCSS Monthly Payout Tracker
-- Task: Monthly payout calendar + TDS tracking
-- Run ONCE — idempotent
-- ============================================================

-- 1. po_payout_log — records each expected/received payout
--    (shared by MIS monthly + SCSS quarterly)
CREATE TABLE IF NOT EXISTS `po_payout_log` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_scheme_id`  int(10) UNSIGNED NOT NULL,
  `payout_date`   date             NOT NULL COMMENT 'Expected payout date (1st of month/quarter)',
  `payout_type`   enum('monthly','quarterly') NOT NULL DEFAULT 'monthly',
  `amount`        decimal(12,2)    NOT NULL DEFAULT 0.00,
  `tds_deducted`  decimal(10,2)    NOT NULL DEFAULT 0.00,
  `tds_tan`       varchar(20)      DEFAULT NULL COMMENT 'TAN of deductor (SCSS TDS)',
  `is_received`   tinyint(1)       NOT NULL DEFAULT 0,
  `notes`         varchar(255)     DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ppl_scheme_date` (`po_scheme_id`, `payout_date`),
  KEY `idx_ppl_scheme`   (`po_scheme_id`),
  KEY `idx_ppl_date`     (`payout_date`),
  KEY `idx_ppl_received` (`is_received`),
  CONSTRAINT `fk_ppl_scheme`
    FOREIGN KEY (`po_scheme_id`) REFERENCES `po_schemes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure po_schemes has required columns for MIS/SCSS calculations
ALTER TABLE `po_schemes`
  ADD COLUMN IF NOT EXISTS `monthly_payout_amount`   decimal(12,2) DEFAULT NULL
    COMMENT 'MIS monthly payout (computed or manual override)'
    AFTER `maturity_amount`,
  ADD COLUMN IF NOT EXISTS `quarterly_payout_amount` decimal(12,2) DEFAULT NULL
    COMMENT 'SCSS quarterly payout (computed or manual override)'
    AFTER `monthly_payout_amount`,
  ADD COLUMN IF NOT EXISTS `total_payout_received`   decimal(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Running total of received payouts'
    AFTER `quarterly_payout_amount`,
  ADD COLUMN IF NOT EXISTS `last_payout_date`        date DEFAULT NULL
    AFTER `total_payout_received`;

-- 3. Index for fast user+type queries
CREATE INDEX IF NOT EXISTS `idx_po_user_type`
  ON `po_schemes` (`scheme_type`);

SELECT 't206 MIS/SCSS Payout Tracker migration complete ✅' AS status;
