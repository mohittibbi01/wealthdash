-- ============================================================
-- WealthDash — Migration t48: PPF + NPS + EPF 80C Tracker
-- Task: Unified 80C deduction tracker
-- Run ONCE — idempotent
-- ============================================================

-- 1. Manual 80C entries (LIC, ELSS, home loan principal, tuition fee, etc.)
CREATE TABLE IF NOT EXISTS `tax_80c_manual_entries` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) UNSIGNED NOT NULL,
  `fy_year`     smallint(4)      NOT NULL COMMENT 'FY start year e.g. 2024 for FY2024-25',
  `category`    enum('lic_premium','elss','home_loan_principal','tuition_fee',
                     'nsc_purchase','sukanya_samridhi','stamp_duty',
                     'unit_linked_insurance','other') NOT NULL DEFAULT 'other',
  `section`     enum('80C','80CCD(1B)','80D','80E') NOT NULL DEFAULT '80C',
  `amount`      decimal(12,2)    NOT NULL,
  `description` varchar(255)     DEFAULT NULL,
  `is_active`   tinyint(1)       NOT NULL DEFAULT 1,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_80c_user_fy` (`user_id`, `fy_year`),
  KEY `idx_80c_section` (`section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure nsc_80c_log exists (created by t205 — safe duplicate CREATE IF NOT EXISTS)
CREATE TABLE IF NOT EXISTS `nsc_80c_log` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nsc_scheme_id` int(10) UNSIGNED NOT NULL,
  `fy`            varchar(9)       NOT NULL,
  `amount`        decimal(12,2)    NOT NULL DEFAULT 0.00,
  `declared_80c`  tinyint(1)       NOT NULL DEFAULT 0,
  `notes`         varchar(255)     DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nsc_log_scheme_fy` (`nsc_scheme_id`, `fy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ensure ppf_fy_deposits exists (created by t203 — safe duplicate)
CREATE TABLE IF NOT EXISTS `ppf_fy_deposits` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ppf_scheme_id`   int(10) UNSIGNED NOT NULL,
  `fy_year`         smallint(4)      NOT NULL,
  `total_deposited` decimal(12,2)    NOT NULL DEFAULT 0.00,
  `entries`         text             DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ppf_fy` (`ppf_scheme_id`, `fy_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't48 PPF+NPS+EPF 80C Tracker migration complete ✅' AS status;
