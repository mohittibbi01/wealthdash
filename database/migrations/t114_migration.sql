-- t114: Gold Tracker — Physical, Digital, ETF Unified
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS gold_holdings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    gold_type ENUM('PHYSICAL','DIGITAL','ETF','FUND') NOT NULL,
    sub_type VARCHAR(50) COMMENT 'coins/bar/jewellery for physical; mmtc/augmont for digital; ticker for ETF',
    name VARCHAR(150) NOT NULL,
    quantity DECIMAL(15,4) NOT NULL COMMENT 'grams for physical/digital, units for ETF/fund',
    buy_price DECIMAL(15,4) NOT NULL COMMENT 'per gram or per unit',
    buy_date DATE NOT NULL,
    making_charges DECIMAL(10,2) DEFAULT 0.00 COMMENT 'for jewellery only',
    purity VARCHAR(10) DEFAULT '24K' COMMENT '24K/22K/18K',
    folio_number VARCHAR(50) DEFAULT NULL COMMENT 'for ETF/fund',
    dp_id VARCHAR(50) DEFAULT NULL COMMENT 'for ETF in demat',
    custodian VARCHAR(100) DEFAULT NULL COMMENT 'for digital gold',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_gold_type (gold_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gold_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    holding_id BIGINT UNSIGNED NOT NULL,
    transaction_type ENUM('BUY','SELL','SIP') NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    transaction_date DATE NOT NULL,
    charges DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (holding_id) REFERENCES gold_holdings(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_holding_id (holding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
