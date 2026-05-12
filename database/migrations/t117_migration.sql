-- WealthDash Migration t117: ESOP / RSU Grant Tracking + Vesting
-- Task: t117 — Full ESOP/RSU tracker with vesting schedule & exercise log
-- Run ONCE — idempotent via IF NOT EXISTS

-- ─── esop_grants ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `esop_grants` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`           INT UNSIGNED NOT NULL,
    `company_name`           VARCHAR(200) NOT NULL,
    `company_symbol`         VARCHAR(30)  DEFAULT NULL           COMMENT 'NSE/BSE symbol if listed',
    `grant_type`             ENUM('ESOP','RSU','SAR','PHANTOM')  NOT NULL DEFAULT 'ESOP',
    `grant_date`             DATE         NOT NULL,
    `grant_ref`              VARCHAR(100) DEFAULT NULL           COMMENT 'Internal grant ID / ref',
    `total_options`          INT UNSIGNED NOT NULL               COMMENT 'Total options/units granted',
    `exercise_price`         DECIMAL(12,4) NOT NULL DEFAULT 0   COMMENT '0 for RSU',
    `currency`               CHAR(3)      NOT NULL DEFAULT 'INR',
    `vesting_start`          DATE         NOT NULL,
    `vesting_cliff_months`   SMALLINT UNSIGNED NOT NULL DEFAULT 12,
    `vesting_period_months`  SMALLINT UNSIGNED NOT NULL DEFAULT 48,
    `vesting_schedule`       VARCHAR(100) DEFAULT '1/4 per year' COMMENT 'Human-readable schedule',
    `vesting_type`           ENUM('cliff','graded','custom')    NOT NULL DEFAULT 'graded',
    `current_fmv`            DECIMAL(12,4) DEFAULT NULL         COMMENT 'FMV per share today',
    `fmv_updated_at`         DATETIME     DEFAULT NULL,
    `options_vested`         INT UNSIGNED NOT NULL DEFAULT 0,
    `options_exercised`      INT UNSIGNED NOT NULL DEFAULT 0,
    `options_lapsed`         INT UNSIGNED NOT NULL DEFAULT 0,
    `status`                 ENUM('active','fully_vested','lapsed','exercised_partial','exercised_full','cancelled')
                             NOT NULL DEFAULT 'active',
    `expiry_date`            DATE         DEFAULT NULL           COMMENT 'ESOP exercise window expiry',
    `notes`                  TEXT         DEFAULT NULL,
    `created_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_esop_portfolio` (`portfolio_id`),
    INDEX `idx_esop_status`    (`status`),
    INDEX `idx_esop_grant_date`(`grant_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── esop_vesting_events ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `esop_vesting_events` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grant_id`        INT UNSIGNED NOT NULL,
    `vest_date`       DATE         NOT NULL,
    `units_vested`    INT UNSIGNED NOT NULL,
    `fmv_on_vest`     DECIMAL(12,4) DEFAULT NULL COMMENT 'FMV per share on vest date (perquisite value)',
    `perquisite_value`DECIMAL(14,4) DEFAULT NULL COMMENT '(fmv - exercise_price) * units',
    `perquisite_tax`  DECIMAL(14,4) DEFAULT NULL COMMENT 'Estimated tax on perquisite',
    `tax_slab_pct`    DECIMAL(5,2)  DEFAULT NULL,
    `is_exercised`    TINYINT(1)    NOT NULL DEFAULT 0,
    `exercise_date`   DATE          DEFAULT NULL,
    `exercise_price`  DECIMAL(12,4) DEFAULT NULL,
    `units_exercised` INT UNSIGNED  DEFAULT 0,
    `sale_date`       DATE          DEFAULT NULL,
    `sale_price`      DECIMAL(12,4) DEFAULT NULL,
    `units_sold`      INT UNSIGNED  DEFAULT 0,
    `capital_gain`    DECIMAL(14,4) DEFAULT NULL,
    `gain_type`       ENUM('STCG','LTCG') DEFAULT NULL,
    `notes`           VARCHAR(255)  DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_esop_vest_grant`  (`grant_id`),
    INDEX `idx_esop_vest_date`   (`vest_date`),
    INDEX `idx_esop_vest_exercised` (`is_exercised`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── esop_exercise_log ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `esop_exercise_log` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grant_id`        INT UNSIGNED NOT NULL,
    `vesting_event_id`INT UNSIGNED DEFAULT NULL,
    `exercise_date`   DATE         NOT NULL,
    `units`           INT UNSIGNED NOT NULL,
    `exercise_price`  DECIMAL(12,4) NOT NULL,
    `fmv_on_exercise` DECIMAL(12,4) DEFAULT NULL,
    `perquisite_value`DECIMAL(14,4) DEFAULT NULL COMMENT '(fmv - exercise_price) * units',
    `broker_charges`  DECIMAL(10,2) DEFAULT 0,
    `tds_deducted`    DECIMAL(12,4) DEFAULT NULL,
    `sale_date`       DATE          DEFAULT NULL,
    `sale_price`      DECIMAL(12,4) DEFAULT NULL,
    `capital_gain`    DECIMAL(14,4) DEFAULT NULL,
    `gain_type`       ENUM('STCG','LTCG') DEFAULT NULL,
    `notes`           VARCHAR(255)  DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_esop_ex_grant`  (`grant_id`),
    INDEX `idx_esop_ex_date`   (`exercise_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't117_migration: ESOP/RSU tracker tables ready' AS status;
