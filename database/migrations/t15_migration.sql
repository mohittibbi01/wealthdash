-- ============================================================
-- WealthDash — Migration t15
-- Task   : MIS & SCSS — maturity = principal, interest alag payout
-- Tables : po_schemes (ALTER), po_payout_log (CREATE)
-- Run    : php database/migrate.php t15
-- ============================================================

-- ── MIS / SCSS payout columns on po_schemes ──────────────────
ALTER TABLE `po_schemes`
    ADD COLUMN IF NOT EXISTS `monthly_payout_amount` DECIMAL(12,2) NULL DEFAULT NULL
        COMMENT 'MIS: monthly interest payout = principal × rate/1200'
        AFTER `maturity_amount`,

    ADD COLUMN IF NOT EXISTS `quarterly_payout_amount` DECIMAL(12,2) NULL DEFAULT NULL
        COMMENT 'SCSS: quarterly interest payout = principal × rate/400'
        AFTER `monthly_payout_amount`,

    ADD COLUMN IF NOT EXISTS `total_payout_received` DECIMAL(16,2) NOT NULL DEFAULT 0.00
        COMMENT 'Running total of interest payouts received so far'
        AFTER `quarterly_payout_amount`,

    ADD COLUMN IF NOT EXISTS `last_payout_date` DATE NULL DEFAULT NULL
        COMMENT 'Date of last recorded payout'
        AFTER `total_payout_received`;

-- ── Backfill MIS monthly_payout_amount for existing rows ─────
UPDATE `po_schemes`
SET `monthly_payout_amount` = ROUND(`principal` * `interest_rate` / 1200, 2)
WHERE `scheme_type` = 'mis'
  AND `principal` > 0
  AND `interest_rate` > 0
  AND `monthly_payout_amount` IS NULL;

-- ── Backfill SCSS quarterly_payout_amount for existing rows ───
UPDATE `po_schemes`
SET `quarterly_payout_amount` = ROUND(`principal` * `interest_rate` / 400, 2)
WHERE `scheme_type` = 'scss'
  AND `principal` > 0
  AND `interest_rate` > 0
  AND `quarterly_payout_amount` IS NULL;

-- ── Payout log table — track each received payout ────────────
CREATE TABLE IF NOT EXISTS `po_payout_log` (
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `po_scheme_id`    INT UNSIGNED      NOT NULL,
    `portfolio_id`    INT UNSIGNED      NOT NULL,
    `payout_type`     ENUM('monthly','quarterly') NOT NULL,
    `payout_date`     DATE              NOT NULL,
    `amount`          DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
    `is_received`     TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1=received, 0=pending/missed',
    `notes`           VARCHAR(255)      NULL,
    `created_at`      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payout_month` (`po_scheme_id`, `payout_date`, `payout_type`),
    KEY `idx_pp_scheme`    (`po_scheme_id`),
    KEY `idx_pp_portfolio` (`portfolio_id`),
    KEY `idx_pp_date`      (`payout_date`),
    CONSTRAINT `fk_pp_scheme`    FOREIGN KEY (`po_scheme_id`)  REFERENCES `po_schemes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pp_portfolio` FOREIGN KEY (`portfolio_id`)  REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='MIS monthly / SCSS quarterly payout log';

SELECT 'Migration t15 complete — MIS/SCSS payout columns + po_payout_log table created' AS status;
