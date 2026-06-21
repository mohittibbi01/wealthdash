-- ============================================================
-- WealthDash — Migration 026: Investment Calendar Events
-- Task: t498 — Investment Calendar 2025-26
-- ============================================================
-- Stores both static FY events (seeded below) and user-added
-- custom reminders. User-specific events (SIP dates, FD maturities)
-- are generated dynamically from existing tables at query time.
-- ============================================================

-- ── investment_calendar_events — static + user custom events ────────────
CREATE TABLE IF NOT EXISTS `investment_calendar_events` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED DEFAULT NULL      COMMENT 'NULL = global/static event visible to all',
    `event_date`   DATE         NOT NULL,
    `event_type`   ENUM(
                     'tax_deadline',
                     'sebi_compliance',
                     'rbi_policy',
                     'budget',
                     'sgb_window',
                     'nfo_open',
                     'nfo_close',
                     'advance_tax',
                     'custom'
                   ) NOT NULL DEFAULT 'custom',
    `title`        VARCHAR(200) NOT NULL,
    `description`  TEXT         DEFAULT NULL,
    `icon`         VARCHAR(10)  DEFAULT '📅'     COMMENT 'Emoji icon for display',
    `color`        VARCHAR(20)  DEFAULT '#3b82f6' COMMENT 'Hex color for calendar cell',
    `is_important` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ice_date`    (`event_date`),
    KEY `idx_ice_user`    (`user_id`),
    KEY `idx_ice_type`    (`event_type`),
    CONSTRAINT `fk_ice_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Investment Calendar — FY 2025-26 events + user custom reminders';

-- ── Seed: FY 2025-26 Static Events ──────────────────────────────────────
INSERT IGNORE INTO `investment_calendar_events`
    (`user_id`, `event_date`, `event_type`, `title`, `description`, `icon`, `color`, `is_important`)
VALUES
-- Advance Tax
(NULL, '2025-06-15', 'advance_tax',    '1st Advance Tax Instalment (15%)', 'FY 2025-26 first advance tax due — 15% of estimated tax liability', '💰', '#f59e0b', 1),
(NULL, '2025-09-15', 'advance_tax',    '2nd Advance Tax Instalment (45%)', 'FY 2025-26 second advance tax due — 45% cumulative', '💰', '#f59e0b', 1),
(NULL, '2025-12-15', 'advance_tax',    '3rd Advance Tax Instalment (75%)', 'FY 2025-26 third advance tax due — 75% cumulative', '💰', '#f59e0b', 1),
(NULL, '2026-03-15', 'advance_tax',    '4th Advance Tax Instalment (100%)', 'FY 2025-26 final advance tax due — 100% cumulative', '💰', '#ef4444', 1),
-- Tax Deadlines
(NULL, '2025-07-31', 'tax_deadline',   'ITR Filing Deadline (non-audit)', 'Last date for income tax return — individuals not requiring audit', '📋', '#ef4444', 1),
(NULL, '2025-10-31', 'tax_deadline',   'ITR Filing (audit cases)',         'Extended deadline for audit cases', '📋', '#ef4444', 0),
(NULL, '2026-03-31', 'tax_deadline',   'FY 2025-26 Year End',             'Last day of financial year — book LTCG/STCG, invest for 80C', '🏁', '#8b5cf6', 1),
(NULL, '2026-04-01', 'tax_deadline',   'FY 2026-27 Begins',               'New financial year starts — reset tax slabs', '🎯', '#16a34a', 0),
-- RBI Policy
(NULL, '2025-06-06', 'rbi_policy',     'RBI MPC Policy Announcement',    'Monetary Policy Committee meeting — rate decision', '🏦', '#0ea5e9', 1),
(NULL, '2025-08-08', 'rbi_policy',     'RBI MPC Policy Announcement',    'Monetary Policy Committee meeting — rate decision', '🏦', '#0ea5e9', 0),
(NULL, '2025-10-08', 'rbi_policy',     'RBI MPC Policy Announcement',    'Monetary Policy Committee meeting — rate decision', '🏦', '#0ea5e9', 0),
(NULL, '2025-12-05', 'rbi_policy',     'RBI MPC Policy Announcement',    'Monetary Policy Committee meeting — rate decision', '🏦', '#0ea5e9', 0),
(NULL, '2026-02-06', 'rbi_policy',     'RBI MPC Policy Announcement',    'Monetary Policy Committee meeting — rate decision', '🏦', '#0ea5e9', 0),
-- SEBI / Compliance
(NULL, '2025-05-30', 'sebi_compliance','LTCG 1Y Window Check',           'Funds bought around May 2024 — 1 year holding period completes. Eligible for LTCG (10%) vs STCG (15%)', '⚖️', '#f97316', 0),
(NULL, '2026-02-01', 'budget',         'Union Budget 2026',               'Annual Union Budget presentation — tax changes may apply', '📊', '#7c3aed', 1),
-- SGB
(NULL, '2025-07-01', 'sgb_window',     'SGB Subscription Window (expected)', 'Sovereign Gold Bond series may open — check RBI notification', '🥇', '#eab308', 0),
(NULL, '2025-10-01', 'sgb_window',     'SGB Subscription Window (expected)', 'Sovereign Gold Bond series may open — check RBI notification', '🥇', '#eab308', 0);

-- ── Verify ───────────────────────────────────────────────────────────────
SELECT COUNT(*) AS seeded_events FROM investment_calendar_events;
SELECT 'investment_calendar_events table created + seeded ✅' AS status;
