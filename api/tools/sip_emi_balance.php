<?php
/**
 * WealthDash — t474: SIP vs EMI Monthly Load Analysis
 * File: api/tools/sip_emi_balance.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id'];
$portfolioId=get_user_portfolio_id($userId);
switch($action){
    case 'sip_emi_summary':{
        $today=date('Y-m-d');
        $sips=DB::fetchAll("SELECT mf_id,sip_amount,sip_date,sip_frequency,fund_name FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active' AND (end_date IS NULL OR end_date>=?) ORDER BY sip_date ASC",[$userId,$portfolioId,$today]);
        $emis=DB::fetchAll("SELECT id,loan_name,loan_type,emi_amount,emi_date,outstanding_principal FROM loans WHERE user_id=? AND status='active' AND (end_date IS NULL OR end_date>=?) ORDER BY emi_date ASC",[$userId,$today]);
        $sipMonthly=0;
        foreach($sips as &$s){$s['sip_amount']=(float)$s['sip_amount'];$freq=strtolower($s['sip_frequency']??'monthly');$mul=match($freq){'weekly'=>4.33,'fortnightly'=>2,'quarterly'=>0.33,'yearly'=>0.083,default=>1};$s['monthly_equivalent']=round($s['sip_amount']*$mul,2);$sipMonthly+=$s['monthly_equivalent'];}
        $emiMonthly=0;
        foreach($emis as &$e){$e['emi_amount']=(float)$e['emi_amount'];$emiMonthly+=$e['emi_amount'];}
        $totalLoad=$sipMonthly+$emiMonthly;
        $sipRatio=$totalLoad>0?round(($sipMonthly/$totalLoad)*100,1):0;
        $emiRatio=$totalLoad>0?round(($emiMonthly/$totalLoad)*100,1):0;
        // 12-month projection
        $projection=[];$d=new DateTime();
        for($i=0;$i<12;$i++){$key=$d->format('Y-m');$st=0;foreach($sips as $s){$ed=$s['end_date']??'2099-12-31';if($key<=substr($ed,0,7))$st+=($s['monthly_equivalent']??$s['sip_amount']);}
        $et=0;foreach($emis as $e){$ed=$e['end_date']??'2099-12-31';if($key<=substr($ed,0,7))$et+=$e['emi_amount'];}
        $projection[]=['month'=>$key,'label'=>$d->format('M Y'),'sip'=>round($st,2),'emi'=>round($et,2),'total'=>round($st+$et,2)];$d->modify('+1 month');}
        json_response(true,'ok',['sips'=>$sips,'emis'=>$emis,'sip_monthly'=>round($sipMonthly,2),'emi_monthly'=>round($emiMonthly,2),'total_load'=>round($totalLoad,2),'sip_ratio'=>$sipRatio,'emi_ratio'=>$emiRatio,'projection'=>$projection]);
        break;
    }
    case 'sip_emi_monthly_breakdown':{
        $fy=clean($_GET['fy']??$_POST['fy']??date('Y').'-'.substr(date('Y')+1,-2));
        preg_match('/^(\d{4})-(\d{2})$/',$fy,$m);
        $fyStart=$m[1].'-04-01'; $fyEnd='20'.$m[2].'-03-31';
        $sips=DB::fetchAll("SELECT sip_amount,sip_date,sip_frequency,fund_name,start_date,end_date FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId]);
        $emis=DB::fetchAll("SELECT emi_amount,emi_date,loan_name,start_date,end_date FROM loans WHERE user_id=? AND status='active'",[$userId]);
        $months=[]; $d=new DateTime($fyStart); $end=new DateTime($fyEnd);
        while($d<=$end){$key=$d->format('Y-m');$st=0;foreach($sips as $s){$sS=$s['start_date']??'2000-01-01';$sE=$s['end_date']??'2099-12-31';if($key>=substr($sS,0,7)&&$key<=substr($sE,0,7)){$freq=strtolower($s['sip_frequency']??'monthly');$mul=match($freq){'weekly'=>4.33,'fortnightly'=>2,'quarterly'=>0.33,'yearly'=>0.083,default=>1};$st+=(float)$s['sip_amount']*$mul;}}
        $et=0;foreach($emis as $e){$eS=$e['start_date']??'2000-01-01';$eE=$e['end_date']??'2099-12-31';if($key>=substr($eS,0,7)&&$key<=substr($eE,0,7))$et+=(float)$e['emi_amount'];}
        $months[]=['month'=>$key,'label'=>$d->format('M Y'),'sip'=>round($st,2),'emi'=>round($et,2),'total'=>round($st+$et,2)];$d->modify('+1 month');}
        json_response(true,'ok',['months'=>$months,'fy'=>$fy]);
        break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
