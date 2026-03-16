<?php
/**
 * Drop at: wealthdash/debug_live_test.php
 * Visit: localhost/wealthdash/debug_live_test.php
 * Tests the actual API responses live
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html>
<head><title>Live API Test</title>
<style>
body{font-family:monospace;max-width:1000px;margin:30px auto;padding:0 20px;background:#f8fafc;}
.ok{color:#15803d;font-weight:700;} .err{color:#dc2626;font-weight:700;} .warn{color:#d97706;}
pre{background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;font-size:12px;overflow-x:auto;white-space:pre-wrap;}
h2{background:#f1f5f9;padding:8px 12px;border-left:4px solid #2563eb;margin:24px 0 8px;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{background:#334155;color:#fff;padding:6px 8px;text-align:left;}
td{padding:4px 8px;border-bottom:1px solid #e2e8f0;}
.badge-g{background:#dcfce7;color:#15803d;padding:1px 7px;border-radius:9px;font-size:11px;font-weight:700;}
.badge-r{background:#fee2e2;color:#dc2626;padding:1px 7px;border-radius:9px;font-size:11px;font-weight:700;}
.badge-b{background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:9px;font-size:11px;font-weight:700;}
</style>
</head>
<body>
<h1>🔬 WealthDash Live API Test</h1>
<div>portfolioId=<b><?= $portfolioId ?></b> | userId=<b><?= $userId ?></b></div>

<?php
$db = DB::conn();

// ── TEST 1: sip_list response ─────────────────────────────────
echo "<h2>Test 1: sip_list API Response (what report_sip.php shows)</h2>";
$sips = $db->prepare("
    SELECT s.*, f.scheme_name AS fund_name, f.category AS fund_category, 
           fh.name AS fund_house, f.latest_nav
    FROM sip_schedules s
    LEFT JOIN funds f ON f.id = s.fund_id
    LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
    WHERE s.portfolio_id = ?
    ORDER BY s.is_active DESC, s.start_date DESC
");
$sips->execute([$portfolioId]);
$sipRows = $sips->fetchAll();

if (empty($sipRows)) {
    echo "<div class='err'>❌ No SIPs returned for portfolio_id=$portfolioId</div>";
    
    // Check all portfolios
    $allSips = $db->query("SELECT portfolio_id, COUNT(*) as cnt FROM sip_schedules GROUP BY portfolio_id")->fetchAll();
    echo "<div class='warn'>SIPs by portfolio_id in DB:</div><pre>";
    foreach ($allSips as $r) echo "portfolio_id={$r['portfolio_id']} → {$r['cnt']} SIPs\n";
    echo "</pre>";
} else {
    echo "<div class='ok'>✅ " . count($sipRows) . " SIP(s) found</div>";
    echo "<table><tr><th>id</th><th>Type</th><th>Fund</th><th>Amount</th><th>Freq</th><th>Start</th><th>Active</th><th>schedule_type</th><th>notes</th></tr>";
    foreach ($sipRows as $s) {
        $type = $s['schedule_type'] === 'SWP' ? "<span class='badge-r'>💸 SWP</span>" : "<span class='badge-g'>🔄 SIP</span>";
        $active = $s['is_active'] ? "<span class='badge-g'>Active</span>" : "<span class='badge-r'>Stopped</span>";
        echo "<tr><td>{$s['id']}</td><td>$type</td><td>{$s['fund_name']}</td><td>₹{$s['sip_amount']}</td><td>{$s['frequency']}</td><td>{$s['start_date']}</td><td>$active</td><td>{$s['schedule_type']}</td><td>{$s['notes']}</td></tr>";
    }
    echo "</table>";
}

// ── TEST 2: mf_list holdings with SIP join ────────────────────
echo "<h2>Test 2: Holdings + SIP Join (what mf_holdings.php table shows)</h2>";
$stmt = $db->prepare("
    SELECT h.fund_id, f.scheme_name,
           (SELECT COUNT(*) FROM sip_schedules s 
            WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
              AND s.is_active = 1 AND s.asset_type = 'mf'
              AND s.schedule_type = 'SIP') AS active_sip_count,
           (SELECT COUNT(*) FROM sip_schedules s 
            WHERE s.fund_id = h.fund_id AND s.portfolio_id = h.portfolio_id
              AND s.is_active = 1 AND s.asset_type = 'mf'
              AND s.schedule_type = 'SWP') AS active_swp_count,
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
    ORDER BY h.total_invested DESC
    LIMIT 10
");
$stmt->execute([$portfolioId]);
$holdings = $stmt->fetchAll();

$withSip = array_filter($holdings, fn($h) => $h['active_sip_count'] > 0 || $h['active_swp_count'] > 0);
echo "<div>" . count($holdings) . " holdings checked, <b class='" . (count($withSip) > 0 ? 'ok' : 'err') . "'>" . count($withSip) . " have SIP/SWP badge</b></div><br>";

echo "<table><tr><th>Fund</th><th>SIP Count</th><th>SWP Count</th><th>Badge shown?</th><th>Amount</th></tr>";
foreach ($holdings as $h) {
    $hasBadge = $h['active_sip_count'] > 0 || $h['active_swp_count'] > 0;
    $badge = $hasBadge
        ? ($h['active_sip_count'] > 0 ? "<span class='badge-g'>🔄 SIP ₹{$h['active_sip_amount']}/{$h['active_sip_frequency']}</span>" : "<span class='badge-r'>💸 SWP</span>")
        : "—";
    $row_bg = $hasBadge ? "background:#f0fdf4;" : "";
    echo "<tr style='$row_bg'><td>{$h['scheme_name']}</td><td>{$h['active_sip_count']}</td><td>{$h['active_swp_count']}</td><td>$badge</td><td>" . ($h['active_sip_amount'] ?? '—') . "</td></tr>";
}
echo "</table>";

// ── TEST 3: mf_list.php actual API call ───────────────────────
echo "<h2>Test 3: Actual mf_list.php API Output (first 3 funds)</h2>";
$_GET['view']         = 'holdings';
$_GET['portfolio_id'] = $portfolioId;
ob_start();
require APP_ROOT . '/api/mutual_funds/mf_list.php';
$raw = ob_get_clean();
$decoded = json_decode($raw, true);
$apiData = $decoded['data'] ?? [];

if (empty($apiData)) {
    echo "<div class='err'>❌ mf_list.php returned no data</div>";
    echo "<pre>" . htmlspecialchars(substr($raw, 0, 500)) . "</pre>";
} else {
    echo "<div class='ok'>✅ " . count($apiData) . " holdings from API</div><br>";
    echo "<table><tr><th>Fund</th><th>active_sip_count</th><th>active_swp_count</th><th>active_sip_amount</th><th>active_sip_frequency</th></tr>";
    foreach (array_slice($apiData, 0, 8) as $h) {
        $hasSip = ($h['active_sip_count'] ?? 0) > 0;
        $hasSwp = ($h['active_swp_count'] ?? 0) > 0;
        $bg = ($hasSip || $hasSwp) ? "background:#f0fdf4;" : "";
        echo "<tr style='$bg'><td style='max-width:250px;overflow:hidden;'>{$h['scheme_name']}</td>"
           . "<td>" . ($h['active_sip_count'] ?? '<span class=err>MISSING</span>') . "</td>"
           . "<td>" . ($h['active_swp_count'] ?? '<span class=err>MISSING</span>') . "</td>"
           . "<td>" . ($h['active_sip_amount'] ?? '—') . "</td>"
           . "<td>" . ($h['active_sip_frequency'] ?? '—') . "</td></tr>";
    }
    echo "</table>";
}

// ── TEST 4: JS cache buster ───────────────────────────────────
echo "<h2>Test 4: JS Cache Check</h2>";
$jsFile = APP_ROOT . '/public/js/mf.js';
$jsMtime = file_exists($jsFile) ? filemtime($jsFile) : 0;
$jsSize  = file_exists($jsFile) ? filesize($jsFile) : 0;
echo "<div>mf.js: size=<b>$jsSize bytes</b> | modified=<b>" . date('Y-m-d H:i:s', $jsMtime) . "</b></div>";
echo "<div class='warn'>⚠️ If mf.js was recently updated, clear browser cache: <b>Ctrl+Shift+R</b> (Windows) or <b>Cmd+Shift+R</b> (Mac)</div>";

// Check mf.js has the badge code
$jsContent = file_get_contents($jsFile);
$hasBadge = strpos($jsContent, 'active_sip_count > 0 || (h.active_swp_count') !== false;
echo $hasBadge 
    ? "<div class='ok'>✅ mf.js has SIP badge code</div>"
    : "<div class='err'>❌ mf.js does NOT have SIP badge code — file not updated!</div>";

echo "<br><div style='background:#f0fdf4;padding:12px;border-radius:8px;'>Done — " . date('Y-m-d H:i:s') . "</div>";
?>
</body></html>
