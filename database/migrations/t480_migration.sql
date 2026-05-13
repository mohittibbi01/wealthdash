-- ============================================================
-- WealthDash Migration: t480 — Data Validation Rules
-- ============================================================

CREATE TABLE IF NOT EXISTS `validation_rules` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_key` varchar(100) NOT NULL COMMENT 'e.g. mf_units_min, stock_price_max',
  `asset_type` enum('mf','stocks','nps','fd','savings','gold','realestate','crypto','all') NOT NULL DEFAULT 'all',
  `field_name` varchar(100) NOT NULL,
  `rule_type` enum('min','max','required','regex','enum','date_past','date_future','positive','nonzero') NOT NULL,
  `rule_value` varchar(500) DEFAULT NULL COMMENT 'numeric threshold or regex pattern or enum CSV',
  `error_msg` varchar(500) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule_key` (`rule_key`),
  KEY `idx_vr_asset` (`asset_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `validation_violations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `asset_type` varchar(50) NOT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `rule_key` varchar(100) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `bad_value` varchar(500) DEFAULT NULL,
  `error_msg` varchar(500) NOT NULL,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vv_portfolio` (`portfolio_id`),
  KEY `idx_vv_asset` (`asset_type`),
  KEY `idx_vv_resolved` (`is_resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed default validation rules ──────────────────────────────
INSERT IGNORE INTO `validation_rules`
  (`rule_key`, `asset_type`, `field_name`, `rule_type`, `rule_value`, `error_msg`) VALUES
-- MF
('mf_units_positive',    'mf',     'units',    'positive',    NULL,       'Units must be greater than 0'),
('mf_units_max',         'mf',     'units',    'max',         '9999999',  'Units seem unrealistically high (>9999999)'),
('mf_nav_positive',      'mf',     'nav',      'positive',    NULL,       'NAV must be greater than 0'),
('mf_nav_max',           'mf',     'nav',      'max',         '100000',   'NAV value seems unrealistically high (>100000)'),
('mf_amount_positive',   'mf',     'amount',   'positive',    NULL,       'Amount must be greater than 0'),
('mf_amount_max',        'mf',     'amount',   'max',         '100000000','Amount exceeds ₹10 Cr — please verify'),
('mf_txn_date_past',     'mf',     'txn_date', 'date_past',   NULL,       'Transaction date cannot be in the future'),
('mf_txn_type_enum',     'mf',     'txn_type', 'enum',        'purchase,redemption,switch_in,switch_out,dividend_reinvest,sip,swp', 'Invalid transaction type'),
-- Stocks
('st_qty_positive',      'stocks', 'quantity', 'positive',    NULL,       'Quantity must be greater than 0'),
('st_qty_max',           'stocks', 'quantity', 'max',         '10000000', 'Quantity seems unrealistically high (>10M)'),
('st_price_positive',    'stocks', 'price',    'positive',    NULL,       'Price must be greater than 0'),
('st_price_max',         'stocks', 'price',    'max',         '1000000',  'Stock price exceeds ₹10L — please verify'),
('st_txn_date_past',     'stocks', 'txn_date', 'date_past',   NULL,       'Transaction date cannot be in the future'),
('st_txn_type_enum',     'stocks', 'txn_type', 'enum',        'buy,sell,bonus,split,rights', 'Invalid stock transaction type'),
('st_brokerage_min',     'stocks', 'brokerage','min',         '0',        'Brokerage cannot be negative'),
-- FD
('fd_principal_positive','fd',     'principal','positive',    NULL,       'FD principal must be greater than 0'),
('fd_rate_min',          'fd',     'interest_rate','min',     '0.01',     'Interest rate must be > 0'),
('fd_rate_max',          'fd',     'interest_rate','max',     '25',       'Interest rate seems too high (>25%) — please verify'),
('fd_maturity_future',   'fd',     'maturity_date','date_future',NULL,    'FD maturity date must be in the future'),
-- NPS
('nps_units_positive',   'nps',    'units',    'positive',    NULL,       'NPS units must be greater than 0'),
('nps_nav_positive',     'nps',    'nav',      'positive',    NULL,       'NPS NAV must be greater than 0'),
('nps_amount_positive',  'nps',    'amount',   'positive',    NULL,       'NPS amount must be greater than 0'),
-- Savings
('sav_balance_min',      'savings','balance',  'min',         '0',        'Savings balance cannot be negative'),
('sav_rate_max',         'savings','interest_rate','max',     '20',       'Savings interest rate seems too high (>20%)');
