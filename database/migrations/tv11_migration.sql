-- ════════════════════════════════════════════════════════════════════════════
-- WealthDash — Migration: tv11 (Benchmark Comparison — Fund vs Index)
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. Add benchmark_index column to funds ───────────────────────────────────
ALTER TABLE `funds`
    ADD COLUMN IF NOT EXISTS `benchmark_index` VARCHAR(20) DEFAULT NULL
        COMMENT 'Index symbol: ^NSEI, ^BSESN, ^NSMIDCP, ^NSSC250, ^NSNXT50, ^CRISIL';

ALTER TABLE `funds`
    ADD INDEX IF NOT EXISTS `idx_funds_benchmark_index` (`benchmark_index`);

-- ── 2. Benchmark NAV cache table ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `benchmark_nav_cache` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `symbol`     VARCHAR(20)   NOT NULL COMMENT '^NSEI, ^BSESN, etc.',
    `nav_date`   DATE          NOT NULL,
    `close`      DECIMAL(14,4) NOT NULL,
    `fetched_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bm_nav`       (`symbol`, `nav_date`),
    INDEX       `idx_bm_symbol`  (`symbol`),
    INDEX       `idx_bm_date`    (`nav_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Benchmark index NAV cache — Nifty 50, Sensex, Midcap etc. (tv11)';

-- ── 3. Bulk-assign default benchmarks by category ────────────────────────────
UPDATE `funds` SET `benchmark_index` = '^NSEI'
WHERE `benchmark_index` IS NULL AND `is_active` = 1
  AND (`category` LIKE '%large cap%' OR `category` LIKE '%elss%'
    OR `category` LIKE '%index%'     OR `category` LIKE '%focused%'
    OR `category` LIKE '%multi cap%' OR `category` LIKE '%flexi cap%'
    OR `category` LIKE '%hybrid%');

UPDATE `funds` SET `benchmark_index` = '^NSMIDCP'
WHERE `benchmark_index` IS NULL AND `is_active` = 1
  AND (`category` LIKE '%mid cap%' OR `category` LIKE '%mid & small%');

UPDATE `funds` SET `benchmark_index` = '^NSSC250'
WHERE `benchmark_index` IS NULL AND `is_active` = 1
  AND `category` LIKE '%small cap%';

UPDATE `funds` SET `benchmark_index` = '^CRISIL'
WHERE `benchmark_index` IS NULL AND `is_active` = 1
  AND (`category` LIKE '%debt%'   OR `category` LIKE '%liquid%'
    OR `category` LIKE '%overnight%' OR `category` LIKE '%gilt%'
    OR `category` LIKE '%money market%');

UPDATE `funds` SET `benchmark_index` = '^NSEI'
WHERE `benchmark_index` IS NULL AND `is_active` = 1;

-- ── 4. Record migration ──────────────────────────────────────────────────────
INSERT IGNORE INTO `migration_log` (`filename`, `checksum`, `batch`, `notes`)
VALUES ('tv11_migration.sql', SHA2('tv11', 256), 1, 'Benchmark Comparison Fund vs Index — benchmark_index column + nav cache + bulk assign');
