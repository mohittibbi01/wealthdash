-- t38: Stocks Screener — Basic Filter + Sort
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS screener_filters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    filter_name VARCHAR(100) NOT NULL,
    filter_config JSON NOT NULL COMMENT 'JSON object with filter criteria',
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS screener_universe (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(200) NOT NULL,
    isin VARCHAR(20),
    exchange VARCHAR(10) DEFAULT 'NSE',
    sector VARCHAR(100),
    industry VARCHAR(100),
    market_cap DECIMAL(25,4),
    market_cap_category ENUM('LARGE','MID','SMALL','MICRO') DEFAULT NULL,
    pe_ratio DECIMAL(10,4),
    pb_ratio DECIMAL(10,4),
    eps DECIMAL(15,4),
    roe DECIMAL(8,4),
    roce DECIMAL(8,4),
    debt_to_equity DECIMAL(10,4),
    current_ratio DECIMAL(10,4),
    dividend_yield DECIMAL(8,4),
    revenue_growth_1y DECIMAL(8,4),
    profit_growth_1y DECIMAL(8,4),
    price_52w_high DECIMAL(15,4),
    price_52w_low DECIMAL(15,4),
    current_price DECIMAL(15,4),
    avg_volume_30d BIGINT UNSIGNED,
    data_date DATE NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_symbol (stock_symbol),
    INDEX idx_sector (sector),
    INDEX idx_market_cap_cat (market_cap_category),
    INDEX idx_pe (pe_ratio),
    INDEX idx_data_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
