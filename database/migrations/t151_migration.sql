-- t151: EPFO Passbook Sync — Automated passbook import
-- Idempotent; run once

CREATE TABLE IF NOT EXISTS epfo_accounts (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED     NOT NULL,
    uan             VARCHAR(20)      NOT NULL              COMMENT 'Universal Account Number',
    member_id       VARCHAR(40)      DEFAULT NULL          COMMENT 'PF Member ID (AABCD1234E001)',
    establishment   VARCHAR(200)     DEFAULT NULL,
    office_code     VARCHAR(20)      DEFAULT NULL          COMMENT 'EPFO regional office code',
    last_sync_at    DATETIME         DEFAULT NULL,
    sync_status     ENUM('idle','pending','syncing','done','failed') NOT NULL DEFAULT 'idle',
    sync_error      TEXT             DEFAULT NULL,
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_uan (user_id, uan),
    KEY idx_user (user_id),
    KEY idx_uan (uan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epfo_passbook_entries (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED     NOT NULL              COMMENT 'FK → epfo_accounts.id',
    user_id         INT UNSIGNED     NOT NULL,
    wage_month      DATE             NOT NULL              COMMENT 'YYYY-MM-01 of the wage month',
    transaction_date DATE            DEFAULT NULL,
    description     VARCHAR(255)     DEFAULT NULL,
    epf_employee    DECIMAL(14,2)    NOT NULL DEFAULT 0    COMMENT 'Employee EPF contribution',
    epf_employer    DECIMAL(14,2)    NOT NULL DEFAULT 0    COMMENT 'Employer EPF contribution',
    eps_employer    DECIMAL(14,2)    NOT NULL DEFAULT 0    COMMENT 'Employer EPS contribution',
    interest        DECIMAL(14,2)    NOT NULL DEFAULT 0,
    balance         DECIMAL(14,2)    NOT NULL DEFAULT 0    COMMENT 'Running EPF balance after this entry',
    entry_type      ENUM('contribution','interest','withdrawal','transfer','opening') NOT NULL DEFAULT 'contribution',
    raw_ref         VARCHAR(80)      DEFAULT NULL          COMMENT 'EPFO transaction reference / trrnno',
    source          ENUM('manual','pdf','api') NOT NULL DEFAULT 'manual',
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_ref (account_id, wage_month, entry_type, raw_ref),
    KEY idx_account (account_id),
    KEY idx_user (user_id),
    KEY idx_month (wage_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS epfo_sync_log (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED     NOT NULL,
    user_id         INT UNSIGNED     NOT NULL,
    source          ENUM('pdf','api','manual') NOT NULL DEFAULT 'pdf',
    rows_inserted   INT UNSIGNED     NOT NULL DEFAULT 0,
    rows_skipped    INT UNSIGNED     NOT NULL DEFAULT 0,
    status          ENUM('ok','partial','failed') NOT NULL DEFAULT 'ok',
    error_detail    TEXT             DEFAULT NULL,
    synced_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_account (account_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add EPF balance snapshot to existing epf_holdings (if table exists)
-- ALTER TABLE epf_holdings
--     ADD COLUMN IF NOT EXISTS epfo_account_id INT UNSIGNED DEFAULT NULL COMMENT 'FK → epfo_accounts.id';
