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

// ─── PENDING TASKS CSRF-EXEMPT ADDITIONS (20 June 2026 merge) ───
    // Admin / Infra (ID-M)
    // t50: Multi-user Management (ID-M v2 — use this, NOT the older admin_* version)
    'mu_list', 'mu_get', 'mu_invitations', 'mu_activity',
    // t51: System Health Dashboard
    'health_full', 'health_ping', 'health_history', 'health_phpinfo',
    // t52: Global Settings Control (ID-M v2 — use this, NOT the older admin_* version)
    'gs_list', 'gs_get', 'gs_audit',
    // t53: DB Manager
    'dbm_tables', 'dbm_describe', 'dbm_preview', 'dbm_history', 'dbm_backup_list', 'dbm_index_report', 'dbm_variables',
    // t307: Audit Log (⚠ also UPDATE 3 existing cases — see note)
    'al_list', 'al_stats', 'al_get', 'al_filters', 'al_retention_get',
    // t308: System Performance Monitor
    'perf_live', 'perf_history', 'perf_slow_alerts', 'perf_percentiles', 'perf_actions',
    // t335: External API for Tools
    'extapi_list', 'extapi_scopes', 'extapi_log',
    // t336: Data Versioning — Undo Import
    'dv_list', 'dv_get', 'dv_stats',
    // t414: Error Monitoring
    'err_list', 'err_types', 'err_trend',
    // t415: Load Testing
    'load_test_list',
    // AI Features
    // t58: AI Portfolio Advisor
    'ai_advisor_action_plan',
    // t59: AI Auto Categorization
    'ai_categorize_transaction','ai_category_rules_list',
    // t61: AI Goal-based Planning
    'ai_goal_plan_list','ai_goal_plan_optimize',
    // t329: AI Weekly Digest
    'ai_digest_history',
    // t330: AI Chatbot (action names renamed — collide with t381)
    'ai_chatbot_history', 'ai_chatbot_sessions',
    // t331: AI SIP Optimizer
    'ai_sip_optimize',
    // t332: AI Goal Advisor
    'ai_goal_advice',
    // t333: AI Portfolio Report Card
    'ai_report_card_history',
    // t384: AI Anomaly Detector v2
    'ai_anomaly_v2_list',
    // t385: AI Goal Coach
    'ai_goal_coach_nudges',
    // Crypto Tools
    // t42: Crypto Tax 30% Flat Calculator (note: 2 conflicting action-name sets exist — see flag)
    'crypto_tax_calculate', 'crypto_tax_load',
    // tc006: Cold Wallet Tracker
    'cold_wallet_list', 'cold_wallet_holdings_list', 'cold_wallet_summary',
    // Tax / Retirement / Goals
    // t358: Goal Notification Engine
    'goal_notifications_list', 'goal_notifications_check',
    // t360: Life Events Calendar
    'life_events_list',
    // EPF / Insurance / Property / Loans
    // t122: Insurance Portfolio
    'insurance_list', 'insurance_summary', 'insurance_premium_calendar',
    // t123: Loan Tracker
    'loans_list', 'loan_summary', 'loan_amortization',
    // t323: ULIP Tracker
    'ulip_list', 'ulip_summary',
    // t461: ULIP Tracker Full
    'ulip_fund_list','ulip_fund_history','ulip_performance',
    // t462: Premium Calendar
    'premium_calendar_get','premium_history_list',
    // t463: Property Portfolio
    'property_list','property_summary','property_valuation_history',
    // t150: DigiLocker / Documents
    'doc_list','doc_categories','digilocker_connect_status',
    // t124: Real Estate / Rental Yield Calculator
    'rental_yield_calculate',
    // Dashboard / UX / Personalization
    // t55: Dashboard Widget Customizer
    'widget_layout_get','widget_data_get',
    // t297: Customizable Dashboard
    'dashboard_layout_get',
    // t350: Font Size Preference
    'font_size_get',
    // t373: Widget Mode
    'widget_mode_render','widget_mode_list',
    // t445: Customizable Overview Cards
    'overview_cards_get','overview_cards_data',
    // t446: Portfolio Health Heatmap
    'portfolio_health_heatmap',
    // t242: Investor Streak & Milestones
    'streak_status','milestones_list','badges_list',
    // Security & Onboarding
    // t371: Biometric Login (WebAuthn)
    'webauthn_register_options','webauthn_login_options','webauthn_login_verify','webauthn_credentials_list',
    // t387: Session Security
    'sessions_list','session_current','session_touch',
    // t389: GDPR Data Controls
    'data_export_list','data_export_download','data_delete_status',
    // t240: Onboarding Flow
    'onboarding_status',
    // t454: Onboarding Setup Wizard
    'setup_wizard_status',
    // Budget & Reports & Sharing
    // t471: Monthly Budget Tracker
    'budget_get','budget_actual_list','budget_summary','budget_categories_list',
    // t378: Report Sharing (WhatsApp/Email)
    'share_whatsapp_link',
    // t485: Portfolio Treemap
    'portfolio_treemap',
    // t488: Yield Curve
    'yield_curve_get','yield_curve_compare_banks',
    // t406: Anonymous Benchmarking
    'benchmark_status',
    // t503: Credit Card Optimizer
    'cc_list','cc_optimize_spend','cc_interest_calculator',
    // th001: Daily Financial Journal
    'djournal_list','djournal_search','djournal_stats','djournal_mood_chart',
    // t443: Morning Briefing
    'morning_briefing_get',
    // t155: Child Education Planner
    'edu_plan_calculate', 'edu_plans_list',
    // t404: Smart Alerts v2
    'smart_alerts_list','smart_alerts_unread_count','smart_alert_settings_get',
    // Tools & Profile
    // tp001: Cache Manager
    'cache_stats', 'cache_flush_user',
    // tp002: Lazy Loader
    'lazy_xirr', 'lazy_portfolio_summary',,
    // th002: SIP Discipline Score
    'sip_discipline_score', 'sip_discipline_history',
    // ti001: Personal Finance Profile
    'fp_get',
    // ti004: Personal Finance Score
    'finance_score',
    // tj005: Annual Financial Review
    'annual_review_checklist',

    // ─── SECOND PASS ADDITIONS (20 Jun 2026) ───
    // t302: Groww CSV Import
    'groww_detect', 'groww_sessions', 'groww_session_detail',
    'groww_fund_map_list', 'groww_import_status',
    // t334: Bulk Import
    'bulk_template_fields', 'bulk_template_download', 'bulk_session_list',
    'bulk_session_detail',
    // t392: Groww API Sync
    'groww_api_status', 'groww_api_sync_log', 'groww_api_mapped_funds',
    // t490: CSV Importer v3
    'csv_v3_sessions', 'csv_v3_session_detail', 'csv_v3_preset_list', 'csv_v3_stats',
    // t136: AIS/TIS Reconciliation
    'ais_list', 'ais_summary', 'ais_mismatch_report',
    // t138: Indexation Calculator
    'indexation_property_list',
    // t198: NPS Screener Enhanced
    'nps_screener',
    // t314: Monthly P&L
    'monthly_pl', 'monthly_pl_history', 'monthly_pl_chart',
    // t340: EPFO UAN
    'uan_profile_get', 'uan_summary',
    // t422: Auto Sweep FD
    'sweep_list', 'sweep_summary',
    // t45: RD Tracker
    'rd_list', 'rd_maturity', 'rd_installment_log',
    // t49: Leave/LTA Tracker
    'lta_summary', 'leave_summary', 'leave_lta_fy',
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
            // t307 FIX (20 Jun 2026): repointed from api/mutual_funds/audit_log.php
            // to api/admin/audit_log.php — old file was wrong/outdated target.
            require APP_ROOT . '/api/admin/audit_log.php'; exit;

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

