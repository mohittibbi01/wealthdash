<?php
/**
 * WealthDash — t46: EPF Monthly Contribution Tracker
 * Actions: epf_monthly_summary|epf_monthly_entries|epf_fy_contribution|epf_salary_history|
 *          epf_monthly_entry_save|epf_monthly_entry_delete|epf_salary_update|epf_balance_sync
 */
defined('WEALTHDASH') or die('Direct access not permitted.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'epf_monthly_summary';

const EPF_EE_PCT=12.0; const EPF_ER_PCT=3.67; const EPF_EPS_PCT=8.33; const EPF_EPS_CAP=15000; const EPF_TAX_TH=250000; const EPF_RATE=8.25;

function _t46_fy(?string $d=null): array { $ts=$d?strtotime($d):time(); $yr=(int)date('Y',$ts); $mo=(int)date('n',$ts); $fys=$mo>=4?$yr:$yr-1; return ['fy_year'=>$fys,'fy_start'=>$fys.'-04-01','fy_end'=>($fys+1).'-03-31','fy_label'=>$fys.'-'.substr((string)($fys+1),2)]; }
function _t46_owned(int $u, int $a): ?array { return DB::fetchOne("SELECT ea.* FROM epf_accounts ea JOIN portfolios p ON p.id=ea.portfolio_id WHERE ea.id=? AND p.user_id=?",[$a,$u])?:null; }
function _t46_accounts(int $u): array { return DB::fetchAll("SELECT ea.id,ea.employer_name,ea.uan,ea.basic_salary,ea.interest_rate,ea.employee_contribution,ea.employer_contribution,ea.current_balance,ea.eps_balance,ea.joining_date,ea.vpf_rate,ea.is_active FROM epf_accounts ea JOIN portfolios p ON p.id=ea.portfolio_id WHERE p.user_id=? AND ea.is_active=1 ORDER BY ea.employer_name ASC",[$u]); }
function _t46_calc(float $b, float $v=0): array { $ee=round($b*EPF_EE_PCT/100,2); $vpf=round($b*$v/100,2); $er=round($b*EPF_ER_PCT/100,2); $eps=round(min($b,EPF_EPS_CAP)*EPF_EPS_PCT/100,2); return ['employee_epf'=>$ee,'vpf'=>$vpf,'employer_epf'=>$er,'eps'=>$eps,'total_employee'=>round($ee+$vpf,2),'total_employer'=>round($er+$eps,2),'total_credit'=>round($ee+$vpf+$er,2)]; }

switch ($action) {
    case 'epf_monthly_summary': {
        $aid = (int)($_GET['account_id']??0);
        $fy  = _t46_fy();
        $accs = $aid ? array_filter(_t46_accounts($userId),fn($a)=>$a['id']==$aid) : _t46_accounts($userId);
        if (!$accs) json_response(false,'No active EPF accounts.');
        $res = [];
        foreach (array_values($accs) as $a) {
            $ents = DB::fetchAll("SELECT * FROM epf_monthly_log WHERE epf_account_id=? AND log_month BETWEEN ? AND ? ORDER BY log_month ASC",[$a['id'],$fy['fy_start'],$fy['fy_end']]);
            $ee=$er=$vpf=$eps=$int=0; $lb=null;
            foreach ($ents as &$e) { $e['month_label']=date('M Y',strtotime($e['log_month'])); $ee+=(float)$e['employee_contribution']; $er+=(float)$e['employer_contribution']; $vpf+=(float)$e['vpf_contribution']; $eps+=(float)$e['eps_contribution']; $int+=(float)$e['interest_credited']; if($e['balance_after']) $lb=(float)$e['balance_after']; } unset($e);
            $ml=count($ents); $calc=_t46_calc((float)$a['basic_salary'],(float)($a['vpf_rate']??0)); $annEe=$ee+$vpf;
            $mr=max(0,12-$ml); $projEe=$mr*($calc['employee_epf']+$calc['vpf']);
            $res[] = ['account_id'=>$a['id'],'employer_name'=>$a['employer_name'],'uan'=>$a['uan'],'basic_salary'=>(float)$a['basic_salary'],'vpf_rate'=>(float)($a['vpf_rate']??0),'calc'=>$calc,'current_balance'=>$lb??(float)$a['current_balance'],'fy'=>$fy,'months_logged'=>$ml,'months_remaining'=>$mr,'entries'=>$ents,'totals'=>['employee'=>round($ee,2),'vpf'=>round($vpf,2),'employer'=>round($er,2),'eps'=>round($eps,2),'interest'=>round($int,2),'total_credit'=>round($ee+$vpf+$er,2)],'projected_fy'=>['total_ee_vpf'=>round($annEe+$projEe,2),'months_left'=>$mr,'will_hit_threshold'=>($annEe+$projEe)>EPF_TAX_TH],'tax_alert'=>$annEe>EPF_TAX_TH,'tax_excess'=>round(max(0,$annEe-EPF_TAX_TH),2),'tax_threshold'=>EPF_TAX_TH,'interest_rate'=>(float)($a['interest_rate']??EPF_RATE)];
        }
        json_response(true,'',['accounts'=>$res,'fy'=>$fy,'epf_rate'=>EPF_RATE]);
    }

    case 'epf_monthly_entries': {
        $aid=(int)($_GET['account_id']??0); $page=max(1,(int)($_GET['page']??1)); $pp=max(6,min(60,(int)($_GET['per_page']??12))); $off=($page-1)*$pp; $fy=clean($_GET['fy']??'');
        if (!$aid) json_response(false,'account_id required.');
        if (!_t46_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $w="WHERE epf_account_id=?"; $p=[$aid];
        if ($fy&&preg_match('/^(\d{4})-(\d{4})$/',$fy,$m)) { $w.=" AND log_month BETWEEN ? AND ?"; $p[]=$m[1].'-04-01'; $p[]=$m[2].'-03-31'; }
        $total=(int)(DB::fetchVal("SELECT COUNT(*) FROM epf_monthly_log {$w}",$p)??0);
        $pp2=array_merge($p,[$pp,$off]);
        $ents=DB::fetchAll("SELECT * FROM epf_monthly_log {$w} ORDER BY log_month DESC LIMIT ? OFFSET ?",$pp2);
        foreach ($ents as &$e) { $e['month_label']=date('M Y',strtotime($e['log_month'])); $e['net_credit']=round((float)$e['employee_contribution']+(float)$e['employer_contribution']+(float)$e['vpf_contribution'],2); } unset($e);
        $tp=$total>0?(int)ceil($total/$pp):1;
        json_response(true,'',['data'=>$ents,'pagination'=>['page'=>$page,'per_page'=>$pp,'total'=>$total,'total_pages'=>$tp,'has_prev'=>$page>1,'has_next'=>$page<$tp],'account_id'=>$aid]);
    }

    case 'epf_fy_contribution': {
        $aid=(int)($_GET['account_id']??0); $yrs=min(10,max(1,(int)($_GET['years']??5)));
        if (!$aid) json_response(false,'account_id required.');
        if (!_t46_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $curFY=_t46_fy(); $data=[];
        for ($i=0;$i<$yrs;$i++) { $fy=$curFY['fy_year']-$i; $fs=$fy.'-04-01'; $fe=($fy+1).'-03-31'; $fl=$fy.'-'.substr((string)($fy+1),2);
            $r=DB::fetchRow("SELECT COALESCE(SUM(employee_contribution),0) AS ee,COALESCE(SUM(vpf_contribution),0) AS vpf,COALESCE(SUM(employer_contribution),0) AS er,COALESCE(SUM(eps_contribution),0) AS eps,COALESCE(SUM(interest_credited),0) AS interest,COALESCE(MAX(balance_after),0) AS bal,COUNT(*) AS mo FROM epf_monthly_log WHERE epf_account_id=? AND log_month BETWEEN ? AND ?",[$aid,$fs,$fe]);
            $ae=(float)$r['ee']+(float)$r['vpf'];
            $data[] = ['fy_year'=>$fy,'fy_label'=>$fl,'ee'=>round((float)$r['ee'],2),'vpf'=>round((float)$r['vpf'],2),'er'=>round((float)$r['er'],2),'eps'=>round((float)$r['eps'],2),'interest'=>round((float)$r['interest'],2),'closing_balance'=>round((float)$r['bal'],2),'months_logged'=>(int)$r['mo'],'total_ee_vpf'=>round($ae,2),'over_threshold'=>$ae>EPF_TAX_TH]; }
        json_response(true,'',['account_id'=>$aid,'fy_data'=>$data,'tax_threshold'=>EPF_TAX_TH]);
    }

    case 'epf_salary_history': {
        $aid=(int)($_GET['account_id']??0);
        if (!$aid) json_response(false,'account_id required.');
        if (!_t46_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $rows=DB::fetchAll("SELECT * FROM epf_salary_log WHERE epf_account_id=? ORDER BY effective_date DESC",[$aid]);
        json_response(true,'',['account_id'=>$aid,'history'=>$rows,'count'=>count($rows)]);
    }

    case 'epf_monthly_entry_save': {
        require_csrf();
        $aid=(int)($_POST['account_id']??0); $lm=clean($_POST['log_month']??date('Y-m-01')); $basic=(float)($_POST['basic_salary']??0);
        $ee=(float)($_POST['employee_contribution']??0); $er=(float)($_POST['employer_contribution']??0); $eps=(float)($_POST['eps_contribution']??0); $vpf=(float)($_POST['vpf_contribution']??0);
        $bal=isset($_POST['balance_after'])&&$_POST['balance_after']!==''?(float)$_POST['balance_after']:null;
        $int=(float)($_POST['interest_credited']??0); $notes=substr(clean($_POST['notes']??''),0,255);
        if (!$aid) json_response(false,'account_id required.');
        if (!_t46_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $lm=date('Y-m-01',strtotime($lm));
        if ($basic>0&&$ee==0) { $c=_t46_calc($basic); $ee=$c['employee_epf']; $er=$c['employer_epf']; $eps=$c['eps']; }
        $tc=round($ee+$er+$vpf,2);
        DB::run("INSERT INTO epf_monthly_log (epf_account_id,log_month,basic_salary,employee_contribution,employer_contribution,eps_contribution,vpf_contribution,total_credit,balance_after,interest_credited,source,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary),employee_contribution=VALUES(employee_contribution),employer_contribution=VALUES(employer_contribution),eps_contribution=VALUES(eps_contribution),vpf_contribution=VALUES(vpf_contribution),total_credit=VALUES(total_credit),balance_after=COALESCE(VALUES(balance_after),balance_after),interest_credited=VALUES(interest_credited),notes=VALUES(notes)",[$aid,$lm,$basic,$ee,$er,$eps,$vpf,$tc,$bal,$int,'manual',$notes?:null]);
        if ($bal!==null) DB::run("UPDATE epf_accounts SET current_balance=? WHERE id=? AND (last_updated IS NULL OR last_updated<=?)",[$bal,$aid,$lm]);
        json_response(true,'Entry saved ✅',['account_id'=>$aid,'log_month'=>$lm,'total_credit'=>$tc]);
    }

    case 'epf_monthly_entry_delete': {
        require_csrf();
        $aid=(int)($_POST['account_id']??0); $lm=clean($_POST['log_month']??'');
        if (!$aid||!$lm) json_response(false,'account_id and log_month required.');
        if (!_t46_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $lm=date('Y-m-01',strtotime($lm)); $d=DB::run("DELETE FROM epf_monthly_log WHERE epf_account_id=? AND log_month=?",[$aid,$lm]);
        json_response((bool)$d,$d?'Entry deleted.':'Entry not found.');
    }

    case 'epf_salary_update': {
        require_csrf();
        $aid=(int)($_POST['account_id']??0); $basic=(float)($_POST['basic_salary']??0); $ed=clean($_POST['effective_date']??date('Y-m-01')); $vr=(float)($_POST['vpf_rate']??0); $notes=substr(clean($_POST['notes']??''),0,255);
        if (!$aid) json_response(false,'account_id required.');
        if (!_t46_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        if ($basic<=0) json_response(false,'Basic salary required.');
        $c=_t46_calc($basic,$vr);
        DB::run("INSERT INTO epf_salary_log (epf_account_id,basic_salary,effective_date,vpf_rate,notes) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary),vpf_rate=VALUES(vpf_rate),notes=VALUES(notes)",[$aid,$basic,$ed,$vr,$notes?:null]);
        DB::run("UPDATE epf_accounts SET basic_salary=?,employee_contribution=?,employer_contribution=?,eps_contribution=?,vpf_rate=? WHERE id=?",[$basic,$c['employee_epf'],$c['employer_epf'],$c['eps'],$vr,$aid]);
        json_response(true,'Salary updated.',['calc'=>$c,'effective_date'=>$ed]);
    }

    case 'epf_balance_sync': {
        require_csrf();
        $aid=(int)($_POST['account_id']??0); $bal=(float)($_POST['balance']??-1); $eps=(float)($_POST['eps_balance']??-1); $dt=clean($_POST['as_of_date']??date('Y-m-d'));
        if (!$aid) json_response(false,'account_id required.');
        if (!_t46_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $s=[]; $p=[];
        if ($bal>=0) { $s[]='current_balance=?'; $p[]=$bal; }
        if ($eps>=0) { $s[]='eps_balance=?'; $p[]=$eps; }
        if (!$s) json_response(false,'Provide balance or eps_balance.');
        $p[]=$aid; DB::run("UPDATE epf_accounts SET ".implode(', ',$s)." WHERE id=?",$p);
        if ($bal>=0) DB::run("UPDATE epf_monthly_log SET balance_after=? WHERE epf_account_id=? AND log_month=?",[$bal,$aid,date('Y-m-01')]);
        json_response(true,'Balance synced ✅',['as_of_date'=>$dt]);
    }

    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
