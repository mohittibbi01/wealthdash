<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$file = APP_ROOT . '/templates/pages/report_sip.php';
$content = file_get_contents($file);
$lines = explode("\n", $content);

// Line 632, col 95
$line = $lines[631]; // 0-indexed
echo "Line 632 full (" . strlen($line) . " chars):\n";
echo $line . "\n\n";

// Show every char with position
echo "Char by char around col 90-100:\n";
for ($i = max(0, 89); $i <= min(strlen($line)-1, 102); $i++) {
    $c = $line[$i];
    $o = ord($c);
    echo "col " . ($i+1) . ": " . ($o > 31 && $o < 127 ? $c : '?') . " (ord=$o)\n";
}

// The REAL fix - show what's at col 95 exactly
echo "\n=== Col 95 char: ord=" . ord($line[94] ?? '') . " ===\n";

// NOW: completely rewrite line 632 removing everything problematic
// It seems the arrow → (E2 86 92) is there
// Let's just rewrite the entire initSipFundSearch function cleanly
echo "\n=== Rewriting initSipFundSearch function ===\n";

$oldFunc = '/function initSipFundSearch\(\).*?^}/ms';

// Find the function
$funcStart = strpos($content, 'function initSipFundSearch()');
if ($funcStart === false) {
    echo "initSipFundSearch not found!\n";
} else {
    // Find its closing brace
    $depth = 0;
    $funcEnd = $funcStart;
    for ($i = $funcStart; $i < strlen($content); $i++) {
        if ($content[$i] === '{') $depth++;
        elseif ($content[$i] === '}') {
            $depth--;
            if ($depth === 0) { $funcEnd = $i + 1; break; }
        }
    }
    echo "Found function from $funcStart to $funcEnd\n";
    echo "Original function:\n" . substr($content, $funcStart, $funcEnd - $funcStart) . "\n";
    
    // Replace with clean ASCII-only version
    $cleanFunc = 'function initSipFundSearch() {
  const input    = document.getElementById(\'sipFundSearch\');
  const dropdown = document.getElementById(\'sipFundDropdown\');
  if (!input || !dropdown) return;

  input.parentElement.style.position = \'relative\';

  input.addEventListener(\'focus\', async () => {
    if (!input.value.trim()) {
      showHoldingsInDropdown();
    }
  });

  input.addEventListener(\'input\', () => {
    clearTimeout(_sipSearchTimer);
    const fidEl = document.getElementById(\'sipFundId\');
    if (fidEl) fidEl.value = \'\';
    const q = input.value.trim();
    if (q.length === 0) { showHoldingsInDropdown(); return; }
    if (q.length >= 2) {
      _sipSearchTimer = setTimeout(() => searchFundsInDropdown(q), 400);
    }
  });

  input.addEventListener(\'blur\', () => {
    setTimeout(() => { dropdown.style.display = \'none\'; }, 200);
  });
}';
    
    $content = substr($content, 0, $funcStart) . $cleanFunc . substr($content, $funcEnd);
    file_put_contents($file, $content);
    echo "\nReplaced initSipFundSearch with clean version!\n";
    echo "New file size: " . strlen($content) . "\n";
    
    // Verify line 632
    $newlines = explode("\n", $content);
    echo "\nLines 630-638:\n";
    for ($i = 629; $i <= 637; $i++) {
        echo ($i+1) . ": " . $newlines[$i] . "\n";
    }
}

echo "\nDone! Ctrl+Shift+R\n";