// ════════════════════════════════════════════════════════
// Admin / Infra (ID-M)
// ════════════════════════════════════════════════════════

        // ── t43: Bank Accounts Tracker ──
        // ── t43 (ID-M): Bank Accounts Tracker ────────────────────
        case 'bank_list':
        case 'bank_get':
        case 'bank_add':
        case 'bank_edit':
        case 'bank_delete':
        case 'bank_update_balance':
        case 'bank_summary':
        case 'bank_balance_history':
        case 'bank_txn_list':
        case 'bank_txn_add':
        case 'bank_txn_delete':
        case 'banks_list':
        require APP_ROOT . '/api/banks/banks.php'; exit;

        // ── t50: Multi-user Management (ID-M v2 — use this, NOT the older admin_* version) ──
        case 'mu_list':
        case 'mu_get':
        case 'mu_add':
        case 'mu_edit':
        case 'mu_delete':
        case 'mu_toggle_status':
        case 'mu_change_role':
        case 'mu_reset_password':
        case 'mu_kill_sessions':
        case 'mu_invite':
        case 'mu_invitations':
        case 'mu_activity':
        require APP_ROOT . '/api/admin/multi_user.php'; exit;

        // ── t51: System Health Dashboard ──
        // ── t51 (ID-M): System Health Dashboard ──────────────────
        case 'health_full':
        case 'health_ping':
        case 'health_history':
        case 'health_phpinfo':
        case 'health_clear_log':
        require APP_ROOT . '/api/admin/system_health.php'; exit;

        // ── t52: Global Settings Control (ID-M v2 — use this, NOT the older admin_* version) ──
        case 'gs_list':
        case 'gs_get':
        case 'gs_save':
        case 'gs_save_bulk':
        case 'gs_reset':
        case 'gs_maintenance_toggle':
        case 'gs_audit':
        case 'gs_test_email':
        require APP_ROOT . '/api/admin/global_settings.php'; exit;

        // ── t53: DB Manager ──
        // ── t53 (ID-M): DB Manager ────────────────────────────────
        case 'dbm_tables':
        case 'dbm_describe':
        case 'dbm_preview':
        case 'dbm_query':
        case 'dbm_execute':
        case 'dbm_confirm_token':
        case 'dbm_history':
        case 'dbm_backup':
        case 'dbm_backup_list':
        case 'dbm_optimize':
        case 'dbm_index_report':
        case 'dbm_variables':
        require APP_ROOT . '/api/admin/db_manager.php'; exit;

        // ── t307: Audit Log (also repointed 3 existing cases above — see note) ──
        case 'al_list':
        case 'al_stats':
        case 'al_get':
        case 'al_export':
        case 'al_purge':
        case 'al_retention_save':
        case 'al_retention_get':
        case 'al_filters':
        require APP_ROOT . '/api/admin/audit_log.php'; exit;

        // ── t308: System Performance Monitor ──
        // ── t308 (ID-M): Performance Monitor ─────────────────────
        case 'perf_live':
        case 'perf_history':
        case 'perf_slow_alerts':
        case 'perf_percentiles':
        case 'perf_purge':
        case 'perf_actions':
        require APP_ROOT . '/api/admin/perf_monitor.php'; exit;

        // ── t335: External API for Tools ──
        // ── t335 (ID-M): External API Key Manager ─────────────────
        case 'extapi_list':
        case 'extapi_create':
        case 'extapi_toggle':
        case 'extapi_delete':
        case 'extapi_regenerate':
        case 'extapi_log':
        case 'extapi_scopes':
        case 'extapi_verify':
        require APP_ROOT . '/api/external/api_key_manager.php'; exit;

        // ── t336: Data Versioning — Undo Import ──
        // ── t336 (ID-M): Data Versioning / Undo Import ────────────
        case 'dv_list':
        case 'dv_get':
        case 'dv_undo':
        case 'dv_delete':
        case 'dv_purge':
        case 'dv_stats':
        require APP_ROOT . '/api/admin/data_versioning.php'; exit;

        // ── t411: Test Runner — SKIPPED, backend file api/admin/test_runner_api.php
        //         does NOT exist on disk yet. Build it first, then uncomment below.
        //         (Actual tests already run via debug/runner.php regardless.)
        // case 'test_run_list':
        //     require APP_ROOT . '/api/admin/test_runner_api.php'; exit;

        // ── t414: Error Monitoring ──
        // ── t414 (ID-M): Error Monitoring ────────────────────────
        case 'err_list':
        case 'err_resolve':
        case 'err_unresolve':
        case 'err_delete':
        case 'err_purge_resolved':
        case 'err_types':
        case 'err_trend':
        case 'err_capture':
        require APP_ROOT . '/includes/error_monitor.php'; exit;

        // ── t415: Load Testing ──
        case 'load_test_list':
        require_auth(ROLE_ADMIN);
        $rows = DB::fetchAll('SELECT * FROM load_test_runs ORDER BY created_at DESC LIMIT 50');
        json_response(true, '', ['runs' => $rows]);
        exit;



