-- WealthDash — t379: Scheduled Reports — auto email
-- Migration: scheduled_reports table

CREATE TABLE IF NOT EXISTS `scheduled_reports` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT UNSIGNED NOT NULL,
    `report_type`   ENUM('net_worth','portfolio_summary','fy_gains','fd_summary','sip_summary','full_report') NOT NULL DEFAULT 'portfolio_summary',
    `frequency`     ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'monthly',
    `day_of_week`   TINYINT UNSIGNED DEFAULT NULL COMMENT '0=Sun,1=Mon,...,6=Sat — used when frequency=weekly',
    `day_of_month`  TINYINT UNSIGNED DEFAULT NULL COMMENT '1-28 — used when frequency=monthly',
    `send_hour`     TINYINT UNSIGNED NOT NULL DEFAULT 8 COMMENT 'Hour in IST (0-23) to send the report',
    `email`         VARCHAR(320) DEFAULT NULL COMMENT 'Override email; NULL = use account email',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `last_sent_at`  DATETIME DEFAULT NULL,
    `next_send_at`  DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_sr_user`      (`user_id`),
    KEY `idx_sr_next_send` (`next_send_at`, `is_active`),
    CONSTRAINT `fk_sr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
