-- ============================================================
-- WealthDash — t412: Performance Tests
-- Migration: database/migrations/t412_migration.sql
-- Worker: ID-M
-- NOTE: Uses perf_request_log from t308 — no new tables needed
-- ============================================================

-- Store perf test baselines
CREATE TABLE IF NOT EXISTS `perf_baselines` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action`      VARCHAR(100) NOT NULL,
    `baseline_ms` FLOAT        NOT NULL,
    `threshold_ms`FLOAT        NOT NULL,
    `measured_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pb_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default baselines
INSERT INTO `perf_baselines` (`action`, `baseline_ms`, `threshold_ms`) VALUES
    ('fd_list',          80,  500),
    ('savings_list',     80,  500),
    ('bank_list',        80,  500),
    ('bank_summary',     100, 600),
    ('mf_list',          120, 700),
    ('sip_list',         100, 600),
    ('get_dashboard_data',200, 1000),
    ('health_ping',       30, 200),
    ('al_list',           80, 500),
    ('gs_list',           60, 400),
    ('dbm_tables',        80, 500),
    ('perf_live',         100, 600)
ON DUPLICATE KEY UPDATE baseline_ms = VALUES(baseline_ms), threshold_ms = VALUES(threshold_ms);
