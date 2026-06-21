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
