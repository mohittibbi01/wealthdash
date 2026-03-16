<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
header('Content-Type: text/plain');

$db = DB::conn();

echo "=== STEP 1: portfolio_id in session = $portfolioId ===\n\n";

echo "=== STEP 2: What mf_list.php ACTUALLY returns for combined view ===\n";
$_GET['view'] = 'holdings';
$_GET['portfolio_id'] = $portfolioId;
// Temporarily rename the constant check
ob_start();
// Direct query instead
$stmt = $db->prepare("
    SELECT
        h.fund_id, f.scheme_name,
        SUM(h.total_invested) AS total_invested,
        (SELECT COUNT(*) FROM sip_schedules s
         WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
           AND s.is_active = 1 AND s.schedule_type = 'SIP') AS active_sip_count,
        (SELECT COUNT(*) FROM sip_schedules s
         WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
           AND s.is_active = 1 AND s.schedule_type = 'SWP') AS active_swp_count,
        (SELECT s.sip_amount FROM sip_schedules s
         WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
           AND s.is_active = 1 AND s.schedule_type = 'SIP'
         ORDER BY s.created_at DESC LIMIT 1) AS active_sip_amount,
        (SELECT s.frequency FROM sip_schedules s
         WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
           AND s.is_active = 1 AND s.schedule_type = 'SIP'
         ORDER BY s.created_at DESC LIMIT 1) AS active_sip_frequency
    FROM mf_holdings h
    JOIN funds f ON f.id = h.fund_id
    WHERE h.portfolio_id = ? AND h.is_active = 1
    GROUP BY h.fund_id
    ORDER BY SUM(h.total_invested) DESC
    LIMIT 5
");
$stmt->execute([$portfolioId]);
$rows = $stmt->fetchAll();
ob_end_clean();

foreach ($rows as $r) {
    echo "fund: {$r['scheme_name']}\n";
    echo "  active_sip_count = {$r['active_sip_count']}\n";
    echo "  active_swp_count = {$r['active_swp_count']}\n";
    echo "  active_sip_amount = {$r['active_sip_amount']}\n";
    echo "  active_sip_frequency = {$r['active_sip_frequency']}\n\n";
}

echo "=== STEP 3: Check mf_list.php file on disk ===\n";
$file = APP_ROOT . '/api/mutual_funds/mf_list.php';
$content = file_get_contents($file);
echo "File size: " . strlen($content) . " bytes\n";
echo "Has active_sip_count in combined view: " . (substr_count($content, 'active_sip_count') >= 3 ? "YES (".substr_count($content, 'active_sip_count')." times)" : "NO - ONLY ".substr_count($content, 'active_sip_count')." time(s)") . "\n";
echo "Has active_sip_amount in return array: " . (strpos($content, "'active_sip_amount'") !== false ? "YES" : "NO") . "\n";

// Find where active_sip_count appears
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'active_sip_count') !== false || strpos($line, 'active_sip_amount') !== false) {
        echo "  Line " . ($i+1) . ": " . trim($line) . "\n";
    }
}

echo "\n=== STEP 4: mf_list.php actual API output (curl simulation) ===\n";
$url = APP_URL . "/api/mutual_funds/mf_list.php?view=holdings&portfolio_id=$portfolioId";
echo "URL: $url\n";
$ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "Cookie: " . session_name() . "=" . session_id()]]);
$resp = @file_get_contents($url, false, $ctx);
if ($resp) {
    $json = json_decode($resp, true);
    $data = $json['data'] ?? [];
    echo "API returned " . count($data) . " holdings\n";
    foreach (array_slice($data, 0, 3) as $h) {
        echo "  {$h['scheme_name']}:\n";
        echo "    active_sip_count = " . ($h['active_sip_count'] ?? 'KEY MISSING') . "\n";
        echo "    active_sip_amount = " . ($h['active_sip_amount'] ?? 'KEY MISSING') . "\n";
    }
} else {
    echo "Could not fetch API\n";
}
