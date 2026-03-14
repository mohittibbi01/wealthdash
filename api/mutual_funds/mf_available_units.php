<?php
/**
 * WealthDash — Available Units API
 * GET ?portfolio_id=&fund_id=&date=YYYY-MM-DD[&folio=]
 * Returns available units to sell for a fund as of a given date.
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
$currentUser = require_auth();

header('Content-Type: application/json');

$portfolio_id = (int)($_GET['portfolio_id'] ?? 0);
$fund_id      = (int)($_GET['fund_id']      ?? 0);
$date         = $_GET['date'] ?? date('Y-m-d');
$folio        = trim($_GET['folio'] ?? '');

if (!$portfolio_id || !$fund_id) {
    echo json_encode(['available_units' => 0, 'error' => 'Missing params']);
    exit;
}

// Validate date
$dt = DateTime::createFromFormat('Y-m-d', $date);
if (!$dt) $date = date('Y-m-d');

try {
    $db = DB::conn();

    // Verify portfolio belongs to user
    $pStmt = $db->prepare("SELECT id, user_id FROM portfolios WHERE id=?");
    $pStmt->execute([$portfolio_id]);
    $portfolio = $pStmt->fetch();
    if (!$portfolio || ($portfolio['user_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin')) {
        echo json_encode(['available_units' => 0, 'error' => 'Access denied']);
        exit;
    }

    // Units bought (BUY / SWITCH_IN / DIV_REINVEST) ON OR BEFORE date
    $buyTypes  = ['BUY', 'SWITCH_IN', 'DIV_REINVEST'];
    $sellTypes = ['SELL', 'SWITCH_OUT'];

    // Don't filter by folio here — user may not have filled it yet
    // Show total available across all folios for this fund in this portfolio

    // Total bought ON OR BEFORE date
    $bSql = "SELECT COALESCE(SUM(units),0), COALESCE(SUM(value_at_cost),0)
             FROM mf_transactions
             WHERE portfolio_id=? AND fund_id=?
               AND transaction_type IN (" . implode(',', array_fill(0, count($buyTypes), '?')) . ")
               AND txn_date <= ?";
    $bStmt = $db->prepare($bSql);
    $bStmt->execute(array_merge([$portfolio_id, $fund_id], $buyTypes, [$date]));
    [$bought, $total_invested] = $bStmt->fetch(PDO::FETCH_NUM);

    // Total sold STRICTLY BEFORE date
    $sSql = "SELECT COALESCE(SUM(units),0)
             FROM mf_transactions
             WHERE portfolio_id=? AND fund_id=?
               AND transaction_type IN (" . implode(',', array_fill(0, count($sellTypes), '?')) . ")
               AND txn_date < ?";
    $sStmt = $db->prepare($sSql);
    $sStmt->execute(array_merge([$portfolio_id, $fund_id], $sellTypes, [$date]));
    $sold = (float)$sStmt->fetchColumn();

    $bought   = (float)$bought;
    $available = max(0, round($bought - $sold, 6));
    $avg_cost_nav = ($bought > 0) ? round($total_invested / $bought, 4) : 0;

    // Also get folio info
    $fStmt = $db->prepare("
        SELECT folio_number FROM mf_transactions
        WHERE portfolio_id=? AND fund_id=? AND folio_number IS NOT NULL AND folio_number != ''
        ORDER BY txn_date ASC LIMIT 1
    ");
    $fStmt->execute([$portfolio_id, $fund_id]);
    $existing_folio = $fStmt->fetchColumn() ?: '';

    echo json_encode([
        'available_units' => $available,
        'bought'          => round($bought, 6),
        'sold'            => round($sold, 6),
        'avg_cost_nav'    => $avg_cost_nav,
        'total_invested'  => round((float)$total_invested, 2),
        'folio'           => $existing_folio,
        'as_of_date'      => $date,
    ]);

} catch (Exception $e) {
    echo json_encode(['available_units' => 0, 'error' => $e->getMessage()]);
}