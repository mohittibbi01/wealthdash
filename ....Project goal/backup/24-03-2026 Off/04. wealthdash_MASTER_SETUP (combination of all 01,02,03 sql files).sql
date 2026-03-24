-- ════════════════════════════════════════════════════════════════════════════
--  WealthDash — MASTER SETUP FILE
--  Naya PC pe sirf yeh ek file phpMyAdmin mein import karo
--  Sequence: DB Create → Schema → Seed Data → Peak NAV Setup
-- ════════════════════════════════════════════════════════════════════════════
--
--  STEPS (dusre PC pe):
--  1. XAMPP start karo (Apache + MySQL)
--  2. phpMyAdmin kholо → localhost/phpmyadmin
--  3. Top mein "Import" tab click karo (koi bhi DB select mat karo pehle)
--  4. Yeh file choose karo → Go
--  5. Done! wealthdash DB ban jayega with all tables + seed data
--
-- ════════════════════════════════════════════════════════════════════════════

SET SQL_MODE   = 'NO_AUTO_VALUE_ON_ZERO';
SET NAMES      utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── STEP 1: Database Create ──────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS `wealthdash`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `wealthdash`;

-- ════════════════════════════════════════════════════════════════════════════
--  STEP 2: SCHEMA — All Tables
-- ════════════════════════════════════════════════════════════════════════════

-- ── app_settings ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100)     NOT NULL,
  `setting_val` text             DEFAULT NULL,
  `updated_by`  int(10) unsigned DEFAULT NULL,
  `updated_at`  datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── audit_log ────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(10)  unsigned   DEFAULT NULL,
  `action`      varchar(100)        NOT NULL,
  `entity_type` varchar(50)         DEFAULT NULL,
  `entity_id`   int(10)  unsigned   DEFAULT NULL,
  `old_values`  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values`  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address`  varchar(45)         DEFAULT NULL,
  `created_at`  datetime            NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_action`  (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── users ────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`             varchar(100)     NOT NULL,
  `email`            varchar(150)     NOT NULL,
  `password_hash`    varchar(255)     DEFAULT NULL,
  `mobile`           varchar(15)      DEFAULT NULL,
  `role`             enum('admin','member') NOT NULL DEFAULT 'member',
  `is_senior_citizen` tinyint(1)      NOT NULL DEFAULT 0,
  `theme`            enum('light','dark') NOT NULL DEFAULT 'light',
  `status`           enum('active','inactive','banned') NOT NULL DEFAULT 'active',
  `email_verified`   tinyint(1)       NOT NULL DEFAULT 0,
  `mobile_verified`  tinyint(1)       NOT NULL DEFAULT 0,
  `last_login_at`    datetime         DEFAULT NULL,
  `login_count`      int(10) unsigned NOT NULL DEFAULT 0,
  `created_at`       datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_role`   (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── portfolios ───────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `portfolios`;
CREATE TABLE `portfolios` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int(10) unsigned NOT NULL,
  `name`       varchar(100)     NOT NULL,
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_one_portfolio_per_user` (`user_id`),
  KEY `fk_portfolio_user` (`user_id`),
  CONSTRAINT `fk_portfolio_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── fund_houses ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `fund_houses`;
CREATE TABLE `fund_houses` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(150)     NOT NULL,
  `short_name` varchar(50)      DEFAULT NULL,
  `amfi_code`  varchar(20)      DEFAULT NULL,
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── funds ────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `funds`;
CREATE TABLE `funds` (
  `id`                 int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fund_house_id`      int(10) unsigned NOT NULL,
  `scheme_code`        varchar(20)      NOT NULL,
  `scheme_name`        varchar(300)     NOT NULL,
  `isin_growth`        varchar(15)      DEFAULT NULL,
  `isin_div`           varchar(15)      DEFAULT NULL,
  `category`           varchar(80)      DEFAULT NULL,
  `sub_category`       varchar(100)     DEFAULT NULL,
  `fund_type`          enum('open_ended','close_ended','interval') DEFAULT 'open_ended',
  `option_type`        enum('growth','dividend','idcw','bonus')    DEFAULT 'growth',
  `min_ltcg_days`      smallint(5) unsigned NOT NULL DEFAULT 365,
  `lock_in_days`       smallint(5) unsigned NOT NULL DEFAULT 0,
  `latest_nav`         decimal(12,4)    DEFAULT NULL,
  `latest_nav_date`    date             DEFAULT NULL,
  `prev_nav`           decimal(12,4)    DEFAULT NULL,
  `prev_nav_date`      date             DEFAULT NULL,
  `highest_nav`        decimal(12,4)    DEFAULT NULL,
  `highest_nav_date`   date             DEFAULT NULL,
  `is_active`          tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`         datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `inception_date`     date             DEFAULT NULL,
  `fund_manager`       varchar(200)     DEFAULT NULL,
  `manager_since`      date             DEFAULT NULL,
  `plan_type`          varchar(20)      DEFAULT NULL,
  `expense_ratio`      decimal(5,2)     DEFAULT NULL,
  `exit_load_pct`      decimal(5,2)     DEFAULT NULL,
  `exit_load_days`     smallint(5) unsigned DEFAULT NULL,
  `aum_crore`          decimal(12,2)    DEFAULT NULL,
  `risk_level`         enum('Low','Low to Moderate','Moderate','Moderately High','High','Very High') DEFAULT NULL,
  `returns_1y`         decimal(6,2)     DEFAULT NULL,
  `returns_3y`         decimal(6,2)     DEFAULT NULL,
  `returns_5y`         decimal(6,2)     DEFAULT NULL,
  `returns_updated_at` date             DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  KEY `fk_fund_house`    (`fund_house_id`),
  KEY `idx_scheme_name`  (`scheme_name`(100)),
  KEY `idx_isin_growth`  (`isin_growth`),
  KEY `idx_category`     (`category`),
  KEY `idx_option_type`  (`option_type`),
  KEY `idx_ltcg_days`    (`min_ltcg_days`),
  KEY `idx_lock_in`      (`lock_in_days`),
  KEY `idx_screener`     (`is_active`,`option_type`,`min_ltcg_days`),
  KEY `idx_risk`         (`risk_level`),
  KEY `idx_returns_1y`   (`returns_1y`),
  KEY `idx_returns_3y`   (`returns_3y`),
  KEY `idx_returns_5y`   (`returns_5y`),
  KEY `idx_fund_manager` (`fund_manager`(50)),
  CONSTRAINT `fk_fund_house` FOREIGN KEY (`fund_house_id`) REFERENCES `fund_houses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── mf_holdings ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `mf_holdings`;
