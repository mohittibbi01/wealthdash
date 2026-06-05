-- t432: Portfolio P/E vs Market P/E
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS stock_fundamentals_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_symbol VARCHAR(20) NOT NULL,
    isin VARCHAR(20),
    pe_ratio DECIMAL(10,4) DEFAULT NULL,
    pb_ratio DECIMAL(10,4) DEFAULT NULL,
    eps DECIMAL(15,4) DEFAULT NULL,
    market_cap DECIMAL(25,4) DEFAULT NULL,
    sector VARCHAR(100),
    industry VARCHAR(100),
    data_date DATE NOT NULL,
    source VARCHAR(50) DEFAULT 'NSE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_symbol_date (stock_symbol, data_date),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_pe_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    index_name VARCHAR(50) NOT NULL COMMENT 'NIFTY50/NIFTY500/SENSEX',
    data_date DATE NOT NULL,
    pe_ratio DECIMAL(10,4) NOT NULL,
    pb_ratio DECIMAL(10,4) DEFAULT NULL,
    div_yield DECIMAL(8,4) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_index_date (index_name, data_date),
    INDEX idx_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
