-- ============================================================
-- WealthDash — Migration 008: NFO Tracker
-- Task: t64 — Open/Upcoming NFOs track karo
-- ============================================================
-- INSTALL:
--   phpMyAdmin → wealthdash DB → SQL tab → paste → Go
--   YA: mysql -u root -p wealthdash < 008_nfo_tracker.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS `nfo_tracker` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `fund_name`       VARCHAR(300)     NOT NULL,
  `amc`             VARCHAR(200)     NOT NULL,
  `scheme_code`     VARCHAR(50)      DEFAULT NULL COMMENT 'AMFI scheme code — links to funds table after allotment',
  `category`        VARCHAR(100)     DEFAULT NULL COMMENT 'Equity/Debt/Hybrid/Index etc.',
  `nfo_price`       DECIMAL(10,4)   DEFAULT 10.0000 COMMENT 'NFO face value (usually ₹10)',
  `min_investment`  DECIMAL(12,2)   DEFAULT 5000.00,
  `open_date`       DATE             NOT NULL,
  `close_date`      DATE             NOT NULL,
  `allotment_date`  DATE             DEFAULT NULL,
  `status`          ENUM('upcoming','open','closing_soon','closed','allotted') NOT NULL DEFAULT 'upcoming',
  `fund_type`       ENUM('open_ended','close_ended','interval') DEFAULT 'open_ended',
  `tax_saving`      TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 = ELSS / 80C eligible',
  `benchmark`       VARCHAR(200)     DEFAULT NULL,
  `fund_manager`    VARCHAR(200)     DEFAULT NULL,
  `amc_website`     VARCHAR(500)     DEFAULT NULL,
  `last_updated`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nfo_name_open` (`fund_name`(150), `open_date`),
  KEY `idx_nfo_status`     (`status`),
  KEY `idx_nfo_close_date` (`close_date`),
  KEY `idx_nfo_open_date`  (`open_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='NFO Tracker — AMFI se daily fetch, screener mein NFO tab';

-- ── Cron schedule (update_nav_daily.php ke baad add karo) ────────────────
-- 0 8 * * * php /var/www/html/wealthdash/cron/fetch_nfo_data.php >> /var/log/wd_nfo.log 2>&1

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'nfo_tracker table created ✅' AS status;
