<?php
/**
 * WealthDash — ti004: Personal Finance Score
 * File: api/profile/finance_score.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id'];
$portfolioId=get_user_portfolio_id($userId);
switch($action){
    case 'finance_score':{
        $profile=DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?",[$userId]);
        $components=[]; $totalScore=0;
        // 1. Emergency Fund (15)
        $ef=(int)($profile['emergency_fund_months']??0);
        $efS=min(15,round(($ef/6)*15));
        $components[]=['name'=>'Emergency Fund','score'=>$efS,'max'=>15,'status'=>$ef>=6?'good':($ef>=3?'fair':'poor'),'detail'=>$ef.' months. Recommended: 6+','icon'=>'🆘'];
        $totalScore+=$efS;
        // 2. Insurance (15)
        $hl=(int)($profile['has_life_insurance']??0); $hh=(int)($profile['has_health_insurance']??0);
        $insS=($hl*7)+($hh*8);
        $components[]=['name'=>'Insurance','score'=>$insS,'max'=>15,'status'=>$insS>=12?'good':($insS>=7?'fair':'poor'),'detail'=>($hl?'✓':'✗').' Life '.($hh?'✓':'✗').' Health','icon'=>'🛡'];
        $totalScore+=$insS;
        // 3. Savings Rate (20)
        $income=(float)($profile['annual_income']??0)/12;
        $savings=(float)($profile['monthly_savings']??0);
        $sr=$income>0?($savings/$income)*100:0;
        $savS=min(20,round(($sr/30)*20));
        $components[]=['name'=>'Savings Rate','score'=>$savS,'max'=>20,'status'=>$sr>=20?'good':($sr>=10?'fair':'poor'),'detail'=>round($sr,1).'% of income. Target: 20%+','icon'=>'💰'];
        $totalScore+=$savS;
        // 4. Investment Diversity (20)
        $at=DB::fetchAll("SELECT DISTINCT asset_type FROM portfolio_holdings WHERE user_id=? AND portfolio_id=?",[$userId,$portfolioId]);
        $fd=(int)(DB::fetchVal("SELECT COUNT(*) FROM fixed_deposits WHERE user_id=? AND status='active'",[$userId])??0);
        $cr=(int)(DB::fetchVal("SELECT COUNT(*) FROM cold_wallets WHERE user_id=?",[$userId])??0);
        $div=count($at)+($fd>0?1:0)+($cr>0?1:0);
        $divS=min(20,$div*4);
        $components[]=['name'=>'Investment Diversity','score'=>$divS,'max'=>20,'status'=>$div>=4?'good':($div>=2?'fair':'poor'),'detail'=>$div.' asset classes tracked','icon'=>'📊'];
        $totalScore+=$divS;
        // 5. SIP Discipline (15)
        $sipT=(int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
        $sipE=(int)(DB::fetchVal("SELECT COUNT(DISTINCT sip_id) FROM mf_transactions WHERE user_id=? AND txn_date>=DATE_SUB(NOW(),INTERVAL 3 MONTH) AND txn_type IN('sip','purchase')",[$userId])??0);
        $sipC=$sipT>0?($sipE/($sipT*3))*100:0;
        $sipS=$sipT>0?min(15,round(($sipC/100)*15)):0;
        $components[]=['name'=>'SIP Discipline','score'=>$sipS,'max'=>15,'status'=>$sipC>=80?'good':($sipC>=50?'fair':'poor'),'detail'=>$sipT.' SIPs, '.round($sipC,1).'% consistency','icon'=>'📈'];
        $totalScore+=$sipS;
        // 6. Debt Management (15)
        $emi=(float)(DB::fetchVal("SELECT COALESCE(SUM(emi_amount),0) FROM loans WHERE user_id=? AND status='active'",[$userId])??0);
        $dr=$income>0?($emi/$income)*100:0;
        $debtS=$dr<=0?15:min(15,max(0,round(15-($dr/50)*15)));
        $components[]=['name'=>'Debt Management','score'=>$debtS,'max'=>15,'status'=>$dr<=20?'good':($dr<=40?'fair':'poor'),'detail'=>'EMI: '.round($dr,1).'% of income. Recommended: <20%','icon'=>'🏦'];
        $totalScore+=$debtS;
        $totalScore=min(100,$totalScore);
        $grade=match(true){$totalScore>=85=>['A+','🟢 Excellent!'],$totalScore>=70=>['A','🟢 Good.'],$totalScore>=55=>['B','🟡 Fair.'],$totalScore>=40=>['C','🟠 Below average.'],default=>['D','🔴 Needs improvement.']};
        json_response(true,'ok',['score'=>$totalScore,'grade'=>$grade[0],'message'=>$grade[1],'components'=>$components,'profile_set'=>!empty($profile)]);
        break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
