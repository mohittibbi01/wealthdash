-- t39: LTCG/STCG Stocks Report
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS stock_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(100) NOT NULL,
    transaction_type ENUM('BUY','SELL') NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    brokerage DECIMAL(10,2) DEFAULT 0.00,
    stt DECIMAL(10,2) DEFAULT 0.00,
    transaction_date DATE NOT NULL,
    exchange VARCHAR(10) DEFAULT 'NSE',
    isin VARCHAR(20),
    grandfathered_price DECIMAL(15,4) DEFAULT NULL COMMENT 'FMV as on 31-Jan-2018 for pre-2018 holdings',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_date (transaction_date),
    INDEX idx_type (transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_lots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(100) NOT NULL,
    buy_transaction_id BIGINT UNSIGNED,
    sell_transaction_id BIGINT UNSIGNED DEFAULT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    buy_price DECIMAL(15,4) NOT NULL,
    sell_price DECIMAL(15,4) DEFAULT NULL,
    buy_date DATE NOT NULL,
    sell_date DATE DEFAULT NULL,
    gain_loss DECIMAL(15,4) DEFAULT NULL,
    tax_category ENUM('STCG','LTCG','UNREALIZED') DEFAULT 'UNREALIZED',
    financial_year VARCHAR(10) COMMENT 'e.g. 2024-25',
    grandfathered_price DECIMAL(15,4) DEFAULT NULL,
    INDEX idx_user_fy (user_id, financial_year),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_category (tax_category),
    INDEX idx_sell_date (sell_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
