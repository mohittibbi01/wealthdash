<?php
/**
 * WealthDash — Central API Router
 * Defines WEALTHDASH, loads config, parses JSON body, then routes to correct handler.
 */

define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

// Must be logged in for all API calls
if (!is_logged_in()) {
    json_response(false, 'Not authenticated.', [], 401);
}

// ── Merge JSON body into $_POST ───────────────────────────────
// fetch() with Content-Type: application/json sends body as JSON, not form-encoded
$_contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (str_contains($_contentType, 'application/json')) {
    $jsonBody = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonBody)) {
        $_POST = array_merge($_POST, $jsonBody);
    }
}

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$userId  = (int) $_SESSION['user_id'];
$isAdmin = is_admin();

// ── 1. Simple inline actions ──────────────────────────────────

if ($action === 'update_theme') {
    $theme = clean($_POST['theme'] ?? 'light');
    $_SESSION['theme'] = in_array($theme, ['light', 'dark']) ? $theme : 'light';
    json_response(true, 'Theme updated.', ['theme' => $_SESSION['theme']]);
}

if ($action === 'switch_portfolio') {
    $pid = (int)($_POST['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, $isAdmin)) {
        $_SESSION['selected_portfolio_id'] = $pid;
        json_response(true, 'Portfolio switched.', ['portfolio_id' => $pid]);
    }
    json_response(false, 'Invalid portfolio.');
}

if ($action === 'create_portfolio') {
    csrf_verify();
    $name = clean($_POST['name'] ?? '');
    if (!$name) json_response(false, 'Portfolio name required.');
    DB::run('INSERT INTO portfolios (user_id, name) VALUES (?, ?)', [$userId, $name]);
    $newId = (int) DB::conn()->lastInsertId();
    $_SESSION['selected_portfolio_id'] = $newId;
    audit_log('create_portfolio', 'portfolios', $newId);
    json_response(true, 'Portfolio created.', ['id' => $newId, 'name' => $name]);
}

// ── 2. SIP / SWP actions ─────────────────────────────────────
if (str_starts_with($action, 'sip_') || str_starts_with($action, 'swp_')) {
    require_once APP_ROOT . '/api/reports/sip_tracker.php';
    exit;
}

// ── 3. Goal planning actions ──────────────────────────────────
if (str_starts_with($action, 'goal_')) {
    require_once APP_ROOT . '/api/reports/goal_planning.php';
    exit;
}

// ── 4. Admin actions ──────────────────────────────────────────
if (str_starts_with($action, 'admin_')) {
    if ($action === 'admin_recalc_holdings') {
        require_once APP_ROOT . '/recalc_holdings.php';
        exit;
    }
    if (in_array($action, [
        'admin_settings_get', 'admin_settings_save',
        'admin_audit_log',
        'admin_add_portfolio_member', 'admin_remove_portfolio_member',
    ])) {
        require_once APP_ROOT . '/api/admin/settings.php';
        exit;
    }
    if (str_starts_with($action, 'admin_db_')) {
        require_once APP_ROOT . '/api/admin/db_manage.php';
        exit;
    }
    require_once APP_ROOT . '/api/admin/users.php';
    exit;
}

// ── 5. NPS actions ────────────────────────────────────────────
if ($action === 'nps_list') { require_once APP_ROOT . '/api/nps/nps_list.php'; exit; }
if ($action === 'nps_add')  { require_once APP_ROOT . '/api/nps/nps_add.php';  exit; }
if ($action === 'nps_delete')     { require_once APP_ROOT . '/api/nps/nps_delete.php';     exit; }
if ($action === 'nps_nav_update') { require_once APP_ROOT . '/api/nps/nps_nav_update.php'; exit; }

// ── 6. Savings actions ────────────────────────────────────────
if ($action === 'savings_list')           { require_once APP_ROOT . '/api/savings/savings_list.php';   exit; }
if ($action === 'savings_delete')         { require_once APP_ROOT . '/api/savings/savings_delete.php'; exit; }
if (str_starts_with($action, 'savings_')) { require_once APP_ROOT . '/api/savings/savings_add.php';    exit; }

// ── 7. FD actions ─────────────────────────────────────────────
if ($action === 'fd_list')   { require_once APP_ROOT . '/api/fd/fd_list.php';   exit; }
if ($action === 'fd_add')    { require_once APP_ROOT . '/api/fd/fd_add.php';    exit; }
if ($action === 'fd_delete') { require_once APP_ROOT . '/api/fd/fd_delete.php'; exit; }
if ($action === 'fd_mature') { require_once APP_ROOT . '/api/fd/fd_mature.php'; exit; }

// ── 8. Stocks actions ─────────────────────────────────────────
if ($action === 'stocks_list')           { require_once APP_ROOT . '/api/stocks/stocks_list.php';           exit; }
if ($action === 'stocks_add')            { require_once APP_ROOT . '/api/stocks/stocks_add.php';            exit; }
if ($action === 'stocks_delete')         { require_once APP_ROOT . '/api/stocks/stocks_delete.php';         exit; }
if ($action === 'stocks_refresh_prices') { require_once APP_ROOT . '/api/stocks/stocks_refresh_prices.php'; exit; }
if ($action === 'stocks_search')         { require_once APP_ROOT . '/api/stocks/stocks_search.php';         exit; }
if ($action === 'stocks_price')          { require_once APP_ROOT . '/api/stocks/stocks_price.php';          exit; }

// ── 9. Report actions ─────────────────────────────────────────
if ($action === 'report_fy_gains')     { require_once APP_ROOT . '/api/reports/fy_gains.php';     exit; }
if ($action === 'report_net_worth')    { require_once APP_ROOT . '/api/reports/net_worth.php';    exit; }
if ($action === 'report_tax_planning') { require_once APP_ROOT . '/api/reports/tax_planning.php'; exit; }
if ($action === 'report_rebalancing')  { require_once APP_ROOT . '/api/reports/rebalancing.php';  exit; }

if (in_array($action, ['export_mf_csv', 'export_holdings_csv', 'export_tax_report_csv'])) {
    require_once APP_ROOT . '/api/reports/export_csv.php';
    exit;
}

// ── Unknown action ────────────────────────────────────────────
json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);