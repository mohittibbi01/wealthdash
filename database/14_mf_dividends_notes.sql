-- ============================================================
-- WealthDash — Migration 027: MF Dividends & Holding Notes
-- Tasks: t171 (Dividend History), t182 (Holdings Notes)
-- ============================================================

-- ── t171: IDCW / Dividend History ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mf_dividends` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `portfolio_id`   INT UNSIGNED  NOT NULL,
  `fund_id`        INT UNSIGNED  NOT NULL,
  `folio_number`   VARCHAR(50)   DEFAULT NULL,
  `dividend_date`  DATE          NOT NULL,
  `nav_before`     DECIMAL(12,4) DEFAULT NULL COMMENT 'NAV ex-dividend date se pehle',
  `nav_after`      DECIMAL(12,4) DEFAULT NULL COMMENT 'NAV ex-dividend date ke baad',
  `rate_per_unit`  DECIMAL(10,4) NOT NULL    COMMENT 'Dividend rate per unit (₹)',
  `units_held`     DECIMAL(14,4) DEFAULT NULL,
  `amount_received` DECIMAL(14,2) DEFAULT NULL COMMENT 'rate_per_unit * units_held',
  `tds_deducted`   DECIMAL(10,2) DEFAULT 0.00,
  `net_received`   DECIMAL(14,2) DEFAULT NULL COMMENT 'amount_received - tds_deducted',
  `is_reinvested`  TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = Growth/Reinvest plan',
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dividend` (`portfolio_id`, `fund_id`, `folio_number`(30), `dividend_date`),
  KEY `idx_div_portfolio` (`portfolio_id`),
  KEY `idx_div_fund`      (`fund_id`),
  KEY `idx_div_date`      (`dividend_date`),
  CONSTRAINT `fk_div_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_div_fund`      FOREIGN KEY (`fund_id`)      REFERENCES `funds`(`id`)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='MF IDCW / Dividend payout history per folio';

-- ── t182: Holdings Notes (personal notes per fund/folio) ─────────────────
CREATE TABLE IF NOT EXISTS `mf_holding_notes` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `fund_id`      INT UNSIGNED NOT NULL,
  `folio_number` VARCHAR(50)  DEFAULT NULL,
  `note`         TEXT         NOT NULL,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_note` (`portfolio_id`, `fund_id`, `folio_number`(30)),
  KEY `idx_hn_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_hn_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hn_fund`      FOREIGN KEY (`fund_id`)      REFERENCES `funds`(`id`)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Personal notes per MF fund/folio — "Bought on COVID dip", "Goal: retirement"';

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'mf_dividends table created ✅' AS d_status;
SELECT 'mf_holding_notes table created ✅' AS n_status;
