-- WealthDash — t385: AI Goal Coach Migration
CREATE TABLE IF NOT EXISTS ai_goal_coach_nudges (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  nudge_date  DATE         NOT NULL,
  nudges_json JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_date (user_id, nudge_date),
  CONSTRAINT fk_gcn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: goals, goal_checkins (tg005), mf_sips (existing)
