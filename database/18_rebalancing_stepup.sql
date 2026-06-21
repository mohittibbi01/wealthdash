-- ============================================================
-- WealthDash — Migration 031: Rebalancing Target + SIP Step-up
-- Tasks: t174 (SIP Top-up Calculator), t175 (Rebalancing Alert)
-- ============================================================

-- ── t175: Rebalancing Target Allocation ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `rebalancing_targets` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `portfolio_id`   INT UNSIGNED  NOT NULL,
  `asset_class`    ENUM('equity','debt','gold','cash','international','other') NOT NULL,
  `target_pct`     DECIMAL(5,2)  NOT NULL  COMMENT 'Target allocation % (0-100)',
  `drift_threshold` DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT 'Alert agar actual drift > threshold %',
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rebal_portfolio_asset` (`portfolio_id`, `asset_class`),
  CONSTRAINT `fk_rebal_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User-defined target allocation for rebalancing alerts';

-- ── t174: SIP Step-up / Top-up config stored in sip_schedules ────────────
-- sip_schedules table mein columns add karo
ALTER TABLE `sip_schedules`
  ADD COLUMN IF NOT EXISTS `step_up_pct`      DECIMAL(5,2) DEFAULT NULL  COMMENT 'Annual step-up % (e.g. 10 = 10% increase per year)',
  ADD COLUMN IF NOT EXISTS `step_up_frequency` ENUM('annual','semi_annual') DEFAULT 'annual',
  ADD COLUMN IF NOT EXISTS `step_up_next_date` DATE         DEFAULT NULL  COMMENT 'Next date when step-up applies',
  ADD COLUMN IF NOT EXISTS `step_up_max_amount` DECIMAL(12,2) DEFAULT NULL COMMENT 'Maximum SIP amount cap';

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'rebalancing_targets table created ✅' AS r_status;
SHOW COLUMNS FROM sip_schedules LIKE 'step_up%';
