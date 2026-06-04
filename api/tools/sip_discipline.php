<?php
/**
 * WealthDash — th002: SIP Discipline Score
 * File: api/tools/sip_discipline.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=$_GET['action']??$_POST['action']??''; $action=clean($action);
$userId=(int)$_SESSION['user_id'];
$portfolioId=get_user_portfolio_id($userId);
switch($action){
    case 'sip_discipline_score':{
        $months=min(max((int)($_GET['months']??12),3),36);
        $sips=DB::fetchAll("SELECT s.id,s.fund_name,s.sip_amount,s.sip_date,s.start_date,s.sip_frequency,s.status FROM mf_sips s WHERE s.user_id=? AND s.portfolio_id=? ORDER BY s.fund_name ASC",[$userId,$portfolioId]);
        if(!$sips){json_response(true,'No SIPs.',['overall_score'=>0,'grade'=>'N/A','sips'=>[],'streak'=>0]);break;}
        $endDate=new DateTime(); $startDate=(clone $endDate)->modify("-{$months} months");
        $results=[]; $totalExp=0; $totalExec=0; $overallStreak=PHP_INT_MAX;
        foreach($sips as $sip){
            $sipStart=new DateTime($sip['start_date']??'2000-01-01');
            $from=max($startDate,$sipStart);
            $expected=[]; $d=clone $from; $d->modify('first day of this month');
            while($d<=$endDate){$expected[]=$d->format('Y-m');$d->modify('+1 month');}
            $expCount=count($expected);
            $txns=DB::fetchAll("SELECT DATE_FORMAT(txn_date,'%Y-%m') AS month FROM mf_transactions WHERE user_id=? AND sip_id=? AND txn_date>=? AND txn_date<=? AND txn_type IN('sip','purchase') GROUP BY DATE_FORMAT(txn_date,'%Y-%m')",[$userId,$sip['id'],$from->format('Y-m-d'),$endDate->format('Y-m-d')]);
            $execMonths=array_column($txns,'month'); $execCount=count($execMonths);
            $missed=array_diff($expected,$execMonths);
            $consistency=$expCount>0?round(($execCount/$expCount)*100,1):0;
            $streak=0; $dm=clone $endDate; $dm->modify('first day of this month');
            while(true){$key=$dm->format('Y-m');if($dm<$from)break;if(in_array($key,$execMonths)){$streak++;$dm->modify('-1 month');}else break;}
            $streakRatio=$expCount>0?min(1,$streak/$expCount):0;
            $recentMiss=in_array($endDate->format('Y-m'),array_values($missed))?0:1;
            $sipScore=round(($consistency*0.60)+($streakRatio*100*0.20)+($recentMiss*100*0.20),1);
            $totalExp+=$expCount; $totalExec+=$execCount; $overallStreak=min($overallStreak,$streak);
            $results[]=['sip_id'=>(int)$sip['id'],'fund_name'=>$sip['fund_name'],'sip_amount'=>(float)$sip['sip_amount'],
                'expected_months'=>$expCount,'executed_months'=>$execCount,'missed_months'=>array_values($missed),
                'missed_count'=>count($missed),'consistency_pct'=>$consistency,'streak'=>$streak,
                'score'=>$sipScore,'grade'=>_dgrade($sipScore),'status'=>$sip['status']];
        }
        $oc=$totalExp>0?round(($totalExec/$totalExp)*100,1):0;
        if($overallStreak===PHP_INT_MAX)$overallStreak=0;
        json_response(true,'ok',['overall_score'=>round($oc,1),'grade'=>_dgrade($oc),'streak'=>$overallStreak,
            'total_expected'=>$totalExp,'total_executed'=>$totalExec,'total_missed'=>$totalExp-$totalExec,
            'lookback_months'=>$months,'sips'=>$results]);
        break;
    }
    case 'sip_discipline_history':{
        $months=min(max((int)($_GET['months']??12),3),36);
        $endDate=new DateTime(); $startDate=(clone $endDate)->modify("-{$months} months");
        $rows=DB::fetchAll("SELECT DATE_FORMAT(txn_date,'%Y-%m') AS month,COUNT(DISTINCT sip_id) AS sips_executed,SUM(amount) AS total_invested FROM mf_transactions WHERE user_id=? AND portfolio_id=? AND txn_date>=? AND txn_type IN('sip','purchase') GROUP BY DATE_FORMAT(txn_date,'%Y-%m') ORDER BY month ASC",[$userId,$portfolioId,$startDate->format('Y-m-d')]);
        json_response(true,'ok',['history'=>$rows]); break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
function _dgrade(float $s):string{return match(true){$s>=95=>'A+',$s>=85=>'A',$s>=75=>'B+',$s>=65=>'B',$s>=55=>'C',$s>=40=>'D',default=>'F'};}
