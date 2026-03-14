<?php
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();

$db = DB::conn();

echo "<pre style='font-size:13px'>";

// Step 1: Find SBI Conservative fund_id
$f = $db->query("SELECT id, scheme_name FROM funds WHERE scheme_name LIKE '%SBI Conservative%' LIMIT 3")->fetchAll();
echo "=== FUNDS MATCHING 'SBI Conservative' ===\n";
foreach($f as $r) echo "  id={$r['id']} name={$r['scheme_name']}\n";

// Step 2: All transactions for this fund
echo "\n=== ALL TRANSACTIONS (SBI Conservative) ===\n";
$txns = $db->query("
    SELECT t.id, t.portfolio_id, t.fund_id, t.folio_number, t.transaction_type, 
           t.txn_date, t.units, t.nav
    FROM mf_transactions t
    JOIN funds f ON f.id = t.fund_id
    WHERE f.scheme_name LIKE '%SBI Conservative%'
    ORDER BY t.txn_date ASC
")->fetchAll();
$totalBuy=0; $totalSell=0;
foreach($txns as $r) {
    $type = $r['transaction_type'];
    if(in_array($type,['BUY','SWITCH_IN','DIV_REINVEST'])) $totalBuy += $r['units'];
    if(in_array($type,['SELL','SWITCH_OUT'])) $totalSell += $r['units'];
    echo "  id={$r['id']} p={$r['portfolio_id']} f={$r['fund_id']} folio='{$r['folio_number']}' type={$type} date={$r['txn_date']} units={$r['units']}\n";
}
echo "  TOTAL BUY: $totalBuy | TOTAL SELL: $totalSell | NET: ".($totalBuy-$totalSell)."\n";

// Step 3: Current mf_holdings row
echo "\n=== mf_holdings ROW ===\n";
$h = $db->query("
    SELECT h.*, f.scheme_name FROM mf_holdings h
    JOIN funds f ON f.id = h.fund_id
    WHERE f.scheme_name LIKE '%SBI Conservative%'
")->fetchAll();
foreach($h as $r) {
    echo "  id={$r['id']} p={$r['portfolio_id']} fund={$r['fund_id']} folio='{$r['folio_number']}' total_units={$r['total_units']} is_active={$r['is_active']}\n";
}

echo "</pre>";
