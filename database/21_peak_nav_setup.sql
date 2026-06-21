-- ============================================================
-- WealthDash: Peak NAV Tracker Setup
-- Run ONCE in phpMyAdmin
-- ============================================================

-- 1. Drop and recreate progress table with correct collation
DROP TABLE IF EXISTS mf_peak_progress;

CREATE TABLE mf_peak_progress (
    scheme_code          VARCHAR(20)  NOT NULL,
    last_processed_date  DATE         DEFAULT NULL,
    status               ENUM('pending','in_progress','completed','error') DEFAULT 'pending',
    error_message        TEXT         DEFAULT NULL,
    updated_at           DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scheme_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seed from funds table
INSERT IGNORE INTO mf_peak_progress (scheme_code, status)
SELECT scheme_code, 'pending'
FROM funds
WHERE is_active = 1;

-- 3. Insert batch date tracker into app_settings (correct column names)
INSERT INTO app_settings (setting_key, setting_val)
VALUES ('peak_nav_batch_date', '2001-01-01')
ON DUPLICATE KEY UPDATE setting_val = setting_val;

-- 4. Confirm
SELECT CONCAT('Seeded: ', COUNT(*), ' schemes') AS result FROM mf_peak_progress;
