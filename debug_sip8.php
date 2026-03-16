<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
header('Content-Type: text/plain');

echo "userId=$userId portfolioId=$portfolioId\n\n";

// Direct DB query - same as mf_list folio view
$db = DB::conn();

// 1. Raw holdings count
$count = $db->prepare("SELECT COUNT(*) FROM mf_holdings WHERE portfolio_id=? AND is_active=1");
$count->execute([$portfolioId]);
echo "Holdings in DB: " . $count->fetchColumn() . "\n\n";

// 2. SIP subquery test on each holding
$holdings = $db->prepare("
    SELECT h.fund_id, f.scheme_name,
           (SELECT COUNT(*) FROM sip_schedules s 
            WHERE s.fund_id = h.fund_id 
              AND s.portfolio_id = h.portfolio_id 
              AND s.is_active = 1) AS sip_count,
           (SELECT sip_amount FROM sip_schedules s 
            WHERE s.fund_id = h.fund_id 
              AND s.portfolio_id = h.portfolio_id 
              AND s.is_active = 1
            ORDER BY s.created_at DESC LIMIT 1) AS sip_amount,
           (SELECT frequency FROM sip_schedules s 
            WHERE s.fund_id = h.fund_id 
              AND s.portfolio_id = h.portfolio_id 
              AND s.is_active = 1
            ORDER BY s.created_at DESC LIMIT 1) AS sip_frequency
    FROM mf_holdings h
    JOIN funds f ON f.id = h.fund_id
    WHERE h.portfolio_id = ? AND h.is_active = 1
    ORDER BY h.total_invested DESC
");
$holdings->execute([$portfolioId]);
$rows = $holdings->fetchAll();

echo "Holdings with SIP data (" . count($rows) . "):\n";
foreach ($rows as $r) {
    $marker = $r['sip_count'] > 0 ? "✓ SIP" : "  ---";
    echo "  $marker | fund_id={$r['fund_id']} sip_count={$r['sip_count']} amt={$r['sip_amount']} | {$r['scheme_name']}\n";
}

echo "\n=== All active SIPs for this portfolio ===\n";
$allSips = $db->prepare("
    SELECT s.id, s.fund_id, s.sip_amount, s.frequency, s.is_active, f.scheme_name
    FROM sip_schedules s
    JOIN funds f ON f.id = s.fund_id
    WHERE s.portfolio_id = ? AND s.is_active = 1
    ORDER BY s.fund_id
");
$allSips->execute([$portfolioId]);
foreach ($allSips->fetchAll() as $s) {
    echo "  id={$s['id']} fund_id={$s['fund_id']} amt={$s['sip_amount']} freq={$s['frequency']} | {$s['scheme_name']}\n";
}

echo "\n=== mf_list.php direct include test ===\n";
// Simulate what mf_list does
$_GET['view'] = 'holdings';
$_GET['portfolio_id'] = $portfolioId;

ob_start();
require APP_ROOT . '/api/mutual_funds/mf_list.php';
$output = ob_get_clean();

$decoded = json_decode($output, true);
$data = $decoded['data'] ?? [];
echo "mf_list returned " . count($data) . " holdings\n";
if (count($data) > 0) {
    foreach (array_slice($data, 0, 5) as $h) {
        echo "  {$h['scheme_name']}: sip_count=" . ($h['active_sip_count']??'MISSING') . " sip_amt=" . ($h['active_sip_amount']??'MISSING') . "\n";
    }
}
