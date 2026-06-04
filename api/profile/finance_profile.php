<?php
/**
 * WealthDash — ti001: Personal Finance Profile
 * File: api/profile/finance_profile.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id'];
switch($action){
    case 'fp_get':{
        $row=DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?",[$userId]);
        if(!$row){json_response(true,'ok',['profile'=>null]);break;}
        $row['goals']=json_decode($row['goals']??'[]',true);
        $row['income_sources']=json_decode($row['income_sources']??'[]',true);
        json_response(true,'ok',['profile'=>$row]); break;
    }
    case 'fp_save':{
        csrf_verify();
        $fields=['age'=>(int)($_POST['age']??0),'employment_type'=>clean($_POST['employment_type']??'salaried'),
            'annual_income'=>(float)($_POST['annual_income']??0),'tax_slab'=>clean($_POST['tax_slab']??'30'),
            'risk_profile'=>clean($_POST['risk_profile']??'moderate'),'investment_horizon'=>clean($_POST['investment_horizon']??'long'),
            'monthly_expenses'=>(float)($_POST['monthly_expenses']??0),'monthly_savings'=>(float)($_POST['monthly_savings']??0),
            'emergency_fund_months'=>(int)($_POST['emergency_fund_months']??0),'dependents'=>(int)($_POST['dependents']??0),
            'has_life_insurance'=>(int)($_POST['has_life_insurance']??0),'has_health_insurance'=>(int)($_POST['has_health_insurance']??0),
            'goals'=>$_POST['goals']??'[]','income_sources'=>$_POST['income_sources']??'[]','notes'=>clean($_POST['notes']??'')];
        $existing=DB::fetchVal("SELECT id FROM finance_profiles WHERE user_id=?",[$userId]);
        if($existing){
            $sets=implode(',',array_map(fn($k)=>"$k=?",array_keys($fields)));
            $params=array_values($fields); $params[]=$userId;
            DB::execute("UPDATE finance_profiles SET $sets,updated_at=NOW() WHERE user_id=?",$params);
        }else{
            $cols=implode(',',array_keys($fields));
            $ph=implode(',',array_fill(0,count($fields),'?'));
            $params=array_values($fields); $params[]=$userId;
            DB::execute("INSERT INTO finance_profiles($cols,user_id,created_at,updated_at)VALUES($ph,?,NOW(),NOW())",$params);
        }
        json_response(true,'Profile saved.'); break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
