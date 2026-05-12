-- ============================================================
-- WealthDash — Migration t115: REITs & InvITs
-- Task: t115 — Real Estate + Infrastructure Investment Trusts
-- ============================================================

-- ── Master trust registry ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reit_invit_master` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol`        VARCHAR(30)  NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `type`          ENUM('REIT','InvIT') NOT NULL,
  `exchange`      ENUM('NSE','BSE','BOTH') NOT NULL DEFAULT 'NSE',
  `isin`          VARCHAR(12)  NULL,
  `sponsor`       VARCHAR(150) NULL,
  `asset_focus`   VARCHAR(100) NULL COMMENT 'e.g. Commercial Office, Highways, Power',
  `distribution_freq` ENUM('quarterly','semi_annual','annual','monthly','irregular') DEFAULT 'quarterly',
  `lot_size`      INT UNSIGNED NOT NULL DEFAULT 1,
  `face_value`    DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  `listed_date`   DATE NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reit_symbol` (`symbol`),
  INDEX `idx_reit_type` (`type`),
  INDEX `idx_reit_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='REITs and InvITs master registry';

-- ── User holdings ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reit_invit_holdings` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `trust_id`        INT UNSIGNED NOT NULL,
  `units`           DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  `avg_cost`        DECIMAL(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'Average cost per unit',
  `total_invested`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `broker`          VARCHAR(80)   NULL,
  `demat_account`   VARCHAR(50)   NULL,
  `notes`           TEXT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reit_user_trust` (`user_id`, `trust_id`),
  INDEX `idx_reit_holding_user` (`user_id`),
  CONSTRAINT `fk_reit_trust` FOREIGN KEY (`trust_id`) REFERENCES `reit_invit_master`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User REIT/InvIT unit holdings';

-- ── Transactions ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reit_invit_transactions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `trust_id`      INT UNSIGNED NOT NULL,
  `txn_type`      ENUM('buy','sell','bonus','rights') NOT NULL DEFAULT 'buy',
  `txn_date`      DATE NOT NULL,
  `units`         DECIMAL(14,4) NOT NULL,
  `price_per_unit` DECIMAL(12,4) NOT NULL,
  `brokerage`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stt`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount`  DECIMAL(14,2) NOT NULL,
  `notes`         VARCHAR(255) NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_reit_txn_user` (`user_id`),
  INDEX `idx_reit_txn_trust` (`trust_id`),
  INDEX `idx_reit_txn_date` (`txn_date`),
  CONSTRAINT `fk_reit_txn_trust` FOREIGN KEY (`trust_id`) REFERENCES `reit_invit_master`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='REIT/InvIT buy/sell transactions';

-- ── Distribution (dividend/interest) log ─────────────────────
CREATE TABLE IF NOT EXISTS `reit_invit_distributions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `trust_id`        INT UNSIGNED NOT NULL,
  `distribution_date` DATE NOT NULL,
  `dist_type`       ENUM('interest','dividend','return_of_capital','special') NOT NULL DEFAULT 'interest',
  `amount_per_unit` DECIMAL(10,4) NOT NULL,
  `units_held`      DECIMAL(14,4) NOT NULL,
  `total_received`  DECIMAL(14,2) NOT NULL,
  `tds_deducted`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `net_received`    DECIMAL(14,2) NOT NULL,
  `is_reinvested`   TINYINT(1) NOT NULL DEFAULT 0,
  `notes`           VARCHAR(255) NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_reit_dist_user` (`user_id`),
  INDEX `idx_reit_dist_trust_date` (`trust_id`, `distribution_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='REIT/InvIT distribution receipts';

-- ── Live price cache ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reit_invit_prices` (
  `trust_id`      INT UNSIGNED NOT NULL,
  `price`         DECIMAL(12,4) NOT NULL,
  `price_date`    DATE NOT NULL,
  `nav`           DECIMAL(12,4) NULL COMMENT 'NAV from filing if available',
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`trust_id`),
  CONSTRAINT `fk_reit_price_trust` FOREIGN KEY (`trust_id`) REFERENCES `reit_invit_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Latest market price cache for REIT/InvIT';

-- ── Seed popular Indian REITs & InvITs ───────────────────────
INSERT IGNORE INTO `reit_invit_master`
  (`symbol`, `name`, `type`, `exchange`, `isin`, `sponsor`, `asset_focus`, `distribution_freq`, `lot_size`, `face_value`, `listed_date`)
VALUES
  ('EMBASSY',    'Embassy Office Parks REIT',         'REIT',  'BOTH', 'INE041025010', 'Embassy Group / Blackstone', 'Commercial Office',     'quarterly',   1, 300.00, '2019-04-01'),
  ('MINDSPACE',  'Mindspace Business Parks REIT',     'REIT',  'BOTH', 'INE216T25015', 'K Raheja Corp / Blackstone', 'Commercial Office',     'quarterly',   1, 275.00, '2020-08-07'),
  ('BROOKFIELD', 'Brookfield India Real Estate Trust','REIT',  'BOTH', 'INE461V25013', 'Brookfield Asset Management', 'Commercial Office',    'quarterly',   1, 275.00, '2021-02-17'),
  ('NEXUS',      'Nexus Select Trust',                'REIT',  'BOTH', 'INE801P25012', 'Blackstone',                 'Retail Malls',          'quarterly',   1, 100.00, '2023-05-19'),
  ('INFRAINVIT', 'India Grid Trust (IndiGrid)',       'InvIT', 'BOTH', 'INE219X25010', 'Sterlite Power',             'Power Transmission',    'quarterly',   1, 100.00, '2017-06-22'),
  ('POWERGRID',  'PowerGrid Infrastructure InvIT',   'InvIT', 'BOTH', 'INE269V25013', 'Power Grid Corporation',     'Power Transmission',    'semi_annual', 1, 100.00, '2021-05-03'),
  ('NHAI',       'NHAI InvIT',                       'InvIT', 'NSE', 'INE0AK025010',  'NHAI',                       'Highways',              'semi_annual', 1, 102.00, '2022-03-11'),
  ('IRB',        'IRB Infrastructure Trust',         'InvIT', 'BOTH', 'INE000Z25029', 'IRB Infrastructure',         'Highways & Toll Roads', 'quarterly',   1, 102.00, '2017-02-06');

SELECT 'REITs & InvITs migration t115 complete ✅' AS status;
SHOW TABLES LIKE 'reit_invit%';
