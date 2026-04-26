-- t341: Gratuity Tracker
-- Stores gratuity-eligible employment records per user/portfolio

CREATE TABLE IF NOT EXISTS `gratuity_accounts` (
  `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `portfolio_id`       int(10) UNSIGNED NOT NULL,
  `employer_name`      varchar(100)     NOT NULL,
  `designation`        varchar(100)     DEFAULT NULL,
  `joining_date`       date             NOT NULL,
  `last_drawn_salary`  decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Monthly Basic+DA at separation',
  `separation_date`    date             DEFAULT NULL COMMENT 'NULL = still employed',
  `separation_type`    enum('employed','resigned','retired','terminated','death','disability') NOT NULL DEFAULT 'employed',
  `actual_gratuity`    decimal(14,2)    DEFAULT NULL COMMENT 'Actual amount received (if separated)',
  `is_govt_employee`   tinyint(1)       NOT NULL DEFAULT 0 COMMENT 'Affects tax exemption limit',
  `is_covered_by_act`  tinyint(1)       NOT NULL DEFAULT 1 COMMENT 'Payment of Gratuity Act 1972',
  `notes`              text             DEFAULT NULL,
  `is_active`          tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`         datetime         NOT NULL DEFAULT current_timestamp(),
  `updated_at`         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_gratuity_portfolio` (`portfolio_id`),
  CONSTRAINT `fk_gratuity_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
