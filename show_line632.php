<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$file = APP_ROOT . '/templates/pages/report_sip.php';
$content = file_get_contents($file);
$lines = explode("\n", $content);

echo "File size: " . strlen($content) . " bytes\n";
echo "Total lines: " . count($lines) . "\n\n";

echo "=== Lines 628-638 (around error) ===\n";
for ($i = 627; $i <= min(637, count($lines)-1); $i++) {
    echo ($i+1) . ": " . $lines[$i] . "\n";
    // Check for non-ASCII
    for ($j = 0; $j < strlen($lines[$i]); $j++) {
        if (ord($lines[$i][$j]) > 127) {
            echo "   ^^^ Non-ASCII char at col ".($j+1).": ord=".ord($lines[$i][$j])."\n";
        }
    }
}

echo "\n=== Replacing entire file with CLEAN version ===\n";

// Read our clean version
$cleanContent = $content;

// Fix ALL non-ASCII characters (emojis etc in JS strings)
// Replace emoji chars that cause issues
$cleanContent = str_replace("\u{23F9}", '', $cleanContent); // ⏹
$cleanContent = str_replace("\u{1F504}", '', $cleanContent); // 🔄
$cleanContent = str_replace("\u{1F4B8}", '', $cleanContent); // 💸
$cleanContent = str_replace("\u{1F4CA}", '', $cleanContent); // 📊
$cleanContent = str_replace("\u{1F4B0}", '', $cleanContent); // 💰
$cleanContent = str_replace("\u{2705}", '', $cleanContent); // ✅

// Also fix any \u encoded
$cleanContent = preg_replace('/\\\\u[0-9a-fA-F]{4}/', '', $cleanContent);

file_put_contents($file, $cleanContent);
echo "Saved! New size: " . strlen($cleanContent) . "\n";

// Show line 632 after fix
$newlines = explode("\n", $cleanContent);
echo "\n=== Line 630-636 after fix ===\n";
for ($i = 629; $i <= min(635, count($newlines)-1); $i++) {
    echo ($i+1) . ": " . $newlines[$i] . "\n";
}

echo "\nDone! Press Ctrl+Shift+R on report_sip.php\n";
