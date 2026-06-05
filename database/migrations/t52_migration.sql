-- WealthDash — t52: Global Settings Migration

CREATE TABLE IF NOT EXISTS app_settings (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(80)   NOT NULL UNIQUE,
  setting_val TEXT          NOT NULL DEFAULT '',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed defaults
INSERT IGNORE INTO app_settings (setting_key, setting_val) VALUES
  ('app_name',             'WealthDash'),
  ('maintenance_mode',     '0'),
  ('registration_open',    '1'),
  ('default_theme',        'light'),
  ('items_per_page',       '25'),
  ('session_timeout_min',  '120'),
  ('max_login_attempts',   '5'),
  ('lockout_duration_min', '15'),
  ('require_2fa_admin',    '0'),
  ('password_min_length',  '8'),
  ('mf_nav_update_time',   '22:00'),
  ('api_rate_limit_rpm',   '60'),
  ('cron_enabled',         '1'),
  ('cache_ttl_seconds',    '300'),
  ('enable_ai_features',   '1'),
  ('email_enabled',        '0'),
  ('smtp_port',            '587'),
  ('smtp_from_name',       'WealthDash'),
  ('sip_reminder_enabled', '1');
