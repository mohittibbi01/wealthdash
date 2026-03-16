<?php
/**
 * JS CACHE BUSTER - Run once: localhost/wealthdash/patch_mf_js.php
 * Forces browser to reload mf.js by touching the file
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
if ($currentUser['role'] !== 'admin') die('Admin only');

header('Content-Type: text/plain');

$jsFile = APP_ROOT . '/public/js/mf.js';
$content = file_get_contents($jsFile);

// Check badge code exists
$hasBadge = strpos($content, 'active_sip_count > 0 || (h.active_swp_count') !== false;
echo "mf.js has badge code: " . ($hasBadge ? "YES" : "NO - NEEDS UPDATE") . "\n";
echo "File size: " . strlen($content) . " bytes\n";
echo "Last modified: " . date('Y-m-d H:i:s', filemtime($jsFile)) . "\n\n";

if (!$hasBadge) {
    echo "PATCHING mf.js...\n";
    // Find the fund-name-cell td and add badge after fund-sub div
    $old = '        <div class="fund-sub">${escHtml(h.fund_house_short||h.fund_house||\'\')} · ${escHtml(h.category||\'\')}${folioInfo ? \' · \' + h.folio_number : \'\'}</div>
      </td>';
    
    $new = '        <div class="fund-sub">${escHtml(h.fund_house_short||h.fund_house||\'\')} · ${escHtml(h.category||\'\')}${folioInfo ? \' · \' + h.folio_number : \'\'}</div>
        ${(h.active_sip_count > 0 || (h.active_swp_count||0) > 0) ? `<div style="margin-top:4px;display:flex;gap:4px;">${h.active_sip_count > 0 ? `<span style="padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;" title="SIP ₹${h.active_sip_amount||\'?\'} / ${h.active_sip_frequency||\'monthly\'}">🔄 SIP</span>` : ""}${(h.active_swp_count||0) > 0 ? `<span style="padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;">💸 SWP</span>` : ""}</div>` : ""}
      </td>';
    
    if (strpos($content, $old) !== false) {
        $content = str_replace($old, $new, $content);
        file_put_contents($jsFile, $content);
        echo "mf.js PATCHED successfully!\n";
    } else {
        echo "Pattern not found in mf.js - checking current state...\n";
        // Show what's around fund-sub
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (strpos($line, 'fund-sub') !== false || strpos($line, 'fund-name-cell') !== false) {
                echo "Line " . ($i+1) . ": " . trim($line) . "\n";
            }
        }
    }
} else {
    echo "Badge code already present in mf.js\n";
}

// Force timestamp change to bust browser cache
touch($jsFile);
echo "\ntouched mf.js — new timestamp: " . date('Y-m-d H:i:s', filemtime($jsFile)) . "\n";
echo "\nNow open: localhost/wealthdash/templates/pages/mf_holdings.php\n";
echo "Press Ctrl+Shift+R for hard refresh\n";
