-- WealthDash — t389: GDPR-style Data Controls Migration
CREATE TABLE IF NOT EXISTS data_export_requests (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  filename    VARCHAR(255)  NOT NULL,
  file_size   INT UNSIGNED  NOT NULL DEFAULT 0,
  status      ENUM('processing','ready','expired') NOT NULL DEFAULT 'ready',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME      NOT NULL,
  KEY idx_user (user_id),
  CONSTRAINT fk_der_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_deletion_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  status        ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  requested_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  scheduled_for DATETIME     NOT NULL,
  cancelled_at  DATETIME     NULL,
  completed_at  DATETIME     NULL,
  KEY idx_user_status (user_id, status),
  KEY idx_scheduled (scheduled_for),
  CONSTRAINT fk_adr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: storage/exports/ directory must be writable by PHP
-- and should NOT be web-accessible directly (serve via download action only).
-- Add to .gitignore: storage/exports/*.json