// ════════════════════════════════════════════════════════
// AI Features
// ════════════════════════════════════════════════════════

        // ── t58: AI Portfolio Advisor ──
        case 'ai_advisor_full_review':
        case 'ai_advisor_ask':
        case 'ai_advisor_action_plan':
        require APP_ROOT . '/api/ai/portfolio_advisor.php'; exit;

        // ── t59: AI Auto Categorization ──
        case 'ai_categorize_transaction':
        case 'ai_categorize_bulk':
        case 'ai_category_rules_list':
        case 'ai_category_rule_add':
        case 'ai_category_rule_delete':
        require APP_ROOT . '/api/ai/auto_categorize.php'; exit;

        // ── t60: AI Anomaly Detection Base ──
        case 'ai_anomaly_detect_base':
        require APP_ROOT . '/api/ai/anomaly_base_redirect.php'; exit;

        // ── t61: AI Goal-based Planning ──
        case 'ai_goal_plan_create':
        case 'ai_goal_plan_list':
        case 'ai_goal_plan_optimize':
        case 'ai_goal_priority_simulate':
        require APP_ROOT . '/api/ai/goal_planning.php'; exit;

        // ── t243: AI Fund Recommendation ──
        case 'ai_fund_recommend':
        require APP_ROOT . '/api/ai/fund_recommend.php'; exit;

        // ── t244: AI Portfolio Narrative ──
        case 'ai_portfolio_narrative':
        require APP_ROOT . '/api/ai/portfolio_narrative.php'; exit;

        // ── t246: AI Anomaly Detector ──
        case 'ai_anomaly_detect':
        require APP_ROOT . '/api/ai/anomaly_detector.php'; exit;

        // ── t329: AI Weekly Digest ──
        case 'ai_weekly_digest':
        case 'ai_digest_history':
        require APP_ROOT . '/api/ai/weekly_digest.php'; exit;

        // ── t330: AI Chatbot (action names renamed — collide with t381) ──
        // RENAMED to avoid collision with t381 (already live):
        //   ai_chat_history  -> ai_chatbot_history
        //   ai_chat_sessions -> ai_chatbot_sessions
        case 'ai_chat_send':
        case 'ai_chatbot_history':
        case 'ai_chatbot_sessions':
        case 'ai_chat_clear':
        require APP_ROOT . '/api/ai/chatbot.php'; exit;

        // ── t331: AI SIP Optimizer ──
        case 'ai_sip_optimize':
        require APP_ROOT . '/api/ai/sip_optimizer.php'; exit;

        // ── t332: AI Goal Advisor ──
        case 'ai_goal_advice':
        require APP_ROOT . '/api/ai/goal_advisor.php'; exit;

        // ── t333: AI Portfolio Report Card ──
        case 'ai_report_card':
        case 'ai_report_card_history':
        require APP_ROOT . '/api/ai/report_card.php'; exit;

        // ── t382: AI Fund Research ──
        case 'ai_fund_research':
        require APP_ROOT . '/api/ai/fund_research.php'; exit;

        // ── t384: AI Anomaly Detector v2 ──
        case 'ai_anomaly_v2_scan':
        case 'ai_anomaly_v2_list':
        case 'ai_anomaly_v2_resolve':
        require APP_ROOT . '/api/ai/anomaly_detector_v2.php'; exit;

        // ── t385: AI Goal Coach ──
        case 'ai_goal_coach_nudges':
        case 'ai_goal_coach_dismiss':
        require APP_ROOT . '/api/ai/goal_coach.php'; exit;



