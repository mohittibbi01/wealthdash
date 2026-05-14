-- ============================================================
-- WealthDash — Migration tc004: Portfolio Rebalancing Targets
-- Task: tc004 — Portfolio Rebalancing — Crypto
-- Run: php database/migrate.php tc004
-- ============================================================

CREATE TABLE IF NOT EXISTS `crypto_rebalance_targets` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED     NOT NULL,
    `coin_id`      VARCHAR(60)      NOT NULL,    -- CoinGecko ID
    `coin_symbol`  VARCHAR(20)      NOT NULL,
    `coin_name`    VARCHAR(100)     NOT NULL,
    `target_pct`   DECIMAL(6,2)     NOT NULL,    -- Target allocation % (0–100)
    `created_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_coin` (`user_id`, `coin_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User-defined crypto rebalancing target allocations (tc004)';

-- Constraint: total targets per user must not exceed 100% (enforced in PHP, not DB)
-- The PHP layer checks sum before insert/update

SELECT 'Crypto rebalance targets table created (tc004)' AS status;
