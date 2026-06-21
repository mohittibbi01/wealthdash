<?php
/**
 * WealthDash — Download Import Result CSV
 * GET /api/mutual_funds/download_import_result.php?token=xxx
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

require_auth();

$token = $_GET['token'] ?? '';
if (!$token) { http_response_code(400); die('Missing token'); }

$key  = 'import_result_' . $token;
$data = $_SESSION[$key] ?? null;

if (!$data || (isset($data['expires']) && $data['expires'] < time())) {
    http_response_code(404);
    die('Download link expired or not found. Please import again.');
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . ($data['filename'] ?? 'import_result.csv') . '"');
header('Content-Length: ' . strlen($data['csv']));
header('Cache-Control: no-cache');

echo $data['csv'];

// Clean up
unset($_SESSION[$key]);
