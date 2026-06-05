-- t436: Stock SIP — Regular Stock Purchase Tracker
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS stock_sip (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(150) NOT NULL,
    exchange VARCHAR(10) DEFAULT 'NSE',
    sip_amount DECIMAL(15,2) NOT NULL COMMENT 'Fixed INR amount per installment',
    frequency ENUM('DAILY','WEEKLY','FORTNIGHTLY','MONTHLY','QUARTERLY') DEFAULT 'MONTHLY',
    sip_day TINYINT UNSIGNED DEFAULT NULL COMMENT 'Day of month (1-28) or day of week (0-6)',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL COMMENT 'NULL = open-ended',
    broker VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_sip_installments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sip_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    installment_date DATE NOT NULL,
    quantity DECIMAL(15,6) NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status ENUM('PENDING','EXECUTED','FAILED','SKIPPED') DEFAULT 'PENDING',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sip_id) REFERENCES stock_sip(id) ON DELETE CASCADE,
    INDEX idx_sip_id (sip_id),
    INDEX idx_user_id (user_id),
    INDEX idx_date (installment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
