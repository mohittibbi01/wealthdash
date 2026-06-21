-- t118: RBI Floating Rate Bonds & G-Secs / T-Bills
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS govt_securities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    security_type ENUM('RBI_FRB','GSEC','TBILL','SDL') NOT NULL,
    security_name VARCHAR(200) NOT NULL,
    isin VARCHAR(20),
    face_value DECIMAL(15,2) NOT NULL DEFAULT 1000.00,
    quantity INT UNSIGNED NOT NULL,
    purchase_price DECIMAL(15,4) NOT NULL,
    purchase_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    coupon_rate DECIMAL(8,4) DEFAULT NULL COMMENT 'NULL for T-Bills (discount instrument)',
    coupon_frequency ENUM('SEMI_ANNUAL','QUARTERLY','FLOATING') DEFAULT 'SEMI_ANNUAL',
    is_floating TINYINT(1) DEFAULT 0 COMMENT '1 for FRB where rate resets',
    floating_reference VARCHAR(50) DEFAULT NULL COMMENT 'NSS rate / Repo rate reference',
    floating_spread DECIMAL(6,4) DEFAULT NULL COMMENT 'Spread above reference rate',
    platform VARCHAR(100) COMMENT 'RBI Retail Direct/Zerodha/NSE goBID',
    redemption_price DECIMAL(15,4) DEFAULT NULL COMMENT 'For T-Bills = face_value',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_security_type (security_type),
    INDEX idx_maturity (maturity_date),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gsec_cashflows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    security_id BIGINT UNSIGNED NOT NULL,
    cashflow_type ENUM('COUPON','MATURITY','PARTIAL_SALE') NOT NULL,
    scheduled_date DATE NOT NULL,
    coupon_rate_applied DECIMAL(8,4) DEFAULT NULL COMMENT 'Actual rate for floating bonds',
    amount DECIMAL(15,4) NOT NULL,
    received TINYINT(1) DEFAULT 0,
    received_date DATE DEFAULT NULL,
    tds_deducted DECIMAL(10,2) DEFAULT 0.00,
    net_amount DECIMAL(15,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (security_id) REFERENCES govt_securities(id) ON DELETE CASCADE,
    INDEX idx_security_id (security_id),
    INDEX idx_user_id (user_id),
    INDEX idx_scheduled_date (scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
