-- WealthDash t05: Admin NAV Auto-Update — app_settings rows
-- Schema: setting_key | setting_val | updated_by | updated_at

INSERT INTO app_settings (`setting_key`, `setting_val`)
VALUES
  ('nav_auto_enabled',            '1'),
  ('nav_auto_time',               '22:00'),
  ('nav_auto_update_last_run',    NULL),
  ('nav_auto_update_last_status', NULL)
ON DUPLICATE KEY UPDATE
  `updated_at` = CURRENT_TIMESTAMP;
