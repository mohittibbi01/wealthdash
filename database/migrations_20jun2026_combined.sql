-- ============================================================
-- WealthDash — Combined Migration for 20 June 2026 Router Merge
-- Run this ONCE after the router.php / sidebar.php merge.
-- All CREATE TABLE statements use IF NOT EXISTS — safe to re-run.
-- NOTE: t302 runs before t392 (t392 references groww_fund_map)
-- ============================================================


-- ──────────────────────────────────────────────────────
-- t43_migration.sql
-- ──────────────────────────────────────────────────────

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


-- ──────────────────────────────────────────────────────
-- t50_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t50: Multi-User Management Migration
-- Ensure users table has required columns

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS role          ENUM('user','admin') NOT NULL DEFAULT 'user'      AFTER email,
  ADD COLUMN IF NOT EXISTS status        ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active' AFTER role,
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL                                     AFTER status,
  ADD COLUMN IF NOT EXISTS theme         ENUM('light','dark') NOT NULL DEFAULT 'light'     AFTER last_login_at;

-- audit_log table (if not exists)
CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(80)  NOT NULL,
  detail     TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_action (user_id, action),
  KEY idx_created_at  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for user lookups
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);
CREATE INDEX IF NOT EXISTS idx_users_role   ON users(role);


-- ──────────────────────────────────────────────────────
-- t51_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t51: System Health Dashboard
-- Migration: database/migrations/t51_migration.sql
-- Worker: ID-M
-- ============================================================

