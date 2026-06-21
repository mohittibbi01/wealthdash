-- ============================================================
-- WealthDash — t344/t345: NSE/BSE Stock Price Alerts
-- stock_price_alerts table (separate from MF price_alerts)
-- ============================================================

CREATE TABLE IF NOT EXISTS `stock_price_alerts` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED  NOT NULL,
  `stock_id`      INT UNSIGNED  NOT NULL,
  `symbol`        VARCHAR(30)   NOT NULL,
  `company_name`  VARCHAR(200)  DEFAULT NULL,
  `alert_type`    ENUM('above','below','pct_up','pct_down') NOT NULL,
  `target_price`  DECIMAL(12,2) NOT NULL,
  `note`          VARCHAR(300)  DEFAULT NULL,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `triggered_at`  DATETIME      DEFAULT NULL,
  `triggered_price` DECIMAL(12,2) DEFAULT NULL,
  `notify_browser` TINYINT(1)   NOT NULL DEFAULT 1,
  `notify_email`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_spa_user`   (`user_id`, `is_active`),
  KEY `idx_spa_stock`  (`stock_id`),
  KEY `idx_spa_symbol` (`symbol`),
  CONSTRAINT `fk_spa_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_spa_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stock price alerts — NSE/BSE target price notifications';

SELECT 'stock_price_alerts table created ✅' AS status;
