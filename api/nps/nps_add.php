<?php
/**
 * WealthDash — NPS Add Transaction
 * POST /api/?action=nps_add
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/holding_calculator.php';

$portfolioId     = (int)($_POST['portfolio_id'] ?? $_SESSION['selected_portfolio_id'] ?? 0);
$schemeId        = (int)($_POST['scheme_id'] ?? 0);
$tier            = clean($_POST['tier'] ?? 'tier1');
$contributionType= clean($_POST['contribution_type'] ?? 'SELF');
$txnDate         = clean($_POST['txn_date'] ?? '');
$units           = (float)($_POST['units'] ?? 0);
$nav             = (float)($_POST['nav'] ?? 0);
$amount          = (float)($_POST['amount'] ?? 0);
$notes           = clean($_POST['notes'] ?? '');

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid portfolio.');
}
if (!$schemeId) json_response(false, 'Please select an NPS scheme.');
if (!in_array($tier, ['tier1','tier2'])) json_response(false, 'Invalid tier.');
if (!in_array($contributionType, ['SELF','EMPLOYER'])) json_response(false, 'Invalid contribution type.');
if (!$txnDate || !validate_date($txnDate)) json_response(false, 'Invalid transaction date.');
if ($units <= 0) json_response(false, 'Units must be positive.');
if ($nav <= 0) json_response(false, 'NAV must be positive.');
if ($amount <= 0) $amount = round($units * $nav, 2);

// Calculate FY
$txnDateObj = new DateTime($txnDate);
$txnMonth   = (int)$txnDateObj->format('n');
$txnYear    = (int)$txnDateObj->format('Y');
$investmentFy = $txnMonth >= 4 ? "{$txnYear}-" . substr((string)($txnYear+1),2) : ($txnYear-1) . '-' . substr((string)$txnYear,2);

$id = DB::insert(
    "INSERT INTO nps_transactions (portfolio_id, scheme_id, tier, contribution_type, txn_date, units, nav, amount, investment_fy, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [$portfolioId, $schemeId, $tier, $contributionType, $txnDate, $units, $nav, $amount, $investmentFy, $notes ?: null]
);

// Recalculate holdings
HoldingCalculator::recalculate_nps_holding($portfolioId, $schemeId, $tier);
audit_log('nps_add', 'nps_transactions', (int)$id);

json_response(true, 'NPS contribution added.', ['id' => $id]);

