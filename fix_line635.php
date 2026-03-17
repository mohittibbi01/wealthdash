<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$file = APP_ROOT . '/templates/pages/report_sip.php';
$content = file_get_contents($file);
$lines = explode("\n", $content);

echo "Lines 632-638 BEFORE fix:\n";
for ($i = 631; $i <= 637; $i++) {
    echo ($i+1) . " [" . strlen($lines[$i]) . " chars]: ";
    // Show hex for non-ASCII
    $out = '';
    for ($j = 0; $j < strlen($lines[$i]); $j++) {
        $o = ord($lines[$i][$j]);
        $out .= $o > 127 ? '<'.dechex($o).'>' : $lines[$i][$j];
    }
    echo $out . "\n";
}

// Fix: replace ALL non-ASCII bytes in the entire file with space
$fixed = '';
$len = strlen($content);
for ($i = 0; $i < $len; $i++) {
    $o = ord($content[$i]);
    if ($o > 127) {
        // Skip entire multi-byte sequence
        if ($o >= 0xF0) { $i += 3; } // 4-byte
        elseif ($o >= 0xE0) { $i += 2; } // 3-byte
        elseif ($o >= 0xC0) { $i += 1; } // 2-byte
        // Replace with nothing (remove emoji/special chars)
    } else {
        $fixed .= $content[$i];
    }
}

file_put_contents($file, $fixed);
echo "\nFixed! Removed all non-ASCII. New size: " . strlen($fixed) . "\n";

// Verify line 632-636
$newlines = explode("\n", $fixed);
echo "\nLines 632-638 AFTER fix:\n";
for ($i = 631; $i <= 637; $i++) {
    echo ($i+1) . ": " . $newlines[$i] . "\n";
}
echo "\nDone! Ctrl+Shift+R on report_sip.php\n";
