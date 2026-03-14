<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/holding_calculator.php';
$currentUser = require_auth();

$db = DB::conn();
echo "<pre>";

// Step 1: Fix transaction id=2917 — set folio to AUTO-1-6206
$db->exec("UPDATE mf_transactions SET folio_number='AUTO-1-6206' WHERE id=2917");
echo "✅ Fixed transaction id=2917 folio → AUTO-1-6206\n";

// Step 2: Delete BOTH duplicate holding rows for fund 6206, portfolio 1
$db->exec("DELETE FROM mf_holdings WHERE fund_id=6206 AND portfolio_id=1");
echo "✅ Deleted all duplicate mf_holdings rows for SBI Conservative\n";

// Step 3: Force recalculate fresh
recalculate_mf_holdings($db, 1, 6206, 'AUTO-1-6206');
echo "✅ Recalculated holdings\n";

// Step 4: Show result
$h = $db->query("SELECT id, folio_number, total_units, total_invested, is_active FROM mf_holdings WHERE fund_id=6206 AND portfolio_id=1")->fetchAll();
echo "\n=== Result ===\n";
foreach($h as $r) {
    echo "  id={$r['id']} folio='{$r['folio_number']}' units={$r['total_units']} invested={$r['total_invested']} active={$r['is_active']}\n";
}

$net = $db->query("
    SELECT 
        SUM(CASE WHEN transaction_type IN ('BUY','SWITCH_IN','DIV_REINVEST') THEN units ELSE 0 END) as bought,
        SUM(CASE WHEN transaction_type IN ('SELL','SWITCH_OUT') THEN units ELSE 0 END) as sold
    FROM mf_transactions WHERE fund_id=6206 AND portfolio_id=1
")->fetch();
echo "\nBought: {$net['bought']} | Sold: {$net['sold']} | Net: " . ($net['bought'] - $net['sold']) . "\n";
echo "</pre>";
