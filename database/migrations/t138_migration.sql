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
