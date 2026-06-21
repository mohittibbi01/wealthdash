<?php
/**
 * WealthDash — tj005: Annual Financial Review Wizard
 * File: api/tools/annual_review.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id'];
$portfolioId=get_user_portfolio_id($userId);
function _checklist():array{return['tax_planning'=>['label'=>'🧾 Tax Planning','items'=>[
    ['id'=>'tp1','label'=>'80C investments done (ELSS/PPF/NSC) — ₹1.5L limit'],
    ['id'=>'tp2','label'=>'80D health insurance premium paid'],
    ['id'=>'tp3','label'=>'80CCD(1B) NPS contribution — extra ₹50,000'],
    ['id'=>'tp4','label'=>'HRA documents collected'],
    ['id'=>'tp5','label'=>'Form 16 received from employer'],
    ['id'=>'tp6','label'=>'Advance tax liability reviewed'],
    ['id'=>'tp7','label'=>'26AS / AIS checked for mismatches'],
    ['id'=>'tp8','label'=>'LTCG booking reviewed — ₹1.25L exemption used']]],
'portfolio'=>['label'=>'📊 Portfolio Review','items'=>[
    ['id'=>'pf1','label'=>'Asset allocation reviewed vs target'],
    ['id'=>'pf2','label'=>'Rebalanced if drift > 5%'],
    ['id'=>'pf3','label'=>'Underperforming funds identified (3Y vs benchmark)'],
    ['id'=>'pf4','label'=>'SIP step-up done for the year'],
    ['id'=>'pf5','label'=>'Exit load & lock-in period checked'],
    ['id'=>'pf6','label'=>'Direct vs regular plan comparison done']]],
'insurance'=>['label'=>'🛡 Insurance Review','items'=>[
    ['id'=>'in1','label'=>'Life insurance sum assured adequate (10-15x income)'],
    ['id'=>'in2','label'=>'Health insurance renewed'],
    ['id'=>'in3','label'=>'Nominee details updated in all policies'],
    ['id'=>'in4','label'=>'Vehicle insurance renewed']]],
'goals'=>['label'=>'🎯 Goals & Loans','items'=>[
    ['id'=>'gl1','label'=>'Goal progress checked — on track?'],
    ['id'=>'gl2','label'=>'Home loan prepayment evaluated'],
    ['id'=>'gl3','label'=>'High-interest loans cleared'],
    ['id'=>'gl4','label'=>'Emergency fund topped up to 6 months'],
    ['id'=>'gl5','label'=>'Credit score checked (CIBIL)']]],
'estate'=>['label'=>'📝 Estate & Admin','items'=>[
    ['id'=>'es1','label'=>'Will updated or created'],
    ['id'=>'es2','label'=>'Nominee updated in MF, bank, demat accounts'],
    ['id'=>'es3','label'=>'Important documents organised']]],
'admin'=>['label'=>'⚙️ Financial Admin','items'=>[
    ['id'=>'ad1','label'=>'Bank account KYC up to date'],
    ['id'=>'ad2','label'=>'PAN-Aadhaar linked'],
    ['id'=>'ad3','label'=>'Unused accounts closed'],
    ['id'=>'ad4','label'=>'Recurring subscriptions reviewed'],
    ['id'=>'ad5','label'=>'Credit card statement audited']]]];}
switch($action){
    case 'annual_review_checklist':{
        $year=(int)($_GET['year']??date('Y'));
        $checklist=_checklist();
        $saved=DB::fetchRow("SELECT checked_items,notes,completed_at FROM annual_reviews WHERE user_id=? AND review_year=?",[$userId,$year]);
        $checkedItems=$saved?json_decode($saved['checked_items']??'[]',true):[];
        $totalItems=0; $checkedCount=0;
        foreach($checklist as &$section){foreach($section['items'] as &$item){$item['checked']=in_array($item['id'],$checkedItems);if($item['checked'])$checkedCount++;$totalItems++;}}
        $auto=[]; $sipC=(int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND status='active'",[$userId])??0);
        if($sipC>0)$auto[]=['id'=>'pf4','reason'=>$sipC.' active SIPs'];
        json_response(true,'ok',['checklist'=>$checklist,'year'=>$year,'checked_count'=>$checkedCount,'total_items'=>$totalItems,
            'progress_pct'=>$totalItems>0?round(($checkedCount/$totalItems)*100):0,'notes'=>$saved['notes']??'',
            'completed_at'=>$saved['completed_at']??null,'auto_checks'=>$auto]);
        break;
    }
    case 'annual_review_save':{
        csrf_verify();
        $year=(int)($_POST['year']??date('Y'));
        $ci=$_POST['checked_items']??'[]'; $notes=clean($_POST['notes']??''); $mc=(int)($_POST['mark_complete']??0);
        $existing=DB::fetchVal("SELECT id FROM annual_reviews WHERE user_id=? AND review_year=?",[$userId,$year]);
        if($existing){DB::execute("UPDATE annual_reviews SET checked_items=?,notes=?,".($mc?"completed_at=NOW(),":"")."updated_at=NOW() WHERE id=?",[$ci,$notes,$existing]);}
        else{DB::execute("INSERT INTO annual_reviews(user_id,review_year,checked_items,notes,completed_at,created_at,updated_at)VALUES(?,?,?,?,".($mc?'NOW()':'NULL').",NOW(),NOW())",[$userId,$year,$ci,$notes]);}
        json_response(true,'Review saved.'); break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
