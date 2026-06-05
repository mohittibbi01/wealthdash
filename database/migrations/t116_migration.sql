-- t116: Corporate Bonds / NCDs — Listed and Unlisted
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS bonds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    bond_type ENUM('NCD','BOND','DEBENTURE','COMMERCIAL_PAPER') NOT NULL,
    listing_type ENUM('LISTED','UNLISTED') NOT NULL DEFAULT 'LISTED',
    issuer_name VARCHAR(200) NOT NULL,
    isin VARCHAR(20),
    series VARCHAR(50),
    face_value DECIMAL(15,2) NOT NULL DEFAULT 1000.00,
    quantity INT UNSIGNED NOT NULL,
    purchase_price DECIMAL(15,4) NOT NULL,
    purchase_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    coupon_rate DECIMAL(8,4) NOT NULL COMMENT 'Annual interest rate %',
    coupon_frequency ENUM('MONTHLY','QUARTERLY','SEMI_ANNUAL','ANNUAL','CUMULATIVE','ON_MATURITY') DEFAULT 'ANNUAL',
    credit_rating VARCHAR(20) COMMENT 'AAA/AA+/etc',
    rating_agency VARCHAR(50),
    secured TINYINT(1) DEFAULT 1,
    broker VARCHAR(100),
    dp_id VARCHAR(50),
    redemption_type ENUM('BULLET','CALLABLE','PUTTABLE','STEP_UP') DEFAULT 'BULLET',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_bond_type (bond_type),
    INDEX idx_listing (listing_type),
    INDEX idx_maturity (maturity_date),
    INDEX idx_isin (isin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bond_cashflows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    bond_id BIGINT UNSIGNED NOT NULL,
    cashflow_type ENUM('COUPON','PRINCIPAL','PARTIAL_REDEMPTION') NOT NULL,
    scheduled_date DATE NOT NULL,
    amount DECIMAL(15,4) NOT NULL,
    received TINYINT(1) DEFAULT 0,
    received_date DATE DEFAULT NULL,
    tds_deducted DECIMAL(10,2) DEFAULT 0.00,
    net_amount DECIMAL(15,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bond_id) REFERENCES bonds(id) ON DELETE CASCADE,
    INDEX idx_bond_id (bond_id),
    INDEX idx_user_id (user_id),
    INDEX idx_scheduled_date (scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
