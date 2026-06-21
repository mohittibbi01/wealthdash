-- WealthDash — t454: Onboarding Setup Wizard Migration
-- Extends user_onboarding table (from t240_migration.sql) with wizard columns.

ALTER TABLE user_onboarding
  ADD COLUMN IF NOT EXISTS wizard_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER skipped,
  ADD COLUMN IF NOT EXISTS wizard_data JSON NULL AFTER wizard_completed;

-- If user_onboarding table doesn't exist yet (t240 not run), create it fully:
CREATE TABLE IF NOT EXISTS user_onboarding (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL UNIQUE,
  completed_steps   JSON         NOT NULL DEFAULT ('[]'),
  skipped           TINYINT(1)   NOT NULL DEFAULT 0,
  wizard_completed  TINYINT(1)   NOT NULL DEFAULT 0,
  wizard_data       JSON         NULL,
  started_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ob_user_wizard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: finance_profiles (ti001), goals (existing)
