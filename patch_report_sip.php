<?php
/**
 * Run: localhost/wealthdash/patch_report_sip.php
 * Directly patches report_sip.php on server
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$db = DB::conn();
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);

echo "=== DIAGNOSE ===\n";
echo "session portfolio_id = $portfolioId\n\n";

// Direct test - what sip_list returns
$sips = $db->prepare("
    SELECT s.id, s.fund_id, s.sip_amount, s.frequency, s.start_date, 
           s.is_active, s.schedule_type, s.notes, f.scheme_name
    FROM sip_schedules s
    LEFT JOIN funds f ON f.id = s.fund_id
    WHERE s.portfolio_id = ?
    ORDER BY s.is_active DESC, s.id DESC
    LIMIT 5
");
$sips->execute([$portfolioId]);
$rows = $sips->fetchAll();
echo "SIPs in DB for portfolio $portfolioId: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  id={$r['id']} | {$r['schedule_type']} | ₹{$r['sip_amount']} {$r['frequency']} | active={$r['is_active']} | {$r['scheme_name']}\n";
}

echo "\n=== PATCH report_sip.php ===\n";
$file = APP_ROOT . '/templates/pages/report_sip.php';
$content = file_get_contents($file);
echo "File size: " . strlen($content) . " bytes\n";

// Check current state
echo "Has stopSip: " . (strpos($content, 'function stopSip') !== false ? "YES" : "NO") . "\n";
echo "Has Type column: " . (strpos($content, '<th>Type</th>') !== false ? "YES" : "NO") . "\n";
echo "getSipPortfolioId returns: checking...\n";

// Find getSipPortfolioId function
preg_match('/function getSipPortfolioId\(\)[^}]+}/s', $content, $m);
if ($m) echo "getSipPortfolioId: " . trim($m[0]) . "\n";

echo "\n=== PATCH sip_tracker.php - test direct sip_list ===\n";
// Simulate what sip_list action does
$trackerFile = APP_ROOT . '/api/reports/sip_tracker.php';
$trackerContent = file_get_contents($trackerFile);
echo "sip_tracker size: " . strlen($trackerContent) . " bytes\n";
echo "Has sip_stop: " . (strpos($trackerContent, "case 'sip_stop'") !== false ? "YES" : "NO") . "\n";
echo "Has scheduleType in INSERT: " . (strpos($trackerContent, 'scheduleType') !== false ? "YES" : "NO") . "\n";

// Check what $portfolioId sip_tracker uses
preg_match('/\$portfolioId\s*=.*?;/', $trackerContent, $pm);
if ($pm) echo "portfolioId line: " . trim($pm[0]) . "\n";

echo "\n=== PATCH: Force portfolio_id in report_sip.php JS ===\n";

// The real fix: hardcode portfolioId from PHP into JS getSipPortfolioId
$oldGetSipPid = 'function getSipPortfolioId() {
  return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || 0;
}';

$newGetSipPid = 'function getSipPortfolioId() {
  // Always use PHP-set value first (most reliable)
  const phpId = <?= (int)($_SESSION["selected_portfolio_id"] ?? 0) ?>;
  if (phpId > 0) { window._SIP_PORTFOLIO_ID = phpId; window.WD && (window.WD.selectedPortfolio = phpId); }
  return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || phpId || 0;
}';

if (strpos($content, $oldGetSipPid) !== false) {
    $content = str_replace($oldGetSipPid, $newGetSipPid, $content);
    echo "getSipPortfolioId patch: APPLIED\n";
} else {
    echo "getSipPortfolioId: pattern not found exactly, searching...\n";
    preg_match('/function getSipPortfolioId.*?\}/s', $content, $m2);
    if ($m2) echo "Found: " . $m2[0] . "\n";
    
    // Try alternate patch
    $alt = 'return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || 0;';
    $altNew = 'const _pid = <?= (int)($_SESSION["selected_portfolio_id"] ?? 0) ?>;
  if (_pid) { window._SIP_PORTFOLIO_ID = _pid; }
  return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || 0;';
    
    if (strpos($content, $alt) !== false) {
        $content = str_replace($alt, $altNew, $content);
        echo "Alternate patch: APPLIED\n";
    } else {
        echo "Could not find return statement either\n";
    }
}

// Also ensure stopSip is there
if (strpos($content, 'function stopSip') === false) {
    echo "Adding stopSip function...\n";
    $stopSipCode = '
async function stopSip(id, name, type) {
  if (!confirm(type + " \\"" + name + "\\" ko aaj stop karna chahte ho?")) return;
  try {
    const pid = getSipPortfolioId();
    const res = await API.post("/api/router.php", {
      action: "sip_stop", sip_id: id,
      end_date: new Date().toISOString().split("T")[0],
      portfolio_id: pid, csrf_token: window.CSRF_TOKEN
    });
    if (res.success) { showToast("✅ " + type + " stopped!", "success"); loadSipList(); loadSipAnalysis(); }
    else showToast(res.message || "Error", "error");
  } catch(e) { showToast("Error: " + e.message, "error"); }
}
';
    $content = str_replace('function closeSipModal()', $stopSipCode . "\nfunction closeSipModal()", $content);
    echo "stopSip: ADDED\n";
}

// Add Stop button to each row if missing
if (strpos($content, 'stopSip(${s.id}') === false) {
    echo "Adding Stop button to rows...\n";
    $oldBtn = '<button class="btn btn-ghost btn-xs" onclick="editSip(${s.id})">Edit</button>';
    $newBtn = '<button class="btn btn-ghost btn-xs" onclick="editSip(${s.id})">Edit</button>
          ${s.is_active == 1 ? `<button class="btn btn-ghost btn-xs" style="color:#d97706" onclick="stopSip(${s.id},\'${esc(s.fund_name||"")}\',\'${isSwp?"SWP":"SIP"}\')">⏹ Stop</button>` : "<span style=\'font-size:11px;color:#94a3b8;\'>Stopped</span>"}';
    if (strpos($content, $oldBtn) !== false) {
        $content = str_replace($oldBtn, $newBtn, $content);
        echo "Stop button: ADDED\n";
    }
}

file_put_contents($file, $content);
echo "\nreport_sip.php saved!\n";
echo "\nNow visit: localhost/wealthdash/templates/pages/report_sip.php\n";
echo "Press Ctrl+Shift+R\n";
