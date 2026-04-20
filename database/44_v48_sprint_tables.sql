-- ============================================================
-- WealthDash — Migration 44
-- v47/v48 Sprint: Nudge dismissals, Discipline Score,
--                 Investment Journal, Credit Cards
-- Run once: php database/migrate.php
-- ============================================================

-- ── nudge_dismissals (t499) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nudge_dismissals` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `nudge_id`     VARCHAR(60)  NOT NULL,
  `dismissed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_nudge` (`user_id`, `nudge_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── spending_discipline (tj003) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `spending_discipline` (
  `id`             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED   NOT NULL,
  `monthly_income` DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `target_pct`     DECIMAL(5,2)   NOT NULL DEFAULT 20.00,
  `updated_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── investment_journal (t408) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `investment_journal` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              INT UNSIGNED NOT NULL,
  `entry_date`           DATE         NOT NULL,
  `title`                VARCHAR(200) NOT NULL DEFAULT '',
  `body`                 TEXT         NOT NULL,
  `mood`                 ENUM('bullish','bearish','neutral','anxious','confident')
                         NOT NULL DEFAULT 'neutral',
  `tags`                 JSON         DEFAULT NULL
                         COMMENT 'Array of tag strings e.g. ["HDFC Flexi","market"]',
  `linked_fund_id`       INT UNSIGNED DEFAULT NULL,
  `portfolio_change_pct` DECIMAL(6,2) DEFAULT NULL,
  `nifty_change_pct`     DECIMAL(6,2) DEFAULT NULL,
  `is_private`           TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`, `entry_date`),
  KEY `idx_user_id`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── credit_cards (t503) ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `credit_cards` (
  `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED   NOT NULL,
  `card_name`     VARCHAR(100)   NOT NULL,
  `bank_name`     VARCHAR(100)   NOT NULL DEFAULT '',
  `card_type`     VARCHAR(50)    NOT NULL DEFAULT 'credit',
  `credit_limit`  DECIMAL(12,2)  NOT NULL DEFAULT 0,
  `outstanding`   DECIMAL(12,2)  NOT NULL DEFAULT 0,
  `min_payment`   DECIMAL(12,2)  NOT NULL DEFAULT 0,
  `due_date`      DATE           DEFAULT NULL,
  `interest_rate` DECIMAL(5,2)   NOT NULL DEFAULT 36.00
                  COMMENT 'Annual % — typical 36-42%',
  `reward_type`   VARCHAR(50)    NOT NULL DEFAULT 'cashback',
  `reward_rate`   DECIMAL(5,2)   NOT NULL DEFAULT 1.00
                  COMMENT '% cashback or points per Rs 100',
  `annual_fee`    DECIMAL(8,2)   NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)     NOT NULL DEFAULT 1,
  `notes`         TEXT           DEFAULT NULL,
  `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── audit_log already exists from 01_schema_complete.sql ─────────────
-- No changes needed for t482 (uses existing audit_log table)
