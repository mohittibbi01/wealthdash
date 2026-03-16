<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
header('Content-Type: text/plain');

// Check what mf_list returns for holdings view
$ch = curl_init(APP_URL . '/api/mutual_funds/mf_list.php?view=holdings&portfolio_id=' . $portfolioId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIE => 'PHPSESSID=' . session_id(),
    CURLOPT_TIMEOUT => 10,
]);
$raw = curl_exec($ch);
curl_close($ch);

$d = json_decode($raw, true);
$holdings = $d['data'] ?? [];

echo "Total holdings: " . count($holdings) . "\n\n";
foreach ($holdings as $h) {
    if (($h['active_sip_count'] ?? 0) > 0) {
        echo "✓ SIP: {$h['scheme_name']}\n";
        echo "  sip_count={$h['active_sip_count']} sip_amount={$h['active_sip_amount']} freq={$h['active_sip_frequency']}\n";
    }
}
echo "\n--- Funds WITHOUT active SIP ---\n";
foreach ($holdings as $h) {
    if (($h['active_sip_count'] ?? 0) == 0) {
        echo "  {$h['scheme_name']}\n";
    }
}

echo "\n=== report_sip sip_list API ===\n";
$ch2 = curl_init(APP_URL . '/api/router.php');
curl_setopt_array($ch2, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => json_encode(['action'=>'sip_list','portfolio_id'=>$portfolioId,'csrf_token'=>csrf_token()]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: '.csrf_token(), 'X-Requested-With: XMLHttpRequest'],
    CURLOPT_COOKIE => 'PHPSESSID=' . session_id(),
    CURLOPT_TIMEOUT => 10,
]);
$raw2 = curl_exec($ch2);
curl_close($ch2);
$d2 = json_decode($raw2, true);
$sips = $d2['data']['sips'] ?? [];
echo "SIPs returned by sip_list: " . count($sips) . "\n";
foreach ($sips as $s) {
    echo "  id={$s['id']} fund={$s['fund_name']} amt={$s['sip_amount']} active={$s['is_active']}\n";
}
