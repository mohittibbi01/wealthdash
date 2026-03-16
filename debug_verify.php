<?php
/**
 * Drop this file at: wealthdash/debug_verify.php
 * Visit: localhost/wealthdash/debug_verify.php
 * This tells you EXACTLY which files need replacing and their paths
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html>
<head><title>WealthDash File Verify</title>
<style>
body{font-family:monospace;max-width:900px;margin:30px auto;padding:0 20px;background:#f8fafc;}
.ok{color:#15803d;font-weight:700;} .err{color:#dc2626;font-weight:700;}
.warn{color:#d97706;font-weight:700;} pre{background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;font-size:12px;overflow-x:auto;}
h2{background:#f1f5f9;padding:8px 12px;border-left:4px solid #2563eb;}
</style>
</head>
<body>
<h1>🔍 WealthDash File & Fix Verifier</h1>

<?php
$checks = [
    'mf_holdings.php (session fix)' => [
        'file' => APP_ROOT . '/templates/pages/mf_holdings.php',
        'must_contain' => 'selected_portfolio_id',
        'fix' => 'Replace templates/pages/mf_holdings.php'
    ],
    'sip_tracker.php (schedule_type in INSERT)' => [
        'file' => APP_ROOT . '/api/reports/sip_tracker.php',
        'must_contain' => '$scheduleType',
        'fix' => 'Replace api/reports/sip_tracker.php'
    ],
    'sip_tracker.php (sip_stop case)' => [
        'file' => APP_ROOT . '/api/reports/sip_tracker.php',
        'must_contain' => "case 'sip_stop'",
        'fix' => 'Replace api/reports/sip_tracker.php'
    ],
    'router.php (sip_stop registered)' => [
        'file' => APP_ROOT . '/api/router.php',
        'must_contain' => "case 'sip_stop'",
        'fix' => 'Replace api/router.php'
    ],
    'mf_list.php (active_sip_amount)' => [
        'file' => APP_ROOT . '/api/mutual_funds/mf_list.php',
        'must_contain' => 'active_sip_amount',
        'fix' => 'Replace api/mutual_funds/mf_list.php'
    ],
    'mf.js (SIP badge in fund-name-cell)' => [
        'file' => APP_ROOT . '/public/js/mf.js',
        'must_contain' => 'active_sip_count > 0 || (h.active_swp_count',
        'fix' => 'Replace public/js/mf.js'
    ],
    'report_sip.php (stopSip function)' => [
        'file' => APP_ROOT . '/templates/pages/report_sip.php',
        'must_contain' => 'function stopSip',
        'fix' => 'Replace templates/pages/report_sip.php'
    ],
    'report_sip.php (Type column header)' => [
        'file' => APP_ROOT . '/templates/pages/report_sip.php',
        'must_contain' => '<th>Type</th>',
        'fix' => 'Replace templates/pages/report_sip.php'
    ],
];

$allOk = true;
foreach ($checks as $label => $check) {
    $content = file_exists($check['file']) ? file_get_contents($check['file']) : '';
    $found = $content && strpos($content, $check['must_contain']) !== false;
    if (!$found) $allOk = false;
    $icon = $found ? '<span class="ok">✅ OK</span>' : '<span class="err">❌ NOT UPDATED</span>';
    echo "<div style='margin:8px 0;padding:8px 12px;background:" . ($found ? '#f0fdf4' : '#fef2f2') . ";border-radius:6px;'>";
    echo "$icon &nbsp; <b>$label</b>";
    if (!$found) echo "<br>&nbsp;&nbsp;&nbsp;&nbsp;<span class='warn'>→ Action needed: {$check['fix']}</span>";
    echo "</div>";
}

echo "<h2>📁 Actual File Paths on This Server</h2>";
echo "<pre>";
echo "APP_ROOT = " . APP_ROOT . "\n";
echo "APP_URL  = " . APP_URL . "\n\n";
$files = [
    'templates/pages/mf_holdings.php',
    'templates/pages/report_sip.php',
    'api/reports/sip_tracker.php',
    'api/router.php',
    'api/mutual_funds/mf_list.php',
    'public/js/mf.js',
];
foreach ($files as $f) {
    $full = APP_ROOT . '/' . $f;
    $exists = file_exists($full) ? 'EXISTS' : 'MISSING';
    $size = file_exists($full) ? filesize($full) . ' bytes' : '—';
    $mod = file_exists($full) ? date('Y-m-d H:i:s', filemtime($full)) : '—';
    echo "$exists  $size  $mod  →  $full\n";
}
echo "</pre>";

echo "<h2>🗄️ DB Check</h2>";
try {
    $db = DB::conn();
    
    // Check schedule_type default
    $col = $db->query("SHOW COLUMNS FROM sip_schedules LIKE 'schedule_type'")->fetch();
    if ($col) {
        echo "<div class='ok'>✅ schedule_type column exists: type=" . $col['Type'] . " default=" . $col['Default'] . "</div>";
    } else {
        echo "<div class='err'>❌ schedule_type column missing!</div>";
    }

    // Count SIPs with NULL schedule_type
    $nullCount = $db->query("SELECT COUNT(*) FROM sip_schedules WHERE schedule_type IS NULL OR schedule_type = ''")->fetchColumn();
    if ($nullCount > 0) {
        echo "<div class='err'>❌ $nullCount SIP(s) have NULL/empty schedule_type — run backfill SQL!</div>";
        echo "<pre>UPDATE sip_schedules SET schedule_type = CASE WHEN notes = 'SWP' THEN 'SWP' ELSE 'SIP' END WHERE schedule_type IS NULL OR schedule_type = '';</pre>";
    } else {
        echo "<div class='ok'>✅ All SIPs have schedule_type set</div>";
    }

    // Session portfolio
    $sessPortfolio = $_SESSION['selected_portfolio_id'] ?? 0;
    if ($sessPortfolio) {
        echo "<div class='ok'>✅ Session portfolio_id = $sessPortfolio</div>";
    } else {
        echo "<div class='err'>❌ Session portfolio_id = 0 — mf_holdings.php fix not applied yet!</div>";
    }

    // Total SIPs
    $total = $db->query("SELECT COUNT(*) FROM sip_schedules")->fetchColumn();
    echo "<div style='margin-top:8px;'>Total SIP records in DB: <b>$total</b></div>";

} catch(Exception $e) {
    echo "<div class='err'>DB error: " . $e->getMessage() . "</div>";
}

if ($allOk) {
    echo "<h2 class='ok'>🎉 All fixes applied! Everything looks good.</h2>";
} else {
    echo "<h2 class='err'>⚠️ Some files not updated — replace the files marked above.</h2>";
}
?>
</body></html>