// ════════════════════════════════════════════════════════
// Crypto Tools
// ════════════════════════════════════════════════════════

        // ── t40: CoinGecko Full Integration ──
        case 'coingecko_search':
        case 'coingecko_trending':
        case 'coingecko_top_coins':
        case 'coingecko_coin_detail':
        require APP_ROOT . '/api/crypto/crypto_add.php'; exit;

        // ── t42: Crypto Tax 30% Flat Calculator (use Jun-3 version — see note) ──
        // Use the NEWER file (tax_calculator.php, Jun 3) not the
        // OLDER duplicate (crypto_tax_calc.php, May 14 - different actions)
        case 'crypto_tax_calculate':
        case 'crypto_tax_save':
        case 'crypto_tax_load':
        case 'crypto_tax_delete':
        require APP_ROOT . '/api/crypto/tax_calculator.php'; exit;

        // ── tc003: DeFi & Staking Income Tracker ──
        case 'crypto_defi_list':
        case 'crypto_defi_add':
        case 'crypto_defi_edit':
        case 'crypto_defi_close':
        case 'crypto_defi_delete':
        case 'crypto_defi_summary':
        require APP_ROOT . '/api/crypto/crypto_defi.php'; exit;

        // ── tc004: Portfolio Rebalancing — Crypto ──
        case 'crypto_rebalance_targets':
        case 'crypto_rebalance_set':
        case 'crypto_rebalance_delete':
        case 'crypto_rebalance_suggest':
        require APP_ROOT . '/api/crypto/crypto_rebalance.php'; exit;

        // ── tc006: Cold Wallet Tracker ──
        case 'cold_wallet_list':
        case 'cold_wallet_add':
        case 'cold_wallet_update':
        case 'cold_wallet_delete':
        case 'cold_wallet_holdings_add':
        case 'cold_wallet_holdings_list':
        case 'cold_wallet_holdings_delete':
        case 'cold_wallet_summary':
        require APP_ROOT . '/api/crypto/cold_wallet.php'; exit;



// ════════════════════════════════════════════════════════
// Tax / Retirement / Goals
// ════════════════════════════════════════════════════════

        // ── t48: 80C Tracker ──
        case 'tax_80c_summary':
        case 'tax_80c_gap_analysis':
        case 'tax_80c_fy_detail':
        case 'tax_80c_history':
        case 'tax_80c_manual_save':
        case 'tax_80c_manual_delete':
        require APP_ROOT . '/api/reports/tax_80c_tracker.php'; exit;

        // ── tg003: Retirement Calculator ──
        case 'retirement_calculate':
        case 'retirement_save_plan':
        case 'retirement_load_plan':
        case 'retirement_delete_plan':
        require APP_ROOT . '/api/goal/retirement_calculator.php'; exit;

        // ── tg005: Goals vs Actual — goal_projection renamed to
        //          goal_checkin_projection (collided with the goal
        //          calculator's goal_projection, line ~801, which takes
        //          raw numbers vs this one which takes a saved goal_id)
        case 'goals_vs_actual':
        case 'goal_checkin_save':
        case 'goal_checkin_history':
        case 'goal_checkin_projection':
        require APP_ROOT . '/api/goal/goals_tracker.php'; exit;

        // ── tv09: Capital Gains Tax Preview ──
        case 'cg_live_preview':
        case 'cg_holding_detail':
        case 'cg_tax_if_sold':
        case 'cg_harvest_suggest':
        require APP_ROOT . '/api/tax/cg_live_preview.php'; exit;
        //

        // ── t358: Goal Notification Engine ──
        case 'goal_notifications_check':
        case 'goal_notifications_list':
        case 'goal_notification_dismiss':
        require APP_ROOT . '/api/goal_notifications.php'; exit;

        // ── t360: Life Events Calendar ──
        case 'life_events_list':
        case 'life_event_add':
        case 'life_event_update':
        case 'life_event_delete':
        require APP_ROOT . '/api/life_events.php'; exit;



