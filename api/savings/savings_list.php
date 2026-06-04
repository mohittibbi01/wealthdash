<?php
/**
 * WealthDash — t33: Savings Accounts List with Pagination
 */
defined('WEALTHDASH') or die('Direct access not permitted.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'savings_list';
$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId ? "AND p.id={$portfolioId} AND p.user_id={$userId}" : "AND p.user_id={$userId}";

function _sav_fy(): array { $yr=(int)date('n')>=4?(int)date('Y'):(int)date('Y')-1; return [$yr.'-04-01',($yr+1).'-03-31']; }

switch ($action) {
    case 'savings_list': {
        $page    = max(1,(int)($_GET['page']??1));
        $perPage = max(5,min(100,(int)($_GET['per_page']??25)));
        $offset  = ($page-1)*$perPage;
        $search  = clean($_GET['search']  ?? '');
        $sortBy  = clean($_GET['sort_by'] ?? 'current_balance');
        $sortDir = strtoupper(clean($_GET['sort_dir']??'DESC'))==='ASC'?'ASC':'DESC';
        $allowed = ['current_balance','bank_name','interest_rate','account_type','balance_date','created_at'];
        if (!in_array($sortBy,$allowed)) $sortBy = 'current_balance';

        $where = "WHERE sa.is_active=1 {$portCond}"; $params = [];
        if ($search) { $where .= " AND (sa.bank_name LIKE ? OR sa.account_type LIKE ?)"; $params[]="%{$search}%"; $params[]="%{$search}%"; }

        $total = (int)DB::fetchVal("SELECT COUNT(*) FROM savings_accounts sa JOIN portfolios p ON p.id=sa.portfolio_id {$where}",$params);
        $pageParams = array_merge($params,[$perPage,$offset]);
        $rows = DB::fetchAll("SELECT sa.*,p.name AS portfolio_name, ROUND(sa.current_balance*sa.interest_rate/100,2) AS est_annual_interest, DATEDIFF(CURDATE(),sa.balance_date) AS days_since_update FROM savings_accounts sa JOIN portfolios p ON p.id=sa.portfolio_id {$where} ORDER BY sa.{$sortBy} {$sortDir} LIMIT ? OFFSET ?",$pageParams);

        [$fyStart,$fyEnd] = _sav_fy();
        foreach ($rows as &$r) {
            $fi = (float)(DB::fetchVal("SELECT COALESCE(SUM(interest_amount),0) FROM savings_interest WHERE account_id=? AND interest_date BETWEEN ? AND ?",[$r['id'],$fyStart,$fyEnd])??0);
            $r['fy_interest_credited'] = $fi;
            $r['tds_applicable'] = $fi > 10000;
            $r['account_masked'] = $r['account_number'] ? '••••'.substr($r['account_number'],-4) : null;
            $r['is_stale'] = (int)($r['days_since_update']??0) > 90;
        } unset($r);

        $summary = DB::fetchRow("SELECT COALESCE(SUM(sa.current_balance),0) AS total_balance,COUNT(*) AS account_count FROM savings_accounts sa JOIN portfolios p ON p.id=sa.portfolio_id WHERE sa.is_active=1 {$portCond}",[]);
        $totalPages = $total>0?(int)ceil($total/$perPage):1;

        json_response(true,'',['data'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>$totalPages,'has_prev'=>$page>1,'has_next'=>$page<$totalPages,'from'=>$total?$offset+1:0,'to'=>min($total,$offset+$perPage)],'filters'=>['search'=>$search,'sort_by'=>$sortBy,'sort_dir'=>$sortDir],'summary'=>['total_balance'=>round((float)($summary['total_balance']??0),2),'account_count'=>(int)($summary['account_count']??0)]]);
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
