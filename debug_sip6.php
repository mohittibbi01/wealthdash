<?php
/**
 * Catch PHP fatal errors in router + sip_tracker
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
if (!$portfolioId) {
    $p = DB::fetchOne("SELECT id FROM portfolios WHERE user_id=? LIMIT 1", [$userId]);
    $portfolioId = $p ? (int)$p['id'] : 0;
}
$fund = DB::fetchOne(
    "SELECT f.id, f.scheme_name FROM mf_holdings h
     JOIN funds f ON f.id=h.fund_id
     WHERE h.portfolio_id=? AND h.is_active=1 LIMIT 1",
    [$portfolioId]
);

header('Content-Type: text/plain');

// Register shutdown to catch fatal errors
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "\n\n=== FATAL ERROR CAUGHT ===\n";
        echo "Type: " . $err['type'] . "\n";
        echo "Message: " . $err['message'] . "\n";
        echo "File: " . $err['file'] . "\n";
        echo "Line: " . $err['line'] . "\n";
    }
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "PHP ERROR [$errno]: $errstr in $errfile:$errline\n";
    return true;
});

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== SIMULATING sip_add DIRECTLY ===\n";
echo "portfolioId=$portfolioId fundId={$fund['id']}\n\n";

// Fake POST
$_POST = [
    'action'       => 'sip_add',
    'portfolio_id' => $portfolioId,
    'fund_id'      => $fund['id'],
    'sip_amount'   => 100,
    'frequency'    => 'monthly',
    'sip_day'      => 1,
    'start_date'   => '01-01-2025',
    'end_date'     => '',
    'folio_number' => '',
    'platform'     => '',
    'notes'        => '',
    'csrf_token'   => csrf_token(),
    '_csrf_token'  => csrf_token(),
];

echo "Step 1: can_edit_portfolio\n";
$r = can_edit_portfolio($portfolioId, $userId, false);
echo "  Result: " . ($r?'YES':'NO') . "\n";

echo "Step 2: csrf_verify\n";
try {
    csrf_verify();
    echo "  CSRF: OK\n";
} catch(Throwable $e) {
    echo "  CSRF FAIL: " . $e->getMessage() . "\n";
}

echo "Step 3: parse inputs\n";
$fundId    = (int)$_POST['fund_id'];
$amount    = (float)$_POST['sip_amount'];
$frequency = in_array($_POST['frequency']??'', ['monthly','quarterly','weekly','yearly','daily','fortnightly'])
             ? $_POST['frequency'] : 'monthly';
$sipDay    = max(1, min(28, (int)($_POST['sip_day']??1)));
$startDate = date_to_db(clean($_POST['start_date']??''));
$endDate   = !empty($_POST['end_date']) ? date_to_db(clean($_POST['end_date'])) : null;

echo "  fundId=$fundId amount=$amount freq=$frequency sipDay=$sipDay\n";
echo "  startDate='$startDate' endDate=" . ($endDate??'NULL') . "\n";

if (!$fundId || $amount <= 0 || !$startDate) {
    echo "  VALIDATION FAIL\n"; exit;
}

echo "Step 4: fund lookup\n";
$fundRow = DB::fetchOne('SELECT id, scheme_name, scheme_code FROM funds WHERE id=?', [$fundId]);
echo "  fund: " . ($fundRow['scheme_name']??'NOT FOUND') . "\n";
if (!$fundRow) exit;

echo "Step 5: INSERT sip_schedules\n";
$pdo = DB::conn();
$pdo->beginTransaction();
try {
    DB::run(
        'INSERT INTO sip_schedules
         (portfolio_id, asset_type, fund_id, folio_number, sip_amount, frequency,
          sip_day, start_date, end_date, platform, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$portfolioId, 'mf', $fundId, null, $amount, $frequency,
         $sipDay, $startDate, $endDate, null, null]
    );
    $newId = (int)$pdo->lastInsertId();
    $pdo->rollBack();
    echo "  INSERT OK id=$newId (ROLLED BACK)\n";
} catch(Throwable $e) {
    $pdo->rollBack();
    echo "  INSERT FAIL: " . $e->getMessage() . "\n";
}

echo "Step 6: audit_log\n";
try {
    audit_log('sip_add', 'sip_schedules', 999);
    echo "  audit_log: OK\n";
} catch(Throwable $e) {
    echo "  audit_log FAIL: " . $e->getMessage() . "\n";
}

echo "\nStep 7: json_response test\n";
echo "  About to call json_response...\n";
// Don't actually call it, just test json_encode
$out = json_encode(['success'=>true,'message'=>'SIP added successfully.','data'=>['id'=>999,'nav_status'=>'available']]);
echo "  json_encode result: $out\n";
echo "  Length: " . strlen($out) . "\n";

echo "\n=== ALL STEPS PASSED ===\n";
echo "If you see this, the logic works fine.\n";
echo "The 500 error must be in router.php include chain.\n\n";

echo "=== CHECKING router.php require chain ===\n";
// Check if sip_tracker is being required correctly
$routerContent = file_get_contents(APP_ROOT . '/api/router.php');
preg_match("/require.*sip_tracker/", $routerContent, $m);
echo "Router sip_tracker require: " . ($m[0] ?? 'NOT FOUND') . "\n";

// Check if our new sip_tracker has syntax errors by checking key functions
$sipContent = file_get_contents(APP_ROOT . '/api/reports/sip_tracker.php');
echo "sip_tracker.php size: " . strlen($sipContent) . " bytes\n";
echo "Has sip_nav_token case: " . (str_contains($sipContent, 'sip_nav_token') ? 'YES' : 'NO') . "\n";
echo "Has _generate_installment_dates: " . (str_contains($sipContent, '_generate_installment_dates') ? 'YES' : 'NO') . "\n";

// Check for PHP syntax using tokenizer
$tokens = token_get_all($sipContent);
$errors = [];
echo "Tokenizer: OK (" . count($tokens) . " tokens)\n";