// ════════════════════════════════════════════════════════
// EPF / Insurance / Property / Loans
// ════════════════════════════════════════════════════════

        // ── t46: EPF Monthly Tracker ──
        case 'epf_monthly_summary':
        case 'epf_monthly_entries':
        case 'epf_fy_contribution':
        case 'epf_salary_history':
        case 'epf_monthly_entry_save':
        case 'epf_monthly_entry_delete':
        case 'epf_salary_update':
        case 'epf_balance_sync':
        require APP_ROOT . '/api/epf/epf_monthly_tracker.php'; exit;
        //

        // ── t467: EPF Balance Tracker ──
        case 'epf_balance_summary':
        case 'epf_monthly_log_list':
        case 'epf_fy_summary':
        case 'epf_balance_history':
        case 'epf_contribution_calc':
        case 'epf_monthly_log_save':
        case 'epf_monthly_log_delete':
        case 'epf_fy_snapshot_save':
        case 'epf_balance_update':
        require APP_ROOT . '/api/epf/epf_balance_tracker.php'; exit;
        //

        // ── t469: EPF Tax Tracker ──
        case 'epf_tax_summary':
        case 'epf_tax_fy_detail':
        case 'epf_tax_projection':
        case 'epf_tax_log_list':
        case 'epf_tax_log_save':
        case 'epf_tax_log_delete':
        require APP_ROOT . '/api/epf/epf_tax_tracker.php'; exit;
        //

        // ── t470: EPF vs NPS vs PPF Comparison ──
        case 'epf_nps_ppf_current':
        case 'epf_nps_ppf_projector':
        case 'epf_nps_ppf_tax_compare':
        case 'epf_nps_ppf_liquidity':
        case 'epf_nps_ppf_full':
        require APP_ROOT . '/api/reports/epf_nps_ppf_compare.php'; exit;

        // ── t122: Insurance Portfolio — DUPLICATE BUILD found. insurance_list/
        //          add/delete/premium_calendar already exist above (line ~1222,
        //          api/insurance/insurance_list.php — that's the live one).
        //          Only genuinely NEW action kept here:
        case 'insurance_summary':
        require APP_ROOT . '/api/insurance/insurance.php'; exit;
        // NOTE: insurance_update has no non-colliding equivalent above
        //       (old version uses insurance_edit instead) — if you want
        //       the t122 version's update logic, rename this case to
        //       'insurance_update' and re-add it here manually after
        //       checking api/insurance/insurance.php's behavior matches.

        // ── t123: Loan Tracker — DUPLICATE BUILD found. loans_list already
        //          exists above (line ~1245, api/loan/loans_list.php — that's
        //          the live one). Only genuinely NEW actions kept here:
        case 'loan_add':
        case 'loan_update':
        case 'loan_delete':
        case 'loan_summary':
        case 'loan_amortization':
        require APP_ROOT . '/api/loans/loans.php'; exit;

        // ── t323: ULIP Tracker ──
        case 'ulip_list':
        case 'ulip_add':
        case 'ulip_update':
        case 'ulip_delete':
        case 'ulip_summary':
        require APP_ROOT . '/api/insurance/ulip.php'; exit;

        // ── t461: ULIP Tracker Full ──
        case 'ulip_fund_list':
        case 'ulip_fund_add':
        case 'ulip_fund_delete':
        case 'ulip_fund_history':
        case 'ulip_switch':
        case 'ulip_performance':
        require APP_ROOT . '/api/insurance/ulip_full.php'; exit;

        // ── t462: Premium Calendar ──
        case 'premium_calendar_get':
        case 'premium_mark_paid':
        case 'premium_history_list':
        case 'premium_payment_delete':
        require APP_ROOT . '/api/insurance/premium_calendar.php'; exit;

        // ── t463: Property Portfolio ──
        case 'property_list':
        case 'property_add':
        case 'property_update':
        case 'property_delete':
        case 'property_summary':
        case 'property_valuation_add':
        case 'property_valuation_history':
        require APP_ROOT . '/api/property/property.php'; exit;

        // ── t150: DigiLocker / Documents ──
        case 'doc_categories':
        case 'doc_list':
        case 'doc_upload':
        case 'doc_delete':
        case 'digilocker_connect_status':
        case 'digilocker_disconnect':
        require APP_ROOT . '/api/documents/digilocker.php'; exit;

        // ── t124: Real Estate / Rental Yield Calculator ──
        case 'rental_yield_calculate':
        require APP_ROOT . '/api/property/rental_yield_calculator.php'; exit;



