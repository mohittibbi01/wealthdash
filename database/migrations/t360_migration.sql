-- WealthDash — t360: Life Events Calendar Migration
CREATE TABLE IF NOT EXISTS life_events (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  event_name       VARCHAR(150) NOT NULL,
  event_type       ENUM('milestone','personal','career','family','goal') NOT NULL DEFAULT 'milestone',
  event_date       DATE         NOT NULL,
  financial_impact VARCHAR(255) NULL,
  notes            TEXT         NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, event_date),
  CONSTRAINT fk_le_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
