-- WealthDash Migration 025: Goal Buckets — Holdings to Goal tagging (t139, t355)
-- Run ONCE — idempotent via IF NOT EXISTS

CREATE TABLE IF NOT EXISTS `goals` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `goal_name`       VARCHAR(120) NOT NULL,
    `goal_type`       ENUM('retirement','education','home','vehicle','emergency','vacation','wedding','business','other') DEFAULT 'other',
    `target_amount`   DECIMAL(16,2) NOT NULL,
    `target_date`     DATE NOT NULL,
    `current_amount`  DECIMAL(16,2) NOT NULL DEFAULT 0,
    `monthly_sip`     DECIMAL(12,2) DEFAULT NULL COMMENT 'Recommended monthly SIP to reach goal',
    `expected_return` DECIMAL(5,2)  DEFAULT 12.00 COMMENT 'Expected CAGR %',
    `inflation_rate`  DECIMAL(5,2)  DEFAULT 6.00,
    `priority`        TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=High 2=Medium 3=Low',
    `color`           VARCHAR(20)   DEFAULT '#3b82f6',
    `emoji`           VARCHAR(10)   DEFAULT '🎯',
    `status`          ENUM('active','achieved','paused','cancelled') NOT NULL DEFAULT 'active',
    `notes`           TEXT DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_goals_user` (`user_id`),
    CONSTRAINT `fk_goals_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links MF holdings/SIPs to a specific goal
CREATE TABLE IF NOT EXISTS `goal_mf_links` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `goal_id`      INT UNSIGNED NOT NULL,
    `portfolio_id` INT UNSIGNED NOT NULL,
    `fund_id`      INT UNSIGNED NOT NULL COMMENT 'mf_holdings.fund_id',
    `allocation_pct` DECIMAL(5,2) DEFAULT 100.00 COMMENT 'What % of this holding counts toward goal',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_goal_mf` (`goal_id`, `fund_id`, `portfolio_id`),
    CONSTRAINT `fk_goal_mf_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links stock holdings to a goal
CREATE TABLE IF NOT EXISTS `goal_stock_links` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `goal_id`      INT UNSIGNED NOT NULL,
    `portfolio_id` INT UNSIGNED NOT NULL,
    `stock_id`     INT UNSIGNED NOT NULL,
    `allocation_pct` DECIMAL(5,2) DEFAULT 100.00,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_goal_stock` (`goal_id`, `stock_id`, `portfolio_id`),
    CONSTRAINT `fk_goal_stock_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SIP schedule linked to goal
ALTER TABLE `sip_schedules`
    ADD COLUMN IF NOT EXISTS `goal_id` INT UNSIGNED DEFAULT NULL,
    ADD KEY IF NOT EXISTS `idx_sip_goal` (`goal_id`);