// ════════════════════════════════════════════════════════
// Dashboard / UX / Personalization
// ════════════════════════════════════════════════════════

        // ── t55: Dashboard Widget Customizer ──
        case 'widget_layout_get':
        case 'widget_layout_save':
        case 'widget_layout_reset':
        case 'widget_data_get':
        require APP_ROOT . '/api/dashboard/widget_customizer.php'; exit;

        // ── t297: Customizable Dashboard ──
        case 'dashboard_layout_get':
        case 'dashboard_layout_save':
        case 'dashboard_layout_reset':
        require APP_ROOT . '/api/dashboard/dashboard_layout.php'; exit;

        // ── t350: Font Size Preference ──
        case 'font_size_get':
        case 'font_size_save':
        require APP_ROOT . '/api/security/font_preference.php'; exit;

        // ── t373: Widget Mode ──
        case 'widget_mode_render':
        case 'widget_mode_list':
        require APP_ROOT . '/api/dashboard/widget_mode.php'; exit;

        // ── t445: Customizable Overview Cards ──
        case 'overview_cards_get':
        case 'overview_cards_save':
        case 'overview_cards_reset':
        case 'overview_cards_data':
        require APP_ROOT . '/api/dashboard/overview_cards.php'; exit;

        // ── t446: Portfolio Health Heatmap ──
        case 'portfolio_health_heatmap':
        require APP_ROOT . '/api/portfolio/health_heatmap.php'; exit;

        // ── t242: Investor Streak & Milestones ──
        case 'streak_status':
        case 'streak_checkin':
        case 'milestones_list':
        case 'badges_list':
        require APP_ROOT . '/api/gamification/streaks.php'; exit;



// ════════════════════════════════════════════════════════
// Security & Onboarding
// ════════════════════════════════════════════════════════

        // ── t371: Biometric Login (WebAuthn) ──
        case 'webauthn_register_options':
        case 'webauthn_register_verify':
        case 'webauthn_login_options':
        case 'webauthn_login_verify':
        case 'webauthn_credentials_list':
        case 'webauthn_credential_delete':
        require APP_ROOT . '/api/webauthn.php'; exit;

        // ── t387: Session Security — DUPLICATE BUILD found. sessions_list/
        //          session_revoke/session_revoke_all already exist above
        //          (line ~1029, api/auth/session_security.php — that's the
        //          live one, marked [BUG-03 FIX]). Only genuinely NEW
        //          actions kept here:
        case 'session_touch':
        case 'session_current':
        require APP_ROOT . '/api/security/sessions.php'; exit;

        // ── t389: GDPR Data Controls ──
        case 'data_export_request':
        case 'data_export_list':
        case 'data_export_download':
        case 'data_delete_request':
        case 'data_delete_status':
        case 'data_delete_cancel':
        require APP_ROOT . '/api/security/data_controls.php'; exit;

        // ── t240: Onboarding Flow ──
        case 'onboarding_status':
        case 'onboarding_complete_step':
        case 'onboarding_skip':
        require APP_ROOT . '/api/onboarding/onboarding.php'; exit;

        // ── t454: Onboarding Setup Wizard ──
        case 'setup_wizard_status':
        case 'setup_wizard_save_step':
        case 'setup_wizard_complete':
        require APP_ROOT . '/api/onboarding/setup_wizard.php'; exit;



