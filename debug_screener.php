<?php
/**
 * WealthDash Debug — Fund Screener Diagnostic
 * Access: http://localhost/wealthdash/debug_screener.php
 * DELETE THIS FILE after debugging!
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$db = DB::conn();
echo "=== WealthDash Fund Screener Debug ===\n\n";

// 1. Test basic funds count
try {
    $total = (int)$db->query("SELECT COUNT(*) FROM funds WHERE is_active=1")->fetchColumn();
    echo "✓ Total active funds: $total\n";
} catch (Exception $e) {
    echo "✗ funds COUNT failed: " . $e->getMessage() . "\n";
}

// 2. Test fund_houses join
try {
    $r = $db->query("SELECT f.id, f.scheme_name, COALESCE(fh.short_name, fh.name, '') AS fund_house FROM funds f LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id WHERE f.is_active=1 LIMIT 1")->fetch();
    echo "✓ JOIN test OK: " . ($r['scheme_name'] ?? 'no data') . "\n";
} catch (Exception $e) {
    echo "✗ JOIN failed: " . $e->getMessage() . "\n";
}

// 3. Test optional columns
$optionalCols = ['expense_ratio', 'exit_load_pct', 'exit_load_days', 'prev_nav', 'prev_nav_date', 'risk_level', 'aum_crore'];
foreach ($optionalCols as $col) {
    try {
        $db->query("SELECT $col FROM funds LIMIT 1");
        echo "✓ Column exists: $col\n";
    } catch (Exception $e) {
        echo "✗ Column MISSING: $col\n";
    }
}

// 4. Test the FULL main query (what fund_screener.php runs)
echo "\n--- Testing full screener query ---\n";
try {
    $hasPrevNav = false;
    try { $db->query("SELECT prev_nav FROM funds LIMIT 1"); $hasPrevNav = true; } catch(Exception $e){}
    $hasExpCol = false;
    try { $db->query("SELECT expense_ratio FROM funds LIMIT 1"); $hasExpCol = true; } catch(Exception $e){}

    $expColSQL  = $hasExpCol  ? ', f.expense_ratio, f.exit_load_pct, f.exit_load_days' : '';
    $prevNavSQL = $hasPrevNav ? ', f.prev_nav, f.prev_nav_date' : '';

    $sql = "
        SELECT f.id, f.scheme_code, f.scheme_name, f.category, f.option_type,
               f.latest_nav, f.latest_nav_date, f.min_ltcg_days, f.lock_in_days,
               f.highest_nav, f.highest_nav_date
               $expColSQL $prevNavSQL,
               COALESCE(fh.short_name, fh.name, '') AS fund_house
        FROM funds f LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id
        WHERE f.is_active = 1
        ORDER BY f.scheme_name ASC LIMIT 5 OFFSET 0
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, 5, PDO::PARAM_INT);
    $stmt->bindValue(2, 0, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    echo "✓ Main query OK — returned " . count($rows) . " rows\n";
    if ($rows) echo "  First fund: " . $rows[0]['scheme_name'] . "\n";
} catch (Exception $e) {
    echo "✗ Main query FAILED: " . $e->getMessage() . "\n";
    echo "  SQL was:\n$sql\n";
}

// 5. Test facet queries
echo "\n--- Testing facet queries ---\n";
try {
    $fhRows = $db->query("SELECT COALESCE(fh.short_name, fh.name) AS amc, COUNT(*) AS cnt FROM funds f LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id WHERE f.is_active=1 AND COALESCE(fh.short_name,fh.name,'') != '' GROUP BY fh.id ORDER BY cnt DESC LIMIT 5")->fetchAll();
    echo "✓ Fund houses facet OK: " . count($fhRows) . " AMCs\n";
} catch (Exception $e) {
    echo "✗ Fund houses facet FAILED: " . $e->getMessage() . "\n";
}

try {
    $catRows = $db->query("SELECT category, COUNT(*) AS cnt FROM funds WHERE is_active=1 AND category IS NOT NULL AND category != '' GROUP BY category ORDER BY cnt DESC LIMIT 5")->fetchAll();
    echo "✓ Categories facet OK: " . count($catRows) . " categories\n";
} catch (Exception $e) {
    echo "✗ Categories facet FAILED: " . $e->getMessage() . "\n";
}

try {
    $ltcgRows = $db->query("SELECT min_ltcg_days, COUNT(*) AS cnt FROM funds WHERE is_active=1 GROUP BY min_ltcg_days")->fetchAll();
    echo "✓ LTCG facet OK: " . count($ltcgRows) . " groups\n";
} catch (Exception $e) {
    echo "✗ LTCG facet FAILED: " . $e->getMessage() . "\n";
}

// 6. Simulate the full JSON response
echo "\n--- Simulating JSON response ---\n";
ob_start();
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM funds f LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id WHERE f.is_active = 1");
    $countStmt->execute([]);
    $total = (int)$countStmt->fetchColumn();

    $result = json_encode(['success' => true, 'total' => $total, 'page' => 1, 'pages' => 1, 'data' => [], 'facets' => null], JSON_UNESCAPED_UNICODE);
    ob_end_clean();
    echo "✓ JSON encode OK, total=$total\n";
    echo "  Sample JSON: " . substr($result, 0, 100) . "\n";
} catch (Exception $e) {
    $out = ob_get_clean();
    echo "✗ JSON simulation FAILED: " . $e->getMessage() . "\n";
    if ($out) echo "  Stray output: " . substr($out, 0, 200) . "\n";
}

// 7. Check if there's a CSRF or session issue with the API
echo "\n--- Session & Auth check ---\n";
echo "Session ID: " . session_id() . "\n";
echo "User ID in session: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
echo "Session active: " . (session_status() === PHP_SESSION_ACTIVE ? 'YES' : 'NO') . "\n";

echo "\n=== Debug complete ===\n";
echo "DELETE this file after use!\n";
