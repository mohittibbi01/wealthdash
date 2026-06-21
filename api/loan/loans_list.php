<?php
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}" : "AND p.user_id = {$userId}";

if ($action === 'loans_list') {
    $rows = DB::fetchAll(
        "SELECT la.*, p.name AS portfolio_name,
                ROUND(la.outstanding * la.interest_rate / 100 / 12, 2) AS monthly_interest,
                DATEDIFF(DATE_ADD(la.start_date, INTERVAL la.tenure_months MONTH), CURDATE()) AS days_remaining
         FROM loan_accounts la
         JOIN portfolios p ON p.id = la.portfolio_id
         WHERE la.is_active = 1 {$portCond}
         ORDER BY la.emi_date ASC"
    );
    json_response(true, '', $rows);
}
if ($action === 'loans_add') {
    $pId = (int)($_POST['portfolio_id'] ?? 0);
    if (!can_access_portfolio($pId, $userId, $isAdmin)) json_response(false, 'Access denied.');
    $principal = (float)($_POST['principal'] ?? 0);
    DB::run(
        "INSERT INTO loan_accounts (portfolio_id,loan_type,lender,loan_number,principal,outstanding,interest_rate,emi_amount,emi_date,start_date,tenure_months,notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [$pId, clean($_POST['loan_type']??'personal'), clean($_POST['lender']??''),
         clean($_POST['loan_number']??''), $principal,
         (float)($_POST['outstanding']??$principal), (float)($_POST['interest_rate']??0),
         (float)($_POST['emi_amount']??0), (int)($_POST['emi_date']??5),
         clean($_POST['start_date']??date('Y-m-d')), (int)($_POST['tenure_months']??0),
         clean($_POST['notes']??'')]
    );
    json_response(true, 'Loan added.');
}
if ($action === 'loans_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("UPDATE loan_accounts SET is_active=0 WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)", [$id,$userId]);
    json_response(true, 'Loan deleted.');
}
if ($action === 'loans_update_outstanding') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("UPDATE loan_accounts SET outstanding=?,updated_at=NOW() WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)", [(float)($_POST['outstanding']??0),$id,$userId]);
    json_response(true, 'Updated.');
}
