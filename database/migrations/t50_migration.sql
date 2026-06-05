-- WealthDash — t50: Multi-User Management Migration
-- Ensure users table has required columns

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role          ENUM('user','admin') NOT NULL DEFAULT 'user'      AFTER email,
  ADD COLUMN IF NOT EXISTS status        ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active' AFTER role,
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL                                     AFTER status,
  ADD COLUMN IF NOT EXISTS theme         ENUM('light','dark') NOT NULL DEFAULT 'light'     AFTER last_login_at;

-- audit_log table (if not exists)
CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(80)  NOT NULL,
  detail     TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_action (user_id, action),
  KEY idx_created_at  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for user lookups
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);
CREATE INDEX IF NOT EXISTS idx_users_role   ON users(role);
