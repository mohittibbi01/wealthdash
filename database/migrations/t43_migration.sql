-- ============================================================
-- WealthDash — t43: Bank Accounts Tracker
-- Migration: database/migrations/t43_migration.sql
-- Worker: ID-M
-- ============================================================

-- Bank Accounts master table
CREATE TABLE IF NOT EXISTS `bank_accounts` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `portfolio_id`     INT UNSIGNED     NOT NULL,
    `user_id`          INT UNSIGNED     NOT NULL,
    `bank_name`        VARCHAR(100)     NOT NULL,
    `branch`           VARCHAR(120)     DEFAULT NULL,
    `account_type`     ENUM('savings','current','salary','nre','nro','fcnr','rd','cc') NOT NULL DEFAULT 'savings',
    `account_number`   VARCHAR(30)      DEFAULT NULL COMMENT 'Last 4 digits or masked',
    `ifsc_code`        VARCHAR(15)      DEFAULT NULL,
    `nickname`         VARCHAR(60)      DEFAULT NULL,
    `currency`         VARCHAR(5)       NOT NULL DEFAULT 'INR',
    `opening_balance`  DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
    `current_balance`  DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
    `balance_date`     DATE             DEFAULT NULL COMMENT 'As-of date for current_balance',
    `interest_rate`    DECIMAL(6,3)     DEFAULT NULL COMMENT 'Savings/RD rate p.a.',
    `rd_amount`        DECIMAL(12,2)    DEFAULT NULL COMMENT 'Monthly RD installment',
    `rd_tenure_months` SMALLINT         DEFAULT NULL,
    `rd_start_date`    DATE             DEFAULT NULL,
    `rd_maturity_date` DATE             DEFAULT NULL,
    `is_joint`         TINYINT(1)       NOT NULL DEFAULT 0,
    `joint_holder`     VARCHAR(100)     DEFAULT NULL,
    `nominee`          VARCHAR(100)     DEFAULT NULL,
    `linked_to_demat`  TINYINT(1)       NOT NULL DEFAULT 0,
    `is_primary`       TINYINT(1)       NOT NULL DEFAULT 0,
    `notes`            TEXT             DEFAULT NULL,
    `status`           ENUM('active','closed','dormant') NOT NULL DEFAULT 'active',
    `created_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ba_portfolio` (`portfolio_id`),
    KEY `idx_ba_user`      (`user_id`),
    KEY `idx_ba_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bank Transactions (manual ledger)
CREATE TABLE IF NOT EXISTS `bank_transactions` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `account_id`     INT UNSIGNED     NOT NULL,
    `user_id`        INT UNSIGNED     NOT NULL,
    `txn_date`       DATE             NOT NULL,
    `value_date`     DATE             DEFAULT NULL,
    `type`           ENUM('credit','debit') NOT NULL,
    `category`       VARCHAR(50)      DEFAULT NULL
        COMMENT 'salary, rent, emi, utilities, transfer, fd_maturity, interest, other',
    `amount`         DECIMAL(15,2)    NOT NULL,
    `balance_after`  DECIMAL(15,2)    DEFAULT NULL,
    `description`    VARCHAR(255)     DEFAULT NULL,
    `ref_number`     VARCHAR(60)      DEFAULT NULL,
    `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bt_account`  (`account_id`),
    KEY `idx_bt_user`     (`user_id`),
    KEY `idx_bt_date`     (`txn_date`),
    CONSTRAINT `fk_bt_account` FOREIGN KEY (`account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Balance history (snapshots for chart)
CREATE TABLE IF NOT EXISTS `bank_balance_history` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `account_id`  INT UNSIGNED  NOT NULL,
    `snap_date`   DATE          NOT NULL,
    `balance`     DECIMAL(15,2) NOT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bbh` (`account_id`, `snap_date`),
    KEY `idx_bbh_account` (`account_id`),
    CONSTRAINT `fk_bbh_account` FOREIGN KEY (`account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
