-- ============================================================
-- WealthDash — tp003: Missing indexes (safe version)
-- Only indexes on columns confirmed used in router.php queries.
-- Run once; IF NOT EXISTS makes it safe to re-run.
-- ============================================================

-- ── mf_holdings ────────────────────────────────────────────
-- WHERE portfolio_id = ? AND is_active = 1  (router.php line 202,206)
ALTER TABLE mf_holdings
  ADD INDEX IF NOT EXISTS idx_mf_holdings_portfolio_active
    (portfolio_id, is_active);

-- ── stock_holdings ─────────────────────────────────────────
-- WHERE portfolio_id = ? AND is_active = 1  (router.php line 212,216)
ALTER TABLE stock_holdings
  ADD INDEX IF NOT EXISTS idx_stock_holdings_portfolio_active
    (portfolio_id, is_active);

-- ── nps_holdings ───────────────────────────────────────────
-- WHERE portfolio_id = ?  (router.php line 222,226)
ALTER TABLE nps_holdings
  ADD INDEX IF NOT EXISTS idx_nps_holdings_portfolio
    (portfolio_id);

-- ── fd_accounts ────────────────────────────────────────────
-- WHERE portfolio_id = ? AND status = 'active'  (router.php line 235,239)
ALTER TABLE fd_accounts
  ADD INDEX IF NOT EXISTS idx_fd_accounts_portfolio_status
    (portfolio_id, status);

-- ── savings_accounts ───────────────────────────────────────
-- WHERE portfolio_id = ? AND is_active = 1  (router.php line 245)
ALTER TABLE savings_accounts
  ADD INDEX IF NOT EXISTS idx_savings_accounts_portfolio_active
    (portfolio_id, is_active);

-- ── users ──────────────────────────────────────────────────
-- Login lookup (email-based auth)
ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_users_email
    (email);

-- ── End of tp003 migration ─────────────────────────────────
--
-- OPTIONAL indexes (run manually after verifying column names):
--
-- ALTER TABLE mf_holdings ADD INDEX IF NOT EXISTS idx_mf_holdings_portfolio_fund (portfolio_id, fund_id);
-- ALTER TABLE fd_accounts ADD INDEX IF NOT EXISTS idx_fd_accounts_maturity_date (maturity_date);
-- ALTER TABLE portfolios ADD INDEX IF NOT EXISTS idx_portfolios_user_id (user_id);
-- ALTER TABLE mf_transactions ADD INDEX IF NOT EXISTS idx_mf_txn_portfolio_date (portfolio_id, transaction_date);
-- ALTER TABLE stock_transactions ADD INDEX IF NOT EXISTS idx_stock_txn_portfolio_date (portfolio_id, transaction_date);
-- ALTER TABLE mutual_funds ADD INDEX IF NOT EXISTS idx_mutual_funds_amfi_code (amfi_code);
-- ALTER TABLE mutual_fund_navs ADD INDEX IF NOT EXISTS idx_mf_navs_fund_date (fund_id, nav_date);
