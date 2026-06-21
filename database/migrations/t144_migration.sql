-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t144: SIP Step-Up Nudge
-- Migration: t144_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS sip_stepup_config (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sip_id           INT UNSIGNED NOT NULL COMMENT 'References sip_schedules.id',
    user_id          INT UNSIGNED NOT NULL,
    portfolio_id     INT UNSIGNED NOT NULL,
    stepup_type      ENUM('percentage','fixed_amount') NOT NULL DEFAULT 'percentage',
    stepup_value     DECIMAL(10,4) NOT NULL COMMENT 'Percentage OR fixed rupee amount',
    stepup_frequency ENUM('yearly','half_yearly','custom') NOT NULL DEFAULT 'yearly',
    stepup_month     TINYINT UNSIGNED DEFAULT 4  COMMENT 'Month to apply step-up (default April = FY start)',
    custom_interval_months TINYINT UNSIGNED DEFAULT NULL,
    max_sip_amount   DECIMAL(12,2) DEFAULT NULL COMMENT 'Cap the SIP at this amount',
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    next_stepup_date DATE         DEFAULT NULL,
    last_stepup_date DATE         DEFAULT NULL,
    last_stepup_from DECIMAL(12,2) DEFAULT NULL,
    last_stepup_to   DECIMAL(12,2) DEFAULT NULL,
    notes            TEXT         DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sip (sip_id),
    INDEX idx_user  (user_id),
    INDEX idx_next  (next_stepup_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sip_stepup_history (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stepup_config_id INT UNSIGNED NOT NULL,
    sip_id           INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    applied_date     DATE         NOT NULL,
    old_amount       DECIMAL(12,2) NOT NULL,
    new_amount       DECIMAL(12,2) NOT NULL,
    stepup_value     DECIMAL(10,4) NOT NULL,
    stepup_type      ENUM('percentage','fixed_amount') NOT NULL,
    applied_by       ENUM('auto','manual') NOT NULL DEFAULT 'manual',
    notes            TEXT         DEFAULT NULL,
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_config (stepup_config_id),
    INDEX idx_sip    (sip_id),
    INDEX idx_user   (user_id),
    INDEX idx_date   (applied_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nudge alerts table (shows salary-hike prompt in dashboard)
CREATE TABLE IF NOT EXISTS sip_stepup_nudges (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    nudge_type       ENUM('salary_hike','fy_start','manual_prompt','anniversary') NOT NULL DEFAULT 'fy_start',
    nudge_date       DATE         NOT NULL,
    is_dismissed     TINYINT(1)   NOT NULL DEFAULT 0,
    is_actioned      TINYINT(1)   NOT NULL DEFAULT 0,
    actioned_sip_ids TEXT         DEFAULT NULL COMMENT 'JSON array of sip_ids that were stepped up',
    created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_date   (nudge_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
