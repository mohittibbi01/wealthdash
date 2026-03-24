-- ============================================================
-- WealthDash: Peak NAV Tracker Setup
-- Dusre PC pe bhi safely run kar sakte ho — idempotent hai
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

-- 2. Seed from funds table (ALL active schemes)
INSERT IGNORE INTO mf_peak_progress (scheme_code, status)
SELECT scheme_code, 'pending'
FROM funds
WHERE is_active = 1;

-- 3. Agar koi active fund bhi ho jo is_active = 0 ho lekin
--    funds table mein hai — usse bhi include karo (optional safety net)
INSERT IGNORE INTO mf_peak_progress (scheme_code, status)
SELECT scheme_code, 'pending'
FROM funds
WHERE scheme_code NOT IN (SELECT scheme_code FROM mf_peak_progress);

-- 4. app_settings mein required keys insert karo
--    ON DUPLICATE KEY — agar already hai to change mat karo
INSERT INTO app_settings (setting_key, setting_val)
VALUES ('peak_nav_batch_date', '2001-01-01')
ON DUPLICATE KEY UPDATE setting_val = setting_val;

INSERT INTO app_settings (setting_key, setting_val)
VALUES ('peak_nav_stop', '0')
ON DUPLICATE KEY UPDATE setting_val = setting_val;

INSERT INTO app_settings (setting_key, setting_val)
VALUES ('peak_nav_status', 'idle')
ON DUPLICATE KEY UPDATE setting_val = setting_val;

-- 5. Confirm — yahan total dikhega
SELECT
    CONCAT('mf_peak_progress mein total schemes: ', COUNT(*))      AS result
FROM mf_peak_progress

UNION ALL

SELECT
    CONCAT('funds table mein active schemes:     ', COUNT(*))
FROM funds
WHERE is_active = 1

UNION ALL

SELECT
    CONCAT('funds table mein ALL schemes:        ', COUNT(*))
FROM funds;
