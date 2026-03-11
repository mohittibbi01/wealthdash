<?php
/**
 * WealthDash — XIRR Debug (TEMP - delete after use)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json');
$currentUser = require_auth();

$db = DB::conn();

// Get all transactions
$stmt = $db->prepare("
    SELECT t.transaction_type, t.txn_date, t.units, t.nav, t.value_at_cost,
           f.scheme_name, f.latest_nav
    FROM mf_transactions t
    JOIN funds f ON f.id = t.fund_id
    JOIN portfolios p ON p.id = t.portfolio_id
    WHERE p.user_id = ?
    ORDER BY t.txn_date ASC
");
$stmt->execute([$currentUser['id']]);
$txns = $stmt->fetchAll();

// Get holdings
$hStmt = $db->prepare("
    SELECT h.total_units, h.total_invested, h.value_now, h.cagr,
           h.first_purchase_date, h.ltcg_date, f.latest_nav, f.min_ltcg_days
    FROM mf_holdings h
    JOIN funds f ON f.id = h.fund_id
    JOIN portfolios p ON p.id = h.portfolio_id
    WHERE p.user_id = ? AND h.is_active = 1
");
$hStmt->execute([$currentUser['id']]);
$holdings = $hStmt->fetchAll();

// Try XIRR calculation
$valueNow = (float)($holdings[0]['value_now'] ?? 0);
$xirrResult = xirr_from_txns($txns, $valueNow, date('Y-m-d'));

echo json_encode([
    'transactions' => $txns,
    'holdings'     => $holdings,
    'value_now'    => $valueNow,
    'xirr_result'  => $xirrResult,
    'today'        => date('Y-m-d'),
], JSON_PRETTY_PRINT);
