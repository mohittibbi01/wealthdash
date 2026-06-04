<?php
/**
 * WealthDash — t498/th003: Investment Calendar
 * File: api/tools/investment_calendar.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id'];
$portfolioId=get_user_portfolio_id($userId);

function _fy_range(string $fy):array{preg_match('/^(\d{4})-(\d{2})$/',$fy,$m);return[$m[1].'-04-01','20'.$m[2].'-03-31'];}
function _fmt(float $a):string{$abs=abs($a);$s=$a<0?'-':'';if($abs>=1e7)return $s.'₹'.number_format($abs/1e7,2).' Cr';if($abs>=1e5)return $s.'₹'.number_format($abs/1e5,2).' L';return $s.'₹'.number_format($abs,0);}

function _static_dates(string $fy):array{
    preg_match('/^(\d{4})-(\d{2})$/',$fy,$m);$y=$m[1];$ny='20'.$m[2];
    return[
        ['date'=>"$y-04-01",'type'=>'tax','category'=>'fiscal','title'=>"FY Start — $fy",'desc'=>'New financial year begins.','priority'=>'info'],
        ['date'=>"$y-06-15",'type'=>'tax','category'=>'advance_tax','title'=>'Advance Tax Q1 (15%)','desc'=>'15% of estimated tax due.','priority'=>'warning'],
        ['date'=>"$y-09-15",'type'=>'tax','category'=>'advance_tax','title'=>'Advance Tax Q2 (45%)','desc'=>'45% cumulative due.','priority'=>'warning'],
        ['date'=>"$y-12-15",'type'=>'tax','category'=>'advance_tax','title'=>'Advance Tax Q3 (75%)','desc'=>'75% cumulative due.','priority'=>'warning'],
        ['date'=>"$ny-03-15",'type'=>'tax','category'=>'advance_tax','title'=>'Advance Tax Q4 (100%)','desc'=>'100% advance tax due.','priority'=>'danger'],
        ['date'=>"$ny-07-31",'type'=>'tax','category'=>'itr','title'=>'ITR Filing Deadline','desc'=>"Last date to file ITR for FY $fy.",'priority'=>'danger'],
        ['date'=>"$ny-03-31",'type'=>'investment','category'=>'80c','title'=>'80C Investment Deadline','desc'=>'Last day for 80C investments. ₹1.5L limit.','priority'=>'danger'],
        ['date'=>"$ny-03-31",'type'=>'investment','category'=>'nps','title'=>'NPS 80CCD(1B) Deadline','desc'=>'Extra ₹50,000 NPS deduction.','priority'=>'warning'],
        ['date'=>"$y-09-30",'type'=>'review','category'=>'portfolio','title'=>'Mid-Year Portfolio Review','desc'=>'Review & rebalance portfolio.','priority'=>'info'],
        ['date'=>"$ny-03-01",'type'=>'review','category'=>'portfolio','title'=>'Year-End Portfolio Review','desc'=>'Tax-loss harvesting, 80C top-up.','priority'=>'warning'],
        ['date'=>"$ny-03-31",'type'=>'tax','category'=>'fiscal','title'=>"FY End — $fy",'desc'=>'Financial year closes.','priority'=>'danger'],
    ];
}

function _sip_events(int $userId,int $portfolioId,string $fy):array{
    [$fyStart,$fyEnd]=_fy_range($fy);
    $sips=DB::fetchAll("SELECT fund_name,sip_amount,sip_date,sip_frequency,start_date,end_date FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId]);
    $events=[];
    foreach($sips as $s){$d=new DateTime($fyStart);$end=new DateTime(min($fyEnd,$s['end_date']??$fyEnd));
        while($d<=$end){$dom=(int)($s['sip_date']??1);$dt=$d->format('Y-m').'-'.str_pad($dom,2,'0',STR_PAD_LEFT);
            try{$dt=(new DateTime($dt))->format('Y-m-d');}catch(\Exception){$d->modify('+1 month');continue;}
            if($dt>=$fyStart&&$dt<=$fyEnd)$events[]=['date'=>$dt,'type'=>'sip','category'=>'sip','title'=>'SIP — '.($s['fund_name']??'Fund'),'desc'=>'SIP debit of '._fmt((float)$s['sip_amount']),'priority'=>'sip'];
            match(strtolower($s['sip_frequency']??'monthly')){'weekly'=>$d->modify('+1 week'),'fortnightly'=>$d->modify('+2 weeks'),'quarterly'=>$d->modify('+3 months'),'yearly'=>$d->modify('+1 year'),default=>$d->modify('+1 month')};}}
    return $events;
}

function _fd_events(int $userId,string $fy):array{
    [$s,$e]=_fy_range($fy);
    $rows=DB::fetchAll("SELECT fd_name,principal_amount,maturity_date,bank_name FROM fixed_deposits WHERE user_id=? AND maturity_date BETWEEN ? AND ? AND status='active'",[$userId,$s,$e]);
    return array_map(fn($r)=>['date'=>$r['maturity_date'],'type'=>'fd','category'=>'fd_maturity','title'=>'FD Maturity — '.($r['bank_name']??$r['fd_name']??'FD'),'desc'=>'FD of '._fmt((float)$r['principal_amount']).' matures.','priority'=>'warning'],$rows);
}

switch($action){
    case 'inv_calendar_events':{
        $fy=clean($_GET['fy']??$_POST['fy']??'2025-26');
        $events=array_merge(_static_dates($fy),_sip_events($userId,$portfolioId,$fy),_fd_events($userId,$fy));
        usort($events,fn($a,$b)=>strcmp($a['date'],$b['date']));
        json_response(true,'ok',['events'=>$events,'fy'=>$fy]); break;
    }
    case 'inv_calendar_month':{
        $month=clean($_GET['month']??date('Y-m'));
        $fy=(int)substr($month,5,2)>=4?substr($month,0,4).'-'.substr(substr($month,0,4)+1,-2):(substr($month,0,4)-1).'-'.substr(substr($month,0,4),-2);
        $events=array_merge(_static_dates($fy),_sip_events($userId,$portfolioId,$fy),_fd_events($userId,$fy));
        $filtered=array_values(array_filter($events,fn($e)=>substr($e['date'],0,7)===$month));
        json_response(true,'ok',['events'=>$filtered,'month'=>$month]); break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
