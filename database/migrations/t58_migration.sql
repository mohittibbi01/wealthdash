-- WealthDash — t58: AI Portfolio Advisor Migration
CREATE TABLE IF NOT EXISTS ai_advisor_sessions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  session_type  ENUM('full_review','follow_up') NOT NULL DEFAULT 'full_review',
  response_json JSON         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, session_type, created_at),
  CONSTRAINT fk_aas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals, goal_checkins (tg005),
--             finance_profiles (ti001), insurance_policies (t122), loans (t123)
