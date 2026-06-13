-- WealthDash — t333: AI Portfolio Report Card Migration
-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals, finance_profiles

-- ai_portfolio_reviews: stores monthly report cards (shared with portfolio_review.php)
CREATE TABLE IF NOT EXISTS ai_portfolio_reviews (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NOT NULL,
  review_month VARCHAR(7)    NOT NULL COMMENT 'YYYY-MM',
  review_type  VARCHAR(30)   NOT NULL DEFAULT 'report_card',
  grade        VARCHAR(3)    NULL,
  score        TINYINT UNSIGNED NULL,
  summary      TEXT          NULL,
  strengths    JSON          NULL,
  weaknesses   JSON          NULL,
  actions      JSON          NULL,
  raw_response JSON          NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_month_type (user_id, review_month, review_type),
  KEY idx_user (user_id),
  CONSTRAINT fk_apr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- t331: no separate migration needed (reads mf_sips + finance_profiles)
-- t332: rate_limit_buckets already created in t332_migration.sql
