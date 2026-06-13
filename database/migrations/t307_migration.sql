-- ============================================================
-- WealthDash — t307: Audit Log — User Action History
-- Migration: database/migrations/t307_migration.sql
-- Worker: ID-M
-- NOTE: audit_log table already exists in 01_schema_complete.sql
--       This migration adds missing columns + indexes only
-- ============================================================

-- Add missing columns to audit_log (safe ALTER)
ALTER TABLE `audit_log`
    ADD COLUMN IF NOT EXISTS `user_agent`    VARCHAR(300) DEFAULT NULL AFTER `ip_address`,
    ADD COLUMN IF NOT EXISTS `session_id`    VARCHAR(64)  DEFAULT NULL AFTER `user_agent`,
    ADD COLUMN IF NOT EXISTS `severity`      ENUM('info','warning','critical') NOT NULL DEFAULT 'info' AFTER `session_id`,
    ADD COLUMN IF NOT EXISTS `request_method` VARCHAR(10) DEFAULT NULL AFTER `severity`,
    ADD COLUMN IF NOT EXISTS `request_uri`   VARCHAR(500) DEFAULT NULL AFTER `request_method`;

-- Add missing index on severity
ALTER TABLE `audit_log`
    ADD INDEX IF NOT EXISTS `idx_audit_severity` (`severity`),
    ADD INDEX IF NOT EXISTS `idx_audit_action`   (`action`);

-- Audit log retention policy table
CREATE TABLE IF NOT EXISTS `audit_retention_config` (
    `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `retention_days`   INT UNSIGNED NOT NULL DEFAULT 365,
    `auto_purge`       TINYINT(1) NOT NULL DEFAULT 0,
    `last_purge_at`    DATETIME DEFAULT NULL,
    `rows_purged`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `audit_retention_config` (`id`, `retention_days`, `auto_purge`)
VALUES (1, 365, 0)
ON DUPLICATE KEY UPDATE `id` = 1;
