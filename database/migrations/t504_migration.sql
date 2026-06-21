-- ============================================================
-- WealthDash Migration: t504 — WealthDash REST API
-- ============================================================

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `key_name` varchar(100) NOT NULL COMMENT 'e.g. My App, Home Automation',
  `api_key` varchar(64) NOT NULL COMMENT 'SHA-256 hex — shown only once at creation',
  `key_prefix` varchar(8) NOT NULL COMMENT 'First 8 chars — shown in list for identification',
  `scopes` varchar(500) NOT NULL DEFAULT 'portfolio:read' COMMENT 'CSV of allowed scopes',
  `rate_limit` int(10) UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Requests per minute',
  `last_used_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = never expires',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_key` (`api_key`),
  KEY `idx_apikey_user` (`user_id`),
  KEY `idx_apikey_prefix` (`key_prefix`),
  CONSTRAINT `fk_apikey_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_request_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `method` varchar(10) NOT NULL,
  `endpoint` varchar(200) NOT NULL,
  `status_code` smallint(5) UNSIGNED NOT NULL DEFAULT 200,
  `response_ms` int(10) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_apirl_key` (`api_key_id`),
  KEY `idx_apirl_user` (`user_id`),
  KEY `idx_apirl_req` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_rate_limit` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_key_id` int(10) UNSIGNED NOT NULL,
  `window_start` datetime NOT NULL,
  `request_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rl_key_window` (`api_key_id`, `window_start`),
  KEY `idx_rl_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
