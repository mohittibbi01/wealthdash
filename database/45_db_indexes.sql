-- ============================================================
-- WealthDash — Migration 45: Missing DB Indexes
-- Task: tp003 | p0 | Slow query fix — missing indexes on hot paths
-- Run: once | Safe: IF NOT EXISTS guards everywhere
-- ============================================================

-- ============================================================
-- mf_holdings — portfolio+status combo (dashboard/holdings page)
-- ============================================================
ALTER TABLE `mf_holdings`
  ADD INDEX IF NOT EXISTS `idx_mfh_portfolio_status` (`portfolio_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_mfh_portfolio_fund`   (`portfolio_id`, `fund_id`);

-- ============================================================
-- mf_transactions — FY filter (tax/gains reports)
-- ============================================================
ALTER TABLE `mf_transactions`
  ADD INDEX IF NOT EXISTS `idx_mft_portfolio_date`   (`portfolio_id`, `txn_date`),
  ADD INDEX IF NOT EXISTS `idx_mft_portfolio_type`   (`portfolio_id`, `txn_type`),
  ADD INDEX IF NOT EXISTS `idx_mft_fy`               (`investment_fy`);

-- ============================================================
-- nav_history — date range lookups for charting (fund_id + date range)
-- The UNIQUE idx already covers (fund_id, nav_date) but an explicit
-- composite covering nav is added for covering-index scans
-- ============================================================
ALTER TABLE `nav_history`
  ADD INDEX IF NOT EXISTS `idx_navh_fund_date_nav`   (`fund_id`, `nav_date`, `nav`);

-- ============================================================
-- sip_schedules — active SIPs per portfolio (SIP tracker page)
-- ============================================================
ALTER TABLE `sip_schedules`
  ADD INDEX IF NOT EXISTS `idx_sip_portfolio_active` (`portfolio_id`, `is_active`),
  ADD INDEX IF NOT EXISTS `idx_sip_active_day`       (`is_active`, `sip_day`);

-- ============================================================
-- nps_holdings — portfolio lookup with scheme join
-- ============================================================
ALTER TABLE `nps_holdings`
  ADD INDEX IF NOT EXISTS `idx_npsh_portfolio_scheme` (`portfolio_id`, `scheme_id`);

-- ============================================================
-- nps_nav_history — date range for NPS charts
-- ============================================================
ALTER TABLE `nps_nav_history`
  ADD INDEX IF NOT EXISTS `idx_nps_nav_scheme_date`  (`scheme_id`, `nav_date`);

-- ============================================================
-- fd_accounts — status filter (active FDs, maturity calendar)
-- ============================================================
ALTER TABLE `fd_accounts`
  ADD INDEX IF NOT EXISTS `idx_fd_portfolio_status`  (`portfolio_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_fd_status_maturity`   (`status`, `maturity_date`);

-- ============================================================
-- po_schemes — portfolio + type + status combo (PO page)
-- ============================================================
ALTER TABLE `po_schemes`
  ADD INDEX IF NOT EXISTS `idx_po_portfolio_type`    (`portfolio_id`, `scheme_type`),
  ADD INDEX IF NOT EXISTS `idx_po_portfolio_status`  (`portfolio_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_po_maturity`          (`maturity_date`);

-- ============================================================
-- loan_accounts — portfolio + status (active loans)
-- ============================================================
ALTER TABLE `loan_accounts`
  ADD INDEX IF NOT EXISTS `idx_loan_portfolio_status` (`portfolio_id`, `status`);

-- ============================================================
-- investment_goals — portfolio + achieved (goal progress page)
-- ============================================================
ALTER TABLE `investment_goals`
  ADD INDEX IF NOT EXISTS `idx_ig_portfolio_achieved` (`portfolio_id`, `is_achieved`),
  ADD INDEX IF NOT EXISTS `idx_ig_target_date`        (`target_date`);

-- ============================================================
-- insurance_policies — portfolio + status + type
-- ============================================================
ALTER TABLE `insurance_policies`
  ADD INDEX IF NOT EXISTS `idx_ins_portfolio_status`  (`portfolio_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_ins_premium_due`       (`premium_due_date`);

-- ============================================================
-- stock_holdings — portfolio + active status
-- ============================================================
ALTER TABLE `stock_holdings`
  ADD INDEX IF NOT EXISTS `idx_sh_portfolio_status`   (`portfolio_id`, `status`);

-- ============================================================
-- stock_transactions — portfolio + date range (LTCG/STCG reports)
-- ============================================================
ALTER TABLE `stock_transactions`
  ADD INDEX IF NOT EXISTS `idx_st_portfolio_date`     (`portfolio_id`, `txn_date`),
  ADD INDEX IF NOT EXISTS `idx_st_portfolio_type`     (`portfolio_id`, `txn_type`);

-- ============================================================
-- funds — screener filters (category + sub-category + status)
-- ============================================================
ALTER TABLE `funds`
  ADD INDEX IF NOT EXISTS `idx_fund_cat_subcat`       (`scheme_category`, `scheme_sub_category`),
  ADD INDEX IF NOT EXISTS `idx_fund_active`           (`is_active`),
  ADD INDEX IF NOT EXISTS `idx_fund_fund_type`        (`fund_type`);

-- ============================================================
-- notifications — user unread count (bell icon — called on every page load)
-- Already has idx_notif_user_unread but add covering index with triggered_at desc
-- ============================================================
ALTER TABLE `notifications`
  ADD INDEX IF NOT EXISTS `idx_notif_user_read_ts`   (`user_id`, `is_read`, `triggered_at`);

-- ============================================================
-- audit_log — user timeline (admin panel)
-- ============================================================
ALTER TABLE `audit_log`
  ADD INDEX IF NOT EXISTS `idx_audit_user_created`   (`user_id`, `created_at`);

-- ============================================================
-- sessions — expired session cleanup (GC query)
-- ============================================================
ALTER TABLE `sessions`
  ADD INDEX IF NOT EXISTS `idx_sess_last_activity`    (`last_activity`);

-- ============================================================
-- savings_accounts — portfolio + status
-- ============================================================
ALTER TABLE `savings_accounts`
  ADD INDEX IF NOT EXISTS `idx_sa_portfolio_status`  (`portfolio_id`, `status`);

-- ============================================================
-- crypto_holdings — portfolio composite
-- ============================================================
ALTER TABLE `crypto_holdings`
  ADD INDEX IF NOT EXISTS `idx_crypto_portfolio_coin` (`portfolio_id`, `coin_id`);

-- ============================================================
-- fund_watchlist (09_watchlist.sql table)
-- ============================================================
ALTER TABLE `fund_watchlist`
  ADD INDEX IF NOT EXISTS `idx_wl_user_fund`         (`user_id`, `fund_id`);

-- ============================================================
-- price_alerts (11_price_alerts.sql table)
-- ============================================================
ALTER TABLE `price_alerts`
  ADD INDEX IF NOT EXISTS `idx_pa_user_active`       (`user_id`, `is_active`),
  ADD INDEX IF NOT EXISTS `idx_pa_fund_active`       (`fund_id`, `is_active`);

-- ============================================================
-- nav_download_progress — status filter (admin panel)
-- ============================================================
ALTER TABLE `nav_download_progress`
  ADD INDEX IF NOT EXISTS `idx_ndp_fund_status`      (`fund_id`, `status`);

-- ============================================================
-- import_logs — user + portfolio + created_at (import history)
-- ============================================================
ALTER TABLE `import_logs`
  ADD INDEX IF NOT EXISTS `idx_il_portfolio_created` (`portfolio_id`, `created_at`);

-- ============================================================
-- epf_accounts — portfolio
-- ============================================================
ALTER TABLE `epf_accounts`
  ADD INDEX IF NOT EXISTS `idx_epf_portfolio_status` (`portfolio_id`, `status`);
