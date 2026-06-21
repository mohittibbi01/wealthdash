-- WealthDash — t406: Anonymous Benchmarking Migration

CREATE TABLE IF NOT EXISTS benchmark_opt_ins (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL UNIQUE,
  opted_in    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_boi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IMPORTANT: This table stores NO personally identifiable information.
-- No fund names, no exact amounts beyond ratios/percentages, no names/emails.
-- user_id is only used to upsert the user's OWN row (never exposed in queries).
CREATE TABLE IF NOT EXISTS benchmark_snapshots (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id                 INT UNSIGNED NOT NULL UNIQUE,
  age_bracket             VARCHAR(10)  NOT NULL COMMENT '18-24, 25-34, 35-44, 45-54, 55+',
  risk_profile            VARCHAR(30)  NOT NULL,
  savings_rate            DECIMAL(6,2) NULL COMMENT 'percent',
  gain_pct                DECIMAL(7,2) NULL COMMENT 'percent',
  sip_count               SMALLINT     NULL,
  monthly_sip_pct_income  DECIMAL(6,2) NULL COMMENT 'percent',
  num_holdings            SMALLINT     NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cohort (age_bracket, risk_profile),
  CONSTRAINT fk_bs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: finance_profiles (ti001), mf_holdings, mf_nav_latest, mf_sips,
--             budget_actuals (t471)
