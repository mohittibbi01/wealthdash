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
