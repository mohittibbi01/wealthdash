<?php
/**
 * WealthDash — FD List
 * GET /api/?action=fd_list
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$status      = clean($_GET['status'] ?? '');

$portWhere = $isAdmin && !$portfolioId
    ? ''
    : ($portfolioId
        ? "AND p.id = {$portfolioId} AND (p.user_id = {$userId} OR EXISTS (SELECT 1 FROM portfolio_members pm WHERE pm.portfolio_id=p.id AND pm.user_id={$userId}))"
        : "AND (p.user_id = {$userId} OR EXISTS (SELECT 1 FROM portfolio_members pm WHERE pm.portfolio_id=p.id AND pm.user_id={$userId}))");

$statusCond = $status ? "AND fa.status = " . DB::pdo()->quote($status) : '';

$rows = DB::fetchAll(
    "SELECT fa.*, p.name AS portfolio_name,
            DATEDIFF(fa.maturity_date, CURDATE()) AS days_left,
            ROUND(fa.maturity_amount - fa.principal, 2) AS interest_earned
     FROM fd_accounts fa
     JOIN portfolios p ON p.id = fa.portfolio_id
     WHERE 1=1 {$portWhere} {$statusCond}
     ORDER BY fa.maturity_date ASC"
);

json_response(true, '', $rows);

