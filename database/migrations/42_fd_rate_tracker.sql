-- ============================================================
-- WealthDash — Migration 42: FD Rate Tracker (t421)
-- Run ONCE before deploying fd_rates.php
-- ============================================================

-- ── Bank FD Rates master table ────────────────────────────
CREATE TABLE IF NOT EXISTS fd_bank_rates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_name       VARCHAR(120)  NOT NULL,
    bank_type       ENUM('public','private_large','private','small_finance','government','cooperative')
                    NOT NULL DEFAULT 'private',
    tenure_months   SMALLINT UNSIGNED NOT NULL,
    rate_regular    DECIMAL(5,2) NOT NULL,
    rate_senior     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    min_amount      INT UNSIGNED NOT NULL DEFAULT 1000,
    is_special      TINYINT(1)   NOT NULL DEFAULT 0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    effective_date  DATE         NOT NULL,
    source_url      VARCHAR(300) DEFAULT NULL,
    notes           VARCHAR(200) DEFAULT NULL,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bank_tenure (bank_name, tenure_months),
    INDEX idx_tenure      (tenure_months),
    INDEX idx_bank_type   (bank_type),
    INDEX idx_rate_reg    (rate_regular DESC),
    INDEX idx_effective   (effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='t421 — FD Rate Tracker: market rates per bank per tenure';

-- ── Rate history log (auto-populated on changes) ──────────
CREATE TABLE IF NOT EXISTS fd_rate_history (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_name       VARCHAR(120)  NOT NULL,
    tenure_months   SMALLINT UNSIGNED NOT NULL,
    rate_regular    DECIMAL(5,2) NOT NULL,
    rate_senior     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    effective_date  DATE         NOT NULL,
    recorded_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bank_tenure (bank_name, tenure_months),
    INDEX idx_date        (effective_date),
    INDEX idx_recorded    (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='t421 — FD Rate Tracker history';

-- ── Seed: curated rates as of Apr 2026 ───────────────────
-- Standard tenures: 3, 6, 9, 12, 18, 24, 36, 60 months
INSERT IGNORE INTO fd_bank_rates
    (bank_name, bank_type, tenure_months, rate_regular, rate_senior, min_amount, effective_date)
VALUES
-- SBI (Public)
('SBI', 'public', 3,  6.00, 6.50, 1000, '2026-01-01'),
('SBI', 'public', 6,  6.50, 7.00, 1000, '2026-01-01'),
('SBI', 'public', 9,  6.75, 7.25, 1000, '2026-01-01'),
('SBI', 'public', 12, 7.10, 7.60, 1000, '2026-01-01'),
('SBI', 'public', 18, 7.10, 7.60, 1000, '2026-01-01'),
('SBI', 'public', 24, 7.00, 7.50, 1000, '2026-01-01'),
('SBI', 'public', 36, 6.75, 7.25, 1000, '2026-01-01'),
('SBI', 'public', 60, 6.50, 7.50, 1000, '2026-01-01'),

-- HDFC Bank (Large Private)
('HDFC Bank', 'private_large', 3,  4.50, 5.00, 5000, '2026-01-01'),
('HDFC Bank', 'private_large', 6,  6.60, 7.10, 5000, '2026-01-01'),
('HDFC Bank', 'private_large', 9,  6.60, 7.10, 5000, '2026-01-01'),
('HDFC Bank', 'private_large', 12, 7.40, 7.90, 5000, '2026-01-01'),
('HDFC Bank', 'private_large', 18, 7.25, 7.75, 5000, '2026-01-01'),
('HDFC Bank', 'private_large', 24, 7.25, 7.75, 5000, '2026-01-01'),
('HDFC Bank', 'private_large', 36, 7.40, 7.90, 5000, '2026-01-01'),
('HDFC Bank', 'private_large', 60, 7.00, 7.50, 5000, '2026-01-01'),

-- ICICI Bank (Large Private)
('ICICI Bank', 'private_large', 3,  4.50, 5.00, 10000, '2026-01-01'),
('ICICI Bank', 'private_large', 6,  6.60, 7.10, 10000, '2026-01-01'),
('ICICI Bank', 'private_large', 9,  6.75, 7.25, 10000, '2026-01-01'),
('ICICI Bank', 'private_large', 12, 7.25, 7.75, 10000, '2026-01-01'),
('ICICI Bank', 'private_large', 18, 7.25, 7.75, 10000, '2026-01-01'),
('ICICI Bank', 'private_large', 24, 7.25, 7.75, 10000, '2026-01-01'),
('ICICI Bank', 'private_large', 36, 7.00, 7.50, 10000, '2026-01-01'),
('ICICI Bank', 'private_large', 60, 7.00, 7.50, 10000, '2026-01-01'),

-- Axis Bank (Large Private)
('Axis Bank', 'private_large', 3,  4.75, 5.25, 5000, '2026-01-01'),
('Axis Bank', 'private_large', 6,  6.50, 7.00, 5000, '2026-01-01'),
('Axis Bank', 'private_large', 9,  6.50, 7.00, 5000, '2026-01-01'),
('Axis Bank', 'private_large', 12, 7.20, 7.70, 5000, '2026-01-01'),
('Axis Bank', 'private_large', 18, 7.10, 7.60, 5000, '2026-01-01'),
('Axis Bank', 'private_large', 24, 7.10, 7.60, 5000, '2026-01-01'),
('Axis Bank', 'private_large', 36, 7.10, 7.60, 5000, '2026-01-01'),
('Axis Bank', 'private_large', 60, 7.00, 7.50, 5000, '2026-01-01'),

-- Kotak Mahindra Bank (Large Private)
('Kotak Bank', 'private_large', 3,  7.00, 7.50, 5000, '2026-01-01'),
('Kotak Bank', 'private_large', 6,  7.10, 7.60, 5000, '2026-01-01'),
('Kotak Bank', 'private_large', 9,  7.10, 7.60, 5000, '2026-01-01'),
('Kotak Bank', 'private_large', 12, 7.40, 7.90, 5000, '2026-01-01'),
('Kotak Bank', 'private_large', 18, 7.20, 7.70, 5000, '2026-01-01'),
('Kotak Bank', 'private_large', 24, 7.20, 7.70, 5000, '2026-01-01'),
('Kotak Bank', 'private_large', 36, 7.00, 7.50, 5000, '2026-01-01'),
('Kotak Bank', 'private_large', 60, 6.60, 7.10, 5000, '2026-01-01'),

-- IDFC First Bank (Private)
('IDFC First Bank', 'private', 3,  6.00, 6.50, 10000, '2026-01-01'),
('IDFC First Bank', 'private', 6,  7.25, 7.75, 10000, '2026-01-01'),
('IDFC First Bank', 'private', 9,  7.25, 7.75, 10000, '2026-01-01'),
('IDFC First Bank', 'private', 12, 7.90, 8.40, 10000, '2026-01-01'),
('IDFC First Bank', 'private', 18, 7.90, 8.40, 10000, '2026-01-01'),
('IDFC First Bank', 'private', 24, 7.50, 8.00, 10000, '2026-01-01'),
('IDFC First Bank', 'private', 36, 7.25, 7.75, 10000, '2026-01-01'),
('IDFC First Bank', 'private', 60, 7.00, 7.50, 10000, '2026-01-01'),

-- RBL Bank (Private)
('RBL Bank', 'private', 3,  6.00, 6.75, 10000, '2026-01-01'),
('RBL Bank', 'private', 6,  7.00, 7.75, 10000, '2026-01-01'),
('RBL Bank', 'private', 9,  7.50, 8.25, 10000, '2026-01-01'),
('RBL Bank', 'private', 12, 8.00, 8.75, 10000, '2026-01-01'),
('RBL Bank', 'private', 18, 7.80, 8.55, 10000, '2026-01-01'),
('RBL Bank', 'private', 24, 7.50, 8.25, 10000, '2026-01-01'),
('RBL Bank', 'private', 36, 7.25, 8.00, 10000, '2026-01-01'),
('RBL Bank', 'private', 60, 7.00, 7.75, 10000, '2026-01-01'),

-- Yes Bank (Private)
('Yes Bank', 'private', 3,  6.25, 6.75, 10000, '2026-01-01'),
('Yes Bank', 'private', 6,  7.25, 7.75, 10000, '2026-01-01'),
('Yes Bank', 'private', 9,  7.25, 7.75, 10000, '2026-01-01'),
('Yes Bank', 'private', 12, 7.75, 8.25, 10000, '2026-01-01'),
('Yes Bank', 'private', 18, 7.75, 8.25, 10000, '2026-01-01'),
('Yes Bank', 'private', 24, 7.75, 8.25, 10000, '2026-01-01'),
('Yes Bank', 'private', 36, 8.00, 8.50, 10000, '2026-01-01'),
('Yes Bank', 'private', 60, 7.75, 8.25, 10000, '2026-01-01'),

-- Unity Small Finance Bank (Small Finance)
('Unity Small Finance Bank', 'small_finance', 3,  7.50, 8.25, 10000, '2026-01-01'),
('Unity Small Finance Bank', 'small_finance', 6,  8.25, 9.00, 10000, '2026-01-01'),
('Unity Small Finance Bank', 'small_finance', 9,  8.50, 9.25, 10000, '2026-01-01'),
('Unity Small Finance Bank', 'small_finance', 12, 9.00, 9.75, 10000, '2026-01-01'),
('Unity Small Finance Bank', 'small_finance', 18, 8.50, 9.25, 10000, '2026-01-01'),
('Unity Small Finance Bank', 'small_finance', 24, 8.25, 9.00, 10000, '2026-01-01'),
('Unity Small Finance Bank', 'small_finance', 36, 8.00, 8.75, 10000, '2026-01-01'),
('Unity Small Finance Bank', 'small_finance', 60, 7.50, 8.25, 10000, '2026-01-01'),

-- Suryoday Small Finance Bank
('Suryoday Small Finance', 'small_finance', 3,  6.75, 7.25, 5000, '2026-01-01'),
('Suryoday Small Finance', 'small_finance', 6,  7.75, 8.25, 5000, '2026-01-01'),
('Suryoday Small Finance', 'small_finance', 9,  8.00, 8.50, 5000, '2026-01-01'),
('Suryoday Small Finance', 'small_finance', 12, 8.60, 9.10, 5000, '2026-01-01'),
('Suryoday Small Finance', 'small_finance', 18, 8.50, 9.00, 5000, '2026-01-01'),
('Suryoday Small Finance', 'small_finance', 24, 8.25, 8.75, 5000, '2026-01-01'),
('Suryoday Small Finance', 'small_finance', 36, 8.00, 8.50, 5000, '2026-01-01'),
('Suryoday Small Finance', 'small_finance', 60, 7.75, 8.25, 5000, '2026-01-01'),

-- Post Office (Government)
('Post Office TD', 'government', 12, 6.90, 6.90, 1000, '2026-01-01'),
('Post Office TD', 'government', 24, 7.00, 7.00, 1000, '2026-01-01'),
('Post Office TD', 'government', 36, 7.10, 7.10, 1000, '2026-01-01'),
('Post Office TD', 'government', 60, 7.50, 7.50, 1000, '2026-01-01');

-- ── Admin setting: rate tracker last-refresh timestamp ────
INSERT IGNORE INTO app_settings (`key`, `value`, `description`)
VALUES ('fd_rates_last_updated', CURDATE(), 'Last date FD bank rates were manually updated (t421)')
ON DUPLICATE KEY UPDATE `value` = `value`;
