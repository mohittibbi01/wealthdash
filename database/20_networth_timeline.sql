-- ============================================================
-- t207: Net Worth Timeline — Monthly snapshot store
-- ============================================================

CREATE TABLE IF NOT EXISTS `net_worth_snapshots` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       int(10) UNSIGNED NOT NULL,
  `snapshot_date` date NOT NULL COMMENT '1st of each month',
  `total_value`   decimal(20,2) NOT NULL DEFAULT 0.00,
  `mf_value`      decimal(20,2) NOT NULL DEFAULT 0.00,
  `stock_value`   decimal(20,2) NOT NULL DEFAULT 0.00,
  `fd_value`      decimal(20,2) NOT NULL DEFAULT 0.00,
  `savings_value` decimal(20,2) NOT NULL DEFAULT 0.00,
  `nps_value`     decimal(20,2) NOT NULL DEFAULT 0.00,
  `po_value`      decimal(20,2) NOT NULL DEFAULT 0.00,
  `gold_value`    decimal(20,2) NOT NULL DEFAULT 0.00,
  `other_value`   decimal(20,2) NOT NULL DEFAULT 0.00,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nws` (`user_id`, `snapshot_date`),
  KEY `idx_nws_user` (`user_id`),
  CONSTRAINT `fk_nws_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Monthly net worth snapshots for timeline chart (t207)';

SELECT 'net_worth_snapshots table created ✅' AS status;
