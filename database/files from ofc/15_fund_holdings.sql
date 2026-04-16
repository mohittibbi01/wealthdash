-- ============================================================
-- WealthDash — Migration 028: Fund Holdings & Data Enrichment
-- Tasks: t176 (Overlap Matrix), t177 (Sector Alloc v2),
--        t178 (Top Holdings), t179 (Style Box)
-- ============================================================

-- ── t176/t177/t178: Fund Stock Holdings (AMFI monthly disclosure) ─────────
CREATE TABLE IF NOT EXISTS `fund_stock_holdings` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `fund_id`      INT UNSIGNED  NOT NULL,
  `stock_name`   VARCHAR(300)  NOT NULL,
  `isin`         VARCHAR(15)   DEFAULT NULL,
  `sector`       VARCHAR(100)  DEFAULT NULL  COMMENT 'AMFI disclosure sector name',
  `weight_pct`   DECIMAL(6,3)  NOT NULL      COMMENT 'Percentage of fund portfolio (0-100)',
  `market_cap`   ENUM('large','mid','small','other') DEFAULT NULL,
  `month_year`   DATE          NOT NULL      COMMENT 'First day of disclosure month (e.g. 2026-03-01)',
  `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_stock_month` (`fund_id`, `isin`(15), `month_year`),
  KEY `idx_fsh_fund`      (`fund_id`),
  KEY `idx_fsh_isin`      (`isin`),
  KEY `idx_fsh_sector`    (`sector`(50)),
  KEY `idx_fsh_month`     (`month_year`),
  CONSTRAINT `fk_fsh_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AMFI monthly portfolio disclosure — stock-level holdings per fund';

-- ── t179: Style Box column on funds ──────────────────────────────────────
-- [FIXED] MySQL 8.0 compatible version of ALTER TABLE `funds`
SET @chk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='funds' AND COLUMN_NAME='style_box');
SET @sql = IF(@chk=0, "ALTER TABLE `funds` ADD COLUMN `style_box` ENUM( 'large_value','large_blend','large_growth', 'mid_value', 'mid_blend', 'mid_growth', 'small_value','small_blend','small_growth', 'other' ) DEFAULT NULL COMMENT 'Morningstar-style 3x3 style box (calc from fund_stock_holdings)'", "SELECT 'style_box already exists' AS info");
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;


-- ── Precomputed pairwise overlap table (performance optimization) ─────────
CREATE TABLE IF NOT EXISTS `fund_overlap_cache` (
  `fund_id_a`    INT UNSIGNED NOT NULL,
  `fund_id_b`    INT UNSIGNED NOT NULL,
  `overlap_pct`  DECIMAL(5,2) NOT NULL  COMMENT 'Percentage of portfolio that overlaps (by weight)',
  `common_stocks` SMALLINT    NOT NULL DEFAULT 0,
  `month_year`   DATE         NOT NULL,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`fund_id_a`, `fund_id_b`, `month_year`),
  KEY `idx_oc_a` (`fund_id_a`),
  KEY `idx_oc_b` (`fund_id_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Precomputed pairwise fund overlap — recalculate monthly';

-- ── Cron to add (monthly, after AMFI portfolio disclosure ~10th of each month) ─
-- 0 6 10 * * php /var/www/html/wealthdash/cron/fetch_fund_holdings.php >> /var/log/wd_holdings.log 2>&1

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'fund_stock_holdings table created ✅'  AS h_status;
SELECT 'fund_overlap_cache table created ✅'    AS o_status;
DESCRIBE funds;
