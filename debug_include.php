<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$currentUser = require_auth();
$_SESSION['user_id'] = $currentUser['id'];

echo "Testing sip_tracker.php include...\n";

// Set up what router.php sets up
$userId = (int)$_SESSION['user_id'];
$isAdmin = is_admin();
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
if (!$portfolioId) {
    $p = \DB::fetchOne("SELECT id FROM portfolios WHERE user_id=? LIMIT 1", [$userId]);
    $portfolioId = $p ? (int)$p['id'] : 0;
    $_SESSION['selected_portfolio_id'] = $portfolioId;
}

// Fake a valid sip_add POST
$_POST = [
    'action'       => 'sip_add',
    'portfolio_id' => $portfolioId,
    'fund_id'      => 3393,
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

echo "Setup done. portfolioId=$portfolioId\n";
echo "Including sip_tracker.php now...\n\n";

// Capture output
ob_start();
try {
    require APP_ROOT . '/api/reports/sip_tracker.php';
} catch (Throwable $e) {
    $out = ob_get_clean();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    echo "Output before exception:\n" . $out . "\n";
    exit;
}
$out = ob_get_clean();
echo "Output from sip_tracker:\n";
echo $out;
echo "\nDone.\n";
