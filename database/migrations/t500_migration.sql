-- ============================================================
-- WealthDash — t500: GODMODE Milestone
-- Migration: database/migrations/t500_migration.sql
-- Worker: ID-M
-- Marks completion of all P1-P3 features in coordinator v8
-- ============================================================

-- Milestone log table
CREATE TABLE IF NOT EXISTS `milestone_log` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `milestone`   VARCHAR(80)  NOT NULL,
    `description` TEXT         DEFAULT NULL,
    `achieved_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `achieved_by` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `milestone_log` (milestone, description) VALUES
    ('GODMODE_v1', 'WealthDash GODMODE — All P1-P3 features complete. Sessions t23-t500 done by ID-M.');
