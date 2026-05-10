-- ============================================================
-- WealthDash — Migration tg001: Monte Carlo Simulations
-- Task: tg001 — Monte Carlo Goal Probability Simulator
-- ============================================================

CREATE TABLE IF NOT EXISTS `mc_simulations` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`         INT UNSIGNED NOT NULL,
  `goal_id`              INT UNSIGNED DEFAULT NULL COMMENT 'Optional link to investment_goals',
  `label`                VARCHAR(150) NOT NULL DEFAULT 'Simulation',
  `target_amount`        DECIMAL(16,2) NOT NULL,
  `current_saved`        DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `monthly_contrib`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `annual_return`        DECIMAL(6,2)  NOT NULL COMMENT 'Expected annual return %',
  `annual_volatility`    DECIMAL(6,2)  NOT NULL COMMENT 'Annual std dev %',
  `months`               SMALLINT UNSIGNED NOT NULL,
  `iterations`           INT UNSIGNED  NOT NULL DEFAULT 5000,
  `inflation_pct`        DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `sip_stepup_pct`       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `success_probability`  DECIMAL(5,1)  NOT NULL COMMENT '% of simulations that hit target',
  `p10`                  DECIMAL(16,2) DEFAULT NULL COMMENT '10th percentile final value',
  `p50`                  DECIMAL(16,2) DEFAULT NULL COMMENT 'Median final value',
  `p90`                  DECIMAL(16,2) DEFAULT NULL COMMENT '90th percentile final value',
  `result_json`          LONGTEXT      DEFAULT NULL COMMENT 'Full result including fan chart data',
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_mc_portfolio` (`portfolio_id`),
  INDEX `idx_mc_goal`      (`goal_id`),
  INDEX `idx_mc_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Monte Carlo goal probability simulation results — tg001';

SELECT 'mc_simulations table created — tg001' AS status;
