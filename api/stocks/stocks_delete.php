<?php
/**
 * WealthDash — Delete Stock Transaction
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/holding_calculator.php';

$id = (int)($_POST['id'] ?? 0);
if (!$id) json_response(false, 'Invalid ID.');

$txn = DB::fetchOne("SELECT t.*, p.user_id FROM stock_transactions t JOIN portfolios p ON p.id=t.portfolio_id WHERE t.id=?", [$id]);
if (!$txn)                                           json_response(false, 'Transaction not found.');
if (!$isAdmin && (int)$txn['user_id'] !== $userId)   json_response(false, 'Access denied.');

$stockId    = (int)$txn['stock_id'];
$portfolioId = (int)$txn['portfolio_id'];

DB::query("DELETE FROM stock_transactions WHERE id=?", [$id]);
HoldingCalculator::recalculate_stock_holding($portfolioId, $stockId);
audit_log('stock_delete', 'stock_transactions', $id);

json_response(true, 'Transaction deleted.');

