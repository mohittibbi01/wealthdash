-- ============================================================
-- WealthDash — t335: API for External Tools
-- Migration: database/migrations/t335_migration.sql
-- Worker: ID-M
-- NOTE: api_key_manager already exists (partial). This migration
--       adds external REST API tables + scopes.
-- ============================================================

-- External API keys (for third-party/personal tool access)
CREATE TABLE IF NOT EXISTS `external_api_keys` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `name`          VARCHAR(80)     NOT NULL COMMENT 'e.g. My Excel Sheet, HomeAssistant',
    `key_prefix`    VARCHAR(12)     NOT NULL COMMENT 'Public prefix e.g. wdx_ab12cd',
    `key_hash`      VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash of full key',
    `scopes`        JSON            NOT NULL COMMENT 'Array of allowed scopes',
    `rate_limit`    SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Requests per minute',
    `last_used_at`  DATETIME        DEFAULT NULL,
    `last_ip`       VARCHAR(45)     DEFAULT NULL,
    `use_count`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`    DATE            DEFAULT NULL COMMENT 'NULL = never',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_eak_prefix` (`key_prefix`),
    KEY `idx_eak_user`   (`user_id`),
    KEY `idx_eak_active` (`is_active`),
    CONSTRAINT `fk_eak_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-key rate limit counters (minute window)
CREATE TABLE IF NOT EXISTS `external_api_rate` (
    `key_id`     INT UNSIGNED  NOT NULL,
    `window`     DATETIME      NOT NULL COMMENT 'Truncated to minute',
    `hits`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`key_id`, `window`),
    CONSTRAINT `fk_ear_key` FOREIGN KEY (`key_id`) REFERENCES `external_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API access log (sampled)
CREATE TABLE IF NOT EXISTS `external_api_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key_id`     INT UNSIGNED    NOT NULL,
    `endpoint`   VARCHAR(120)    NOT NULL,
    `method`     VARCHAR(8)      NOT NULL DEFAULT 'GET',
    `status`     SMALLINT        NOT NULL DEFAULT 200,
    `duration_ms`FLOAT           DEFAULT NULL,
    `ip_address` VARCHAR(45)     DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_eal_key`     (`key_id`),
    KEY `idx_eal_created` (`created_at`),
    CONSTRAINT `fk_eal_key` FOREIGN KEY (`key_id`) REFERENCES `external_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
