<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
if (!$portfolioId) {
    $p = DB::fetchOne("SELECT id FROM portfolios WHERE user_id=? LIMIT 1", [$userId]);
    $portfolioId = $p ? (int)$p['id'] : 0;
    $_SESSION['selected_portfolio_id'] = $portfolioId;
}

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
    'platform'     => 'Test',
    'notes'        => 'debug test - will delete',
    'csrf_token'   => csrf_token(),
    '_csrf_token'  => csrf_token(),
];

ob_start();
try {
    require APP_ROOT . '/api/reports/sip_tracker.php';
} catch (Throwable $e) {
    $out = ob_get_clean();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit;
}
$out = ob_get_clean();
echo $out;

// Clean up test SIP
$decoded = json_decode($out, true);
if (!empty($decoded['success']) && !empty($decoded['data']['id'])) {
    DB::run("DELETE FROM sip_schedules WHERE id=? AND notes='debug test - will delete'", [$decoded['data']['id']]);
    echo "\n(test SIP id={$decoded['data']['id']} deleted)";
}
