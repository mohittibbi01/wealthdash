<?php
/**
 * Run: localhost/wealthdash/restore_and_fix.php
 * Restores backup and applies ONE clean fix
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
$file = APP_ROOT . '/templates/pages/report_sip.php';

// Find the most recent backup
$backups = glob($file . '.bak*');
sort($backups);
echo "Backups found:\n";
foreach ($backups as $b) {
    echo "  " . basename($b) . " (" . filesize($b) . " bytes, " . date('Y-m-d H:i:s', filemtime($b)) . ")\n";
}

// Use the OLDEST backup (original file before all patches)
$originalBackup = $backups[0] ?? null;
if ($originalBackup) {
    echo "\nRestoring from: " . basename($originalBackup) . "\n";
    $original = file_get_contents($originalBackup);
    echo "Original size: " . strlen($original) . " bytes\n";
    
    // Verify it doesn't have syntax error
    $lines = explode("\n", $original);
    $line593 = $lines[592] ?? '';
    echo "Original line 593: $line593\n";
    
    // Apply ONLY the fixes we need - clean and simple
    
    // Fix 1: getSipPortfolioId - hardcode portfolio_id
    $original = preg_replace(
        '/function getSipPortfolioId\(\)\s*\{[^}]+\}/s',
        'function getSipPortfolioId() { return ' . $portfolioId . '; }',
        $original
    );
    echo "Fix 1 (getSipPortfolioId): APPLIED\n";
    
    // Fix 2: Add stopSip function before closeSipModal
    if (strpos($original, 'function stopSip') === false) {
        $stopFn = '
function stopSip(id, name, type) {
  if (!confirm(type + " \\"" + name + "\\" stop karna chahte ho?")) return;
  API.post("/api/router.php", {
    action: "sip_stop", sip_id: id,
    end_date: new Date().toISOString().split("T")[0],
    portfolio_id: ' . $portfolioId . ', csrf_token: window.CSRF_TOKEN
  }).then(function(res) {
    if (res.success) { showToast(type + " stopped!", "success"); loadSipList(); loadSipAnalysis(); }
    else showToast(res.message || "Error", "error");
  }).catch(function(e) { showToast("Error: " + e.message, "error"); });
}
';
        $original = str_replace('function closeSipModal()', $stopFn . 'function closeSipModal()', $original);
        echo "Fix 2 (stopSip): ADDED\n";
    }
    
    // Fix 3: Add Type column to table header
    if (strpos($original, '<th>Type</th>') === false) {
        $original = str_replace(
            '<th>Fund</th><th>Category</th>',
            '<th>Fund</th><th>Type</th><th>Category</th>',
            $original
        );
        echo "Fix 3 (Type column header): ADDED\n";
    }
    
    // Fix 4: Add type badge + stop button to rows - SAFE string concatenation
    // Find the existing row render and add type badge
    $oldRow = "      return `\n      <tr class=\"\${s.is_active != 1 ? 'row-inactive' : ''}\" data-sip-id=\"\${s.id}\">\n        <td>\${esc(s.fund_name||'—')}<br><small class=\"text-secondary\">\${esc(s.fund_house||'')}</small></td>\n        <td><span class=\"badge badge-secondary text-xs\">\${esc(s.fund_category||'—')}</span></td>";
    
    if (strpos($original, $oldRow) !== false) {
        $newRow = "      var typeLabel = (s.notes||'').toUpperCase()==='SWP' ? 'SWP' : 'SIP';
      var typeBadgeHtml = typeLabel==='SWP'
        ? '<span style=\"display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;\">SWP</span>'
        : '<span style=\"display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;\">SIP</span>';
      return `\n      <tr class=\"\${s.is_active != 1 ? 'row-inactive' : ''}\" data-sip-id=\"\${s.id}\">\n        <td>\${esc(s.fund_name||'—')}<br><small class=\"text-secondary\">\${esc(s.fund_house||'')}</small></td>\n        <td>\${typeBadgeHtml}</td>\n        <td><span class=\"badge badge-secondary text-xs\">\${esc(s.fund_category||'—')}</span></td>";
        $original = str_replace($oldRow, $newRow, $original);
        echo "Fix 4 (type badge in row): APPLIED\n";
    } else {
        echo "Fix 4: row pattern not found (will skip)\n";
    }
    
    // Fix 5: Add stop button - find Actions td
    $oldActions = '<button class="btn btn-ghost btn-xs" onclick="editSip(${s.id})">Edit</button>
          <button class="btn btn-ghost btn-xs text-danger" onclick="deleteSip(${s.id},\'${esc(s.fund_name)}\')">Delete</button>';
    if (strpos($original, $oldActions) !== false) {
        $newActions = '<button class="btn btn-ghost btn-xs" onclick="editSip(${s.id})">Edit</button>
          <button class="btn btn-ghost btn-xs" style="color:#d97706" onclick="stopSip(${s.id},String(s.fund_name||\'\'),typeLabel)">Stop</button>
          <button class="btn btn-ghost btn-xs text-danger" onclick="deleteSip(${s.id},\'${esc(s.fund_name)}\')">Delete</button>';
        $original = str_replace($oldActions, $newActions, $original);
        echo "Fix 5 (Stop button): ADDED\n";
    } else {
        echo "Fix 5: actions pattern not found\n";
    }
    
    // Save
    file_put_contents($file, $original);
    echo "\nSaved! Size: " . strlen($original) . " bytes\n";
    
    // Final check - show line 593 area
    $newlines = explode("\n", $original);
    echo "\nLines 590-598:\n";
    for ($i = 589; $i <= min(597, count($newlines)-1); $i++) {
        echo ($i+1) . ": " . $newlines[$i] . "\n";
    }
    
} else {
    echo "NO BACKUP FOUND!\n";
    echo "Current file size: " . filesize($file) . "\n";
    
    // Show line 593
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    echo "Lines 588-598:\n";
    for ($i = 587; $i <= min(597, count($lines)-1); $i++) {
        echo ($i+1) . ": " . $lines[$i] . "\n";
    }
}

echo "\n\nDone! Now visit report_sip.php and press Ctrl+Shift+R\n";
