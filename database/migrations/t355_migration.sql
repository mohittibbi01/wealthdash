-- ============================================================
-- WealthDash — Migration t355: Goal Buckets Custom Strategy UI
-- Task: bucket_strategy_save / bucket_strategy_load
-- Run ONCE — idempotent
-- ============================================================

-- 1. Portfolio-level key-value settings table
--    (used by bucket_strategy.php for saving bucket target %)
CREATE TABLE IF NOT EXISTS `user_kv_settings` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED DEFAULT NULL,
  `setting_key`  varchar(80)      NOT NULL,
  `setting_value` text            DEFAULT NULL,
  `updated_at`   datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kv_user_portfolio_key` (`user_id`, `portfolio_id`, `setting_key`),
  KEY `idx_kv_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bucket strategy presets — user can save named presets
CREATE TABLE IF NOT EXISTS `bucket_strategy_presets` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `preset_name`  varchar(100)     NOT NULL DEFAULT 'My Strategy',
  `bucket1_pct`  tinyint UNSIGNED NOT NULL DEFAULT 10 COMMENT 'Safety  0-2yr %',
  `bucket2_pct`  tinyint UNSIGNED NOT NULL DEFAULT 20 COMMENT 'Stable  2-5yr %',
  `bucket3_pct`  tinyint UNSIGNED NOT NULL DEFAULT 70 COMMENT 'Growth  5yr+  %',
  `is_active`    tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`   datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bsp_portfolio` (`portfolio_id`),
  KEY `idx_bsp_user`      (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't355 Bucket Strategy migration complete ✅' AS status;
