-- ============================================================
-- WealthDash — Migration t203: PPF Annual Deposit Tracker
-- Replaces app_settings key-value hack with proper table
-- ============================================================

-- 1. Dedicated PPF FY deposit tracking table
CREATE TABLE IF NOT EXISTS `ppf_fy_deposits` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ppf_scheme_id`   int(10) UNSIGNED NOT NULL,
  `fy_year`         int(4) UNSIGNED  NOT NULL,
  `total_deposited` decimal(10,2)    NOT NULL DEFAULT 0.00,
  `entries`         longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                    CHECK (json_valid(`entries`)),
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ppf_fy` (`ppf_scheme_id`, `fy_year`),
  KEY `idx_ppf_fy_scheme` (`ppf_scheme_id`),
  CONSTRAINT `fk_ppf_fy_scheme`
    FOREIGN KEY (`ppf_scheme_id`) REFERENCES `po_schemes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Migrate existing app_settings data (ppf_fy_deposit_{id}_{year}) → new table
INSERT IGNORE INTO `ppf_fy_deposits` (ppf_scheme_id, fy_year, total_deposited, entries)
SELECT
    CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(setting_key, '_', -2), '_', 1) AS UNSIGNED) AS ppf_scheme_id,
    CAST(SUBSTRING_INDEX(setting_key, '_', -1) AS UNSIGNED) AS fy_year,
    CAST(setting_val AS DECIMAL(10,2)) AS total_deposited,
    '[]'
FROM app_settings
WHERE setting_key LIKE 'ppf\_fy\_deposit\_%'
  AND setting_val REGEXP '^[0-9]+(\.[0-9]+)?$'
  AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(setting_key, '_', -2), '_', 1) AS UNSIGNED) > 0;

-- 3. Clean up old keys from app_settings (optional — won't break anything if left)
DELETE FROM `app_settings` WHERE setting_key LIKE 'ppf\_fy\_deposit\_%';

SELECT 't203 PPF Annual Deposit Tracker migration complete' AS status;
