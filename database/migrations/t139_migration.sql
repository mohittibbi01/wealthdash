-- ============================================================
-- WealthDash — Migration t139: Goal-Based Buckets
-- Retirement · Education · Emergency
-- ============================================================

-- 1. Ensure goal_buckets table exists (for older installs)
CREATE TABLE IF NOT EXISTS `goal_buckets` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        int(10) UNSIGNED NOT NULL,
  `name`           varchar(150) NOT NULL,
  `emoji`          varchar(10)  NOT NULL DEFAULT '🎯',
  `color`          varchar(7)   NOT NULL DEFAULT '#6366f1',
  `target_amount`  decimal(16,2) NOT NULL DEFAULT 0.00,
  `target_date`    date DEFAULT NULL,
  `notes`          text DEFAULT NULL,
  `is_achieved`    tinyint(1) NOT NULL DEFAULT 0,
  `achieved_at`    date DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gb_user` (`user_id`),
  CONSTRAINT `fk_gb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add bucket_type column (safe — IF NOT EXISTS via ALTER IGNORE pattern)
ALTER TABLE `goal_buckets`
  ADD COLUMN IF NOT EXISTS `bucket_type`      enum('retirement','education','emergency','house','vehicle','travel','wedding','custom')
                                              NOT NULL DEFAULT 'custom'
                                              AFTER `name`,
  ADD COLUMN IF NOT EXISTS `monthly_target`   decimal(14,2) NOT NULL DEFAULT 0.00
                                              AFTER `target_amount`,
  ADD COLUMN IF NOT EXISTS `current_amount`   decimal(16,2) NOT NULL DEFAULT 0.00
                                              AFTER `monthly_target`,
  ADD COLUMN IF NOT EXISTS `risk_profile`     enum('conservative','moderate','aggressive')
                                              NOT NULL DEFAULT 'moderate'
                                              AFTER `current_amount`,
  ADD COLUMN IF NOT EXISTS `priority`         enum('high','medium','low') NOT NULL DEFAULT 'medium'
                                              AFTER `risk_profile`;

-- 3. Ensure goal_fund_links table exists
CREATE TABLE IF NOT EXISTS `goal_fund_links` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `goal_id`    int(10) UNSIGNED NOT NULL,
  `fund_id`    int(10) UNSIGNED DEFAULT NULL,
  `sip_id`     int(10) UNSIGNED DEFAULT NULL,
  `link_type`  enum('holding','sip') NOT NULL DEFAULT 'holding',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gfl_goal` (`goal_id`),
  CONSTRAINT `fk_gfl_goal` FOREIGN KEY (`goal_id`) REFERENCES `goal_buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bucket contributions log
CREATE TABLE IF NOT EXISTS `goal_bucket_contributions` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket_id`           int(10) UNSIGNED NOT NULL,
  `amount`              decimal(14,2) NOT NULL,
  `contribution_date`   date NOT NULL,
  `note`                varchar(255) DEFAULT NULL,
  `created_at`          datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gbc_bucket` (`bucket_id`),
  CONSTRAINT `fk_gbc_bucket` FOREIGN KEY (`bucket_id`) REFERENCES `goal_buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seed default bucket types for existing users (optional — skip if table already has rows)
-- INSERT INTO `goal_buckets` ... (not seeded; UI lets users create their own)

SELECT 't139 Goal-Based Buckets migration complete' AS status;
