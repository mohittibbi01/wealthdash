<?php
/**
 * WealthDash — t340: EPFO UAN Integration
 * UAN-based EPF account management + passbook import.
 * (EPFO has no public REST API — this provides manual entry + CSV passbook import)
 *
 * Actions (read):  uan_profile_get | uan_passbook_list | uan_summary
 * Actions (write): uan_profile_save | uan_passbook_import | uan_passbook_entry_save | uan_passbook_delete
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'uan_profile_get';

function _uan_account_owned(int $uid, int $aid): ?array {
    return DB::fetchOne("SELECT ea.* FROM epf_accounts ea JOIN portfolios p ON p.id=ea.portfolio_id WHERE ea.id=? AND p.user_id=?",[$aid,$uid])?:null;
}

switch ($action) {
    case 'uan_profile_get': {
        $accounts=DB::fetchAll("SELECT ea.id,ea.uan,ea.employer_name,ea.current_balance,ea.eps_balance,ea.basic_salary,ea.joining_date,ea.is_active,ea.interest_rate,p.name AS portfolio_name FROM epf_accounts ea JOIN portfolios p ON p.id=ea.portfolio_id WHERE p.user_id=? AND ea.is_active=1 ORDER BY ea.employer_name",[$userId]);
        foreach ($accounts as &$a) {
            $a['uan_masked']=$a['uan']?'••••••'.substr($a['uan'],-4):'—';
            $a['total_balance']=round((float)$a['current_balance']+(float)$a['eps_balance'],2);
            $a['last_passbook_entry']=DB::fetchVal("SELECT MAX(log_month) FROM epf_monthly_log WHERE epf_account_id=?",[$a['id']]);
        } unset($a);
        json_response(true,'',['accounts'=>$accounts,'count'=>count($accounts),'epfo_url'=>'https://passbook.epfindia.gov.in/MemberPassBook/login','uan_help'=>'UAN: Universal Account Number — EPFO member portal se passbook download karo']);
    }
    case 'uan_summary': {
        $aid=(int)($_GET['account_id']??0); if(!$aid) json_response(false,'account_id required.');
        $a=_uan_account_owned($userId,$aid); if(!$a) json_response(false,'Account not found.',[],404);
        $fy=(int)date('n')>=4?(int)date('Y'):(int)date('Y')-1; $fs=$fy.'-04-01'; $fe=($fy+1).'-03-31';
        $fyData=DB::fetchRow("SELECT COALESCE(SUM(employee_contribution+vpf_contribution),0) AS ee,COALESCE(SUM(employer_contribution),0) AS er,COALESCE(SUM(eps_contribution),0) AS eps,COALESCE(SUM(interest_credited),0) AS interest,COUNT(*) AS months FROM epf_monthly_log WHERE epf_account_id=? AND log_month BETWEEN ? AND ?",[$aid,$fs,$fe]);
        $recent=DB::fetchAll("SELECT log_month,employee_contribution,employer_contribution,eps_contribution,vpf_contribution,total_credit,balance_after FROM epf_monthly_log WHERE epf_account_id=? ORDER BY log_month DESC LIMIT 6",[$aid]);
        json_response(true,'',['account'=>$a,'fy'=>['year'=>$fyr??$fy,'label'=>$fy.'-'.substr((string)($fy+1),2),'ee_contribution'=>round((float)($fyData['ee']??0),2),'er_contribution'=>round((float)($fyData['er']??0),2),'interest'=>round((float)($fyData['interest']??0),2),'months_logged'=>(int)($fyData['months']??0)],'recent_months'=>$recent]);
    }
    case 'uan_passbook_import': {
        require_csrf();
        $aid=(int)($_POST['account_id']??0); if(!$aid) json_response(false,'account_id required.');
        if(!_uan_account_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $raw='';
        if(!empty($_FILES['passbook']['tmp_name'])) $raw=file_get_contents($_FILES['passbook']['tmp_name']);
        elseif(!empty($_POST['passbook_text'])) $raw=$_POST['passbook_text'];
        if(!$raw) json_response(false,'Passbook file or text required.');
        // Parse EPFO passbook CSV format: Month,EE,ER,EPS,Interest,Balance
        $lines=array_filter(preg_split('/\r?\n/',trim($raw)),fn($l)=>trim($l)!=='');
        $imported=0; $skipped=0; $header=null;
        foreach($lines as $li=>$line) {
            $cols=str_getcsv($line,',','"','\\');
            if($li===0||count($cols)<5) { $header=$cols; continue; }
            // Try to parse month (YYYY-MM or MMM-YYYY)
            $rawMonth=trim($cols[0]??''); $dt=null;
            foreach(['Y-m','m/Y','M-Y','m-Y'] as $fmt) { $d=DateTime::createFromFormat($fmt,$rawMonth); if($d){$dt=$d->format('Y-m-01');break;} }
            if(!$dt) { $ts=strtotime("01 {$rawMonth}"); if($ts) $dt=date('Y-m-01',$ts); }
            if(!$dt){$skipped++;continue;}
            $ee=(float)preg_replace('/[^\d.]/','',($cols[1]??'0'));
            $er=(float)preg_replace('/[^\d.]/','',($cols[2]??'0'));
            $eps=(float)preg_replace('/[^\d.]/','',($cols[3]??'0'));
            $int=(float)preg_replace('/[^\d.]/','',($cols[4]??'0'));
            $bal=isset($cols[5])?(float)preg_replace('/[^\d.]/','',($cols[5])):null;
            DB::run("INSERT INTO epf_monthly_log (epf_account_id,log_month,employee_contribution,employer_contribution,eps_contribution,total_credit,interest_credited,balance_after,source) VALUES (?,?,?,?,?,?,?,?,'epfo_sync') ON DUPLICATE KEY UPDATE employee_contribution=VALUES(employee_contribution),employer_contribution=VALUES(employer_contribution),eps_contribution=VALUES(eps_contribution),total_credit=VALUES(total_credit),interest_credited=VALUES(interest_credited),balance_after=COALESCE(VALUES(balance_after),balance_after),source='epfo_sync'",[$aid,$dt,$ee,$er,$eps,round($ee+$er,2),$int,$bal]);
            if($bal) DB::run("UPDATE epf_accounts SET current_balance=? WHERE id=? AND (last_updated IS NULL OR last_updated<=?)",[$bal,$aid,$dt]);
            $imported++;
        }
        json_response(true,"{$imported} months imported.",['imported'=>$imported,'skipped'=>$skipped,'account_id'=>$aid]);
    }
    case 'uan_passbook_entry_save': {
        require_csrf();
        $aid=(int)($_POST['account_id']??0); if(!$aid) json_response(false,'account_id required.');
        if(!_uan_account_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        $lm=date('Y-m-01',strtotime(clean($_POST['log_month']??date('Y-m-01'))));
        $ee=(float)($_POST['employee_contribution']??0); $er=(float)($_POST['employer_contribution']??0);
        $eps=(float)($_POST['eps_contribution']??0); $int=(float)($_POST['interest_credited']??0);
        $bal=isset($_POST['balance_after'])&&$_POST['balance_after']!==''?(float)$_POST['balance_after']:null;
        DB::run("INSERT INTO epf_monthly_log (epf_account_id,log_month,employee_contribution,employer_contribution,eps_contribution,total_credit,interest_credited,balance_after,source) VALUES (?,?,?,?,?,?,?,?,'manual') ON DUPLICATE KEY UPDATE employee_contribution=VALUES(employee_contribution),employer_contribution=VALUES(employer_contribution),eps_contribution=VALUES(eps_contribution),total_credit=VALUES(total_credit),interest_credited=VALUES(interest_credited),balance_after=COALESCE(VALUES(balance_after),balance_after)",[$aid,$lm,$ee,$er,$eps,round($ee+$er,2),$int,$bal]);
        if($bal) DB::run("UPDATE epf_accounts SET current_balance=? WHERE id=? AND (last_updated IS NULL OR last_updated<=?)",[$bal,$aid,$lm]);
        json_response(true,'Entry saved.',['account_id'=>$aid,'log_month'=>$lm]);
    }
    case 'uan_profile_save': {
        require_csrf();
        $aid=(int)($_POST['account_id']??0); $uan=clean($_POST['uan']??'');
        if(!$aid) json_response(false,'account_id required.');
        if(!_uan_account_owned($userId,$aid)) json_response(false,'Account not found.',[],404);
        if($uan&&!preg_match('/^\d{12}$/',$uan)) json_response(false,'UAN must be 12 digits.');
        DB::run("UPDATE epf_accounts SET uan=? WHERE id=?",[$uan?:null,$aid]);
        json_response(true,'UAN updated.');
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
