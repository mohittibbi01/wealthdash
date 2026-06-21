-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t115: REITs & InvITs
-- Migration: t115_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS reits_invits (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    portfolio_id     INT UNSIGNED NOT NULL,
    symbol           VARCHAR(30)  NOT NULL,
    name             VARCHAR(200) NOT NULL,
    trust_type       ENUM('REIT','InvIT') NOT NULL DEFAULT 'REIT',
    exchange         ENUM('NSE','BSE') NOT NULL DEFAULT 'NSE',
    isin             VARCHAR(20)  DEFAULT NULL,
    units            DECIMAL(14,4) NOT NULL DEFAULT 0,
    avg_buy_price    DECIMAL(12,4) NOT NULL DEFAULT 0,
    total_invested   DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_price    DECIMAL(12,4) DEFAULT NULL,
    current_value    DECIMAL(14,2) DEFAULT NULL,
    gain_loss        DECIMAL(14,2) DEFAULT NULL,
    gain_loss_pct    DECIMAL(8,4)  DEFAULT NULL,
    last_price_date  DATE          DEFAULT NULL,
    notes            TEXT          DEFAULT NULL,
    created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_portfolio (portfolio_id),
    INDEX idx_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reits_invits_transactions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holding_id       INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    portfolio_id     INT UNSIGNED NOT NULL,
    symbol           VARCHAR(30)  NOT NULL,
    transaction_type ENUM('BUY','SELL','DIVIDEND','DISTRIBUTION') NOT NULL,
    txn_date         DATE         NOT NULL,
    units            DECIMAL(14,4) NOT NULL,
    price            DECIMAL(12,4) NOT NULL,
    amount           DECIMAL(14,2) NOT NULL,
    brokerage        DECIMAL(10,2) DEFAULT 0,
    notes            TEXT          DEFAULT NULL,
    created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_holding (holding_id),
    INDEX idx_user    (user_id),
    INDEX idx_date    (txn_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reits_invits_distributions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holding_id       INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    symbol           VARCHAR(30)  NOT NULL,
    dist_type        ENUM('dividend','interest','SPD','return_of_capital') NOT NULL DEFAULT 'dividend',
    ex_date          DATE         NOT NULL,
    pay_date         DATE         DEFAULT NULL,
    per_unit_amount  DECIMAL(10,4) NOT NULL,
    units_held       DECIMAL(14,4) NOT NULL,
    total_amount     DECIMAL(12,2) NOT NULL,
    tds_deducted     DECIMAL(10,2) DEFAULT 0,
    net_amount       DECIMAL(12,2) NOT NULL,
    notes            TEXT          DEFAULT NULL,
    created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_holding (holding_id),
    INDEX idx_user    (user_id),
    INDEX idx_ex_date (ex_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Known REITs & InvITs master list
CREATE TABLE IF NOT EXISTS reits_invits_master (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol      VARCHAR(30)  NOT NULL UNIQUE,
    name        VARCHAR(200) NOT NULL,
    trust_type  ENUM('REIT','InvIT') NOT NULL,
    exchange    VARCHAR(10)  NOT NULL DEFAULT 'NSE',
    isin        VARCHAR(20)  DEFAULT NULL,
    sector      VARCHAR(100) DEFAULT NULL,
    sponsor     VARCHAR(200) DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    INDEX idx_type (trust_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reits_invits_master (symbol, name, trust_type, exchange, isin, sector, sponsor) VALUES
('EMBASSY',    'Embassy Office Parks REIT',    'REIT',  'NSE', 'INE041025011', 'Commercial Office', 'Embassy Group'),
('MINDSPACE',  'Mindspace Business Parks REIT','REIT',  'NSE', 'INE790U01018', 'Commercial Office', 'K Raheja Corp'),
('BROOKFIELD', 'Brookfield India Real Estate Trust', 'REIT', 'NSE', 'INE0GRO01016', 'Commercial Office', 'Brookfield Asset Management'),
('NEXUSSELECT','Nexus Select Trust REIT',      'REIT',  'NSE', 'INE09SC01018', 'Retail Mall',       'Blackstone'),
('POWERGRID',  'PowerGrid Infrastructure InvIT','InvIT','NSE', 'INE939M08013', 'Power Transmission','Power Grid Corp'),
('INDIGRID',   'IndiGrid InvIT',               'InvIT', 'NSE', 'INE219X08014', 'Power Transmission','Sterlite Power'),
('IRB',        'IRB InvIT Fund',               'InvIT', 'NSE', 'INE821K08014', 'Roads',             'IRB Infrastructure'),
('NHAI',       'National Highways Infra Trust InvIT','InvIT','NSE',NULL,        'Roads',             'NHAI'),
('BHARAT',     'Bharat Highways InvIT',        'InvIT', 'NSE', NULL,           'Roads',             'BHEL');
