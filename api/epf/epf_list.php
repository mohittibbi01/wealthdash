<?php
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}" : "AND p.user_id = {$userId}";

if ($action === 'epf_list') {
    $rows = DB::fetchAll(
        "SELECT ea.*, p.name AS portfolio_name,
                ROUND((ea.employee_contribution + ea.employer_contribution) * 12, 2) AS annual_contribution,
                ROUND(ea.current_balance * ea.interest_rate / 100, 2) AS annual_interest,
                TIMESTAMPDIFF(YEAR, ea.joining_date, CURDATE()) AS years_of_service
         FROM epf_accounts ea
         JOIN portfolios p ON p.id = ea.portfolio_id
         WHERE 1=1 {$portCond}
         ORDER BY ea.is_active DESC, ea.employer_name ASC"
    );
    json_response(true, '', $rows);
}
if ($action === 'epf_add') {
    $pId   = (int)($_POST['portfolio_id'] ?? 0);
    if (!can_access_portfolio($pId, $userId, $isAdmin)) json_response(false, 'Access denied.');
    $basic = (float)($_POST['basic_salary'] ?? 0);
    DB::run(
        "INSERT INTO epf_accounts (portfolio_id,uan,employer_name,employee_contribution,employer_contribution,eps_contribution,basic_salary,joining_date,current_balance,eps_balance,interest_rate,notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [$pId, clean($_POST['uan']??''), clean($_POST['employer_name']??''),
         (float)($_POST['employee_contribution'] ?? round($basic*0.12,2)),
         (float)($_POST['employer_contribution'] ?? round($basic*0.0367,2)),
         (float)($_POST['eps_contribution']      ?? round($basic*0.0833,2)),
         $basic, clean($_POST['joining_date']??'')?:null,
         (float)($_POST['current_balance']??0), (float)($_POST['eps_balance']??0),
         (float)($_POST['interest_rate']??8.15), clean($_POST['notes']??'')]
    );
    json_response(true, 'EPF account added.');
}
if ($action === 'epf_update_balance') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("UPDATE epf_accounts SET current_balance=?,eps_balance=?,updated_at=NOW() WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)",
        [(float)($_POST['current_balance']??0),(float)($_POST['eps_balance']??0),$id,$userId]);
    json_response(true, 'Balance updated.');
}
if ($action === 'epf_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("UPDATE epf_accounts SET is_active=0 WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)", [$id,$userId]);
    json_response(true, 'Deleted.');
}
