-- ============================================================
-- WealthDash — Complete Database Schema v1.0
-- PHP 8.x + MySQL 8.x | XAMPP Local
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";

-- GROUP 1: AUTH & USERS

DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `otp_tokens`;
DROP TABLE IF EXISTS `google_auth`;
DROP TABLE IF EXISTS `portfolio_members`;
DROP TABLE IF EXISTS `mf_dividends`;
DROP TABLE IF EXISTS `mf_holdings`;
DROP TABLE IF EXISTS `mf_transactions`;
DROP TABLE IF EXISTS `nps_holdings`;
DROP TABLE IF EXISTS `nps_transactions`;
DROP TABLE IF EXISTS `stock_dividends`;
DROP TABLE IF EXISTS `stock_corporate_actions`;
DROP TABLE IF EXISTS `stock_holdings`;
DROP TABLE IF EXISTS `stock_transactions`;
DROP TABLE IF EXISTS `fd_interest_accruals`;
DROP TABLE IF EXISTS `fd_accounts`;
DROP TABLE IF EXISTS `savings_interest_log`;
DROP TABLE IF EXISTS `savings_accounts`;
DROP TABLE IF EXISTS `nav_history`;
DROP TABLE IF EXISTS `funds`;
DROP TABLE IF EXISTS `fund_houses`;
DROP TABLE IF EXISTS `nps_schemes`;
DROP TABLE IF EXISTS `stock_master`;
DROP TABLE IF EXISTS `portfolios`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `app_settings`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `mobile` VARCHAR(15) DEFAULT NULL,
  `role` ENUM('admin','member') NOT NULL DEFAULT 'member',
  `is_senior_citizen` TINYINT(1) NOT NULL DEFAULT 0,
  `theme` ENUM('light','dark') NOT NULL DEFAULT 'light',
  `status` ENUM('active','inactive','banned') NOT NULL DEFAULT 'active',
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `mobile_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login_at` DATETIME DEFAULT NULL,
  `login_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `google_auth` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `google_id` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `access_token` TEXT DEFAULT NULL,
  `token_expiry` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_google_id` (`google_id`),
  KEY `fk_gauth_user` (`user_id`),
  CONSTRAINT `fk_gauth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `otp_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `otp_hash` VARCHAR(255) NOT NULL,
  `purpose` ENUM('login','register','password_reset') NOT NULL DEFAULT 'login',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_otp_user` (`user_id`),
  KEY `idx_mobile_exp` (`mobile`, `expires_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(150) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `fk_sess_user` (`user_id`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_email_time` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 2: PORTFOLIO STRUCTURE

CREATE TABLE `portfolios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `color` VARCHAR(7) NOT NULL DEFAULT '#3B82F6',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_portfolio_user` (`user_id`),
  CONSTRAINT `fk_portfolio_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `portfolio_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
  `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port_user` (`portfolio_id`, `user_id`),
  KEY `fk_pm_user` (`user_id`),
  CONSTRAINT `fk_pm_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 3: MASTER DATA

CREATE TABLE `fund_houses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `short_name` VARCHAR(50) DEFAULT NULL,
  `amfi_code` VARCHAR(20) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `funds` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_house_id` INT UNSIGNED NOT NULL,
  `scheme_code` VARCHAR(20) NOT NULL,
  `scheme_name` VARCHAR(300) NOT NULL,
  `isin_growth` VARCHAR(15) DEFAULT NULL,
  `isin_div` VARCHAR(15) DEFAULT NULL,
  `category` VARCHAR(80) DEFAULT NULL,
  `sub_category` VARCHAR(100) DEFAULT NULL,
  `fund_type` ENUM('open_ended','close_ended','interval') DEFAULT 'open_ended',
  `option_type` ENUM('growth','dividend','idcw','bonus') DEFAULT 'growth',
  `min_ltcg_days` SMALLINT UNSIGNED NOT NULL DEFAULT 365,
  `lock_in_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `latest_nav` DECIMAL(12,4) DEFAULT NULL,
  `latest_nav_date` DATE DEFAULT NULL,
  `highest_nav` DECIMAL(12,4) DEFAULT NULL,
  `highest_nav_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  KEY `fk_fund_house` (`fund_house_id`),
  KEY `idx_scheme_name` (`scheme_name`(100)),
  KEY `idx_isin_growth` (`isin_growth`),
  CONSTRAINT `fk_fund_house` FOREIGN KEY (`fund_house_id`) REFERENCES `fund_houses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `nav_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id` INT UNSIGNED NOT NULL,
  `nav_date` DATE NOT NULL,
  `nav` DECIMAL(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_date` (`fund_id`, `nav_date`),
  KEY `idx_fund_date` (`fund_id`, `nav_date`),
  CONSTRAINT `fk_navh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `nps_schemes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pfm_name` VARCHAR(100) NOT NULL,
  `scheme_name` VARCHAR(200) NOT NULL,
  `scheme_code` VARCHAR(30) NOT NULL,
  `tier` ENUM('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `asset_class` ENUM('E','C','G','A') DEFAULT 'E',
  `latest_nav` DECIMAL(12,4) DEFAULT NULL,
  `latest_nav_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  KEY `idx_pfm` (`pfm_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_master` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exchange` ENUM('NSE','BSE') NOT NULL DEFAULT 'NSE',
  `symbol` VARCHAR(30) NOT NULL,
  `company_name` VARCHAR(200) NOT NULL,
  `isin` VARCHAR(15) DEFAULT NULL,
  `sector` VARCHAR(100) DEFAULT NULL,
  `latest_price` DECIMAL(12,2) DEFAULT NULL,
  `latest_price_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exchange_symbol` (`exchange`, `symbol`),
  KEY `idx_symbol` (`symbol`),
  KEY `idx_isin` (`isin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 4: MF TRANSACTIONS & HOLDINGS

CREATE TABLE `mf_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `fund_id` INT UNSIGNED NOT NULL,
  `folio_number` VARCHAR(30) DEFAULT NULL,
  `transaction_type` ENUM('BUY','SELL','DIV_REINVEST','DIV_PAYOUT','SWITCH_IN','SWITCH_OUT','STP_IN','STP_OUT','SWP') NOT NULL,
  `platform` VARCHAR(50) DEFAULT NULL,
  `txn_date` DATE NOT NULL,
  `units` DECIMAL(14,4) NOT NULL,
  `nav` DECIMAL(12,4) NOT NULL,
  `value_at_cost` DECIMAL(14,2) NOT NULL,
  `investment_fy` VARCHAR(7) DEFAULT NULL,
  `stamp_duty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `import_source` ENUM('manual','csv_wealthdash','csv_cams','csv_kfintech','csv_zerodha','csv_groww') DEFAULT 'manual',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_mft_portfolio` (`portfolio_id`),
  KEY `fk_mft_fund` (`fund_id`),
  KEY `idx_txn_date` (`txn_date`),
  KEY `idx_folio` (`folio_number`),
  CONSTRAINT `fk_mft_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mft_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mf_holdings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `fund_id` INT UNSIGNED NOT NULL,
  `folio_number` VARCHAR(30) DEFAULT NULL,
  `total_units` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  `avg_cost_nav` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  `total_invested` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `value_now` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gain_loss` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gain_pct` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `cagr` DECIMAL(8,4) DEFAULT NULL,
  `first_purchase_date` DATE DEFAULT NULL,
  `ltcg_date` DATE DEFAULT NULL,
  `lock_in_date` DATE DEFAULT NULL,
  `withdrawable_date` DATE DEFAULT NULL,
  `investment_fy` VARCHAR(7) DEFAULT NULL,
  `withdrawable_fy` VARCHAR(7) DEFAULT NULL,
  `gain_type` ENUM('LTCG','STCG','MIXED','NA') DEFAULT 'NA',
  `highest_nav` DECIMAL(12,4) DEFAULT NULL,
  `highest_nav_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_calculated` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port_fund_folio` (`portfolio_id`, `fund_id`, `folio_number`),
  KEY `fk_mfh_fund` (`fund_id`),
  CONSTRAINT `fk_mfh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mfh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mf_dividends` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `fund_id` INT UNSIGNED NOT NULL,
  `folio_number` VARCHAR(30) DEFAULT NULL,
  `div_date` DATE NOT NULL,
  `div_per_unit` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  `total_units` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `dividend_fy` VARCHAR(7) DEFAULT NULL,
  `is_auto_fetched` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_mfd_portfolio` (`portfolio_id`),
  KEY `fk_mfd_fund` (`fund_id`),
  CONSTRAINT `fk_mfd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mfd_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 5: NPS

CREATE TABLE `nps_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `scheme_id` INT UNSIGNED NOT NULL,
  `tier` ENUM('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `contribution_type` ENUM('SELF','EMPLOYER') NOT NULL DEFAULT 'SELF',
  `txn_date` DATE NOT NULL,
  `units` DECIMAL(14,4) NOT NULL,
  `nav` DECIMAL(12,4) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `investment_fy` VARCHAR(7) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_npst_portfolio` (`portfolio_id`),
  KEY `fk_npst_scheme` (`scheme_id`),
  CONSTRAINT `fk_npst_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_npst_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `nps_holdings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `scheme_id` INT UNSIGNED NOT NULL,
  `tier` ENUM('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `total_units` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  `total_invested` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `latest_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gain_loss` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gain_pct` DECIMAL(8,4) DEFAULT NULL,
  `cagr` DECIMAL(8,4) DEFAULT NULL,
  `first_contribution_date` DATE DEFAULT NULL,
  `last_calculated` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port_scheme_tier` (`portfolio_id`, `scheme_id`, `tier`),
  KEY `fk_npsh_scheme` (`scheme_id`),
  CONSTRAINT `fk_npsh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_npsh_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 6: STOCKS & ETF

CREATE TABLE `stock_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `stock_id` INT UNSIGNED NOT NULL,
  `txn_type` ENUM('BUY','SELL','BONUS','SPLIT','DIV') NOT NULL,
  `txn_date` DATE NOT NULL,
  `quantity` DECIMAL(14,4) NOT NULL,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `brokerage` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stt` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `exchange_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `value_at_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `investment_fy` VARCHAR(7) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `import_source` ENUM('manual','csv_zerodha','csv_groww','csv_custom') DEFAULT 'manual',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_st_portfolio` (`portfolio_id`),
  KEY `fk_st_stock` (`stock_id`),
  CONSTRAINT `fk_st_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_holdings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `stock_id` INT UNSIGNED NOT NULL,
  `quantity` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  `avg_buy_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_invested` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `current_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gain_loss` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gain_pct` DECIMAL(8,4) DEFAULT NULL,
  `cagr` DECIMAL(8,4) DEFAULT NULL,
  `first_buy_date` DATE DEFAULT NULL,
  `ltcg_date` DATE DEFAULT NULL,
  `gain_type` ENUM('LTCG','STCG','MIXED','NA') DEFAULT 'NA',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_calculated` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port_stock` (`portfolio_id`, `stock_id`),
  KEY `fk_sh_stock` (`stock_id`),
  CONSTRAINT `fk_sh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sh_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_dividends` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `stock_id` INT UNSIGNED NOT NULL,
  `div_date` DATE NOT NULL,
  `amount_per_share` DECIMAL(10,4) NOT NULL,
  `total_shares` DECIMAL(14,4) NOT NULL,
  `total_amount` DECIMAL(14,2) NOT NULL,
  `dividend_fy` VARCHAR(7) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sd_portfolio` (`portfolio_id`),
  KEY `fk_sd_stock` (`stock_id`),
  CONSTRAINT `fk_sd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sd_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_corporate_actions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_id` INT UNSIGNED NOT NULL,
  `action_type` ENUM('SPLIT','BONUS','RIGHTS') NOT NULL,
  `action_date` DATE NOT NULL,
  `ratio` VARCHAR(20) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `processed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sca_stock` (`stock_id`),
  CONSTRAINT `fk_sca_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 7: FIXED DEPOSITS

CREATE TABLE `fd_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `bank_name` VARCHAR(100) NOT NULL,
  `fd_number` VARCHAR(50) DEFAULT NULL,
  `principal` DECIMAL(14,2) NOT NULL,
  `interest_rate` DECIMAL(6,3) NOT NULL,
  `is_senior_rate` TINYINT(1) NOT NULL DEFAULT 0,
  `compounding_type` ENUM('monthly','quarterly','half_yearly','yearly','cumulative') NOT NULL DEFAULT 'cumulative',
  `open_date` DATE NOT NULL,
  `maturity_date` DATE NOT NULL,
  `maturity_amount` DECIMAL(14,2) DEFAULT NULL,
  `tds_applicable` TINYINT(1) NOT NULL DEFAULT 1,
  `form_15g_submitted` TINYINT(1) NOT NULL DEFAULT 0,
  `auto_renewal` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('active','matured','broken','renewed') NOT NULL DEFAULT 'active',
  `investment_fy` VARCHAR(7) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_fd_portfolio` (`portfolio_id`),
  KEY `idx_maturity_date` (`maturity_date`),
  CONSTRAINT `fk_fd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fd_interest_accruals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fd_id` INT UNSIGNED NOT NULL,
  `fy` VARCHAR(7) NOT NULL,
  `accrued_interest` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tds_deducted` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_interest` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `calculated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fd_fy` (`fd_id`, `fy`),
  CONSTRAINT `fk_fdia_fd` FOREIGN KEY (`fd_id`) REFERENCES `fd_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 8: SAVINGS ACCOUNTS

CREATE TABLE `savings_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` INT UNSIGNED NOT NULL,
  `bank_name` VARCHAR(100) NOT NULL,
  `account_type` ENUM('savings','current','salary') NOT NULL DEFAULT 'savings',
  `account_no` VARCHAR(20) DEFAULT NULL,
  `interest_rate` DECIMAL(5,3) NOT NULL DEFAULT 3.500,
  `balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `balance_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sa_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_sa_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `savings_interest_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `savings_account_id` INT UNSIGNED NOT NULL,
  `month` VARCHAR(7) NOT NULL,
  `interest_earned` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_month` (`savings_account_id`, `month`),
  CONSTRAINT `fk_sil_sa` FOREIGN KEY (`savings_account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GROUP 9: AUDIT & SETTINGS

CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_val` TEXT DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`, `setting_val`) VALUES
('app_name', 'WealthDash'),
('tax_year_start_month', '4'),
('ltcg_exemption_limit', '125000'),
('equity_ltcg_rate', '12.5'),
('equity_stcg_rate', '20'),
('debt_ltcg_rate', '20'),
('fd_tds_rate', '10'),
('fd_tds_senior_rate', '10'),
('fd_tds_threshold', '40000'),
('fd_tds_threshold_senior', '50000'),
('savings_80tta_limit', '10000'),
('savings_80ttb_limit', '50000'),
('amfi_url', 'https://www.amfiindia.com/spages/NAVAll.txt'),
('nav_last_updated', NULL),
('registration_open', '1');

SET FOREIGN_KEY_CHECKS = 1;
