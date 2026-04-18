-- ============================================================
-- WealthDash — Migration 011: Price Alerts (DB-persistent)
-- Task: t77/t30 — NAV price alerts localStorage → DB upgrade
-- ============================================================
-- NOTE: t30 mein localStorage-based alerts already hain.
--       Ye migration server-side persistent alerts ke liye hai.
-- ============================================================

CREATE TABLE IF NOT EXISTS `price_alerts` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `fund_id`      INT UNSIGNED  NOT NULL,
  `type`         ENUM('above','below') NOT NULL COMMENT 'NAV rises above OR falls below target',
  `target_nav`   DECIMAL(12,4) NOT NULL,
  `note`         VARCHAR(300)  DEFAULT NULL,
  `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
  `triggered_at` DATETIME      DEFAULT NULL COMMENT 'NULL = not yet triggered',
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_user`      (`user_id`, `is_active`),
  KEY `idx_pa_fund`      (`fund_id`),
  CONSTRAINT `fk_pa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_pa_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Price Alerts — target NAV pe notify karo';

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT COUNT(*) AS total_alerts FROM price_alerts;
SELECT 'price_alerts table created ✅' AS status;
