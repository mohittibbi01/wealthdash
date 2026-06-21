<?php
/**
 * WealthDash — t45: Recurring Deposit (RD) Tracker
 * Monthly installment RD with maturity calculation.
 * Actions: rd_list | rd_add | rd_edit | rd_delete | rd_maturity | rd_installment_log | rd_installment_save
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'rd_list';

function _rd_owned(int $uid, int $id): ?array {
    return DB::fetchOne("SELECT rd.* FROM rd_accounts rd JOIN portfolios p ON p.id=rd.portfolio_id WHERE rd.id=? AND p.user_id=?",[$id,$uid])?:null;
}

/**
 * RD Maturity Calculation (compound interest quarterly)
 * Formula: M = R × [(1+i)^n - 1] / [1-(1+i)^(-1/3)]
 * where i = quarterly rate = rate/(4*100), n = total quarters
 */
function _rd_maturity(float $monthly, float $annualRate, int $months): float {
    if($months<=0||$monthly<=0) return 0;
    $i=$annualRate/(4*100); $n=$months/3;
    if($i==0) return round($monthly*$months,2);
    $maturity=$monthly*(pow(1+$i,$n)-1)/(1-pow(1+$i,-1/3));
    return round($maturity,2);
}

function _rd_total_deposited(int $rdId): array {
    $row=DB::fetchRow("SELECT COUNT(*) AS cnt,COALESCE(SUM(amount),0) AS total FROM rd_installments WHERE rd_account_id=? AND status='paid'",[$rdId]);
    return ['count'=>(int)($row['cnt']??0),'total'=>(float)($row['total']??0)];
}

switch ($action) {
    case 'rd_list': {
        $pid=(int)($_GET['portfolio_id']??0);
        $w=$pid?"WHERE rd.portfolio_id=? AND p.user_id=?":'WHERE p.user_id=?';
        $pa=$pid?[$pid,$userId]:[$userId];
        $rows=DB::fetchAll("SELECT rd.*,p.name AS portfolio_name FROM rd_accounts rd JOIN portfolios p ON p.id=rd.portfolio_id {$w} ORDER BY rd.start_date DESC",$pa);
        foreach($rows as &$r) {
            $paid=_rd_total_deposited((int)$r['id']);
            $mat=_rd_maturity((float)$r['monthly_installment'],(float)$r['interest_rate'],(int)$r['tenure_months']);
            $r['total_deposited']=$paid['total']; $r['installments_paid']=$paid['count'];
            $r['maturity_amount']=$mat; $r['total_interest']=round($mat-($r['monthly_installment']*$r['tenure_months']),2);
            $r['progress_pct']=round($paid['count']/$r['tenure_months']*100,1);
            $r['days_to_maturity']=max(0,(int)ceil((strtotime($r['maturity_date'])-time())/86400));
            $r['account_masked']=$r['account_number']?'••••'.substr($r['account_number'],-4):null;
        } unset($r);
        $total=array_sum(array_column(array_filter($rows,fn($r)=>$r['status']==='active'),'total_deposited'));
        json_response(true,'',['accounts'=>$rows,'count'=>count($rows),'total_deposited'=>round($total,2)]);
    }
    case 'rd_add': {
        require_csrf();
        $pid=(int)($_POST['portfolio_id']??0); if(!$pid) json_response(false,'portfolio_id required.');
        $monthly=(float)($_POST['monthly_installment']??0); $rate=(float)($_POST['interest_rate']??0); $tenure=(int)($_POST['tenure_months']??0);
        if(!$monthly||!$rate||!$tenure) json_response(false,'monthly_installment, interest_rate, tenure_months required.');
        $start=clean($_POST['start_date']??date('Y-m-d'));
        $matDate=date('Y-m-d',strtotime("{$start} +{$tenure} months"));
        $mat=_rd_maturity($monthly,$rate,$tenure);
        $id=DB::insert("INSERT INTO rd_accounts (portfolio_id,bank_name,account_number,monthly_installment,interest_rate,tenure_months,start_date,maturity_date,maturity_amount,nominee,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'active')",[
            $pid,clean($_POST['bank_name']??''),clean($_POST['account_number']??'')?:null,$monthly,$rate,$tenure,$start,$matDate,$mat,clean($_POST['nominee']??'')?:null,clean($_POST['notes']??'')?:null
        ]);
        json_response(true,'RD added.',['id'=>(int)$id,'maturity_date'=>$matDate,'maturity_amount'=>$mat]);
    }
    case 'rd_edit': {
        require_csrf();
        $id=(int)($_POST['id']??0); if(!$id||!_rd_owned($userId,$id)) json_response(false,'Not found.',[],404);
        DB::run("UPDATE rd_accounts SET bank_name=?,account_number=?,monthly_installment=?,interest_rate=?,tenure_months=?,start_date=?,maturity_date=?,nominee=?,notes=? WHERE id=?",[clean($_POST['bank_name']??''),clean($_POST['account_number']??'')?:null,(float)($_POST['monthly_installment']??0),(float)($_POST['interest_rate']??0),(int)($_POST['tenure_months']??0),clean($_POST['start_date']??''),clean($_POST['maturity_date']??''),clean($_POST['nominee']??'')?:null,clean($_POST['notes']??'')?:null,$id]);
        json_response(true,'RD updated.');
    }
    case 'rd_delete': {
        require_csrf();
        $id=(int)($_POST['id']??0); if(!$id||!_rd_owned($userId,$id)) json_response(false,'Not found.',[],404);
        DB::run("DELETE FROM rd_accounts WHERE id=?",[$id]); json_response(true,'Deleted.');
    }
    case 'rd_maturity': {
        $monthly=(float)($_GET['monthly']??1000); $rate=(float)($_GET['rate']??7.0); $tenure=(int)($_GET['tenure']??12);
        $mat=_rd_maturity($monthly,$rate,$tenure);
        $totalDeposit=$monthly*$tenure;
        json_response(true,'',['monthly_installment'=>$monthly,'interest_rate'=>$rate,'tenure_months'=>$tenure,'total_deposited'=>$totalDeposit,'maturity_amount'=>$mat,'total_interest'=>round($mat-$totalDeposit,2),'effective_yield'=>$totalDeposit>0?round(($mat-$totalDeposit)/$totalDeposit*100,2):0]);
    }
    case 'rd_installment_log': {
        $id=(int)($_GET['rd_id']??0); if(!$id||!_rd_owned($userId,$id)) json_response(false,'Not found.',[],404);
        $logs=DB::fetchAll("SELECT * FROM rd_installments WHERE rd_account_id=? ORDER BY due_date ASC",[$id]);
        $paid=_rd_total_deposited($id);
        json_response(true,'',['installments'=>$logs,'summary'=>$paid]);
    }
    case 'rd_installment_save': {
        require_csrf();
        $rdId=(int)($_POST['rd_id']??0); if(!$rdId||!_rd_owned($userId,$rdId)) json_response(false,'Not found.',[],404);
        $dueDate=clean($_POST['due_date']??''); $paidDate=clean($_POST['paid_date']??null)?:null;
        $amt=(float)($_POST['amount']??0); $status=clean($_POST['status']??'paid');
        if(!in_array($status,['paid','pending','missed'])) $status='paid';
        DB::run("INSERT INTO rd_installments (rd_account_id,due_date,paid_date,amount,status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE paid_date=VALUES(paid_date),amount=VALUES(amount),status=VALUES(status)",[$rdId,$dueDate,$paidDate,$amt,$status]);
        json_response(true,'Installment saved.');
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
