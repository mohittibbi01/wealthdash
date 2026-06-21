<?php
/**
 * WealthDash — t49: Leave Encashment + LTA Tracker
 * Leave encashment exemption (u/s 10(10AA)) + LTA (Leave Travel Allowance) tracker.
 *
 * Rules:
 * Leave Encashment:
 *   - At retirement (govt): Fully exempt
 *   - At retirement (private): Exempt up to ₹25 lakh (post Budget 2023) or least of:
 *       (a) Actual leave encashment, (b) Average 10-month salary, (c) ₹25L, (d) Cash equivalent of leave
 *   - During service: Fully taxable
 *
 * LTA (Section 10(5)):
 *   - Exempt for domestic travel (economy air / AC1 train)
 *   - Block of 4 years — 2 journeys exempt
 *   - Current block: 2022-25
 *   - Carry forward: 1 journey to next block
 *
 * Actions (read):  lta_summary | leave_summary | leave_lta_fy
 * Actions (write): lta_entry_save | lta_entry_delete | leave_entry_save | leave_entry_delete
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'leave_lta_fy';

const LTA_BLOCK = ['2022-25'=>[2022,2023,2024,2025],'2018-21'=>[2018,2019,2020,2021],'2014-17'=>[2014,2015,2016,2017]];
const LEAVE_ENC_PRIVATE_LIMIT = 2500000; // ₹25L post Budget 2023

function _lla_fy(?string $fy=null): array {
    if($fy&&preg_match('/^(\d{4})-(\d{4})$/',$fy,$m)) return [$m[1].'-04-01',$m[2].'-03-31',$fy,(int)$m[1]];
    $yr=(int)date('n')>=4?(int)date('Y'):(int)date('Y')-1;
    return [$yr.'-04-01',($yr+1).'-03-31',$yr.'-'.($yr+1),$yr];
}

function _lla_current_lta_block(): string {
    $yr=(int)date('Y'); foreach(LTA_BLOCK as $b=>$years) { if(in_array($yr,$years)) return $b; } return '2022-25';
}

switch ($action) {
    case 'lta_summary': {
        $block=clean($_GET['block']??_lla_current_lta_block());
        $years=LTA_BLOCK[$block]??LTA_BLOCK['2022-25'];
        $entries=DB::fetchAll("SELECT * FROM lta_entries WHERE user_id=? AND YEAR(travel_date) IN (".implode(',',array_fill(0,count($years),'?')).") ORDER BY travel_date DESC",array_merge([$userId],$years));
        $claimedCount=count(array_filter($entries,fn($e)=>$e['is_claimed']));
        $totalClaimed=array_sum(array_column(array_filter($entries,fn($e)=>$e['is_claimed']),'claim_amount'));
        $totalExempt=array_sum(array_column(array_filter($entries,fn($e)=>$e['is_claimed']),'exempt_amount'));
        json_response(true,'',['block'=>$block,'block_years'=>$years,'entries'=>$entries,'summary'=>['journeys_taken'=>count($entries),'journeys_claimed'=>$claimedCount,'journeys_remaining'=>max(0,2-$claimedCount),'total_claimed'=>round((float)$totalClaimed,2),'total_exempt'=>round((float)$totalExempt,2)],'rules'=>['max_journeys_per_block'=>2,'eligible_transport'=>'Domestic only — Economy Air / AC1 Train','current_block'=>$block,'note'=>'2 journeys per 4-yr block. Carry 1 to next block if not availed.']]);
    }
    case 'leave_summary': {
        $rows=DB::fetchAll("SELECT * FROM leave_encashment WHERE user_id=? ORDER BY encashment_date DESC",[$userId]);
        $totalReceived=array_sum(array_column($rows,'amount_received'));
        $totalExempt=array_sum(array_column($rows,'exempt_amount'));
        $totalTaxable=array_sum(array_column($rows,'taxable_amount'));
        json_response(true,'',['entries'=>$rows,'summary'=>['total_received'=>round((float)$totalReceived,2),'total_exempt'=>round((float)$totalExempt,2),'total_taxable'=>round((float)$totalTaxable,2)],'exemption_limit'=>LEAVE_ENC_PRIVATE_LIMIT,'rules'=>['govt_employee'=>'Fully exempt at retirement','private_employee'=>'Exempt min of: actual, 10-month avg salary, ₹25L, leave encash amount','during_service'=>'Fully taxable']]);
    }
    case 'leave_lta_fy': {
        [$fs,$fe,$fyl,$fyr]=_lla_fy($_GET['fy']??null);
        $lta=DB::fetchAll("SELECT * FROM lta_entries WHERE user_id=? AND travel_date BETWEEN ? AND ?",[$userId,$fs,$fe]);
        $leave=DB::fetchAll("SELECT * FROM leave_encashment WHERE user_id=? AND encashment_date BETWEEN ? AND ?",[$userId,$fs,$fe]);
        $ltaTax=array_sum(array_column($lta,'taxable_amount')); $leaveTax=array_sum(array_column($leave,'taxable_amount'));
        json_response(true,'',['fy'=>$fyl,'lta_entries'=>$lta,'leave_entries'=>$leave,'total_taxable_lta'=>round((float)$ltaTax,2),'total_taxable_leave'=>round((float)$leaveTax,2),'itr_section'=>'Schedule S (Salary Income) — declare exempt in ITR Sec 10(5) and 10(10AA)']);
    }
    case 'lta_entry_save': {
        require_csrf();
        $id=(int)($_POST['id']??0); $travelDate=clean($_POST['travel_date']??''); $destination=clean($_POST['destination']??'');
        $mode=clean($_POST['mode']??'train'); $actual=(float)($_POST['actual_fare']??0); $claimed=(int)(bool)($_POST['is_claimed']??0);
        $claimAmt=(float)($_POST['claim_amount']??$actual); $exemptAmt=(float)($_POST['exempt_amount']??$claimAmt);
        $taxableAmt=round(max(0,$actual-$exemptAmt),2); $notes=substr(clean($_POST['notes']??''),0,255);
        if(!in_array($mode,['air','train','bus','other'])) $mode='train';
        if($id) DB::run("UPDATE lta_entries SET travel_date=?,destination=?,mode_of_travel=?,actual_fare=?,is_claimed=?,claim_amount=?,exempt_amount=?,taxable_amount=?,notes=? WHERE id=? AND user_id=?",[$travelDate,$destination,$mode,$actual,$claimed,$claimAmt,$exemptAmt,$taxableAmt,$notes,$id,$userId]);
        else $id=DB::insert("INSERT INTO lta_entries (user_id,travel_date,destination,mode_of_travel,actual_fare,is_claimed,claim_amount,exempt_amount,taxable_amount,notes) VALUES (?,?,?,?,?,?,?,?,?,?)",[$userId,$travelDate,$destination,$mode,$actual,$claimed,$claimAmt,$exemptAmt,$taxableAmt,$notes]);
        json_response(true,'LTA entry saved.',['id'=>(int)$id]);
    }
    case 'lta_entry_delete': {
        require_csrf();
        $id=(int)($_POST['id']??0);
        $ok=DB::run("DELETE FROM lta_entries WHERE id=? AND user_id=?",[$id,$userId]);
        json_response((bool)$ok,$ok?'Deleted.':'Not found.');
    }
    case 'leave_entry_save': {
        require_csrf();
        $id=(int)($_POST['id']??0); $dt=clean($_POST['encashment_date']??''); $empType=clean($_POST['employer_type']??'private');
        $received=(float)($_POST['amount_received']??0); $avgSalary=(float)($_POST['avg_10month_salary']??0);
        $leaveBalance=(int)($_POST['leave_balance_days']??0);
        // Calculate exemption
        if($empType==='govt') { $exempt=$received; }
        else {
            $dailySalary=$avgSalary>0?$avgSalary/30:0;
            $optC=$avgSalary*10; $optD=$dailySalary*$leaveBalance;
            $exempt=min($received,$optC,LEAVE_ENC_PRIVATE_LIMIT,$optD>0?$optD:PHP_INT_MAX);
        }
        $taxable=round(max(0,$received-$exempt),2); $exempt=round($exempt,2);
        if($id) DB::run("UPDATE leave_encashment SET encashment_date=?,employer_type=?,amount_received=?,avg_10month_salary=?,leave_balance_days=?,exempt_amount=?,taxable_amount=?,notes=? WHERE id=? AND user_id=?",[$dt,$empType,$received,$avgSalary,$leaveBalance,$exempt,$taxable,clean($_POST['notes']??'')?:null,$id,$userId]);
        else $id=DB::insert("INSERT INTO leave_encashment (user_id,encashment_date,employer_type,amount_received,avg_10month_salary,leave_balance_days,exempt_amount,taxable_amount,notes) VALUES (?,?,?,?,?,?,?,?,?)",[$userId,$dt,$empType,$received,$avgSalary,$leaveBalance,$exempt,$taxable,clean($_POST['notes']??'')?:null]);
        json_response(true,'Leave encashment saved.',['id'=>(int)$id,'exempt_amount'=>$exempt,'taxable_amount'=>$taxable]);
    }
    case 'leave_entry_delete': {
        require_csrf();
        $id=(int)($_POST['id']??0);
        $ok=DB::run("DELETE FROM leave_encashment WHERE id=? AND user_id=?",[$id,$userId]);
        json_response((bool)$ok,$ok?'Deleted.':'Not found.');
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