CREATE TABLE `mf_holdings` (
  `id`                  int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`        int(10) unsigned NOT NULL,
  `fund_id`             int(10) unsigned NOT NULL,
  `folio_number`        varchar(30)      DEFAULT NULL,
  `total_units`         decimal(14,4)    NOT NULL DEFAULT 0.0000,
  `avg_cost_nav`        decimal(12,4)    NOT NULL DEFAULT 0.0000,
  `total_invested`      decimal(14,2)    NOT NULL DEFAULT 0.00,
  `value_now`           decimal(14,2)    NOT NULL DEFAULT 0.00,
  `gain_loss`           decimal(14,2)    NOT NULL DEFAULT 0.00,
  `gain_pct`            decimal(8,4)     NOT NULL DEFAULT 0.0000,
  `cagr`                decimal(8,4)     DEFAULT NULL,
  `first_purchase_date` date             DEFAULT NULL,
  `ltcg_date`           date             DEFAULT NULL,
  `lock_in_date`        date             DEFAULT NULL,
  `withdrawable_date`   date             DEFAULT NULL,
  `investment_fy`       varchar(7)       DEFAULT NULL,
  `withdrawable_fy`     varchar(7)       DEFAULT NULL,
  `gain_type`           enum('LTCG','STCG','MIXED','NA') DEFAULT 'NA',
  `highest_nav`         decimal(12,4)    DEFAULT NULL,
  `highest_nav_date`    date             DEFAULT NULL,
  `is_active`           tinyint(1)       NOT NULL DEFAULT 1,
  `last_calculated`     datetime         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port_fund_folio` (`portfolio_id`,`fund_id`,`folio_number`),
  KEY `fk_mfh_fund` (`fund_id`),
  CONSTRAINT `fk_mfh_fund`      FOREIGN KEY (`fund_id`)      REFERENCES `funds`      (`id`),
  CONSTRAINT `fk_mfh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── mf_transactions ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `mf_transactions`;
CREATE TABLE `mf_transactions` (
  `id`                 int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`       int(10) unsigned NOT NULL,
  `fund_id`            int(10) unsigned NOT NULL,
  `folio_number`       varchar(30)      DEFAULT NULL,
  `transaction_type`   enum('BUY','SELL','DIV_REINVEST','DIV_PAYOUT','SWITCH_IN','SWITCH_OUT','STP_IN','STP_OUT','SWP') NOT NULL,
  `platform`           varchar(50)      DEFAULT NULL,
  `txn_date`           date             NOT NULL,
  `units`              decimal(14,4)    NOT NULL,
  `nav`                decimal(12,4)    NOT NULL,
  `value_at_cost`      decimal(14,2)    NOT NULL,
  `investment_fy`      varchar(7)       DEFAULT NULL,
  `stamp_duty`         decimal(10,2)    NOT NULL DEFAULT 0.00,
  `notes`              text             DEFAULT NULL,
  `import_source`      enum('manual','csv_wealthdash','csv_cams','csv_kfintech','csv_zerodha','csv_groww') DEFAULT 'manual',
  `import_fingerprint` varchar(32)      DEFAULT NULL,
  `created_at`         datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_mft_portfolio`  (`portfolio_id`),
  KEY `fk_mft_fund`       (`fund_id`),
  KEY `idx_txn_date`      (`txn_date`),
  KEY `idx_folio`         (`folio_number`),
  KEY `idx_import_fp`     (`import_fingerprint`),
  CONSTRAINT `fk_mft_fund`      FOREIGN KEY (`fund_id`)      REFERENCES `funds`      (`id`),
  CONSTRAINT `fk_mft_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── mf_dividends ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `mf_dividends`;
CREATE TABLE `mf_dividends` (
  `id`             int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`   int(10) unsigned NOT NULL,
  `fund_id`        int(10) unsigned NOT NULL,
  `folio_number`   varchar(30)      DEFAULT NULL,
  `div_date`       date             NOT NULL,
  `div_per_unit`   decimal(10,4)    NOT NULL DEFAULT 0.0000,
  `total_units`    decimal(14,4)    NOT NULL DEFAULT 0.0000,
  `total_amount`   decimal(14,2)    NOT NULL DEFAULT 0.00,
  `dividend_fy`    varchar(7)       DEFAULT NULL,
  `is_auto_fetched` tinyint(1)      NOT NULL DEFAULT 0,
  `created_at`     datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_mfd_portfolio` (`portfolio_id`),
  KEY `fk_mfd_fund`      (`fund_id`),
  CONSTRAINT `fk_mfd_fund`      FOREIGN KEY (`fund_id`)      REFERENCES `funds`      (`id`),
  CONSTRAINT `fk_mfd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── mf_peak_progress ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `mf_peak_progress`;
CREATE TABLE `mf_peak_progress` (
  `scheme_code`         varchar(20) NOT NULL,
  `last_processed_date` date        DEFAULT NULL,
  `status`              enum('pending','in_progress','completed','error') DEFAULT 'pending',
  `error_message`       text        DEFAULT NULL,
  `updated_at`          datetime    DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scheme_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── nav_history ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `nav_history`;
CREATE TABLE `nav_history` (
  `id`       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fund_id`  int(10) unsigned    NOT NULL,
  `nav_date` date                NOT NULL,
  `nav`      decimal(12,4)       NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_date`  (`fund_id`,`nav_date`),
  KEY        `idx_fund_date` (`fund_id`,`nav_date`),
  CONSTRAINT `fk_navh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── nav_download_progress ────────────────────────────────────────────────────
DROP TABLE IF EXISTS `nav_download_progress`;
CREATE TABLE `nav_download_progress` (
  `scheme_code`          varchar(20) NOT NULL,
  `fund_id`              int(11)     DEFAULT NULL,
  `status`               enum('pending','in_progress','completed','error') DEFAULT 'pending',
  `from_date`            date        DEFAULT NULL,
  `last_downloaded_date` date        DEFAULT NULL,
  `records_saved`        int(11)     DEFAULT 0,
  `error_message`        text        DEFAULT NULL,
  `updated_at`           datetime    DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scheme_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── nps_schemes ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `nps_schemes`;
CREATE TABLE `nps_schemes` (
  `id`              int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pfm_name`        varchar(100)     NOT NULL,
  `scheme_name`     varchar(200)     NOT NULL,
  `scheme_code`     varchar(30)      NOT NULL,
  `tier`            enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `asset_class`     enum('E','C','G','A') DEFAULT 'E',
  `latest_nav`      decimal(12,4)    DEFAULT NULL,
  `latest_nav_date` date             DEFAULT NULL,
  `is_active`       tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`      datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_code` (`scheme_code`),
  KEY `idx_pfm` (`pfm_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── nps_holdings ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `nps_holdings`;
CREATE TABLE `nps_holdings` (
  `id`                      int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`            int(10) unsigned NOT NULL,
  `scheme_id`               int(10) unsigned NOT NULL,
  `tier`                    enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `total_units`             decimal(14,4)    NOT NULL DEFAULT 0.0000,
  `total_invested`          decimal(14,2)    NOT NULL DEFAULT 0.00,
  `latest_value`            decimal(14,2)    NOT NULL DEFAULT 0.00,
  `gain_loss`               decimal(14,2)    NOT NULL DEFAULT 0.00,
  `gain_pct`                decimal(8,4)     DEFAULT NULL,
  `cagr`                    decimal(8,4)     DEFAULT NULL,
  `first_contribution_date` date             DEFAULT NULL,
  `last_calculated`         datetime         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port_scheme_tier` (`portfolio_id`,`scheme_id`,`tier`),
  KEY `fk_npsh_scheme` (`scheme_id`),
  CONSTRAINT `fk_npsh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_npsh_scheme`    FOREIGN KEY (`scheme_id`)    REFERENCES `nps_schemes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── nps_transactions ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `nps_transactions`;
CREATE TABLE `nps_transactions` (
  `id`                int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`      int(10) unsigned NOT NULL,
  `scheme_id`         int(10) unsigned NOT NULL,
  `tier`              enum('tier1','tier2') NOT NULL DEFAULT 'tier1',
  `contribution_type` enum('SELF','EMPLOYER') NOT NULL DEFAULT 'SELF',
  `txn_date`          date             NOT NULL,
  `units`             decimal(14,4)    NOT NULL,
  `nav`               decimal(12,4)    NOT NULL,
  `amount`            decimal(14,2)    NOT NULL,
  `investment_fy`     varchar(7)       DEFAULT NULL,
  `notes`             text             DEFAULT NULL,
  `created_at`        datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_npst_portfolio` (`portfolio_id`),
  KEY `fk_npst_scheme`    (`scheme_id`),
  CONSTRAINT `fk_npst_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_npst_scheme`    FOREIGN KEY (`scheme_id`)    REFERENCES `nps_schemes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── sip_schedules ────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `sip_schedules`;
CREATE TABLE `sip_schedules` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`  int(10) unsigned NOT NULL,
  `asset_type`    enum('mf','stock','nps') NOT NULL DEFAULT 'mf',
  `schedule_type` enum('SIP','SWP')        NOT NULL DEFAULT 'SIP',
  `fund_id`       int(10) unsigned DEFAULT NULL,
  `stock_id`      int(10) unsigned DEFAULT NULL,
  `nps_scheme_id` int(10) unsigned DEFAULT NULL,
  `folio_number`  varchar(50)      DEFAULT NULL,
  `sip_amount`    decimal(14,2)    NOT NULL,
  `frequency`     enum('daily','weekly','fortnightly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  `sip_day`       tinyint(3) unsigned NOT NULL DEFAULT 1,
  `start_date`    date             NOT NULL,
  `end_date`      date             DEFAULT NULL,
  `platform`      varchar(50)      DEFAULT NULL,
  `is_active`     tinyint(1)       NOT NULL DEFAULT 1,
  `notes`         varchar(255)     DEFAULT NULL,
  `created_at`    datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sip_portfolio` (`portfolio_id`),
  KEY `idx_sip_fund`      (`fund_id`),
  CONSTRAINT `fk_sip_fund`      FOREIGN KEY (`fund_id`)      REFERENCES `funds`      (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sip_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── stock_master ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `stock_master`;
CREATE TABLE `stock_master` (
  `id`                int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exchange`          enum('NSE','BSE') NOT NULL DEFAULT 'NSE',
  `symbol`            varchar(30)      NOT NULL,
  `company_name`      varchar(200)     NOT NULL,
  `isin`              varchar(15)      DEFAULT NULL,
  `sector`            varchar(100)     DEFAULT NULL,
  `latest_price`      decimal(12,2)    DEFAULT NULL,
  `latest_price_date` date             DEFAULT NULL,
  `is_active`         tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`        datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exchange_symbol` (`exchange`,`symbol`),
  KEY `idx_symbol` (`symbol`),
  KEY `idx_isin`   (`isin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── stock_holdings ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `stock_holdings`;
CREATE TABLE `stock_holdings` (
  `id`             int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`   int(10) unsigned NOT NULL,
  `stock_id`       int(10) unsigned NOT NULL,
  `quantity`       decimal(14,4)    NOT NULL DEFAULT 0.0000,
  `avg_buy_price`  decimal(12,2)    NOT NULL DEFAULT 0.00,
  `total_invested` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `current_value`  decimal(14,2)    NOT NULL DEFAULT 0.00,
  `gain_loss`      decimal(14,2)    NOT NULL DEFAULT 0.00,
  `gain_pct`       decimal(8,4)     DEFAULT NULL,
  `cagr`           decimal(8,4)     DEFAULT NULL,
  `first_buy_date` date             DEFAULT NULL,
  `ltcg_date`      date             DEFAULT NULL,
  `gain_type`      enum('LTCG','STCG','MIXED','NA') DEFAULT 'NA',
  `is_active`      tinyint(1)       NOT NULL DEFAULT 1,
  `last_calculated` datetime        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_port_stock` (`portfolio_id`,`stock_id`),
  KEY `fk_sh_stock` (`stock_id`),
  CONSTRAINT `fk_sh_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sh_stock`     FOREIGN KEY (`stock_id`)     REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── stock_transactions ───────────────────────────────────────────────────────
DROP TABLE IF EXISTS `stock_transactions`;
CREATE TABLE `stock_transactions` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`     int(10) unsigned NOT NULL,
  `stock_id`         int(10) unsigned NOT NULL,
  `txn_type`         enum('BUY','SELL','BONUS','SPLIT','DIV') NOT NULL,
  `txn_date`         date             NOT NULL,
  `quantity`         decimal(14,4)    NOT NULL,
  `price`            decimal(12,2)    NOT NULL DEFAULT 0.00,
  `brokerage`        decimal(10,2)    NOT NULL DEFAULT 0.00,
  `stt`              decimal(10,2)    NOT NULL DEFAULT 0.00,
  `exchange_charges` decimal(10,2)    NOT NULL DEFAULT 0.00,
  `value_at_cost`    decimal(14,2)    NOT NULL DEFAULT 0.00,
  `investment_fy`    varchar(7)       DEFAULT NULL,
  `notes`            text             DEFAULT NULL,
  `import_source`    enum('manual','csv_zerodha','csv_groww','csv_custom') DEFAULT 'manual',
  `created_at`       datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_st_portfolio` (`portfolio_id`),
  KEY `fk_st_stock`     (`stock_id`),
  CONSTRAINT `fk_st_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_stock`     FOREIGN KEY (`stock_id`)     REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── stock_dividends ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `stock_dividends`;
CREATE TABLE `stock_dividends` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`     int(10) unsigned NOT NULL,
  `stock_id`         int(10) unsigned NOT NULL,
  `div_date`         date             NOT NULL,
  `amount_per_share` decimal(10,4)    NOT NULL,
  `total_shares`     decimal(14,4)    NOT NULL,
  `total_amount`     decimal(14,2)    NOT NULL,
  `dividend_fy`      varchar(7)       DEFAULT NULL,
  `created_at`       datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sd_portfolio` (`portfolio_id`),
  KEY `fk_sd_stock`     (`stock_id`),
  CONSTRAINT `fk_sd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sd_stock`     FOREIGN KEY (`stock_id`)     REFERENCES `stock_master` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── stock_corporate_actions ──────────────────────────────────────────────────
DROP TABLE IF EXISTS `stock_corporate_actions`;
CREATE TABLE `stock_corporate_actions` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stock_id`    int(10) unsigned NOT NULL,
  `action_type` enum('SPLIT','BONUS','RIGHTS') NOT NULL,
  `action_date` date             NOT NULL,
  `ratio`       varchar(20)      NOT NULL,
  `notes`       text             DEFAULT NULL,
  `processed`   tinyint(1)       NOT NULL DEFAULT 0,
  `created_at`  datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sca_stock` (`stock_id`),
  CONSTRAINT `fk_sca_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── fd_accounts ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `fd_accounts`;
CREATE TABLE `fd_accounts` (
  `id`                  int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`        int(10) unsigned NOT NULL,
  `bank_name`           varchar(100)     NOT NULL,
  `fd_number`           varchar(50)      DEFAULT NULL,
  `principal`           decimal(14,2)    NOT NULL,
  `interest_rate`       decimal(6,3)     NOT NULL,
  `is_senior_rate`      tinyint(1)       NOT NULL DEFAULT 0,
  `compounding_type`    enum('monthly','quarterly','half_yearly','yearly','cumulative') NOT NULL DEFAULT 'cumulative',
  `open_date`           date             NOT NULL,
  `maturity_date`       date             NOT NULL,
  `maturity_amount`     decimal(14,2)    DEFAULT NULL,
  `tds_applicable`      tinyint(1)       NOT NULL DEFAULT 1,
  `form_15g_submitted`  tinyint(1)       NOT NULL DEFAULT 0,
  `auto_renewal`        tinyint(1)       NOT NULL DEFAULT 0,
  `status`              enum('active','matured','broken','renewed') NOT NULL DEFAULT 'active',
  `investment_fy`       varchar(7)       DEFAULT NULL,
  `notes`               text             DEFAULT NULL,
  `created_at`          datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`          datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_fd_portfolio`   (`portfolio_id`),
  KEY `idx_maturity_date` (`maturity_date`),
  CONSTRAINT `fk_fd_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── fd_interest_accruals ─────────────────────────────────────────────────────
DROP TABLE IF EXISTS `fd_interest_accruals`;
CREATE TABLE `fd_interest_accruals` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fd_id`            int(10) unsigned NOT NULL,
  `fy`               varchar(7)       NOT NULL,
  `accrued_interest` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `tds_deducted`     decimal(14,2)    NOT NULL DEFAULT 0.00,
  `net_interest`     decimal(14,2)    NOT NULL DEFAULT 0.00,
  `calculated_at`    datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fd_fy` (`fd_id`,`fy`),
  CONSTRAINT `fk_fdia_fd` FOREIGN KEY (`fd_id`) REFERENCES `fd_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── bank_accounts ────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `bank_accounts`;
CREATE TABLE `bank_accounts` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`  int(10) unsigned NOT NULL,
  `bank_name`     varchar(100)     NOT NULL,
  `account_type`  enum('savings','current','salary','nre','nro','other') NOT NULL DEFAULT 'savings',
  `account_number` varchar(30)     DEFAULT NULL,
  `ifsc_code`     varchar(15)      DEFAULT NULL,
  `balance`       decimal(14,2)    NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(5,3)     NOT NULL DEFAULT 4.000,
  `is_primary`    tinyint(1)       NOT NULL DEFAULT 0,
  `notes`         text             DEFAULT NULL,
  `updated_at`    datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at`    datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_bank_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_bank_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── bank_balance_history ─────────────────────────────────────────────────────
DROP TABLE IF EXISTS `bank_balance_history`;
CREATE TABLE `bank_balance_history` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id`  int(10) unsigned NOT NULL,
  `balance`     decimal(14,2)    NOT NULL,
  `recorded_at` date             NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_bbh_account` (`account_id`),
  CONSTRAINT `fk_bbh_account` FOREIGN KEY (`account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── savings_accounts ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `savings_accounts`;
CREATE TABLE `savings_accounts` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`  int(10) unsigned NOT NULL,
  `bank_name`     varchar(100)     NOT NULL,
  `account_type`  enum('savings','current','salary') NOT NULL DEFAULT 'savings',
  `account_no`    varchar(20)      DEFAULT NULL,
  `interest_rate` decimal(5,3)     NOT NULL DEFAULT 3.500,
  `balance`       decimal(14,2)    NOT NULL DEFAULT 0.00,
  `balance_date`  date             DEFAULT NULL,
  `is_active`     tinyint(1)       NOT NULL DEFAULT 1,
  `notes`         text             DEFAULT NULL,
  `created_at`    datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sa_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_sa_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── savings_interest_log ─────────────────────────────────────────────────────
DROP TABLE IF EXISTS `savings_interest_log`;
CREATE TABLE `savings_interest_log` (
  `id`                 int(10) unsigned NOT NULL AUTO_INCREMENT,
  `savings_account_id` int(10) unsigned NOT NULL,
  `month`              varchar(7)       NOT NULL,
  `interest_earned`    decimal(10,2)    NOT NULL DEFAULT 0.00,
  `created_at`         datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_month` (`savings_account_id`,`month`),
  CONSTRAINT `fk_sil_sa` FOREIGN KEY (`savings_account_id`) REFERENCES `savings_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── po_schemes ───────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `po_schemes`;
CREATE TABLE `po_schemes` (
  `id`              int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`    int(10) unsigned NOT NULL,
  `scheme_type`     enum('savings_account','rd','td','mis','scss','ppf','ssy','nsc','kvp') NOT NULL,
  `account_number`  varchar(50)      DEFAULT NULL,
  `holder_name`     varchar(100)     NOT NULL,
  `principal`       decimal(14,2)    NOT NULL DEFAULT 0.00,
  `interest_rate`   decimal(6,3)     NOT NULL,
  `open_date`       date             NOT NULL,
  `maturity_date`   date             DEFAULT NULL,
  `maturity_amount` decimal(14,2)    DEFAULT NULL,
  `current_value`   decimal(14,2)    DEFAULT NULL,
  `deposit_amount`  decimal(14,2)    DEFAULT NULL,
  `interest_freq`   enum('monthly','quarterly','half_yearly','yearly','cumulative','on_maturity') NOT NULL DEFAULT 'yearly',
  `compounding`     enum('simple','compound') NOT NULL DEFAULT 'compound',
  `status`          enum('active','matured','closed','partial_withdrawn') NOT NULL DEFAULT 'active',
  `is_joint`        tinyint(1)       NOT NULL DEFAULT 0,
  `nominee`         varchar(100)     DEFAULT NULL,
  `post_office`     varchar(150)     DEFAULT NULL,
  `notes`           text             DEFAULT NULL,
  `investment_fy`   varchar(7)       DEFAULT NULL,
  `created_at`      datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_po_portfolio` (`portfolio_id`),
  KEY `idx_po_type`      (`scheme_type`),
  KEY `idx_po_status`    (`status`),
  CONSTRAINT `fk_po_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── epf_accounts ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `epf_accounts`;
CREATE TABLE `epf_accounts` (
  `id`                   int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`         int(10) unsigned NOT NULL,
  `uan`                  varchar(20)      DEFAULT NULL,
  `employer_name`        varchar(100)     NOT NULL,
  `employee_contribution` decimal(10,2)   NOT NULL DEFAULT 0.00,
  `employer_contribution` decimal(10,2)   NOT NULL DEFAULT 0.00,
  `eps_contribution`     decimal(10,2)    NOT NULL DEFAULT 0.00,
  `basic_salary`         decimal(10,2)    NOT NULL DEFAULT 0.00,
  `joining_date`         date             DEFAULT NULL,
  `current_balance`      decimal(14,2)    NOT NULL DEFAULT 0.00,
  `eps_balance`          decimal(14,2)    NOT NULL DEFAULT 0.00,
  `interest_rate`        decimal(5,3)     NOT NULL DEFAULT 8.150,
  `is_active`            tinyint(1)       NOT NULL DEFAULT 1,
  `notes`                text             DEFAULT NULL,
  `created_at`           datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`           datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_epf_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_epf_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── loan_accounts ────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `loan_accounts`;
CREATE TABLE `loan_accounts` (
  `id`             int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`   int(10) unsigned NOT NULL,
  `loan_type`      enum('home','personal','education','vehicle','business','gold','other') NOT NULL,
  `lender`         varchar(100)     NOT NULL,
  `loan_number`    varchar(50)      DEFAULT NULL,
  `principal`      decimal(14,2)    NOT NULL,
  `outstanding`    decimal(14,2)    NOT NULL,
  `interest_rate`  decimal(6,3)     NOT NULL,
  `emi_amount`     decimal(10,2)    NOT NULL,
  `emi_date`       tinyint(2)       NOT NULL DEFAULT 5,
  `start_date`     date             NOT NULL,
  `tenure_months`  int(11)          NOT NULL,
  `is_active`      tinyint(1)       NOT NULL DEFAULT 1,
  `notes`          text             DEFAULT NULL,
  `created_at`     datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_loan_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_loan_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── insurance_policies ───────────────────────────────────────────────────────
DROP TABLE IF EXISTS `insurance_policies`;
CREATE TABLE `insurance_policies` (
  `id`                int(10) unsigned NOT NULL AUTO_INCREMENT,
  `portfolio_id`      int(10) unsigned NOT NULL,
  `policy_type`       enum('term','health','ulip','endowment','vehicle','travel','other') NOT NULL DEFAULT 'term',
  `insurer`           varchar(100)     NOT NULL,
  `policy_number`     varchar(50)      DEFAULT NULL,
  `sum_assured`       decimal(14,2)    NOT NULL DEFAULT 0.00,
  `annual_premium`    decimal(10,2)    NOT NULL DEFAULT 0.00,
  `premium_frequency` enum('monthly','quarterly','half_yearly','yearly','single') NOT NULL DEFAULT 'yearly',
  `next_premium_date` date             DEFAULT NULL,
  `start_date`        date             NOT NULL,
  `maturity_date`     date             DEFAULT NULL,
  `is_active`         tinyint(1)       NOT NULL DEFAULT 1,
  `nominee_name`      varchar(100)     DEFAULT NULL,
  `notes`             text             DEFAULT NULL,
  `created_at`        datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_ins_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_ins_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── goal_buckets ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `goal_buckets`;
CREATE TABLE `goal_buckets` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`       int(10) unsigned NOT NULL,
  `name`          varchar(100)     NOT NULL,
  `emoji`         varchar(10)      DEFAULT '?',
  `color`         varchar(20)      DEFAULT '#6366f1',
  `target_amount` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `target_date`   date             DEFAULT NULL,
  `notes`         text             DEFAULT NULL,
  `created_at`    datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_goal_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── goal_fund_links ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `goal_fund_links`;
CREATE TABLE `goal_fund_links` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `goal_id`    int(10) unsigned NOT NULL,
  `fund_id`    int(10) unsigned DEFAULT NULL,
  `sip_id`     int(10) unsigned DEFAULT NULL,
  `link_type`  enum('holding','sip') NOT NULL DEFAULT 'holding',
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_gfl_goal` (`goal_id`),
  CONSTRAINT `fk_gfl_goal` FOREIGN KEY (`goal_id`) REFERENCES `goal_buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── import_logs ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `import_logs`;
CREATE TABLE `import_logs` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`          int(10) unsigned NOT NULL,
  `portfolio_id`     int(10) unsigned DEFAULT NULL,
  `source`           varchar(50)      NOT NULL DEFAULT 'CAS',
  `filename`         varchar(255)     DEFAULT NULL,
  `format_detected`  varchar(30)      DEFAULT NULL,
  `imported_count`   int(11)          NOT NULL DEFAULT 0,
  `failed_count`     int(11)          NOT NULL DEFAULT 0,
  `duplicate_count`  int(11)          NOT NULL DEFAULT 0,
  `imported_at`      datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_il_user`        (`user_id`),
  KEY `idx_il_imported_at` (`imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── sessions ─────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int(10) unsigned NOT NULL,
  `token`      varchar(128)     NOT NULL,
  `ip`         varchar(45)      DEFAULT NULL,
  `user_agent` text             DEFAULT NULL,
  `expires_at` datetime         NOT NULL,
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token`   (`token`),
  KEY         `fk_sess_user` (`user_id`),
  KEY         `idx_expires`  (`expires_at`),
  CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── login_attempts ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id`           int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address`   varchar(45)      NOT NULL,
  `email`        varchar(150)     DEFAULT NULL,
  `attempted_at` datetime         NOT NULL DEFAULT current_timestamp(),
  `success`      tinyint(1)       NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time`    (`ip_address`,`attempted_at`),
  KEY `idx_email_time` (`email`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── password_resets ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email`      varchar(150)     NOT NULL,
  `token_hash` varchar(255)     NOT NULL,
  `expires_at` datetime         NOT NULL,
  `used`       tinyint(1)       NOT NULL DEFAULT 0,
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email`   (`email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── otp_tokens ───────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `otp_tokens`;
CREATE TABLE `otp_tokens` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int(10) unsigned NOT NULL,
  `mobile`     varchar(15)      NOT NULL,
  `otp_hash`   varchar(255)     NOT NULL,
  `purpose`    enum('login','register','password_reset') NOT NULL DEFAULT 'login',
  `attempts`   tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime         NOT NULL,
  `used`       tinyint(1)       NOT NULL DEFAULT 0,
  `created_at` datetime         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_otp_user`     (`user_id`),
  KEY `idx_mobile_exp`  (`mobile`,`expires_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── google_auth ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `google_auth`;
CREATE TABLE `google_auth` (
  `id`           int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) unsigned NOT NULL,
  `google_id`    varchar(100)     NOT NULL,
  `email`        varchar(150)     NOT NULL,
  `access_token` text             DEFAULT NULL,
  `token_expiry` datetime         DEFAULT NULL,
  `created_at`   datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`   datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_google_id`  (`google_id`),
  KEY `fk_gauth_user` (`user_id`),
  CONSTRAINT `fk_gauth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════════
--  STEP 3: SEED DATA
-- ════════════════════════════════════════════════════════════════════════════

-- ── app_settings seed ────────────────────────────────────────────────────────
INSERT INTO `app_settings` (`setting_key`, `setting_val`) VALUES
('app_name',                  'WealthDash'),
('tax_year_start_month',      '4'),
('ltcg_exemption_limit',      '125000'),
('equity_ltcg_rate',          '12.5'),
('equity_stcg_rate',          '20'),
('debt_ltcg_rate',            '20'),
('fd_tds_rate',               '10'),
('fd_tds_senior_rate',        '10'),
('fd_tds_threshold',          '40000'),
('fd_tds_threshold_senior',   '50000'),
('savings_80tta_limit',       '10000'),
('savings_80ttb_limit',       '50000'),
('amfi_url',                  'https://www.amfiindia.com/spages/NAVAll.txt'),
('nav_last_updated',          NULL),
('registration_open',         '1'),
-- Peak NAV tracker keys
('peak_nav_batch_date',       '2001-01-01'),
('peak_nav_stop',             '0'),
('peak_nav_status',           'idle')
ON DUPLICATE KEY UPDATE setting_val = setting_val;

-- ── fund_houses seed ─────────────────────────────────────────────────────────
INSERT INTO `fund_houses` (`name`, `short_name`) VALUES
('Aditya Birla Sun Life Mutual Fund', 'ABSL MF'),
('Axis Mutual Fund',                  'Axis MF'),
('Bandhan Mutual Fund',               'Bandhan MF'),
('Baroda BNP Paribas Mutual Fund',    'Baroda BNP MF'),
('Canara Robeco Mutual Fund',         'Canara Robeco MF'),
('DSP Mutual Fund',                   'DSP MF'),
('Edelweiss Mutual Fund',             'Edelweiss MF'),
('Franklin Templeton Mutual Fund',    'Franklin MF'),
('HDFC Mutual Fund',                  'HDFC MF'),
('HSBC Mutual Fund',                  'HSBC MF'),
('ICICI Prudential Mutual Fund',      'ICICI Pru MF'),
('IDFC Mutual Fund',                  'IDFC MF'),
('IL&FS Mutual Fund',                 'ILFS MF'),
('Invesco Mutual Fund',               'Invesco MF'),
('ITI Mutual Fund',                   'ITI MF'),
('Kotak Mahindra Mutual Fund',        'Kotak MF'),
('L&T Mutual Fund',                   'L&T MF'),
('LIC Mutual Fund',                   'LIC MF'),
('Mahindra Manulife Mutual Fund',     'Mahindra MF'),
('Mirae Asset Mutual Fund',           'Mirae MF'),
('Motilal Oswal Mutual Fund',         'Motilal MF'),
('Navi Mutual Fund',                  'Navi MF'),
('Nippon India Mutual Fund',          'Nippon MF'),
('PGIM India Mutual Fund',            'PGIM MF'),
('PPFAS Mutual Fund',                 'PPFAS MF'),
('Quantum Mutual Fund',               'Quantum MF'),
('Quant Mutual Fund',                 'Quant MF'),
('SBI Mutual Fund',                   'SBI MF'),
('Shriram Mutual Fund',               'Shriram MF'),
('Sundaram Mutual Fund',              'Sundaram MF'),
('Tata Mutual Fund',                  'Tata MF'),
('Taurus Mutual Fund',                'Taurus MF'),
('Union Mutual Fund',                 'Union MF'),
('UTI Mutual Fund',                   'UTI MF'),
('WhiteOak Capital Mutual Fund',      'WhiteOak MF'),
('Zerodha Mutual Fund',               'Zerodha MF');

-- ── nps_schemes seed ─────────────────────────────────────────────────────────
INSERT INTO `nps_schemes` (`pfm_name`, `scheme_name`, `scheme_code`, `tier`, `asset_class`) VALUES
('SBI Pension Funds',                  'SBI Pension Fund - Scheme E - Tier I',        'SBI_E_T1',   'tier1', 'E'),
('SBI Pension Funds',                  'SBI Pension Fund - Scheme C - Tier I',        'SBI_C_T1',   'tier1', 'C'),
('SBI Pension Funds',                  'SBI Pension Fund - Scheme G - Tier I',        'SBI_G_T1',   'tier1', 'G'),
('SBI Pension Funds',                  'SBI Pension Fund - Scheme A - Tier I',        'SBI_A_T1',   'tier1', 'A'),
('SBI Pension Funds',                  'SBI Pension Fund - Scheme E - Tier II',       'SBI_E_T2',   'tier2', 'E'),
('SBI Pension Funds',                  'SBI Pension Fund - Scheme C - Tier II',       'SBI_C_T2',   'tier2', 'C'),
('SBI Pension Funds',                  'SBI Pension Fund - Scheme G - Tier II',       'SBI_G_T2',   'tier2', 'G'),
('LIC Pension Fund',                   'LIC Pension Fund - Scheme E - Tier I',        'LIC_E_T1',   'tier1', 'E'),
('LIC Pension Fund',                   'LIC Pension Fund - Scheme C - Tier I',        'LIC_C_T1',   'tier1', 'C'),
('LIC Pension Fund',                   'LIC Pension Fund - Scheme G - Tier I',        'LIC_G_T1',   'tier1', 'G'),
('LIC Pension Fund',                   'LIC Pension Fund - Scheme E - Tier II',       'LIC_E_T2',   'tier2', 'E'),
('UTI Retirement Solutions',           'UTI Retirement Solutions - Scheme E - Tier I','UTI_E_T1',   'tier1', 'E'),
('UTI Retirement Solutions',           'UTI Retirement Solutions - Scheme C - Tier I','UTI_C_T1',   'tier1', 'C'),
('UTI Retirement Solutions',           'UTI Retirement Solutions - Scheme G - Tier I','UTI_G_T1',   'tier1', 'G'),
('UTI Retirement Solutions',           'UTI Retirement Solutions - Scheme E - Tier II','UTI_E_T2',  'tier2', 'E'),
('HDFC Pension Fund',                  'HDFC Pension Fund - Scheme E - Tier I',       'HDFC_E_T1',  'tier1', 'E'),
('HDFC Pension Fund',                  'HDFC Pension Fund - Scheme C - Tier I',       'HDFC_C_T1',  'tier1', 'C'),
('HDFC Pension Fund',                  'HDFC Pension Fund - Scheme G - Tier I',       'HDFC_G_T1',  'tier1', 'G'),
('ICICI Prudential Pension Fund',      'ICICI Pru Pension Fund - Scheme E - Tier I',  'ICICI_E_T1', 'tier1', 'E'),
('ICICI Prudential Pension Fund',      'ICICI Pru Pension Fund - Scheme C - Tier I',  'ICICI_C_T1', 'tier1', 'C'),
('ICICI Prudential Pension Fund',      'ICICI Pru Pension Fund - Scheme G - Tier I',  'ICICI_G_T1', 'tier1', 'G'),
('Kotak Pension Fund',                 'Kotak Pension Fund - Scheme E - Tier I',      'KOTAK_E_T1', 'tier1', 'E'),
('Kotak Pension Fund',                 'Kotak Pension Fund - Scheme C - Tier I',      'KOTAK_C_T1', 'tier1', 'C'),
('Kotak Pension Fund',                 'Kotak Pension Fund - Scheme G - Tier I',      'KOTAK_G_T1', 'tier1', 'G'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme E - Tier I',       'ABSL_E_T1',  'tier1', 'E'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme C - Tier I',       'ABSL_C_T1',  'tier1', 'C'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme G - Tier I',       'ABSL_G_T1',  'tier1', 'G'),
('Axis Pension Fund',                  'Axis Pension Fund - Scheme E - Tier I',       'AXIS_E_T1',  'tier1', 'E'),
('Axis Pension Fund',                  'Axis Pension Fund - Scheme C - Tier I',       'AXIS_C_T1',  'tier1', 'C'),
('Axis Pension Fund',                  'Axis Pension Fund - Scheme G - Tier I',       'AXIS_G_T1',  'tier1', 'G'),
('DSP Pension Fund',                   'DSP Pension Fund - Scheme E - Tier I',        'DSP_E_T1',   'tier1', 'E'),
('DSP Pension Fund',                   'DSP Pension Fund - Scheme C - Tier I',        'DSP_C_T1',   'tier1', 'C'),
('DSP Pension Fund',                   'DSP Pension Fund - Scheme G - Tier I',        'DSP_G_T1',   'tier1', 'G'),
('Tata Pension Fund',                  'Tata Pension Fund - Scheme E - Tier I',       'TATA_E_T1',  'tier1', 'E'),
('Tata Pension Fund',                  'Tata Pension Fund - Scheme C - Tier I',       'TATA_C_T1',  'tier1', 'C'),
('Tata Pension Fund',                  'Tata Pension Fund - Scheme G - Tier I',       'TATA_G_T1',  'tier1', 'G');

-- ── stock_master seed ────────────────────────────────────────────────────────
INSERT INTO `stock_master` (`exchange`, `symbol`, `company_name`, `isin`, `sector`) VALUES
('NSE', 'RELIANCE',   'Reliance Industries Ltd',             'INE002A01018', 'Energy'),
('NSE', 'TCS',        'Tata Consultancy Services Ltd',       'INE467B01029', 'IT'),
('NSE', 'HDFCBANK',   'HDFC Bank Ltd',                       'INE040A01034', 'Banking'),
('NSE', 'INFY',       'Infosys Ltd',                         'INE009A01021', 'IT'),
('NSE', 'ICICIBANK',  'ICICI Bank Ltd',                      'INE090A01021', 'Banking'),
('NSE', 'HINDUNILVR', 'Hindustan Unilever Ltd',              'INE030A01027', 'FMCG'),
('NSE', 'SBIN',       'State Bank of India',                 'INE062A01020', 'Banking'),
('NSE', 'BHARTIARTL', 'Bharti Airtel Ltd',                   'INE397D01024', 'Telecom'),
('NSE', 'ITC',        'ITC Ltd',                             'INE154A01025', 'FMCG'),
('NSE', 'KOTAKBANK',  'Kotak Mahindra Bank Ltd',             'INE237A01028', 'Banking'),
('NSE', 'LT',         'Larsen & Toubro Ltd',                 'INE018A01030', 'Infrastructure'),
('NSE', 'AXISBANK',   'Axis Bank Ltd',                       'INE238A01034', 'Banking'),
('NSE', 'WIPRO',      'Wipro Ltd',                           'INE075A01022', 'IT'),
('NSE', 'BAJFINANCE', 'Bajaj Finance Ltd',                   'INE296A01024', 'NBFC'),
('NSE', 'SUNPHARMA',  'Sun Pharmaceutical Industries',       'INE044A01036', 'Pharma'),
('NSE', 'TITAN',      'Titan Company Ltd',                   'INE280A01028', 'Consumer'),
('NSE', 'HCLTECH',    'HCL Technologies Ltd',                'INE860A01027', 'IT'),
('NSE', 'ASIANPAINT', 'Asian Paints Ltd',                    'INE021A01026', 'Consumer'),
('NSE', 'MARUTI',     'Maruti Suzuki India Ltd',             'INE585B01010', 'Auto'),
('NSE', 'NESTLEIND',  'Nestle India Ltd',                    'INE239A01016', 'FMCG'),
('NSE', 'ULTRACEMCO', 'UltraTech Cement Ltd',                'INE481G01011', 'Cement'),
('NSE', 'POWERGRID',  'Power Grid Corporation of India',     'INE752E01010', 'Utilities'),
('NSE', 'NTPC',       'NTPC Ltd',                            'INE733E01010', 'Utilities'),
('NSE', 'ONGC',       'Oil and Natural Gas Corporation',     'INE213A01029', 'Energy'),
('NSE', 'BAJAJFINSV', 'Bajaj Finserv Ltd',                   'INE918I01026', 'Financial Services'),
('BSE', 'RELIANCE',   'Reliance Industries Ltd',             'INE002A01018', 'Energy'),
('BSE', 'TCS',        'Tata Consultancy Services Ltd',       'INE467B01029', 'IT'),
('BSE', 'HDFCBANK',   'HDFC Bank Ltd',                       'INE040A01034', 'Banking'),
('BSE', 'INFY',       'Infosys Ltd',                         'INE009A01021', 'IT');

-- ════════════════════════════════════════════════════════════════════════════
--  STEP 4: PEAK NAV TRACKER — mf_peak_progress seed
--  (funds table abhi empty hai — NAV update ke baad yeh auto-populate hoga)
--  Agar NAV already load hai to yeh INSERT seedha funds se le leta hai
-- ════════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `mf_peak_progress` (`scheme_code`, `status`)
SELECT `scheme_code`, 'pending'
FROM `funds`;

-- ════════════════════════════════════════════════════════════════════════════
--  STEP 5: FINAL CHECKS
-- ════════════════════════════════════════════════════════════════════════════
SET FOREIGN_KEY_CHECKS = 1;
COMMIT;

-- Confirm result
SELECT 'TABLES CREATED' AS step, COUNT(*) AS count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'wealthdash' AND TABLE_TYPE = 'BASE TABLE'

UNION ALL SELECT 'fund_houses',  COUNT(*) FROM fund_houses
UNION ALL SELECT 'nps_schemes',  COUNT(*) FROM nps_schemes
UNION ALL SELECT 'stock_master', COUNT(*) FROM stock_master
UNION ALL SELECT 'app_settings', COUNT(*) FROM app_settings
UNION ALL SELECT 'mf_peak_progress (after NAV load hogi)', COUNT(*) FROM mf_peak_progress;

-- ════════════════════════════════════════════════════════════════════════════
--  NEXT STEPS (import ke baad):
--  1. localhost/wealthdash/api/nav/update_amfi.php   ← NAV load karo (~14k funds)
--  2. localhost/wealthdash/peak_nav/setup.sql run karo (sirf agar NAV pehle load ki)
--     Ya phpMyAdmin mein run karo:
--     INSERT IGNORE INTO mf_peak_progress (scheme_code, status)
--     SELECT scheme_code, 'pending' FROM funds;
--  3. localhost/wealthdash/peak_nav/status.php       ← Dashboard ready!
-- ════════════════════════════════════════════════════════════════════════════
