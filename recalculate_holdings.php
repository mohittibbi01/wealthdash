<?php
/**
 * WealthDash — One-time Holdings Recalculator
 * Run this ONCE to rebuild mf_holdings from existing transactions
 * Place in: C:\xampp\htdocs\wealthdash\recalculate_holdings.php
 * Then open: localhost/wealthdash/recalculate_holdings.php
 * DELETE this file after running!
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/holding_calculator.php';

echo "<pre style='font-family:monospace;padding:20px;'>";
echo "=== WealthDash Holdings Recalculator ===\n\n";

$db = DB::conn();

// Get all distinct fund+folio+portfolio combos from transactions
$combos = $db->query("
    SELECT DISTINCT portfolio_id, fund_id, folio_number
    FROM mf_transactions
    ORDER BY portfolio_id, fund_id
")->fetchAll();

echo "Found " . count($combos) . " fund combinations to process...\n\n";

$success = 0;
$errors  = 0;

foreach ($combos as $combo) {
    $pid   = (int)$combo['portfolio_id'];
    $fid   = (int)$combo['fund_id'];
    $folio = $combo['folio_number']; // may be null

    try {
        recalculate_mf_holdings($db, $pid, $fid, $folio);
        echo "✅ Portfolio #{$pid} | Fund #{$fid} | Folio: " . ($folio ?? 'NULL') . "\n";
        $success++;
    } catch (Throwable $e) {
        echo "❌ ERROR — Portfolio #{$pid} | Fund #{$fid} | Folio: " . ($folio ?? 'NULL') . "\n";
        echo "   → " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== Done! ===\n";
echo "✅ Success: {$success}\n";
echo "❌ Errors:  {$errors}\n";
echo "\n⚠️  DELETE this file now! (recalculate_holdings.php)\n";
echo "</pre>";
