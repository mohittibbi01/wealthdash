-- t121: International Stocks / LRS Tracker
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS international_stocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ticker VARCHAR(20) NOT NULL,
    stock_name VARCHAR(200) NOT NULL,
    exchange VARCHAR(20) NOT NULL COMMENT 'NYSE/NASDAQ/LSE/etc',
    country VARCHAR(5) DEFAULT 'US',
    currency VARCHAR(5) DEFAULT 'USD',
    quantity DECIMAL(15,6) NOT NULL COMMENT 'fractional shares allowed',
    avg_buy_price_foreign DECIMAL(15,4) NOT NULL COMMENT 'in foreign currency',
    avg_buy_price_inr DECIMAL(15,4) NOT NULL COMMENT 'INR at time of purchase',
    current_price_foreign DECIMAL(15,4) DEFAULT NULL,
    current_price_inr DECIMAL(15,4) DEFAULT NULL,
    broker_platform VARCHAR(100) COMMENT 'Vested/Indmoney/Stockal/etc',
    sector VARCHAR(100),
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    last_refreshed TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_ticker (ticker),
    INDEX idx_exchange (exchange),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lrs_remittances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    remittance_date DATE NOT NULL,
    amount_inr DECIMAL(15,2) NOT NULL,
    amount_foreign DECIMAL(15,4) NOT NULL,
    currency VARCHAR(5) DEFAULT 'USD',
    exchange_rate DECIMAL(10,4) NOT NULL,
    purpose VARCHAR(200) COMMENT 'Investment/Education/Travel/etc',
    bank_name VARCHAR(100),
    forex_charges DECIMAL(10,2) DEFAULT 0.00,
    tcs_paid DECIMAL(10,2) DEFAULT 0.00 COMMENT 'TCS @ 20% above 7L threshold',
    financial_year VARCHAR(10),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_fy (financial_year),
    INDEX idx_date (remittance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intl_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    lrs_id BIGINT UNSIGNED DEFAULT NULL,
    transaction_type ENUM('BUY','SELL','DIVIDEND') NOT NULL,
    quantity DECIMAL(15,6) NOT NULL,
    price_foreign DECIMAL(15,4) NOT NULL,
    price_inr DECIMAL(15,4) NOT NULL,
    exchange_rate DECIMAL(10,4) NOT NULL,
    transaction_date DATE NOT NULL,
    charges_foreign DECIMAL(10,4) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stock_id) REFERENCES international_stocks(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_stock_id (stock_id),
    INDEX idx_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
