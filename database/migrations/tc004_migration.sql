-- WealthDash Migration tc004: Portfolio Rebalancing — Crypto

CREATE TABLE IF NOT EXISTS `crypto_rebal_targets` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id` INT UNSIGNED NOT NULL,
    `coin_id`      INT UNSIGNED NOT NULL,
    `target_pct`   DECIMAL(6,3) NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crt_portfolio_coin` (`portfolio_id`, `coin_id`),
    INDEX `idx_crt_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_rebal_history` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`   INT UNSIGNED NOT NULL,
    `actions_json`   JSON         DEFAULT NULL,
    `note`           TEXT         DEFAULT NULL,
    `rebalanced_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crh_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'tc004_migration: Crypto rebalancing tables ready' AS status;
