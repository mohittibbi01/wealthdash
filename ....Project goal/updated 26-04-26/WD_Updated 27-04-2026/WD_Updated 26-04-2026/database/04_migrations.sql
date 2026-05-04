-- ============================================================
-- WealthDash — 04_migrations.sql
-- STEP 4: Schema Migrations (for EXISTING databases only)
-- ============================================================
-- Fresh install: SKIP this file (01_schema.sql already has everything)
-- Existing DB:   Run this AFTER 01_schema.sql additions would fail
--
-- All statements use IF NOT EXISTS / IF EXISTS guards → safe to re-run.
-- ============================================================

-- ============================================================
-- MIGRATION: funds table — add all missing columns
-- (covers 007_fund_returns, 012_alpha_beta, 013_screener_metrics,
--  015_fund_holdings style_box, 016_fund_managers denorm, 023_fund_ratings)
-- ============================================================
ALTER TABLE `funds`
  -- Returns
  ADD COLUMN IF NOT EXISTS `returns_1y`              DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `returns_3y`              DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `returns_5y`              DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `returns_6m`              DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `returns_1m`              DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `returns_3m`              DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `returns_since_inception` DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `returns_updated_at`      DATETIME      DEFAULT NULL,
  -- Risk metrics
  ADD COLUMN IF NOT EXISTS `sharpe_ratio`            DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sortino_ratio`           DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `calmar_ratio`            DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_drawdown`            DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_drawdown_date`       DATE          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_drawdown_from_nav`   DECIMAL(12,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `standard_deviation`      DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `alpha`                   DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `beta`                    DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `r_squared`               DECIMAL(5,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `alpha_updated_at`        DATETIME      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `momentum_score`          DECIMAL(5,2)  DEFAULT NULL,
  -- Category averages
  ADD COLUMN IF NOT EXISTS `category_avg_1y`         DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `category_avg_3y`         DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `category_avg_5y`         DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `category_rank_1y`        SMALLINT      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `category_total`          SMALLINT      DEFAULT NULL,
  -- Manager denorm
  ADD COLUMN IF NOT EXISTS `manager_since`           DATE          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `manager_name`            VARCHAR(100)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `inception_date`          DATE          DEFAULT NULL,
  -- WD ratings
  ADD COLUMN IF NOT EXISTS `wd_stars`                TINYINT UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `rating_stars`            TINYINT(1)    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `health_score`            TINYINT UNSIGNED DEFAULT NULL,
  -- Style box (size + value → style_box GENERATED)
  ADD COLUMN IF NOT EXISTS `style_size`              ENUM('large','mid','small') DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `style_value`             ENUM('value','blend','growth') DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `style_drift_note`        VARCHAR(500)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `avg_market_cap_cr`       DECIMAL(14,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `avg_pe_ratio`            DECIMAL(8,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `style_updated_at`        DATETIME      DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ter_history_json`        TEXT          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `nav_date`                DATE          DEFAULT NULL;

-- NOTE: style_box is a GENERATED column — add separately if not exists
-- (ALTER TABLE cannot add GENERATED columns with IF NOT EXISTS in all MySQL versions)
-- Run manually if needed:
-- ALTER TABLE funds ADD COLUMN `style_box` VARCHAR(20) GENERATED ALWAYS AS (
--   CASE WHEN style_size IS NOT NULL AND style_value IS NOT NULL
--        THEN CONCAT(style_size, '_', style_value) ELSE NULL END
-- ) STORED;

-- Indexes
ALTER TABLE `funds`
  ADD INDEX IF NOT EXISTS `idx_funds_returns_1y`  (`returns_1y`),
  ADD INDEX IF NOT EXISTS `idx_funds_returns_3y`  (`returns_3y`),
  ADD INDEX IF NOT EXISTS `idx_funds_sharpe`      (`sharpe_ratio`),
  ADD INDEX IF NOT EXISTS `idx_funds_alpha`       (`alpha`),
  ADD INDEX IF NOT EXISTS `idx_funds_sortino`     (`sortino_ratio`),
  ADD INDEX IF NOT EXISTS `idx_funds_momentum`    (`momentum_score`),
  ADD INDEX IF NOT EXISTS `idx_funds_wd_stars`    (`wd_stars`),
  ADD INDEX IF NOT EXISTS `idx_funds_style_size`  (`style_size`),
  ADD INDEX IF NOT EXISTS `idx_funds_style_value` (`style_value`),
  ADD INDEX IF NOT EXISTS `idx_funds_cat_avg_1y`  (`category_avg_1y`),
  ADD INDEX IF NOT EXISTS `idx_funds_cat_rank_1y` (`category_rank_1y`);

SELECT 'funds table columns migrated ✅' AS status;

-- ============================================================
-- MIGRATION: mf_holdings — missing columns
-- ============================================================
ALTER TABLE `mf_holdings`
  ADD COLUMN IF NOT EXISTS `investment_fy`       VARCHAR(10) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `withdrawable_date`   DATE        DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `first_purchase_date` DATE        DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `status`              ENUM('active','redeemed') NOT NULL DEFAULT 'active';

ALTER TABLE `mf_holdings`
  ADD INDEX IF NOT EXISTS `idx_mfh_portfolio_status` (`portfolio_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_mfh_portfolio_fund`   (`portfolio_id`, `fund_id`);

SELECT 'mf_holdings migrated ✅' AS status;

-- ============================================================
-- MIGRATION: mf_transactions — missing columns + indexes
-- ============================================================
ALTER TABLE `mf_transactions`
  ADD COLUMN IF NOT EXISTS `investment_fy` VARCHAR(10) DEFAULT NULL;

ALTER TABLE `mf_transactions`
  ADD INDEX IF NOT EXISTS `idx_mft_portfolio_date` (`portfolio_id`, `txn_date`),
  ADD INDEX IF NOT EXISTS `idx_mft_portfolio_type` (`portfolio_id`, `txn_type`),
  ADD INDEX IF NOT EXISTS `idx_mft_fy`             (`investment_fy`);

SELECT 'mf_transactions migrated ✅' AS status;

-- ============================================================
-- MIGRATION: nav_history — covering index
-- ============================================================
ALTER TABLE `nav_history`
  ADD INDEX IF NOT EXISTS `idx_navh_fund_date_nav` (`fund_id`, `nav_date`, `nav`);

SELECT 'nav_history index added ✅' AS status;

-- ============================================================
-- MIGRATION: sip_schedules — step-up + goal columns (t174/025)
-- ============================================================
ALTER TABLE `sip_schedules`
  ADD COLUMN IF NOT EXISTS `step_up_pct`          DECIMAL(5,2)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step_up_frequency`    ENUM('annual','semi_annual') DEFAULT 'annual',
  ADD COLUMN IF NOT EXISTS `step_up_next_date`    DATE          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `step_up_max_amount`   DECIMAL(12,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `goal_id`              INT UNSIGNED  DEFAULT NULL;

ALTER TABLE `sip_schedules`
  ADD INDEX IF NOT EXISTS `idx_sip_goal` (`goal_id`);

SELECT 'sip_schedules migrated ✅' AS status;

-- ============================================================
-- MIGRATION: users — 2FA columns (t386)
-- ============================================================
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `totp_secret`       VARCHAR(64)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `totp_enabled`      TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `totp_backup_codes` TEXT         DEFAULT NULL;

SELECT 'users 2FA columns migrated ✅' AS status;

-- ============================================================
-- MIGRATION: nav_download_queue — missing columns (t42)
-- ============================================================
ALTER TABLE `nav_download_queue`
  ADD COLUMN IF NOT EXISTS `retry_count`       TINYINT      NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `nav_records_added` INT          NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `error_msg`         VARCHAR(500)          DEFAULT NULL;

SELECT 'nav_download_queue migrated ✅' AS status;

-- ============================================================
-- MIGRATION: New tables (idempotent — IF NOT EXISTS)
-- ============================================================

-- Migration 007: fund_rolling_returns
CREATE TABLE IF NOT EXISTS `fund_rolling_returns` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `period` enum('1Y','3Y','5Y') NOT NULL,
  `min_return` decimal(8,4) DEFAULT NULL,
  `max_return` decimal(8,4) DEFAULT NULL,
  `avg_return` decimal(8,4) DEFAULT NULL,
  `median_return` decimal(8,4) DEFAULT NULL,
  `positive_periods_pct` decimal(5,2) DEFAULT NULL,
  `total_windows` int UNSIGNED DEFAULT NULL,
  `calculated_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_period_date` (`fund_id`, `period`, `calculated_date`),
  KEY `idx_fund` (`fund_id`), KEY `idx_period` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 008: nfo_tracker
CREATE TABLE IF NOT EXISTS `nfo_tracker` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_name` varchar(300) NOT NULL, `amc` varchar(200) NOT NULL,
  `scheme_code` varchar(50) DEFAULT NULL, `category` varchar(100) DEFAULT NULL,
  `nfo_price` decimal(10,4) DEFAULT 10.0000, `min_investment` decimal(12,2) DEFAULT 5000.00,
  `open_date` date NOT NULL, `close_date` date NOT NULL, `allotment_date` date DEFAULT NULL,
  `status` enum('upcoming','open','closing_soon','closed','allotted') NOT NULL DEFAULT 'upcoming',
  `fund_type` enum('open_ended','close_ended','interval') DEFAULT 'open_ended',
  `tax_saving` tinyint(1) NOT NULL DEFAULT 0,
  `benchmark` varchar(200) DEFAULT NULL, `fund_manager` varchar(200) DEFAULT NULL,
  `amc_website` varchar(500) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nfo_name_open` (`fund_name`(150), `open_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 009/010: mf_watchlist + alerts + history
CREATE TABLE IF NOT EXISTS `mf_watchlist` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `fund_id` int(10) UNSIGNED NOT NULL,
  `notes` varchar(300) DEFAULT NULL, `alert_nav` decimal(10,4) DEFAULT NULL,
  `alert_type` enum('above','below') DEFAULT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_fund` (`user_id`, `fund_id`),
  KEY `idx_wl_user` (`user_id`), KEY `idx_wl_fund` (`fund_id`),
  CONSTRAINT `fk_wl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wl_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mf_watchlist_alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `fund_id` int(10) UNSIGNED NOT NULL,
  `alert_type` enum('nav_above','nav_below','return_1y_above','return_3y_above',
                     'sharpe_above','aum_above','expense_below','multi_condition') NOT NULL,
  `target_value` decimal(12,4) DEFAULT NULL, `conditions_json` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_triggered` datetime DEFAULT NULL, `snooze_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wla_user` (`user_id`), KEY `idx_wla_fund` (`fund_id`), KEY `idx_wla_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mf_alert_history` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id` int(10) UNSIGNED NOT NULL, `user_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL, `trigger_val` decimal(12,4) DEFAULT NULL,
  `triggered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ah_alert` (`alert_id`), KEY `idx_ah_user` (`user_id`), KEY `idx_ah_date` (`triggered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 010: notifications + notification_prefs
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `type` varchar(60) NOT NULL,
  `title` varchar(300) NOT NULL, `body` text DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL, `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `triggered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_unread` (`user_id`, `is_read`, `triggered_at`), KEY `idx_notif_type` (`type`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notification_prefs` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `nav_alerts` tinyint(1) NOT NULL DEFAULT 1, `fd_maturity` tinyint(1) NOT NULL DEFAULT 1,
  `sip_reminder` tinyint(1) NOT NULL DEFAULT 1, `drawdown_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `nfo_alerts` tinyint(1) NOT NULL DEFAULT 1, `goal_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 011: price_alerts (MF)
CREATE TABLE IF NOT EXISTS `price_alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `fund_id` int(10) UNSIGNED NOT NULL,
  `type` enum('above','below') NOT NULL, `target_nav` decimal(12,4) NOT NULL,
  `note` varchar(300) DEFAULT NULL, `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `triggered_at` datetime DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_user` (`user_id`, `is_active`), KEY `idx_pa_fund` (`fund_id`),
  CONSTRAINT `fk_pa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 013: fund_benchmark_map + benchmark_nav
CREATE TABLE IF NOT EXISTS `fund_benchmark_map` (
  `fund_id` int(10) UNSIGNED NOT NULL, `benchmark_code` varchar(30) NOT NULL,
  `benchmark_name` varchar(80) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`fund_id`), KEY `idx_benchmark` (`benchmark_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `benchmark_nav` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL, `nav_date` date NOT NULL, `nav_value` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code_date` (`code`, `nav_date`),
  KEY `idx_code` (`code`), KEY `idx_date` (`nav_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 013/016: fund_managers
CREATE TABLE IF NOT EXISTS `fund_managers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL, `manager_name` varchar(200) NOT NULL,
  `from_date` date NOT NULL, `to_date` date DEFAULT NULL, `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fm_fund` (`fund_id`), KEY `idx_fm_manager` (`manager_name`(100)), KEY `idx_fm_current` (`is_current`),
  CONSTRAINT `fk_fm_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 014/027: mf_dividends
CREATE TABLE IF NOT EXISTS `mf_dividends` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL, `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(50) DEFAULT NULL, `dividend_date` date NOT NULL,
  `nav_before` decimal(12,4) DEFAULT NULL, `nav_after` decimal(12,4) DEFAULT NULL,
  `rate_per_unit` decimal(10,4) NOT NULL, `units_held` decimal(14,4) DEFAULT NULL,
  `amount_received` decimal(14,2) DEFAULT NULL, `tds_deducted` decimal(10,2) DEFAULT 0.00,
  `net_received` decimal(14,2) DEFAULT NULL, `is_reinvested` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dividend` (`portfolio_id`, `fund_id`, `folio_number`(30), `dividend_date`),
  KEY `idx_div_portfolio` (`portfolio_id`), KEY `idx_div_fund` (`fund_id`), KEY `idx_div_date` (`dividend_date`),
  CONSTRAINT `fk_div_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_div_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 015: mf_saved_screens + fund_ratings + mf_holdings_notes + fund_ter_history + fund_dividends + import_logs
CREATE TABLE IF NOT EXISTS `mf_saved_screens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `name` varchar(80) NOT NULL,
  `filters_json` text NOT NULL, `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `share_token` varchar(32) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`), KEY `idx_public` (`is_public`), KEY `idx_token` (`share_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fund_ratings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `fund_id` int(10) UNSIGNED NOT NULL,
  `stars` tinyint UNSIGNED NOT NULL, `rating_stars` tinyint(1) DEFAULT NULL,
  `score_total` decimal(6,4) DEFAULT NULL, `score_breakdown` json DEFAULT NULL,
  `return_score` decimal(5,2) DEFAULT NULL, `consistency_score` decimal(5,2) DEFAULT NULL,
  `risk_score` decimal(5,2) DEFAULT NULL, `cost_score` decimal(5,2) DEFAULT NULL,
  `manager_score` decimal(5,2) DEFAULT NULL, `total_score` decimal(5,2) DEFAULT NULL,
  `calc_date` date NOT NULL DEFAULT (CURDATE()),
  `rated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fr_fund` (`fund_id`),
  KEY `idx_fr_stars` (`stars`), KEY `idx_fr_rated` (`rated_at`),
  CONSTRAINT `fk_fr_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mf_holdings_notes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `holding_id` int(10) UNSIGNED NOT NULL,
  `note_text` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_user_holding` (`user_id`, `holding_id`), KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fund_ter_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL, `ter_pct` decimal(5,3) NOT NULL, `effective_date` date NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fund_date` (`fund_id`, `effective_date`), KEY `idx_fund` (`fund_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fund_dividends` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL, `record_date` date NOT NULL, `ex_date` date DEFAULT NULL,
  `dividend_per_unit` decimal(10,4) NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fund_date` (`fund_id`, `record_date`),
  KEY `idx_fund` (`fund_id`), KEY `idx_date` (`record_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `import_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `filename` varchar(200) NOT NULL,
  `format` varchar(30) NOT NULL, `imported_count` int UNSIGNED DEFAULT 0,
  `skipped_count` int UNSIGNED DEFAULT 0, `failed_count` int UNSIGNED DEFAULT 0,
  `error_json` text DEFAULT NULL, `status` enum('success','partial','failed') NOT NULL DEFAULT 'success',
  `imported_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_user` (`user_id`), KEY `idx_date` (`imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 015/028: fund_stock_holdings
CREATE TABLE IF NOT EXISTS `fund_stock_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL, `stock_name` varchar(300) NOT NULL,
  `isin` varchar(15) DEFAULT NULL, `sector` varchar(100) DEFAULT NULL,
  `weight_pct` decimal(6,3) NOT NULL, `market_cap` enum('large','mid','small','other') DEFAULT NULL,
  `month_year` date NOT NULL, `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_fund_stock_month` (`fund_id`, `isin`(15), `month_year`),
  KEY `idx_fsh_fund` (`fund_id`), KEY `idx_fsh_isin` (`isin`),
  KEY `idx_fsh_sector` (`sector`(50)), KEY `idx_fsh_month` (`month_year`),
  CONSTRAINT `fk_fsh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 017: expense_ratio_history
CREATE TABLE IF NOT EXISTS `expense_ratio_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL, `expense_ratio` decimal(6,4) NOT NULL,
  `plan_type` enum('direct','regular') NOT NULL DEFAULT 'direct',
  `recorded_date` date NOT NULL, `source` varchar(100) DEFAULT 'amfi_website',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_exp_fund_month` (`fund_id`, `plan_type`, `recorded_date`),
  KEY `idx_erh_fund` (`fund_id`), KEY `idx_erh_date` (`recorded_date`),
  CONSTRAINT `fk_erh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 018: rebalancing_targets
CREATE TABLE IF NOT EXISTS `rebalancing_targets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `asset_class` enum('equity','debt','gold','cash','international','other') NOT NULL,
  `target_pct` decimal(5,2) NOT NULL, `drift_threshold` decimal(5,2) NOT NULL DEFAULT 5.00,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_rebal_portfolio_asset` (`portfolio_id`, `asset_class`),
  CONSTRAINT `fk_rebal_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 019: nav_proxy_cache
CREATE TABLE IF NOT EXISTS `nav_proxy_cache` (
  `fund_id` int(10) UNSIGNED NOT NULL,
  `period` enum('1M','3M','6M','1Y','3Y','5Y','ALL') NOT NULL,
  `data_json` longtext NOT NULL, `data_points` int NOT NULL DEFAULT 0,
  `cached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `source` enum('nav_history','mfapi') NOT NULL DEFAULT 'nav_history',
  PRIMARY KEY (`fund_id`, `period`), KEY `idx_npc_cached_at` (`cached_at`),
  CONSTRAINT `fk_npc_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 020: net_worth_snapshots
CREATE TABLE IF NOT EXISTS `net_worth_snapshots` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `snapshot_date` date NOT NULL,
  `total_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `mf_value` decimal(20,2) NOT NULL DEFAULT 0.00, `stock_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fd_value` decimal(20,2) NOT NULL DEFAULT 0.00, `savings_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `nps_value` decimal(20,2) NOT NULL DEFAULT 0.00, `po_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `gold_value` decimal(20,2) NOT NULL DEFAULT 0.00, `crypto_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `other_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_nws` (`user_id`, `snapshot_date`), KEY `idx_nws_user` (`user_id`),
  CONSTRAINT `fk_nws_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 021: mf_peak_progress
CREATE TABLE IF NOT EXISTS `mf_peak_progress` (
  `scheme_code` varchar(20) NOT NULL, `last_processed_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','error') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`scheme_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 025: sector_rotation_cache
CREATE TABLE IF NOT EXISTS `sector_rotation_cache` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sector_name` varchar(100) NOT NULL, `sector_slug` varchar(60) NOT NULL,
  `ret_1m` decimal(8,4) DEFAULT NULL, `ret_3m` decimal(8,4) DEFAULT NULL,
  `ret_6m` decimal(8,4) DEFAULT NULL, `ret_1y` decimal(8,4) DEFAULT NULL,
  `fund_count` smallint UNSIGNED DEFAULT 0, `top_fund_id` int UNSIGNED DEFAULT NULL,
  `data_source` enum('category_proxy','amfi_holdings') NOT NULL DEFAULT 'category_proxy',
  `computed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_sr_sector` (`sector_slug`), KEY `idx_sr_ret1y` (`ret_1y`),
  CONSTRAINT `fk_sr_top_fund` FOREIGN KEY (`top_fund_id`) REFERENCES `funds`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 026: investment_calendar_events
CREATE TABLE IF NOT EXISTS `investment_calendar_events` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED DEFAULT NULL, `event_date` date NOT NULL,
  `event_type` enum('tax_deadline','sebi_compliance','rbi_policy','budget','sgb_window',
                     'nfo_open','nfo_close','advance_tax','custom') NOT NULL DEFAULT 'custom',
  `title` varchar(200) NOT NULL, `description` text DEFAULT NULL,
  `icon` varchar(10) DEFAULT '📅', `color` varchar(20) DEFAULT '#3b82f6',
  `is_important` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_ice_date` (`event_date`), KEY `idx_ice_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 017_sgb: sgb_holdings + sgb_interest_log
CREATE TABLE IF NOT EXISTS `sgb_holdings` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `portfolio_id` int(10) UNSIGNED NOT NULL, `series_name` varchar(100) NOT NULL,
  `tranche` varchar(50) DEFAULT NULL, `units` decimal(10,4) NOT NULL DEFAULT 0,
  `issue_price` decimal(12,4) NOT NULL, `issue_date` date NOT NULL, `maturity_date` date NOT NULL,
  `current_gold_price` decimal(12,4) DEFAULT NULL, `face_value` decimal(12,4) NOT NULL DEFAULT 0,
  `current_value` decimal(14,4) DEFAULT NULL, `interest_rate` decimal(5,4) NOT NULL DEFAULT 0.025,
  `next_interest_date` date DEFAULT NULL, `isin` varchar(20) DEFAULT NULL,
  `tax_exemption` tinyint(1) NOT NULL DEFAULT 1, `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sgb_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_sgb_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sgb_interest_log` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sgb_id` int(10) UNSIGNED NOT NULL, `portfolio_id` int(10) UNSIGNED NOT NULL,
  `period` varchar(20) NOT NULL, `amount` decimal(12,4) NOT NULL, `paid_on` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sgb_interest_sgb` (`sgb_id`),
  CONSTRAINT `fk_sgb_interest_sgb` FOREIGN KEY (`sgb_id`) REFERENCES `sgb_holdings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 019_esop: esop_grants + esop_vesting_events
CREATE TABLE IF NOT EXISTS `esop_grants` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `portfolio_id` int(10) UNSIGNED NOT NULL, `company_name` varchar(200) NOT NULL,
  `grant_type` enum('ESOP','RSU','SAR') NOT NULL DEFAULT 'ESOP', `grant_date` date NOT NULL,
  `total_options` int UNSIGNED NOT NULL, `exercise_price` decimal(12,4) NOT NULL DEFAULT 0,
  `vesting_start` date NOT NULL, `vesting_cliff_months` smallint UNSIGNED DEFAULT 12,
  `vesting_period_months` smallint UNSIGNED DEFAULT 48, `vesting_schedule` varchar(50) DEFAULT '1/4 per year',
  `current_fmv` decimal(12,4) DEFAULT NULL,
  `exercise_price_total` decimal(16,4) AS (total_options * exercise_price) STORED,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `status` enum('active','fully_vested','lapsed','exercised_partial','exercised_full') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_esop_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_esop_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `esop_vesting_events` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grant_id` int(10) UNSIGNED NOT NULL, `vest_date` date NOT NULL, `units_vested` int UNSIGNED NOT NULL,
  `fmv_on_vest` decimal(12,4) DEFAULT NULL, `perquisite_tax` decimal(14,4) DEFAULT NULL,
  `tax_slab_pct` decimal(5,2) DEFAULT NULL, `is_exercised` tinyint(1) NOT NULL DEFAULT 0,
  `exercise_date` date DEFAULT NULL, `exercise_price` decimal(12,4) DEFAULT NULL,
  `sale_date` date DEFAULT NULL, `sale_price` decimal(12,4) DEFAULT NULL,
  `capital_gain` decimal(14,4) DEFAULT NULL, `gain_type` enum('STCG','LTCG') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_esop_vest_grant` (`grant_id`),
  CONSTRAINT `fk_esop_vest_grant` FOREIGN KEY (`grant_id`) REFERENCES `esop_grants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 025: goals + goal_mf_links + goal_stock_links + goal_holdings
CREATE TABLE IF NOT EXISTS `goals` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY, `user_id` int(10) UNSIGNED NOT NULL,
  `goal_name` varchar(120) NOT NULL,
  `goal_type` enum('retirement','education','home','vehicle','emergency','vacation','wedding','business','other') DEFAULT 'other',
  `target_amount` decimal(16,2) NOT NULL, `target_date` date NOT NULL,
  `current_amount` decimal(16,2) NOT NULL DEFAULT 0, `monthly_sip` decimal(12,2) DEFAULT NULL,
  `expected_return` decimal(5,2) DEFAULT 12.00, `inflation_rate` decimal(5,2) DEFAULT 6.00,
  `priority` tinyint UNSIGNED NOT NULL DEFAULT 2, `color` varchar(20) DEFAULT '#3b82f6',
  `emoji` varchar(10) DEFAULT '🎯',
  `status` enum('active','achieved','paused','cancelled') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_goals_user` (`user_id`),
  CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goal_mf_links` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `goal_id` int(10) UNSIGNED NOT NULL, `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL, `allocation_pct` decimal(5,2) DEFAULT 100.00,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_goal_mf` (`goal_id`, `fund_id`, `portfolio_id`),
  CONSTRAINT `fk_goal_mf_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goal_stock_links` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `goal_id` int(10) UNSIGNED NOT NULL, `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL, `allocation_pct` decimal(5,2) DEFAULT 100.00,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_goal_stock` (`goal_id`, `stock_id`, `portfolio_id`),
  CONSTRAINT `fk_goal_stock_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goal_holdings` (
  `id` bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `goal_id` int(10) UNSIGNED NOT NULL, `user_id` int(10) UNSIGNED NOT NULL,
  `asset_type` enum('mf','fd','nps','stock','crypto','other') NOT NULL, `asset_id` int(10) UNSIGNED NOT NULL,
  `allocated_pct` decimal(5,2) DEFAULT 100.00, `notes` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_goal_asset` (`goal_id`, `asset_type`, `asset_id`), KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- t344/t345: stock_price_alerts
CREATE TABLE IF NOT EXISTS `stock_price_alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL, `stock_id` int(10) UNSIGNED NOT NULL,
  `symbol` varchar(30) NOT NULL, `company_name` varchar(200) DEFAULT NULL,
  `alert_type` enum('above','below','pct_up','pct_down') NOT NULL,
  `target_price` decimal(12,2) NOT NULL, `note` varchar(300) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1, `triggered_at` datetime DEFAULT NULL,
  `triggered_price` decimal(12,2) DEFAULT NULL, `notify_browser` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_spa_user` (`user_id`, `is_active`),
  KEY `idx_spa_stock` (`stock_id`), KEY `idx_spa_symbol` (`symbol`),
  CONSTRAINT `fk_spa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spa_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- t347: user_settings
CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `number_format` varchar(10) NOT NULL DEFAULT 'indian',
  `compact_large_numbers` tinyint(1) NOT NULL DEFAULT 1,
  `currency_symbol` varchar(5) NOT NULL DEFAULT '₹',
  `decimal_places` tinyint NOT NULL DEFAULT 2,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 038/043: crypto_holdings + crypto_transactions + crypto_price_cache
CREATE TABLE IF NOT EXISTS `crypto_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `portfolio_id` int(10) UNSIGNED NOT NULL,
  `coin_id` varchar(60) NOT NULL, `coin_symbol` varchar(20) NOT NULL, `coin_name` varchar(100) NOT NULL,
  `quantity` decimal(24,8) NOT NULL DEFAULT 0, `avg_buy_price` decimal(20,4) NOT NULL DEFAULT 0,
  `total_invested` decimal(20,2) NOT NULL DEFAULT 0, `current_price` decimal(20,4) DEFAULT 0,
  `current_value_inr` decimal(15,2) DEFAULT 0, `exchange` varchar(60) DEFAULT NULL,
  `wallet_address` varchar(200) DEFAULT NULL, `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `price_updated_at` datetime DEFAULT NULL, `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_portfolio` (`portfolio_id`), KEY `idx_coin` (`coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `crypto_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, `portfolio_id` int(10) UNSIGNED NOT NULL,
  `coin_id` varchar(60) NOT NULL, `coin_symbol` varchar(20) NOT NULL,
  `txn_type` enum('buy','sell','transfer_in','transfer_out','reward') NOT NULL,
  `quantity` decimal(24,8) NOT NULL, `price_inr` decimal(20,4) NOT NULL,
  `fee_inr` decimal(10,2) DEFAULT 0, `total_inr` decimal(15,2) NOT NULL,
  `tds_deducted` decimal(10,2) DEFAULT 0, `exchange` varchar(60) DEFAULT NULL,
  `txn_date` date NOT NULL, `notes` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_portfolio` (`portfolio_id`),
  KEY `idx_coin` (`coin_id`), KEY `idx_date` (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `crypto_price_cache` (
  `id` int(10) AUTO_INCREMENT PRIMARY KEY, `coin_id` varchar(100) NOT NULL,
  `symbol` varchar(20) NOT NULL, `name` varchar(100) NOT NULL,
  `price_inr` decimal(20,4) DEFAULT 0, `price_usd` decimal(20,8) DEFAULT 0,
  `change_24h` decimal(10,4) DEFAULT 0, `market_cap` bigint DEFAULT 0,
  `fetched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_coin` (`coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 041: user_sessions + rate_limit_buckets + ai tables + fd alert tables + investment_journal + credit_cards
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `session_token` varchar(128) NOT NULL, `device_name` varchar(120) DEFAULT NULL,
  `device_type` enum('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
  `browser` varchar(80) DEFAULT NULL, `ip_address` varchar(45) DEFAULT NULL,
  `last_active` datetime NOT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_token` (`session_token`),
  KEY `idx_user` (`user_id`), KEY `idx_active` (`last_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rate_limit_buckets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `bucket_key` varchar(120) NOT NULL,
  `hits` int(10) UNSIGNED NOT NULL DEFAULT 0, `window_start` datetime NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_bucket` (`bucket_key`), KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_chat_history` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `role` enum('user','assistant') NOT NULL, `message` text NOT NULL,
  `context_id` varchar(36) DEFAULT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_user_ctx` (`user_id`, `context_id`), KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_portfolio_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `review_month` varchar(7) NOT NULL, `review_type` varchar(30) NOT NULL DEFAULT 'monthly',
  `grade` char(2) DEFAULT NULL, `score` tinyint UNSIGNED DEFAULT NULL,
  `summary` text DEFAULT NULL, `strengths` text DEFAULT NULL,
  `weaknesses` text DEFAULT NULL, `actions` text DEFAULT NULL, `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_user_month` (`user_id`, `review_month`, `review_type`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_anomalies` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `anomaly_type` varchar(60) NOT NULL, `severity` enum('info','warning','critical') DEFAULT 'warning',
  `title` varchar(200) NOT NULL, `description` text DEFAULT NULL, `data_json` text DEFAULT NULL,
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0, `detected_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dismissed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`), KEY `idx_user` (`user_id`), KEY `idx_severity` (`severity`),
  KEY `idx_dismissed` (`is_dismissed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fd_maturity_alerts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `fd_id` int(10) UNSIGNED NOT NULL, `alert_days` tinyint UNSIGNED NOT NULL,
  `is_sent` tinyint(1) NOT NULL DEFAULT 0, `sent_at` datetime DEFAULT NULL,
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_fd_days` (`fd_id`, `alert_days`),
  KEY `idx_user` (`user_id`), KEY `idx_sent` (`is_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fd_market_rates` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `bank_name` varchar(80) NOT NULL,
  `tenure_label` varchar(30) NOT NULL, `rate_general` decimal(5,2) NOT NULL,
  `rate_senior` decimal(5,2) NOT NULL, `effective_date` date DEFAULT (CURDATE()),
  `source_url` varchar(300) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fd_interest_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `fd_id` int(10) UNSIGNED NOT NULL, `credit_date` date NOT NULL,
  `interest_earned` decimal(12,2) NOT NULL, `tds_deducted` decimal(10,2) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_user` (`user_id`), KEY `idx_date` (`credit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 044: nudge_dismissals + spending_discipline + investment_journal + credit_cards
CREATE TABLE IF NOT EXISTS `nudge_dismissals` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `nudge_id` varchar(60) NOT NULL, `dismissed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_user_nudge` (`user_id`, `nudge_id`), KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `spending_discipline` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `monthly_income` decimal(15,2) NOT NULL DEFAULT 0, `target_pct` decimal(5,2) NOT NULL DEFAULT 20.00,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `investment_journal` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `entry_date` date NOT NULL, `category` varchar(60) DEFAULT NULL,
  `title` varchar(200) NOT NULL, `body` text NOT NULL,
  `mood` enum('bullish','bearish','neutral','anxious','confident') DEFAULT 'neutral',
  `linked_asset_type` varchar(20) DEFAULT NULL, `linked_asset_id` int UNSIGNED DEFAULT NULL,
  `tags` varchar(300) DEFAULT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_user` (`user_id`), KEY `idx_date` (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `credit_cards` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` int(10) UNSIGNED NOT NULL,
  `card_name` varchar(100) NOT NULL, `bank_name` varchar(100) NOT NULL,
  `last4` char(4) DEFAULT NULL, `credit_limit` decimal(12,2) DEFAULT NULL,
  `outstanding` decimal(12,2) DEFAULT 0.00, `due_date` date DEFAULT NULL,
  `min_due` decimal(10,2) DEFAULT 0.00, `interest_rate` decimal(5,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1, `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 045: missing indexes (performance)
ALTER TABLE `mf_holdings`
  ADD INDEX IF NOT EXISTS `idx_mfh_portfolio_status` (`portfolio_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_mfh_portfolio_fund`   (`portfolio_id`, `fund_id`);

ALTER TABLE `mf_transactions`
  ADD INDEX IF NOT EXISTS `idx_mft_portfolio_date`   (`portfolio_id`, `txn_date`),
  ADD INDEX IF NOT EXISTS `idx_mft_portfolio_type`   (`portfolio_id`, `txn_type`),
  ADD INDEX IF NOT EXISTS `idx_mft_fy`               (`investment_fy`);

ALTER TABLE `nav_history`
  ADD INDEX IF NOT EXISTS `idx_navh_fund_date_nav`   (`fund_id`, `nav_date`, `nav`);

SELECT '✅ All migrations complete' AS result;