// ════════════════════════════════════════════════════════
// Budget & Reports & Sharing
// ════════════════════════════════════════════════════════

        // ── t471: Monthly Budget Tracker ──
        case 'budget_get':
        case 'budget_save':
        case 'budget_actual_list':
        case 'budget_actual_add':
        case 'budget_actual_update':
        case 'budget_actual_delete':
        case 'budget_summary':
        case 'budget_categories_list':
        require APP_ROOT . '/api/budget/budget.php'; exit;

        // ── t378: Report Sharing (WhatsApp/Email) ──
        case 'share_whatsapp_link':
        case 'share_email_send':
        case 'share_pdf_link':
        require APP_ROOT . '/api/sharing/report_share.php'; exit;

        // ── t485: Portfolio Treemap ──
        case 'portfolio_treemap':
        require APP_ROOT . '/api/portfolio/treemap.php'; exit;

        // ── t488: Yield Curve ──
        case 'yield_curve_get':
        case 'yield_curve_compare_banks':
        case 'yield_curve_save_rate':
        require APP_ROOT . '/api/portfolio/yield_curve.php'; exit;

        // ── t406: Anonymous Benchmarking — benchmark_compare renamed to
        //          peer_benchmark_compare (collided with tv11's mutual-fund
        //          benchmark_compare, line ~956 — different feature entirely)
        case 'benchmark_opt_in':
        case 'benchmark_opt_out':
        case 'benchmark_status':
        case 'peer_benchmark_compare':
        require APP_ROOT . '/api/benchmarking/peer_compare.php'; exit;

        // ── t503: Credit Card Optimizer — DUPLICATE BUILD found.
        //          cc_list/cc_add/cc_delete already exist above (line ~1065,
        //          api/mutual_funds/credit_card.php — that's the live one).
        //          Only the genuinely NEW actions from this session are kept:
        case 'cc_optimize_spend':
        case 'cc_interest_calculator':
        require APP_ROOT . '/api/budget/credit_card_optimizer.php'; exit;
        // NOTE: cc_update here behaves like cc_edit above — skipped as duplicate.
        //       api/budget/credit_card_optimizer.php is otherwise unused now;
        //       safe to archive once confirmed cc_optimize_spend/cc_interest_calculator
        //       don't depend on table setup unique to that file.

        // ── th001: Daily Financial Journal — renamed to avoid collision
        //          with t408 (Investment Journal) which already used
        //          journal_list/add/delete. Updated api/journal/journal.php
        //          internally to match these names too.
        case 'djournal_list':
        case 'djournal_add':
        case 'djournal_update':
        case 'djournal_delete':
        case 'djournal_search':
        case 'djournal_stats':
        case 'djournal_mood_chart':
        require APP_ROOT . '/api/journal/journal.php'; exit;

        // ── t443: Morning Briefing ──
        case 'morning_briefing_get':
        require APP_ROOT . '/api/dashboard/morning_briefing.php'; exit;

        // ── t106: NPS Bank Statement Import ──
        case 'nps_import_parse':
        case 'nps_import_staging_list':
        case 'nps_import_staging_update':
        case 'nps_import_staging_accept':
        case 'nps_import_staging_reject':
        case 'nps_import_confirm':
        case 'nps_import_sessions_list':
        case 'nps_import_schemes':
        case 'nps_import_session_delete':
        require APP_ROOT . '/api/nps/nps_import.php'; exit;

        // ── t155: Child Education Planner ──
        case 'edu_plan_calculate':
        case 'edu_plans_list':
        case 'edu_plan_save':
        case 'edu_plan_delete':
        require APP_ROOT . '/api/goal/education_planner.php'; exit;

        // ── t234: SIP vs Lumpsum Backtest ──
        case 'sip_lumpsum_backtest':
        $_GET['action'] = 'backtest';
        require APP_ROOT . '/api/mutual_funds/sip_lumpsum_backtest.php'; exit;
        case 'sip_lumpsum_backtest_rolling':
        $_GET['action'] = 'rolling_backtest';
        require APP_ROOT . '/api/mutual_funds/sip_lumpsum_backtest.php'; exit;
        case 'sip_lumpsum_best_entry':
        $_GET['action'] = 'best_entry';
        require APP_ROOT . '/api/mutual_funds/sip_lumpsum_backtest.php'; exit;
        case 'sip_lumpsum_summary':
        $_GET['action'] = 'summary';
        require APP_ROOT . '/api/mutual_funds/sip_lumpsum_backtest.php'; exit;

        // ── t404: Smart Alerts v2 ──
        case 'smart_alerts_check':
        case 'smart_alerts_list':
        case 'smart_alerts_unread_count':
        case 'smart_alert_dismiss':
        case 'smart_alert_settings_get':
        case 'smart_alert_settings_save':
        require APP_ROOT . '/api/alerts/smart_alerts.php'; exit;



