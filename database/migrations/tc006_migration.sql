-- ============================================================
-- WealthDash — Migration tc006: Cold Wallet Tracker
-- Task: tc006 — Hardware/Cold Wallet Tracker
-- Run: php database/migrate.php tc006
-- ============================================================

CREATE TABLE IF NOT EXISTS `crypto_cold_wallets` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`             INT UNSIGNED     NOT NULL,

    -- Identity
    `label`               VARCHAR(120)     NOT NULL,                  -- User-friendly name
    `address`             VARCHAR(200)     NOT NULL,                  -- Public wallet address
    `chain`               VARCHAR(40)      NOT NULL DEFAULT 'bitcoin',-- bitcoin / ethereum / solana…

    -- Device info
    `device_type`         ENUM(
                            'LEDGER','TREZOR','COLDCARD','BITBOX',
                            'KEYSTONE','NGRAVE','FOUNDATION',
                            'PAPER','SEED','OTHER'
                          ) NOT NULL DEFAULT 'OTHER',

    -- Holdings
    `quantity`            DECIMAL(24,8)    NOT NULL DEFAULT 0,        -- Amount of native coin
    `cost_basis_inr`      DECIMAL(20,2)    NOT NULL DEFAULT 0,        -- Total purchase cost
    `total_value_inr`     DECIMAL(20,2)    NOT NULL DEFAULT 0,        -- Last known value

    -- Meta
    `purchase_date`       DATE             NULL,
    `alert_threshold_pct` DECIMAL(6,2)     NULL,                      -- Alert if value drops by X%
    `notes`               TEXT             NULL,

    `last_synced_at`      TIMESTAMP        NULL,
    `created_at`          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_user`    (`user_id`),
    KEY `idx_chain`   (`chain`),
    KEY `idx_address` (`address`(20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cold / hardware wallet address tracker (tc006)';

SELECT 'Cold wallet tracker table created (tc006)' AS status;
