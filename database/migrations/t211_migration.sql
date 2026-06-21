-- ============================================================
-- WealthDash Migration: t211 — Admin DB Backup & Restore
-- ============================================================
-- Run: php artisan migrate OR paste in phpMyAdmin
-- ============================================================

CREATE TABLE IF NOT EXISTS `db_backups` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `filepath` varchar(500) NOT NULL COMMENT 'Relative path from APP_ROOT/backups/',
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bytes',
  `tables_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `rows_count` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  `notes` varchar(500) DEFAULT NULL,
  `error_msg` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dbbackup_status` (`status`),
  KEY `idx_dbbackup_created` (`created_at`),
  CONSTRAINT `fk_dbbackup_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `db_restore_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `backup_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL if manual upload',
  `filename` varchar(255) NOT NULL,
  `status` enum('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  `tables_restored` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `error_msg` text DEFAULT NULL,
  `restored_by` int(10) UNSIGNED NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dbrestore_status` (`status`),
  KEY `idx_dbrestore_started` (`started_at`),
  CONSTRAINT `fk_dbrestore_user` FOREIGN KEY (`restored_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
