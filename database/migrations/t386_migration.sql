-- t386: 2FA — TOTP Google Authenticator
-- Run once; idempotent via IF NOT EXISTS / IF NOT EXISTS column checks

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS totp_secret       VARCHAR(64)   DEFAULT NULL        COMMENT 'Base32-encoded TOTP secret',
    ADD COLUMN IF NOT EXISTS totp_enabled       TINYINT(1)    NOT NULL DEFAULT 0  COMMENT '1 = 2FA active',
    ADD COLUMN IF NOT EXISTS totp_verified_at   DATETIME      DEFAULT NULL        COMMENT 'When user first verified setup',
    ADD COLUMN IF NOT EXISTS totp_backup_codes  TEXT          DEFAULT NULL        COMMENT 'JSON array of bcrypt-hashed backup codes';

CREATE TABLE IF NOT EXISTS totp_sessions (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED     NOT NULL,
    token       VARCHAR(64)      NOT NULL COMMENT 'Random token stored in session, used as challenge key',
    ip          VARCHAR(45)      DEFAULT NULL,
    user_agent  VARCHAR(255)     DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_user (user_id),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS totp_audit (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED     NOT NULL,
    event       VARCHAR(32)      NOT NULL COMMENT 'setup|verify|disable|login_ok|login_fail|backup_used',
    ip          VARCHAR(45)      DEFAULT NULL,
    user_agent  VARCHAR(255)     DEFAULT NULL,
    detail      VARCHAR(255)     DEFAULT NULL,
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_event (event),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
