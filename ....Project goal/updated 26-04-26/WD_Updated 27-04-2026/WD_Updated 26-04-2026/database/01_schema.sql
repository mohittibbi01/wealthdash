-- ============================================================
-- WealthDash — 01_schema.sql
-- STEP 1: Base Schema — Run this FIRST on a fresh database
-- ============================================================
-- Contains: All core tables (43 tables) with correct final schema
-- Includes all columns that were later added via migrations
-- (merged to avoid ALTER TABLE errors on fresh installs)
-- ============================================================
-- HOW TO RUN:
--   Option A: phpMyAdmin → wealthdash DB → SQL tab → paste → Go
--   Option B: mysql -u root -p wealthdash < 01_schema.sql
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- TABLE: app_settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `profile_pic` varchar(500) DEFAULT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_backup_codes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: user_settings
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `number_format` varchar(10) NOT NULL DEFAULT 'indian' COMMENT 'indian | international',
  `compact_large_numbers` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = show 1.5L/2.3Cr',
  `currency_symbol` varchar(5) NOT NULL DEFAULT '₹',
  `decimal_places` tinyint NOT NULL DEFAULT 2,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `last_active` datetime NOT NULL,
  `data` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`),
  KEY `idx_sessions_active` (`last_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: user_sessions (device tracking, t387)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `session_token` varchar(128) NOT NULL,
  `device_name` varchar(120) DEFAULT NULL,
  `device_type` enum('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
  `browser` varchar(80) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `last_active` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`session_token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_active` (`last_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: rate_limit_buckets (t390)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rate_limit_buckets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket_key` varchar(120) NOT NULL,
  `hits` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bucket` (`bucket_key`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: login_attempts
-- ============================================================
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `success` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_la_email` (`email`),
  KEY `idx_la_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: otp_tokens
-- ============================================================
CREATE TABLE IF NOT EXISTS `otp_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(10) NOT NULL,
  `purpose` enum('2fa','email_verify','password_reset') NOT NULL DEFAULT '2fa',
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: password_resets
-- ============================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pr_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: google_auth
-- ============================================================
CREATE TABLE IF NOT EXISTS `google_auth` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `google_id` varchar(100) NOT NULL,
  `access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_google_id` (`google_id`),
  KEY `idx_ga_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: audit_log
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(60) DEFAULT NULL,
  `record_id` int(10) UNSIGNED DEFAULT NULL,
  `old_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: portfolios
-- ============================================================
CREATE TABLE IF NOT EXISTS `portfolios` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL DEFAULT 'My Portfolio',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_port_user` (`user_id`),
  CONSTRAINT `fk_port_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_houses
-- ============================================================
CREATE TABLE IF NOT EXISTS `fund_houses` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `short_name` varchar(50) DEFAULT NULL,
  `website` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fh_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: funds  (MERGED — includes all ALTER TABLE additions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `funds` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `scheme_code` varchar(20) NOT NULL,
  `isin` varchar(20) DEFAULT NULL,
  `isin_reinvest` varchar(20) DEFAULT NULL,
  `fund_house_id` int(10) UNSIGNED DEFAULT NULL,
  `scheme_name` varchar(300) NOT NULL,
  `scheme_type` varchar(100) DEFAULT NULL,
  `scheme_category` varchar(100) DEFAULT NULL,
  `scheme_sub_category` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'ELSS/Equity/Debt/Hybrid/Index/Liquid',
  -- NAV data
  `nav` decimal(14,4) DEFAULT NULL,
  `nav_date` date DEFAULT NULL,
  -- Returns (original short names from base schema)
  `return_1y` decimal(8,4) DEFAULT NULL COMMENT '1-Year CAGR %',
  `return_3y` decimal(8,4) DEFAULT NULL COMMENT '3-Year CAGR %',
  `return_5y` decimal(8,4) DEFAULT NULL COMMENT '5-Year CAGR %',
  `return_since` decimal(8,4) DEFAULT NULL COMMENT 'Since inception return %',
  -- Extended returns (migration 007 aliases — kept for PHP compatibility)
  `returns_1y` decimal(8,4) DEFAULT NULL COMMENT 'Alias of return_1y — screener use',
  `returns_3y` decimal(8,4) DEFAULT NULL,
  `returns_5y` decimal(8,4) DEFAULT NULL,
  `returns_6m` decimal(8,4) DEFAULT NULL,
  `returns_1m` decimal(8,4) DEFAULT NULL,
  `returns_3m` decimal(8,4) DEFAULT NULL,
  `returns_since_inception` decimal(8,4) DEFAULT NULL,
  `returns_updated_at` datetime DEFAULT NULL,
  -- Risk metrics (migrations 007, 012, 013)
  `sharpe_ratio` decimal(8,4) DEFAULT NULL COMMENT 'Annualised Sharpe Ratio (Rf=6.5%)',
  `sortino_ratio` decimal(8,4) DEFAULT NULL COMMENT 'Sortino Ratio — downside risk',
  `calmar_ratio` decimal(8,4) DEFAULT NULL COMMENT 'Return / Max Drawdown',
  `max_drawdown` decimal(8,4) DEFAULT NULL COMMENT 'Max Drawdown % (peak to trough)',
  `max_drawdown_date` date DEFAULT NULL COMMENT 'Date of max drawdown trough',
  `max_drawdown_from_nav` decimal(12,4) DEFAULT NULL COMMENT 'NAV at peak before worst drawdown',
  `standard_deviation` decimal(8,4) DEFAULT NULL COMMENT 'Annualised volatility %',
  `alpha` decimal(8,4) DEFAULT NULL COMMENT 'Jensen Alpha % — excess over CAPM',
  `beta` decimal(8,4) DEFAULT NULL COMMENT 'Market sensitivity',
  `r_squared` decimal(5,2) DEFAULT NULL COMMENT 'R-Squared (0-100)',
  `alpha_updated_at` datetime DEFAULT NULL,
  `momentum_score` decimal(5,2) DEFAULT NULL COMMENT '0-100 weighted momentum',
  -- Category peer averages
  `category_avg_1y` decimal(8,4) DEFAULT NULL,
  `category_avg_3y` decimal(8,4) DEFAULT NULL,
  `category_avg_5y` decimal(8,4) DEFAULT NULL,
  `category_rank_1y` smallint DEFAULT NULL COMMENT 'Rank within category by 1Y (1=best)',
  `category_total` smallint DEFAULT NULL COMMENT 'Total funds in same category',
  -- Fund details
  `aum_cr` decimal(14,2) DEFAULT NULL,
  `expense_ratio` decimal(5,3) DEFAULT NULL,
  `exit_load_text` text DEFAULT NULL,
  `exit_load_pct` decimal(5,3) DEFAULT NULL,
  `exit_load_days` int DEFAULT NULL,
  `risk_level` varchar(30) DEFAULT NULL,
  `benchmark` varchar(200) DEFAULT NULL,
  `min_sip_amount` decimal(10,2) DEFAULT NULL,
  `min_lumpsum` decimal(10,2) DEFAULT NULL,
  `inception_date` date DEFAULT NULL,
  -- Fund manager (denormalised for screener speed)
  `fund_manager` varchar(200) DEFAULT NULL,
  `manager_since` date DEFAULT NULL,
  `manager_name` varchar(100) DEFAULT NULL,
  -- WealthDash ratings/scores
  `wd_stars` tinyint(1) UNSIGNED DEFAULT NULL COMMENT 'WD star rating 1-5',
  `rating_stars` tinyint(1) DEFAULT NULL COMMENT '1-5 WealthDash stars (screener alias)',
  `health_score` tinyint UNSIGNED DEFAULT NULL COMMENT '0-100',
  -- Style box (migration 024 — MERGED: uses generated column approach)
  `style_size` enum('large','mid','small') DEFAULT NULL COMMENT 'Size axis classification',
  `style_value` enum('value','blend','growth') DEFAULT NULL COMMENT 'Style axis classification',
  `style_box` varchar(20) GENERATED ALWAYS AS (
    CASE WHEN style_size IS NOT NULL AND style_value IS NOT NULL
         THEN CONCAT(style_size, '_', style_value)
         ELSE NULL END
  ) STORED COMMENT 'e.g. large_growth, mid_value',
  `style_drift_note` varchar(500) DEFAULT NULL,
  `avg_market_cap_cr` decimal(14,2) DEFAULT NULL,
  `avg_pe_ratio` decimal(8,2) DEFAULT NULL,
  `style_updated_at` datetime DEFAULT NULL,
  -- TER history (JSON snapshot for quick display)
  `ter_history_json` text DEFAULT NULL COMMENT 'Last 4 quarters TER',
  -- Status
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  KEY `idx_funds_category` (`category`(50)),
  KEY `idx_funds_returns_1y` (`returns_1y`),
  KEY `idx_funds_returns_3y` (`returns_3y`),
  KEY `idx_funds_sharpe` (`sharpe_ratio`),
  KEY `idx_funds_alpha` (`alpha`),
  KEY `idx_funds_sortino` (`sortino_ratio`),
  KEY `idx_funds_momentum` (`momentum_score`),
  KEY `idx_funds_wd_stars` (`wd_stars`),
  KEY `idx_funds_style_size` (`style_size`),
  KEY `idx_funds_style_value` (`style_value`),
  KEY `idx_funds_style_box` (`style_box`),
  KEY `idx_funds_cat_avg_1y` (`category_avg_1y`),
  KEY `idx_funds_cat_avg_3y` (`category_avg_3y`),
  KEY `idx_funds_cat_rank_1y` (`category_rank_1y`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nav_history
-- ============================================================
CREATE TABLE IF NOT EXISTS `nav_history` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `nav_date` date NOT NULL,
  `nav` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_navh_fund_date` (`fund_id`, `nav_date`),
  KEY `idx_navh_fund_date_nav` (`fund_id`, `nav_date`, `nav`),
  CONSTRAINT `fk_navh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_holdings
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(50) DEFAULT NULL,
  `platform` varchar(50) DEFAULT NULL,
  `units` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `avg_nav` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `invested_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(16,2) NOT NULL DEFAULT 0.00,
  `gain_loss` decimal(16,2) NOT NULL DEFAULT 0.00,
  `xirr` decimal(8,4) DEFAULT NULL,
  `sip_active` tinyint(1) NOT NULL DEFAULT 0,
  `swp_active` tinyint(1) NOT NULL DEFAULT 0,
  `first_investment_date` date DEFAULT NULL,
  `first_purchase_date` date DEFAULT NULL,
  `last_transaction_date` date DEFAULT NULL,
  `investment_fy` varchar(10) DEFAULT NULL COMMENT 'e.g. 2023-24',
  `withdrawable_date` date DEFAULT NULL,
  `status` enum('active','redeemed') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mfh_portfolio_status` (`portfolio_id`, `status`),
  KEY `idx_mfh_portfolio_fund` (`portfolio_id`, `fund_id`),
  CONSTRAINT `fk_mfh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mfh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_transactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `holding_id` int(10) UNSIGNED DEFAULT NULL,
  `txn_date` date NOT NULL,
  `txn_type` enum('buy','sell','switch_in','switch_out','sip','swp','dividend_reinvest') NOT NULL,
  `units` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `nav` decimal(14,4) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `folio_number` varchar(50) DEFAULT NULL,
  `investment_fy` varchar(10) DEFAULT NULL COMMENT 'e.g. 2023-24',
  `stcg_units` decimal(18,4) DEFAULT NULL,
  `ltcg_units` decimal(18,4) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mft_portfolio_date` (`portfolio_id`, `txn_date`),
  KEY `idx_mft_portfolio_type` (`portfolio_id`, `txn_type`),
  KEY `idx_mft_fy` (`investment_fy`),
  CONSTRAINT `fk_mft_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sip_schedules
-- ============================================================
CREATE TABLE IF NOT EXISTS `sip_schedules` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `frequency` enum('monthly','quarterly','weekly') NOT NULL DEFAULT 'monthly',
  `sip_day` tinyint UNSIGNED DEFAULT NULL COMMENT 'Day of month (1-31)',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `step_up_pct` decimal(5,2) DEFAULT NULL COMMENT 'Annual step-up % (t174)',
  `step_up_frequency` enum('annual','semi_annual') DEFAULT 'annual',
  `step_up_next_date` date DEFAULT NULL,
  `step_up_max_amount` decimal(12,2) DEFAULT NULL,
  `goal_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sip_portfolio` (`portfolio_id`),
  KEY `idx_sip_goal` (`goal_id`),
  CONSTRAINT `fk_sip_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nps_schemes
-- ============================================================
CREATE TABLE IF NOT EXISTS `nps_schemes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `pfm_name` varchar(100) NOT NULL,
  `scheme_name` varchar(200) NOT NULL,
  `scheme_code` varchar(30) NOT NULL,
  `tier` enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `asset_class` enum('E','C','G','A') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_code` (`scheme_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nps_holdings
-- ============================================================
CREATE TABLE IF NOT EXISTS `nps_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `scheme_id` int(10) UNSIGNED DEFAULT NULL,
  `pran` varchar(20) DEFAULT NULL,
  `pfm_name` varchar(100) DEFAULT NULL,
  `scheme_name` varchar(200) DEFAULT NULL,
  `tier` enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `asset_class` enum('E','C','G','A') DEFAULT NULL,
  `units` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `nav` decimal(14,4) DEFAULT NULL,
  `invested_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(16,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nps_user` (`user_id`),
  CONSTRAINT `fk_nps_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nps_nav_history
-- ============================================================
CREATE TABLE IF NOT EXISTS `nps_nav_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `scheme_id` int(10) UNSIGNED NOT NULL,
  `nav_date` date NOT NULL,
  `nav` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nps_nav` (`scheme_id`, `nav_date`),
  CONSTRAINT `fk_nps_nav_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nps_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `nps_transactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `holding_id` int(10) UNSIGNED NOT NULL,
  `txn_date` date NOT NULL,
  `txn_type` enum('contribution','withdrawal','switch') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `units` decimal(18,4) DEFAULT NULL,
  `nav` decimal(14,4) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nps_txn_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_master
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_master` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol` varchar(30) NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `exchange` enum('NSE','BSE','NSE_BSE') NOT NULL DEFAULT 'NSE',
  `isin` varchar(15) DEFAULT NULL,
  `sector` varchar(100) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `market_cap_category` enum('large','mid','small') DEFAULT NULL,
  `current_price` decimal(12,4) DEFAULT NULL,
  `price_updated_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_holdings
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `avg_buy_price` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `invested_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(16,2) DEFAULT 0.00,
  `gain_loss` decimal(16,2) DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sh_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_sh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sh_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_transactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `txn_date` date NOT NULL,
  `txn_type` enum('buy','sell','bonus','split','rights') NOT NULL,
  `quantity` decimal(14,4) NOT NULL,
  `price` decimal(12,4) NOT NULL,
  `amount` decimal(16,2) NOT NULL,
  `brokerage` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stxn_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_dividends
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_dividends` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `record_date` date NOT NULL,
  `dividend_per_share` decimal(10,4) NOT NULL,
  `tds_deducted` decimal(10,2) DEFAULT 0.00,
  `net_amount` decimal(14,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sdiv_stock` (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_corporate_actions
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_corporate_actions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `action_type` enum('split','bonus','rights','merger') NOT NULL,
  `action_date` date NOT NULL,
  `ratio_from` decimal(8,4) DEFAULT NULL,
  `ratio_to` decimal(8,4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sca_stock` (`stock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_price_alerts (t344/t345)
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_price_alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `symbol` varchar(30) NOT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `alert_type` enum('above','below','pct_up','pct_down') NOT NULL,
  `target_price` decimal(12,2) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `triggered_at` datetime DEFAULT NULL,
  `triggered_price` decimal(12,2) DEFAULT NULL,
  `notify_browser` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_spa_user` (`user_id`, `is_active`),
  KEY `idx_spa_stock` (`stock_id`),
  KEY `idx_spa_symbol` (`symbol`),
  CONSTRAINT `fk_spa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spa_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stock price alerts — NSE/BSE target price notifications';

-- ============================================================
-- TABLE: fd_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `fd_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(150) NOT NULL,
  `account_number` varchar(30) DEFAULT NULL,
  `principal` decimal(16,2) NOT NULL,
  `interest_rate` decimal(6,3) NOT NULL COMMENT 'Annual rate %',
  `tenure_months` int NOT NULL,
  `start_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `compounding` enum('monthly','quarterly','half_yearly','annual','simple') NOT NULL DEFAULT 'quarterly',
  `maturity_amount` decimal(16,2) DEFAULT NULL,
  `is_senior_citizen` tinyint(1) NOT NULL DEFAULT 0,
  `is_tax_saver` tinyint(1) NOT NULL DEFAULT 0 COMMENT '5-yr tax saver FD',
  `auto_renewal` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','matured','broken','renewed') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fda_user` (`user_id`),
  CONSTRAINT `fk_fda_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fd_interest_accruals
-- ============================================================
CREATE TABLE IF NOT EXISTS `fd_interest_accruals` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fd_id` int(10) UNSIGNED NOT NULL,
  `accrual_date` date NOT NULL,
  `interest_earned` decimal(12,2) NOT NULL,
  `tds_deducted` decimal(10,2) DEFAULT 0.00,
  `cumulative_interest` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fdia_fd` (`fd_id`),
  CONSTRAINT `fk_fdia_fd` FOREIGN KEY (`fd_id`) REFERENCES `fd_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fd_interest_log (t80)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fd_interest_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fd_id` int(10) UNSIGNED NOT NULL,
  `credit_date` date NOT NULL,
  `interest_earned` decimal(12,2) NOT NULL,
  `tds_deducted` decimal(10,2) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`credit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fd_maturity_alerts (t80)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fd_maturity_alerts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fd_id` int(10) UNSIGNED NOT NULL,
  `alert_days` tinyint UNSIGNED NOT NULL,
  `is_sent` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fd_days` (`fd_id`, `alert_days`),
  KEY `idx_user` (`user_id`),
  KEY `idx_sent` (`is_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fd_market_rates (t421)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fd_market_rates` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(80) NOT NULL,
  `tenure_label` varchar(30) NOT NULL,
  `rate_general` decimal(5,2) NOT NULL,
  `rate_senior` decimal(5,2) NOT NULL,
  `effective_date` date DEFAULT (CURDATE()),
  `source_url` varchar(300) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: savings_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `savings_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(150) NOT NULL,
  `account_number` varchar(30) DEFAULT NULL,
  `account_type` enum('savings','current','salary') NOT NULL DEFAULT 'savings',
  `balance` decimal(16,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(5,3) DEFAULT NULL COMMENT 'Annual interest rate %',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sa_user` (`user_id`),
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: savings_interest / savings_interest_log
-- ============================================================
CREATE TABLE IF NOT EXISTS `savings_interest` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(10) UNSIGNED NOT NULL,
  `credit_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_si_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `savings_interest_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(10) UNSIGNED NOT NULL,
  `log_date` date NOT NULL,
  `interest_earned` decimal(12,2) NOT NULL,
  `balance_used` decimal(16,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sil_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: bank_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(150) NOT NULL,
  `account_number` varchar(30) DEFAULT NULL,
  `ifsc` varchar(15) DEFAULT NULL,
  `account_type` enum('savings','current','salary') NOT NULL DEFAULT 'savings',
  `balance` decimal(16,2) NOT NULL DEFAULT 0.00,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ba_user` (`user_id`),
  CONSTRAINT `fk_ba_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: bank_balance_history
-- ============================================================
CREATE TABLE IF NOT EXISTS `bank_balance_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(10) UNSIGNED NOT NULL,
  `balance` decimal(16,2) NOT NULL,
  `recorded_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bbh_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: epf_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `epf_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `uan` varchar(20) DEFAULT NULL,
  `employer_name` varchar(200) DEFAULT NULL,
  `employee_share` decimal(16,2) NOT NULL DEFAULT 0.00,
  `employer_share` decimal(16,2) NOT NULL DEFAULT 0.00,
  `pension_share` decimal(16,2) NOT NULL DEFAULT 0.00,
  `total_balance` decimal(16,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(5,3) DEFAULT 8.100,
  `last_updated` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_epf_user` (`user_id`),
  CONSTRAINT `fk_epf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: po_schemes (Post Office Schemes)
-- ============================================================
CREATE TABLE IF NOT EXISTS `po_schemes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `scheme_type` varchar(60) NOT NULL COMMENT 'PPF/NSC/SCSS/MIS/RD/SSY/KVP',
  `account_number` varchar(30) DEFAULT NULL,
  `invested_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(16,2) DEFAULT 0.00,
  `interest_rate` decimal(6,3) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `maturity_date` date DEFAULT NULL,
  `status` enum('active','matured','closed') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_po_user` (`user_id`),
  CONSTRAINT `fk_po_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: import_logs (MERGED — migration 015/017)
-- ============================================================
CREATE TABLE IF NOT EXISTS `import_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `filename` varchar(200) NOT NULL,
  `format` varchar(30) NOT NULL COMMENT 'cas_pdf|groww_csv|zerodha_csv|kuvera_json|...',
  `imported_count` int UNSIGNED DEFAULT 0,
  `skipped_count` int UNSIGNED DEFAULT 0,
  `failed_count` int UNSIGNED DEFAULT 0,
  `error_json` text DEFAULT NULL,
  `status` enum('success','partial','failed') NOT NULL DEFAULT 'success',
  `imported_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: notifications (MERGED — migration 010/041)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(60) NOT NULL,
  `title` varchar(300) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `triggered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_unread` (`user_id`, `is_read`, `triggered_at`),
  KEY `idx_notif_type` (`type`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: notification_prefs
-- ============================================================
CREATE TABLE IF NOT EXISTS `notification_prefs` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `nav_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `fd_maturity` tinyint(1) NOT NULL DEFAULT 1,
  `sip_reminder` tinyint(1) NOT NULL DEFAULT 1,
  `drawdown_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `nfo_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `goal_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: price_alerts (MF NAV alerts — migration 011)
-- ============================================================
CREATE TABLE IF NOT EXISTS `price_alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `type` enum('above','below') NOT NULL,
  `target_nav` decimal(12,4) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `triggered_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_user` (`user_id`, `is_active`),
  KEY `idx_pa_fund` (`fund_id`),
  CONSTRAINT `fk_pa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nfo_tracker (migration 008)
-- ============================================================
CREATE TABLE IF NOT EXISTS `nfo_tracker` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_name` varchar(300) NOT NULL,
  `amc` varchar(200) NOT NULL,
  `scheme_code` varchar(50) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `nfo_price` decimal(10,4) DEFAULT 10.0000,
  `min_investment` decimal(12,2) DEFAULT 5000.00,
  `open_date` date NOT NULL,
  `close_date` date NOT NULL,
  `allotment_date` date DEFAULT NULL,
  `status` enum('upcoming','open','closing_soon','closed','allotted') NOT NULL DEFAULT 'upcoming',
  `fund_type` enum('open_ended','close_ended','interval') DEFAULT 'open_ended',
  `tax_saving` tinyint(1) NOT NULL DEFAULT 0,
  `benchmark` varchar(200) DEFAULT NULL,
  `fund_manager` varchar(200) DEFAULT NULL,
  `amc_website` varchar(500) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nfo_name_open` (`fund_name`(150), `open_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_watchlist / mf_watchlist (MERGED — 009/010)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_watchlist` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `alert_nav` decimal(10,4) DEFAULT NULL,
  `alert_type` enum('above','below') DEFAULT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_fund` (`user_id`, `fund_id`),
  KEY `idx_wl_user` (`user_id`),
  KEY `idx_wl_fund` (`fund_id`),
  CONSTRAINT `fk_wl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wl_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_watchlist_alerts (migration 010)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_watchlist_alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `alert_type` enum('nav_above','nav_below','return_1y_above','return_3y_above',
                     'sharpe_above','aum_above','expense_below','multi_condition') NOT NULL,
  `target_value` decimal(12,4) DEFAULT NULL,
  `conditions_json` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_triggered` datetime DEFAULT NULL,
  `snooze_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wla_user` (`user_id`),
  KEY `idx_wla_fund` (`fund_id`),
  KEY `idx_wla_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_alert_history (migration 010)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_alert_history` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `trigger_val` decimal(12,4) DEFAULT NULL,
  `triggered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ah_alert` (`alert_id`),
  KEY `idx_ah_user` (`user_id`),
  KEY `idx_ah_date` (`triggered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_dividends (migration 014/027)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_dividends` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(50) DEFAULT NULL,
  `dividend_date` date NOT NULL,
  `nav_before` decimal(12,4) DEFAULT NULL,
  `nav_after` decimal(12,4) DEFAULT NULL,
  `rate_per_unit` decimal(10,4) NOT NULL,
  `units_held` decimal(14,4) DEFAULT NULL,
  `amount_received` decimal(14,2) DEFAULT NULL,
  `tds_deducted` decimal(10,2) DEFAULT 0.00,
  `net_received` decimal(14,2) DEFAULT NULL,
  `is_reinvested` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dividend` (`portfolio_id`, `fund_id`, `folio_number`(30), `dividend_date`),
  KEY `idx_div_portfolio` (`portfolio_id`),
  KEY `idx_div_fund` (`fund_id`),
  KEY `idx_div_date` (`dividend_date`),
  CONSTRAINT `fk_div_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_div_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_holdings_notes (migration 015/030)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_holdings_notes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `holding_id` int(10) UNSIGNED NOT NULL,
  `note_text` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_holding` (`user_id`, `holding_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_saved_screens (migration 015)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_saved_screens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `filters_json` text NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `share_token` varchar(32) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_public` (`is_public`),
  KEY `idx_token` (`share_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_ratings (MERGED — migration 015/023)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fund_ratings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `stars` tinyint UNSIGNED NOT NULL COMMENT '1-5',
  `rating_stars` tinyint(1) DEFAULT NULL,
  `score_total` decimal(6,4) DEFAULT NULL,
  `score_breakdown` json DEFAULT NULL COMMENT '{returns, expense, drawdown, direct}',
  `return_score` decimal(5,2) DEFAULT NULL,
  `consistency_score` decimal(5,2) DEFAULT NULL,
  `risk_score` decimal(5,2) DEFAULT NULL,
  `cost_score` decimal(5,2) DEFAULT NULL,
  `manager_score` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(5,2) DEFAULT NULL,
  `calc_date` date NOT NULL DEFAULT (CURDATE()),
  `rated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fr_fund` (`fund_id`),
  KEY `idx_fr_stars` (`stars`),
  KEY `idx_fr_rated` (`rated_at`),
  CONSTRAINT `fk_fr_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_rolling_returns (migration 013)
-- ============================================================
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
  KEY `idx_fund` (`fund_id`),
  KEY `idx_period` (`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_benchmark_map (migration 013)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fund_benchmark_map` (
  `fund_id` int(10) UNSIGNED NOT NULL,
  `benchmark_code` varchar(30) NOT NULL,
  `benchmark_name` varchar(80) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`fund_id`),
  KEY `idx_benchmark` (`benchmark_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: benchmark_nav (migration 013)
-- ============================================================
CREATE TABLE IF NOT EXISTS `benchmark_nav` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `nav_date` date NOT NULL,
  `nav_value` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code_date` (`code`, `nav_date`),
  KEY `idx_code` (`code`),
  KEY `idx_date` (`nav_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_managers (MERGED — migration 013/016)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fund_managers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `manager_name` varchar(200) NOT NULL,
  `from_date` date NOT NULL,
  `to_date` date DEFAULT NULL COMMENT 'NULL = currently managing',
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fm_fund` (`fund_id`),
  KEY `idx_fm_manager` (`manager_name`(100)),
  KEY `idx_fm_current` (`is_current`),
  CONSTRAINT `fk_fm_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: expense_ratio_history / fund_ter_history (MERGED — migration 017/015)
-- ============================================================
CREATE TABLE IF NOT EXISTS `expense_ratio_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `expense_ratio` decimal(6,4) NOT NULL COMMENT 'TER in %',
  `plan_type` enum('direct','regular') NOT NULL DEFAULT 'direct',
  `recorded_date` date NOT NULL COMMENT 'First day of the month',
  `source` varchar(100) DEFAULT 'amfi_website',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exp_fund_month` (`fund_id`, `plan_type`, `recorded_date`),
  KEY `idx_erh_fund` (`fund_id`),
  KEY `idx_erh_date` (`recorded_date`),
  CONSTRAINT `fk_erh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fund_ter_history alias (same data, kept for backward PHP compat)
CREATE TABLE IF NOT EXISTS `fund_ter_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `ter_pct` decimal(5,3) NOT NULL,
  `effective_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_date` (`fund_id`, `effective_date`),
  KEY `idx_fund` (`fund_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_dividends (migration 015/027)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fund_dividends` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `record_date` date NOT NULL,
  `ex_date` date DEFAULT NULL,
  `dividend_per_unit` decimal(10,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_date` (`fund_id`, `record_date`),
  KEY `idx_fund` (`fund_id`),
  KEY `idx_date` (`record_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_stock_holdings / fund_portfolio_holdings (MERGED — 015/028)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fund_stock_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `stock_name` varchar(300) NOT NULL,
  `isin` varchar(15) DEFAULT NULL,
  `sector` varchar(100) DEFAULT NULL,
  `weight_pct` decimal(6,3) NOT NULL,
  `market_cap` enum('large','mid','small','other') DEFAULT NULL,
  `month_year` date NOT NULL COMMENT 'First day of disclosure month',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_stock_month` (`fund_id`, `isin`(15), `month_year`),
  KEY `idx_fsh_fund` (`fund_id`),
  KEY `idx_fsh_isin` (`isin`),
  KEY `idx_fsh_sector` (`sector`(50)),
  KEY `idx_fsh_month` (`month_year`),
  CONSTRAINT `fk_fsh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: rebalancing_targets (migration 018)
-- ============================================================
CREATE TABLE IF NOT EXISTS `rebalancing_targets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `asset_class` enum('equity','debt','gold','cash','international','other') NOT NULL,
  `target_pct` decimal(5,2) NOT NULL,
  `drift_threshold` decimal(5,2) NOT NULL DEFAULT 5.00,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rebal_portfolio_asset` (`portfolio_id`, `asset_class`),
  CONSTRAINT `fk_rebal_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nav_proxy_cache (migration 019)
-- ============================================================
CREATE TABLE IF NOT EXISTS `nav_proxy_cache` (
  `fund_id` int(10) UNSIGNED NOT NULL,
  `period` enum('1M','3M','6M','1Y','3Y','5Y','ALL') NOT NULL,
  `data_json` longtext NOT NULL,
  `data_points` int NOT NULL DEFAULT 0,
  `cached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `source` enum('nav_history','mfapi') NOT NULL DEFAULT 'nav_history',
  PRIMARY KEY (`fund_id`, `period`),
  KEY `idx_npc_cached_at` (`cached_at`),
  CONSTRAINT `fk_npc_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: net_worth_snapshots (migration 020 — MERGED with 041)
-- ============================================================
CREATE TABLE IF NOT EXISTS `net_worth_snapshots` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `snapshot_date` date NOT NULL,
  `total_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `mf_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `stock_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fd_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `savings_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `nps_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `po_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `gold_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `crypto_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `other_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nws` (`user_id`, `snapshot_date`),
  KEY `idx_nws_user` (`user_id`),
  CONSTRAINT `fk_nws_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_peak_progress (migration 021 — FINAL version)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_peak_progress` (
  `scheme_code` varchar(20) NOT NULL,
  `last_processed_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','error') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`scheme_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nav_download_queue
-- ============================================================
CREATE TABLE IF NOT EXISTS `nav_download_queue` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `scheme_code` varchar(20) NOT NULL,
  `status` enum('pending','running','done','error') NOT NULL DEFAULT 'pending',
  `retry_count` tinyint NOT NULL DEFAULT 0,
  `nav_records_added` int NOT NULL DEFAULT 0,
  `error_msg` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ndq_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nav_download_progress (migration 042)
-- ============================================================
CREATE TABLE IF NOT EXISTS `nav_download_progress` (
  `id` int(10) AUTO_INCREMENT PRIMARY KEY,
  `scheme_code` varchar(20) NOT NULL,
  `fund_id` int(11) DEFAULT NULL,
  `status` enum('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  `from_date` date DEFAULT NULL,
  `last_downloaded_date` date DEFAULT NULL,
  `records_saved` int NOT NULL DEFAULT 0,
  `error_msg` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_scheme` (`scheme_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sector_rotation_cache (migration 025)
-- ============================================================
CREATE TABLE IF NOT EXISTS `sector_rotation_cache` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sector_name` varchar(100) NOT NULL,
  `sector_slug` varchar(60) NOT NULL,
  `ret_1m` decimal(8,4) DEFAULT NULL,
  `ret_3m` decimal(8,4) DEFAULT NULL,
  `ret_6m` decimal(8,4) DEFAULT NULL,
  `ret_1y` decimal(8,4) DEFAULT NULL,
  `fund_count` smallint UNSIGNED DEFAULT 0,
  `top_fund_id` int UNSIGNED DEFAULT NULL,
  `data_source` enum('category_proxy','amfi_holdings') NOT NULL DEFAULT 'category_proxy',
  `computed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sr_sector` (`sector_slug`),
  KEY `idx_sr_ret1y` (`ret_1y`),
  CONSTRAINT `fk_sr_top_fund` FOREIGN KEY (`top_fund_id`) REFERENCES `funds`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: investment_calendar_events (migration 026)
-- ============================================================
CREATE TABLE IF NOT EXISTS `investment_calendar_events` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = global event visible to all',
  `event_date` date NOT NULL,
  `event_type` enum('tax_deadline','sebi_compliance','rbi_policy','budget','sgb_window',
                     'nfo_open','nfo_close','advance_tax','custom') NOT NULL DEFAULT 'custom',
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(10) DEFAULT '📅',
  `color` varchar(20) DEFAULT '#3b82f6',
  `is_important` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ice_date` (`event_date`),
  KEY `idx_ice_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sgb_holdings (migration 017_sgb)
-- ============================================================
CREATE TABLE IF NOT EXISTS `sgb_holdings` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `series_name` varchar(100) NOT NULL,
  `tranche` varchar(50) DEFAULT NULL,
  `units` decimal(10,4) NOT NULL DEFAULT 0,
  `issue_price` decimal(12,4) NOT NULL,
  `issue_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `current_gold_price` decimal(12,4) DEFAULT NULL,
  `face_value` decimal(12,4) NOT NULL DEFAULT 0,
  `current_value` decimal(14,4) DEFAULT NULL,
  `interest_rate` decimal(5,4) NOT NULL DEFAULT 0.025,
  `next_interest_date` date DEFAULT NULL,
  `isin` varchar(20) DEFAULT NULL,
  `tax_exemption` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sgb_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_sgb_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sgb_interest_log
-- ============================================================
CREATE TABLE IF NOT EXISTS `sgb_interest_log` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sgb_id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `period` varchar(20) NOT NULL,
  `amount` decimal(12,4) NOT NULL,
  `paid_on` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_sgb_interest_sgb` (`sgb_id`),
  CONSTRAINT `fk_sgb_interest_sgb` FOREIGN KEY (`sgb_id`) REFERENCES `sgb_holdings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: esop_grants (migration 019_esop)
-- ============================================================
CREATE TABLE IF NOT EXISTS `esop_grants` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `grant_type` enum('ESOP','RSU','SAR') NOT NULL DEFAULT 'ESOP',
  `grant_date` date NOT NULL,
  `total_options` int UNSIGNED NOT NULL,
  `exercise_price` decimal(12,4) NOT NULL DEFAULT 0,
  `vesting_start` date NOT NULL,
  `vesting_cliff_months` smallint UNSIGNED DEFAULT 12,
  `vesting_period_months` smallint UNSIGNED DEFAULT 48,
  `vesting_schedule` varchar(50) DEFAULT '1/4 per year',
  `current_fmv` decimal(12,4) DEFAULT NULL,
  `exercise_price_total` decimal(16,4) AS (total_options * exercise_price) STORED,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `status` enum('active','fully_vested','lapsed','exercised_partial','exercised_full') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_esop_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_esop_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: esop_vesting_events
-- ============================================================
CREATE TABLE IF NOT EXISTS `esop_vesting_events` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grant_id` int(10) UNSIGNED NOT NULL,
  `vest_date` date NOT NULL,
  `units_vested` int UNSIGNED NOT NULL,
  `fmv_on_vest` decimal(12,4) DEFAULT NULL,
  `perquisite_tax` decimal(14,4) DEFAULT NULL,
  `tax_slab_pct` decimal(5,2) DEFAULT NULL,
  `is_exercised` tinyint(1) NOT NULL DEFAULT 0,
  `exercise_date` date DEFAULT NULL,
  `exercise_price` decimal(12,4) DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `sale_price` decimal(12,4) DEFAULT NULL,
  `capital_gain` decimal(14,4) DEFAULT NULL,
  `gain_type` enum('STCG','LTCG') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_esop_vest_grant` (`grant_id`),
  CONSTRAINT `fk_esop_vest_grant` FOREIGN KEY (`grant_id`) REFERENCES `esop_grants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: goals (MERGED — migration 025/041)
-- ============================================================
CREATE TABLE IF NOT EXISTS `goals` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` int(10) UNSIGNED NOT NULL,
  `goal_name` varchar(120) NOT NULL,
  `goal_type` enum('retirement','education','home','vehicle','emergency','vacation',
                   'wedding','business','other') DEFAULT 'other',
  `target_amount` decimal(16,2) NOT NULL,
  `target_date` date NOT NULL,
  `current_amount` decimal(16,2) NOT NULL DEFAULT 0,
  `monthly_sip` decimal(12,2) DEFAULT NULL,
  `expected_return` decimal(5,2) DEFAULT 12.00,
  `inflation_rate` decimal(5,2) DEFAULT 6.00,
  `priority` tinyint UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=High 2=Medium 3=Low',
  `color` varchar(20) DEFAULT '#3b82f6',
  `emoji` varchar(10) DEFAULT '🎯',
  `status` enum('active','achieved','paused','cancelled') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_goals_user` (`user_id`),
  CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: goal_mf_links / goal_stock_links / goal_holdings (MERGED)
-- ============================================================
CREATE TABLE IF NOT EXISTS `goal_mf_links` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `goal_id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `allocation_pct` decimal(5,2) DEFAULT 100.00,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_goal_mf` (`goal_id`, `fund_id`, `portfolio_id`),
  CONSTRAINT `fk_goal_mf_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goal_stock_links` (
  `id` int(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `goal_id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `allocation_pct` decimal(5,2) DEFAULT 100.00,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_goal_stock` (`goal_id`, `stock_id`, `portfolio_id`),
  CONSTRAINT `fk_goal_stock_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- goal_holdings — generic asset→goal linker (migration 041)
CREATE TABLE IF NOT EXISTS `goal_holdings` (
  `id` bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `goal_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `asset_type` enum('mf','fd','nps','stock','crypto','other') NOT NULL,
  `asset_id` int(10) UNSIGNED NOT NULL,
  `allocated_pct` decimal(5,2) DEFAULT 100.00,
  `notes` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_goal_asset` (`goal_id`, `asset_type`, `asset_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: crypto_holdings (MERGED — migration 038/041, portfolio_id schema)
-- ============================================================
CREATE TABLE IF NOT EXISTS `crypto_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `coin_id` varchar(60) NOT NULL,
  `coin_symbol` varchar(20) NOT NULL,
  `coin_name` varchar(100) NOT NULL,
  `quantity` decimal(24,8) NOT NULL DEFAULT 0,
  `avg_buy_price` decimal(20,4) NOT NULL DEFAULT 0,
  `total_invested` decimal(20,2) NOT NULL DEFAULT 0,
  `current_price` decimal(20,4) DEFAULT 0,
  `current_value_inr` decimal(15,2) DEFAULT 0,
  `exchange` varchar(60) DEFAULT NULL,
  `wallet_address` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `price_updated_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_portfolio` (`portfolio_id`),
  KEY `idx_coin` (`coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: crypto_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `crypto_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `coin_id` varchar(60) NOT NULL,
  `coin_symbol` varchar(20) NOT NULL,
  `txn_type` enum('buy','sell','transfer_in','transfer_out','reward') NOT NULL,
  `quantity` decimal(24,8) NOT NULL,
  `price_inr` decimal(20,4) NOT NULL,
  `fee_inr` decimal(10,2) DEFAULT 0,
  `total_inr` decimal(15,2) NOT NULL,
  `tds_deducted` decimal(10,2) DEFAULT 0,
  `exchange` varchar(60) DEFAULT NULL,
  `txn_date` date NOT NULL,
  `notes` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_portfolio` (`portfolio_id`),
  KEY `idx_coin` (`coin_id`),
  KEY `idx_date` (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: crypto_price_cache (migration 043)
-- ============================================================
CREATE TABLE IF NOT EXISTS `crypto_price_cache` (
  `id` int(10) AUTO_INCREMENT PRIMARY KEY,
  `coin_id` varchar(100) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price_inr` decimal(20,4) DEFAULT 0,
  `price_usd` decimal(20,8) DEFAULT 0,
  `change_24h` decimal(10,4) DEFAULT 0,
  `market_cap` bigint DEFAULT 0,
  `fetched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_coin` (`coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nudge_dismissals (t499)
-- ============================================================
CREATE TABLE IF NOT EXISTS `nudge_dismissals` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `nudge_id` varchar(60) NOT NULL,
  `dismissed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_nudge` (`user_id`, `nudge_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: spending_discipline (tj003)
-- ============================================================
CREATE TABLE IF NOT EXISTS `spending_discipline` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `monthly_income` decimal(15,2) NOT NULL DEFAULT 0,
  `target_pct` decimal(5,2) NOT NULL DEFAULT 20.00,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: investment_journal (t408)
-- ============================================================
CREATE TABLE IF NOT EXISTS `investment_journal` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `entry_date` date NOT NULL,
  `category` varchar(60) DEFAULT NULL COMMENT 'mf/stock/fd/general/review',
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `mood` enum('bullish','bearish','neutral','anxious','confident') DEFAULT 'neutral',
  `linked_asset_type` varchar(20) DEFAULT NULL,
  `linked_asset_id` int UNSIGNED DEFAULT NULL,
  `tags` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_date` (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: credit_cards (t44 sprint)
-- ============================================================
CREATE TABLE IF NOT EXISTS `credit_cards` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `card_name` varchar(100) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `last4` char(4) DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT NULL,
  `outstanding` decimal(12,2) DEFAULT 0.00,
  `due_date` date DEFAULT NULL,
  `min_due` decimal(10,2) DEFAULT 0.00,
  `interest_rate` decimal(5,2) DEFAULT NULL COMMENT 'Annual interest rate %',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: ai_chat_history (t381)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_chat_history` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `role` enum('user','assistant') NOT NULL,
  `message` text NOT NULL,
  `context_id` varchar(36) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_ctx` (`user_id`, `context_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: ai_portfolio_reviews (t380/t333)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_portfolio_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `review_month` varchar(7) NOT NULL,
  `review_type` varchar(30) NOT NULL DEFAULT 'monthly',
  `grade` char(2) DEFAULT NULL,
  `score` tinyint UNSIGNED DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `weaknesses` text DEFAULT NULL,
  `actions` text DEFAULT NULL,
  `raw_response` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_month` (`user_id`, `review_month`, `review_type`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: ai_anomalies (t384)
-- ============================================================
CREATE TABLE IF NOT EXISTS `ai_anomalies` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `anomaly_type` varchar(60) NOT NULL,
  `severity` enum('info','warning','critical') DEFAULT 'warning',
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `data_json` text DEFAULT NULL,
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `detected_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dismissed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_dismissed` (`is_dismissed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: insurance_policies (t122)
-- ============================================================
CREATE TABLE IF NOT EXISTS `insurance_policies` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `policy_type` enum('term','health','ulip','endowment','vehicle','other') NOT NULL,
  `insurer` varchar(150) NOT NULL,
  `policy_number` varchar(60) DEFAULT NULL,
  `sum_assured` decimal(16,2) DEFAULT NULL,
  `premium_amount` decimal(12,2) DEFAULT NULL,
  `premium_frequency` enum('annual','semi_annual','quarterly','monthly') DEFAULT 'annual',
  `start_date` date DEFAULT NULL,
  `maturity_date` date DEFAULT NULL,
  `next_premium_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ins_user` (`user_id`),
  CONSTRAINT `fk_ins_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: loan_accounts (t123)
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `loan_type` enum('home','car','personal','education','gold','other') NOT NULL,
  `lender` varchar(150) NOT NULL,
  `account_number` varchar(40) DEFAULT NULL,
  `principal` decimal(16,2) NOT NULL,
  `outstanding` decimal(16,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(6,3) NOT NULL,
  `emi_amount` decimal(12,2) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','closed','overdue') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_loan_user` (`user_id`),
  CONSTRAINT `fk_loan_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
