<?php
/**
 * WealthDash — Force Recalculate All MF Holdings
 * Run ONCE: http://localhost/wealthdash/recalc_holdings.php
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/holding_calculator.php';
$currentUser = require_auth();
if ($currentUser['role'] !== 'admin') die('Admin only');

set_time_limit(300);
$db = DB::conn();

// Get all unique portfolio+fund combinations
$rows = $db->query("
    SELECT DISTINCT portfolio_id, fund_id
    FROM mf_transactions
")->fetchAll();

echo "<pre>";
echo "Recalculating " . count($rows) . " fund+portfolio combinations...\n\n";

$ok = 0; $err = 0;
foreach ($rows as $r) {
    try {
        recalculate_mf_holdings($db, (int)$r['portfolio_id'], (int)$r['fund_id'], null);
        echo "✅ portfolio={$r['portfolio_id']} fund={$r['fund_id']}\n";
        $ok++;
    } catch (Exception $e) {
        echo "❌ portfolio={$r['portfolio_id']} fund={$r['fund_id']}: " . $e->getMessage() . "\n";
        $err++;
    }
}

echo "\nDone! ✅ $ok recalculated, ❌ $err errors\n";

// Show current holdings
echo "\n--- Current Holdings ---\n";
$holdings = $db->query("
    SELECT h.portfolio_id, f.scheme_name, h.folio_number, h.total_units, h.total_invested, h.is_active
    FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
    ORDER BY h.portfolio_id, f.scheme_name
")->fetchAll();
foreach ($holdings as $h) {
    echo "P{$h['portfolio_id']} | {$h['scheme_name']} | folio:{$h['folio_number']} | units:{$h['total_units']} | active:{$h['is_active']}\n";
}
echo "</pre>";