-- System health snapshots (for trend chart)
CREATE TABLE IF NOT EXISTS `system_health_log` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `snap_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `php_memory`   INT UNSIGNED DEFAULT NULL COMMENT 'bytes',
    `db_size_mb`   DECIMAL(10,2) DEFAULT NULL,
    `slow_queries` INT UNSIGNED DEFAULT NULL,
    `cache_hits`   INT UNSIGNED DEFAULT NULL,
    `cache_misses` INT UNSIGNED DEFAULT NULL,
    `active_users` INT UNSIGNED DEFAULT NULL,
    `error_count`  INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_shl_snap` (`snap_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t52_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t52: Global Settings Migration

CREATE TABLE IF NOT EXISTS app_settings (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(80)   NOT NULL UNIQUE,
  setting_val TEXT          NOT NULL DEFAULT '',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed defaults
INSERT IGNORE INTO app_settings (setting_key, setting_val) VALUES
  ('app_name',             'WealthDash'),
  ('maintenance_mode',     '0'),
  ('registration_open',    '1'),
  ('default_theme',        'light'),
  ('items_per_page',       '25'),
  ('session_timeout_min',  '120'),
  ('max_login_attempts',   '5'),
  ('lockout_duration_min', '15'),
  ('require_2fa_admin',    '0'),
  ('password_min_length',  '8'),
  ('mf_nav_update_time',   '22:00'),
  ('api_rate_limit_rpm',   '60'),
  ('cron_enabled',         '1'),
  ('cache_ttl_seconds',    '300'),
  ('enable_ai_features',   '1'),
  ('email_enabled',        '0'),
  ('smtp_port',            '587'),
  ('smtp_from_name',       'WealthDash'),
  ('sip_reminder_enabled', '1');


-- ──────────────────────────────────────────────────────
-- t53_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t53: DB Manager Tab
-- Migration: database/migrations/t53_migration.sql
-- Worker: ID-M
-- ============================================================

-- DB Manager query history
CREATE TABLE IF NOT EXISTS `db_query_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    NOT NULL,
    `query_text`  TEXT            NOT NULL,
    `rows_affected` INT DEFAULT NULL,
    `exec_ms`     FLOAT DEFAULT NULL,
    `is_success`  TINYINT(1) NOT NULL DEFAULT 1,
    `error_msg`   TEXT DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dql_user` (`user_id`),
    KEY `idx_dql_created` (`created_at`),
    CONSTRAINT `fk_dql_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DB backups log
CREATE TABLE IF NOT EXISTS `db_backup_log` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename`    VARCHAR(255) NOT NULL,
    `size_bytes`  BIGINT DEFAULT NULL,
    `tables`      INT DEFAULT NULL,
    `triggered_by` INT UNSIGNED DEFAULT NULL,
    `method`      ENUM('manual','cron','auto') NOT NULL DEFAULT 'manual',
    `status`      ENUM('completed','failed','in_progress') NOT NULL DEFAULT 'completed',
    `error_msg`   TEXT DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dbl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t307_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t307: Audit Log — User Action History
-- Migration: database/migrations/t307_migration.sql
-- Worker: ID-M
-- NOTE: audit_log table already exists in 01_schema_complete.sql
--       This migration adds missing columns + indexes only
-- ============================================================

-- Add missing columns to audit_log (safe ALTER)
ALTER TABLE `audit_log`
    ADD COLUMN IF NOT EXISTS `user_agent`    VARCHAR(300) DEFAULT NULL AFTER `ip_address`,
    ADD COLUMN IF NOT EXISTS `session_id`    VARCHAR(64)  DEFAULT NULL AFTER `user_agent`,
    ADD COLUMN IF NOT EXISTS `severity`      ENUM('info','warning','critical') NOT NULL DEFAULT 'info' AFTER `session_id`,
    ADD COLUMN IF NOT EXISTS `request_method` VARCHAR(10) DEFAULT NULL AFTER `severity`,
    ADD COLUMN IF NOT EXISTS `request_uri`   VARCHAR(500) DEFAULT NULL AFTER `request_method`;

-- Add missing index on severity
ALTER TABLE `audit_log`
    ADD INDEX IF NOT EXISTS `idx_audit_severity` (`severity`),
    ADD INDEX IF NOT EXISTS `idx_audit_action`   (`action`);

-- Audit log retention policy table
CREATE TABLE IF NOT EXISTS `audit_retention_config` (
    `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `retention_days`   INT UNSIGNED NOT NULL DEFAULT 365,
    `auto_purge`       TINYINT(1) NOT NULL DEFAULT 0,
    `last_purge_at`    DATETIME DEFAULT NULL,
    `rows_purged`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `audit_retention_config` (`id`, `retention_days`, `auto_purge`)
VALUES (1, 365, 0)
ON DUPLICATE KEY UPDATE `id` = 1;


-- ──────────────────────────────────────────────────────
-- t308_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t308: System Performance Monitor
-- Migration: database/migrations/t308_migration.sql
-- Worker: ID-M
-- ============================================================

-- API request performance log
CREATE TABLE IF NOT EXISTS `perf_request_log` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`       VARCHAR(100)    NOT NULL,
    `user_id`      INT UNSIGNED    DEFAULT NULL,
    `duration_ms`  FLOAT           NOT NULL,
    `memory_bytes` INT UNSIGNED    DEFAULT NULL,
    `db_queries`   SMALLINT        DEFAULT NULL,
    `status_code`  SMALLINT        DEFAULT 200,
    `ip_address`   VARCHAR(45)     DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_prl_action`  (`action`),
    KEY `idx_prl_created` (`created_at`),
    KEY `idx_prl_duration`(`duration_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hourly aggregated performance snapshots
CREATE TABLE IF NOT EXISTS `perf_hourly_stats` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hour`          DATETIME     NOT NULL COMMENT 'Truncated to hour',
    `action`        VARCHAR(100) NOT NULL DEFAULT '__all__',
    `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `avg_ms`        FLOAT        DEFAULT NULL,
    `p95_ms`        FLOAT        DEFAULT NULL,
    `max_ms`        FLOAT        DEFAULT NULL,
    `error_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `avg_memory_mb` FLOAT        DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_phs_hour_action` (`hour`, `action`),
    KEY `idx_phs_hour` (`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Slow query alerts
CREATE TABLE IF NOT EXISTS `perf_slow_alerts` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`      VARCHAR(100) NOT NULL,
    `duration_ms` FLOAT        NOT NULL,
    `threshold_ms`FLOAT        NOT NULL,
    `user_id`     INT UNSIGNED DEFAULT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `context`     TEXT         DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_psa_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t335_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t335: API for External Tools
-- Migration: database/migrations/t335_migration.sql
-- Worker: ID-M
-- NOTE: api_key_manager already exists (partial). This migration
--       adds external REST API tables + scopes.
-- ============================================================

-- External API keys (for third-party/personal tool access)
CREATE TABLE IF NOT EXISTS `external_api_keys` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `name`          VARCHAR(80)     NOT NULL COMMENT 'e.g. My Excel Sheet, HomeAssistant',
    `key_prefix`    VARCHAR(12)     NOT NULL COMMENT 'Public prefix e.g. wdx_ab12cd',
    `key_hash`      VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash of full key',
    `scopes`        JSON            NOT NULL COMMENT 'Array of allowed scopes',
    `rate_limit`    SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Requests per minute',
    `last_used_at`  DATETIME        DEFAULT NULL,
    `last_ip`       VARCHAR(45)     DEFAULT NULL,
    `use_count`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at`    DATE            DEFAULT NULL COMMENT 'NULL = never',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_eak_prefix` (`key_prefix`),
    KEY `idx_eak_user`   (`user_id`),
    KEY `idx_eak_active` (`is_active`),
    CONSTRAINT `fk_eak_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-key rate limit counters (minute window)
CREATE TABLE IF NOT EXISTS `external_api_rate` (
    `key_id`     INT UNSIGNED  NOT NULL,
    `window`     DATETIME      NOT NULL COMMENT 'Truncated to minute',
    `hits`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`key_id`, `window`),
    CONSTRAINT `fk_ear_key` FOREIGN KEY (`key_id`) REFERENCES `external_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API access log (sampled)
CREATE TABLE IF NOT EXISTS `external_api_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key_id`     INT UNSIGNED    NOT NULL,
    `endpoint`   VARCHAR(120)    NOT NULL,
    `method`     VARCHAR(8)      NOT NULL DEFAULT 'GET',
    `status`     SMALLINT        NOT NULL DEFAULT 200,
    `duration_ms`FLOAT           DEFAULT NULL,
    `ip_address` VARCHAR(45)     DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_eal_key`     (`key_id`),
    KEY `idx_eal_created` (`created_at`),
    CONSTRAINT `fk_eal_key` FOREIGN KEY (`key_id`) REFERENCES `external_api_keys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t336_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t336: Data Versioning — Undo Import
-- Migration: database/migrations/t336_migration.sql
-- Worker: ID-M
-- ============================================================

-- Import version snapshots
CREATE TABLE IF NOT EXISTS `import_versions` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL,
    `portfolio_id`  INT UNSIGNED    NOT NULL,
    `import_type`   VARCHAR(40)     NOT NULL COMMENT 'mf_csv, stocks_csv, fd_manual, cas_pdf, etc.',
    `label`         VARCHAR(120)    NOT NULL,
    `snapshot_data` LONGTEXT        NOT NULL COMMENT 'JSON snapshot of affected rows before import',
    `affected_table`VARCHAR(60)     NOT NULL,
    `affected_ids`  JSON            NOT NULL COMMENT 'Array of inserted/modified row IDs',
    `rows_added`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `rows_modified` INT UNSIGNED    NOT NULL DEFAULT 0,
    `rows_deleted`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `file_name`     VARCHAR(255)    DEFAULT NULL,
    `file_hash`     VARCHAR(64)     DEFAULT NULL COMMENT 'SHA256 of original file',
    `status`        ENUM('active','undone','partial') NOT NULL DEFAULT 'active',
    `undone_at`     DATETIME        DEFAULT NULL,
    `undone_by`     INT UNSIGNED    DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_iv_user`       (`user_id`),
    KEY `idx_iv_portfolio`  (`portfolio_id`),
    KEY `idx_iv_type`       (`import_type`),
    KEY `idx_iv_status`     (`status`),
    CONSTRAINT `fk_iv_user`      FOREIGN KEY (`user_id`)      REFERENCES `users`(`id`)      ON DELETE CASCADE,
    CONSTRAINT `fk_iv_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Row-level change log (granular undo)
CREATE TABLE IF NOT EXISTS `import_row_changes` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version_id`    INT UNSIGNED    NOT NULL,
    `table_name`    VARCHAR(60)     NOT NULL,
    `row_id`        INT UNSIGNED    NOT NULL,
    `change_type`   ENUM('insert','update','delete') NOT NULL,
    `old_data`      JSON            DEFAULT NULL,
    `new_data`      JSON            DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_irc_version` (`version_id`),
    KEY `idx_irc_table_row` (`table_name`, `row_id`),
    CONSTRAINT `fk_irc_version` FOREIGN KEY (`version_id`) REFERENCES `import_versions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t411_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t411: Automated Test Suite
-- Migration: database/migrations/t411_migration.sql
-- Worker: ID-M
-- ============================================================

CREATE TABLE IF NOT EXISTS `test_run_log` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `run_id`       VARCHAR(36)     NOT NULL COMMENT 'UUID per run',
    `suite`        VARCHAR(60)     NOT NULL COMMENT 'unit | integration | e2e | perf',
    `test_name`    VARCHAR(120)    NOT NULL,
    `status`       ENUM('pass','fail','skip','error') NOT NULL,
    `duration_ms`  FLOAT           DEFAULT NULL,
    `message`      TEXT            DEFAULT NULL,
    `triggered_by` INT UNSIGNED    DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_trl_run`     (`run_id`),
    KEY `idx_trl_suite`   (`suite`),
    KEY `idx_trl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t414_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t414: Error Monitoring
-- Migration: database/migrations/t414_migration.sql
-- Worker: ID-M
-- ============================================================

CREATE TABLE IF NOT EXISTS `error_events` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fingerprint`  VARCHAR(64)     NOT NULL COMMENT 'SHA1 of type+file+line',
    `error_type`   VARCHAR(80)     NOT NULL COMMENT 'E_ERROR, E_WARNING, Exception, etc.',
    `message`      TEXT            NOT NULL,
    `file`         VARCHAR(300)    DEFAULT NULL,
    `line`         INT UNSIGNED    DEFAULT NULL,
    `stack_trace`  TEXT            DEFAULT NULL,
    `url`          VARCHAR(500)    DEFAULT NULL,
    `user_id`      INT UNSIGNED    DEFAULT NULL,
    `ip_address`   VARCHAR(45)     DEFAULT NULL,
    `user_agent`   VARCHAR(300)    DEFAULT NULL,
    `env`          VARCHAR(20)     DEFAULT NULL,
    `first_seen`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `count`        INT UNSIGNED    NOT NULL DEFAULT 1,
    `is_resolved`  TINYINT(1)      NOT NULL DEFAULT 0,
    `resolved_at`  DATETIME        DEFAULT NULL,
    `resolved_by`  INT UNSIGNED    DEFAULT NULL,
    `notes`        TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ee_fingerprint` (`fingerprint`),
    KEY `idx_ee_type`       (`error_type`),
    KEY `idx_ee_resolved`   (`is_resolved`),
    KEY `idx_ee_last_seen`  (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t415_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — t415: Load Testing
-- Migration: database/migrations/t415_migration.sql
-- Worker: ID-M
-- ============================================================

CREATE TABLE IF NOT EXISTS `load_test_runs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id`          VARCHAR(36)  NOT NULL,
    `scenario`        VARCHAR(80)  NOT NULL,
    `concurrency`     SMALLINT     NOT NULL DEFAULT 10,
    `duration_sec`    INT          NOT NULL,
    `total_requests`  INT UNSIGNED NOT NULL,
    `success_count`   INT UNSIGNED NOT NULL,
    `error_count`     INT UNSIGNED NOT NULL,
    `avg_ms`          FLOAT        DEFAULT NULL,
    `p50_ms`          FLOAT        DEFAULT NULL,
    `p95_ms`          FLOAT        DEFAULT NULL,
    `p99_ms`          FLOAT        DEFAULT NULL,
    `max_ms`          FLOAT        DEFAULT NULL,
    `rps`             FLOAT        DEFAULT NULL COMMENT 'Requests per second',
    `triggered_by`    INT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ltr_run`     (`run_id`),
    KEY `idx_ltr_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t58_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t58: AI Portfolio Advisor Migration
CREATE TABLE IF NOT EXISTS ai_advisor_sessions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  session_type  ENUM('full_review','follow_up') NOT NULL DEFAULT 'full_review',
  response_json JSON         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, session_type, created_at),
  CONSTRAINT fk_aas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals, goal_checkins (tg005),
--             finance_profiles (ti001), insurance_policies (t122), loans (t123)


-- ──────────────────────────────────────────────────────
-- t59_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t59: AI Auto Categorization Migration
CREATE TABLE IF NOT EXISTS category_rules (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  keyword     VARCHAR(80)  NOT NULL,
  category    VARCHAR(60)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_keyword (user_id, keyword),
  CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: budget_actuals (t471_migration.sql)


-- ──────────────────────────────────────────────────────
-- t60_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t60: AI Anomaly Detection Base Migration
-- NO NEW TABLES — functionality already fully covered by:
--   t246_migration.sql (anomaly_log table, basic detection)
--   t384_migration.sql (anomaly_log extended, dedup index)
-- This task is satisfied by existing t246 + t384 work. See
-- api/ai/anomaly_base_redirect.php for action-name alias note.
SELECT 1;


-- ──────────────────────────────────────────────────────
-- t61_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t61: AI Goal-based Planning Migration
CREATE TABLE IF NOT EXISTS goal_plan_meta (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  goal_id          INT UNSIGNED NOT NULL UNIQUE,
  user_id          INT UNSIGNED NOT NULL,
  priority         ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  existing_corpus  DECIMAL(16,2) NOT NULL DEFAULT 0,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_gpm_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
  CONSTRAINT fk_gpm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: goals, goal_checkins (tg005_migration.sql)


-- ──────────────────────────────────────────────────────
-- t243_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t243: AI Fund Recommendation Migration (FIXED)
-- mf_funds table exist nahi karti — ALTER hata diya.
-- Fund category mapping PHP code mein rule-based handle hogi.

-- Rate limit log (if not already created by security module)
CREATE TABLE IF NOT EXISTS rate_limit_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(80)  NOT NULL,
  ip         VARCHAR(45)  NULL,
  hit_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action_time (action, hit_at),
  KEY idx_user_action (user_id, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t244_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t244: AI Portfolio Narrative Migration
-- No new tables required.
-- Depends on existing tables (must exist before running):
--   ✅ mf_holdings      (existing)
--   ✅ mf_nav_latest    (existing)
--   ✅ mf_funds         (existing — category column needed, added in t243_migration.sql)

-- ai_narrative_log: optional — stores generated narratives per user per month
CREATE TABLE IF NOT EXISTS ai_narrative_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  month       VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
  mode        ENUM('ai','rule_based') NOT NULL DEFAULT 'rule_based',
  narrative   TEXT         NOT NULL,
  stats       JSON         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_month (user_id, month),
  CONSTRAINT fk_anl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t246_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t246: AI Anomaly Detector Migration (FIXED)
-- mf_transactions mein mf_id nahi hai — ALTER hata diya.
-- sip_id column bhi skip — transactions table structure as-is use hoga.

-- anomaly_log: stores flagged anomalies per scan
CREATE TABLE IF NOT EXISTS anomaly_log (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NOT NULL,
  scan_date    DATE          NOT NULL,
  anomaly_type VARCHAR(60)   NOT NULL,
  txn_date     DATE          NULL,
  fund_name    VARCHAR(150)  NULL,
  txn_type     VARCHAR(30)   NULL,
  amount       DECIMAL(14,2) NULL,
  reason       VARCHAR(255)  NULL,
  severity     ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  resolved     TINYINT(1)    NOT NULL DEFAULT 0,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_scan     (user_id, scan_date),
  KEY idx_user_resolved (user_id, resolved),
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t329_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t329: AI Weekly Digest Migration
CREATE TABLE IF NOT EXISTS ai_weekly_digests (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  week_key    VARCHAR(8)   NOT NULL COMMENT 'ISO week: YYYY-WW',
  digest_json JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_week (user_id, week_key),
  KEY idx_user_id (user_id),
  CONSTRAINT fk_wd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t330_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t330: AI Chatbot Migration
CREATE TABLE IF NOT EXISTS ai_chat_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED    NOT NULL,
  role        ENUM('user','assistant') NOT NULL,
  message     TEXT            NOT NULL,
  context_id  VARCHAR(40)     NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_ctx     (user_id, context_id),
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t331_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t331: AI SIP Optimizer Migration
-- No new tables required.
-- Depends on:
--   ✅ mf_sips         (existing)
--   ✅ mf_holdings     (existing)
--   ✅ mf_nav_latest   (existing)
--   ✅ finance_profiles (from ti001_migration.sql)

-- rate_limit_buckets (if not already created)
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
  bucket_key   VARCHAR(180) NOT NULL PRIMARY KEY,
  requests     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL,
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t332_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t332: AI Goal Advisor Migration
-- No new tables required.
-- Depends on:
--   ✅ goals          (existing)
--   ✅ goal_checkins  (from tg005_migration.sql)
--   ✅ mf_sips        (existing)
--   ✅ mf_holdings    (existing)
--   ✅ mf_nav_latest  (existing)
--   ✅ finance_profiles (from ti001_migration.sql)

-- rate_limit_buckets (for ai_goal_advice limit — if not already created)
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
  bucket_key   VARCHAR(180) NOT NULL PRIMARY KEY,
  requests     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL,
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t333_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t333: AI Portfolio Report Card Migration
-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals, finance_profiles

-- ai_portfolio_reviews: stores monthly report cards (shared with portfolio_review.php)
CREATE TABLE IF NOT EXISTS ai_portfolio_reviews (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NOT NULL,
  review_month VARCHAR(7)    NOT NULL COMMENT 'YYYY-MM',
  review_type  VARCHAR(30)   NOT NULL DEFAULT 'report_card',
  grade        VARCHAR(3)    NULL,
  score        TINYINT UNSIGNED NULL,
  summary      TEXT          NULL,
  strengths    JSON          NULL,
  weaknesses   JSON          NULL,
  actions      JSON          NULL,
  raw_response JSON          NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_month_type (user_id, review_month, review_type),
  KEY idx_user (user_id),
  CONSTRAINT fk_apr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- t331: no separate migration needed (reads mf_sips + finance_profiles)
-- t332: rate_limit_buckets already created in t332_migration.sql


-- ──────────────────────────────────────────────────────
-- t382_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t382: AI Fund Research Migration
-- No new tables required.
-- Depends on: mf_holdings, mf_nav_latest (existing)
-- Uses ai_chat RateLimit::LIMITS key (already defined in rate_limit.php)
-- Nothing to run — placeholder for consistency.
SELECT 1;


-- ──────────────────────────────────────────────────────
-- t384_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t384: AI Anomaly Detector v2 Migration
-- Depends on: anomaly_log table (from t246_migration.sql fixed version)
-- If t246 migration not yet run, create table here too (IF NOT EXISTS is safe):

CREATE TABLE IF NOT EXISTS anomaly_log (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NOT NULL,
  scan_date    DATE          NOT NULL,
  anomaly_type VARCHAR(60)   NOT NULL,
  txn_date     DATE          NULL,
  fund_name    VARCHAR(150)  NULL,
  txn_type     VARCHAR(30)   NULL,
  amount       DECIMAL(14,2) NULL,
  reason       VARCHAR(255)  NULL,
  severity     ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  resolved     TINYINT(1)    NOT NULL DEFAULT 0,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_scan     (user_id, scan_date),
  KEY idx_user_resolved (user_id, resolved),
  CONSTRAINT fk_al_user_v2 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add dedup index used by v2 scan (skip if already exists from t246)
ALTER TABLE anomaly_log
  ADD INDEX IF NOT EXISTS idx_dedup (user_id, anomaly_type, txn_date, fund_name, resolved);


-- ──────────────────────────────────────────────────────
-- t385_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t385: AI Goal Coach Migration
CREATE TABLE IF NOT EXISTS ai_goal_coach_nudges (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  nudge_date  DATE         NOT NULL,
  nudges_json JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_date (user_id, nudge_date),
  CONSTRAINT fk_gcn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: goals, goal_checkins (tg005), mf_sips (existing)


-- ──────────────────────────────────────────────────────
-- t40_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash Migration t40: Crypto Holdings + CoinGecko Integration
-- Task: t40

CREATE TABLE IF NOT EXISTS `crypto_master` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `coingecko_id`      VARCHAR(100) NOT NULL UNIQUE,
    `symbol`            VARCHAR(20)  NOT NULL,
    `name`              VARCHAR(150) NOT NULL,
    `logo_url`          VARCHAR(500) DEFAULT NULL,
    `current_price_inr` DECIMAL(20,8) DEFAULT 0,
    `price_change_24h`  DECIMAL(10,4) DEFAULT 0,
    `market_cap_inr`    DECIMAL(22,4) DEFAULT 0,
    `volume_24h_inr`    DECIMAL(22,4) DEFAULT 0,
    `ath_inr`           DECIMAL(20,8) DEFAULT NULL,
    `atl_inr`           DECIMAL(20,8) DEFAULT NULL,
    `circulating_supply`DECIMAL(22,4) DEFAULT NULL,
    `total_supply`      DECIMAL(22,4) DEFAULT NULL,
    `rank`              SMALLINT UNSIGNED DEFAULT NULL,
    `price_updated_at`  DATETIME DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cm_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_holdings` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`     INT UNSIGNED NOT NULL,
    `coin_id`          INT UNSIGNED NOT NULL,
    `quantity`         DECIMAL(28,10) NOT NULL DEFAULT 0,
    `avg_buy_price`    DECIMAL(20,8) DEFAULT 0,
    `total_invested`   DECIMAL(18,4) DEFAULT 0,
    `current_value`    DECIMAL(18,4) DEFAULT 0,
    `first_buy_date`   DATE DEFAULT NULL,
    `wallet`           VARCHAR(100) DEFAULT NULL,
    `notes`            TEXT DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ch_portfolio_coin` (`portfolio_id`, `coin_id`),
    INDEX `idx_ch_portfolio` (`portfolio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_transactions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id`  INT UNSIGNED NOT NULL,
    `coin_id`       INT UNSIGNED NOT NULL,
    `type`          ENUM('buy','sell','transfer_in','transfer_out','staking','airdrop','mining') NOT NULL DEFAULT 'buy',
    `quantity`      DECIMAL(28,10) NOT NULL,
    `price_inr`     DECIMAL(20,8) DEFAULT 0,
    `total_inr`     DECIMAL(18,4) DEFAULT 0,
    `fee_inr`       DECIMAL(12,4) DEFAULT 0,
    `exchange_name` VARCHAR(50) DEFAULT NULL,
    `wallet`        VARCHAR(100) DEFAULT NULL,
    `txn_hash`      VARCHAR(100) DEFAULT NULL,
    `note`          TEXT DEFAULT NULL,
    `txn_date`      DATE NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ct_portfolio` (`portfolio_id`),
    INDEX `idx_ct_coin`      (`coin_id`),
    INDEX `idx_ct_date`      (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crypto_watchlist` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `coin_id`          INT UNSIGNED NOT NULL,
    `alert_price_low`  DECIMAL(20,8) DEFAULT NULL,
    `alert_price_high` DECIMAL(20,8) DEFAULT NULL,
    `notes`            VARCHAR(500) DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cwl_user_coin` (`user_id`, `coin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't40_migration: Crypto tables ready' AS status;


-- ──────────────────────────────────────────────────────
-- t42_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t42: Crypto Tax Calculator Migration
CREATE TABLE IF NOT EXISTS crypto_tax_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  fy          VARCHAR(7)   NOT NULL COMMENT 'e.g. 2024-25',
  label       VARCHAR(100) NOT NULL DEFAULT 'Crypto Tax',
  trades      JSON         NOT NULL,
  summary     JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_fy (user_id, fy),
  CONSTRAINT fk_ctx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- tc003_migration.sql
-- ──────────────────────────────────────────────────────

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


-- ──────────────────────────────────────────────────────
-- tc004_migration.sql
-- ──────────────────────────────────────────────────────

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


-- ──────────────────────────────────────────────────────
-- tc006_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — tc006: Cold Wallet Tracker Migration
CREATE TABLE IF NOT EXISTS cold_wallets (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  name        VARCHAR(100)  NOT NULL,
  type        ENUM('hardware','paper','mobile','air_gapped') NOT NULL DEFAULT 'hardware',
  device      VARCHAR(80)   NULL,
  address     VARCHAR(200)  NULL,
  network     VARCHAR(50)   NULL,
  notes       TEXT          NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_cw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cold_wallet_holdings (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wallet_id   INT UNSIGNED  NOT NULL,
  coin        VARCHAR(20)   NOT NULL,
  quantity    DECIMAL(24,8) NOT NULL DEFAULT 0,
  buy_price   DECIMAL(18,2) NULL DEFAULT 0,
  value_inr   DECIMAL(18,2) NULL DEFAULT 0,
  notes       VARCHAR(255)  NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_wallet_coin (wallet_id, coin),
  CONSTRAINT fk_cwh_wallet FOREIGN KEY (wallet_id) REFERENCES cold_wallets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t48_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — Migration t48: PPF + NPS + EPF 80C Tracker
-- Task: Unified 80C deduction tracker
-- Run ONCE — idempotent
-- ============================================================

-- 1. Manual 80C entries (LIC, ELSS, home loan principal, tuition fee, etc.)
CREATE TABLE IF NOT EXISTS `tax_80c_manual_entries` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) UNSIGNED NOT NULL,
  `fy_year`     smallint(4)      NOT NULL COMMENT 'FY start year e.g. 2024 for FY2024-25',
  `category`    enum('lic_premium','elss','home_loan_principal','tuition_fee',
                     'nsc_purchase','sukanya_samridhi','stamp_duty',
                     'unit_linked_insurance','other') NOT NULL DEFAULT 'other',
  `section`     enum('80C','80CCD(1B)','80D','80E') NOT NULL DEFAULT '80C',
  `amount`      decimal(12,2)    NOT NULL,
  `description` varchar(255)     DEFAULT NULL,
  `is_active`   tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_80c_user_fy` (`user_id`, `fy_year`),
  KEY `idx_80c_section` (`section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure nsc_80c_log exists (created by t205 — safe duplicate CREATE IF NOT EXISTS)
CREATE TABLE IF NOT EXISTS `nsc_80c_log` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nsc_scheme_id` int(10) UNSIGNED NOT NULL,
  `fy`            varchar(9)       NOT NULL,
  `amount`        decimal(12,2)    NOT NULL DEFAULT 0.00,
  `declared_80c`  tinyint(1)       NOT NULL DEFAULT 0,
  `notes`         varchar(255)     DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nsc_log_scheme_fy` (`nsc_scheme_id`, `fy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure ppf_fy_deposits exists (created by t203 — safe duplicate)
CREATE TABLE IF NOT EXISTS `ppf_fy_deposits` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ppf_scheme_id`   int(10) UNSIGNED NOT NULL,
  `fy_year`         smallint(4)      NOT NULL,
  `total_deposited` decimal(12,2)    NOT NULL DEFAULT 0.00,
  `entries`         text             DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ppf_fy` (`ppf_scheme_id`, `fy_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't48 PPF+NPS+EPF 80C Tracker migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- tg003_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — tg003: Retirement Corpus Calculator Migration

CREATE TABLE IF NOT EXISTS retirement_plans (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  plan_name   VARCHAR(100)  NOT NULL DEFAULT 'My Retirement Plan',
  inputs      JSON          NOT NULL COMMENT 'Calculator input parameters',
  results     JSON          NOT NULL COMMENT 'Last calculated results',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_id (user_id),
  CONSTRAINT fk_ret_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- tg005_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — tg005: Goals vs Actual Tracking Migration

-- goal_checkins: monthly investment log per goal
CREATE TABLE IF NOT EXISTS goal_checkins (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  goal_id      INT UNSIGNED  NOT NULL,
  user_id      INT UNSIGNED  NOT NULL,
  amount       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  checkin_date DATE          NOT NULL,
  notes        VARCHAR(255)  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_goal_date   (goal_id, checkin_date),
  KEY idx_user_date   (user_id, checkin_date),
  CONSTRAINT fk_gc_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
  CONSTRAINT fk_gc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure goals table has required columns (add if missing)
-- These are additive ALTER — safe to run on existing table
ALTER TABLE goals
  ADD COLUMN IF NOT EXISTS start_date  DATE         NULL AFTER goal_name,
  ADD COLUMN IF NOT EXISTS goal_type   VARCHAR(50)  NULL DEFAULT 'general' AFTER target_amount,
  ADD COLUMN IF NOT EXISTS priority    ENUM('low','medium','high') NOT NULL DEFAULT 'medium' AFTER goal_type,
  ADD COLUMN IF NOT EXISTS notes       TEXT         NULL AFTER priority;

-- mf_sips: add goal_id FK if not present (optional link)
ALTER TABLE mf_sips
  ADD COLUMN IF NOT EXISTS goal_id INT UNSIGNED NULL DEFAULT NULL AFTER portfolio_id,
  ADD KEY IF NOT EXISTS idx_sip_goal (goal_id);


-- ──────────────────────────────────────────────────────
-- tv09_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — Migration tv09: Capital Gains Tax Preview
-- Task: Live unrealized CG on MF holdings
-- Run ONCE — idempotent
-- ============================================================

-- No new tables. Existing mf_holdings + funds tables used.
-- Add index for faster CG queries.

CREATE INDEX IF NOT EXISTS `idx_mf_holdings_active_fund`
  ON `mf_holdings` (`fund_id`, `units`);

CREATE INDEX IF NOT EXISTS `idx_funds_category`
  ON `funds` (`scheme_category`(50));

SELECT 'tv09 Capital Gains Preview migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t358_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t358: Goal Notification Engine Migration
CREATE TABLE IF NOT EXISTS goal_notifications (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  NOT NULL,
  goal_id       INT UNSIGNED  NOT NULL,
  milestone_pct SMALLINT      NOT NULL COMMENT 'Positive = % achieved, Negative = days left',
  message       VARCHAR(255)  NOT NULL,
  emoji         VARCHAR(8)    NULL,
  is_read       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_goal_milestone (user_id, goal_id, milestone_pct),
  KEY idx_user_read (user_id, is_read),
  CONSTRAINT fk_gn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_gn_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t360_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t360: Life Events Calendar Migration
CREATE TABLE IF NOT EXISTS life_events (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  event_name       VARCHAR(150) NOT NULL,
  event_type       ENUM('milestone','personal','career','family','goal') NOT NULL DEFAULT 'milestone',
  event_date       DATE         NOT NULL,
  financial_impact VARCHAR(255) NULL,
  notes            TEXT         NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, event_date),
  CONSTRAINT fk_le_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t46_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — Migration t46: EPF Monthly Contribution Tracker
-- Task: Monthly EPF entry log + salary change history
-- Run ONCE — idempotent
-- Depends on: t467_migration.sql (epf_monthly_log must exist)
-- ============================================================

-- 1. EPF salary change log (for tracking increments + VPF changes)
CREATE TABLE IF NOT EXISTS `epf_salary_log` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`   int(10) UNSIGNED NOT NULL,
  `basic_salary`     decimal(14,2)    NOT NULL,
  `effective_date`   date             NOT NULL,
  `vpf_rate`         decimal(5,2)     NOT NULL DEFAULT 0.00,
  `notes`            varchar(255)     DEFAULT NULL,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salary_log_date` (`epf_account_id`, `effective_date`),
  KEY `idx_sl_account` (`epf_account_id`),
  CONSTRAINT `fk_sl_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure epf_monthly_log.total_credit column exists (in case t467 not yet run)
CREATE TABLE IF NOT EXISTS `epf_monthly_log` (
  `id`                    int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`        int(10) UNSIGNED NOT NULL,
  `log_month`             date             NOT NULL COMMENT 'YYYY-MM-01',
  `basic_salary`          decimal(14,2)    NOT NULL DEFAULT 0.00,
  `employee_contribution` decimal(10,2)    NOT NULL DEFAULT 0.00,
  `employer_contribution` decimal(10,2)    NOT NULL DEFAULT 0.00,
  `eps_contribution`      decimal(10,2)    NOT NULL DEFAULT 0.00,
  `vpf_contribution`      decimal(10,2)    NOT NULL DEFAULT 0.00,
  `total_credit`          decimal(12,2)    NOT NULL DEFAULT 0.00,
  `balance_after`         decimal(16,2)    DEFAULT NULL,
  `interest_credited`     decimal(12,2)    NOT NULL DEFAULT 0.00,
  `source`                enum('manual','epfo_sync','import') NOT NULL DEFAULT 'manual',
  `notes`                 varchar(255)     DEFAULT NULL,
  `created_at`            datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`            datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_log_month` (`epf_account_id`, `log_month`),
  KEY `idx_epfl_account` (`epf_account_id`),
  KEY `idx_epfl_month`   (`log_month`),
  CONSTRAINT `fk_epfl_t46_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure required columns on epf_accounts (idempotent)
ALTER TABLE `epf_accounts`
  ADD COLUMN IF NOT EXISTS `basic_salary`          decimal(14,2) NOT NULL DEFAULT 0.00 AFTER `employer_name`,
  ADD COLUMN IF NOT EXISTS `employee_contribution` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `basic_salary`,
  ADD COLUMN IF NOT EXISTS `employer_contribution` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `employee_contribution`,
  ADD COLUMN IF NOT EXISTS `eps_contribution`      decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `employer_contribution`,
  ADD COLUMN IF NOT EXISTS `vpf_rate`              decimal(5,2)  NOT NULL DEFAULT 0.00 AFTER `eps_contribution`,
  ADD COLUMN IF NOT EXISTS `joining_date`          date DEFAULT NULL AFTER `vpf_rate`,
  ADD COLUMN IF NOT EXISTS `current_balance`       decimal(16,2) NOT NULL DEFAULT 0.00 AFTER `joining_date`,
  ADD COLUMN IF NOT EXISTS `eps_balance`           decimal(16,2) NOT NULL DEFAULT 0.00 AFTER `current_balance`,
  ADD COLUMN IF NOT EXISTS `is_active`             tinyint(1) NOT NULL DEFAULT 1 AFTER `eps_balance`,
  ADD COLUMN IF NOT EXISTS `last_updated`          date DEFAULT NULL AFTER `is_active`;

SELECT 't46 EPF Monthly Tracker migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t467_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — Migration t467: EPF Balance Tracker
-- Task: Monthly contribution log + balance history
-- Run ONCE — idempotent (IF NOT EXISTS + ALTER IF NOT EXISTS)
-- ============================================================

-- 1. Extend epf_accounts with fields used by epf_list.php + t467
ALTER TABLE `epf_accounts`
  ADD COLUMN IF NOT EXISTS `basic_salary`           decimal(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Current basic salary (EPF contribution base)'
    AFTER `employer_name`,
  ADD COLUMN IF NOT EXISTS `employee_contribution`  decimal(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Monthly employee EPF contribution (12% of basic)'
    AFTER `basic_salary`,
  ADD COLUMN IF NOT EXISTS `employer_contribution`  decimal(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Monthly employer EPF contribution (3.67% of basic)'
    AFTER `employee_contribution`,
  ADD COLUMN IF NOT EXISTS `eps_contribution`       decimal(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Monthly EPS contribution (8.33% of basic, max ₹15K base)'
    AFTER `employer_contribution`,
  ADD COLUMN IF NOT EXISTS `vpf_rate`               decimal(5,2)  NOT NULL DEFAULT 0.00
    COMMENT 'VPF % of basic (voluntary, over 12%)'
    AFTER `eps_contribution`,
  ADD COLUMN IF NOT EXISTS `joining_date`           date DEFAULT NULL
    COMMENT 'Date of joining employer (for service years)'
    AFTER `vpf_rate`,
  ADD COLUMN IF NOT EXISTS `current_balance`        decimal(16,2) NOT NULL DEFAULT 0.00
    COMMENT 'Latest known EPF balance (sync with EPFO passbook)'
    AFTER `joining_date`,
  ADD COLUMN IF NOT EXISTS `eps_balance`            decimal(16,2) NOT NULL DEFAULT 0.00
    COMMENT 'EPS balance (pension component)'
    AFTER `current_balance`,
  ADD COLUMN IF NOT EXISTS `is_active`              tinyint(1)    NOT NULL DEFAULT 1
    AFTER `eps_balance`;

-- 2. Monthly contribution log (passbook-style)
CREATE TABLE IF NOT EXISTS `epf_monthly_log` (
  `id`                   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`       int(10) UNSIGNED NOT NULL,
  `log_month`            date             NOT NULL COMMENT 'YYYY-MM-01',
  `basic_salary`         decimal(14,2)    NOT NULL DEFAULT 0.00,
  `employee_contribution`decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'EPF employee share',
  `employer_contribution`decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'Employer EPF (3.67%)',
  `eps_contribution`     decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'EPS share (8.33%)',
  `vpf_contribution`     decimal(10,2)    NOT NULL DEFAULT 0.00 COMMENT 'VPF (voluntary)',
  `total_credit`         decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Employee+Employer+VPF',
  `balance_after`        decimal(16,2)    DEFAULT NULL           COMMENT 'EPF balance after this month (if known)',
  `interest_credited`    decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Interest credited this month (March credit)',
  `source`               enum('manual','epfo_sync','import') NOT NULL DEFAULT 'manual',
  `notes`                varchar(255)     DEFAULT NULL,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`           datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_log_month` (`epf_account_id`, `log_month`),
  KEY `idx_epfl_account` (`epf_account_id`),
  KEY `idx_epfl_month`   (`log_month`),
  CONSTRAINT `fk_epfl_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Annual FY balance snapshots (for graph + interest reconciliation)
CREATE TABLE IF NOT EXISTS `epf_fy_snapshot` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`   int(10) UNSIGNED NOT NULL,
  `fy_year`          smallint(4)      NOT NULL COMMENT 'FY start year e.g. 2024 for FY24-25',
  `opening_balance`  decimal(16,2)    NOT NULL DEFAULT 0.00,
  `closing_balance`  decimal(16,2)    NOT NULL DEFAULT 0.00,
  `total_ee_contrib` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `total_er_contrib` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `total_vpf`        decimal(14,2)    NOT NULL DEFAULT 0.00,
  `interest_credited`decimal(14,2)    NOT NULL DEFAULT 0.00,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_snap_fy` (`epf_account_id`, `fy_year`),
  KEY `idx_epfs_account` (`epf_account_id`),
  CONSTRAINT `fk_epfs_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't467 EPF Balance Tracker migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t469_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — Migration t469: EPF Tax Tracker
-- Task: Taxable EPF contribution alert (Budget 2021)
-- Run ONCE — idempotent
-- ============================================================

-- EPF Tax Rules (Budget 2021, effective FY2021-22 onwards):
--  - Employee contribution (EPF+VPF) > ₹2,50,000/yr → interest on EXCESS is taxable
--  - Tax on excess interest at slab rate (IFOS)
--  - Government employee threshold: ₹5,00,000/yr (no employer contribution)
--  - Employer contribution > ₹7,50,000/yr (EPF+NPS+Gratuity combined) → taxable as perquisite

-- Annual EPF tax summary per account per FY
CREATE TABLE IF NOT EXISTS `epf_tax_log` (
  `id`                      int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`          int(10) UNSIGNED NOT NULL,
  `fy_year`                 smallint(4)      NOT NULL COMMENT 'FY start year e.g. 2024 for FY24-25',
  `annual_ee_contribution`  decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Total employee EPF+VPF contribution this FY',
  `annual_er_contribution`  decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Total employer EPF contribution this FY',
  `threshold_ee`            decimal(14,2)    NOT NULL DEFAULT 250000.00
    COMMENT '2.5L normal / 5L for govt employees',
  `taxable_ee_excess`       decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Employee contribution above threshold',
  `epf_interest_fy`         decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Total interest credited this FY',
  `taxable_interest`        decimal(14,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Interest on excess taxable contribution',
  `estimated_tax_30pct`     decimal(12,2)    NOT NULL DEFAULT 0.00
    COMMENT 'Estimated tax @ 30% slab (worst case)',
  `is_govt_employee`        tinyint(1)       NOT NULL DEFAULT 0
    COMMENT '1 = govt employee, threshold is 5L',
  `notes`                   varchar(255)     DEFAULT NULL,
  `created_at`              datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`              datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_tax_fy` (`epf_account_id`, `fy_year`),
  KEY `idx_epf_tax_account` (`epf_account_id`),
  CONSTRAINT `fk_epf_tax_account`
    FOREIGN KEY (`epf_account_id`) REFERENCES `epf_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't469 EPF Tax Tracker migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t470_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — Migration t470: EPF vs NPS vs PPF Comparison
-- Task: Side-by-side comparison (read-only API — no new tables)
-- Run ONCE — idempotent
-- ============================================================

-- No new tables required for t470.
-- epf_nps_ppf_compare.php uses existing tables:
--   epf_accounts, epf_monthly_log (t467)
--   nps_holdings, nps_transactions
--   po_schemes (scheme_type='ppf'), ppf_fy_deposits (t203)

-- Optional: cache comparison snapshots for performance
CREATE TABLE IF NOT EXISTS `comparison_snapshots` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) UNSIGNED NOT NULL,
  `snapshot_key` varchar(40)      NOT NULL COMMENT 'e.g. epf_nps_ppf_2024-25',
  `payload`      mediumtext       NOT NULL COMMENT 'JSON snapshot of comparison',
  `expires_at`   datetime         NOT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_snap_user_key` (`user_id`, `snapshot_key`),
  KEY `idx_snap_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't470 EPF vs NPS vs PPF Comparison migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t122_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t122: Insurance Portfolio Migration
CREATE TABLE IF NOT EXISTS insurance_policies (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id            INT UNSIGNED  NOT NULL,
  policy_name        VARCHAR(150)  NOT NULL,
  policy_type        ENUM('term','health','ulip','endowment','vehicle','other') NOT NULL DEFAULT 'term',
  insurer            VARCHAR(100)  NULL,
  policy_number      VARCHAR(60)   NULL,
  sum_assured        DECIMAL(16,2) NOT NULL DEFAULT 0,
  premium_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  premium_frequency  ENUM('monthly','quarterly','half_yearly','annual','single') NOT NULL DEFAULT 'annual',
  start_date         DATE          NULL,
  maturity_date      DATE          NULL,
  next_premium_date  DATE          NULL,
  maturity_amount    DECIMAL(16,2) NULL,
  nominee            VARCHAR(100)  NULL,
  status             ENUM('active','lapsed','surrendered','matured') NOT NULL DEFAULT 'active',
  notes              TEXT          NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, policy_type),
  KEY idx_user_next (user_id, next_premium_date),
  CONSTRAINT fk_ins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t123_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t123: Loan Tracker Migration
CREATE TABLE IF NOT EXISTS loans (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id               INT UNSIGNED  NOT NULL,
  loan_name             VARCHAR(150)  NOT NULL,
  loan_type             ENUM('home','personal','education','vehicle','gold','other') NOT NULL DEFAULT 'personal',
  lender                VARCHAR(100)  NULL,
  loan_amount           DECIMAL(16,2) NOT NULL DEFAULT 0,
  outstanding_principal DECIMAL(16,2) NOT NULL DEFAULT 0,
  interest_rate         DECIMAL(6,2)  NOT NULL DEFAULT 0,
  tenure_months         SMALLINT      NOT NULL DEFAULT 12,
  emi_amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
  emi_date              TINYINT       NOT NULL DEFAULT 5,
  start_date            DATE          NULL,
  end_date              DATE          NULL,
  total_paid            DECIMAL(16,2) NOT NULL DEFAULT 0,
  status                ENUM('active','closed','written_off') NOT NULL DEFAULT 'active',
  notes                 TEXT          NULL,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_type (user_id, loan_type),
  KEY idx_user_status (user_id, status),
  CONSTRAINT fk_loan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t323_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t323: ULIP Tracker Migration
CREATE TABLE IF NOT EXISTS ulip_policies (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED  NOT NULL,
  policy_name         VARCHAR(150)  NOT NULL,
  insurer             VARCHAR(100)  NULL,
  policy_number       VARCHAR(60)   NULL,
  premium_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  premium_frequency   ENUM('monthly','quarterly','half_yearly','annual') NOT NULL DEFAULT 'annual',
  sum_assured         DECIMAL(16,2) NOT NULL DEFAULT 0,
  start_date          DATE          NULL,
  maturity_date       DATE          NULL,
  current_fund_value  DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_premium_paid  DECIMAL(16,2) NOT NULL DEFAULT 0,
  lock_in_years       TINYINT       NOT NULL DEFAULT 5,
  status              ENUM('active','surrendered','matured','lapsed') NOT NULL DEFAULT 'active',
  notes               TEXT          NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_ulip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ulip_fund_values (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ulip_id     INT UNSIGNED  NOT NULL,
  fund_name   VARCHAR(150)  NOT NULL,
  units       DECIMAL(14,4) NOT NULL DEFAULT 0,
  nav         DECIMAL(12,4) NOT NULL DEFAULT 0,
  value_date  DATE          NOT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ulip (ulip_id, value_date),
  CONSTRAINT fk_ufv_ulip FOREIGN KEY (ulip_id) REFERENCES ulip_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t461_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t461: ULIP Tracker Full Migration
CREATE TABLE IF NOT EXISTS ulip_switches (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ulip_id     INT UNSIGNED  NOT NULL,
  from_fund   VARCHAR(150)  NOT NULL,
  to_fund     VARCHAR(150)  NOT NULL,
  amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  switch_date DATE          NOT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ulip (ulip_id, switch_date),
  CONSTRAINT fk_usw_ulip FOREIGN KEY (ulip_id) REFERENCES ulip_policies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ulip_fund_values
  ADD INDEX IF NOT EXISTS idx_ulip_fund_date (ulip_id, fund_name, value_date);


-- ──────────────────────────────────────────────────────
-- t462_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t462: Premium Calendar Migration
CREATE TABLE IF NOT EXISTS premium_payments (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NOT NULL,
  policy_id      INT UNSIGNED  NOT NULL,
  due_date       DATE          NOT NULL,
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_date      DATE          NOT NULL,
  payment_method VARCHAR(30)   NULL,
  notes          VARCHAR(255)  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_policy_due (user_id, policy_id, due_date),
  KEY idx_user_paid (user_id, paid_date),
  CONSTRAINT fk_pp_user   FOREIGN KEY (user_id)   REFERENCES users(id)               ON DELETE CASCADE,
  CONSTRAINT fk_pp_policy FOREIGN KEY (policy_id) REFERENCES insurance_policies(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t463_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t463: Property Portfolio Migration
CREATE TABLE IF NOT EXISTS properties (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED  NOT NULL,
  property_name    VARCHAR(150)  NOT NULL,
  property_type    ENUM('residential','commercial','land','agricultural','other') NOT NULL DEFAULT 'residential',
  address          VARCHAR(255)  NULL,
  area_sqft        DECIMAL(10,2) NULL,
  purchase_price   DECIMAL(16,2) NOT NULL DEFAULT 0,
  purchase_date    DATE          NULL,
  loan_outstanding DECIMAL(16,2) NOT NULL DEFAULT 0,
  monthly_rental   DECIMAL(12,2) NOT NULL DEFAULT 0,
  status           ENUM('active','sold') NOT NULL DEFAULT 'active',
  notes            TEXT          NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_type   (user_id, property_type),
  KEY idx_user_status (user_id, status),
  CONSTRAINT fk_prop_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_valuations (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  property_id     INT UNSIGNED  NOT NULL,
  current_value   DECIMAL(16,2) NOT NULL DEFAULT 0,
  valuation_date  DATE          NOT NULL,
  source          VARCHAR(50)   NULL DEFAULT 'manual',
  notes           VARCHAR(255)  NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_property_date (property_id, valuation_date),
  CONSTRAINT fk_pv_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t150_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t150: DigiLocker Integration Migration

CREATE TABLE IF NOT EXISTS user_documents (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  category    VARCHAR(40)   NOT NULL DEFAULT 'other',
  doc_name    VARCHAR(150)  NOT NULL,
  file_path   VARCHAR(255)  NOT NULL COMMENT 'Random filename in storage/documents/<user_id>/',
  file_size   INT UNSIGNED  NOT NULL DEFAULT 0,
  expiry_date DATE          NULL,
  source      ENUM('manual_upload','digilocker') NOT NULL DEFAULT 'manual_upload',
  uploaded_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_category (user_id, category),
  KEY idx_user_expiry   (user_id, expiry_date),
  CONSTRAINT fk_ud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digilocker_connections (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL UNIQUE,
  connected     TINYINT(1)   NOT NULL DEFAULT 0,
  access_token  VARCHAR(500) NULL COMMENT 'Encrypted with WDCrypt when OAuth is wired in',
  connected_at  DATETIME     NULL,
  CONSTRAINT fk_dc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IMPORTANT: storage/documents/ must be writable by PHP and NON-web-accessible
-- (serve files only through an authenticated download action, not directly).


-- ──────────────────────────────────────────────────────
-- t124_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t124: Real Estate Portfolio Migration
-- NO NEW TABLES — core property tracking already built in t463 (this session):
--   properties, property_valuations tables (see t463_migration.sql)
-- This file is a pure calculator (no persistence needed for what-if scenarios).
SELECT 1;


-- ──────────────────────────────────────────────────────
-- t55_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t55: Dashboard Widget Customizer Migration
CREATE TABLE IF NOT EXISTS dashboard_widget_layouts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL UNIQUE,
  layout_json JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dwl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_holdings, mf_nav_latest, mf_sips, insurance_policies (t122),
--             budget_actuals (t471), properties (t463)


-- ──────────────────────────────────────────────────────
-- t297_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t297: Customizable Dashboard Migration
CREATE TABLE IF NOT EXISTS dashboard_layouts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL UNIQUE,
  layout      JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t350_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t350: Font Size Preference Migration
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS font_size ENUM('small','medium','large','xlarge') NOT NULL DEFAULT 'medium' AFTER theme;


-- ──────────────────────────────────────────────────────
-- t373_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t373: Widget Mode Migration
-- No new tables required.
-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals, goal_checkins (tg005),
--             investor_streaks (t242), market_pulse session cache
SELECT 1;


-- ──────────────────────────────────────────────────────
-- t445_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t445: Customizable Overview Cards Migration
CREATE TABLE IF NOT EXISTS overview_card_prefs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL UNIQUE,
  card_order  JSON         NOT NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ocp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals, goal_checkins (tg005),
--             insurance_policies (t122), loans (t123)


-- ──────────────────────────────────────────────────────
-- t446_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t446: Portfolio Health Heatmap Migration
-- No new tables required.
-- Depends on: mf_holdings, mf_nav_latest, mf_sips, goals (existing)
-- NOTE: Uses h.first_purchase_date if it exists, falls back to h.created_at.
-- If mf_holdings has neither column, run:
--   ALTER TABLE mf_holdings ADD COLUMN IF NOT EXISTS first_purchase_date DATE NULL;
SELECT 1;


-- ──────────────────────────────────────────────────────
-- t242_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t242: Investor Streak & Milestones Migration

CREATE TABLE IF NOT EXISTS investor_streaks (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL UNIQUE,
  current_streak  INT UNSIGNED NOT NULL DEFAULT 0,
  longest_streak  INT UNSIGNED NOT NULL DEFAULT 0,
  last_checked    DATETIME     NULL,
  CONSTRAINT fk_is_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_checkins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  checkin_date  DATE         NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_date (user_id, checkin_date),
  CONSTRAINT fk_dci_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_badges (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  badge_key   VARCHAR(40)  NOT NULL,
  earned_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_badge (user_id, badge_key),
  CONSTRAINT fk_ub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_transactions, mf_holdings, mf_nav_latest, goals,
--             insurance_policies (t122), finance_profiles (ti001)


-- ──────────────────────────────────────────────────────
-- t371_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t371: WebAuthn Biometric Login Migration
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NOT NULL,
  credential_id  VARCHAR(512)  NOT NULL UNIQUE,
  public_key_spki TEXT         NULL COMMENT 'Raw attestation object (base64)',
  device_name    VARCHAR(100)  NOT NULL DEFAULT 'My Device',
  sign_count     INT UNSIGNED  NOT NULL DEFAULT 0,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at   DATETIME      NULL,
  KEY idx_user (user_id),
  CONSTRAINT fk_wc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t387_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t387: Session Security Migration
CREATE TABLE IF NOT EXISTS user_sessions (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED  NOT NULL,
  session_id   VARCHAR(128)  NOT NULL UNIQUE,
  ip_address   VARCHAR(45)   NULL,
  user_agent   VARCHAR(255)  NULL,
  device_label VARCHAR(100)  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  KEY idx_last_active (last_active),
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t389_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t389: GDPR-style Data Controls Migration
CREATE TABLE IF NOT EXISTS data_export_requests (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  filename    VARCHAR(255)  NOT NULL,
  file_size   INT UNSIGNED  NOT NULL DEFAULT 0,
  status      ENUM('processing','ready','expired') NOT NULL DEFAULT 'ready',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME      NOT NULL,
  KEY idx_user (user_id),
  CONSTRAINT fk_der_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_deletion_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  status        ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  requested_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  scheduled_for DATETIME     NOT NULL,
  cancelled_at  DATETIME     NULL,
  completed_at  DATETIME     NULL,
  KEY idx_user_status (user_id, status),
  KEY idx_scheduled (scheduled_for),
  CONSTRAINT fk_adr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: storage/exports/ directory must be writable by PHP
-- and should NOT be web-accessible directly (serve via download action only).
-- Add to .gitignore: storage/exports/*.json


-- ──────────────────────────────────────────────────────
-- t240_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t240: Onboarding Flow Migration
CREATE TABLE IF NOT EXISTS user_onboarding (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL UNIQUE,
  completed_steps JSON         NOT NULL DEFAULT ('[]'),
  skipped         TINYINT(1)   NOT NULL DEFAULT 0,
  started_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ob_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t454_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t454: Onboarding Setup Wizard Migration
-- Extends user_onboarding table (from t240_migration.sql) with wizard columns.

ALTER TABLE user_onboarding
  ADD COLUMN IF NOT EXISTS wizard_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER skipped,
  ADD COLUMN IF NOT EXISTS wizard_data JSON NULL AFTER wizard_completed;

-- If user_onboarding table doesn't exist yet (t240 not run), create it fully:
CREATE TABLE IF NOT EXISTS user_onboarding (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL UNIQUE,
  completed_steps   JSON         NOT NULL DEFAULT ('[]'),
  skipped           TINYINT(1)   NOT NULL DEFAULT 0,
  wizard_completed  TINYINT(1)   NOT NULL DEFAULT 0,
  wizard_data       JSON         NULL,
  started_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ob_user_wizard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: finance_profiles (ti001), goals (existing)


-- ──────────────────────────────────────────────────────
-- t471_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t471: Monthly Budget Tracker Migration

CREATE TABLE IF NOT EXISTS budget_plans (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  month       VARCHAR(7)   NOT NULL COMMENT 'YYYY-MM',
  plan_json   JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_month (user_id, month),
  CONSTRAINT fk_bp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_actuals (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED  NOT NULL,
  category    VARCHAR(60)   NOT NULL,
  txn_type    ENUM('income','expense','savings') NOT NULL DEFAULT 'expense',
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  txn_date    DATE          NOT NULL,
  description VARCHAR(255)  NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_month  (user_id, txn_date),
  KEY idx_user_cat    (user_id, category),
  CONSTRAINT fk_ba_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t378_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t378: WhatsApp / Email Report Sharing Migration

CREATE TABLE IF NOT EXISTS report_shares (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  channel     ENUM('whatsapp','email') NOT NULL,
  report_type VARCHAR(40)  NOT NULL DEFAULT 'summary',
  recipient   VARCHAR(150) NULL,
  success     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_rs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_share_tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token       VARCHAR(64)  NOT NULL UNIQUE,
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token (token),
  CONSTRAINT fk_rst_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: app_settings (t52 — for email_enabled, smtp_from_name config)


-- ──────────────────────────────────────────────────────
-- t485_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t485: Portfolio Treemap Migration
-- No new tables required.
-- Depends on: mf_holdings, mf_nav_latest (existing)
SELECT 1;


-- ──────────────────────────────────────────────────────
-- t488_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t488: Yield Curve Migration

CREATE TABLE IF NOT EXISTS yield_curve_reference (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenure_months SMALLINT     NOT NULL UNIQUE,
  tenure_label  VARCHAR(10)  NOT NULL,
  rate          DECIMAL(5,2) NOT NULL,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenure (tenure_months)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default reference curve (typical Indian bank FD rates)
INSERT IGNORE INTO yield_curve_reference (tenure_months, tenure_label, rate) VALUES
  (3,   '3M',  6.50),
  (6,   '6M',  7.00),
  (12,  '1Y',  7.10),
  (24,  '2Y',  7.25),
  (36,  '3Y',  7.00),
  (60,  '5Y',  6.75),
  (120, '10Y', 6.50);

-- NOTE: Gracefully handles absence of fd_investments table (skipped via
-- try/catch in PHP) — this is part of another module (api/mf/* or similar,
-- skipped per session rules). If fd_investments exists, user FDs auto-plot.


-- ──────────────────────────────────────────────────────
-- t406_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t406: Anonymous Benchmarking Migration

CREATE TABLE IF NOT EXISTS benchmark_opt_ins (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL UNIQUE,
  opted_in    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_boi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IMPORTANT: This table stores NO personally identifiable information.
-- No fund names, no exact amounts beyond ratios/percentages, no names/emails.
-- user_id is only used to upsert the user's OWN row (never exposed in queries).
CREATE TABLE IF NOT EXISTS benchmark_snapshots (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id                 INT UNSIGNED NOT NULL UNIQUE,
  age_bracket             VARCHAR(10)  NOT NULL COMMENT '18-24, 25-34, 35-44, 45-54, 55+',
  risk_profile            VARCHAR(30)  NOT NULL,
  savings_rate            DECIMAL(6,2) NULL COMMENT 'percent',
  gain_pct                DECIMAL(7,2) NULL COMMENT 'percent',
  sip_count               SMALLINT     NULL,
  monthly_sip_pct_income  DECIMAL(6,2) NULL COMMENT 'percent',
  num_holdings            SMALLINT     NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cohort (age_bracket, risk_profile),
  CONSTRAINT fk_bs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: finance_profiles (ti001), mf_holdings, mf_nav_latest, mf_sips,
--             budget_actuals (t471)


-- ──────────────────────────────────────────────────────
-- t503_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t503: Credit Card Optimizer Migration

CREATE TABLE IF NOT EXISTS credit_cards (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED  NOT NULL,
  card_name      VARCHAR(100)  NOT NULL,
  bank           VARCHAR(80)   NULL,
  credit_limit   DECIMAL(12,2) NOT NULL DEFAULT 0,
  outstanding    DECIMAL(12,2) NOT NULL DEFAULT 0,
  reward_rate    DECIMAL(5,2)  NOT NULL DEFAULT 1.00,
  reward_type    ENUM('cashback','points','miles') NOT NULL DEFAULT 'cashback',
  interest_rate  DECIMAL(5,2)  NOT NULL DEFAULT 42.00,
  due_date       TINYINT       NOT NULL DEFAULT 15,
  annual_fee     DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  CONSTRAINT fk_cc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- th001_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — th001: Daily Financial Journal Migration

CREATE TABLE IF NOT EXISTS journal_entries (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED  NOT NULL,
  entry_date      DATE          NOT NULL,
  title           VARCHAR(150)  NOT NULL DEFAULT 'Journal Entry',
  content         TEXT          NOT NULL,
  mood            ENUM('confident','optimistic','neutral','anxious','fearful','excited','regretful') NOT NULL DEFAULT 'neutral',
  tags            VARCHAR(255)  NULL COMMENT 'comma-separated',
  related_action  VARCHAR(40)   NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, entry_date),
  FULLTEXT KEY ft_content (title, content),
  CONSTRAINT fk_je_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t443_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t443: Morning Briefing Migration
CREATE TABLE IF NOT EXISTS morning_briefings (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  briefing_date  DATE         NOT NULL,
  briefing_json  JSON         NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_date (user_id, briefing_date),
  CONSTRAINT fk_mb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_holdings, mf_nav_latest, mf_sips, smart_alerts (t404)


-- ──────────────────────────────────────────────────────
-- t106_migration.sql
-- ──────────────────────────────────────────────────────

-- ============================================================
-- WealthDash — Migration t106: NPS Contribution Auto-detect
-- Task: Bank statement import staging for NPS contributions
-- Depends on: t99 (nps_transactions.tier, contribution_type, investment_fy)
-- Run ONCE — idempotent
-- ============================================================

-- 1. Import session log
CREATE TABLE IF NOT EXISTS `nps_import_sessions` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`    int(10) UNSIGNED NOT NULL,
  `user_id`         int(10) UNSIGNED NOT NULL,
  `bank_name`       varchar(80)      DEFAULT NULL COMMENT 'Detected or user-selected bank',
  `statement_from`  date             DEFAULT NULL COMMENT 'Statement period start',
  `statement_to`    date             DEFAULT NULL COMMENT 'Statement period end',
  `raw_filename`    varchar(255)     DEFAULT NULL,
  `total_rows`      int UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'Total rows parsed',
  `detected_rows`   int UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'NPS rows detected',
  `confirmed_rows`  int UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'Rows imported to nps_transactions',
  `status`          enum('pending','reviewed','imported','dismissed') NOT NULL DEFAULT 'pending',
  `import_notes`    varchar(255)     DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nps_imp_portfolio` (`portfolio_id`),
  KEY `idx_nps_imp_user`      (`user_id`),
  KEY `idx_nps_imp_status`    (`status`),
  CONSTRAINT `fk_nps_imp_portfolio`
    FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Staging rows — one per detected NPS transaction
CREATE TABLE IF NOT EXISTS `nps_import_staging` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`        int(10) UNSIGNED NOT NULL,
  `portfolio_id`      int(10) UNSIGNED NOT NULL,

  -- Raw data from bank statement
  `raw_date`          varchar(30)      DEFAULT NULL,
  `raw_narration`     varchar(500)     DEFAULT NULL,
  `raw_debit`         varchar(30)      DEFAULT NULL,
  `raw_credit`        varchar(30)      DEFAULT NULL,
  `raw_balance`       varchar(30)      DEFAULT NULL,
  `raw_row_number`    int UNSIGNED     DEFAULT NULL COMMENT 'Row number in original file',

  -- Parsed / normalised
  `txn_date`          date             DEFAULT NULL,
  `amount`            decimal(16,2)    DEFAULT NULL,
  `detected_bank`     varchar(80)      DEFAULT NULL,
  `detected_pfm`      varchar(100)     DEFAULT NULL COMMENT 'Detected PFM from narration',
  `detected_tier`     enum('tier1','tier2') DEFAULT 'tier1',
  `detected_contrib`  enum('SELF','EMPLOYER') DEFAULT 'SELF',
  `confidence`        tinyint UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 match confidence',
  `match_keywords`    varchar(255)     DEFAULT NULL COMMENT 'Keywords that triggered detection',

  -- User-assigned (review step)
  `scheme_id`         int(10) UNSIGNED DEFAULT NULL,
  `tier`              enum('tier1','tier2') DEFAULT 'tier1',
  `contribution_type` enum('SELF','EMPLOYER') DEFAULT 'SELF',
  `units`             decimal(18,4)    DEFAULT NULL,
  `nav`               decimal(12,4)    DEFAULT NULL,
  `investment_fy`     varchar(10)      DEFAULT NULL,
  `notes`             varchar(255)     DEFAULT NULL,

  -- Import status
  `row_status`        enum('detected','accepted','rejected','imported','duplicate') NOT NULL DEFAULT 'detected',
  `imported_txn_id`   int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to nps_transactions.id after import',

  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`        datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_nps_stg_session`   (`session_id`),
  KEY `idx_nps_stg_portfolio` (`portfolio_id`),
  KEY `idx_nps_stg_status`    (`row_status`),
  KEY `idx_nps_stg_date`      (`txn_date`),
  CONSTRAINT `fk_nps_stg_session`
    FOREIGN KEY (`session_id`) REFERENCES `nps_import_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nps_stg_portfolio`
    FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure nps_transactions has required columns (added by t99; safe ALTER IF NOT EXISTS)
ALTER TABLE `nps_transactions`
  ADD COLUMN IF NOT EXISTS `tier`              enum('tier1','tier2') NOT NULL DEFAULT 'tier1' AFTER `scheme_id`,
  ADD COLUMN IF NOT EXISTS `contribution_type` enum('SELF','EMPLOYER') NOT NULL DEFAULT 'SELF'  AFTER `tier`,
  ADD COLUMN IF NOT EXISTS `investment_fy`     varchar(10) DEFAULT NULL                          AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `import_source`     enum('manual','bank_import','csv_upload') NOT NULL DEFAULT 'manual' AFTER `investment_fy`,
  ADD COLUMN IF NOT EXISTS `staging_id`        int(10) UNSIGNED DEFAULT NULL COMMENT 'FK to nps_import_staging' AFTER `import_source`;

SELECT 't106 NPS Bank Import migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t155_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t155: Child Education Planner Migration
CREATE TABLE IF NOT EXISTS education_plans (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  child_name  VARCHAR(80)  NOT NULL,
  target_age  TINYINT      NOT NULL DEFAULT 18,
  inputs      JSON         NOT NULL,
  results     JSON         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_child (user_id, child_name),
  CONSTRAINT fk_edu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t234_migration.sql
-- ──────────────────────────────────────────────────────

-- ════════════════════════════════════════════════════════════════════════════
-- WealthDash — Migration: t234 (SIP vs Lumpsum Historical Backtest)
-- Depends on: nav_history table (t160), funds table
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. Backtest cache table ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mf_backtest_cache` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `fund_id`        INT UNSIGNED      NOT NULL,
    `period`         VARCHAR(5)        NOT NULL COMMENT '1Y,3Y,5Y,10Y,etc.',
    `monthly_amount` DECIMAL(12,2)     NOT NULL DEFAULT 5000.00,
    `sip_day`        TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    `result_json`    MEDIUMTEXT        NOT NULL COMMENT 'Cached JSON response',
    `nav_count`      INT UNSIGNED      NOT NULL DEFAULT 0 COMMENT 'nav_history rows at cache time',
    `computed_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_backtest_cache` (`fund_id`, `period`, `monthly_amount`, `sip_day`),
    INDEX `idx_backtest_fund`     (`fund_id`),
    INDEX `idx_backtest_computed` (`computed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cache for SIP vs Lumpsum backtest results (t234)';

-- ── 2. Ensure nav_history index for fast fund+date range queries ─────────────
ALTER TABLE `nav_history`
    ADD INDEX IF NOT EXISTS `idx_navhist_fund_date` (`fund_id`, `nav_date`);

-- ── 3. Record migration ──────────────────────────────────────────────────────
INSERT IGNORE INTO `migration_log` (`filename`, `checksum`, `batch`, `notes`)
VALUES ('t234_migration.sql', SHA2('t234', 256), 1, 'SIP vs Lumpsum Historical Backtest — cache table + nav_history index');


-- ──────────────────────────────────────────────────────
-- t404_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — t404: Smart Alerts v2 Migration
CREATE TABLE IF NOT EXISTS smart_alerts (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED  NOT NULL,
  alert_type    VARCHAR(40)   NOT NULL,
  dedup_key     VARCHAR(150)  NOT NULL,
  icon          VARCHAR(8)    NULL,
  message       VARCHAR(255)  NOT NULL,
  severity      ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  relevant_date DATE          NULL,
  is_read       TINYINT(1)    NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_dedup (user_id, dedup_key),
  KEY idx_user_read (user_id, is_read),
  CONSTRAINT fk_sa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smart_alert_settings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL UNIQUE,
  sip_due         TINYINT(1)   NOT NULL DEFAULT 1,
  insurance_due   TINYINT(1)   NOT NULL DEFAULT 1,
  loan_emi_due    TINYINT(1)   NOT NULL DEFAULT 1,
  drawdown_alert  TINYINT(1)   NOT NULL DEFAULT 1,
  gain_alert      TINYINT(1)   NOT NULL DEFAULT 1,
  goal_milestone  TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_sas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Depends on: mf_sips, insurance_policies (t122), loans (t123), mf_holdings, mf_nav_latest


-- ──────────────────────────────────────────────────────
-- ti001_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — ti001: Personal Finance Profile Migration
CREATE TABLE IF NOT EXISTS finance_profiles (
  id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id               INT UNSIGNED  NOT NULL UNIQUE,
  age                   TINYINT       NULL,
  employment_type       ENUM('salaried','self_employed','business','freelancer','retired') NOT NULL DEFAULT 'salaried',
  annual_income         DECIMAL(14,2) NULL DEFAULT 0,
  tax_slab              VARCHAR(5)    NULL DEFAULT '30',
  risk_profile          ENUM('conservative','moderate','moderately_aggressive','aggressive') NOT NULL DEFAULT 'moderate',
  investment_horizon    ENUM('short','medium','long') NOT NULL DEFAULT 'long',
  monthly_expenses      DECIMAL(12,2) NULL DEFAULT 0,
  monthly_savings       DECIMAL(12,2) NULL DEFAULT 0,
  emergency_fund_months TINYINT       NULL DEFAULT 0,
  dependents            TINYINT       NULL DEFAULT 0,
  has_life_insurance    TINYINT(1)    NOT NULL DEFAULT 0,
  has_health_insurance  TINYINT(1)    NOT NULL DEFAULT 0,
  goals                 JSON          NULL,
  income_sources        JSON          NULL,
  notes                 TEXT          NULL,
  created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- tj005_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash — tj005: Annual Financial Review Wizard Migration

CREATE TABLE IF NOT EXISTS annual_reviews (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  review_year   SMALLINT     NOT NULL,
  checked_items JSON         NOT NULL DEFAULT ('[]'),
  notes         TEXT         NULL,
  completed_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_year (user_id, review_year),
  CONSTRAINT fk_ar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t38_migration.sql
-- ──────────────────────────────────────────────────────

-- t38: Stocks Screener — Basic Filter + Sort
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS screener_filters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    filter_name VARCHAR(100) NOT NULL,
    filter_config JSON NOT NULL COMMENT 'JSON object with filter criteria',
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS screener_universe (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(200) NOT NULL,
    isin VARCHAR(20),
    exchange VARCHAR(10) DEFAULT 'NSE',
    sector VARCHAR(100),
    industry VARCHAR(100),
    market_cap DECIMAL(25,4),
    market_cap_category ENUM('LARGE','MID','SMALL','MICRO') DEFAULT NULL,
    pe_ratio DECIMAL(10,4),
    pb_ratio DECIMAL(10,4),
    eps DECIMAL(15,4),
    roe DECIMAL(8,4),
    roce DECIMAL(8,4),
    debt_to_equity DECIMAL(10,4),
    current_ratio DECIMAL(10,4),
    dividend_yield DECIMAL(8,4),
    revenue_growth_1y DECIMAL(8,4),
    profit_growth_1y DECIMAL(8,4),
    price_52w_high DECIMAL(15,4),
    price_52w_low DECIMAL(15,4),
    current_price DECIMAL(15,4),
    avg_volume_30d BIGINT UNSIGNED,
    data_date DATE NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_symbol (stock_symbol),
    INDEX idx_sector (sector),
    INDEX idx_market_cap_cat (market_cap_category),
    INDEX idx_pe (pe_ratio),
    INDEX idx_data_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t39_migration.sql
-- ──────────────────────────────────────────────────────

-- t39: LTCG/STCG Stocks Report
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS stock_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(100) NOT NULL,
    transaction_type ENUM('BUY','SELL') NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    brokerage DECIMAL(10,2) DEFAULT 0.00,
    stt DECIMAL(10,2) DEFAULT 0.00,
    transaction_date DATE NOT NULL,
    exchange VARCHAR(10) DEFAULT 'NSE',
    isin VARCHAR(20),
    grandfathered_price DECIMAL(15,4) DEFAULT NULL COMMENT 'FMV as on 31-Jan-2018 for pre-2018 holdings',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_date (transaction_date),
    INDEX idx_type (transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tax_lots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(100) NOT NULL,
    buy_transaction_id BIGINT UNSIGNED,
    sell_transaction_id BIGINT UNSIGNED DEFAULT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    buy_price DECIMAL(15,4) NOT NULL,
    sell_price DECIMAL(15,4) DEFAULT NULL,
    buy_date DATE NOT NULL,
    sell_date DATE DEFAULT NULL,
    gain_loss DECIMAL(15,4) DEFAULT NULL,
    tax_category ENUM('STCG','LTCG','UNREALIZED') DEFAULT 'UNREALIZED',
    financial_year VARCHAR(10) COMMENT 'e.g. 2024-25',
    grandfathered_price DECIMAL(15,4) DEFAULT NULL,
    INDEX idx_user_fy (user_id, financial_year),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_category (tax_category),
    INDEX idx_sell_date (sell_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t114_migration.sql
-- ──────────────────────────────────────────────────────

-- t114: Gold Tracker — Physical, Digital, ETF Unified
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS gold_holdings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    gold_type ENUM('PHYSICAL','DIGITAL','ETF','FUND') NOT NULL,
    sub_type VARCHAR(50) COMMENT 'coins/bar/jewellery for physical; mmtc/augmont for digital; ticker for ETF',
    name VARCHAR(150) NOT NULL,
    quantity DECIMAL(15,4) NOT NULL COMMENT 'grams for physical/digital, units for ETF/fund',
    buy_price DECIMAL(15,4) NOT NULL COMMENT 'per gram or per unit',
    buy_date DATE NOT NULL,
    making_charges DECIMAL(10,2) DEFAULT 0.00 COMMENT 'for jewellery only',
    purity VARCHAR(10) DEFAULT '24K' COMMENT '24K/22K/18K',
    folio_number VARCHAR(50) DEFAULT NULL COMMENT 'for ETF/fund',
    dp_id VARCHAR(50) DEFAULT NULL COMMENT 'for ETF in demat',
    custodian VARCHAR(100) DEFAULT NULL COMMENT 'for digital gold',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_gold_type (gold_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gold_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    holding_id BIGINT UNSIGNED NOT NULL,
    transaction_type ENUM('BUY','SELL','SIP') NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    transaction_date DATE NOT NULL,
    charges DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (holding_id) REFERENCES gold_holdings(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_holding_id (holding_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t116_migration.sql
-- ──────────────────────────────────────────────────────

-- t116: Corporate Bonds / NCDs — Listed and Unlisted
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS bonds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    bond_type ENUM('NCD','BOND','DEBENTURE','COMMERCIAL_PAPER') NOT NULL,
    listing_type ENUM('LISTED','UNLISTED') NOT NULL DEFAULT 'LISTED',
    issuer_name VARCHAR(200) NOT NULL,
    isin VARCHAR(20),
    series VARCHAR(50),
    face_value DECIMAL(15,2) NOT NULL DEFAULT 1000.00,
    quantity INT UNSIGNED NOT NULL,
    purchase_price DECIMAL(15,4) NOT NULL,
    purchase_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    coupon_rate DECIMAL(8,4) NOT NULL COMMENT 'Annual interest rate %',
    coupon_frequency ENUM('MONTHLY','QUARTERLY','SEMI_ANNUAL','ANNUAL','CUMULATIVE','ON_MATURITY') DEFAULT 'ANNUAL',
    credit_rating VARCHAR(20) COMMENT 'AAA/AA+/etc',
    rating_agency VARCHAR(50),
    secured TINYINT(1) DEFAULT 1,
    broker VARCHAR(100),
    dp_id VARCHAR(50),
    redemption_type ENUM('BULLET','CALLABLE','PUTTABLE','STEP_UP') DEFAULT 'BULLET',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_bond_type (bond_type),
    INDEX idx_listing (listing_type),
    INDEX idx_maturity (maturity_date),
    INDEX idx_isin (isin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bond_cashflows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    bond_id BIGINT UNSIGNED NOT NULL,
    cashflow_type ENUM('COUPON','PRINCIPAL','PARTIAL_REDEMPTION') NOT NULL,
    scheduled_date DATE NOT NULL,
    amount DECIMAL(15,4) NOT NULL,
    received TINYINT(1) DEFAULT 0,
    received_date DATE DEFAULT NULL,
    tds_deducted DECIMAL(10,2) DEFAULT 0.00,
    net_amount DECIMAL(15,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bond_id) REFERENCES bonds(id) ON DELETE CASCADE,
    INDEX idx_bond_id (bond_id),
    INDEX idx_user_id (user_id),
    INDEX idx_scheduled_date (scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t118_migration.sql
-- ──────────────────────────────────────────────────────

-- t118: RBI Floating Rate Bonds & G-Secs / T-Bills
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS govt_securities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    security_type ENUM('RBI_FRB','GSEC','TBILL','SDL') NOT NULL,
    security_name VARCHAR(200) NOT NULL,
    isin VARCHAR(20),
    face_value DECIMAL(15,2) NOT NULL DEFAULT 1000.00,
    quantity INT UNSIGNED NOT NULL,
    purchase_price DECIMAL(15,4) NOT NULL,
    purchase_date DATE NOT NULL,
    maturity_date DATE NOT NULL,
    coupon_rate DECIMAL(8,4) DEFAULT NULL COMMENT 'NULL for T-Bills (discount instrument)',
    coupon_frequency ENUM('SEMI_ANNUAL','QUARTERLY','FLOATING') DEFAULT 'SEMI_ANNUAL',
    is_floating TINYINT(1) DEFAULT 0 COMMENT '1 for FRB where rate resets',
    floating_reference VARCHAR(50) DEFAULT NULL COMMENT 'NSS rate / Repo rate reference',
    floating_spread DECIMAL(6,4) DEFAULT NULL COMMENT 'Spread above reference rate',
    platform VARCHAR(100) COMMENT 'RBI Retail Direct/Zerodha/NSE goBID',
    redemption_price DECIMAL(15,4) DEFAULT NULL COMMENT 'For T-Bills = face_value',
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_security_type (security_type),
    INDEX idx_maturity (maturity_date),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gsec_cashflows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    security_id BIGINT UNSIGNED NOT NULL,
    cashflow_type ENUM('COUPON','MATURITY','PARTIAL_SALE') NOT NULL,
    scheduled_date DATE NOT NULL,
    coupon_rate_applied DECIMAL(8,4) DEFAULT NULL COMMENT 'Actual rate for floating bonds',
    amount DECIMAL(15,4) NOT NULL,
    received TINYINT(1) DEFAULT 0,
    received_date DATE DEFAULT NULL,
    tds_deducted DECIMAL(10,2) DEFAULT 0.00,
    net_amount DECIMAL(15,4),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (security_id) REFERENCES govt_securities(id) ON DELETE CASCADE,
    INDEX idx_security_id (security_id),
    INDEX idx_user_id (user_id),
    INDEX idx_scheduled_date (scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t121_migration.sql
-- ──────────────────────────────────────────────────────

-- t121: International Stocks / LRS Tracker
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS international_stocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ticker VARCHAR(20) NOT NULL,
    stock_name VARCHAR(200) NOT NULL,
    exchange VARCHAR(20) NOT NULL COMMENT 'NYSE/NASDAQ/LSE/etc',
    country VARCHAR(5) DEFAULT 'US',
    currency VARCHAR(5) DEFAULT 'USD',
    quantity DECIMAL(15,6) NOT NULL COMMENT 'fractional shares allowed',
    avg_buy_price_foreign DECIMAL(15,4) NOT NULL COMMENT 'in foreign currency',
    avg_buy_price_inr DECIMAL(15,4) NOT NULL COMMENT 'INR at time of purchase',
    current_price_foreign DECIMAL(15,4) DEFAULT NULL,
    current_price_inr DECIMAL(15,4) DEFAULT NULL,
    broker_platform VARCHAR(100) COMMENT 'Vested/Indmoney/Stockal/etc',
    sector VARCHAR(100),
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    last_refreshed TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_ticker (ticker),
    INDEX idx_exchange (exchange),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lrs_remittances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    remittance_date DATE NOT NULL,
    amount_inr DECIMAL(15,2) NOT NULL,
    amount_foreign DECIMAL(15,4) NOT NULL,
    currency VARCHAR(5) DEFAULT 'USD',
    exchange_rate DECIMAL(10,4) NOT NULL,
    purpose VARCHAR(200) COMMENT 'Investment/Education/Travel/etc',
    bank_name VARCHAR(100),
    forex_charges DECIMAL(10,2) DEFAULT 0.00,
    tcs_paid DECIMAL(10,2) DEFAULT 0.00 COMMENT 'TCS @ 20% above 7L threshold',
    financial_year VARCHAR(10),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_fy (financial_year),
    INDEX idx_date (remittance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intl_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    lrs_id BIGINT UNSIGNED DEFAULT NULL,
    transaction_type ENUM('BUY','SELL','DIVIDEND') NOT NULL,
    quantity DECIMAL(15,6) NOT NULL,
    price_foreign DECIMAL(15,4) NOT NULL,
    price_inr DECIMAL(15,4) NOT NULL,
    exchange_rate DECIMAL(10,4) NOT NULL,
    transaction_date DATE NOT NULL,
    charges_foreign DECIMAL(10,4) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stock_id) REFERENCES international_stocks(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_stock_id (stock_id),
    INDEX idx_date (transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t145_migration.sql
-- ──────────────────────────────────────────────────────

-- t145: Stock Picker Reality Check vs Nifty 50
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS benchmark_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    benchmark_name VARCHAR(50) NOT NULL COMMENT 'NIFTY50/SENSEX/NIFTY500',
    data_date DATE NOT NULL,
    close_value DECIMAL(15,4) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bench_date (benchmark_name, data_date),
    INDEX idx_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portfolio_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    snapshot_date DATE NOT NULL,
    portfolio_value DECIMAL(20,4) NOT NULL,
    invested_value DECIMAL(20,4) NOT NULL,
    xirr DECIMAL(8,4) DEFAULT NULL COMMENT 'XIRR %',
    twrr DECIMAL(8,4) DEFAULT NULL COMMENT 'Time-weighted return %',
    nifty50_value DECIMAL(15,4) DEFAULT NULL COMMENT 'Nifty 50 on same date',
    nifty50_returns DECIMAL(8,4) DEFAULT NULL COMMENT 'Nifty 50 equivalent % returns',
    alpha DECIMAL(8,4) DEFAULT NULL COMMENT 'portfolio_returns - benchmark_returns',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_date (user_id, snapshot_date),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t432_migration.sql
-- ──────────────────────────────────────────────────────

-- t432: Portfolio P/E vs Market P/E
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS stock_fundamentals_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_symbol VARCHAR(20) NOT NULL,
    isin VARCHAR(20),
    pe_ratio DECIMAL(10,4) DEFAULT NULL,
    pb_ratio DECIMAL(10,4) DEFAULT NULL,
    eps DECIMAL(15,4) DEFAULT NULL,
    market_cap DECIMAL(25,4) DEFAULT NULL,
    sector VARCHAR(100),
    industry VARCHAR(100),
    data_date DATE NOT NULL,
    source VARCHAR(50) DEFAULT 'NSE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_symbol_date (stock_symbol, data_date),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_pe_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    index_name VARCHAR(50) NOT NULL COMMENT 'NIFTY50/NIFTY500/SENSEX',
    data_date DATE NOT NULL,
    pe_ratio DECIMAL(10,4) NOT NULL,
    pb_ratio DECIMAL(10,4) DEFAULT NULL,
    div_yield DECIMAL(8,4) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_index_date (index_name, data_date),
    INDEX idx_date (data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t435_migration.sql
-- ──────────────────────────────────────────────────────

-- t435: Watchlist with Price Targets
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS watchlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(150) NOT NULL,
    exchange VARCHAR(10) DEFAULT 'NSE',
    isin VARCHAR(20),
    buy_target DECIMAL(15,4) DEFAULT NULL COMMENT 'Target buy price',
    sell_target DECIMAL(15,4) DEFAULT NULL COMMENT 'Target sell / exit price',
    stop_loss DECIMAL(15,4) DEFAULT NULL,
    current_price DECIMAL(15,4) DEFAULT NULL,
    rationale TEXT COMMENT 'Why watching this stock',
    sector VARCHAR(100),
    tags VARCHAR(255) COMMENT 'comma-separated custom tags',
    alert_on_buy_target TINYINT(1) DEFAULT 1,
    alert_on_sell_target TINYINT(1) DEFAULT 1,
    alert_on_stop_loss TINYINT(1) DEFAULT 1,
    notes TEXT,
    added_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_symbol (user_id, stock_symbol),
    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watchlist_price_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    watchlist_id BIGINT UNSIGNED NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (watchlist_id) REFERENCES watchlist(id) ON DELETE CASCADE,
    INDEX idx_watchlist_id (watchlist_id),
    INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t436_migration.sql
-- ──────────────────────────────────────────────────────

-- t436: Stock SIP — Regular Stock Purchase Tracker
-- Worker: ID-W3

CREATE TABLE IF NOT EXISTS stock_sip (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    stock_symbol VARCHAR(20) NOT NULL,
    stock_name VARCHAR(150) NOT NULL,
    exchange VARCHAR(10) DEFAULT 'NSE',
    sip_amount DECIMAL(15,2) NOT NULL COMMENT 'Fixed INR amount per installment',
    frequency ENUM('DAILY','WEEKLY','FORTNIGHTLY','MONTHLY','QUARTERLY') DEFAULT 'MONTHLY',
    sip_day TINYINT UNSIGNED DEFAULT NULL COMMENT 'Day of month (1-28) or day of week (0-6)',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL COMMENT 'NULL = open-ended',
    broker VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_symbol (stock_symbol),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_sip_installments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sip_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    installment_date DATE NOT NULL,
    quantity DECIMAL(15,6) NOT NULL,
    price DECIMAL(15,4) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status ENUM('PENDING','EXECUTED','FAILED','SKIPPED') DEFAULT 'PENDING',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sip_id) REFERENCES stock_sip(id) ON DELETE CASCADE,
    INDEX idx_sip_id (sip_id),
    INDEX idx_user_id (user_id),
    INDEX idx_date (installment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t302_migration.sql
-- ──────────────────────────────────────────────────────

-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t302: Groww Portfolio Import
-- Migration: t302_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `groww_import_sessions` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `import_type`     ENUM('csv','api','manual') NOT NULL DEFAULT 'csv',
    `filename`        VARCHAR(255) DEFAULT NULL,
    `status`          ENUM('pending','processing','done','failed','partial') NOT NULL DEFAULT 'pending',
    `total_rows`      INT UNSIGNED DEFAULT 0,
    `imported`        INT UNSIGNED DEFAULT 0,
    `skipped`         INT UNSIGNED DEFAULT 0,
    `errors`          INT UNSIGNED DEFAULT 0,
    `mf_count`        INT UNSIGNED DEFAULT 0,
    `stock_count`     INT UNSIGNED DEFAULT 0,
    `error_log`       TEXT DEFAULT NULL,
    `raw_response`    MEDIUMTEXT DEFAULT NULL COMMENT 'Raw JSON/CSV for audit',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`      (`user_id`),
    INDEX `idx_portfolio` (`portfolio_id`),
    INDEX `idx_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_fund_map` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `groww_name`      VARCHAR(300) NOT NULL COMMENT 'Scheme name as Groww exports it',
    `fund_id`         INT UNSIGNED DEFAULT NULL COMMENT 'Resolved WealthDash fund id',
    `scheme_code`     VARCHAR(20)  DEFAULT NULL,
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `is_confirmed`    TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_groww_name` (`groww_name`(200)),
    INDEX `idx_fund_id` (`fund_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t334_migration.sql
-- ──────────────────────────────────────────────────────

-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t334: Bulk Import (Excel Template, 50 fields)
-- Migration: t334_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `bulk_import_sessions` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `import_source`   VARCHAR(50)  NOT NULL DEFAULT 'excel' COMMENT 'excel|csv|api',
    `asset_type`      ENUM('mf','stocks','fd','gold','reits','smallcase','mixed') NOT NULL DEFAULT 'mf',
    `filename`        VARCHAR(255) DEFAULT NULL,
    `status`          ENUM('pending','validating','importing','done','failed','partial') DEFAULT 'pending',
    `total_rows`      INT UNSIGNED DEFAULT 0,
    `valid_rows`      INT UNSIGNED DEFAULT 0,
    `imported`        INT UNSIGNED DEFAULT 0,
    `skipped`         INT UNSIGNED DEFAULT 0,
    `error_count`     INT UNSIGNED DEFAULT 0,
    `validation_log`  MEDIUMTEXT DEFAULT NULL COMMENT 'JSON array of validation errors',
    `import_log`      MEDIUMTEXT DEFAULT NULL COMMENT 'JSON array of import results',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`      (`user_id`),
    INDEX `idx_portfolio` (`portfolio_id`),
    INDEX `idx_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which template version was used (for future compatibility)
CREATE TABLE IF NOT EXISTS `bulk_import_templates` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `version`         VARCHAR(20)  NOT NULL UNIQUE,
    `asset_type`      VARCHAR(50)  NOT NULL,
    `field_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `fields_json`     MEDIUMTEXT   NOT NULL COMMENT 'JSON array of field definitions',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: MF template v1 with 50 fields
INSERT IGNORE INTO `bulk_import_templates` (version, asset_type, field_count, fields_json)
VALUES ('mf_v1', 'mf', 50, '["fund_name","scheme_code","isin","amc","category","sub_category","plan_type","option_type","folio_number","portfolio_name","transaction_type","txn_date","units","nav","amount","stamp_duty","exit_load","stt","gst","brokerage","platform","advisor","bank_account","payment_mode","cheque_number","utr_number","sip_id","sip_frequency","sip_day","sip_start_date","sip_end_date","lumpsum_flag","switch_from_fund","switch_to_fund","switch_units","redemption_bank","redemption_ifsc","redemption_account","dividend_type","dividend_amount","dividend_date","xirr","investment_fy","cost_of_acquisition","indexed_cost","capital_gain_type","stcg_amount","ltcg_amount","grandfathered_nav","notes"]');


-- ──────────────────────────────────────────────────────
-- t392_migration.sql
-- ──────────────────────────────────────────────────────

-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t392: Groww API Sync (MF + Stocks)
-- Migration: t392_migration.sql
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `groww_api_credentials` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL UNIQUE,
    `access_token`    TEXT         DEFAULT NULL COMMENT 'Encrypted bearer token',
    `refresh_token`   TEXT         DEFAULT NULL COMMENT 'Encrypted refresh token',
    `token_expires_at` DATETIME    DEFAULT NULL,
    `linked_email`    VARCHAR(200) DEFAULT NULL,
    `linked_mobile`   VARCHAR(15)  DEFAULT NULL,
    `scope`           VARCHAR(200) DEFAULT NULL COMMENT 'mf,stocks,profile',
    `status`          ENUM('active','expired','revoked','error') NOT NULL DEFAULT 'active',
    `last_sync_at`    DATETIME     DEFAULT NULL,
    `last_sync_type`  VARCHAR(50)  DEFAULT NULL,
    `error_msg`       VARCHAR(500) DEFAULT NULL,
    `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`   (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_sync_log` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `portfolio_id`    INT UNSIGNED NOT NULL,
    `sync_type`       ENUM('mf_holdings','mf_transactions','stock_holdings','stock_transactions','full') NOT NULL,
    `status`          ENUM('running','done','failed','partial') NOT NULL DEFAULT 'running',
    `mf_synced`       INT UNSIGNED DEFAULT 0,
    `stock_synced`    INT UNSIGNED DEFAULT 0,
    `errors`          INT UNSIGNED DEFAULT 0,
    `error_detail`    TEXT DEFAULT NULL,
    `api_calls`       INT UNSIGNED DEFAULT 0,
    `started_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at`    DATETIME DEFAULT NULL,
    INDEX `idx_user`  (`user_id`),
    INDEX `idx_date`  (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_mf_holdings_raw` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `sync_log_id`     INT UNSIGNED NOT NULL,
    `groww_folio`     VARCHAR(100) DEFAULT NULL,
    `groww_scheme_id` VARCHAR(100) DEFAULT NULL,
    `scheme_name`     VARCHAR(300) NOT NULL,
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `units`           DECIMAL(14,4) DEFAULT NULL,
    `nav`             DECIMAL(12,4) DEFAULT NULL,
    `current_value`   DECIMAL(14,2) DEFAULT NULL,
    `invested_value`  DECIMAL(14,2) DEFAULT NULL,
    `fund_id`         INT UNSIGNED DEFAULT NULL COMMENT 'Resolved WD fund_id',
    `is_mapped`       TINYINT(1)   NOT NULL DEFAULT 0,
    `synced_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user`  (`user_id`),
    INDEX `idx_sync`  (`sync_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groww_stock_holdings_raw` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT UNSIGNED NOT NULL,
    `sync_log_id`     INT UNSIGNED NOT NULL,
    `symbol`          VARCHAR(30)  NOT NULL,
    `company_name`    VARCHAR(200) DEFAULT NULL,
    `exchange`        VARCHAR(10)  DEFAULT 'NSE',
    `isin`            VARCHAR(20)  DEFAULT NULL,
    `quantity`        DECIMAL(14,4) DEFAULT NULL,
    `avg_price`       DECIMAL(12,4) DEFAULT NULL,
    `ltp`             DECIMAL(12,4) DEFAULT NULL,
    `invested_value`  DECIMAL(14,2) DEFAULT NULL,
    `current_value`   DECIMAL(14,2) DEFAULT NULL,
    `pnl`             DECIMAL(14,2) DEFAULT NULL,
    `synced_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user`  (`user_id`),
    INDEX `idx_sync`  (`sync_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t490_migration.sql
-- ──────────────────────────────────────────────────────

-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t490: CSV Importer v3 (Auto-Detect Any Format)
-- Migration: t490_migration.sql
-- NOTE: mf_import_csv_v3.php already exists in api/mutual_funds/
-- This migration adds the import_history tracking table
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `csv_import_v3_sessions` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`           INT UNSIGNED NOT NULL,
    `portfolio_id`      INT UNSIGNED DEFAULT NULL,
    `filename`          VARCHAR(255) DEFAULT NULL,
    `file_size`         INT UNSIGNED DEFAULT NULL,
    `detected_format`   VARCHAR(50)  DEFAULT NULL,
    `format_label`      VARCHAR(100) DEFAULT NULL,
    `confidence`        TINYINT UNSIGNED DEFAULT 0,
    `col_mapping_json`  TEXT         DEFAULT NULL COMMENT 'JSON of column index map',
    `header_row_index`  TINYINT UNSIGNED DEFAULT 0,
    `total_data_rows`   INT UNSIGNED DEFAULT 0,
    `preview_json`      MEDIUMTEXT   DEFAULT NULL COMMENT 'First 10 rows preview',
    `action`            ENUM('detect','import','preview') DEFAULT 'detect',
    `imported`          INT UNSIGNED DEFAULT 0,
    `skipped`           INT UNSIGNED DEFAULT 0,
    `errors`            INT UNSIGNED DEFAULT 0,
    `error_rows_json`   MEDIUMTEXT   DEFAULT NULL,
    `status`            ENUM('detected','previewed','imported','failed') DEFAULT 'detected',
    `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user`       (`user_id`),
    INDEX `idx_portfolio`  (`portfolio_id`),
    INDEX `idx_format`     (`detected_format`),
    INDEX `idx_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-saved custom column mappings (for recurring imports from same source)
CREATE TABLE IF NOT EXISTS `csv_column_mapping_presets` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `name`        VARCHAR(100) NOT NULL COMMENT 'e.g. My CAMS Format',
    `format_hint` VARCHAR(50)  DEFAULT NULL,
    `mapping_json`TEXT         NOT NULL COMMENT 'JSON column mapping',
    `use_count`   INT UNSIGNED DEFAULT 0,
    `last_used`   DATETIME     DEFAULT NULL,
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ──────────────────────────────────────────────────────
-- t136_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t136: AIS/TIS Reconciliation
CREATE TABLE IF NOT EXISTS `ais_entries` (
  `id`                   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              int(10) UNSIGNED NOT NULL,
  `fy_year`              smallint(4)      NOT NULL,
  `category`             enum('salary','interest','dividend','mutual_fund_redemption','property_sale','rental_income','other_income','tds_deducted','advance_tax','self_assessment_tax','refund','other') NOT NULL DEFAULT 'other',
  `description`          varchar(255)     DEFAULT NULL,
  `reported_amount`      decimal(16,2)    NOT NULL DEFAULT 0.00,
  `user_declared_amount` decimal(16,2)    DEFAULT NULL,
  `deductor_name`        varchar(150)     DEFAULT NULL,
  `tds_deducted`         decimal(12,2)    NOT NULL DEFAULT 0.00,
  `transaction_date`     date             DEFAULT NULL,
  `feedback_status`      enum('accepted','incorrect','not_mine','duplicate','other') NOT NULL DEFAULT 'accepted',
  `notes`                varchar(255)     DEFAULT NULL,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`           datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ais_user_fy` (`user_id`, `fy_year`),
  KEY `idx_ais_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't136 AIS Reconciliation migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t138_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t138: Indexation Benefit Calculator — LTCG Property
CREATE TABLE IF NOT EXISTS `property_assets` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          int(10) UNSIGNED NOT NULL,
  `property_name`    varchar(200)     NOT NULL,
  `property_type`    enum('residential','commercial','land','other') NOT NULL DEFAULT 'residential',
  `address`          varchar(500)     DEFAULT NULL,
  `purchase_date`    date             NOT NULL,
  `purchase_price`   decimal(16,2)    NOT NULL,
  `improvement_cost` decimal(14,2)    NOT NULL DEFAULT 0.00,
  `improvement_fy`   varchar(9)       DEFAULT NULL,
  `sale_date`        date             DEFAULT NULL,
  `sale_price`       decimal(16,2)    NOT NULL DEFAULT 0.00,
  `fmv_2001`         decimal(16,2)    NOT NULL DEFAULT 0.00 COMMENT 'FMV as of 2001-02 if purchased before 2001',
  `is_active`        tinyint(1)       NOT NULL DEFAULT 1,
  `notes`            varchar(255)     DEFAULT NULL,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pa_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't138 Indexation Calculator migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t198_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t198: NPS Screener Enhanced
-- nps_screener.php is full overwrite of existing — no new tables needed.
-- Add missing columns to nps_schemes if any.
ALTER TABLE `nps_schemes`
  ADD COLUMN IF NOT EXISTS `return_1y`   decimal(6,2) DEFAULT NULL AFTER `asset_class`,
  ADD COLUMN IF NOT EXISTS `return_3y`   decimal(6,2) DEFAULT NULL AFTER `return_1y`,
  ADD COLUMN IF NOT EXISTS `return_5y`   decimal(6,2) DEFAULT NULL AFTER `return_3y`,
  ADD COLUMN IF NOT EXISTS `return_since`decimal(6,2) DEFAULT NULL AFTER `return_5y`,
  ADD COLUMN IF NOT EXISTS `aum_cr`      decimal(14,2) DEFAULT NULL AFTER `return_since`,
  ADD COLUMN IF NOT EXISTS `latest_nav`  decimal(12,4) DEFAULT NULL AFTER `aum_cr`,
  ADD COLUMN IF NOT EXISTS `latest_nav_date` date DEFAULT NULL AFTER `latest_nav`,
  ADD COLUMN IF NOT EXISTS `nav_returns_updated_at` datetime DEFAULT NULL AFTER `latest_nav_date`,
  ADD COLUMN IF NOT EXISTS `is_active`   tinyint(1) NOT NULL DEFAULT 1 AFTER `nav_returns_updated_at`;
CREATE INDEX IF NOT EXISTS `idx_nps_asset_class` ON `nps_schemes` (`asset_class`);
CREATE INDEX IF NOT EXISTS `idx_nps_return_1y`   ON `nps_schemes` (`return_1y`);
SELECT 't198 NPS Screener migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t314_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t314: Monthly P&L Statement
-- Uses existing tables. Optional: mf_dividends if not exists.
CREATE TABLE IF NOT EXISTS `mf_dividends` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) UNSIGNED NOT NULL,
  `portfolio_id` int(10) UNSIGNED NOT NULL,
  `fund_id`      int(10) UNSIGNED DEFAULT NULL,
  `dividend_date` date NOT NULL,
  `amount`       decimal(14,2) NOT NULL,
  `dividend_type` enum('idcw','growth','reinvest') NOT NULL DEFAULT 'idcw',
  `notes`        varchar(255) DEFAULT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_div_user_date` (`user_id`,`dividend_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't314 Monthly P&L migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t340_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t340: EPFO UAN Integration
-- Uses epf_monthly_log (t467). Adds last_updated to epf_accounts.
ALTER TABLE `epf_accounts`
  ADD COLUMN IF NOT EXISTS `last_updated` date DEFAULT NULL AFTER `is_active`;
CREATE TABLE IF NOT EXISTS `epf_monthly_log` (
  `id`                    int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `epf_account_id`        int(10) UNSIGNED NOT NULL,
  `log_month`             date NOT NULL COMMENT 'YYYY-MM-01',
  `basic_salary`          decimal(14,2) NOT NULL DEFAULT 0.00,
  `employee_contribution` decimal(10,2) NOT NULL DEFAULT 0.00,
  `employer_contribution` decimal(10,2) NOT NULL DEFAULT 0.00,
  `eps_contribution`      decimal(10,2) NOT NULL DEFAULT 0.00,
  `vpf_contribution`      decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_credit`          decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_after`         decimal(16,2) DEFAULT NULL,
  `interest_credited`     decimal(12,2) NOT NULL DEFAULT 0.00,
  `source`                enum('manual','epfo_sync','import') NOT NULL DEFAULT 'manual',
  `notes`                 varchar(255) DEFAULT NULL,
  `created_at`            datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`            datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_epf_log_month` (`epf_account_id`,`log_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't340 EPFO UAN Integration migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t422_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t422: Auto Sweep FD Tracker
CREATE TABLE IF NOT EXISTS `sweep_fds` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`        int(10) UNSIGNED NOT NULL,
  `bank_name`           varchar(100)     NOT NULL,
  `savings_account_id`  int(10) UNSIGNED DEFAULT NULL,
  `principal`           decimal(14,2)    NOT NULL,
  `interest_rate`       decimal(5,2)     NOT NULL,
  `sweep_date`          date             NOT NULL,
  `maturity_date`       date             NOT NULL,
  `sweep_threshold`     decimal(14,2)    DEFAULT NULL COMMENT 'Savings balance at which sweep triggered',
  `auto_reverse`        tinyint(1)       NOT NULL DEFAULT 1 COMMENT '1=auto-broken when balance low',
  `status`              enum('active','matured','broken','renewed') NOT NULL DEFAULT 'active',
  `current_value`       decimal(14,2)    DEFAULT NULL,
  `amount_received`     decimal(14,2)    DEFAULT NULL,
  `broken_date`         date             DEFAULT NULL,
  `notes`               varchar(255)     DEFAULT NULL,
  `created_at`          datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`          datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_swp_portfolio` (`portfolio_id`),
  KEY `idx_swp_status`    (`status`),
  KEY `idx_swp_maturity`  (`maturity_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't422 Auto Sweep FD migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t45_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t45: Recurring Deposit (RD) Tracker
CREATE TABLE IF NOT EXISTS `rd_accounts` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`        int(10) UNSIGNED NOT NULL,
  `bank_name`           varchar(100)     NOT NULL,
  `account_number`      varchar(30)      DEFAULT NULL,
  `monthly_installment` decimal(12,2)    NOT NULL,
  `interest_rate`       decimal(5,2)     NOT NULL,
  `tenure_months`       smallint         NOT NULL,
  `start_date`          date             NOT NULL,
  `maturity_date`       date             NOT NULL,
  `maturity_amount`     decimal(16,2)    DEFAULT NULL,
  `status`              enum('active','matured','closed') NOT NULL DEFAULT 'active',
  `nominee`             varchar(100)     DEFAULT NULL,
  `notes`               varchar(255)     DEFAULT NULL,
  `created_at`          datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`          datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rd_portfolio` (`portfolio_id`),
  KEY `idx_rd_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rd_installments` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rd_account_id`  int(10) UNSIGNED NOT NULL,
  `due_date`       date             NOT NULL,
  `paid_date`      date             DEFAULT NULL,
  `amount`         decimal(12,2)    NOT NULL,
  `status`         enum('paid','pending','missed') NOT NULL DEFAULT 'pending',
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rd_due` (`rd_account_id`,`due_date`),
  KEY `idx_rdi_account` (`rd_account_id`),
  CONSTRAINT `fk_rdi_account` FOREIGN KEY (`rd_account_id`) REFERENCES `rd_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't45 RD Tracker migration complete ✅' AS status;


-- ──────────────────────────────────────────────────────
-- t49_migration.sql
-- ──────────────────────────────────────────────────────

-- WealthDash t49: Leave Encashment + LTA Tracker
CREATE TABLE IF NOT EXISTS `lta_entries` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         int(10) UNSIGNED NOT NULL,
  `travel_date`     date             NOT NULL,
  `destination`     varchar(150)     NOT NULL,
  `mode_of_travel`  enum('air','train','bus','other') NOT NULL DEFAULT 'train',
  `actual_fare`     decimal(12,2)    NOT NULL,
  `is_claimed`      tinyint(1)       NOT NULL DEFAULT 0,
  `claim_amount`    decimal(12,2)    NOT NULL DEFAULT 0.00,
  `exempt_amount`   decimal(12,2)    NOT NULL DEFAULT 0.00,
  `taxable_amount`  decimal(12,2)    NOT NULL DEFAULT 0.00,
  `notes`           varchar(255)     DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lta_user_date` (`user_id`,`travel_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leave_encashment` (
  `id`                   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`              int(10) UNSIGNED NOT NULL,
  `encashment_date`      date             NOT NULL,
  `employer_type`        enum('govt','private') NOT NULL DEFAULT 'private',
  `amount_received`      decimal(14,2)    NOT NULL,
  `avg_10month_salary`   decimal(14,2)    NOT NULL DEFAULT 0.00,
  `leave_balance_days`   int              NOT NULL DEFAULT 0,
  `exempt_amount`        decimal(14,2)    NOT NULL DEFAULT 0.00,
  `taxable_amount`       decimal(14,2)    NOT NULL DEFAULT 0.00,
  `notes`                varchar(255)     DEFAULT NULL,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_le_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SELECT 't49 Leave + LTA migration complete ✅' AS status;
