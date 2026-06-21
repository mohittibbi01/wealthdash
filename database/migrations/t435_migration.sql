-- t435: Watchlist with Price Targets
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS watchlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(150) NOT NULL,
    exchange VARCHAR(10) DEFAULT 'NSE',
    isin VARCHAR(20),
    buy_target DECIMAL(15,4) DEFAULT NULL COMMENT 'Target buy price',
    sell_target DECIMAL(15,4) DEFAULT NULL COMMENT 'Target sell / exit price',
    stop_loss DECIMAL(15,4) DEFAULT NULL,
    current_price DECIMAL(15,4) DEFAULT NULL,
    rationale TEXT COMMENT 'Why watching this stock',
    sector VARCHAR(100),
    tags VARCHAR(255) COMMENT 'comma-separated custom tags',
    alert_on_buy_target TINYINT(1) DEFAULT 1,
    alert_on_sell_target TINYINT(1) DEFAULT 1,
    alert_on_stop_loss TINYINT(1) DEFAULT 1,
    notes TEXT,
    added_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_symbol (user_id, stock_symbol),
    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watchlist_price_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    watchlist_id BIGINT UNSIGNED NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (watchlist_id) REFERENCES watchlist(id) ON DELETE CASCADE,
    INDEX idx_watchlist_id (watchlist_id),
    INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
