<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$file = APP_ROOT . '/templates/pages/report_sip.php';
$content = file_get_contents($file);

echo "Server file: " . strlen($content) . " bytes, " . substr_count($content, "\n") . " lines\n\n";

// Show line 632 col 95 exactly
$lines = explode("\n", $content);
$line632 = $lines[631] ?? '';
echo "Line 632 (" . strlen($line632) . " chars):\n$line632\n\n";

// Show chars 88-100
echo "Chars 88-103 of line 632:\n";
for ($i = 87; $i < min(103, strlen($line632)); $i++) {
    printf("col%3d: '%s' (ord %d)\n", $i+1, $line632[$i], ord($line632[$i]));
}

// THE ACTUAL FIX: replace char by char, removing anything > 127
echo "\n\nApplying byte-level clean...\n";
$out = '';
$i = 0;
$removed = 0;
while ($i < strlen($content)) {
    $byte = ord($content[$i]);
    if ($byte <= 127) {
        $out .= $content[$i];
        $i++;
    } else {
        // Skip entire UTF-8 multibyte sequence
        if ($byte >= 0xF0)      { $skip = 4; }
        elseif ($byte >= 0xE0)  { $skip = 3; }
        elseif ($byte >= 0xC0)  { $skip = 2; }
        else                     { $skip = 1; }
        $removed++;
        $i += $skip;
    }
}
echo "Removed $removed multibyte sequences\n";

// Write back
file_put_contents($file, $out);
echo "Saved! New size: " . strlen($out) . " bytes\n";

// Verify line 632
$newlines = explode("\n", $out);
$newline632 = $newlines[631] ?? '';
echo "\nLine 632 after fix (" . strlen($newline632) . " chars):\n$newline632\n";

// Check for any remaining non-ASCII
$remaining = 0;
for ($i = 0; $i < strlen($out); $i++) {
    if (ord($out[$i]) > 127) $remaining++;
}
echo "\nRemaining non-ASCII bytes: $remaining\n";

if ($remaining === 0) {
    echo "\nPERFECT! File is 100% ASCII clean. Hard refresh report_sip.php now.\n";
} else {
    echo "\nStill has non-ASCII - something went wrong!\n";
}
