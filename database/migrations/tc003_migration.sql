-- ============================================================
-- WealthDash — Migration tc003: DeFi & Staking Income Tracker
-- Task: tc003 — DeFi & Staking Income Tracker
-- Run: php database/migrate.php tc003
-- ============================================================

CREATE TABLE IF NOT EXISTS `crypto_defi_positions` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `portfolio_id`        INT UNSIGNED     NOT NULL,

    -- Protocol & chain
    `protocol`            VARCHAR(60)      NOT NULL,        -- Aave, Uniswap, Lido, etc.
    `chain`               VARCHAR(60)      NOT NULL DEFAULT 'Ethereum',
    `position_type`       ENUM(
                            'STAKING','LIQUID_STAKING','LP',
                            'LENDING','YIELD_FARMING','VAULT','OTHER'
                          ) NOT NULL DEFAULT 'STAKING',

    -- Token info (primary token)
    `coin_id`             VARCHAR(60)      NULL,            -- CoinGecko ID
    `coin_symbol`         VARCHAR(20)      NOT NULL,
    `coin_name`           VARCHAR(100)     NOT NULL,
    `pair_symbol`         VARCHAR(40)      NULL,            -- LP pairs e.g. ETH/USDC

    -- Position details
    `wallet_address`      VARCHAR(200)     NULL,
    `principal_inr`       DECIMAL(20,2)    NOT NULL DEFAULT 0,   -- Initial investment in INR
    `current_value_inr`   DECIMAL(20,2)    NOT NULL DEFAULT 0,   -- Last known value (manual update)
    `entry_date`          DATE             NOT NULL,
    `apy_pct`             DECIMAL(8,2)     NOT NULL DEFAULT 0,   -- Current APY %

    -- Rewards tracking
    `rewards_coin_id`     VARCHAR(60)      NULL,
    `rewards_coin_symbol` VARCHAR(20)      NULL,
    `rewards_quantity`    DECIMAL(24,8)    NOT NULL DEFAULT 0,
    `rewards_value_inr`   DECIMAL(20,2)    NOT NULL DEFAULT 0,

    -- Exit info (when CLOSED)
    `status`              ENUM('ACTIVE','CLOSED') NOT NULL DEFAULT 'ACTIVE',
    `exit_date`           DATE             NULL,
    `exit_value_inr`      DECIMAL(20,2)    NULL,
    `realised_pnl_inr`    DECIMAL(20,2)    NULL,

    `notes`               TEXT             NULL,
    `created_at`          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_portfolio`   (`portfolio_id`),
    KEY `idx_status`      (`status`),
    KEY `idx_protocol`    (`protocol`),
    KEY `idx_chain`       (`chain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='DeFi positions: LP, lending, staking, yield farming (tc003)';

SELECT 'DeFi positions table created (tc003)' AS status;
