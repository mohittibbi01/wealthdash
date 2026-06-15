-- WealthDash — t443: Morning Briefing Migration
CREATE TABLE IF NOT EXISTS morning_briefings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  briefing_date  DATE         NOT NULL,
  briefing_json  JSON         NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_date (user_id, briefing_date),
  CONSTRAINT fk_mb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_holdings, mf_nav_latest, mf_sips, smart_alerts (t404)
