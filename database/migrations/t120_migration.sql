-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t120: Smallcase Portfolio Sync
-- Migration: t120_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS smallcase_portfolios (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    portfolio_id     INT UNSIGNED NOT NULL,
    name             VARCHAR(200) NOT NULL,
    description      TEXT         DEFAULT NULL,
    strategy_type    VARCHAR(100) DEFAULT NULL,
    manager          VARCHAR(100) DEFAULT NULL,
    external_id      VARCHAR(100) DEFAULT NULL COMMENT 'Smallcase basket ID if available',
    invested_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value    DECIMAL(14,2) DEFAULT NULL,
    gain_loss        DECIMAL(14,2) DEFAULT NULL,
    gain_loss_pct    DECIMAL(8,4)  DEFAULT NULL,
    xirr             DECIMAL(8,4)  DEFAULT NULL,
    subscription_fee DECIMAL(10,2) DEFAULT 0,
    fee_frequency    ENUM('monthly','quarterly','yearly','one_time') DEFAULT NULL,
    last_rebalanced  DATE          DEFAULT NULL,
    next_rebalance   DATE          DEFAULT NULL,
    is_active        TINYINT(1)    NOT NULL DEFAULT 1,
    notes            TEXT          DEFAULT NULL,
    created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user       (user_id),
    INDEX idx_portfolio  (portfolio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smallcase_holdings (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    smallcase_id       INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NOT NULL,
    symbol             VARCHAR(30)  NOT NULL,
    company_name       VARCHAR(200) NOT NULL,
    exchange           ENUM('NSE','BSE') NOT NULL DEFAULT 'NSE',
    isin               VARCHAR(20)  DEFAULT NULL,
    quantity           DECIMAL(14,4) NOT NULL DEFAULT 0,
    avg_buy_price      DECIMAL(12,4) NOT NULL DEFAULT 0,
    invested_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_price      DECIMAL(12,4) DEFAULT NULL,
    current_value      DECIMAL(14,2) DEFAULT NULL,
    weight_pct         DECIMAL(6,2)  DEFAULT NULL COMMENT 'Allocation % in this basket',
    target_weight_pct  DECIMAL(6,2)  DEFAULT NULL,
    sector             VARCHAR(100)  DEFAULT NULL,
    last_price_date    DATE          DEFAULT NULL,
    created_at         DATETIME      DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_smallcase (smallcase_id),
    INDEX idx_user      (user_id),
    INDEX idx_symbol    (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smallcase_transactions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    smallcase_id     INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    txn_type         ENUM('invest','redeem','rebalance','dividend','switch') NOT NULL DEFAULT 'invest',
    txn_date         DATE         NOT NULL,
    amount           DECIMAL(14,2) NOT NULL DEFAULT 0,
    units_change     DECIMAL(14,4) DEFAULT NULL COMMENT 'For individual stock change in rebalance',
    notes            TEXT          DEFAULT NULL,
    import_source    VARCHAR(50)   DEFAULT 'manual',
    created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_smallcase (smallcase_id),
    INDEX idx_user      (user_id),
    INDEX idx_date      (txn_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smallcase_rebalance_history (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    smallcase_id     INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    rebalance_date   DATE         NOT NULL,
    reason           VARCHAR(200) DEFAULT NULL COMMENT 'e.g. Quarterly rebalance, Stock addition',
    stocks_added     TEXT         DEFAULT NULL COMMENT 'JSON array of symbols added',
    stocks_removed   TEXT         DEFAULT NULL COMMENT 'JSON array of symbols removed',
    stocks_changed   TEXT         DEFAULT NULL COMMENT 'JSON array of weight changes',
    portfolio_value  DECIMAL(14,2) DEFAULT NULL,
    notes            TEXT         DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_smallcase (smallcase_id),
    INDEX idx_user      (user_id),
    INDEX idx_date      (rebalance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
