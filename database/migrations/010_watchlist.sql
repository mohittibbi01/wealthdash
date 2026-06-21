-- ============================================================
-- Migration 010: Watchlist + Watchlist Alerts
-- Tasks: t68, tv10, t405
-- ============================================================

CREATE TABLE IF NOT EXISTS mf_watchlist (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    fund_id     INT UNSIGNED NOT NULL,
    notes       VARCHAR(300) DEFAULT NULL,
    added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_fund (user_id, fund_id),
    INDEX idx_user (user_id),
    INDEX idx_fund (fund_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mf_watchlist_alerts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    fund_id         INT UNSIGNED NOT NULL,
    alert_type      ENUM('nav_above','nav_below','return_1y_above','return_3y_above',
                         'sharpe_above','aum_above','expense_below','multi_condition') NOT NULL,
    target_value    DECIMAL(12,4)  DEFAULT NULL,
    conditions_json TEXT           DEFAULT NULL COMMENT 'JSON for multi_condition type',
    is_active       TINYINT(1)     NOT NULL DEFAULT 1,
    last_triggered  DATETIME       DEFAULT NULL,
    snooze_until    DATETIME       DEFAULT NULL,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_fund   (fund_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mf_alert_history (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_id    INT UNSIGNED    NOT NULL,
    user_id     INT UNSIGNED    NOT NULL,
    fund_id     INT UNSIGNED    NOT NULL,
    trigger_val DECIMAL(12,4)   DEFAULT NULL,
    triggered_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alert  (alert_id),
    INDEX idx_user   (user_id),
    INDEX idx_date   (triggered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
