<?php
/**
 * Run: localhost/wealthdash/fix_syntax_error.php
 * Fixes the JS syntax error in report_sip.php line ~593
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
$file = APP_ROOT . '/templates/pages/report_sip.php';

// Backup original first
$content = file_get_contents($file);
file_put_contents($file . '.bak', $content);
echo "Backup saved\n";
echo "portfolio_id = $portfolioId\n\n";

// Show lines around 590-596 to see the actual error
$lines = explode("\n", $content);
echo "=== Lines 585-600 (where syntax error is) ===\n";
for ($i = 584; $i <= min(599, count($lines)-1); $i++) {
    echo ($i+1) . ": " . $lines[$i] . "\n";
}

echo "\n=== Finding all problematic patterns ===\n";

// Find any broken stopSip calls
preg_match_all('/stopSip\([^)]*\)/', $content, $m);
foreach ($m[0] as $match) {
    echo "stopSip call: $match\n";
}

// Find the row rendering JS - the sips.map section
$mapStart = strpos($content, 'tbody.innerHTML = sips.map');
if ($mapStart !== false) {
    echo "\n=== sips.map section (first 800 chars) ===\n";
    echo substr($content, $mapStart, 800) . "\n";
}

echo "\n=== APPLYING CLEAN FIX ===\n";

// Strategy: Replace the entire sips.map block with a clean version
// First find the exact block
$oldMap = "    tbody.innerHTML = sips.map(s => {
      const isSwp = (s.notes||'').toUpperCase() === 'SWP';
      const typeBadge = isSwp
        ? `<span style=\"display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;\">💸 SWP</span>`
        : `<span style=\"display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;\">🔄 SIP</span>`;
      return `";

// Find and show what's actually there
$idx = strpos($content, 'tbody.innerHTML = sips.map');
if ($idx !== false) {
    echo "Found sips.map at position $idx\n";
    // Find the end of this block
    $block = substr($content, $idx, 1500);
    echo "Block preview:\n" . $block . "\n\n";
}

// CLEAN REPLACEMENT - replace the entire sips.map with a safe version
$cleanMap = '    tbody.innerHTML = sips.map(function(s) {
      var isSwp = (s.notes||"").toUpperCase() === "SWP";
      var stype = isSwp ? "SWP" : "SIP";
      var typeBadge = isSwp
        ? "<span style=\"display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;\">&#x1F4B8; SWP</span>"
        : "<span style=\"display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;\">&#x1F504; SIP</span>";
      var stopBtn = s.is_active == 1
        ? "<button class=\"btn btn-ghost btn-xs\" style=\"color:#d97706\" onclick=\"stopSip(" + s.id + ",\'" + esc(s.fund_name||"") + "\',\'" + stype + "\')\">&#x23F9; Stop</button>"
        : "<span style=\"font-size:11px;color:#94a3b8;\">Stopped</span>";
      return "<tr class=\"" + (s.is_active != 1 ? "row-inactive" : "") + "\" data-sip-id=\"" + s.id + "\">"
        + "<td>" + esc(s.fund_name||"—") + "<br><small class=\"text-secondary\">" + esc(s.fund_house||"") + "</small></td>"
        + "<td>" + typeBadge + "</td>"
        + "<td><span class=\"badge badge-secondary text-xs\">" + esc(s.fund_category||"—") + "</span></td>"
        + "<td class=\"text-right\"><strong>" + formatINR(s.sip_amount) + "</strong></td>"
        + "<td>" + (s.frequency||"") + "</td>"
        + "<td>" + (s.sip_day||"") + "</td>"
        + "<td>" + formatDate(s.start_date) + "</td>"
        + "<td>" + (s.next_date ? formatDate(s.next_date) : "<span class=\"text-secondary\">—</span>") + "</td>"
        + "<td class=\"text-right\">" + formatINR(s.total_invested) + "</td>"
        + "<td class=\"text-right sip-xirr\"><span style=\"color:var(--text-muted);font-size:12px;cursor:pointer\" onclick=\"loadSipXirr(" + s.id + ")\" title=\"Click to calculate XIRR\">&#x1F4CA; Calc</span></td>"
        + "<td><span class=\"badge " + (s.is_active==1 ? "badge-success" : "badge-secondary") + "\">" + (s.is_active==1?"Active":"Paused") + "</span></td>"
        + "<td>" + "<button class=\"btn btn-ghost btn-xs\" onclick=\"editSip(" + s.id + ")\">Edit</button> " + stopBtn
        + " <button class=\"btn btn-ghost btn-xs text-danger\" onclick=\"deleteSip(" + s.id + ",\'" + esc(s.fund_name||"") + "\')\">Delete</button></td>"
        + "</tr>";
    }).join("");';

// Find the existing sips.map block and replace it entirely
// Match from "tbody.innerHTML = sips.map" to "}).join('');"
$pattern = '/    tbody\.innerHTML = sips\.map\(.*?\.join\([\'""]\'\);\n/s';
if (preg_match($pattern, $content, $found)) {
    echo "Found sips.map block (" . strlen($found[0]) . " chars), replacing...\n";
    $content = preg_replace($pattern, $cleanMap . "\n", $content, 1);
    echo "Replaced!\n";
} else {
    echo "Could not find sips.map block with regex\n";
    // Try simpler approach - find and replace just the problematic stopSip line
    // Look for the broken line near 593
    echo "Trying line-by-line fix...\n";
    for ($i = 588; $i <= min(600, count($lines)-1); $i++) {
        echo "Line " . ($i+1) . ": " . $lines[$i] . "\n";
    }
}

file_put_contents($file, $content);
echo "\nSaved!\n";

// Verify no syntax issues in the map section
$verify = file_get_contents($file);
$vlines = explode("\n", $verify);
echo "\n=== Verification - lines around getSipPortfolioId ===\n";
foreach ($vlines as $i => $line) {
    if (strpos($line, 'getSipPortfolioId') !== false) {
        echo ($i+1) . ": $line\n";
    }
}

echo "\n✅ Done! Hard refresh report_sip.php with Ctrl+Shift+R\n";
