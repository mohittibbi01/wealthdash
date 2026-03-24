-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 21, 2026 at 08:15 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wealthdash`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_accounts`
--

CREATE TABLE `fd_accounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `fd_number` varchar(50) DEFAULT NULL,
  `principal` decimal(14,2) NOT NULL,
  `interest_rate` decimal(6,3) NOT NULL,
  `is_senior_rate` tinyint(1) NOT NULL DEFAULT 0,
  `compounding_type` enum('monthly','quarterly','half_yearly','yearly','cumulative') NOT NULL DEFAULT 'cumulative',
  `open_date` date NOT NULL,
  `maturity_date` date NOT NULL,
  `maturity_amount` decimal(14,2) DEFAULT NULL,
  `tds_applicable` tinyint(1) NOT NULL DEFAULT 1,
  `form_15g_submitted` tinyint(1) NOT NULL DEFAULT 0,
  `auto_renewal` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','matured','broken','renewed') NOT NULL DEFAULT 'active',
  `investment_fy` varchar(7) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fd_interest_accruals`
--

CREATE TABLE `fd_interest_accruals` (
  `id` int(10) UNSIGNED NOT NULL,
  `fd_id` int(10) UNSIGNED NOT NULL,
  `fy` varchar(7) NOT NULL,
  `accrued_interest` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tds_deducted` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_interest` decimal(14,2) NOT NULL DEFAULT 0.00,
  `calculated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `funds`
--

CREATE TABLE `funds` (
  `id` int(10) UNSIGNED NOT NULL,
  `fund_house_id` int(10) UNSIGNED NOT NULL,
  `scheme_code` varchar(20) NOT NULL,
  `scheme_name` varchar(300) NOT NULL,
  `isin_growth` varchar(15) DEFAULT NULL,
  `isin_div` varchar(15) DEFAULT NULL,
  `category` varchar(80) DEFAULT NULL,
  `sub_category` varchar(100) DEFAULT NULL,
  `fund_type` enum('open_ended','close_ended','interval') DEFAULT 'open_ended',
  `option_type` enum('growth','dividend','idcw','bonus') DEFAULT 'growth',
  `min_ltcg_days` smallint(5) UNSIGNED NOT NULL DEFAULT 365,
  `lock_in_days` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `latest_nav` decimal(12,4) DEFAULT NULL,
  `latest_nav_date` date DEFAULT NULL,
  `prev_nav` decimal(12,4) DEFAULT NULL,
  `prev_nav_date` date DEFAULT NULL,
  `highest_nav` decimal(12,4) DEFAULT NULL,
  `highest_nav_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expense_ratio` decimal(5,2) DEFAULT NULL COMMENT 'Annual expense ratio %',
  `exit_load_pct` decimal(5,2) DEFAULT NULL COMMENT 'Exit load percentage',
  `exit_load_days` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Exit load applicable days',
  `aum_crore` decimal(12,2) DEFAULT NULL COMMENT 'AUM in crores',
  `risk_level` enum('Low','Low to Moderate','Moderate','Moderately High','High','Very High') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fund_houses`
--

CREATE TABLE `fund_houses` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `short_name` varchar(50) DEFAULT NULL,
  `amfi_code` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `google_auth`
--

CREATE TABLE `google_auth` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `google_id` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `access_token` text DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `success` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mf_dividends`
--

CREATE TABLE `mf_dividends` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(30) DEFAULT NULL,
  `div_date` date NOT NULL,
  `div_per_unit` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `total_units` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `dividend_fy` varchar(7) DEFAULT NULL,
  `is_auto_fetched` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mf_holdings`
--

CREATE TABLE `mf_holdings` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(30) DEFAULT NULL,
  `total_units` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `avg_cost_nav` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `total_invested` decimal(14,2) NOT NULL DEFAULT 0.00,
  `value_now` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gain_loss` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gain_pct` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `cagr` decimal(8,4) DEFAULT NULL,
  `first_purchase_date` date DEFAULT NULL,
  `ltcg_date` date DEFAULT NULL,
  `lock_in_date` date DEFAULT NULL,
  `withdrawable_date` date DEFAULT NULL,
  `investment_fy` varchar(7) DEFAULT NULL,
  `withdrawable_fy` varchar(7) DEFAULT NULL,
  `gain_type` enum('LTCG','STCG','MIXED','NA') DEFAULT 'NA',
  `highest_nav` decimal(12,4) DEFAULT NULL,
  `highest_nav_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_calculated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mf_peak_progress`
--

CREATE TABLE `mf_peak_progress` (
  `scheme_code` varchar(20) NOT NULL,
  `last_processed_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','error') DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mf_transactions`
--

CREATE TABLE `mf_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `folio_number` varchar(30) DEFAULT NULL,
  `transaction_type` enum('BUY','SELL','DIV_REINVEST','DIV_PAYOUT','SWITCH_IN','SWITCH_OUT','STP_IN','STP_OUT','SWP') NOT NULL,
  `platform` varchar(50) DEFAULT NULL,
  `txn_date` date NOT NULL,
  `units` decimal(14,4) NOT NULL,
  `nav` decimal(12,4) NOT NULL,
  `value_at_cost` decimal(14,2) NOT NULL,
  `investment_fy` varchar(7) DEFAULT NULL,
  `stamp_duty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `import_source` enum('manual','csv_wealthdash','csv_cams','csv_kfintech','csv_zerodha','csv_groww') DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nav_download_progress`
--

CREATE TABLE `nav_download_progress` (
  `scheme_code` varchar(20) NOT NULL,
  `fund_id` int(11) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','error') DEFAULT 'pending',
  `from_date` date DEFAULT NULL,
  `last_downloaded_date` date DEFAULT NULL,
  `records_saved` int(11) DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nav_history`
--

CREATE TABLE `nav_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` int(10) UNSIGNED NOT NULL,
  `nav_date` date NOT NULL,
  `nav` decimal(12,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nps_holdings`
--

CREATE TABLE `nps_holdings` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `scheme_id` int(10) UNSIGNED NOT NULL,
  `tier` enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `total_units` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `total_invested` decimal(14,2) NOT NULL DEFAULT 0.00,
  `latest_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gain_loss` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gain_pct` decimal(8,4) DEFAULT NULL,
  `cagr` decimal(8,4) DEFAULT NULL,
  `first_contribution_date` date DEFAULT NULL,
  `last_calculated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nps_schemes`
--

CREATE TABLE `nps_schemes` (
  `id` int(10) UNSIGNED NOT NULL,
  `pfm_name` varchar(100) NOT NULL,
  `scheme_name` varchar(200) NOT NULL,
  `scheme_code` varchar(30) NOT NULL,
  `tier` enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `asset_class` enum('E','C','G','A') DEFAULT 'E',
  `latest_nav` decimal(12,4) DEFAULT NULL,
  `latest_nav_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nps_transactions`
--

CREATE TABLE `nps_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `scheme_id` int(10) UNSIGNED NOT NULL,
  `tier` enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `contribution_type` enum('SELF','EMPLOYER') NOT NULL DEFAULT 'SELF',
  `txn_date` date NOT NULL,
  `units` decimal(14,4) NOT NULL,
  `nav` decimal(12,4) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `investment_fy` varchar(7) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_tokens`
--

CREATE TABLE `otp_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `purpose` enum('login','register','password_reset') NOT NULL DEFAULT 'login',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(150) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `portfolios`
--

CREATE TABLE `portfolios` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#3B82F6',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `portfolio_members`
--

CREATE TABLE `portfolio_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `added_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `po_schemes`
--

CREATE TABLE `po_schemes` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `scheme_type` enum('savings_account','rd','td','mis','scss','ppf','ssy','nsc','kvp') NOT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `holder_name` varchar(100) NOT NULL,
  `principal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(6,3) NOT NULL,
  `open_date` date NOT NULL,
  `maturity_date` date DEFAULT NULL,
  `maturity_amount` decimal(14,2) DEFAULT NULL,
  `current_value` decimal(14,2) DEFAULT NULL COMMENT 'For PPF/SSY: running balance',
  `deposit_amount` decimal(14,2) DEFAULT NULL COMMENT 'For RD: monthly instalment',
  `interest_freq` enum('monthly','quarterly','half_yearly','yearly','cumulative','on_maturity') NOT NULL DEFAULT 'yearly',
  `compounding` enum('simple','compound') NOT NULL DEFAULT 'compound',
  `status` enum('active','matured','closed','partial_withdrawn') NOT NULL DEFAULT 'active',
  `is_joint` tinyint(1) NOT NULL DEFAULT 0,
  `nominee` varchar(100) DEFAULT NULL,
  `post_office` varchar(150) DEFAULT NULL COMMENT 'Branch / post office name',
  `notes` text DEFAULT NULL,
  `investment_fy` varchar(7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `savings_accounts`
--

CREATE TABLE `savings_accounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `account_type` enum('savings','current','salary') NOT NULL DEFAULT 'savings',
  `account_no` varchar(20) DEFAULT NULL,
  `interest_rate` decimal(5,3) NOT NULL DEFAULT 3.500,
  `balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `savings_interest_log`
--

CREATE TABLE `savings_interest_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `savings_account_id` int(10) UNSIGNED NOT NULL,
  `month` varchar(7) NOT NULL,
  `interest_earned` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(128) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sip_schedules`
--

CREATE TABLE `sip_schedules` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `asset_type` enum('mf','stock','nps') NOT NULL DEFAULT 'mf',
  `schedule_type` enum('SIP','SWP') NOT NULL DEFAULT 'SIP',
  `fund_id` int(10) UNSIGNED DEFAULT NULL,
  `stock_id` int(10) UNSIGNED DEFAULT NULL,
  `nps_scheme_id` int(10) UNSIGNED DEFAULT NULL,
  `folio_number` varchar(50) DEFAULT NULL,
  `sip_amount` decimal(14,2) NOT NULL,
  `frequency` enum('daily','weekly','fortnightly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  `sip_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Day of month (1-28)',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'NULL = ongoing',
  `platform` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_corporate_actions`
--

CREATE TABLE `stock_corporate_actions` (
  `id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `action_type` enum('SPLIT','BONUS','RIGHTS') NOT NULL,
  `action_date` date NOT NULL,
  `ratio` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_dividends`
--

CREATE TABLE `stock_dividends` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `div_date` date NOT NULL,
  `amount_per_share` decimal(10,4) NOT NULL,
  `total_shares` decimal(14,4) NOT NULL,
  `total_amount` decimal(14,2) NOT NULL,
  `dividend_fy` varchar(7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_holdings`
--

CREATE TABLE `stock_holdings` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `avg_buy_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_invested` decimal(14,2) NOT NULL DEFAULT 0.00,
  `current_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gain_loss` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gain_pct` decimal(8,4) DEFAULT NULL,
  `cagr` decimal(8,4) DEFAULT NULL,
  `first_buy_date` date DEFAULT NULL,
  `ltcg_date` date DEFAULT NULL,
  `gain_type` enum('LTCG','STCG','MIXED','NA') DEFAULT 'NA',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_calculated` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_master`
--

CREATE TABLE `stock_master` (
  `id` int(10) UNSIGNED NOT NULL,
  `exchange` enum('NSE','BSE') NOT NULL DEFAULT 'NSE',
  `symbol` varchar(30) NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `isin` varchar(15) DEFAULT NULL,
  `sector` varchar(100) DEFAULT NULL,
  `latest_price` decimal(12,2) DEFAULT NULL,
  `latest_price_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_transactions`
--

CREATE TABLE `stock_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `stock_id` int(10) UNSIGNED NOT NULL,
  `txn_type` enum('BUY','SELL','BONUS','SPLIT','DIV') NOT NULL,
  `txn_date` date NOT NULL,
  `quantity` decimal(14,4) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `brokerage` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `exchange_charges` decimal(10,2) NOT NULL DEFAULT 0.00,
  `value_at_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `investment_fy` varchar(7) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `import_source` enum('manual','csv_zerodha','csv_groww','csv_custom') DEFAULT 'manual',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `mobile` varchar(15) DEFAULT NULL,
  `role` enum('admin','member') NOT NULL DEFAULT 'member',
  `is_senior_citizen` tinyint(1) NOT NULL DEFAULT 0,
  `theme` enum('light','dark') NOT NULL DEFAULT 'light',
  `status` enum('active','inactive','banned') NOT NULL DEFAULT 'active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `mobile_verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `login_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_key` (`setting_key`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `fd_accounts`
--
ALTER TABLE `fd_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_fd_portfolio` (`portfolio_id`),
  ADD KEY `idx_maturity_date` (`maturity_date`);

--
-- Indexes for table `fd_interest_accruals`
--
ALTER TABLE `fd_interest_accruals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fd_fy` (`fd_id`,`fy`);

--
-- Indexes for table `funds`
--
ALTER TABLE `funds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  ADD KEY `fk_fund_house` (`fund_house_id`),
  ADD KEY `idx_scheme_name` (`scheme_name`(100)),
  ADD KEY `idx_isin_growth` (`isin_growth`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_option_type` (`option_type`),
  ADD KEY `idx_ltcg_days` (`min_ltcg_days`),
  ADD KEY `idx_lock_in` (`lock_in_days`),
  ADD KEY `idx_screener` (`is_active`,`option_type`,`min_ltcg_days`),
  ADD KEY `idx_risk` (`risk_level`);

--
-- Indexes for table `fund_houses`
--
ALTER TABLE `fund_houses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_name` (`name`);

--
-- Indexes for table `google_auth`
--
ALTER TABLE `google_auth`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_google_id` (`google_id`),
  ADD KEY `fk_gauth_user` (`user_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempted_at`),
  ADD KEY `idx_email_time` (`email`,`attempted_at`);

--
-- Indexes for table `mf_dividends`
--
ALTER TABLE `mf_dividends`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mfd_portfolio` (`portfolio_id`),
  ADD KEY `fk_mfd_fund` (`fund_id`);

--
-- Indexes for table `mf_holdings`
--
ALTER TABLE `mf_holdings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_port_fund_folio` (`portfolio_id`,`fund_id`,`folio_number`),
  ADD KEY `fk_mfh_fund` (`fund_id`);

--
-- Indexes for table `mf_peak_progress`
--
ALTER TABLE `mf_peak_progress`
  ADD PRIMARY KEY (`scheme_code`);

--
-- Indexes for table `mf_transactions`
--
ALTER TABLE `mf_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mft_portfolio` (`portfolio_id`),
  ADD KEY `fk_mft_fund` (`fund_id`),
  ADD KEY `idx_txn_date` (`txn_date`),
  ADD KEY `idx_folio` (`folio_number`);

--
-- Indexes for table `nav_download_progress`
--
ALTER TABLE `nav_download_progress`
  ADD PRIMARY KEY (`scheme_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `nav_history`
--
ALTER TABLE `nav_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fund_date` (`fund_id`,`nav_date`),
  ADD KEY `idx_fund_date` (`fund_id`,`nav_date`);

--
-- Indexes for table `nps_holdings`
--
ALTER TABLE `nps_holdings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_port_scheme_tier` (`portfolio_id`,`scheme_id`,`tier`),
  ADD KEY `fk_npsh_scheme` (`scheme_id`);

--
-- Indexes for table `nps_schemes`
--
ALTER TABLE `nps_schemes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  ADD KEY `idx_pfm` (`pfm_name`);

--
-- Indexes for table `nps_transactions`
--
ALTER TABLE `nps_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_npst_portfolio` (`portfolio_id`),
  ADD KEY `fk_npst_scheme` (`scheme_id`);

--
-- Indexes for table `otp_tokens`
--
ALTER TABLE `otp_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_otp_user` (`user_id`),
  ADD KEY `idx_mobile_exp` (`mobile`,`expires_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `portfolios`
--
ALTER TABLE `portfolios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_portfolio_user` (`user_id`);

--
-- Indexes for table `portfolio_members`
--
ALTER TABLE `portfolio_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_port_user` (`portfolio_id`,`user_id`),
  ADD KEY `fk_pm_user` (`user_id`);

--
-- Indexes for table `po_schemes`
--
ALTER TABLE `po_schemes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po_portfolio` (`portfolio_id`),
  ADD KEY `idx_po_type` (`scheme_type`),
  ADD KEY `idx_po_status` (`status`);

--
-- Indexes for table `savings_accounts`
--
ALTER TABLE `savings_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sa_portfolio` (`portfolio_id`);

--
-- Indexes for table `savings_interest_log`
--
ALTER TABLE `savings_interest_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_account_month` (`savings_account_id`,`month`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_token` (`token`),
  ADD KEY `fk_sess_user` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `sip_schedules`
--
ALTER TABLE `sip_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sip_portfolio` (`portfolio_id`),
  ADD KEY `idx_sip_fund` (`fund_id`);

--
-- Indexes for table `stock_corporate_actions`
--
ALTER TABLE `stock_corporate_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sca_stock` (`stock_id`);

--
-- Indexes for table `stock_dividends`
--
ALTER TABLE `stock_dividends`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_sd_portfolio` (`portfolio_id`),
  ADD KEY `fk_sd_stock` (`stock_id`);

--
-- Indexes for table `stock_holdings`
--
ALTER TABLE `stock_holdings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_port_stock` (`portfolio_id`,`stock_id`),
  ADD KEY `fk_sh_stock` (`stock_id`);

--
-- Indexes for table `stock_master`
--
ALTER TABLE `stock_master`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_exchange_symbol` (`exchange`,`symbol`),
  ADD KEY `idx_symbol` (`symbol`),
  ADD KEY `idx_isin` (`isin`);

--
-- Indexes for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_st_portfolio` (`portfolio_id`),
  ADD KEY `fk_st_stock` (`stock_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app_settings`
--
ALTER TABLE `app_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_accounts`
--
ALTER TABLE `fd_accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fd_interest_accruals`
--
ALTER TABLE `fd_interest_accruals`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `funds`
--
ALTER TABLE `funds`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fund_houses`
--
ALTER TABLE `fund_houses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `google_auth`
--
ALTER TABLE `google_auth`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mf_dividends`
--
ALTER TABLE `mf_dividends`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mf_holdings`
--
ALTER TABLE `mf_holdings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mf_transactions`
--
ALTER TABLE `mf_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nav_history`
--
ALTER TABLE `nav_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nps_holdings`
--
ALTER TABLE `nps_holdings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nps_schemes`
--
ALTER TABLE `nps_schemes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nps_transactions`
--
ALTER TABLE `nps_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otp_tokens`
--
ALTER TABLE `otp_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `portfolios`
--
ALTER TABLE `portfolios`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `portfolio_members`
--
ALTER TABLE `portfolio_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `po_schemes`
--
ALTER TABLE `po_schemes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `savings_accounts`
--
ALTER TABLE `savings_accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `savings_interest_log`
--
ALTER TABLE `savings_interest_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sip_schedules`
--
ALTER TABLE `sip_schedules`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_corporate_actions`
--
ALTER TABLE `stock_corporate_actions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_dividends`
--
ALTER TABLE `stock_dividends`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_holdings`
--
ALTER TABLE `stock_holdings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_master`
--
ALTER TABLE `stock_master`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `fd_accounts`
--
ALTER TABLE `fd_accounts`
  ADD CONSTRAINT `fk_fd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fd_interest_accruals`
--
ALTER TABLE `fd_interest_accruals`
  ADD CONSTRAINT `fk_fdia_fd` FOREIGN KEY (`fd_id`) REFERENCES `fd_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `funds`
--
ALTER TABLE `funds`
  ADD CONSTRAINT `fk_fund_house` FOREIGN KEY (`fund_house_id`) REFERENCES `fund_houses` (`id`);

--
-- Constraints for table `google_auth`
--
ALTER TABLE `google_auth`
  ADD CONSTRAINT `fk_gauth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mf_dividends`
--
ALTER TABLE `mf_dividends`
  ADD CONSTRAINT `fk_mfd_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`),
  ADD CONSTRAINT `fk_mfd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mf_holdings`
--
ALTER TABLE `mf_holdings`
  ADD CONSTRAINT `fk_mfh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`),
  ADD CONSTRAINT `fk_mfh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mf_transactions`
--
ALTER TABLE `mf_transactions`
  ADD CONSTRAINT `fk_mft_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`),
  ADD CONSTRAINT `fk_mft_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `nav_history`
--
ALTER TABLE `nav_history`
  ADD CONSTRAINT `fk_navh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `nps_holdings`
--
ALTER TABLE `nps_holdings`
  ADD CONSTRAINT `fk_npsh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_npsh_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`);

--
-- Constraints for table `nps_transactions`
--
ALTER TABLE `nps_transactions`
  ADD CONSTRAINT `fk_npst_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_npst_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`);

--
-- Constraints for table `otp_tokens`
--
ALTER TABLE `otp_tokens`
  ADD CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `portfolios`
--
ALTER TABLE `portfolios`
  ADD CONSTRAINT `fk_portfolio_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `portfolio_members`
--
ALTER TABLE `portfolio_members`
  ADD CONSTRAINT `fk_pm_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `po_schemes`
--
ALTER TABLE `po_schemes`
  ADD CONSTRAINT `fk_po_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `savings_accounts`
--
ALTER TABLE `savings_accounts`
  ADD CONSTRAINT `fk_sa_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `savings_interest_log`
--
ALTER TABLE `savings_interest_log`
  ADD CONSTRAINT `fk_sil_sa` FOREIGN KEY (`savings_account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sip_schedules`
--
ALTER TABLE `sip_schedules`
  ADD CONSTRAINT `fk_sip_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sip_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_corporate_actions`
--
ALTER TABLE `stock_corporate_actions`
  ADD CONSTRAINT `fk_sca_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_dividends`
--
ALTER TABLE `stock_dividends`
  ADD CONSTRAINT `fk_sd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sd_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`);

--
-- Constraints for table `stock_holdings`
--
ALTER TABLE `stock_holdings`
  ADD CONSTRAINT `fk_sh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sh_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`);

--
-- Constraints for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  ADD CONSTRAINT `fk_st_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_st_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
