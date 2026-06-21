-- WealthDash — t386: 2FA TOTP Migration
-- Run once on existing DB

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS totp_enabled        TINYINT(1)   NOT NULL DEFAULT 0         AFTER password_hash,
  ADD COLUMN IF NOT EXISTS totp_secret         VARCHAR(64)  NULL     DEFAULT NULL       AFTER totp_enabled,
  ADD COLUMN IF NOT EXISTS totp_secret_pending VARCHAR(64)  NULL     DEFAULT NULL       AFTER totp_secret,
  ADD COLUMN IF NOT EXISTS totp_backup_codes   TEXT         NULL     DEFAULT NULL       AFTER totp_secret_pending,
  ADD COLUMN IF NOT EXISTS totp_setup_at       DATETIME     NULL     DEFAULT NULL       AFTER totp_backup_codes;

-- Audit log entries for 2FA events (if audit_log table exists)
-- Assumes: audit_log(id, user_id, action, detail, created_at)
-- No schema change needed — uses existing audit_log table.

-- Index for fast lookup
CREATE INDEX IF NOT EXISTS idx_users_totp_enabled ON users(totp_enabled);
