-- ============================================================
-- WealthDash Phase 4 — DB additions (if not already in schema.sql)
-- Run this ONLY if Phase 1 schema.sql was used as-is
-- ============================================================

-- savings_interest table (may be missing from original schema)
CREATE TABLE IF NOT EXISTS savings_interest (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL,
    interest_date    DATE         NOT NULL,
    interest_amount  DECIMAL(12,2) NOT NULL,
    balance_after    DECIMAL(14,2) NULL,
    interest_fy      VARCHAR(10)  NOT NULL COMMENT 'e.g. 2024-25',
    notes            TEXT         NULL,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acct_date (account_id, interest_date),
    CONSTRAINT fk_si_account FOREIGN KEY (account_id) REFERENCES savings_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure savings_accounts has annual_interest_earned column
ALTER TABLE savings_accounts
    ADD COLUMN IF NOT EXISTS annual_interest_earned DECIMAL(12,2) DEFAULT 0 AFTER interest_rate,
    ADD COLUMN IF NOT EXISTS account_type VARCHAR(20) DEFAULT 'savings' AFTER account_number,
    ADD COLUMN IF NOT EXISTS balance_date DATE NULL AFTER current_balance;

-- Ensure stock_holdings has gain_pct column
ALTER TABLE stock_holdings
    ADD COLUMN IF NOT EXISTS gain_pct      DECIMAL(8,4)  DEFAULT 0  AFTER gain_loss,
    ADD COLUMN IF NOT EXISTS current_price DECIMAL(12,4) DEFAULT 0  AFTER current_value;

-- Ensure nps_holdings has gain_pct column
ALTER TABLE nps_holdings
    ADD COLUMN IF NOT EXISTS gain_pct DECIMAL(8,4) DEFAULT 0 AFTER gain_loss,
    ADD COLUMN IF NOT EXISTS cagr     DECIMAL(8,4) DEFAULT 0 AFTER gain_pct;
