<?php
$file = __DIR__ . '/api/reports/sip_tracker.php';
$content = file_get_contents($file);

// Check for common PHP 8 syntax issues
$issues = [];

// Check match() expressions are complete
preg_match_all('/match\s*\(/', $content, $matches);
echo "match() count: " . count($matches[0]) . "\n";

// Check for 'never' return type (PHP 8.1+)
preg_match_all('/:\s*never\b/', $content, $m);
echo "never return type: " . count($m[0]) . "\n";

// Try including file and catch fatal
echo "File size: " . strlen($content) . " bytes\n";
echo "Lines: " . substr_count($content, "\n") . "\n";

// Check last valid PHP
$tokens = @token_get_all($content);
echo "Tokens: " . count($tokens) . "\n";

// Look for any stray output before <?php
$trimmed = ltrim($content);
if (substr($trimmed, 0, 5) !== '<?php') {
    echo "PROBLEM: File doesn't start with <?php\n";
    echo "First 20 chars hex: ";
    for ($i=0; $i<20; $i++) echo sprintf('%02X ', ord($content[$i]));
    echo "\n";
} else {
    echo "File starts correctly with <?php\n";
}

// Check the switch statement structure
preg_match_all('/case\s+\'sip_/', $content, $cases);
echo "SIP cases: " . implode(', ', array_map(fn($c) => trim($c), $cases[0])) . "\n";

echo "\nDone - no syntax errors detected by tokenizer\n";
