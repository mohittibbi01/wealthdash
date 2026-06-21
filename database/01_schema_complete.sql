-- ============================================================
-- WealthDash — Complete Database Schema
-- Version: 2.0 (March 2026)
-- ============================================================
-- INSTALL KAISE KARO:
--   1. phpMyAdmin mein naya database banao: wealthdash
--   2. Ye file import karo (schema_complete.sql)
--   3. Phir seed.sql import karo
-- ============================================================
-- Ye file include karti hai:
--   • Saare 43 tables (schema + migrations 001-014 + peak_nav)
--   • Saare indexes aur constraints
--   • Koi user data nahi (seed.sql mein hai)
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
  UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: audit_log
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `theme` varchar(20) NOT NULL DEFAULT 'light',
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `avatar_url` varchar(500) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `last_activity` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sess_user` (`user_id`),
  KEY `idx_sess_activity` (`last_activity`),
  CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: login_attempts
-- ============================================================
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `success` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_la_email` (`email`),
  KEY `idx_la_ip` (`ip_address`),
  KEY `idx_la_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: otp_tokens
-- ============================================================
CREATE TABLE IF NOT EXISTS `otp_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(10) NOT NULL,
  `purpose` varchar(50) NOT NULL DEFAULT 'login',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otp_user` (`user_id`),
  KEY `idx_otp_token` (`token`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: password_resets
-- ============================================================
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_token` (`token`),
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_google_id` (`google_id`),
  KEY `idx_gauth_user` (`user_id`),
  CONSTRAINT `fk_gauth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: portfolios
-- ============================================================
CREATE TABLE IF NOT EXISTS `portfolios` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT 'My Portfolio',
  `description` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_portfolio_user` (`user_id`),
  CONSTRAINT `fk_portfolio_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: import_logs
-- ============================================================
CREATE TABLE IF NOT EXISTS `import_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED DEFAULT NULL,
  `import_type` varchar(50) NOT NULL COMMENT 'csv, cas_pdf, etc',
  `filename` varchar(255) DEFAULT NULL,
  `rows_total` int(11) NOT NULL DEFAULT 0,
  `rows_success` int(11) NOT NULL DEFAULT 0,
  `rows_failed` int(11) NOT NULL DEFAULT 0,
  `status` enum('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `error_log` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_il_user` (`user_id`),
  KEY `idx_il_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_il_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fund_houses (AMCs)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fund_houses` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `short_name` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fh_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: funds (Mutual Fund schemes)
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
  `nav` decimal(14,4) DEFAULT NULL,
  `nav_date` date DEFAULT NULL,
  `return_1y` decimal(8,4) DEFAULT NULL,
  `return_3y` decimal(8,4) DEFAULT NULL,
  `return_5y` decimal(8,4) DEFAULT NULL,
  `return_since` decimal(8,4) DEFAULT NULL,
  `aum_cr` decimal(14,2) DEFAULT NULL,
  `expense_ratio` decimal(5,3) DEFAULT NULL,
  `exit_load_text` text DEFAULT NULL,
  `exit_load_pct` decimal(5,3) DEFAULT NULL,
  `exit_load_days` int(11) DEFAULT NULL,
  `risk_level` varchar(30) DEFAULT NULL,
  `benchmark` varchar(150) DEFAULT NULL,
  `fund_manager` varchar(150) DEFAULT NULL,
  `min_sip_amount` decimal(10,2) DEFAULT NULL,
  `min_lumpsum` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  KEY `idx_fund_house` (`fund_house_id`),
  KEY `idx_fund_isin` (`isin`),
  KEY `idx_fund_category` (`scheme_category`),
  KEY `idx_fund_name` (`scheme_name`(100)),
  CONSTRAINT `fk_fund_house` FOREIGN KEY (`fund_house_id`) REFERENCES `fund_houses` (`id`)
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
  UNIQUE KEY `uq_nav` (`fund_id`, `nav_date`),
  KEY `idx_nav_date` (`nav_date`),
  CONSTRAINT `fk_navh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE CASCADE
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
  `last_transaction_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfh` (`portfolio_id`, `fund_id`, `folio_number`),
  KEY `idx_mfh_fund` (`fund_id`),
  CONSTRAINT `fk_mfh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`),
  CONSTRAINT `fk_mfh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_transactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(50) DEFAULT NULL,
  `txn_type` enum('purchase','redemption','switch_in','switch_out','dividend_reinvest','sip','swp') NOT NULL,
  `txn_date` date NOT NULL,
  `units` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `nav` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `platform` varchar(50) DEFAULT NULL,
  `investment_fy` varchar(10) DEFAULT NULL COMMENT 'e.g. 2024-25',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mft_portfolio` (`portfolio_id`),
  KEY `idx_mft_fund` (`fund_id`),
  KEY `idx_mft_date` (`txn_date`),
  KEY `idx_mft_type` (`txn_type`),
  CONSTRAINT `fk_mft_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`),
  CONSTRAINT `fk_mft_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_dividends
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_dividends` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(50) DEFAULT NULL,
  `dividend_date` date NOT NULL,
  `units_held` decimal(18,4) DEFAULT NULL,
  `dividend_per_unit` decimal(10,4) DEFAULT NULL,
  `total_dividend` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tds_deducted` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_dividend` decimal(14,2) NOT NULL DEFAULT 0.00,
  `dividend_type` enum('cash','reinvest') NOT NULL DEFAULT 'cash',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mfd_portfolio` (`portfolio_id`),
  KEY `idx_mfd_fund` (`fund_id`),
  CONSTRAINT `fk_mfd_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`),
  CONSTRAINT `fk_mfd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: mf_peak_progress  (Peak NAV tracker — run peak_nav/setup.sql to seed)
-- ============================================================
CREATE TABLE IF NOT EXISTS `mf_peak_progress` (
  `scheme_code` varchar(20) NOT NULL,
  `last_processed_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','needs_update','error') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scheme_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sip_schedules
