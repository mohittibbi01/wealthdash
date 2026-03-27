-- ============================================================
-- WealthDash — Migration 014: NPS NAV History
-- Task t99: Store daily NAV history for NPS schemes
-- ============================================================

CREATE TABLE IF NOT EXISTS `nps_nav_history` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scheme_id`  INT UNSIGNED NOT NULL,
  `nav_date`   DATE NOT NULL,
  `nav`        DECIMAL(12, 4) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nps_nav` (`scheme_id`, `nav_date`),
  KEY `idx_nps_nav_date` (`nav_date`),
  KEY `idx_nps_nav_scheme` (`scheme_id`),
  CONSTRAINT `fk_nps_nav_scheme`
    FOREIGN KEY (`scheme_id`) REFERENCES `nps_schemes` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- nps_schemes mein 1Y/3Y/5Y return columns add karo
-- ============================================================
ALTER TABLE `nps_schemes`
  ADD COLUMN IF NOT EXISTS `return_1y`              DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `return_3y`              DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `return_5y`              DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `return_since`           DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `aum_cr`                 DECIMAL(14,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `nav_returns_updated_at` DATETIME DEFAULT NULL;

-- ============================================================
-- Admin panel: NPS settings (NOTE: column is `setting_val` not `setting_value`)
-- ============================================================
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_val`)
VALUES
  ('nps_nav_auto_update',  '1'),
  ('nps_nav_source',       'pfrda_api'),
  ('nps_historical_years', '5'),
  ('nps_nav_last_run',     NULL),
  ('nps_nav_last_status',  'never_run');
