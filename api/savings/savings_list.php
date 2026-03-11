<?php
/**
 * WealthDash — Savings List (Accounts + Interest History)
 * GET /api/?action=savings_list&type=accounts|interest
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$type        = clean($_GET['type']        ?? 'accounts');
$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$accountId   = (int)($_GET['account_id']  ?? 0);

$portWhere = $isAdmin && !$portfolioId
    ? ''
    : ($portfolioId
        ? "AND p.id = {$portfolioId} AND (p.user_id={$userId} OR EXISTS (SELECT 1 FROM portfolio_members pm WHERE pm.portfolio_id=p.id AND pm.user_id={$userId}))"
        : "AND (p.user_id={$userId} OR EXISTS (SELECT 1 FROM portfolio_members pm WHERE pm.portfolio_id=p.id AND pm.user_id={$userId}))");

if ($type === 'accounts') {
    $rows = DB::fetchAll(
        "SELECT sa.*, p.name AS portfolio_name
         FROM savings_accounts sa
         JOIN portfolios p ON p.id = sa.portfolio_id
         WHERE sa.is_active = 1 {$portWhere}
         ORDER BY sa.bank_name ASC, sa.account_type ASC"
    );
    json_response(true, '', $rows);
}

if ($type === 'interest') {
    $acctCond = $accountId ? "AND si.account_id = {$accountId}" : '';
    $rows     = DB::fetchAll(
        "SELECT si.*, sa.bank_name, sa.account_number, sa.account_type
         FROM savings_interest si
         JOIN savings_accounts sa ON sa.id = si.account_id
         JOIN portfolios p ON p.id = sa.portfolio_id
         WHERE 1=1 {$portWhere} {$acctCond}
         ORDER BY si.interest_date DESC, si.id DESC
         LIMIT 200"
    );
    json_response(true, '', $rows);
}

json_response(false, 'Invalid type.');

