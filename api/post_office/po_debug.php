<?php
/**
 * WealthDash — PO Debug File
 * Place at: wealthdash/api/post_office/po_debug.php
 * Visit: localhost/wealthdash/api/post_office/po_debug.php
 */
ob_start();

$results = [];

// Test 1: Basic PHP
$results[] = ['test' => 'PHP working', 'status' => 'OK', 'value' => phpversion()];

// Test 2: WEALTHDASH define
define('WEALTHDASH', true);
$results[] = ['test' => 'WEALTHDASH defined', 'status' => 'OK'];

// Test 3: Config path
$configPath = dirname(dirname(dirname(__FILE__))) . '/config/config.php';
$results[] = ['test' => 'Config path', 'status' => file_exists($configPath) ? 'OK' : 'FAIL', 'value' => $configPath];

// Test 4: Load config
if (file_exists($configPath)) {
    try {
        require_once $configPath;
        $results[] = ['test' => 'Config loaded', 'status' => 'OK'];
    } catch (Throwable $e) {
        $results[] = ['test' => 'Config load', 'status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    $results[] = ['test' => 'Config load', 'status' => 'SKIP - file not found'];
}

// Test 5: auth_check
$authPath = APP_ROOT . '/includes/auth_check.php';
$results[] = ['test' => 'auth_check path', 'status' => file_exists($authPath) ? 'OK' : 'FAIL', 'value' => $authPath];
if (file_exists($authPath)) {
    try {
        require_once $authPath;
        $results[] = ['test' => 'auth_check loaded', 'status' => 'OK'];
    } catch (Throwable $e) {
        $results[] = ['test' => 'auth_check load', 'status' => 'FAIL', 'error' => $e->getMessage()];
    }
}

// Test 6: helpers
$helpersPath = APP_ROOT . '/includes/helpers.php';
if (file_exists($helpersPath)) {
    try {
        require_once $helpersPath;
        $results[] = ['test' => 'helpers loaded', 'status' => 'OK'];
    } catch (Throwable $e) {
        $results[] = ['test' => 'helpers load', 'status' => 'FAIL', 'error' => $e->getMessage()];
    }
} else {
    $results[] = ['test' => 'helpers', 'status' => 'FAIL - not found', 'value' => $helpersPath];
}

// Test 7: DB connection
try {
    $db = DB::conn();
    $results[] = ['test' => 'DB connection', 'status' => 'OK'];
} catch (Throwable $e) {
    $results[] = ['test' => 'DB connection', 'status' => 'FAIL', 'error' => $e->getMessage()];
}

// Test 8: po_schemes table
try {
    DB::conn()->query("SELECT 1 FROM po_schemes LIMIT 1");
    $results[] = ['test' => 'po_schemes table', 'status' => 'OK - table exists'];
} catch (Throwable $e) {
    $results[] = ['test' => 'po_schemes table', 'status' => 'MISSING - ' . $e->getMessage()];
}

// Test 9: session
$results[] = ['test' => 'Session user_id', 'status' => isset($_SESSION['user_id']) ? 'OK' : 'NOT SET', 'value' => $_SESSION['user_id'] ?? 'null'];

// Test 10: is_logged_in
if (function_exists('is_logged_in')) {
    $results[] = ['test' => 'is_logged_in()', 'status' => is_logged_in() ? 'true (logged in)' : 'false (NOT logged in)'];
} else {
    $results[] = ['test' => 'is_logged_in()', 'status' => 'FUNCTION NOT FOUND'];
}

// Test 11: What ob_get_contents has so far (any leaked output?)
$leaked = ob_get_contents();
$results[] = ['test' => 'Leaked output before JSON', 'status' => strlen($leaked) === 0 ? 'OK - clean' : 'PROBLEM', 'leaked_bytes' => strlen($leaked), 'leaked_content' => substr($leaked, 0, 200)];

// Output results
ob_clean();
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['debug' => true, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
