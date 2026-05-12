-- ============================================================
-- WealthDash — Migration t144: SIP Step-Up Nudge
-- Task: t144 — Salary hike ke saath SIP badhao
-- ============================================================

-- ── Salary / income events (triggers step-up nudge) ──────────
CREATE TABLE IF NOT EXISTS `user_salary_events` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `event_type`    ENUM('appraisal','new_job','bonus','freelance_surge','manual') NOT NULL DEFAULT 'appraisal',
  `effective_date` DATE NOT NULL,
  `old_salary`    DECIMAL(12,2) NULL COMMENT 'Monthly take-home before event',
  `new_salary`    DECIMAL(12,2) NOT NULL COMMENT 'Monthly take-home after event',
  `hike_pct`      DECIMAL(6,2)  NULL COMMENT 'Auto-calculated if old_salary provided',
  `notes`         VARCHAR(255)  NULL,
  `nudge_sent`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = step-up nudge already triggered',
  `nudge_sent_at` DATETIME     NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_sal_user` (`user_id`),
  INDEX `idx_sal_date` (`effective_date`),
  INDEX `idx_sal_nudge` (`nudge_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Income increase events that trigger SIP step-up nudges';

-- ── Step-up nudge log (what was suggested, what accepted) ────
CREATE TABLE IF NOT EXISTS `sip_stepup_nudges` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  `salary_event_id`   INT UNSIGNED NULL,
  `sip_schedule_id`   INT UNSIGNED NULL COMMENT 'Which SIP was suggested for step-up',
  `current_amount`    DECIMAL(12,2) NOT NULL,
  `suggested_amount`  DECIMAL(12,2) NOT NULL,
  `suggested_pct`     DECIMAL(5,2)  NOT NULL COMMENT '% increase suggested',
  `basis`             ENUM('salary_hike_50pct','fixed_10pct','user_defined','inflation_adj') NOT NULL DEFAULT 'salary_hike_50pct'
                        COMMENT 'Algorithm used for suggestion',
  `status`            ENUM('pending','accepted','rejected','snoozed','expired') NOT NULL DEFAULT 'pending',
  `accepted_amount`   DECIMAL(12,2) NULL COMMENT 'Actual amount user accepted',
  `responded_at`      DATETIME NULL,
  `snooze_until`      DATE NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_nudge_user` (`user_id`),
  INDEX `idx_nudge_status` (`status`),
  INDEX `idx_nudge_sip` (`sip_schedule_id`),
  CONSTRAINT `fk_nudge_salary_event` FOREIGN KEY (`salary_event_id`) REFERENCES `user_salary_events`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='SIP step-up nudge history — suggestions and user responses';

-- ── Step-up schedule config (per SIP, optional override) ─────
-- Extends sip_schedules — safe ALTERs (IF NOT EXISTS guard)
ALTER TABLE `sip_schedules`
  ADD COLUMN IF NOT EXISTS `stepup_nudge_enabled`  TINYINT(1)    NOT NULL DEFAULT 1
    COMMENT 'Whether step-up nudges apply to this SIP',
  ADD COLUMN IF NOT EXISTS `stepup_custom_pct`     DECIMAL(5,2)  DEFAULT NULL
    COMMENT 'User-set custom step-up % (overrides salary hike algo)',
  ADD COLUMN IF NOT EXISTS `stepup_max_cap`        DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Maximum SIP amount this SIP should reach',
  ADD COLUMN IF NOT EXISTS `stepup_last_applied`   DATE          DEFAULT NULL
    COMMENT 'Date of last applied step-up',
  ADD COLUMN IF NOT EXISTS `stepup_next_review`    DATE          DEFAULT NULL
    COMMENT 'Scheduled next step-up review date';

-- ── Projection config (per user) ─────────────────────────────
CREATE TABLE IF NOT EXISTS `sip_stepup_projections` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED NOT NULL,
  `sip_schedule_id`     INT UNSIGNED NOT NULL,
  `base_amount`         DECIMAL(12,2) NOT NULL,
  `stepup_pct`          DECIMAL(5,2)  NOT NULL DEFAULT 10.00,
  `target_years`        INT UNSIGNED  NOT NULL DEFAULT 10,
  `expected_return_pct` DECIMAL(5,2)  NOT NULL DEFAULT 12.00,
  `projected_corpus`    DECIMAL(16,2) NULL COMMENT 'Computed at save time',
  `vs_flat_sip_corpus`  DECIMAL(16,2) NULL COMMENT 'Corpus without step-up, for comparison',
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proj_user_sip` (`user_id`, `sip_schedule_id`),
  INDEX `idx_proj_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='SIP step-up projection configs saved by user';

SELECT 'SIP Step-Up Nudge migration t144 complete ✅' AS status;
SHOW TABLES LIKE 'sip_stepup%';
SHOW TABLES LIKE 'user_salary%';
SHOW COLUMNS FROM sip_schedules LIKE 'stepup%';
