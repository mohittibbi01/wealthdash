<?php
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}" : "AND p.user_id = {$userId}";

if ($action === 'insurance_list') {
    $rows = DB::fetchAll(
        "SELECT ip.*, p.name AS portfolio_name,
                DATEDIFF(ip.next_premium_date, CURDATE()) AS days_to_premium,
                DATEDIFF(ip.maturity_date, CURDATE()) AS days_to_maturity
         FROM insurance_policies ip
         JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE ip.is_active = 1 {$portCond}
         ORDER BY ip.next_premium_date ASC, ip.policy_type ASC"
    );
    json_response(true, '', $rows);
}
if ($action === 'insurance_add') {
    $pId = (int)($_POST['portfolio_id'] ?? 0);
    if (!can_access_portfolio($pId, $userId, $isAdmin)) json_response(false, 'Access denied.');
    DB::run(
        "INSERT INTO insurance_policies (portfolio_id,policy_type,insurer,policy_number,sum_assured,annual_premium,premium_frequency,next_premium_date,start_date,maturity_date,nominee_name,notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [$pId, clean($_POST['policy_type']??'term'), clean($_POST['insurer']??''),
         clean($_POST['policy_number']??''), (float)($_POST['sum_assured']??0),
         (float)($_POST['annual_premium']??0), clean($_POST['premium_frequency']??'yearly'),
         clean($_POST['next_premium_date']??'')?:null, clean($_POST['start_date']??date('Y-m-d')),
         clean($_POST['maturity_date']??'')?:null, clean($_POST['nominee_name']??''), clean($_POST['notes']??'')]
    );
    json_response(true, 'Policy added.');
}
if ($action === 'insurance_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("UPDATE insurance_policies SET is_active=0 WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)", [$id,$userId]);
    json_response(true, 'Policy deleted.');
}
