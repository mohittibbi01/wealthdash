-- WealthDash — t358: Goal Notification Engine Migration
CREATE TABLE IF NOT EXISTS goal_notifications (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  NOT NULL,
  goal_id       INT UNSIGNED  NOT NULL,
  milestone_pct SMALLINT      NOT NULL COMMENT 'Positive = % achieved, Negative = days left',
  message       VARCHAR(255)  NOT NULL,
  emoji         VARCHAR(8)    NULL,
  is_read       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_goal_milestone (user_id, goal_id, milestone_pct),
  KEY idx_user_read (user_id, is_read),
  CONSTRAINT fk_gn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_gn_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
