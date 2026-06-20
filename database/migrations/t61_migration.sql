-- WealthDash — t61: AI Goal-based Planning Migration
CREATE TABLE IF NOT EXISTS goal_plan_meta (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  goal_id          INT UNSIGNED NOT NULL UNIQUE,
  user_id          INT UNSIGNED NOT NULL,
  priority         ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  existing_corpus  DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_gpm_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
  CONSTRAINT fk_gpm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: goals, goal_checkins (tg005_migration.sql)
