-- ============================================================
-- WealthDash — Migration t33: FD + Savings Pagination (FIXED)
-- Error fix: removed index on current_balance (column may not exist)
-- Run ONCE — idempotent
-- ============================================================

-- FD indexes for faster paginated queries
CREATE INDEX IF NOT EXISTS `idx_fd_status_maturity`
  ON `fd_accounts` (`status`, `maturity_date`);

CREATE INDEX IF NOT EXISTS `idx_fd_bank_status`
  ON `fd_accounts` (`bank_name`(50), `status`);

-- Savings index — only on is_active (safe, no unknown columns)
CREATE INDEX IF NOT EXISTS `idx_sa_active`
  ON `savings_accounts` (`is_active`);

SELECT 't33 FD + Savings Pagination migration complete ✅' AS status;
