<?php
/**
 * PASTE THIS FILE: C:\xampp\htdocs\wealthdash\test_sip.php
 * OPEN: http://localhost/wealthdash/test_sip.php
 * Ye batayega ki sip_tracker.php file sahi hai ya nahi
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
header('Content-Type: text/plain');

$file = APP_ROOT . '/api/reports/sip_tracker.php';
$content = file_get_contents($file);

echo "=== sip_tracker.php CHECK ===\n\n";
echo "File size: " . strlen($content) . " bytes\n";
echo "Has sip_list case: " . (strpos($content, "case 'sip_list':") !== false ? "YES ✅" : "NO ❌ — purani file hai!") . "\n";
echo "Has f.fund_house (WRONG): " . (preg_match("/f\.fund_house[^_]/", $content) ? "YES ❌ — galat column!" : "NO ✅") . "\n";
echo "Has fh.short_name (CORRECT): " . (strpos($content, 'fh.short_name') !== false ? "YES ✅" : "NO ❌") . "\n";
echo "Has fund_houses JOIN: " . (strpos($content, 'fund_houses fh') !== false ? "YES ✅" : "NO ❌") . "\n";
echo "\nConclusion: " . (
    strpos($content, "case 'sip_list':") !== false && 
    !preg_match("/f\.fund_house[^_]/", $content)
    ? "✅ File sahi hai — browser cache clear karo (Ctrl+Shift+R)"
    : "❌ Purani file hai — naya sip_tracker.php replace karo"
) . "\n";
