-- ============================================================
-- WealthDash — Migration t470: EPF vs NPS vs PPF Comparison
-- Task: Side-by-side comparison (read-only API — no new tables)
-- Run ONCE — idempotent
-- ============================================================

-- No new tables required for t470.
-- epf_nps_ppf_compare.php uses existing tables:
--   epf_accounts, epf_monthly_log (t467)
--   nps_holdings, nps_transactions
--   po_schemes (scheme_type='ppf'), ppf_fy_deposits (t203)

-- Optional: cache comparison snapshots for performance
CREATE TABLE IF NOT EXISTS `comparison_snapshots` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      int(10) UNSIGNED NOT NULL,
  `snapshot_key` varchar(40)      NOT NULL COMMENT 'e.g. epf_nps_ppf_2024-25',
  `payload`      mediumtext       NOT NULL COMMENT 'JSON snapshot of comparison',
  `expires_at`   datetime         NOT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_snap_user_key` (`user_id`, `snapshot_key`),
  KEY `idx_snap_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 't470 EPF vs NPS vs PPF Comparison migration complete ✅' AS status;
