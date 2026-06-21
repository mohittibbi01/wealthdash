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