-- ============================================================
CREATE TABLE IF NOT EXISTS `sip_schedules` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `asset_type` enum('mf','stock','nps') NOT NULL DEFAULT 'mf',
  `fund_id` int(10) UNSIGNED DEFAULT NULL,
  `stock_id` int(10) UNSIGNED DEFAULT NULL,
  `nps_scheme_id` int(10) UNSIGNED DEFAULT NULL,
  `folio_number` varchar(50) DEFAULT NULL,
  `sip_amount` decimal(14,2) NOT NULL,
  `swp_amount` decimal(14,2) DEFAULT NULL,
  `sip_type` enum('sip','swp') NOT NULL DEFAULT 'sip',
  `frequency` enum('monthly','quarterly','weekly','yearly') NOT NULL DEFAULT 'monthly',
  `sip_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Day of month 1-28',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'NULL = ongoing',
  `platform` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sip_portfolio` (`portfolio_id`),
  KEY `idx_sip_fund` (`fund_id`),
  CONSTRAINT `fk_sip_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sip_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE SET NULL
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
  `asset_class` enum('E','C','G','A') NOT NULL DEFAULT 'E',
  `nav` decimal(12,4) DEFAULT NULL,
  `nav_date` date DEFAULT NULL,
  `return_1y` decimal(8,4) DEFAULT NULL,
  `return_3y` decimal(8,4) DEFAULT NULL,
  `return_5y` decimal(8,4) DEFAULT NULL,
  `return_since` decimal(8,4) DEFAULT NULL,
  `aum_cr` decimal(14,2) DEFAULT NULL,
  `nav_returns_updated_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nps_code` (`scheme_code`),
  KEY `idx_nps_pfm` (`pfm_name`),
  KEY `idx_nps_tier` (`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nps_holdings
