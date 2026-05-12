-- ============================================================
-- WealthDash — Migration t120: Smallcase Portfolio Sync
-- Task: t120 — Basket strategy tracker
-- ============================================================

-- ── Smallcase strategies (user's subscribed baskets) ─────────
CREATE TABLE IF NOT EXISTS `smallcase_portfolios` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `smallcase_id`    VARCHAR(50)  NOT NULL COMMENT 'Smallcase slug/id e.g. EQUITY_50',
  `name`            VARCHAR(150) NOT NULL,
  `publisher`       VARCHAR(100) NULL COMMENT 'Windmill Capital / SEBI RIA name',
  `strategy_type`   ENUM('model','thematic','quantamental','sectoral','smart_beta','other') NOT NULL DEFAULT 'model',
  `rebalance_freq`  ENUM('monthly','quarterly','half_yearly','yearly','event_based') DEFAULT 'quarterly',
  `min_investment`  DECIMAL(12,2) NULL,
  `cagr_1y`         DECIMAL(6,2) NULL,
  `cagr_3y`         DECIMAL(6,2) NULL,
  `volatility`      DECIMAL(6,2) NULL,
  `description`     TEXT NULL,
  `invested_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `current_value`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `last_synced_at`  DATETIME NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sc_user` (`user_id`),
  INDEX `idx_sc_active` (`is_active`),
  UNIQUE KEY `uq_sc_user_id` (`user_id`, `smallcase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User smallcase basket subscriptions';

-- ── Holdings inside each smallcase (stock-level) ─────────────
CREATE TABLE IF NOT EXISTS `smallcase_holdings` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`      INT UNSIGNED NOT NULL,
  `user_id`           INT UNSIGNED NOT NULL,
  `symbol`            VARCHAR(20)  NOT NULL,
  `isin`              VARCHAR(12)  NULL,
  `stock_name`        VARCHAR(150) NOT NULL,
  `quantity`          DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  `avg_price`         DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `current_price`     DECIMAL(12,4) NULL,
  `invested_value`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `current_value`     DECIMAL(14,2) NULL,
  `weight_pct`        DECIMAL(6,2)  NULL COMMENT 'Weight in basket %',
  `last_rebalanced`   DATE NULL,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sc_holding_portfolio` (`portfolio_id`),
  INDEX `idx_sc_holding_user` (`user_id`),
  UNIQUE KEY `uq_sc_portfolio_symbol` (`portfolio_id`, `symbol`),
  CONSTRAINT `fk_sc_holding_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `smallcase_portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stock-level holdings per smallcase portfolio';

-- ── Transactions (invest / SIP / rebalance / withdraw) ───────
CREATE TABLE IF NOT EXISTS `smallcase_transactions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`  INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `txn_type`      ENUM('invest','sip','rebalance','withdraw','dividend') NOT NULL,
  `txn_date`      DATE NOT NULL,
  `amount`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes`         VARCHAR(255) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sc_txn_portfolio` (`portfolio_id`),
  INDEX `idx_sc_txn_user` (`user_id`),
  INDEX `idx_sc_txn_date` (`txn_date`),
  CONSTRAINT `fk_sc_txn_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `smallcase_portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Smallcase invest/SIP/rebalance transactions';

-- ── Value snapshot (for performance chart) ───────────────────
CREATE TABLE IF NOT EXISTS `smallcase_value_history` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`  INT UNSIGNED NOT NULL,
  `snap_date`     DATE NOT NULL,
  `invested`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `current_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `xirr`          DECIMAL(8,4) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sc_snap` (`portfolio_id`, `snap_date`),
  INDEX `idx_sc_snap_date` (`snap_date`),
  CONSTRAINT `fk_sc_hist_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `smallcase_portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Daily value snapshots for XIRR / chart';

SELECT 'Smallcase Portfolio Sync migration t120 complete ✅' AS status;
SHOW TABLES LIKE 'smallcase%';
