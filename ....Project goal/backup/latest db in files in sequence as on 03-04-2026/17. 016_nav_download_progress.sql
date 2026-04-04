-- ============================================================
-- WealthDash — Migration 016: NAV Download Progress (FIXED v2)
-- Task: t191 — nav_download_progress table recreate + seed
-- ============================================================
-- FIX v2: Table pehle se incomplete structure se exist kar raha tha
--         (total_records column missing tha). Isliye DROP karke
--         sahi structure se recreate kiya gaya hai.
--
-- INSTALL:
--   phpMyAdmin → wealthdash DB → SQL tab → paste → Go
--   YA: mysql -u root -p wealthdash < 016_nav_download_progress.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ────────────────────────────────────────────────────────────
-- Step 1: Purana incomplete table drop karo
-- ────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `nav_download_progress`;

-- ────────────────────────────────────────────────────────────
-- Step 2: Sahi structure se recreate karo
-- ────────────────────────────────────────────────────────────
CREATE TABLE `nav_download_progress` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id`       int(10) UNSIGNED NOT NULL,
  `scheme_code`   varchar(20)      NOT NULL,
  `status`        enum('pending','downloading','done','error','needs_update')
                  NOT NULL DEFAULT 'pending',
  `total_records` int(11)          NOT NULL DEFAULT 0,
  `last_nav_date` date                      DEFAULT NULL,
  `error_message` text                      DEFAULT NULL,
  `updated_at`    datetime         NOT NULL DEFAULT current_timestamp()
                  ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ndp_fund`    (`fund_id`),
  KEY            `idx_ndp_status` (`status`),
  CONSTRAINT `fk_ndp_fund` FOREIGN KEY (`fund_id`)
    REFERENCES `funds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ────────────────────────────────────────────────────────────
-- Step 3: Sab active funds ko 'pending' ke saath seed karo
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO `nav_download_progress`
  (`fund_id`, `scheme_code`, `status`, `total_records`, `last_nav_date`)
SELECT
  f.`id`,
  f.`scheme_code`,
  'pending'  AS `status`,
  0          AS `total_records`,
  NULL       AS `last_nav_date`
FROM `funds` f
WHERE f.`is_active`    = 1
  AND f.`scheme_code` IS NOT NULL
  AND f.`scheme_code`  != '';

-- ────────────────────────────────────────────────────────────
-- Step 4: Jinke paas already NAV history hai → needs_update
-- ────────────────────────────────────────────────────────────
UPDATE `nav_download_progress` ndp
INNER JOIN (
  SELECT `fund_id`,
         COUNT(*)        AS nav_count,
         MAX(`nav_date`) AS last_date
  FROM   `nav_history`
  GROUP  BY `fund_id`
) nh ON nh.`fund_id` = ndp.`fund_id`
SET
  ndp.`status`        = 'needs_update',
  ndp.`total_records` = nh.`nav_count`,
  ndp.`last_nav_date` = nh.`last_date`
WHERE ndp.`status`   = 'pending'
  AND nh.`nav_count`  > 0;

-- ────────────────────────────────────────────────────────────
-- Step 5: app_settings mein batch tracking key add karo
-- ────────────────────────────────────────────────────────────
INSERT INTO `app_settings` (`setting_key`, `setting_val`)
VALUES ('nav_download_batch_date', '2001-01-01')
ON DUPLICATE KEY UPDATE `setting_val` = `setting_val`;

-- ────────────────────────────────────────────────────────────
-- Step 6: Verify — yeh result dikhega
-- ────────────────────────────────────────────────────────────
SELECT
  'nav_download_progress'           AS `table_name`,
  COUNT(*)                          AS total_funds,
  SUM(`status` = 'pending')         AS pending,
  SUM(`status` = 'needs_update')    AS needs_update,
  SUM(`status` = 'downloading')     AS downloading,
  SUM(`status` = 'done')            AS done,
  SUM(`status` = 'error')           AS error_count
FROM `nav_download_progress`;

-- ── Next step ────────────────────────────────────────────────
-- Seed ke baad nav_download/processor.php chalao NAV history
-- download karne ke liye. Ya admin panel → NAV Download section.
-- ─────────────────────────────────────────────────────────────
