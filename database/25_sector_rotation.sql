-- ============================================================
-- WealthDash — Migration 025: Sector Rotation Cache
-- Task: t502 — Sector Rotation Tracker
-- ============================================================
-- Caches sector-level performance data computed from fund categories
-- or actual AMFI fund_stock_holdings data.
-- Refreshed by: cron/update_nav_daily.php (daily)
-- ============================================================

-- ── sector_rotation_cache — precomputed sector × period returns ──────────
CREATE TABLE IF NOT EXISTS `sector_rotation_cache` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sector_name`  VARCHAR(100) NOT NULL          COMMENT 'e.g. Banking, IT, Pharma, FMCG',
    `sector_slug`  VARCHAR(60)  NOT NULL          COMMENT 'URL-safe slug e.g. banking, it',
    `ret_1m`       DECIMAL(8,4) DEFAULT NULL      COMMENT '1-month avg return %',
    `ret_3m`       DECIMAL(8,4) DEFAULT NULL      COMMENT '3-month avg return %',
    `ret_6m`       DECIMAL(8,4) DEFAULT NULL      COMMENT '6-month avg return %',
    `ret_1y`       DECIMAL(8,4) DEFAULT NULL      COMMENT '1-year avg return %',
    `fund_count`   SMALLINT UNSIGNED DEFAULT 0    COMMENT 'Number of funds in this sector',
    `top_fund_id`  INT UNSIGNED DEFAULT NULL      COMMENT 'Best performing fund in sector',
    `data_source`  ENUM('category_proxy','amfi_holdings') NOT NULL DEFAULT 'category_proxy',
    `computed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sr_sector` (`sector_slug`),
    KEY `idx_sr_ret1y` (`ret_1y`),
    CONSTRAINT `fk_sr_top_fund` FOREIGN KEY (`top_fund_id`) REFERENCES `funds`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sector rotation cache — daily computed sector performance';

-- ── sector_rotation_history — time-series for trend charts ──────────────
CREATE TABLE IF NOT EXISTS `sector_rotation_history` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sector_slug`  VARCHAR(60)  NOT NULL,
    `period_date`  DATE         NOT NULL          COMMENT 'Snapshot date',
    `ret_1m`       DECIMAL(8,4) DEFAULT NULL,
    `ret_3m`       DECIMAL(8,4) DEFAULT NULL,
    `ret_1y`       DECIMAL(8,4) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_srh_sector_date` (`sector_slug`, `period_date`),
    KEY `idx_srh_date` (`period_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sector rotation time-series — for trend line charts';

-- ── Verify ───────────────────────────────────────────────────────────────
SELECT 'sector_rotation_cache table created ✅' AS status;
SELECT 'sector_rotation_history table created ✅' AS status;
