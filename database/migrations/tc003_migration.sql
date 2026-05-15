-- WealthDash Migration tc003: DeFi & Staking Income Tracker

CREATE TABLE IF NOT EXISTS `defi_positions` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED NOT NULL,
    `platform`          VARCHAR(100) NOT NULL,
    `position_type`     ENUM('staking','lending','liquidity','yield_farming','vault','other') NOT NULL DEFAULT 'staking',
    `asset_symbol`      VARCHAR(20)  NOT NULL,
    `asset_name`        VARCHAR(100) DEFAULT NULL,
    `staked_amount`     DECIMAL(28,10) NOT NULL DEFAULT 0,
    `staked_value_inr`  DECIMAL(18,4)  DEFAULT 0,
    `apy_pct`           DECIMAL(8,4)   DEFAULT 0,
    `reward_symbol`     VARCHAR(20)    DEFAULT NULL,
    `chain_name`        VARCHAR(50)    DEFAULT NULL,
    `contract_address`  VARCHAR(100)   DEFAULT NULL,
    `start_date`        DATE           NOT NULL,
    `lockup_days`       SMALLINT UNSIGNED DEFAULT 0,
    `unlock_date`       DATE           DEFAULT NULL,
    `last_income_date`  DATE           DEFAULT NULL,
    `notes`             TEXT           DEFAULT NULL,
    `is_active`         TINYINT(1)     NOT NULL DEFAULT 1,
    `created_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_defi_user`     (`user_id`, `is_active`),
    INDEX `idx_defi_platform` (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `defi_income_log` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `position_id`   INT UNSIGNED NOT NULL,
    `income_date`   DATE         NOT NULL,
    `income_type`   ENUM('staking','interest','lp_fee','airdrop','bonus','other') NOT NULL DEFAULT 'staking',
    `reward_symbol` VARCHAR(20)  DEFAULT NULL,
    `amount_token`  DECIMAL(28,10) NOT NULL,
    `price_inr`     DECIMAL(20,8)  DEFAULT 0,
    `amount_inr`    DECIMAL(18,4)  DEFAULT 0,
    `notes`         TEXT           DEFAULT NULL,
    `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_dil_position` (`position_id`),
    INDEX `idx_dil_date`     (`income_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'tc003_migration: DeFi & Staking tables ready' AS status;