// ════════════════════════════════════════════════════════
// Tools & Profile
// ════════════════════════════════════════════════════════

        // ── tp001: Cache Manager ──
        case 'cache_stats':
        case 'cache_flush_user':
        case 'cache_flush_all':
        require APP_ROOT . '/api/cache/cache_manager.php'; exit;

        // ── tp002: Lazy Loader ──
        case 'lazy_xirr':
        case 'lazy_portfolio_summary':
        case 'lazy_sip_analysis':
        case 'lazy_goal_progress':
        case 'lazy_tax_estimate':
        require APP_ROOT . '/api/tools/lazy_loader.php'; exit;

        // ── th002: SIP Discipline Score ──
        case 'sip_discipline_score':
        case 'sip_discipline_history':
        require APP_ROOT . '/api/tools/sip_discipline.php'; exit;

        // ── ti001: Personal Finance Profile ──
        case 'fp_get':
        case 'fp_save':
        require APP_ROOT . '/api/profile/finance_profile.php'; exit;

        // ── ti004: Personal Finance Score ──
        case 'finance_score':
        require APP_ROOT . '/api/profile/finance_score.php'; exit;

        // ── tj005: Annual Financial Review ──
        case 'annual_review_checklist':
        case 'annual_review_save':
        require APP_ROOT . '/api/tools/annual_review.php'; exit;

        // ── ID-W3 ORPHAN TASKS (t38,t39,t114,t116,t118,t121,t145,t432,
        //    t435,t436) — these were built as REST-style PHP classes,
        //    incompatible with this switch-case router. Bridged through
        //    api/rest_dispatch.php (see that file's header for details
        //    and the security fix applied to neutralise the user_id
        //    spoofing risk in the original classes).
        //    Frontend call pattern: apiPost({action:'gold_action',
        //    method:'getHoldings', ...}) — pass the class method name
        //    as a second 'method' param.
        case 'gold_action':         // t114: Gold Tracker
        case 'bonds_action':        // t116: Corporate Bonds/NCDs
        case 'rbi_sec_action':      // t118: RBI/G-Secs/T-Bills
        case 'watchlist_action':    // t435: Watchlist
        case 'stocks_tax_action':   // t39:  LTCG/STCG Tax Report
        case 'portfolio_pe_action': // t432: Portfolio P/E
        case 'stock_sip_action':    // t436: Stock SIP
        case 'reality_chk_action':  // t145: Reality Check
        case 'screener_action':     // t38:  Stocks Screener
        case 'intl_stocks_action':  // t121: International Stocks/LRS
        require APP_ROOT . '/api/rest_dispatch.php'; exit;

        // ════════════════════════════════════════════════════════
        // SECOND PASS ADDITIONS (20 Jun 2026) — found in HANDOFF.md
        // files that used a different note format than the rest,
        // missed in the first merge pass. Verified against router.php
        // before adding — none of these collide with existing cases.
        // ════════════════════════════════════════════════════════

        // ── t302: Groww CSV Import ──
        case 'groww_detect':
        case 'groww_import_csv':
        case 'groww_sessions':
        case 'groww_session_detail':
        case 'groww_fund_map_list':
        case 'groww_fund_map_save':
        case 'groww_import_status':
            require APP_ROOT . '/api/mutual_funds/groww_import.php'; exit;

        // ── t334: Bulk Import (50-field Excel) ──
        case 'bulk_template_fields':
        case 'bulk_template_download':
        case 'bulk_validate':
        case 'bulk_import':
        case 'bulk_session_list':
        case 'bulk_session_detail':
            require APP_ROOT . '/api/mutual_funds/bulk_import.php'; exit;

        // ── t392: Groww API Sync — run t302 migration FIRST
        //          (groww_fund_map table dependency) ──
        case 'groww_api_connect':
        case 'groww_api_status':
        case 'groww_api_disconnect':
        case 'groww_api_sync':
        case 'groww_api_sync_mf':
        case 'groww_api_sync_stocks':
        case 'groww_api_push_to_portfolio':
        case 'groww_api_sync_log':
        case 'groww_api_mapped_funds':
        case 'groww_api_map_fund':
            require APP_ROOT . '/api/mutual_funds/groww_api_sync.php'; exit;

        // ── t490: CSV Importer v3 Extensions
        //          (csv_v3_formats already merged earlier) ──
        case 'csv_v3_sessions':
        case 'csv_v3_session_detail':
        case 'csv_v3_preset_list':
        case 'csv_v3_preset_save':
        case 'csv_v3_preset_delete':
        case 'csv_v3_preset_apply':
        case 'csv_v3_save_session':
        case 'csv_v3_retry':
        case 'csv_v3_stats':
            require APP_ROOT . '/api/mutual_funds/csv_v3_ext.php'; exit;

        // ── t136: AIS / TIS Reconciliation ──
        case 'ais_list':
        case 'ais_summary':
        case 'ais_mismatch_report':
        case 'ais_entry_save':
        case 'ais_entry_delete':
        case 'ais_import_json':
        case 'ais_mark_feedback':
            require APP_ROOT . '/api/tax/ais_reconciliation.php'; exit;

        // ── t138: Indexation Benefit Calculator ──
        case 'indexation_calculate':
        case 'indexation_property_list':
        case 'indexation_property_save':
        case 'indexation_property_delete':
            require APP_ROOT . '/api/tax/indexation_calc.php'; exit;

        // ── t198: NPS Screener Enhanced ──
        case 'nps_screener':
            require APP_ROOT . '/api/nps/nps_screener.php'; exit;

        // ── t314: Monthly P&L Statement ──
        case 'monthly_pl':
        case 'monthly_pl_history':
        case 'monthly_pl_chart':
            require APP_ROOT . '/api/reports/monthly_pl.php'; exit;

        // ── t340: EPFO UAN Integration ──
        case 'uan_profile_get':
        case 'uan_summary':
        case 'uan_passbook_import':
        case 'uan_passbook_entry_save':
        case 'uan_profile_save':
            require APP_ROOT . '/api/epf/epfo_uan.php'; exit;

        // ── t422: Auto Sweep FD Tracker ──
        case 'sweep_list':
        case 'sweep_summary':
        case 'sweep_save':
        case 'sweep_delete':
        case 'sweep_mark_broken':
        case 'sweep_balance_update':
            require APP_ROOT . '/api/fd/auto_sweep.php'; exit;

        // ── t45: Recurring Deposit (RD) Tracker ──
        case 'rd_list':
        case 'rd_add':
        case 'rd_edit':
        case 'rd_delete':
        case 'rd_maturity':
        case 'rd_installment_log':
        case 'rd_installment_save':
            require APP_ROOT . '/api/rd/rd_tracker.php'; exit;

        // ── t49: Leave Encashment + LTA Tracker ──
        case 'lta_summary':
        case 'leave_summary':
        case 'leave_lta_fy':
        case 'lta_entry_save':
        case 'lta_entry_delete':
        case 'leave_entry_save':
        case 'leave_entry_delete':
            require APP_ROOT . '/api/tax/leave_lta.php'; exit;

        default:
            json_response(false, "Unknown action: {$action}", [], 400);
    }

} catch (Exception $e) {
    error_log('API error [' . $action . ']: ' . $e->getMessage());
    json_response(false, IS_LOCAL ? $e->getMessage() : 'An error occurred. Please try again.', [], 500);
}