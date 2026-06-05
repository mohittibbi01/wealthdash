-- t145: Stock Picker Reality Check vs Nifty 50
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS benchmark_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    benchmark_name VARCHAR(50) NOT NULL COMMENT 'NIFTY50/SENSEX/NIFTY500',
    data_date DATE NOT NULL,
    close_value DECIMAL(15,4) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bench_date (benchmark_name, data_date),
    INDEX idx_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portfolio_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    snapshot_date DATE NOT NULL,
    portfolio_value DECIMAL(20,4) NOT NULL,
    invested_value DECIMAL(20,4) NOT NULL,
    xirr DECIMAL(8,4) DEFAULT NULL COMMENT 'XIRR %',
    twrr DECIMAL(8,4) DEFAULT NULL COMMENT 'Time-weighted return %',
    nifty50_value DECIMAL(15,4) DEFAULT NULL COMMENT 'Nifty 50 on same date',
    nifty50_returns DECIMAL(8,4) DEFAULT NULL COMMENT 'Nifty 50 equivalent % returns',
    alpha DECIMAL(8,4) DEFAULT NULL COMMENT 'portfolio_returns - benchmark_returns',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_date (user_id, snapshot_date),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
