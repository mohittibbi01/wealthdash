-- ============================================================
-- WealthDash — Phase 5 Migration
-- SIP Schedules + Investment Goals
-- Run after 002_phase4_additions.sql
-- ============================================================

-- -----------------------------------------------------------
-- SIP Schedules
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sip_schedules` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`    INT UNSIGNED NOT NULL,
  `asset_type`      ENUM('mf','stock','nps') NOT NULL DEFAULT 'mf',
  `fund_id`         INT UNSIGNED DEFAULT NULL,        -- FK funds.id (for MF)
  `stock_id`        INT UNSIGNED DEFAULT NULL,        -- FK stock_master.id
  `nps_scheme_id`   INT UNSIGNED DEFAULT NULL,        -- FK nps_schemes.id
  `folio_number`    VARCHAR(50) DEFAULT NULL,
  `sip_amount`      DECIMAL(14,2) NOT NULL,
  `frequency`       ENUM('monthly','quarterly','weekly','yearly') NOT NULL DEFAULT 'monthly',
  `sip_day`         TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Day of month (1-28)',
  `start_date`      DATE NOT NULL,
  `end_date`        DATE DEFAULT NULL COMMENT 'NULL = ongoing',
  `platform`        VARCHAR(50) DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `notes`           VARCHAR(255) DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sip_portfolio` (`portfolio_id`),
  KEY `idx_sip_fund`      (`fund_id`),
  CONSTRAINT `fk_sip_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sip_fund`      FOREIGN KEY (`fund_id`)      REFERENCES `funds` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Investment Goals
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `investment_goals` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`        INT UNSIGNED NOT NULL,
  `name`                VARCHAR(150) NOT NULL,
  `description`         TEXT DEFAULT NULL,
  `target_amount`       DECIMAL(16,2) NOT NULL,
  `target_date`         DATE NOT NULL,
  `current_saved`       DECIMAL(16,2) NOT NULL DEFAULT 0.00 COMMENT 'Manually tracked or auto-linked',
  `monthly_sip_needed`  DECIMAL(14,2) DEFAULT NULL COMMENT 'Auto-calculated',
  `expected_return_pct` DECIMAL(5,2) NOT NULL DEFAULT 12.00 COMMENT 'Assumed annual return %',
  `priority`            ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  `color`               VARCHAR(7) NOT NULL DEFAULT '#2563EB',
  `icon`                VARCHAR(30) DEFAULT 'target',
  `linked_fund_ids`     JSON DEFAULT NULL COMMENT 'Array of fund_ids contributing to this goal',
  `linked_stock_ids`    JSON DEFAULT NULL,
  `linked_fd_ids`       JSON DEFAULT NULL,
  `is_achieved`         TINYINT(1) NOT NULL DEFAULT 0,
  `achieved_at`         DATE DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_goal_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_goal_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Goal Contributions (manual + auto log)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `goal_contributions` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `goal_id`       INT UNSIGNED NOT NULL,
  `amount`        DECIMAL(14,2) NOT NULL,
  `contribution_date` DATE NOT NULL,
  `note`          VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contrib_goal` (`goal_id`),
  CONSTRAINT `fk_contrib_goal` FOREIGN KEY (`goal_id`) REFERENCES `investment_goals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- Add missing app_settings keys (safe INSERT IGNORE)
-- -----------------------------------------------------------
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_val`) VALUES
('sip_reminder_enabled', '1'),
('sip_reminder_days_before', '2'),
('goal_default_return_pct', '12'),
('nav_last_updated', NULL),
('app_name', 'WealthDash'),
('app_version', '1.0.0');
