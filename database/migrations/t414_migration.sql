-- ============================================================
-- WealthDash — t414: Error Monitoring
-- Migration: database/migrations/t414_migration.sql
-- Worker: ID-M
-- ============================================================

CREATE TABLE IF NOT EXISTS `error_events` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fingerprint`  VARCHAR(64)     NOT NULL COMMENT 'SHA1 of type+file+line',
    `error_type`   VARCHAR(80)     NOT NULL COMMENT 'E_ERROR, E_WARNING, Exception, etc.',
    `message`      TEXT            NOT NULL,
    `file`         VARCHAR(300)    DEFAULT NULL,
    `line`         INT UNSIGNED    DEFAULT NULL,
    `stack_trace`  TEXT            DEFAULT NULL,
    `url`          VARCHAR(500)    DEFAULT NULL,
    `user_id`      INT UNSIGNED    DEFAULT NULL,
    `ip_address`   VARCHAR(45)     DEFAULT NULL,
    `user_agent`   VARCHAR(300)    DEFAULT NULL,
    `env`          VARCHAR(20)     DEFAULT NULL,
    `first_seen`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `count`        INT UNSIGNED    NOT NULL DEFAULT 1,
    `is_resolved`  TINYINT(1)      NOT NULL DEFAULT 0,
    `resolved_at`  DATETIME        DEFAULT NULL,
    `resolved_by`  INT UNSIGNED    DEFAULT NULL,
    `notes`        TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ee_fingerprint` (`fingerprint`),
    KEY `idx_ee_type`       (`error_type`),
    KEY `idx_ee_resolved`   (`is_resolved`),
    KEY `idx_ee_last_seen`  (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
