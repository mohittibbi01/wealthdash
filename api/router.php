<?php
/**
 * WealthDash — Central API Router
 * All AJAX/POST requests go through here
 * Handles: portfolio CRUD, theme update, portfolio switch
 */
ob_start(); // Buffer all output — prevents PHP warnings from corrupting JSON
define('WEALTHDASH', true);
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
    'admin_settings_get', 'admin_audit_log', 'admin_db_list', 'admin_db_status',
    'admin_fund_rules_search', 'admin_fund_rules_get', 'admin_fund_rules_categories',
    'admin_import_ter',
    'admin_import_exit_load',
    'get_portfolio_summary', 'get_dashboard_data',
    'fd_list', 'fd_add', 'fd_delete', 'fd_mature', 'fd_maturity',
    'stocks_list', 'stocks_get',
    'nps_list', 'nps_nav_history',
    'savings_list',
    'po_list', 'po_meta',
    'goal_list', 'goal_projection',
    'sip_list', 'sip_analysis', 'sip_upcoming', 'sip_monthly_chart',
    'sip_xirr', 'sip_nav_status', 'sip_nav_token', 'sip_sync_txns',
    'indexes_fetch',
    'report_fy_gains',
    'nps_statement',
    'admin_nps_nav_trigger',
    'fund_notes_get',
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
            require APP_ROOT . '/api/mutual_funds/fund_managers.php'; exit;
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
        case 'report_tax_planning':
            require APP_ROOT . '/api/reports/tax_planning.php'; exit;
        case 'report_net_worth':
            require APP_ROOT . '/api/reports/net_worth.php'; exit;
        case 'nw_timeline':        // t207: Net Worth Timeline fetch
        case 'nw_snapshot_save':   // t207: Save monthly snapshot
            require APP_ROOT . '/api/reports/net_worth_timeline.php'; exit;
        case 'report_rebalancing':
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

        // ── Phase 5: Goal Planning ───────────────────────────
        case 'goal_list':
        case 'goal_add':
        case 'goal_edit':
        case 'goal_delete':
        case 'goal_mark_achieved':
        case 'goal_contribute':
        case 'notif_list':           // t57/t81 — Notifications Center
        case 'notif_unread_count':
        case 'notif_mark_read':
        case 'notif_mark_all_read':
        case 'notif_clear_all':
        case 'notif_prefs_get':
        case 'notif_prefs_save':
            require APP_ROOT . '/api/notifications.php'; exit;

        case 'goal_projection':
            require APP_ROOT . '/api/reports/goal_planning.php'; exit;

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

        default:
            json_response(false, "Unknown action: {$action}", [], 400);
    }

} catch (Exception $e) {
    error_log('API error [' . $action . ']: ' . $e->getMessage());
    json_response(false, IS_LOCAL ? $e->getMessage() : 'An error occurred. Please try again.', [], 500);
}