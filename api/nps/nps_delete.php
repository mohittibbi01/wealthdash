<?php
/**
 * WealthDash — NPS Delete Transaction
 * POST /api/?action=nps_delete
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/holding_calculator.php';

$id = (int)($_POST['id'] ?? 0);
if (!$id) json_response(false, 'Invalid ID.');

$txn = DB::fetchOne("SELECT t.*, p.user_id FROM nps_transactions t JOIN portfolios p ON p.id = t.portfolio_id WHERE t.id = ?", [$id]);
if (!$txn) json_response(false, 'Transaction not found.');
if (!$isAdmin && (int)$txn['user_id'] !== $userId) json_response(false, 'Access denied.');

$schemeId    = (int)$txn['scheme_id'];
$portfolioId = (int)$txn['portfolio_id'];
$tier        = $txn['tier'];

DB::query("DELETE FROM nps_transactions WHERE id = ?", [$id]);
HoldingCalculator::recalculate_nps_holding($portfolioId, $schemeId, $tier);
audit_log('nps_delete', 'nps_transactions', $id);

json_response(true, 'Contribution deleted.');

