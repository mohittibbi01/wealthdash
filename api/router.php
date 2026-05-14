<?php
/**
 * WealthDash — Central API Router
 * All AJAX/POST requests go through here
 * Handles: portfolio CRUD, theme update, portfolio switch
 */
ob_start(); // Buffer all output — prevents PHP warnings from corrupting JSON
define('WEALTHDASH', true);
// Suppress PHP warnings/notices so they don't corrupt JSON output
error_reporting(0);
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
// helpers.php already loaded by config.php

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Parse JSON body (API.post sends application/json, not form data)
$_rawBody = file_get_contents('php://input');
if (!empty($_rawBody)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $_jsonData = json_decode($_rawBody, true);
        if (is_array($_jsonData)) {
            foreach ($_jsonData as $k => $v) { $_POST[$k] = $v; }
        }
    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        // fallback: parse urlencoded body into $_POST
        parse_str($_rawBody, $_formData);
        foreach ($_formData as $k => $v) { $_POST[$k] = $v; }
    } else {
        // try JSON first, then urlencoded
        $_jsonData = json_decode($_rawBody, true);
        if (is_array($_jsonData)) {
            foreach ($_jsonData as $k => $v) { $_POST[$k] = $v; }
        } else {
            parse_str($_rawBody, $_formData);
            foreach ($_formData as $k => $v) { $_POST[$k] = $v; }
        }
    }
}

// Must be logged in for all API calls
if (!is_logged_in()) {
    json_response(false, 'Unauthorized. Please log in.', [], 401);
}

$action  = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId  = (int) $_SESSION['user_id'];
$isAdmin = is_admin();

// Read-only actions don't need CSRF (no state change)
$csrfExempt = [
    'admin_stats', 'admin_users',
    'admin_cron_status', 'admin_cron_history',
    'admin_settings_get', 'admin_audit_log', 'admin_db_list', 'admin_db_status',
    'admin_migrations_list',
    // t310: Rate Limiter admin (read-only)
    'admin_rl_stats',
    // tg001: Monte Carlo Simulator (read-only actions)
    'monte_carlo_presets', 'monte_carlo_history',
    // tg002: Bucket Strategy (read-only)
    'bucket_strategy_summary', 'bucket_strategy_goals', 'bucket_strategy_health', 'bucket_strategy_load',
    // t312: Rebalancing Report (read-only)
    'report_rebalancing', 'rebalancing_load_targets',
    'admin_fund_rules_search', 'admin_fund_rules_get', 'admin_fund_rules_categories',
    'admin_import_ter',
    'admin_import_exit_load',
    'get_portfolio_summary', 'get_dashboard_data', 'global_search', 'wealth_statement',
    'unified_summary', 'unified_activity', 'unified_alerts',  // t442
    'scheduled_reports_list',
    'fd_list', 'fd_add', 'fd_delete', 'fd_mature', 'fd_maturity', 'fd_ladder',
    'stocks_list', 'stocks_get',
    // t222 — NSE Free Data (read-only)
    'nse_quote', 'nse_quote_bulk', 'nse_ohlc', 'nse_indexes', 'nse_index_detail',
    // t281 — Stock Fundamentals History (read-only)
    'fundamentals_history',
    'nps_list', 'nps_nav_history',
    'savings_list',
    'po_list', 'po_meta',
    'goal_list', 'goal_projection', 'goal_asset_allocation', 'goal_link_asset', 'goal_unlink_asset',
    'sip_list', 'sip_analysis', 'sip_upcoming', 'sip_monthly_chart',
    'sip_xirr', 'sip_nav_status', 'sip_nav_token', 'sip_sync_txns',
    'indexes_fetch',
    'report_fy_gains',
    'annual_report_data',   // t376
    'nps_statement',
    'insurance_list', 'insurance_premium_calendar',  // t321/t322/t324
    'health_summary', 'health_members_list', 'health_claims_list',  // t460
    'loans_list',  // t123
    'hl_detail', 'hl_rate_history', 'hl_prepayments', 'hl_tax_claims', 'hl_emi_calendar',  // t464
    'admin_nps_nav_trigger',
    'fund_notes_get',
    'data_quality_report',   // tv13
    'nfo_list',              // tv08
    'wl_alerts_list',        // tv10
    'wl_alerts_check',       // tv10
    'wl_alerts_history',     // t405
    'wl_alerts_simulate',    // t405
    'fund_ratings_get',      // tv01
    'fund_ratings_list',     // tv01
    'fund_health_score',     // t362
    'fund_health_top',       // t362
    'style_box_map',         // tv04
    'style_box_fund',        // tv04
    'style_box_screener',    // tv04
    'style_box_portfolio',   // tv04
    'benchmark_compare',     // tv11
    'benchmark_alpha',       // tv11
    'benchmark_defaults',    // tv11
    'isin_validate',         // t483
    'isin_validate_code',    // t483
    'isin_invalid_list',     // t483
    'isin_cache_refresh',    // t483
    'csv_v3_formats',        // t490
    // t24 — Crypto
    'crypto_list', 'crypto_prices', 'crypto_summary', 'crypto_txns',
    'crypto_vda_tax',
    // t315 — Crypto Holdings (read-only actions)
    'crypto_portfolio_stats', 'crypto_staking_list',
    'crypto_wl_list', 'crypto_tax_year_summary',
    // t97/t177/t178 — Fund Sectors & Holdings
    'fund_sectors', 'portfolio_sectors', 'sector_allocation',
    'sector_filter_list', 'fetch_sectors_amfi',
    'fund_holdings_status', 'fund_holdings_data',
    'fund_holdings_overlap', 'fund_holdings_matrix', 'fund_holdings_sector',
    'fund_top_holdings',
    // Fund Screener
    'fund_screener', 'fund_detail', 'fund_compare', 'fund_top_performers',
    'recommend_funds', 'saved_screens_list', 'filter_meta',
    // CAS status (read)
    'cas_status',
    // t262 — Rolling Returns
    'rolling_returns',
    // Lumpsum SIP Optimizer (read)
    'lumpsum_sip_signal', 'lumpsum_sip_historical', 'lumpsum_sip_recommend', 'lumpsum_sip_all',
    // MF Metrics (read)
    'mf_volatility', 'mf_sortino', 'mf_calmar', 'mf_fund_age', 'mf_momentum', 'mf_metrics_all',
    // IDCW (read)
    'idcw_dividends', 'idcw_tax_impact', 'idcw_portfolio', 'idcw_comparison',
    // Tax Loss Harvest (read)
    'tlh_candidates', 'tlh_impact', 'tlh_dashboard',
    // SIP Pause (read)
    'sip_pause_impact', 'sip_pause_market', 'sip_pause_emergency',
    'sip_pause_resume', 'sip_pause_dashboard',
    // Watchlist (read)
    'wl_list',
    // Portfolio Optimizer (read)
    'po_overlap_matrix', 'po_consolidation', 'po_optimal_count', 'po_full',
    // Available units, dividend history, benchmark proxy (read)
    'mf_available_units', 'dividend_history', 'dividend_portfolio', 'benchmark_proxy',
    // Fund house rankings & import history (read)
    'fund_house_rankings', 'import_history',
    // t386: 2FA (read-only check)
    '2fa_status',
    // t387: Session Security (read-only list)
    'sessions_list',
    // t396: Net Worth Projection (read-only)
    'nw_projection',
    // t197: NPS Contribution SIP Tracker (read-only)
    'nps_sip_tracker',
    // t424: FD Interest Tracker (read-only)
    'fd_interest_tracker',
    // t466: Real Estate (read-only list)
    'realestate_list', 'realestate_summary',
    // t465: Physical Gold (read-only list)
    'gold_list', 'gold_summary',
    // t394: SGB — Sovereign Gold Bonds (read-only + live price)
    'sgb_list', 'sgb_summary', 'sgb_live_price', 'sgb_series_list', 'sgb_interest_list',
    // t320: PO Rate Change Alert (read-only)
    'po_rate_alert',
    // t46 + t339: EPF Tracker + Interest Calculator
    'epf_list',
    // t341: Gratuity Tracker
    'gratuity_list',
    // MF List (read-only GET actions)
    'mf_list', 'mf_summary', 'portfolio_xirr', 'portfolio_health',
    'asset_allocation', 'overlap_check', 'dividend_history',
    'portfolio_risk', 'smart_insights', 'transactions',
    // Notifications (read-only)
    'notif_unread_count', 'notif_list', 'notif_mark_read',
    // t211 — DB Backup & Restore
    'admin_db_backup_list', 'admin_db_backup_stats', 'admin_db_restore_log',
    // t479 — Duplicate Detector
    'dedup_stats', 'dedup_list', 'dedup_check_new',
    // t480 — Data Validation
    'validate_entry', 'validation_rules_list', 'validation_violations',
    // t504 — REST API Keys
    'api_keys_list', 'api_usage_stats',
    // t115 — REITs & InvITs
    'reit_list', 'reit_summary', 'reit_master_list',
    // t120 — Smallcase
    'smallcase_list', 'smallcase_summary', 'smallcase_holdings', 'smallcase_txns', 'smallcase_performance',
    // t144 — SIP Step-Up Nudge
    'stepup_dashboard', 'stepup_salary_list', 'stepup_nudges', 'stepup_projection_get',
    // t317 — Crypto Import
    'crypto_import_log',
    // tc005 — Exchange Sync
    'exchange_keys_list', 'exchange_sync_log',
    // t113 — SGB
    'sgb_list', 'sgb_summary', 'sgb_live_price', 'sgb_series_list', 'sgb_interest_list',
    // t117 — ESOP/RSU
    'esop_list', 'esop_summary', 'esop_vesting_list', 'esop_exercise_log',
    // t203 — PPF Tracker
    'ppf_deposit_get', 'ppf_deposits_list', 'ppf_deposit_history',
    // t151 — EPFO Passbook
    'epfo_accounts_list', 'epfo_passbook', 'epfo_summary', 'epfo_sync_log',
    // t139 — Goal Buckets
    'bucket_list', 'bucket_summary', 'bucket_progress',
    // t151 (W5) — EPFO Passbook
    'epfo_passbook_entries',
    // t474 — SIP vs EMI
    'sip_emi_summary', 'sip_emi_monthly_breakdown',
    // t498 — Investment Calendar
    'inv_calendar_events', 'inv_calendar_month',
    // t380 — AI Portfolio Review
    'ai_review_get', 'ai_review_history', 'ai_review_status', 'ai_review_prefs_get',
    // t381 — AI Chat
    'ai_chat_session_get', 'ai_chat_history', 'ai_chat_usage',
    // t205 — NSC Tracker
    'nsc_list', 'nsc_80c_schedule', 'nsc_fy_declaration', 'nsc_maturity_calc',
    // t206 — MIS/SCSS Payout
    'mis_payout_calendar', 'scss_payout_calendar', 'payout_income_fy',
    'payout_upcoming', 'mis_payout_summary', 'scss_payout_summary',
];
if (!in_array($action, $csrfExempt)) {
    csrf_verify();
}

