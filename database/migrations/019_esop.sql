-- WealthDash Migration 019: ESOP / RSU Grants (t117)
-- Run ONCE — idempotent via IF NOT EXISTS

CREATE TABLE IF NOT EXISTS `esop_grants` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `company_name`    VARCHAR(200) NOT NULL,
    `grant_type`      ENUM('ESOP','RSU','SAR') NOT NULL DEFAULT 'ESOP',
    `grant_date`      DATE NOT NULL,
    `total_options`   INT UNSIGNED NOT NULL,
    `exercise_price`  DECIMAL(12,4) NOT NULL DEFAULT 0,
    `vesting_start`   DATE NOT NULL,
    `vesting_cliff_months` SMALLINT UNSIGNED DEFAULT 12,
    `vesting_period_months` SMALLINT UNSIGNED DEFAULT 48,
    `vesting_schedule` VARCHAR(50) DEFAULT '1/4 per year',
    `current_fmv`     DECIMAL(12,4) DEFAULT NULL COMMENT 'Fair Market Value per share today',
    `exercise_price_total` DECIMAL(16,4) AS (total_options * exercise_price) STORED,
    `currency`        CHAR(3) NOT NULL DEFAULT 'INR',
    `status`          ENUM('active','fully_vested','lapsed','exercised_partial','exercised_full') NOT NULL DEFAULT 'active',
    `notes`           TEXT DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_esop_portfolio` (`portfolio_id`),
    CONSTRAINT `fk_esop_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `esop_vesting_events` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `grant_id`      INT UNSIGNED NOT NULL,
    `vest_date`     DATE NOT NULL,
    `units_vested`  INT UNSIGNED NOT NULL,
    `fmv_on_vest`   DECIMAL(12,4) DEFAULT NULL COMMENT 'FMV per share on vest date — perquisite value',
    `perquisite_tax`DECIMAL(14,4) DEFAULT NULL COMMENT '= (fmv - exercise_price) * units * slab',
    `tax_slab_pct`  DECIMAL(5,2) DEFAULT NULL,
    `is_exercised`  TINYINT(1) NOT NULL DEFAULT 0,
    `exercise_date` DATE DEFAULT NULL,
    `exercise_price` DECIMAL(12,4) DEFAULT NULL,
    `sale_date`     DATE DEFAULT NULL,
    `sale_price`    DECIMAL(12,4) DEFAULT NULL,
    `capital_gain`  DECIMAL(14,4) DEFAULT NULL,
    `gain_type`     ENUM('STCG','LTCG') DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_esop_vest_grant` (`grant_id`),
    CONSTRAINT `fk_esop_vest_grant` FOREIGN KEY (`grant_id`) REFERENCES `esop_grants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
