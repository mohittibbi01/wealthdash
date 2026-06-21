-- WealthDash — t387: Session Security Migration
CREATE TABLE IF NOT EXISTS user_sessions (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NOT NULL,
  session_id   VARCHAR(128)  NOT NULL UNIQUE,
  ip_address   VARCHAR(45)   NULL,
  user_agent   VARCHAR(255)  NULL,
  device_label VARCHAR(100)  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  KEY idx_last_active (last_active),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
