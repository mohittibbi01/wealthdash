<?php
/**
 * Run: localhost/wealthdash/patch_report_sip2.php
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();
header('Content-Type: text/plain');

$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
$db = DB::conn();

echo "portfolio_id = $portfolioId\n\n";

// 1. Directly call what sip_list action does
echo "=== Direct sip_list simulation ===\n";
$sips = $db->prepare("
    SELECT s.*,
           f.scheme_name AS fund_name,
           f.category    AS fund_category,
           fh.name       AS fund_house,
           f.latest_nav
    FROM sip_schedules s
    LEFT JOIN funds f  ON f.id  = s.fund_id
    LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
    WHERE s.portfolio_id = ?
    ORDER BY s.is_active DESC, s.start_date DESC
");
$sips->execute([$portfolioId]);
$rows = $sips->fetchAll();
echo "sip_list query returns: " . count($rows) . " rows\n";
foreach (array_slice($rows, 0, 3) as $r) {
    echo "  id={$r['id']} fund={$r['fund_name']} active={$r['is_active']}\n";
}

// 2. Check what API.post does - check if router.php handles sip_list
echo "\n=== router.php sip_list check ===\n";
$router = file_get_contents(APP_ROOT . '/api/router.php');
echo "Has sip_list in router: " . (strpos($router, "'sip_list'") !== false ? "YES" : "NO") . "\n";

// 3. Check report_sip.php JS - what does loadSipList call
$report = file_get_contents(APP_ROOT . '/templates/pages/report_sip.php');

// Find loadSipList function
preg_match('/async function loadSipList\(\).*?(?=async function|\nfunction )/s', $report, $m);
if ($m) {
    echo "\n=== loadSipList function ===\n";
    echo substr($m[0], 0, 500) . "\n";
}

// Find what portfolio_id is sent
preg_match_all('/portfolio_id.*?getSipPortfolioId/', $report, $pm);
echo "\nportfolio_id calls in JS: " . count($pm[0]) . "\n";

// 4. Check getSipPortfolioId current state
preg_match('/function getSipPortfolioId\(\).*?\}/s', $report, $gm);
if ($gm) {
    echo "\n=== getSipPortfolioId (current) ===\n";
    echo $gm[0] . "\n";
}

// 5. NOW THE REAL FIX: Check if PHP syntax error exists in report_sip.php
echo "\n=== Checking for PHP syntax issue in report_sip.php ===\n";
// The patch we applied embeds PHP in JS which runs at page load
// Check if it was applied correctly
if (strpos($report, 'const _pid = <?=') !== false) {
    echo "PHP tag inside JS: YES - this could cause issues if page not refreshed\n";
    // Check what value it outputs
    preg_match('/const _pid = (<\?=.*?\?>)/', $report, $php_m);
    if ($php_m) echo "PHP expression: " . $php_m[1] . "\n";
} else {
    echo "PHP tag inside JS: NOT FOUND - patch may not have applied\n";
}

// 6. AGGRESSIVE FIX: Rewrite report_sip.php init section
echo "\n=== Applying aggressive portfolio_id fix ===\n";

// Find the script init block
$oldInit = 'window._SIP_PORTFOLIO_ID = <?= $portfolioId ?>;
window._SIP_PORTFOLIOS   = <?= json_encode($portfolios) ?>;';

$newInit = 'window._SIP_PORTFOLIO_ID = <?= (int)$portfolioId ?>;
window._SIP_PORTFOLIOS   = <?= json_encode($portfolios) ?>;
// Force sync WD object
if (window.WD) window.WD.selectedPortfolio = <?= (int)$portfolioId ?>;';

if (strpos($report, $oldInit) !== false) {
    $report = str_replace($oldInit, $newInit, $report);
    echo "Init block fix: APPLIED\n";
} else {
    echo "Init block: checking current...\n";
    preg_match('/window\._SIP_PORTFOLIO_ID.*?;/', $report, $im);
    if ($im) echo "Current: " . $im[0] . "\n";
}

// 7. Fix getSipPortfolioId to be dead simple
$oldFn = 'function getSipPortfolioId() {
  const _pid = <?= (int)($_SESSION["selected_portfolio_id"] ?? 0) ?>;
  if (_pid) { window._SIP_PORTFOLIO_ID = _pid; }
  return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || 0;
}';

$newFn = 'function getSipPortfolioId() {
  return <?= (int)$portfolioId ?>;
}';

if (strpos($report, $oldFn) !== false) {
    $report = str_replace($oldFn, $newFn, $report);
    echo "getSipPortfolioId hardcode fix: APPLIED\n";
} else {
    // Try other variants
    $patterns = [
        'function getSipPortfolioId() {
  return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || 0;
}',
        'function getSipPortfolioId() {
  // Always use PHP-set value first (most reliable)
  const phpId = <?= (int)($_SESSION["selected_portfolio_id"] ?? 0) ?>;
  if (phpId > 0) { window._SIP_PORTFOLIO_ID = phpId; window.WD && (window.WD.selectedPortfolio = phpId); }
  return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || phpId || 0;
}',
    ];
    
    foreach ($patterns as $p) {
        if (strpos($report, $p) !== false) {
            $report = str_replace($p, $newFn, $report);
            echo "getSipPortfolioId variant fix: APPLIED\n";
            break;
        }
    }
    
    // Last resort: regex replace
    $count = 0;
    $report = preg_replace(
        '/function getSipPortfolioId\(\)\s*\{[^}]+\}/s',
        'function getSipPortfolioId() { return <?= (int)$portfolioId ?>; }',
        $report, 1, $count
    );
    if ($count) echo "getSipPortfolioId regex fix: APPLIED\n";
    else echo "getSipPortfolioId: could not fix!\n";
}

file_put_contents(APP_ROOT . '/templates/pages/report_sip.php', $report);
echo "\nreport_sip.php saved!\n";

// 8. Verify final state
$final = file_get_contents(APP_ROOT . '/templates/pages/report_sip.php');
preg_match('/function getSipPortfolioId\(\).*?\}/s', $final, $fm);
echo "\nFinal getSipPortfolioId:\n" . ($fm[0] ?? 'NOT FOUND') . "\n";

echo "\n✅ Done! Visit report_sip.php and press Ctrl+Shift+R\n";
