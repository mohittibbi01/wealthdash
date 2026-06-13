<?php
/**
 * WealthDash — t422: Auto Sweep FD Tracker
 * Track bank auto-sweep FDs linked to savings accounts.
 * Auto-sweep: bank automatically creates FDs when savings balance crosses threshold.
 *
 * Actions (read):  sweep_list | sweep_summary | sweep_maturity
 * Actions (write): sweep_save | sweep_delete | sweep_mark_broken | sweep_balance_update
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'sweep_list';

function _swp_owned(int $uid, int $id): bool {
    return (bool)DB::fetchVal("SELECT sf.id FROM sweep_fds sf JOIN portfolios p ON p.id=sf.portfolio_id WHERE sf.id=? AND p.user_id=?",[$id,$uid]);
}
function _swp_mat(float $p, float $r, int $days): float { return round($p*(1+($r/100)*($days/365)),2); }

switch ($action) {
    case 'sweep_list': {
        $pid=(int)($_GET['portfolio_id']??0);
        $w=$pid?"WHERE sf.portfolio_id=? AND p.user_id=?":'WHERE p.user_id=?';
        $pa=$pid?[$pid,$userId]:[$userId];
        $rows=DB::fetchAll("SELECT sf.*,p.name AS portfolio_name,DATEDIFF(sf.maturity_date,CURDATE()) AS days_left FROM sweep_fds sf JOIN portfolios p ON p.id=sf.portfolio_id {$w} ORDER BY sf.maturity_date ASC",$pa);
        foreach($rows as &$r) {
            $days=(int)((strtotime($r['maturity_date'])-strtotime($r['sweep_date']))/86400);
            $r['maturity_amount_calc']=_swp_mat((float)$r['principal'],(float)$r['interest_rate'],$days);
            $r['accrued_interest']=round(_swp_mat((float)$r['principal'],(float)$r['interest_rate'],(int)max(0,(time()-strtotime($r['sweep_date']))/86400))-(float)$r['principal'],2);
            $r['is_maturing_soon']=(int)($r['days_left']??999)<=7&&$r['status']==='active';
        } unset($r);
        $active=array_filter($rows,fn($r)=>$r['status']==='active');
        json_response(true,'',['sweep_fds'=>$rows,'count'=>count($rows),'active_count'=>count($active),'total_principal'=>round(array_sum(array_column(array_values($active),'principal')),2)]);
    }
    case 'sweep_summary': {
        $row=DB::fetchRow("SELECT COUNT(*) AS total,SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,SUM(CASE WHEN status='active' THEN principal ELSE 0 END) AS active_principal,SUM(CASE WHEN status='broken' THEN 1 ELSE 0 END) AS broken FROM sweep_fds sf JOIN portfolios p ON p.id=sf.portfolio_id WHERE p.user_id=?",[$userId]);
        $maturing=DB::fetchAll("SELECT sf.id,sf.bank_name,sf.principal,sf.maturity_date,DATEDIFF(sf.maturity_date,CURDATE()) AS days_left FROM sweep_fds sf JOIN portfolios p ON p.id=sf.portfolio_id WHERE p.user_id=? AND sf.status='active' AND DATEDIFF(sf.maturity_date,CURDATE()) BETWEEN 0 AND 7 ORDER BY sf.maturity_date ASC",[$userId]);
        json_response(true,'',['summary'=>$row,'maturing_soon'=>$maturing]);
    }
    case 'sweep_save': {
        require_csrf();
        $pid=(int)($_POST['portfolio_id']??0); $id=(int)($_POST['id']??0);
        $f=['bank_name'=>clean($_POST['bank_name']??''),'savings_account_id'=>(int)($_POST['savings_account_id']??0)?:null,'principal'=>(float)($_POST['principal']??0),'interest_rate'=>(float)($_POST['interest_rate']??0),'sweep_date'=>clean($_POST['sweep_date']??date('Y-m-d')),'maturity_date'=>clean($_POST['maturity_date']??''),'sweep_threshold'=>(float)($_POST['sweep_threshold']??0)?:null,'auto_reverse'=>(int)(bool)($_POST['auto_reverse']??0),'notes'=>substr(clean($_POST['notes']??''),0,255)];
        if(!$f['principal']||!$f['maturity_date']) json_response(false,'principal and maturity_date required.');
        if($id) { $s=implode(',',array_map(fn($k)=>"{$k}=?",array_keys($f))); DB::run("UPDATE sweep_fds SET {$s} WHERE id=? AND portfolio_id=?",array_merge(array_values($f),[$id,$pid])); }
        else { $c=implode(',',array_keys($f)); $ph=implode(',',array_fill(0,count($f),'?')); $id=DB::insert("INSERT INTO sweep_fds (portfolio_id,{$c}) VALUES (?,{$ph})",array_merge([$pid],array_values($f))); }
        json_response(true,'Sweep FD saved.',['id'=>(int)$id]);
    }
    case 'sweep_delete': {
        require_csrf();
        $id=(int)($_POST['id']??0); if(!$id||!_swp_owned($userId,$id)) json_response(false,'Not found.',[],404);
        DB::run("DELETE FROM sweep_fds WHERE id=?",[$id]); json_response(true,'Deleted.');
    }
    case 'sweep_mark_broken': {
        require_csrf();
        $id=(int)($_POST['id']??0); $amt=(float)($_POST['amount_received']??0);
        if(!$id||!_swp_owned($userId,$id)) json_response(false,'Not found.',[],404);
        DB::run("UPDATE sweep_fds SET status='broken',amount_received=?,broken_date=CURDATE() WHERE id=?",[$amt?:null,$id]);
        json_response(true,'Marked as broken.');
    }
    case 'sweep_balance_update': {
        require_csrf();
        $id=(int)($_POST['id']??0); $bal=(float)($_POST['current_value']??0);
        if(!$id||!_swp_owned($userId,$id)) json_response(false,'Not found.',[],404);
        DB::run("UPDATE sweep_fds SET current_value=? WHERE id=?",[$bal,$id]);
        json_response(true,'Balance updated.');
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
