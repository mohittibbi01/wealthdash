-- ============================================================
-- WealthDash — Migration: New Tables from Pending Tasks
-- Run this ONCE before deploying the new PHP files
-- Generated for tasks: t80, t139, t286, t315-317, t355, t380-390, t396, t437, t440
-- ============================================================

-- ── 2FA (t386) ───────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS totp_secret       VARCHAR(64)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS totp_enabled      TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS totp_backup_codes TEXT         DEFAULT NULL;

-- ── Session Security (t387) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS user_sessions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    session_token VARCHAR(128) NOT NULL UNIQUE,
    device_name   VARCHAR(120),
    device_type   ENUM('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
    browser       VARCHAR(80),
    ip_address    VARCHAR(45),
    last_active   DATETIME NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user  (user_id),
    INDEX idx_token (session_token),
    INDEX idx_active(last_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Rate Limiting (t390) ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    bucket_key   VARCHAR(180) NOT NULL PRIMARY KEY,
    requests     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_start INT UNSIGNED NOT NULL,
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── AI Chat History (t381) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_chat_history (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    role       ENUM('user','assistant') NOT NULL,
    message    TEXT NOT NULL,
    context_id VARCHAR(36) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_ctx (user_id, context_id),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── AI Portfolio Reviews (t380, t333) ────────────────────────
CREATE TABLE IF NOT EXISTS ai_portfolio_reviews (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    review_month VARCHAR(7)   NOT NULL,
    review_type  VARCHAR(30)  NOT NULL DEFAULT 'monthly',
    grade        CHAR(2),
    score        TINYINT UNSIGNED,
    summary      TEXT,
    strengths    TEXT,
    weaknesses   TEXT,
    actions      TEXT,
    raw_response TEXT,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_month (user_id, review_month, review_type),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── AI Anomalies (t384) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_anomalies (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    anomaly_type VARCHAR(60)  NOT NULL,
    severity     ENUM('info','warning','critical') DEFAULT 'warning',
    title        VARCHAR(200) NOT NULL,
    description  TEXT,
    data_json    TEXT,
    is_dismissed TINYINT(1) NOT NULL DEFAULT 0,
    detected_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dismissed_at DATETIME DEFAULT NULL,
    INDEX idx_user     (user_id),
    INDEX idx_severity (severity),
    INDEX idx_dismissed(is_dismissed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── FD Maturity Alerts (t80) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS fd_maturity_alerts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    fd_id        INT UNSIGNED NOT NULL,
    alert_days   TINYINT UNSIGNED NOT NULL,
    is_sent      TINYINT(1) NOT NULL DEFAULT 0,
    sent_at      DATETIME DEFAULT NULL,
    is_dismissed TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_fd_days (fd_id, alert_days),
    INDEX idx_user (user_id),
    INDEX idx_sent (is_sent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── FD Market Rates (t421) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS fd_market_rates (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_name      VARCHAR(80)  NOT NULL,
    tenure_label   VARCHAR(30)  NOT NULL,
    rate_general   DECIMAL(5,2) NOT NULL,
    rate_senior    DECIMAL(5,2) NOT NULL,
    effective_date DATE DEFAULT (CURDATE()),
    source_url     VARCHAR(300) DEFAULT NULL,
    updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Goals & Goal Holdings (t139, t355) ───────────────────────
CREATE TABLE IF NOT EXISTS goals (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    goal_name        VARCHAR(120) NOT NULL,
    goal_type        VARCHAR(50)  DEFAULT 'custom',
    target_amount    DECIMAL(15,2) NOT NULL,
    current_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
    monthly_sip      DECIMAL(10,2) DEFAULT 0,
    target_date      DATE NOT NULL,
    expected_return  DECIMAL(5,2)  DEFAULT 12.00,
    status           ENUM('active','completed','paused') DEFAULT 'active',
    notes            TEXT,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goal_holdings (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    goal_id       INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    asset_type    ENUM('mf','fd','nps','stock','crypto','other') NOT NULL,
    asset_id      INT UNSIGNED NOT NULL,
    allocated_pct DECIMAL(5,2) DEFAULT 100.00,
    notes         VARCHAR(200),
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_goal_asset (goal_id, asset_type, asset_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Net Worth Snapshots (t295, t396) ─────────────────────────
CREATE TABLE IF NOT EXISTS networth_snapshots (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    snapshot_date DATE NOT NULL,
    mf_value      DECIMAL(15,2) DEFAULT 0,
    fd_value      DECIMAL(15,2) DEFAULT 0,
    nps_value     DECIMAL(15,2) DEFAULT 0,
    stock_value   DECIMAL(15,2) DEFAULT 0,
    crypto_value  DECIMAL(15,2) DEFAULT 0,
    other_value   DECIMAL(15,2) DEFAULT 0,
    total_value   DECIMAL(15,2) NOT NULL,
    UNIQUE KEY uk_user_date (user_id, snapshot_date),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Crypto Holdings & Transactions (t24, t315-317) ───────────
CREATE TABLE IF NOT EXISTS crypto_holdings (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    coin_id          VARCHAR(60)  NOT NULL,
    coin_symbol      VARCHAR(20)  NOT NULL,
    coin_name        VARCHAR(80)  NOT NULL,
    quantity         DECIMAL(24,8) NOT NULL,
    avg_buy_price    DECIMAL(20,2) NOT NULL,
    current_price    DECIMAL(20,2) DEFAULT 0,
    current_value_inr DECIMAL(15,2) DEFAULT 0,
    invested_inr     DECIMAL(15,2) DEFAULT 0,
    exchange         VARCHAR(60)  DEFAULT NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    price_updated_at DATETIME DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_coin_exchange (user_id, coin_id, exchange),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crypto_transactions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    holding_id   INT UNSIGNED NOT NULL,
    tx_type      ENUM('buy','sell','transfer_in','transfer_out','reward') NOT NULL,
    quantity     DECIMAL(24,8) NOT NULL,
    price_inr    DECIMAL(20,2) NOT NULL,
    fee_inr      DECIMAL(10,2) DEFAULT 0,
    total_inr    DECIMAL(15,2) NOT NULL,
    tds_deducted DECIMAL(10,2) DEFAULT 0,
    exchange     VARCHAR(60)  DEFAULT NULL,
    tx_date      DATE NOT NULL,
    notes        VARCHAR(200) DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user    (user_id),
    INDEX idx_holding (holding_id),
    INDEX idx_date    (tx_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Notifications table (used by fd_alerts) ──────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(60)  NOT NULL,
    title      VARCHAR(200) NOT NULL,
    body       TEXT,
    link       VARCHAR(300) DEFAULT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_read   (is_read),
    INDEX idx_type   (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── FD Interest Log (used by capital gains) ──────────────────
CREATE TABLE IF NOT EXISTS fd_interest_log (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    fd_id        INT UNSIGNED NOT NULL,
    credit_date  DATE NOT NULL,
    interest_earned DECIMAL(12,2) NOT NULL,
    tds_deducted DECIMAL(10,2) DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_date (credit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── app_settings: new keys ───────────────────────────────────
INSERT IGNORE INTO app_settings (setting_key, setting_val) VALUES
  ('session_idle_minutes', '30'),
  ('2fa_required',         '0'),
  ('crypto_auto_refresh',  '1');

-- ============================================================
-- Done! All tables created. Run php artisan migrate or import
-- this file via phpMyAdmin / mysql CLI.
-- ============================================================