-- ============================================================
CREATE TABLE IF NOT EXISTS `nps_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `scheme_id` int(10) UNSIGNED NOT NULL,
  `units` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `avg_nav` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `invested_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(16,2) NOT NULL DEFAULT 0.00,
  `gain_loss` decimal(16,2) NOT NULL DEFAULT 0.00,
  `gain_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `cagr` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `first_investment_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_npsh` (`portfolio_id`, `scheme_id`),
  KEY `idx_npsh_scheme` (`scheme_id`),
  CONSTRAINT `fk_npsh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_npsh_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nps_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `nps_transactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `scheme_id` int(10) UNSIGNED NOT NULL,
  `txn_type` enum('purchase','redemption','switch_in','switch_out') NOT NULL DEFAULT 'purchase',
  `txn_date` date NOT NULL,
  `units` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `nav` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_npst_portfolio` (`portfolio_id`),
  KEY `idx_npst_scheme` (`scheme_id`),
  KEY `idx_npst_date` (`txn_date`),
  CONSTRAINT `fk_npst_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_npst_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nps_nav_history  (Migration 014)
-- ============================================================
CREATE TABLE IF NOT EXISTS `nps_nav_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `scheme_id` int(10) UNSIGNED NOT NULL,
  `nav_date` date NOT NULL,
  `nav` decimal(12,4) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nps_nav` (`scheme_id`, `nav_date`),
  KEY `idx_nps_nav_date` (`nav_date`),
  KEY `idx_nps_nav_scheme` (`scheme_id`),
  CONSTRAINT `fk_nps_nav_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_master
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_master` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exchange` enum('NSE','BSE') NOT NULL DEFAULT 'NSE',
  `symbol` varchar(30) NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `isin` varchar(20) DEFAULT NULL,
  `sector` varchar(100) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `current_price` decimal(12,4) DEFAULT NULL,
  `prev_close` decimal(12,4) DEFAULT NULL,
  `day_change_pct` decimal(8,4) DEFAULT NULL,
  `market_cap` decimal(20,2) DEFAULT NULL,
  `pe_ratio` decimal(10,4) DEFAULT NULL,
  `price_updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock` (`exchange`, `symbol`),
  KEY `idx_stock_isin` (`isin`),
  KEY `idx_stock_sector` (`sector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_holdings
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_holdings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `avg_price` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `invested_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(16,2) NOT NULL DEFAULT 0.00,
  `gain_loss` decimal(16,2) NOT NULL DEFAULT 0.00,
  `gain_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `current_price` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `first_purchase_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sh` (`portfolio_id`, `stock_id`),
  KEY `idx_sh_stock` (`stock_id`),
  CONSTRAINT `fk_sh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sh_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_transactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `txn_type` enum('buy','sell','bonus','split','rights') NOT NULL DEFAULT 'buy',
  `txn_date` date NOT NULL,
  `quantity` decimal(14,4) NOT NULL,
  `price` decimal(12,4) NOT NULL,
  `brokerage` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_st_portfolio` (`portfolio_id`),
  KEY `idx_st_stock` (`stock_id`),
  KEY `idx_st_date` (`txn_date`),
  CONSTRAINT `fk_st_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_dividends
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_dividends` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `ex_date` date NOT NULL,
  `dividend_per_share` decimal(10,4) NOT NULL,
  `shares_held` decimal(14,4) DEFAULT NULL,
  `total_dividend` decimal(14,2) DEFAULT NULL,
  `tds_deducted` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sd_portfolio` (`portfolio_id`),
  KEY `idx_sd_stock` (`stock_id`),
  CONSTRAINT `fk_sd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sd_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: stock_corporate_actions
-- ============================================================
CREATE TABLE IF NOT EXISTS `stock_corporate_actions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `action_type` enum('split','bonus','rights','merger','demerger') NOT NULL,
  `action_date` date NOT NULL,
  `ratio_from` decimal(10,4) DEFAULT NULL,
  `ratio_to` decimal(10,4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sca_stock` (`stock_id`),
  CONSTRAINT `fk_sca_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fd_accounts (Fixed Deposits)
-- ============================================================
CREATE TABLE IF NOT EXISTS `fd_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `fd_type` enum('cumulative','non_cumulative','tax_saver','flexi','senior_citizen') NOT NULL DEFAULT 'cumulative',
  `principal_amount` decimal(14,2) NOT NULL,
  `interest_rate` decimal(6,3) NOT NULL,
  `compounding` enum('monthly','quarterly','half_yearly','yearly','at_maturity') NOT NULL DEFAULT 'quarterly',
  `start_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `maturity_amount` decimal(14,2) DEFAULT NULL,
  `interest_earned` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','matured','broken','renewed') NOT NULL DEFAULT 'active',
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `folio_number` varchar(50) DEFAULT NULL,
  `nominee` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fd_portfolio` (`portfolio_id`),
  KEY `idx_fd_maturity` (`maturity_date`),
  CONSTRAINT `fk_fd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: fd_interest_accruals
-- ============================================================
CREATE TABLE IF NOT EXISTS `fd_interest_accruals` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fd_id` int(10) UNSIGNED NOT NULL,
  `accrual_date` date NOT NULL,
  `interest_amount` decimal(14,2) NOT NULL,
  `cumulative_interest` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fdia_fd` (`fd_id`),
  CONSTRAINT `fk_fdia_fd` FOREIGN KEY (`fd_id`) REFERENCES `fd_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: savings_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `savings_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `account_number` varchar(30) DEFAULT NULL,
  `account_type` varchar(20) NOT NULL DEFAULT 'savings',
  `current_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_date` date DEFAULT NULL,
  `interest_rate` decimal(5,3) NOT NULL DEFAULT 4.000,
  `annual_interest_earned` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sa_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_sa_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: savings_interest  (Migration 002)
-- ============================================================
CREATE TABLE IF NOT EXISTS `savings_interest` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(10) UNSIGNED NOT NULL,
  `interest_date` date NOT NULL,
  `interest_amount` decimal(12,2) NOT NULL,
  `balance_after` decimal(14,2) DEFAULT NULL,
  `interest_fy` varchar(10) NOT NULL COMMENT 'e.g. 2024-25',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_si_acct_date` (`account_id`, `interest_date`),
  CONSTRAINT `fk_si_account` FOREIGN KEY (`account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: savings_interest_log  (legacy interest log, kept for compatibility)
-- ============================================================
CREATE TABLE IF NOT EXISTS `savings_interest_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(10) UNSIGNED NOT NULL,
  `log_date` date NOT NULL,
  `balance` decimal(14,2) NOT NULL,
  `interest_earned` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sil_account` (`account_id`),
  CONSTRAINT `fk_sil_sa` FOREIGN KEY (`account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: bank_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `account_type` enum('savings','current','salary','nre','nro','other') NOT NULL DEFAULT 'savings',
  `account_number` varchar(30) DEFAULT NULL,
  `ifsc_code` varchar(15) DEFAULT NULL,
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(5,3) NOT NULL DEFAULT 4.000,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ba_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_bank_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: bank_balance_history
-- ============================================================
CREATE TABLE IF NOT EXISTS `bank_balance_history` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` int(10) UNSIGNED NOT NULL,
  `balance` decimal(14,2) NOT NULL,
  `recorded_at` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bbh` (`account_id`, `recorded_at`),
  KEY `idx_bbh_date` (`recorded_at`),
  CONSTRAINT `fk_bbh_account` FOREIGN KEY (`account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: epf_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `epf_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `uan` varchar(20) DEFAULT NULL,
  `employer_name` varchar(100) NOT NULL,
  `employee_share` decimal(14,2) NOT NULL DEFAULT 0.00,
  `employer_share` decimal(14,2) NOT NULL DEFAULT 0.00,
  `interest_earned` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(5,3) NOT NULL DEFAULT 8.150 COMMENT 'Current EPF rate %',
  `monthly_contribution` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_updated` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_epf_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_epf_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: insurance_policies
-- ============================================================
CREATE TABLE IF NOT EXISTS `insurance_policies` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `policy_number` varchar(50) DEFAULT NULL,
  `insurer_name` varchar(100) NOT NULL,
  `policy_type` enum('term','health','ulip','endowment','money_back','vehicle','home','travel','other') NOT NULL DEFAULT 'term',
  `insured_name` varchar(100) DEFAULT NULL,
  `sum_assured` decimal(16,2) NOT NULL DEFAULT 0.00,
  `premium_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `premium_frequency` enum('monthly','quarterly','half_yearly','yearly','single') NOT NULL DEFAULT 'yearly',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `maturity_date` date DEFAULT NULL,
  `maturity_amount` decimal(16,2) DEFAULT NULL,
  `surrender_value` decimal(16,2) DEFAULT NULL,
  `status` enum('active','lapsed','surrendered','matured','claimed') NOT NULL DEFAULT 'active',
  `nominee` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ins_portfolio` (`portfolio_id`),
  KEY `idx_ins_type` (`policy_type`),
  CONSTRAINT `fk_ins_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: loan_accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `lender_name` varchar(100) NOT NULL,
  `loan_type` enum('home','personal','vehicle','education','gold','business','other') NOT NULL DEFAULT 'personal',
  `account_number` varchar(50) DEFAULT NULL,
  `principal_amount` decimal(16,2) NOT NULL,
  `outstanding_balance` decimal(16,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(6,3) NOT NULL,
  `rate_type` enum('fixed','floating') NOT NULL DEFAULT 'fixed',
  `emi_amount` decimal(12,2) DEFAULT NULL,
  `tenure_months` int(11) DEFAULT NULL,
  `disbursement_date` date NOT NULL,
  `first_emi_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `prepayment_penalty` decimal(5,3) DEFAULT NULL,
  `status` enum('active','closed','npa') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_loan_portfolio` (`portfolio_id`),
  KEY `idx_loan_type` (`loan_type`),
  CONSTRAINT `fk_loan_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: po_schemes (Post Office Schemes)
-- ============================================================
CREATE TABLE IF NOT EXISTS `po_schemes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `scheme_type` enum('PPF','NSC','SCSS','MIS','SSY','KVP','TD','RD','NPS_APY') NOT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `post_office` varchar(100) DEFAULT NULL,
  `principal_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(6,3) NOT NULL DEFAULT 0.000,
  `start_date` date NOT NULL,
  `maturity_date` date DEFAULT NULL,
  `maturity_amount` decimal(14,2) DEFAULT NULL,
  `interest_earned` decimal(14,2) NOT NULL DEFAULT 0.00,
  `annual_deposit` decimal(14,2) DEFAULT NULL,
  `status` enum('active','matured','closed','extended') NOT NULL DEFAULT 'active',
  `nominee` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON: sub-tenures, rates etc' CHECK (json_valid(`meta`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_po_portfolio` (`portfolio_id`),
  KEY `idx_po_type` (`scheme_type`),
  CONSTRAINT `fk_po_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: goal_buckets
-- ============================================================
CREATE TABLE IF NOT EXISTS `goal_buckets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `emoji` varchar(10) NOT NULL DEFAULT '🎯',
  `color` varchar(7) NOT NULL DEFAULT '#6366f1',
  `target_amount` decimal(16,2) NOT NULL DEFAULT 0.00,
  `target_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_achieved` tinyint(1) NOT NULL DEFAULT 0,
  `achieved_at` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gb_user` (`user_id`),
  CONSTRAINT `fk_gb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: goal_fund_links
-- ============================================================
CREATE TABLE IF NOT EXISTS `goal_fund_links` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `goal_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED DEFAULT NULL,
  `sip_id` int(10) UNSIGNED DEFAULT NULL,
  `link_type` enum('holding','sip') NOT NULL DEFAULT 'holding',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gfl_goal` (`goal_id`),
  CONSTRAINT `fk_gfl_goal` FOREIGN KEY (`goal_id`) REFERENCES `goal_buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: investment_goals  (Migration 003)
-- ============================================================
CREATE TABLE IF NOT EXISTS `investment_goals` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `target_amount` decimal(16,2) NOT NULL,
  `target_date` date NOT NULL,
  `current_saved` decimal(16,2) NOT NULL DEFAULT 0.00,
  `monthly_sip_needed` decimal(14,2) DEFAULT NULL,
  `expected_return_pct` decimal(5,2) NOT NULL DEFAULT 12.00,
  `priority` enum('high','medium','low') NOT NULL DEFAULT 'medium',
  `color` varchar(7) NOT NULL DEFAULT '#2563EB',
  `icon` varchar(30) DEFAULT 'target',
  `linked_fund_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`linked_fund_ids`)),
  `linked_stock_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`linked_stock_ids`)),
  `linked_fd_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`linked_fd_ids`)),
  `is_achieved` tinyint(1) NOT NULL DEFAULT 0,
  `achieved_at` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ig_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_ig_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: goal_contributions  (Migration 003)
-- ============================================================
CREATE TABLE IF NOT EXISTS `goal_contributions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `goal_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `contribution_date` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gc_goal` (`goal_id`),
  CONSTRAINT `fk_gc_goal` FOREIGN KEY (`goal_id`) REFERENCES `investment_goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: nav_download_progress
-- ============================================================
CREATE TABLE IF NOT EXISTS `nav_download_progress` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `scheme_code` varchar(20) NOT NULL,
  `status` enum('pending','downloading','done','error','needs_update') NOT NULL DEFAULT 'pending',
  `total_records` int(11) NOT NULL DEFAULT 0,
  `last_nav_date` date DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ndp_fund` (`fund_id`),
  KEY `idx_ndp_status` (`status`),
  CONSTRAINT `fk_ndp_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
