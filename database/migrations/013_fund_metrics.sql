-- ============================================================
-- Migration 013: Fund Risk Metrics + Rolling Returns
-- Tasks: t93, t94, t95, t98, t262-t270
-- ============================================================

CREATE TABLE IF NOT EXISTS fund_rolling_returns (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fund_id               INT UNSIGNED NOT NULL,
    period                ENUM('1Y','3Y','5Y') NOT NULL,
    min_return            DECIMAL(8,4) DEFAULT NULL,
    max_return            DECIMAL(8,4) DEFAULT NULL,
    avg_return            DECIMAL(8,4) DEFAULT NULL,
    median_return         DECIMAL(8,4) DEFAULT NULL,
    positive_periods_pct  DECIMAL(5,2) DEFAULT NULL COMMENT '% of windows with +ve return',
    total_windows         INT UNSIGNED DEFAULT NULL,
    calculated_date       DATE         NOT NULL,
    UNIQUE KEY uk_fund_period_date (fund_id, period, calculated_date),
    INDEX idx_fund (fund_id),
    INDEX idx_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fund_benchmark_map (
    fund_id        INT UNSIGNED NOT NULL PRIMARY KEY,
    benchmark_code VARCHAR(30)  NOT NULL COMMENT 'e.g. NIFTY50, NIFTYMIDCAP150',
    benchmark_name VARCHAR(80)  NOT NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_benchmark (benchmark_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS benchmark_nav (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(30) NOT NULL,
    nav_date   DATE        NOT NULL,
    nav_value  DECIMAL(12,4) NOT NULL,
    UNIQUE KEY uk_code_date (code, nav_date),
    INDEX idx_code (code),
    INDEX idx_date (nav_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fund manager tenure tracking
CREATE TABLE IF NOT EXISTS fund_managers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fund_id      INT UNSIGNED NOT NULL,
    manager_name VARCHAR(100) NOT NULL,
    since_date   DATE         DEFAULT NULL,
    is_current   TINYINT(1)   NOT NULL DEFAULT 1,
    added_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fund (fund_id),
    INDEX idx_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE funds
  ADD COLUMN IF NOT EXISTS inception_date  DATE         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS manager_name    VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS manager_since   DATE         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rating_stars    TINYINT(1)   DEFAULT NULL COMMENT '1-5 WealthDash stars',
  ADD COLUMN IF NOT EXISTS health_score    TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
  ADD COLUMN IF NOT EXISTS style_size      ENUM('large','mid','small','debt','hybrid') DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS style_value     ENUM('value','blend','growth','na')         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS ter_history_json TEXT         DEFAULT NULL COMMENT 'Last 4 quarters TER';
