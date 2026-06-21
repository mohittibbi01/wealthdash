-- ============================================================
-- WealthDash — Migration 029: Fund Manager Track Record
-- Task: t180 — Manager wise historical performance
-- ============================================================

CREATE TABLE IF NOT EXISTS `fund_managers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_id`      INT UNSIGNED NOT NULL,
  `manager_name` VARCHAR(200) NOT NULL,
  `from_date`    DATE         NOT NULL,
  `to_date`      DATE         DEFAULT NULL COMMENT 'NULL = currently managing',
  `is_current`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fm_fund`    (`fund_id`),
  KEY `idx_fm_manager` (`manager_name`(100)),
  KEY `idx_fm_current` (`is_current`),
  CONSTRAINT `fk_fm_fund` FOREIGN KEY (`fund_id`) REFERENCES `funds`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fund manager history — track performance before/after manager change';

-- ── Quick columns on funds table for current manager (denormalized for speed) ─
ALTER TABLE `funds`
  ADD COLUMN IF NOT EXISTS `manager_since`  DATE         DEFAULT NULL COMMENT 'Current manager start date',
  ADD COLUMN IF NOT EXISTS `fund_manager`   VARCHAR(200) DEFAULT NULL COMMENT 'Current fund manager name (denorm for screener)';

-- Agar fund_manager column already hai to ye silently skip hoga (IF NOT EXISTS)

-- ── Verify ──────────────────────────────────────────────────────────────────
SELECT 'fund_managers table created ✅' AS status;
SELECT COUNT(*) AS manager_records FROM fund_managers;