try {
    switch ($action) {

        // ---- THEME: Update ----
        case 'update_theme':
            $theme = clean($_POST['theme'] ?? 'light');
            if (!in_array($theme, ['light', 'dark'])) $theme = 'light';
            DB::run('UPDATE users SET theme = ? WHERE id = ?', [$theme, $userId]);
            $_SESSION['user_theme'] = $theme;
            json_response(true, 'Theme updated.');

        // ---- DASHBOARD: Net worth summary ----
        case 'net_worth_summary':
            $portfolioId = (int) ($_POST['portfolio_id'] ?? 0);
            if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);
            if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
                json_response(false, 'Invalid portfolio.');
            }

            // MF total
            $mfTotal = (float) DB::fetchVal(
                'SELECT COALESCE(SUM(value_now), 0) FROM mf_holdings WHERE portfolio_id = ? AND is_active = 1',
                [$portfolioId]
            );
            $mfInvested = (float) DB::fetchVal(
                'SELECT COALESCE(SUM(total_invested), 0) FROM mf_holdings WHERE portfolio_id = ? AND is_active = 1',
                [$portfolioId]
            );

            // Stock total
            $stTotal = (float) DB::fetchVal(
                'SELECT COALESCE(SUM(current_value), 0) FROM stock_holdings WHERE portfolio_id = ? AND is_active = 1',
                [$portfolioId]
            );
            $stInvested = (float) DB::fetchVal(
                'SELECT COALESCE(SUM(total_invested), 0) FROM stock_holdings WHERE portfolio_id = ? AND is_active = 1',
                [$portfolioId]
            );

            // NPS total
            $npsTotal = (float) DB::fetchVal(
                'SELECT COALESCE(SUM(latest_value), 0) FROM nps_holdings WHERE portfolio_id = ?',
                [$portfolioId]
            );
            $npsInvested = (float) DB::fetchVal(
                'SELECT COALESCE(SUM(total_invested), 0) FROM nps_holdings WHERE portfolio_id = ?',
                [$portfolioId]
            );

            // FD total (principal + accrued)
            $fdTotal = (float) DB::fetchVal(
                "SELECT COALESCE(SUM(
                    principal * POW(1 + interest_rate/100/4, 4 * DATEDIFF(LEAST(maturity_date, CURDATE()), open_date)/365)
                 ), 0)
                 FROM fd_accounts WHERE portfolio_id = ? AND status = 'active'",
                [$portfolioId]
            );
            $fdInvested = (float) DB::fetchVal(
                "SELECT COALESCE(SUM(principal), 0) FROM fd_accounts WHERE portfolio_id = ? AND status = 'active'",
                [$portfolioId]
            );

            // Savings total
            $savTotal = (float) DB::fetchVal(
                'SELECT COALESCE(SUM(balance), 0) FROM savings_accounts WHERE portfolio_id = ? AND is_active = 1',
                [$portfolioId]
            );

            $totalValue    = $mfTotal + $stTotal + $npsTotal + $fdTotal + $savTotal;
            $totalInvested = $mfInvested + $stInvested + $npsInvested + $fdInvested + $savTotal;
            $totalGain     = $totalValue - $totalInvested;
            $totalGainPct  = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0;

            json_response(true, '', [
                'net_worth'       => round($totalValue, 2),
                'total_invested'  => round($totalInvested, 2),
                'total_gain'      => round($totalGain, 2),
                'total_gain_pct'  => $totalGainPct,
                'breakdown'       => [
                    'mf'      => ['value' => round($mfTotal, 2),  'invested' => round($mfInvested, 2)],
                    'stocks'  => ['value' => round($stTotal, 2),  'invested' => round($stInvested, 2)],
                    'nps'     => ['value' => round($npsTotal, 2), 'invested' => round($npsInvested, 2)],
                    'fd'      => ['value' => round($fdTotal, 2),  'invested' => round($fdInvested, 2)],
                    'savings' => ['value' => round($savTotal, 2), 'invested' => round($savTotal, 2)],
                ],
            ]);

        // ── MF routes (delegate to specific files) ──────────
        case 'mf_search':
            require APP_ROOT . '/api/mutual_funds/mf_search.php'; exit;
        case 'fund_house_rankings':  // t168
            require APP_ROOT . '/api/mutual_funds/fund_house_rankings.php'; exit;

        // ── t97 / t177 / t178 — Fund Sectors & Top Holdings ──
        case 'fund_sectors':
        case 'portfolio_sectors':
        case 'sector_allocation':
        case 'sector_filter_list':
        case 'fetch_sectors_amfi':
            require APP_ROOT . '/api/mutual_funds/fund_sectors.php'; exit;

        // ── t176 / t178 — Fund Holdings Overlap & Top Holdings
        // fund_holdings.php is standalone — re-reads $_GET['action'] internally
        case 'fund_holdings_status':
            $_GET['action'] = 'status';
            require APP_ROOT . '/api/mutual_funds/fund_holdings.php'; exit;
        case 'fund_holdings_data':
            $_GET['action'] = 'holdings';
            require APP_ROOT . '/api/mutual_funds/fund_holdings.php'; exit;
        case 'fund_holdings_overlap':
            $_GET['action'] = 'overlap';
            require APP_ROOT . '/api/mutual_funds/fund_holdings.php'; exit;
        case 'fund_holdings_matrix':
            $_GET['action'] = 'matrix';
            require APP_ROOT . '/api/mutual_funds/fund_holdings.php'; exit;
        case 'fund_holdings_sector':
            $_GET['action'] = 'sector_allocation';
            require APP_ROOT . '/api/mutual_funds/fund_holdings.php'; exit;
        case 'fund_top_holdings':
            $_GET['action'] = 'top_holdings';
            require APP_ROOT . '/api/mutual_funds/fund_holdings.php'; exit;

        // ── Fund Screener (t108 / t96 / t109 / t110 etc.) ───
        case 'fund_screener':
        case 'fund_detail':
        case 'fund_compare':
        case 'fund_top_performers':
        case 'recommend_funds':
        case 'saved_screens_list':
        case 'saved_screen_save':
        case 'saved_screen_delete':
        case 'filter_meta':
            require APP_ROOT . '/api/mutual_funds/fund_screener.php'; exit;

        // ── t187 — CAS Import (CAMS / KFintech) ─────────────
        case 'cas_parse':
        case 'cas_import':
        case 'cas_status':
            require APP_ROOT . '/api/mutual_funds/cas_import.php'; exit;

        // ── t262 — Rolling Returns Chart ─────────────────────
        case 'rolling_returns':
            require APP_ROOT . '/api/mutual_funds/rolling_returns.php'; exit;

        // ── Lumpsum vs SIP Optimizer ─────────────────────────
        // Standalone file — map prefixed names to internal action names
        case 'lumpsum_sip_signal':
            $_GET['action'] = 'market_signal';
            require APP_ROOT . '/api/mutual_funds/lumpsum_sip_optimizer.php'; exit;
        case 'lumpsum_sip_historical':
            $_GET['action'] = 'historical';
            require APP_ROOT . '/api/mutual_funds/lumpsum_sip_optimizer.php'; exit;
        case 'lumpsum_sip_recommend':
            $_GET['action'] = 'fund_recommendation';
            require APP_ROOT . '/api/mutual_funds/lumpsum_sip_optimizer.php'; exit;
        case 'lumpsum_sip_all':
            $_GET['action'] = 'all';
            require APP_ROOT . '/api/mutual_funds/lumpsum_sip_optimizer.php'; exit;

        // ── MF Metrics (Volatility / Sortino / Calmar etc.) ──
        // Standalone file — map prefixed names to internal action names
        case 'mf_volatility':
            $_GET['action'] = $_POST['action'] = 'volatility';
            require APP_ROOT . '/api/mutual_funds/mf_metrics.php'; exit;
        case 'mf_sortino':
            $_GET['action'] = $_POST['action'] = 'sortino';
            require APP_ROOT . '/api/mutual_funds/mf_metrics.php'; exit;
        case 'mf_calmar':
            $_GET['action'] = $_POST['action'] = 'calmar';
            require APP_ROOT . '/api/mutual_funds/mf_metrics.php'; exit;
        case 'mf_fund_age':
            $_GET['action'] = $_POST['action'] = 'fund_age';
            require APP_ROOT . '/api/mutual_funds/mf_metrics.php'; exit;
        case 'mf_momentum':
            $_GET['action'] = $_POST['action'] = 'momentum';
            require APP_ROOT . '/api/mutual_funds/mf_metrics.php'; exit;
        case 'mf_metrics_all':
            $_GET['action'] = $_POST['action'] = 'all';
            require APP_ROOT . '/api/mutual_funds/mf_metrics.php'; exit;

        // ── IDCW / Dividend Tracker ───────────────────────────
        case 'idcw_dividends':
            $_GET['action'] = $_POST['action'] = 'dividends';
            require APP_ROOT . '/api/mutual_funds/idcw_tracker.php'; exit;
        case 'idcw_tax_impact':
            $_GET['action'] = $_POST['action'] = 'tax_impact';
            require APP_ROOT . '/api/mutual_funds/idcw_tracker.php'; exit;
        case 'idcw_portfolio':
            $_GET['action'] = $_POST['action'] = 'portfolio_idcw';
            require APP_ROOT . '/api/mutual_funds/idcw_tracker.php'; exit;
        case 'idcw_comparison':
            $_GET['action'] = $_POST['action'] = 'comparison';
            require APP_ROOT . '/api/mutual_funds/idcw_tracker.php'; exit;

        // ── Tax Loss Harvesting ───────────────────────────────
        case 'tlh_candidates':
            $_GET['action'] = $_POST['action'] = 'candidates';
            require APP_ROOT . '/api/mutual_funds/tax_loss_harvest.php'; exit;
        case 'tlh_impact':
            $_GET['action'] = $_POST['action'] = 'impact';
            require APP_ROOT . '/api/mutual_funds/tax_loss_harvest.php'; exit;
        case 'tlh_dashboard':
            $_GET['action'] = $_POST['action'] = 'dashboard';
            require APP_ROOT . '/api/mutual_funds/tax_loss_harvest.php'; exit;

        // ── SIP Pause Intelligence ────────────────────────────
        case 'sip_pause_impact':
            $_GET['action'] = $_POST['action'] = 'pause_impact';
            require APP_ROOT . '/api/mutual_funds/sip_pause_intelligence.php'; exit;
        case 'sip_pause_market':
            $_GET['action'] = $_POST['action'] = 'market_context';
            require APP_ROOT . '/api/mutual_funds/sip_pause_intelligence.php'; exit;
        case 'sip_pause_emergency':
            $_GET['action'] = $_POST['action'] = 'emergency_check';
            require APP_ROOT . '/api/mutual_funds/sip_pause_intelligence.php'; exit;
        case 'sip_pause_resume':
            $_GET['action'] = $_POST['action'] = 'resume_analysis';
            require APP_ROOT . '/api/mutual_funds/sip_pause_intelligence.php'; exit;
        case 'sip_pause_dashboard':
            $_GET['action'] = $_POST['action'] = 'dashboard';
            require APP_ROOT . '/api/mutual_funds/sip_pause_intelligence.php'; exit;

        // ── Watchlist (wl_list / wl_toggle) ──────────────────
        case 'wl_list':
        case 'wl_toggle':
            require APP_ROOT . '/api/mutual_funds/watchlist.php'; exit;

        // ── Portfolio Optimizer ───────────────────────────────
        case 'po_overlap_matrix':
            $_GET['action'] = $_POST['action'] = 'overlap_matrix';
            require APP_ROOT . '/api/mutual_funds/portfolio_optimizer.php'; exit;
        case 'po_consolidation':
            $_GET['action'] = $_POST['action'] = 'consolidation';
            require APP_ROOT . '/api/mutual_funds/portfolio_optimizer.php'; exit;
        case 'po_optimal_count':
            $_GET['action'] = $_POST['action'] = 'optimal_count';
            require APP_ROOT . '/api/mutual_funds/portfolio_optimizer.php'; exit;
        case 'po_full':
            $_GET['action'] = $_POST['action'] = 'full';
            require APP_ROOT . '/api/mutual_funds/portfolio_optimizer.php'; exit;

        // ── Available Units (sell validation) ────────────────
        case 'mf_available_units':
            require APP_ROOT . '/api/mutual_funds/mf_available_units.php'; exit;

        // ── Dividend History ──────────────────────────────────
        case 'dividend_history':
        case 'dividend_portfolio':
            require APP_ROOT . '/api/mutual_funds/dividend_history.php'; exit;

        // ── Benchmark Proxy (internal) ────────────────────────
        case 'benchmark_proxy':
            require APP_ROOT . '/api/mutual_funds/benchmark_proxy.php'; exit;
        case 'user_profile_save':    // t54 — User Settings
        case 'user_change_password':
            require APP_ROOT . '/api/user_settings.php'; exit;

        case 'pa_list':              // t77 — DB-persistent price alerts
        case 'pa_add':
        case 'pa_delete':
        case 'pa_toggle':
            require APP_ROOT . '/api/mutual_funds/price_alerts.php'; exit;

        case 'ter_trend':            // t169
            require APP_ROOT . '/api/mutual_funds/ter_trend.php'; exit;
        case 'fund_managers':        // t180
        case 'manager_stats':        // tv05 — Fund Manager Track Record
            require APP_ROOT . '/api/mutual_funds/fund_managers.php'; exit;

        case 'sector_performance':   // t502 — Sector Rotation Tracker
        case 'sector_heatmap':
        case 'portfolio_sector_exposure':
        case 'sector_trend':
            require APP_ROOT . '/api/mutual_funds/sector_rotation.php'; exit;
        case 'import_history':       // t190
            require APP_ROOT . '/api/mutual_funds/import_history.php'; exit;
        case 'mf_add':
        case 'mf_edit':
            require APP_ROOT . '/api/mutual_funds/mf_add.php'; exit;
        case 'mf_delete':
            require APP_ROOT . '/api/mutual_funds/mf_delete.php'; exit;
        case 'mf_list':
            require APP_ROOT . '/api/mutual_funds/mf_list.php'; exit;
        case 'mf_nav_history':
            require APP_ROOT . '/api/mutual_funds/mf_nav_history.php'; exit;
        case 'nav_proxy':  // t163 — NAV chart proxy with DB cache
            require APP_ROOT . '/api/mutual_funds/nav_proxy.php'; exit;
        case 'mf_import_csv':
            require APP_ROOT . '/api/mutual_funds/mf_import_csv.php'; exit;
        case 'fund_notes_get':
        case 'fund_note_save':
        case 'fund_note_delete':
            require APP_ROOT . '/api/mutual_funds/mf_notes.php'; exit;

        // ── Phase 3: Reports ─────────────────────────────────
        case 'report_fy_gains':
            require APP_ROOT . '/api/reports/fy_gains.php'; exit;
        case 'annual_report_data':  // t376
            require APP_ROOT . '/api/reports/annual_report.php'; exit;
        case 'report_tax_planning':
            require APP_ROOT . '/api/reports/tax_planning.php'; exit;
        case 'report_net_worth':
            require APP_ROOT . '/api/reports/net_worth.php'; exit;
        case 'nw_timeline':        // t207: Net Worth Timeline fetch
        case 'nw_snapshot_save':   // t207: Save monthly snapshot
            require APP_ROOT . '/api/reports/net_worth_timeline.php'; exit;
        case 'nw_projection':      // t396: Net Worth Projection 5/10/20yr
            require APP_ROOT . '/api/reports/networth_projection.php'; exit;
        case 'nps_sip_tracker':    // t197: NPS Contribution SIP Tracker
            require APP_ROOT . '/api/nps/nps_sip_tracker.php'; exit;
        case 'po_rate_alert':      // t320: PO Rate Change Alert
            require APP_ROOT . '/api/post_office/po_rate_alert.php'; exit;
        case 'report_rebalancing':
        case 'rebalancing_save_targets':
        case 'rebalancing_load_targets':
            require APP_ROOT . '/api/reports/rebalancing.php'; exit;
        case 'export_csv':
        case 'export_holdings_csv':
        case 'export_tax_report_csv':
            require APP_ROOT . '/api/reports/export_csv.php'; exit;

        // ── Phase 4: NPS ─────────────────────────────────────
        case 'nps_nav_history':
            require APP_ROOT . '/api/nps/nps_nav_history.php'; exit;
        case 'nps_list':
            require APP_ROOT . '/api/nps/nps_list.php'; exit;
        case 'nps_add':
            require APP_ROOT . '/api/nps/nps_add.php'; exit;
        case 'nps_delete':
            require APP_ROOT . '/api/nps/nps_delete.php'; exit;
        case 'nps_nav_update':
            require APP_ROOT . '/api/nps/nps_nav_update.php'; exit;
        case 'nps_statement':
            require APP_ROOT . '/api/nps/nps_statement.php'; exit;
        case 'admin_nps_nav_trigger':
            if (!$isAdmin) json_response(false, 'Admin only.', [], 403);
            require APP_ROOT . '/api/admin/nps_nav_trigger.php'; exit;

                // ── Market Indexes ─────────────────────────────────
        case 'indexes_fetch':
            require APP_ROOT . '/api/indexes/indexes_fetch.php'; exit;

