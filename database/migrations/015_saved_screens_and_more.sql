-- ============================================================
-- Migration 015: Saved Screens + Fund Ratings
-- Tasks: t110, tv01
-- ============================================================

CREATE TABLE IF NOT EXISTS mf_saved_screens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    name         VARCHAR(80)  NOT NULL,
    filters_json TEXT         NOT NULL COMMENT 'JSON encoded filter state',
    is_public    TINYINT(1)   NOT NULL DEFAULT 0,
    share_token  VARCHAR(32)  DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_public (is_public),
    INDEX idx_token  (share_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 016: Fund Ratings
CREATE TABLE IF NOT EXISTS fund_ratings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fund_id          INT UNSIGNED NOT NULL UNIQUE,
    rating_stars     TINYINT(1)   DEFAULT NULL COMMENT '1-5',
    return_score     DECIMAL(5,2) DEFAULT NULL COMMENT 'Returns component 0-100',
    consistency_score DECIMAL(5,2) DEFAULT NULL,
    risk_score       DECIMAL(5,2) DEFAULT NULL,
    cost_score       DECIMAL(5,2) DEFAULT NULL,
    manager_score    DECIMAL(5,2) DEFAULT NULL,
    total_score      DECIMAL(5,2) DEFAULT NULL,
    calc_date        DATE         NOT NULL,
    INDEX idx_fund  (fund_id),
    INDEX idx_stars (rating_stars),
    INDEX idx_score (total_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 026: Expense (TER) History
CREATE TABLE IF NOT EXISTS fund_ter_history (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fund_id        INT UNSIGNED NOT NULL,
    ter_pct        DECIMAL(5,3) NOT NULL,
    effective_date DATE         NOT NULL,
    UNIQUE KEY uk_fund_date (fund_id, effective_date),
    INDEX idx_fund (fund_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 027: IDCW/Dividend History
CREATE TABLE IF NOT EXISTS fund_dividends (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fund_id         INT UNSIGNED NOT NULL,
    record_date     DATE         NOT NULL,
    ex_date         DATE         DEFAULT NULL,
    dividend_per_unit DECIMAL(10,4) NOT NULL,
    UNIQUE KEY uk_fund_date (fund_id, record_date),
    INDEX idx_fund (fund_id),
    INDEX idx_date (record_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 028: Fund Portfolio Holdings (top stocks per fund)
CREATE TABLE IF NOT EXISTS fund_portfolio_holdings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fund_id     INT UNSIGNED NOT NULL,
    stock_name  VARCHAR(100) NOT NULL,
    isin        VARCHAR(12)  DEFAULT NULL,
    sector      VARCHAR(60)  DEFAULT NULL,
    weight_pct  DECIMAL(5,2) DEFAULT NULL,
    month_year  VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
    UNIQUE KEY uk_fund_stock_month (fund_id, isin, month_year),
    INDEX idx_fund  (fund_id),
    INDEX idx_month (month_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 030: Holdings Notes
CREATE TABLE IF NOT EXISTS mf_holdings_notes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    holding_id INT UNSIGNED NOT NULL,
    note_text  TEXT         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_holding (user_id, holding_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration 031: Import Log
CREATE TABLE IF NOT EXISTS import_logs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    filename       VARCHAR(200) NOT NULL,
    format         VARCHAR(30)  NOT NULL COMMENT 'cas_pdf|groww_csv|zerodha_csv|kuvera_json|...',
    imported_count INT UNSIGNED DEFAULT 0,
    skipped_count  INT UNSIGNED DEFAULT 0,
    failed_count   INT UNSIGNED DEFAULT 0,
    error_json     TEXT         DEFAULT NULL,
    status         ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
    imported_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_date (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
