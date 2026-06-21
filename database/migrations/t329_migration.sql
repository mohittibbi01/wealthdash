-- WealthDash — t329: AI Weekly Digest Migration
CREATE TABLE IF NOT EXISTS ai_weekly_digests (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  week_key    VARCHAR(8)   NOT NULL COMMENT 'ISO week: YYYY-WW',
  digest_json JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_week (user_id, week_key),
  KEY idx_user_id (user_id),
  CONSTRAINT fk_wd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
