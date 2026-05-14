-- ============================================================
-- WealthDash — Migration t205: NSC Interest Deemed Reinvestment Tracker
-- Task: 80C eligible interest tracking for NSC
-- Run ONCE — idempotent
-- ============================================================

-- 1. NSC 80C declaration log
--    Tracks year-by-year 80C declaration status per NSC holding
CREATE TABLE IF NOT EXISTS `nsc_80c_log` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nsc_scheme_id`  int(10) UNSIGNED NOT NULL COMMENT 'FK → po_schemes.id (scheme_type=NSC)',
  `fy`             varchar(9)       NOT NULL COMMENT 'e.g. 2024-2025',
  `amount`         decimal(12,2)    NOT NULL DEFAULT 0.00 COMMENT 'Interest eligible for 80C this FY',
  `declared_80c`   tinyint(1)       NOT NULL DEFAULT 0   COMMENT '1 = user has declared in ITR',
  `notes`          varchar(255)     DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nsc_log_scheme_fy` (`nsc_scheme_id`, `fy`),
  KEY `idx_nsc_log_scheme` (`nsc_scheme_id`),
  CONSTRAINT `fk_nsc_log_scheme`
    FOREIGN KEY (`nsc_scheme_id`) REFERENCES `po_schemes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure po_schemes has interest_rate column (in case older schema missing it)
ALTER TABLE `po_schemes`
  ADD COLUMN IF NOT EXISTS `interest_rate` decimal(5,2) NOT NULL DEFAULT 7.70
    COMMENT 'Annual interest rate %'
    AFTER `scheme_type`;

SELECT 't205 NSC Interest Tracker migration complete ✅' AS status;
