<?php
/**
 * WealthDash — t462: Premium Calendar
 * File: api/insurance/premium_calendar.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id'];

switch($action){
    case 'premium_calendar_get':{
        $year=(int)($_GET['year']??date('Y'));
        $policies=DB::fetchAll("SELECT id,policy_name,insurer,policy_type,premium_amount,premium_frequency,next_premium_date FROM insurance_policies WHERE user_id=? AND status='active'",[$userId]);
        $months=[];for($m=1;$m<=12;$m++)$months[$m]=[];
        foreach($policies as $p){
            if(!$p['next_premium_date'])continue;
            $mi=match($p['premium_frequency']){'monthly'=>1,'quarterly'=>3,'half_yearly'=>6,'annual'=>12,'single'=>0,default=>12};
            if($mi===0){$d=strtotime($p['next_premium_date']);if((int)date('Y',$d)===$year)$months[(int)date('n',$d)][]=['policy_id'=>(int)$p['id'],'policy_name'=>$p['policy_name'],'insurer'=>$p['insurer'],'policy_type'=>$p['policy_type'],'amount'=>(float)$p['premium_amount'],'due_date'=>date('Y-m-d',$d),'paid'=>false];continue;}
            $c=strtotime($p['next_premium_date']);
            while((int)date('Y',$c)>$year)$c=strtotime("-{$mi} months",$c);
            while((int)date('Y',$c)<$year)$c=strtotime("+{$mi} months",$c);
            $s=0;
            while((int)date('Y',$c)===$year&&$s<20){$months[(int)date('n',$c)][]=['policy_id'=>(int)$p['id'],'policy_name'=>$p['policy_name'],'insurer'=>$p['insurer']??'','policy_type'=>$p['policy_type'],'amount'=>(float)$p['premium_amount'],'due_date'=>date('Y-m-d',$c),'paid'=>false];$c=strtotime("+{$mi} months",$c);$s++;}
        }
        $paid=DB::fetchAll("SELECT policy_id,due_date FROM premium_payments WHERE user_id=? AND YEAR(due_date)=?",[$userId,$year]);
        $ps=[];foreach($paid as $pr)$ps["{$pr['policy_id']}_{$pr['due_date']}"]=true;
        foreach($months as &$entries)foreach($entries as &$e)$e['paid']=isset($ps["{$e['policy_id']}_{$e['due_date']}"]);
        $at=0;foreach($months as $entries)foreach($entries as $e)$at+=$e['amount'];
        json_response(true,'ok',['months'=>$months,'year'=>$year,'annual_total'=>round($at,2)]);
        break;
    }
    case 'premium_mark_paid':{
        csrf_verify();
        $pid=(int)($_POST['policy_id']??0);$dd=clean($_POST['due_date']??'');$amt=(float)($_POST['amount']??0);$pd=clean($_POST['paid_date']??date('Y-m-d'));$meth=clean($_POST['payment_method']??'');$notes=clean($_POST['notes']??'');
        $own=DB::fetchVal("SELECT id FROM insurance_policies WHERE id=? AND user_id=?",[$pid,$userId]);
        if(!$own)json_response(false,'Policy not found.');
        $ex=DB::fetchVal("SELECT id FROM premium_payments WHERE user_id=? AND policy_id=? AND due_date=?",[$userId,$pid,$dd]);
        if($ex)json_response(false,'Already marked as paid.');
        DB::execute("INSERT INTO premium_payments(user_id,policy_id,due_date,amount,paid_date,payment_method,notes,created_at) VALUES(?,?,?,?,?,?,?,NOW())",[$userId,$pid,$dd,$amt,$pd,$meth,$notes]);
        $pol=DB::fetchRow("SELECT next_premium_date,premium_frequency FROM insurance_policies WHERE id=?",[$pid]);
        if($pol&&$pol['next_premium_date']===$dd){$mi=match($pol['premium_frequency']){'monthly'=>1,'quarterly'=>3,'half_yearly'=>6,'annual'=>12,default=>12};if($mi>0){$nd=date('Y-m-d',strtotime("+{$mi} months",strtotime($dd)));DB::execute("UPDATE insurance_policies SET next_premium_date=? WHERE id=?",[$nd,$pid]);}}
        json_response(true,'Premium marked as paid! ✅');
        break;
    }
    case 'premium_history_list':{
        $pid=(int)($_GET['policy_id']??0);
        $where="pp.user_id=?";$params=[$userId];
        if($pid){$where.=" AND pp.policy_id=?";$params[]=$pid;}
        $rows=DB::fetchAll("SELECT pp.*,ip.policy_name,ip.insurer FROM premium_payments pp JOIN insurance_policies ip ON ip.id=pp.policy_id WHERE $where ORDER BY pp.paid_date DESC LIMIT 100",$params);
        json_response(true,'ok',['payments'=>$rows]);
        break;
    }
    case 'premium_payment_delete':{
        csrf_verify();
        $id=(int)($_POST['id']??0);
        $own=DB::fetchVal("SELECT id FROM premium_payments WHERE id=? AND user_id=?",[$id,$userId]);
        if(!$own)json_response(false,'Not found.');
        DB::execute("DELETE FROM premium_payments WHERE id=?",[$id]);
        json_response(true,'Removed.');
        break;
    }
    default:json_response(false,'Unknown action.',[],400);
}
