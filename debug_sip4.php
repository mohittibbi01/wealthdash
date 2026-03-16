<?php
/**
 * Final direct HTTP test - captures exact raw response
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

// Get a fund from holdings
$fund = DB::fetchOne(
    "SELECT f.id FROM mf_holdings h JOIN funds f ON f.id=h.fund_id
     WHERE h.portfolio_id=? AND h.is_active=1 LIMIT 1", [$portfolioId]
);

// Make exact same request as browser - JSON body, exact same headers
$postData = json_encode([
    'action'       => 'sip_add',
    'portfolio_id' => $portfolioId,
    'fund_id'      => $fund['id'] ?? 1,
    'sip_amount'   => 100,
    'frequency'    => 'monthly',
    'sip_day'      => 1,
    'start_date'   => '01-01-2025',
    'end_date'     => '',
    'folio_number' => '',
    'platform'     => '',
    'notes'        => '',
    'csrf_token'   => csrf_token(),
]);

$ch = curl_init(APP_URL . '/api/router.php');
curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_POSTFIELDS      => $postData,
    CURLOPT_HTTPHEADER      => [
        'Content-Type: application/json',
        'X-CSRF-Token: ' . csrf_token(),
        'X-Requested-With: XMLHttpRequest',
        'Cookie: PHPSESSID=' . session_id(),
    ],
    CURLOPT_SSL_VERIFYPEER  => false,
    CURLOPT_TIMEOUT         => 15,
    CURLOPT_VERBOSE         => false,
    CURLOPT_FOLLOWLOCATION  => true,
]);

$raw     = curl_exec($ch);
$code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr    = curl_error($ch);
curl_close($ch);

header('Content-Type: text/plain');
echo "HTTP: $code\n";
echo "cURL error: " . ($cerr ?: 'none') . "\n";
echo "Response length: " . strlen($raw ?: '') . "\n";
echo "---RAW---\n";
echo $raw;
echo "\n---HEX first 100---\n";
for ($i=0; $i<min(100,strlen($raw??'')); $i++) {
    $c = $raw[$i];
    $o = ord($c);
    echo ($o<32||$o>126) ? "[".sprintf('%02X',$o)."]" : $c;
}
echo "\n---JSON PARSE---\n";
$d = @json_decode($raw, true);
echo $d ? "VALID: " . json_encode($d) : "INVALID: " . json_last_error_msg();
