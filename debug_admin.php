<?php
define('WEALTHDASH', true);
require_once dirname(__FILE__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

header('Content-Type: application/json');

// Test 1: Session
$out = [];
$out['session_user_id'] = $_SESSION['user_id'] ?? 'NOT SET';
$out['session_role']    = $_SESSION['role'] ?? 'NOT SET';
$out['is_admin']        = is_admin() ? 'YES' : 'NO';
$out['is_logged_in']    = is_logged_in() ? 'YES' : 'NO';

// Test 2: CSRF
$token_in_session = $_SESSION['_csrf_token'] ?? 'NOT SET';
$token_in_meta    = 'check browser source'; 
$out['csrf_session_token_length'] = strlen($token_in_session);

// Test 3: Direct stats query
try {
    $out['db_connected'] = 'YES';
    $out['users_count']  = (int) DB::fetchVal("SELECT COUNT(*) FROM users WHERE status='active'");
    $out['funds_count']  = (int) DB::fetchVal("SELECT COUNT(*) FROM funds");
    $out['mf_holdings']  = (int) DB::fetchVal("SELECT COUNT(*) FROM mf_holdings WHERE is_active=1");
} catch (Exception $e) {
    $out['db_error'] = $e->getMessage();
}

// Test 4: Simulate what router does with CSRF
$_rawBody = file_get_contents('php://input');
$out['raw_body']     = $_rawBody ?: '(empty)';
$out['post_action']  = $_POST['action'] ?? 'NOT SET';
$out['post_csrf']    = $_POST['csrf_token'] ?? 'NOT SET';
$out['header_csrf']  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 'NOT SET';

// Test 5: Manual CSRF check
$submitted = $_POST['_csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$expected  = $_SESSION['_csrf_token'] ?? '';
$out['csrf_submitted_length'] = strlen($submitted);
$out['csrf_expected_length']  = strlen($expected);
$out['csrf_match']            = ($expected && hash_equals($expected, $submitted)) ? 'MATCH' : 'MISMATCH';

echo json_encode($out, JSON_PRETTY_PRINT);
