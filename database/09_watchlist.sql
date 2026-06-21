-- ============================================================
-- WealthDash — Migration 009: Fund Watchlist (DB-persistent)
-- Task: t68 — Watchlist localStorage se DB pe upgrade karo
-- ============================================================
-- NOTE: mf_screener.php mein localStorage-based watchlist already
--       kaam kar rahi hai. Ye migration DB persistence add karta hai
--       taaki cross-device sync ho sake.
-- ============================================================

CREATE TABLE IF NOT EXISTS `fund_watchlist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `fund_id`    INT UNSIGNED NOT NULL,
  `notes`      TEXT         DEFAULT NULL  COMMENT 'User ka personal note is fund pe',
  `alert_nav`  DECIMAL(10,4) DEFAULT NULL COMMENT 'Price alert — NAV is level pe pahunche to notify',
  `alert_type` ENUM('above','below') DEFAULT NULL,
  `added_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_fund` (`user_id`, `fund_id`),
  KEY `idx_wl_user`   (`user_id`),
  KEY `idx_wl_fund`   (`fund_id`),
  CONSTRAINT `fk_wl_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_wl_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fund Watchlist — user ke favourite funds, DB-persistent';

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'fund_watchlist table created ✅' AS status;
