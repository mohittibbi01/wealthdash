<?php
/**
 * WealthDash — Stock Add Transaction (BUY/SELL/DIV/BONUS/SPLIT)
 * POST /api/?action=stocks_add
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/holding_calculator.php';

$portfolioId  = (int)($_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)$currentUser['id'] ?? 0);
$stockId      = (int)($_POST['stock_id'] ?? 0);
$txnType      = clean($_POST['txn_type'] ?? 'BUY');
$txnDate      = clean($_POST['txn_date'] ?? '');
$quantity     = (float)($_POST['quantity'] ?? 0);
$price        = (float)($_POST['price'] ?? 0);
$brokerage    = (float)($_POST['brokerage'] ?? 0);
$stt          = (float)($_POST['stt'] ?? 0);
$exchCharges  = (float)($_POST['exchange_charges'] ?? 0);
$notes        = clean($_POST['notes'] ?? '');
$newSymbol    = clean($_POST['new_symbol'] ?? '');
$newCompany   = clean($_POST['new_company'] ?? '');
$newExchange  = clean($_POST['new_exchange'] ?? 'NSE');
$newSector    = clean($_POST['new_sector'] ?? '');

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid portfolio.');
}
if (!in_array($txnType, ['BUY','SELL','DIV','BONUS','SPLIT'])) json_response(false, 'Invalid transaction type.');
if (!$txnDate || !validate_date($txnDate)) json_response(false, 'Invalid date.');

// If no stock_id, create new stock
if (!$stockId && $newSymbol) {
    $newSymbol = strtoupper(trim($newSymbol));
    $existing = DB::fetchVal("SELECT id FROM stock_master WHERE symbol = ? AND exchange = ?", [$newSymbol, $newExchange]);
    if ($existing) {
        $stockId = (int)$existing;
    } else {
        $stockId = (int)DB::insert(
            "INSERT INTO stock_master (exchange, symbol, company_name, sector) VALUES (?,?,?,?)",
            [$newExchange, $newSymbol, $newCompany ?: $newSymbol, $newSector ?: null]
        );
    }
}
if (!$stockId) json_response(false, 'Please select or enter a stock symbol.');
if ($quantity <= 0 && in_array($txnType, ['BUY','SELL','BONUS'])) json_response(false, 'Quantity required.');

$totalCharges = $brokerage + $stt + $exchCharges;
$valueAtCost  = in_array($txnType, ['BUY','BONUS','SPLIT']) ? round($quantity * $price + $totalCharges, 2) : round($quantity * $price, 2);

// DIV transaction (no quantity required)
if ($txnType === 'DIV') {
    $totalDiv = (float)($_POST['total_dividend'] ?? $price); // price field = amount per share
    $divId = DB::insert(
        "INSERT INTO stock_dividends (portfolio_id, stock_id, div_date, amount_per_share, total_shares, total_amount, dividend_fy)
         VALUES (?,?,?,?,?,?,?)",
        [$portfolioId, $stockId, $txnDate, $price, $quantity, $totalDiv, calculate_fy($txnDate)]
    );
    audit_log('stock_div', 'stock_dividends', (int)$divId);
    json_response(true, 'Dividend recorded.', ['id' => $divId]);
}

$txnMonth = (int)(new DateTime($txnDate))->format('n');
$txnYear  = (int)(new DateTime($txnDate))->format('Y');
$investFy = $txnMonth >= 4 ? "{$txnYear}-" . substr((string)($txnYear+1),2) : ($txnYear-1) . '-' . substr((string)$txnYear,2);

$id = DB::insert(
    "INSERT INTO stock_transactions (portfolio_id, stock_id, txn_type, txn_date, quantity, price, brokerage, stt, exchange_charges, value_at_cost, investment_fy, notes)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
    [$portfolioId, $stockId, $txnType, $txnDate, $quantity, $price, $brokerage, $stt, $exchCharges, $valueAtCost, $investFy, $notes ?: null]
);

HoldingCalculator::recalculate_stock_holding($portfolioId, $stockId);
audit_log('stock_' . strtolower($txnType), 'stock_transactions', (int)$id);

json_response(true, 'Stock transaction saved.', ['id' => $id]);