// ── Phase 4: Stocks ──────────────────────────────────
        case 'stocks_list':
            require APP_ROOT . '/api/stocks/stocks_list.php'; exit;
        case 'stocks_add':
            require APP_ROOT . '/api/stocks/stocks_add.php'; exit;
        case 'stocks_delete':
            require APP_ROOT . '/api/stocks/stocks_delete.php'; exit;
        case 'stocks_search':
            require APP_ROOT . '/api/stocks/stocks_search.php'; exit;
        case 'stocks_refresh_prices':
            require APP_ROOT . '/api/stocks/stocks_refresh_prices.php'; exit;
        case 'stocks_price':
            require APP_ROOT . '/api/stocks/stocks_price.php'; exit;
        case 'stocks_fundamentals':
        case 'fundamentals_all':
        case 'holdings_enriched':
        case 'week52_tracker':
        case 'fundamentals_history':
            require APP_ROOT . '/api/stocks/fundamentals.php'; exit;

        // ── t222 — NSE India Free Data ───────────────────────────
        case 'nse_quote':
        case 'nse_quote_bulk':
        case 'nse_ohlc':
        case 'nse_price_sync':
        case 'nse_indexes':
        case 'nse_index_detail':
            require APP_ROOT . '/api/stocks/nse_data.php'; exit;
        case 'stocks_alert_list':    // t344/t345
        case 'stocks_alert_save':
        case 'stocks_alert_delete':
        case 'stocks_alert_toggle':
        case 'stocks_alert_check':
            require APP_ROOT . '/api/stocks/alerts.php'; exit;

        // ── Phase 4: FD ───────────────────────────────────────
        case 'fd_list':
            require APP_ROOT . '/api/fd/fd_list.php'; exit;
        case 'fd_add':
            require APP_ROOT . '/api/fd/fd_add.php'; exit;
        case 'fd_delete':
            require APP_ROOT . '/api/fd/fd_delete.php'; exit;
        case 'fd_mature':
        case 'fd_maturity':
            require APP_ROOT . '/api/fd/fd_mature.php'; exit;

        // ── Phase 4: Savings ─────────────────────────────────
        // ── Post Office Schemes ─────────────────────────────────
        case 'po_list':
        case 'po_add':
        case 'po_edit':
        case 'po_close':
        case 'po_delete':
        case 'po_meta':
            require APP_ROOT . '/api/po_schemes/po_schemes.php'; exit;

        case 'savings_list':
            require APP_ROOT . '/api/savings/savings_list.php'; exit;
        case 'savings_add':
        case 'savings_add_interest':
        case 'savings_update_balance':
            require APP_ROOT . '/api/savings/savings_add.php'; exit;
        case 'savings_delete':
        case 'savings_delete_interest':
            require APP_ROOT . '/api/savings/savings_delete.php'; exit;

        // ── Admin NAV Update ─────────────────────────────────
        case 'admin_nav_update':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/nav/update_amfi.php'; exit;
        case 'admin_import_amfi':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            $_GET['mode'] = 'full_import';
            require APP_ROOT . '/api/nav/update_amfi.php'; exit;

        // ── Phase 5: SIP Tracker ─────────────────────────────
        case 'sip_list':
        case 'sip_analysis':
        case 'sip_upcoming':
        case 'sip_monthly_chart':
        case 'sip_add':
        case 'sip_edit':
        case 'sip_delete':
        case 'sip_stop':
        case 'sip_xirr':
        case 'sip_nav_status':
        case 'sip_nav_token':
        case 'sip_sync_txns':
            require APP_ROOT . '/api/reports/sip_tracker.php'; exit;

        // ── Phase 5: Goal Planning ───────────────────────────  [BUG-02 FIXED]
        case 'goal_list':
        case 'goal_add':
        case 'goal_edit':
        case 'goal_delete':
        case 'goal_mark_achieved':
        case 'goal_contribute':
        case 'goal_projection':
        case 'goal_asset_allocation':  // t292
        case 'goal_link_asset':        // t292
        case 'goal_unlink_asset':      // t292
            require APP_ROOT . '/api/reports/goal_planning.php'; exit;

        // ── tg001: Monte Carlo Goal Probability Simulator ─────
        case 'monte_carlo_run':
        case 'monte_carlo_save':
        case 'monte_carlo_history':
        case 'monte_carlo_delete':
        case 'monte_carlo_presets':
            require APP_ROOT . '/api/goals/monte_carlo.php'; exit;

        // ── tg002: Bucket Strategy ────────────────────────────
        case 'bucket_strategy_summary':
        case 'bucket_strategy_goals':
        case 'bucket_strategy_health':
        case 'bucket_strategy_save':
        case 'bucket_strategy_load':
            require APP_ROOT . '/api/goals/bucket_strategy.php'; exit;

        // ── Notifications Center ─────────────────────────────
        case 'notif_list':           // t57/t81 — Notifications Center
        case 'notif_unread_count':
        case 'notif_mark_read':
        case 'notif_mark_all_read':
        case 'notif_clear_all':
        case 'notif_prefs_get':
        case 'notif_prefs_save':
            require APP_ROOT . '/api/notifications.php'; exit;

        // ── Admin — Recalculate Holdings ─────────────────────
        case 'admin_recalc_holdings':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/recalc_holdings.php'; exit;

        // ── Admin — TER Import ────────────────────────────────
        case 'admin_import_ter':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/mutual_funds/import_ter.php'; exit;

        // ── Admin — Exit Load Seeder ──────────────────────────
        case 'admin_import_exit_load':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/mutual_funds/import_exit_load.php'; exit;

        // ── Admin — Fund Rules (LTCG / Lock-in) ──────────────
        case 'admin_fund_rules_search':
        case 'admin_fund_rules_get':
        case 'admin_fund_rules_update':
        case 'admin_fund_rules_bulk_update':
        case 'admin_fund_rules_categories':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/fund_rules.php'; exit;

        // ── Admin — DB Manager ────────────────────────────────
        case 'admin_db_list':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/db_manage.php'; exit;
        case 'admin_db_truncate_one':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/db_manage.php'; exit;
        case 'admin_db_truncate_all':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/db_manage.php'; exit;
        case 'admin_db_protect':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/db_manage.php'; exit;
        case 'admin_db_unprotect':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/db_manage.php'; exit;

        // ── Admin — Setup Wizard (status only; downloads are direct) ─
        case 'admin_db_status':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/db_setup_download.php'; exit;

        // ── t417: Admin — DB Migrations ──────────────────────
        case 'admin_migrations_list':
        case 'admin_migrations_run':
        case 'admin_migrations_rollback':
            if (!$isAdmin) json_response(false, 'Admin only', [], 403);
            require APP_ROOT . '/api/admin/db_migrations.php'; exit;

        // ── Phase 5: Admin — Users ───────────────────────────
        case 'admin_users':
        case 'admin_add_user':
        case 'admin_toggle_user':
        case 'admin_change_role':
        case 'admin_reset_password':
        case 'admin_delete_user':
        case 'admin_stats':
            require APP_ROOT . '/api/admin/users.php'; exit;

        // ── Phase 5: Admin — Settings ────────────────────────
        case 'admin_settings_get':
        case 'admin_settings_save':
        case 'admin_audit_log':
            require APP_ROOT . '/api/admin/settings.php'; exit;

        // ── t309: Admin — Cron Job Dashboard ────────────────
        case 'admin_cron_status':
        case 'admin_cron_history':
        case 'admin_cron_trigger':
        case 'admin_cron_clear':
            require APP_ROOT . '/api/admin/cron_dashboard.php'; exit;

        // ── t310: Admin — Rate Limiter ────────────────────────
        case 'admin_rl_stats':
        case 'admin_rl_flush':
        case 'admin_rl_reset_bucket':
            require APP_ROOT . '/api/admin/rate_limit_admin.php'; exit;

        // ── tv13: Admin — Data Quality ───────────────────────
        case 'data_quality_report':
        case 'data_quality_fix_nav':
            require APP_ROOT . '/api/admin/data_quality.php'; exit;

        // ── tv08: NFO Tracker ────────────────────────────────
        // ── t343: Live NAV Widget ─────────────────────────────────────
        case 'live_nav_estimate':
            require APP_ROOT . '/api/mutual_funds/live_nav.php'; exit;

        case 'nfo_list':
            require APP_ROOT . '/api/mutual_funds/nfo_list.php'; exit;

        // ── tv10 + t405: Watchlist Alerts (v2) ──────────────────
        case 'wl_alerts_list':
        case 'wl_alert_save':
        case 'wl_alert_delete':
        case 'wl_alerts_check':
        case 'wl_alert_save_multi':
        case 'wl_alerts_history':
        case 'wl_alert_snooze':
        case 'wl_alerts_simulate':
            require APP_ROOT . '/api/mutual_funds/wl_alerts.php'; exit;

        // ── tv01 + t362: Fund Ratings + Health Score ─────────────
        case 'fund_ratings_get':
        case 'fund_ratings_list':
        case 'fund_ratings_recalc':
        case 'fund_health_score':
        case 'fund_health_top':
            require APP_ROOT . '/api/mutual_funds/fund_ratings.php'; exit;

        // ── tv04: Style Box ──────────────────────────────────────
        case 'style_box_map':
        case 'style_box_fund':
        case 'style_box_screener':
        case 'style_box_portfolio':
        case 'style_box_recalc':
            require APP_ROOT . '/api/mutual_funds/style_box.php'; exit;

        // ── tv11: Benchmark Comparison ───────────────────────────
        case 'benchmark_compare':
        case 'benchmark_alpha':
        case 'benchmark_defaults':
        case 'benchmark_assign':
        case 'benchmark_bulk_assign':
            require APP_ROOT . '/api/mutual_funds/fund_benchmark.php'; exit;

        // ── t483: ISIN Validator ─────────────────────────────────
        case 'isin_validate':
        case 'isin_validate_code':
        case 'isin_batch_validate':
        case 'isin_fix':
        case 'isin_cache_refresh':
        case 'isin_invalid_list':
            require APP_ROOT . '/api/mutual_funds/isin_validator.php'; exit;

        // ── t490: CSV Importer v3 ────────────────────────────────
        case 'csv_v3_detect':
        case 'csv_v3_import':
        case 'csv_v3_formats':
            require APP_ROOT . '/api/mutual_funds/mf_import_csv_v3.php'; exit;

        // ── t24 — Crypto Holdings ───────────────────────────────────
        case 'crypto_list':
        case 'crypto_prices':
        case 'crypto_summary':
        case 'crypto_txns':
        case 'crypto_add':
        case 'crypto_delete':
        case 'crypto_txn_add':
            require APP_ROOT . '/api/crypto/crypto_list.php'; exit;

        // ── t315 — Crypto Holdings Full Portfolio Tracking ───────
        case 'crypto_portfolio_stats':
        case 'crypto_edit_holding':
        case 'crypto_staking_list':
        case 'crypto_staking_add':
        case 'crypto_staking_delete':
        case 'crypto_wl_list':
        case 'crypto_wl_add':
        case 'crypto_wl_delete':
        case 'crypto_tax_year_summary':
            require APP_ROOT . '/api/crypto/crypto_holdings.php'; exit;

        // ── tc001 — Live Crypto Price Stream (SSE) ───────────────
        case 'crypto_price_stream':
            require APP_ROOT . '/api/crypto/crypto_price_stream.php'; exit;

        // ── t42 — VDA Tax Calculator ─────────────────────────────
        case 'crypto_vda_tax':
            require APP_ROOT . '/api/crypto/vda_tax.php'; exit;

        // ── t62 — AI Tax Optimization ────────────────────────────
        case 'ai_tax_optimize':
            require APP_ROOT . '/api/reports/tax_planning.php'; exit;

        // ── t383 — AI Tax Optimizer (full engine) ────────────────
        case 'ai_tax_get_suggestions':
        case 'ai_tax_full_analysis':
        case 'ai_tax_income_update':
        case 'ai_tax_checklist':
        case 'ai_tax_deadline_alerts':
            require APP_ROOT . '/api/ai_advisor/tax.php'; exit;

        // ── t386: 2FA — TOTP Setup / Verify / Disable  [BUG-03 FIX] ──
        case '2fa_status':
        case '2fa_setup':
        case '2fa_verify_setup':
        case '2fa_disable':
        case '2fa_verify_login':
            require APP_ROOT . '/api/auth/2fa.php'; exit;

        // ── t387: Session Security — List / Revoke  [BUG-03 FIX] ────
        case 'sessions_list':
        case 'session_revoke':
        case 'session_revoke_all':
            require APP_ROOT . '/api/auth/session_security.php'; exit;


        // ── t482: Portfolio Audit Log ────────────────────────────────────
        case 'audit_log_list':
        case 'audit_log_stats':
        case 'audit_log_export':
            require APP_ROOT . '/api/mutual_funds/audit_log.php'; exit;

        // ── t499: Behavioral Nudge Engine ───────────────────────────────
        case 'nudge_get':
        case 'nudge_list_all':
        case 'nudge_dismiss':
            require APP_ROOT . '/api/mutual_funds/nudge_engine.php'; exit;

        // ── tj003: Spending Discipline Score ────────────────────────────
        case 'discipline_get':
        case 'discipline_set_income':
        case 'discipline_history':
            require APP_ROOT . '/api/user/discipline.php'; exit;

        // ── t408: Investment Journal ─────────────────────────────────────
        case 'journal_list':
        case 'journal_add':
        case 'journal_edit':
        case 'journal_delete':
        case 'journal_calendar':
        case 'journal_get':
            require APP_ROOT . '/api/mutual_funds/investment_journal.php'; exit;

        // ── t503: Credit Card Optimizer ──────────────────────────────────
        case 'cc_list':
        case 'cc_add':
        case 'cc_edit':
        case 'cc_delete':
        case 'cc_summary':
        case 'cc_avalanche':
            require APP_ROOT . '/api/mutual_funds/credit_card.php'; exit;

        // ── t491: Cross-FY Comparison ────────────────────────────────────
        case 'fy_compare':
        case 'fy_holdings_span':
            require APP_ROOT . '/api/reports/fy_gains.php'; exit;

        // ── t273: NPS Allocation Analyzer ───────────────────────────────
        case 'nps_allocation_analyzer':
            require APP_ROOT . '/api/nps/nps_analytics.php'; exit;

        // ── t484: Sunburst Chart Data ────────────────────────────────────
        case 'sunburst_data':
            require APP_ROOT . '/api/mutual_funds/sunburst_data.php'; exit;

        // ── t298: Market Pulse Widget ────────────────────────────────
        case 'market_pulse':
            require APP_ROOT . '/api/dashboard/market_pulse.php'; exit;

        // ── t442: Unified Dashboard — All Assets ─────────────────────
        case 'unified_summary':
        case 'unified_activity':
        case 'unified_alerts':
            require APP_ROOT . '/api/dashboard/unified.php'; exit;

        // ── t443: Morning Briefing — 9AM digest ──────────────────────
        case 'morning_briefing':
            require APP_ROOT . '/api/dashboard/morning_briefing.php'; exit;

        // ── t300: Portfolio Heatmap ───────────────────────────────────
        case 'portfolio_heatmap':
            require APP_ROOT . '/api/dashboard/portfolio_heatmap.php'; exit;

        // ── t254/t291/t295/t400: Dashboard Widgets ───────────────────
        case 'fy_summary_card':
        case 'goal_rings':
        case 'networth_trend':
        case 'milestone_status':
            require APP_ROOT . '/api/dashboard/widgets.php'; exit;

        // ── t496: Tax Slab Calculator ────────────────────────────────
        case 'tax_slab_calc':
            require APP_ROOT . '/api/reports/tax_slab_calc.php'; exit;

        // ── t413: Data Integrity Check ───────────────────────────────
        case 'data_integrity_check':
        case 'data_integrity_fix':
            require APP_ROOT . '/api/mutual_funds/data_integrity.php'; exit;

        // ── t293: FIRE Calculator ────────────────────────────────────
        case 'fire_calc':
            // Handled client-side; no server action needed
            json_response(false, 'Use the FIRE Calculator page directly.', [], 400); exit;

        // ── t233: Portfolio Overlap Checker ──────────────────────────
        case 'portfolio_overlap':
            require APP_ROOT . '/api/mutual_funds/portfolio_overlap.php'; exit;

        // ── t287+t290: Tax Intelligence (Debt Tax + LTCG Harvest) ────
        case 'debt_fund_tax':
        case 'ltcg_harvest_schedule':
            require APP_ROOT . '/api/reports/tax_intelligence.php'; exit;

        // ── t259: Data Backup ────────────────────────────────────────
        case 'data_backup_download':
        case 'data_backup_status':
            require APP_ROOT . '/api/user/data_backup.php'; exit;

        // ── t88: Ctrl+K Global Search ─────────────────────────────────────
        case 'global_search':
            require APP_ROOT . '/api/search/global_search.php'; exit;

        // ── t313: Wealth Statement ────────────────────────────────────────
        case 'wealth_statement':
            require APP_ROOT . '/api/reports/wealth_statement.php'; exit;

        // ── t379: Scheduled Reports ───────────────────────────────────────
        case 'scheduled_reports_list':
        case 'scheduled_report_save':
        case 'scheduled_report_delete':
        case 'scheduled_report_toggle':
            require APP_ROOT . '/api/reports/scheduled_reports.php'; exit;

        // ── t46 + t339: EPF Tracker + Interest Calculator ─────────────────
        case 'epf_list':
        case 'epf_add':
        case 'epf_update_balance':
        case 'epf_delete':
        case 'epf_interest_calc':
            require APP_ROOT . '/api/epf/epf_list.php'; exit;

        // ── t341: Gratuity Tracker ────────────────────────────────────────
        case 'gratuity_list':
        case 'gratuity_add':
        case 'gratuity_update':
        case 'gratuity_delete':
        case 'gratuity_calc':
            require APP_ROOT . '/api/epf/gratuity.php'; exit;

        case 'eps_pension_calc':
            require APP_ROOT . '/api/epf/eps_pension.php'; exit;

        case 'retirement_combined':
            require APP_ROOT . '/api/epf/retirement_combined.php'; exit;

        // ── t134: 54EC Bond Tracker ──────────────────────────────────────────
        case 'bonds54ec_list':
        case 'bonds54ec_add':
        case 'bonds54ec_edit':
        case 'bonds54ec_delete':
            require APP_ROOT . '/api/bonds/bonds_54ec.php'; exit;

        // ── t424: FD Interest Payout Tracker ────────────────
        case 'fd_interest_tracker':
            require APP_ROOT . '/api/fd/fd_interest_tracker.php'; exit;

        // ── t44: FD Laddering Visualization ─────────────────
        case 'fd_ladder':
            require APP_ROOT . '/api/fd/fd_ladder.php'; exit;

        // ── t466: Real Estate (t124 stub → full impl) ────────
        case 'realestate_list':
        case 'realestate_add':
        case 'realestate_update':
        case 'realestate_delete':
        case 'realestate_summary':
            require APP_ROOT . '/api/realestate/realestate.php'; exit;

        // ── t465: Physical Gold ───────────────────────────────
        case 'gold_list':
        case 'gold_add':
        case 'gold_update':
        case 'gold_delete':
        case 'gold_summary':
        case 'gold_update_rate':
            require APP_ROOT . '/api/gold/gold.php'; exit;

        // ── t394: SGB — Sovereign Gold Bonds ──────────────────────────────
        case 'sgb_list':
        case 'sgb_add':
        case 'sgb_update':
        case 'sgb_delete':
        case 'sgb_summary':
        case 'sgb_live_price':
        case 'sgb_refresh_nav':
        case 'sgb_interest_add':
        case 'sgb_interest_list':
        case 'sgb_series_list':
            require APP_ROOT . '/api/sgb/sgb.php'; exit;

        // ── t321/t322/t324/t459: Insurance Portfolio ──────────────────────
        case 'insurance_list':
        case 'insurance_add':
        case 'insurance_edit':
        case 'insurance_delete':
        case 'insurance_premium_calendar':
        case 'insurance_adequacy':
            require APP_ROOT . '/api/insurance/insurance_list.php'; exit;

        // ── t460: Health Insurance Tracker ───────────────────────────────
        case 'health_summary':
        case 'health_members_list':
        case 'health_member_add':
        case 'health_member_edit':
        case 'health_member_delete':
        case 'health_claims_list':
        case 'health_claim_add':
        case 'health_claim_edit':
        case 'health_claim_delete':
        case 'health_update_details':
        case 'health_ncb_update':
            require APP_ROOT . '/api/insurance/health_tracker.php'; exit;

        // ── t123: Loan Tracker ────────────────────────────────────────────
        case 'loans_list':
        case 'loans_add':
        case 'loans_delete':
        case 'loans_update_outstanding':
            require APP_ROOT . '/api/loan/loans_list.php'; exit;

        // ── t464: Home Loan EMI Tracker ───────────────────────────────────
        case 'hl_detail':
        case 'hl_update_details':
        case 'hl_rate_history':
        case 'hl_rate_add':
        case 'hl_rate_delete':
        case 'hl_prepayments':
        case 'hl_prepayment_add':
        case 'hl_prepayment_delete':
        case 'hl_tax_claims':
        case 'hl_tax_claim_save':
        case 'hl_emi_calendar':
            require APP_ROOT . '/api/loan/home_loan_tracker.php'; exit;

        // ── t211: Admin — DB Backup & Restore ────────────────────
        case 'admin_db_backup_list':
        case 'admin_db_backup_stats':
        case 'admin_db_backup_create':
        case 'admin_db_backup_delete':
        case 'admin_db_backup_download':
        case 'admin_db_restore_upload':
        case 'admin_db_restore_run':
        case 'admin_db_restore_log':
            if (!$isAdmin) json_response(false, 'Admin only.', [], 403);
            require APP_ROOT . '/api/admin/db_backup.php'; exit;

        // ── t479: Duplicate Transaction Detector ─────────────────
        case 'dedup_scan':
        case 'dedup_list':
        case 'dedup_merge':
        case 'dedup_dismiss':
        case 'dedup_check_new':
        case 'dedup_stats':
            require APP_ROOT . '/api/mutual_funds/dedup_detector.php'; exit;

        // ── t480: Data Validation Rules ───────────────────────────
        case 'validate_entry':
        case 'validation_rules_list':
        case 'validation_rule_save':
        case 'validation_rule_toggle':
        case 'validation_rule_delete':
        case 'validation_scan':
        case 'validation_violations':
        case 'validation_resolve':
            require APP_ROOT . '/api/mutual_funds/data_validation.php'; exit;

        // ── t504: REST API — Key Management ───────────────────────
        case 'api_keys_list':
        case 'api_key_create':
        case 'api_key_delete':
        case 'api_key_toggle':
        case 'api_usage_stats':
            require APP_ROOT . '/api/rest/api_key_manager.php'; exit;

        // ── t115: REITs & InvITs ──────────────────────────────────
        case 'reit_list':
        case 'reit_summary':
        case 'reit_master_list':
        case 'reit_add_holding':
        case 'reit_edit_holding':
        case 'reit_delete_holding':
        case 'reit_add_txn':
        case 'reit_delete_txn':
        case 'reit_txns':
        case 'reit_add_dist':
        case 'reit_distributions':
        case 'reit_update_price':
            require APP_ROOT . '/api/reits/reits.php'; exit;

        // ── t120: Smallcase Portfolio Sync ────────────────────────
        case 'smallcase_list':
        case 'smallcase_summary':
        case 'smallcase_add':
        case 'smallcase_edit':
        case 'smallcase_delete':
        case 'smallcase_holdings':
        case 'smallcase_sync':
        case 'smallcase_add_txn':
        case 'smallcase_txns':
        case 'smallcase_performance':
            require APP_ROOT . '/api/smallcase/smallcase.php'; exit;

        // ── t144: SIP Step-Up Nudge ───────────────────────────────
        case 'stepup_dashboard':
        case 'stepup_salary_add':
        case 'stepup_salary_list':
        case 'stepup_nudges':
        case 'stepup_respond':
        case 'stepup_calculate':
        case 'stepup_projection_save':
        case 'stepup_projection_get':
        case 'stepup_apply':
            require APP_ROOT . '/api/mutual_funds/sip_stepup_nudge.php'; exit;

        // ── t317: Crypto Exchange CSV Import ─────────────────────
        case 'crypto_import_preview':
        case 'crypto_import_confirm':
        case 'crypto_import_log':
            require APP_ROOT . '/api/crypto/crypto_import.php'; exit;

        // ── tc005: Exchange Sync (Binance / WazirX API) ──────────
        case 'exchange_keys_save':
        case 'exchange_keys_list':
        case 'exchange_keys_delete':
        case 'exchange_sync_run':
        case 'exchange_sync_log':
            require APP_ROOT . '/api/crypto/crypto_exchange_sync.php'; exit;


        // ── t117: ESOP / RSU — Grant + Vesting Tracker ───────────
        case 'esop_list':
        case 'esop_add':
        case 'esop_update':
        case 'esop_delete':
        case 'esop_summary':
        case 'esop_vesting_list':
        case 'esop_vesting_add':
        case 'esop_vesting_update':
        case 'esop_exercise_add':
        case 'esop_exercise_log':
        case 'esop_fmv_update':
        case 'esop_schedule_generate':
            require APP_ROOT . '/api/esop/esop.php'; exit;

        // ── t203: PPF Annual Deposit Tracker ─────────────────────
        case 'ppf_deposit_save':
        case 'ppf_deposit_delete':
        case 'ppf_deposit_get':
        case 'ppf_deposits_list':
        case 'ppf_deposit_history':
            require APP_ROOT . '/api/ppf/ppf_tracker.php'; exit;

        // ── t151: EPFO Passbook Sync ──────────────────────────────
        case 'epfo_accounts_list':
        case 'epfo_account_add':
        case 'epfo_account_delete':
        case 'epfo_import_pdf':
        case 'epfo_passbook':
        case 'epfo_summary':
        case 'epfo_sync_log':
            require APP_ROOT . '/api/epfo/epfo_sync.php'; exit;

        // ── t139: Goal-Based Buckets ──────────────────────────────
        case 'bucket_list':
        case 'bucket_summary':
        case 'bucket_progress':
        case 'bucket_add':
        case 'bucket_edit':
        case 'bucket_delete':
        case 'bucket_contribute':
        case 'bucket_link_asset':
        case 'bucket_unlink_asset':
        case 'bucket_mark_achieved':
            require APP_ROOT . '/api/goals/goal_buckets.php'; exit;

        // ── t151 (W5): EPFO Passbook — updated file + actions ────
        case 'epfo_passbook_import':
        case 'epfo_passbook_entries':
            require APP_ROOT . '/api/epfo/passbook.php'; exit;

        // ── t474: SIP vs EMI Monthly Load Analysis ────────────────
        case 'sip_emi_summary':
        case 'sip_emi_monthly_breakdown':
            require APP_ROOT . '/api/tools/sip_emi_balance.php'; exit;

        // ── t498: Investment Calendar ─────────────────────────────
        case 'inv_calendar_events':
        case 'inv_calendar_month':
            require APP_ROOT . '/api/tools/investment_calendar.php'; exit;

        // ── t380: AI Portfolio Review ─────────────────────────────
        case 'ai_review_generate':
        case 'ai_review_get':
        case 'ai_review_history':
        case 'ai_review_status':
        case 'ai_review_prefs_get':
        case 'ai_review_prefs_save':
        case 'ai_review_delete':
            require APP_ROOT . '/api/reports/ai_portfolio_review.php'; exit;

        // ── t381: AI Chat Assistant ───────────────────────────────
        case 'ai_chat_message':
        case 'ai_chat_session_new':
        case 'ai_chat_session_get':
        case 'ai_chat_session_delete':
        case 'ai_chat_history':
        case 'ai_chat_usage':
        case 'ai_chat_clear_all':
            require APP_ROOT . '/api/ai/ai_chat.php'; exit;

        // ── t205: NSC Interest Tracker ────────────────────────────
        case 'nsc_list':
        case 'nsc_80c_schedule':
        case 'nsc_fy_declaration':
        case 'nsc_maturity_calc':
        case 'nsc_80c_log_save':
        case 'nsc_80c_log_delete':
            require APP_ROOT . '/api/po_schemes/nsc_tracker.php'; exit;

        // ── t206: MIS/SCSS Payout Tracker ────────────────────────
        case 'mis_payout_calendar':
        case 'scss_payout_calendar':
        case 'payout_income_fy':
        case 'payout_upcoming':
        case 'mis_payout_summary':
        case 'scss_payout_summary':
        case 'payout_mark_received':
        case 'payout_mark_pending':
        case 'payout_bulk_generate':
        case 'payout_tds_log_save':
        case 'payout_tds_log_delete':
            require APP_ROOT . '/api/po_schemes/mis_scss_payout.php'; exit;

        // ── t115 (W1): REITs — additional actions ─────────────────
        case 'reits_list':
        case 'reits_summary':
        case 'reits_add':
        case 'reits_edit':
        case 'reits_delete':
        case 'reits_txn_add':
        case 'reits_txn_list':
        case 'reits_txn_delete':
        case 'reits_dist_add':
        case 'reits_dist_list':
        case 'reits_dist_delete':
        case 'reits_price_refresh':
        case 'reits_master_search':
            require APP_ROOT . '/api/reits/reits.php'; exit;

        // ── t120 (W1): Smallcase — additional actions ─────────────
        case 'smallcase_holding_list':
        case 'smallcase_holding_save':
        case 'smallcase_holding_delete':
        case 'smallcase_holding_bulk_import':
        case 'smallcase_txn_list':
        case 'smallcase_txn_add':
        case 'smallcase_txn_delete':
        case 'smallcase_rebalance_add':
        case 'smallcase_rebalance_list':
        case 'smallcase_price_update':
        case 'smallcase_calc_xirr':
            require APP_ROOT . '/api/smallcase/smallcase.php'; exit;

        // ── t144 (W1): SIP Step-Up — additional actions ───────────
        case 'stepup_list':
        case 'stepup_save':
        case 'stepup_delete':
        case 'stepup_history':
        case 'stepup_preview':
        case 'stepup_simulate':
        case 'stepup_nudge_dismiss':
        case 'stepup_nudge_salary_hike':
            require APP_ROOT . '/api/sip/sip_stepup.php'; exit;

        default:
            json_response(false, "Unknown action: {$action}", [], 400);
    }

} catch (Exception $e) {
    error_log('API error [' . $action . ']: ' . $e->getMessage());
    json_response(false, IS_LOCAL ? $e->getMessage() : 'An error occurred. Please try again.', [], 500);
}