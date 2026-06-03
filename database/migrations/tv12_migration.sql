-- ═══════════════════════════════════════════════════════════════
-- WealthDash — tv12: MF Compare Tool (Side-by-side 5 Funds)
-- Migration: tv12_migration.sql
-- No new tables required — uses existing `funds` + `nav_history`
-- Only adds a stored watchlist_compare field for saved comparisons
-- ═══════════════════════════════════════════════════════════════

-- Optional: persist user's last comparison session
CREATE TABLE IF NOT EXISTS mf_compare_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    fund_ids    VARCHAR(100) NOT NULL COMMENT 'comma-separated fund IDs, max 5',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- ROUTER NOTES (Master merge karega — touch mat karo router.php)
-- ═══════════════════════════════════════════════════════════════
-- READ-ONLY actions to add in $csrfExempt array:
--   'mf_compare_detail',       // tv12 - full compare data for up to 5 funds
--   'mf_compare_nav_chart',    // tv12 - NAV history chart data
--   'mf_compare_save',         // tv12 - save last comparison (needs CSRF)
--   'mf_compare_load',         // tv12 - load saved comparison
-- Route case block (in switch($action)):
/*
case 'mf_compare_detail':
case 'mf_compare_nav_chart':
case 'mf_compare_save':
case 'mf_compare_load':
    require APP_ROOT . '/api/mutual_funds/mf_compare.php';
    break;
*/
