-- ============================================================
-- WealthDash — Migration 010: Notifications Center
-- Task: t81 — Bell icon + all alerts ek jagah
-- ============================================================

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `type`         ENUM('nav_alert','fd_maturity','sip_reminder','drawdown','nfo_closing','system','goal','tax') NOT NULL,
  `title`        VARCHAR(300)  NOT NULL,
  `body`         TEXT          NOT NULL,
  `link_url`     VARCHAR(500)  DEFAULT NULL COMMENT 'Click pe kahan jaaye',
  `is_read`      TINYINT(1)    NOT NULL DEFAULT 0,
  `triggered_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at`      DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_unread` (`user_id`, `is_read`, `triggered_at`),
  KEY `idx_notif_type`        (`type`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Notifications Center — sab alerts ek jagah';

-- Per-user notification preferences
CREATE TABLE IF NOT EXISTS `notification_prefs` (
  `user_id`         INT UNSIGNED NOT NULL,
  `nav_alerts`      TINYINT(1)   NOT NULL DEFAULT 1,
  `fd_maturity`     TINYINT(1)   NOT NULL DEFAULT 1,
  `sip_reminder`    TINYINT(1)   NOT NULL DEFAULT 1,
  `drawdown_alerts` TINYINT(1)   NOT NULL DEFAULT 1,
  `nfo_alerts`      TINYINT(1)   NOT NULL DEFAULT 1,
  `tax_reminders`   TINYINT(1)   NOT NULL DEFAULT 1,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_np_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'notifications + notification_prefs tables created ✅' AS status;
