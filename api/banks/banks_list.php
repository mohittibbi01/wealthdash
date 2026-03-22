<?php
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}" : "AND p.user_id = {$userId}";

if ($action === 'banks_list') {
    $rows = DB::fetchAll(
        "SELECT ba.*, p.name AS portfolio_name,
                ROUND(ba.balance * ba.interest_rate / 100, 2) AS annual_interest
         FROM bank_accounts ba
         JOIN portfolios p ON p.id = ba.portfolio_id
         WHERE 1=1 {$portCond}
         ORDER BY ba.is_primary DESC, ba.bank_name ASC"
    );
    json_response(true, '', $rows);
}
if ($action === 'banks_add') {
    $pId = (int)($_POST['portfolio_id'] ?? 0);
    if (!can_access_portfolio($pId, $userId, $isAdmin)) json_response(false, 'Access denied.');
    DB::run(
        "INSERT INTO bank_accounts (portfolio_id,bank_name,account_type,account_number,ifsc_code,balance,interest_rate,is_primary,notes)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [$pId, clean($_POST['bank_name']??''), clean($_POST['account_type']??'savings'),
         clean($_POST['account_number']??''), clean($_POST['ifsc_code']??''),
         (float)($_POST['balance']??0), (float)($_POST['interest_rate']??4.0),
         (int)($_POST['is_primary']??0), clean($_POST['notes']??'')]
    );
    json_response(true, 'Bank account added.');
}
if ($action === 'banks_update_balance') {
    $id  = (int)($_POST['id'] ?? 0);
    $bal = (float)($_POST['balance'] ?? 0);
    DB::run("UPDATE bank_accounts SET balance=?,updated_at=NOW() WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)", [$bal,$id,$userId]);
    DB::run("INSERT INTO bank_balance_history (account_id,balance,recorded_at) VALUES (?,?,CURDATE()) ON DUPLICATE KEY UPDATE balance=?", [$id,$bal,$bal]);
    json_response(true, 'Balance updated.');
}
if ($action === 'banks_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("DELETE FROM bank_accounts WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)", [$id,$userId]);
    json_response(true, 'Deleted.');
}
