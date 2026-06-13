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
