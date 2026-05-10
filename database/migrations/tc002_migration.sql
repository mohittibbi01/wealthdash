-- ============================================================
-- WealthDash — Migration tc002
-- Task   : Crypto Tax (VDA) — Section 115BBH flat 30% tracker
-- Tables : vda_transactions (CREATE), vda_tds_log (CREATE)
-- Run    : php database/migrate.php tc002
-- ============================================================
-- Law reference:
--   Section 115BBH (Finance Act 2022): Income from transfer of VDA
--   taxed at flat 30% (+ 4% cess = effective 31.2%).
--   No deduction allowed except cost of acquisition.
--   Loss from VDA cannot be set off against any other income.
--   Loss cannot be carried forward to next FY.
--   Section 194S: 1% TDS deducted by buyer / exchange on transfer > ₹10,000/year.
-- ============================================================

-- ── VDA Transactions — buy/sell ledger ───────────────────────
CREATE TABLE IF NOT EXISTS `vda_transactions` (
    `id`                     INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `portfolio_id`           INT UNSIGNED      NOT NULL,

    -- Asset identification
    `coin_symbol`            VARCHAR(20)       NOT NULL COMMENT 'BTC, ETH, SOL etc.',
    `coin_name`              VARCHAR(100)      NULL,
    `coingecko_id`           VARCHAR(80)       NULL     COMMENT 'For price lookup',

    -- Transaction type
    `txn_type`               ENUM('BUY','SELL','GIFT_RECEIVED','GIFT_GIVEN','MINING','STAKING','AIRDROP') NOT NULL DEFAULT 'BUY',

    -- BUY leg
    `buy_date`               DATE              NULL,
    `buy_quantity`           DECIMAL(24,8)     NULL     DEFAULT 0,
    `buy_price_inr`          DECIMAL(20,4)     NULL     DEFAULT 0 COMMENT 'Per unit INR at purchase',
    `buy_cost_inr`           DECIMAL(20,2)     NULL     DEFAULT 0 COMMENT 'Total cost = qty × price + fees',
    `buy_fees_inr`           DECIMAL(14,2)     NULL     DEFAULT 0 COMMENT 'Exchange fees on buy',
    `buy_exchange`           VARCHAR(80)       NULL     COMMENT 'WazirX, CoinDCX, Binance, etc.',

    -- SELL leg
    `sell_date`              DATE              NULL,
    `sell_quantity`          DECIMAL(24,8)     NULL     DEFAULT 0,
    `sell_price_inr`         DECIMAL(20,4)     NULL     DEFAULT 0 COMMENT 'Per unit INR at sale',
    `sell_proceeds_inr`      DECIMAL(20,2)     NULL     DEFAULT 0 COMMENT 'Total proceeds = qty × price',
    `sell_fees_inr`          DECIMAL(14,2)     NULL     DEFAULT 0 COMMENT 'Exchange fees on sell',
    `sell_exchange`          VARCHAR(80)       NULL,

    -- Tax computation (115BBH)
    `cost_of_acquisition`    DECIMAL(20,2)     NULL     DEFAULT 0 COMMENT 'Only allowable deduction',
    `gross_proceeds`         DECIMAL(20,2)     NULL     DEFAULT 0,
    `gain_loss_inr`          DECIMAL(20,2)     NULL     DEFAULT 0 COMMENT 'gain_loss = proceeds - COA (fees NOT deductible)',
    `is_gain`                TINYINT(1)        NULL     DEFAULT 1 COMMENT '1=gain, 0=loss',
    `tax_30pct`              DECIMAL(14,2)     NULL     DEFAULT 0 COMMENT 'Flat 30% on gain only; loss = 0 tax',
    `cess_4pct`              DECIMAL(14,2)     NULL     DEFAULT 0 COMMENT '4% Health & Education cess on tax',
    `total_tax_payable`      DECIMAL(14,2)     NULL     DEFAULT 0 COMMENT 'tax_30pct + cess_4pct',

    -- TDS (Section 194S)
    `tds_deducted`           DECIMAL(14,2)     NOT NULL DEFAULT 0 COMMENT '1% TDS deducted by exchange',
    `tds_date`               DATE              NULL,
    `tds_certificate_no`     VARCHAR(50)       NULL,

    -- FY tagging
    `fy`                     VARCHAR(9)        NOT NULL COMMENT 'YYYY-YYYY e.g. 2024-2025',
    `fy_quarter`             TINYINT UNSIGNED  NULL     COMMENT 'Q1-Q4 within FY',

    -- Metadata
    `txn_hash`               VARCHAR(100)      NULL     COMMENT 'Blockchain txn hash if available',
    `notes`                  TEXT              NULL,
    `created_at`             TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_vda_portfolio`  (`portfolio_id`),
    KEY `idx_vda_coin`       (`coin_symbol`),
    KEY `idx_vda_fy`         (`fy`),
    KEY `idx_vda_buy_date`   (`buy_date`),
    KEY `idx_vda_sell_date`  (`sell_date`),
    KEY `idx_vda_type`       (`txn_type`),
    CONSTRAINT `fk_vda_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='VDA (Virtual Digital Assets) transactions — Section 115BBH tax tracker';

-- ── VDA TDS Log — per-exchange annual TDS tracking (194S) ────
CREATE TABLE IF NOT EXISTS `vda_tds_log` (
    `id`                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `portfolio_id`       INT UNSIGNED      NOT NULL,
    `exchange`           VARCHAR(80)       NOT NULL,
    `fy`                 VARCHAR(9)        NOT NULL,
    `total_sale_value`   DECIMAL(20,2)     NOT NULL DEFAULT 0,
    `tds_1pct`           DECIMAL(14,2)     NOT NULL DEFAULT 0,
    `tds_paid`           DECIMAL(14,2)     NOT NULL DEFAULT 0,
    `tds_balance`        DECIMAL(14,2)     NOT NULL DEFAULT 0 COMMENT 'tds_1pct - tds_paid (refund/credit)',
    `form_26as_verified` TINYINT(1)        NOT NULL DEFAULT 0,
    `notes`              VARCHAR(255)      NULL,
    `updated_at`         TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_vda_tds` (`portfolio_id`, `exchange`, `fy`),
    KEY `idx_vda_tds_fy` (`fy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='VDA Section 194S — 1% TDS per exchange per FY summary';

SELECT 'Migration tc002 complete — vda_transactions + vda_tds_log tables created' AS status;
