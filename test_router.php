<?php
// Simulate exactly what router.php does, with full error reporting
define('WEALTHDASH', true);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once dirname(__FILE__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

header('Content-Type: application/json');

$errors = [];

// Step 1: Check session
$errors['step1_session_ok'] = isset($_SESSION['user_id']);
$errors['user_id'] = $_SESSION['user_id'] ?? null;
$errors['role'] = $_SESSION['role'] ?? null;
$errors['is_logged_in'] = is_logged_in();
$errors['is_admin'] = is_admin();

// Step 2: Try to require users.php like router does
$_POST['action'] = 'admin_stats';
try {
    ob_start();
    require APP_ROOT . '/api/admin/users.php';
    $output = ob_get_clean();
    $errors['users_php_output'] = $output;
    $errors['users_php_ok'] = true;
} catch (Throwable $e) {
    ob_end_clean();
    $errors['users_php_error'] = $e->getMessage();
    $errors['users_php_file']  = $e->getFile();
    $errors['users_php_line']  = $e->getLine();
}

echo json_encode($errors, JSON_PRETTY_PRINT);
