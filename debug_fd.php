<?php
define('WEALTHDASH', true);
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once dirname(__FILE__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
header('Content-Type: application/json');

$out = [];

// 1. Session
$out['session_user_id']   = $_SESSION['user_id'] ?? 'NOT SET';
$out['session_role']      = $_SESSION['role']    ?? 'NOT SET';
$out['is_logged_in']      = is_logged_in();
$out['is_admin']          = is_admin();

// 2. fmt_date exists?
$out['fmt_date_exists']   = function_exists('fmt_date')   ? 'YES' : 'NO';
$out['inr_exists']        = function_exists('inr')        ? 'YES' : 'NO';
$out['date_display_exists']= function_exists('date_display')? 'YES': 'NO';

// 3. DB direct FD query
try {
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $rows = DB::fetchAll("SELECT fa.id, fa.bank_name, fa.principal, fa.status, p.name as portfolio_name
        FROM fd_accounts fa
        JOIN portfolios p ON p.id = fa.portfolio_id
        WHERE p.user_id = ?
        LIMIT 5", [$userId]);
    $out['fd_rows_for_user'] = $rows;
    $out['fd_count'] = count($rows);

    $total = DB::fetchVal("SELECT COUNT(*) FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id WHERE p.user_id=?", [$userId]);
    $out['fd_total_user'] = $total;

    $allFd = DB::fetchVal("SELECT COUNT(*) FROM fd_accounts");
    $out['fd_total_all'] = $allFd;
} catch(Throwable $e) {
    $out['db_error'] = $e->getMessage();
}

// 4. Simulate fd_list API call
$out['router_path'] = APP_ROOT . '/api/router.php';
$out['fd_list_path'] = APP_ROOT . '/api/fd/fd_list.php';
$out['fd_list_exists'] = file_exists(APP_ROOT . '/api/fd/fd_list.php') ? 'YES' : 'NO';

// 5. Try including fd_list directly
if (isset($_SESSION['user_id'])) {
    $userId  = (int)$_SESSION['user_id'];
    $isAdmin = is_admin();
    $_GET['action'] = 'fd_list';
    try {
        ob_start();
        include APP_ROOT . '/api/fd/fd_list.php';
        $fdListOutput = ob_get_clean();
        $out['fd_list_raw_output'] = $fdListOutput;
        $out['fd_list_parsed']     = json_decode($fdListOutput, true);
    } catch(Throwable $e) {
        ob_end_clean();
        $out['fd_list_error'] = $e->getMessage();
        $out['fd_list_line']  = $e->getLine();
        $out['fd_list_file']  = $e->getFile();
    }
}

// 6. Check fd.php for PHP errors
try {
    $check = file_get_contents(APP_ROOT . '/templates/pages/fd.php');
    $out['fd_php_size'] = strlen($check) . ' bytes';
} catch(Throwable $e) {
    $out['fd_php_error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
