-- ============================================================
-- WealthDash — Migration t42: Crypto Tax 30% Flat Calculator
-- Task: t42 — Crypto tax calculator + saved calculations
-- Run: php database/migrate.php t42
-- ============================================================

CREATE TABLE IF NOT EXISTS `crypto_tax_saved` (
    `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED   NOT NULL,
    `label`       VARCHAR(120)   NOT NULL,
    `fy`          VARCHAR(10)    NOT NULL DEFAULT '2024-25',  -- e.g. 2024-25
    `calc_json`   TEXT           NOT NULL,                   -- Full calculation result
    `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_user`    (`user_id`),
    KEY `idx_fy`      (`fy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Saved VDA tax calculations and what-if scenarios (t42)';

-- Ensure crypto_transactions has tds_deducted column (may exist from t38/vda_tax)
ALTER TABLE `crypto_transactions`
    ADD COLUMN IF NOT EXISTS `tds_deducted` DECIMAL(20,2) NOT NULL DEFAULT 0
        COMMENT 'TDS amount deducted by exchange (Sec 194S)' AFTER `amount_inr`;

SELECT 'Tax calculator tables ready (t42)' AS status;
