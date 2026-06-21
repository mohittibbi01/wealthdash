<?php
/**
 * WealthDash — t463: Property Portfolio API
 * File: api/property/property.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id'];

switch($action){
    case 'property_list':{
        $rows=DB::fetchAll("SELECT p.*,COALESCE((SELECT v.current_value FROM property_valuations v WHERE v.property_id=p.id ORDER BY v.valuation_date DESC LIMIT 1),p.purchase_price) AS current_value FROM properties p WHERE p.user_id=? ORDER BY p.purchase_date DESC",[$userId]);
        foreach($rows as &$r){$r['purchase_price']=(float)$r['purchase_price'];$r['current_value']=(float)$r['current_value'];$r['loan_outstanding']=(float)($r['loan_outstanding']??0);$r['equity']=round($r['current_value']-$r['loan_outstanding'],2);$r['gain']=round($r['current_value']-$r['purchase_price'],2);$r['gain_pct']=$r['purchase_price']>0?round($r['gain']/$r['purchase_price']*100,2):0;$years=$r['purchase_date']?max(0.1,(time()-strtotime($r['purchase_date']))/(365.25*86400)):1;$r['cagr']=$r['purchase_price']>0?round((pow($r['current_value']/$r['purchase_price'],1/$years)-1)*100,2):0;}
        json_response(true,'ok',['properties'=>$rows]);break;
    }
    case 'property_add':{
        csrf_verify();
        $name=clean($_POST['property_name']??'');$purchase=(float)($_POST['purchase_price']??0);
        if(!$name||!$purchase)json_response(false,'Property name and purchase price required.');
        DB::execute("INSERT INTO properties(user_id,property_name,property_type,address,area_sqft,purchase_price,purchase_date,loan_outstanding,monthly_rental,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())",[$userId,$name,clean($_POST['property_type']??'residential'),clean($_POST['address']??''),(float)($_POST['area_sqft']??0),$purchase,clean($_POST['purchase_date']??''),(float)($_POST['loan_outstanding']??0),(float)($_POST['monthly_rental']??0),clean($_POST['notes']??'')]);
        $newId=DB::lastInsertId();
        if($newId&&$purchase)DB::execute("INSERT INTO property_valuations(property_id,current_value,valuation_date,source,created_at) VALUES(?,?,?,?,NOW())",[$newId,$purchase,clean($_POST['purchase_date']??date('Y-m-d')),'purchase']);
        json_response(true,'Property added.',['id'=>$newId]);break;
    }
    case 'property_update':{
        csrf_verify();$id=(int)($_POST['id']??0);$own=DB::fetchVal("SELECT id FROM properties WHERE id=? AND user_id=?",[$id,$userId]);if(!$own)json_response(false,'Not found.');
        $sets=[];$params=[];foreach(['property_name','property_type','address','area_sqft','loan_outstanding','monthly_rental','notes','status'] as $f){if(isset($_POST[$f])){$sets[]="$f=?";$params[]=clean($_POST[$f]);}}
        if(!$sets)json_response(false,'Nothing.');$params[]=$id;DB::execute("UPDATE properties SET ".implode(',',$sets).",updated_at=NOW() WHERE id=?",$params);json_response(true,'Updated.');break;
    }
    case 'property_delete':{csrf_verify();$id=(int)($_POST['id']??0);$own=DB::fetchVal("SELECT id FROM properties WHERE id=? AND user_id=?",[$id,$userId]);if(!$own)json_response(false,'Not found.');DB::execute("DELETE FROM properties WHERE id=?",[$id]);json_response(true,'Deleted.');break;}
    case 'property_summary':{
        $rows=DB::fetchAll("SELECT p.property_type,COUNT(*) AS count,SUM(p.purchase_price) AS total_cost,SUM(COALESCE((SELECT v.current_value FROM property_valuations v WHERE v.property_id=p.id ORDER BY v.valuation_date DESC LIMIT 1),p.purchase_price)) AS total_value,SUM(p.loan_outstanding) AS total_loan,SUM(p.monthly_rental) AS total_rental FROM properties p WHERE p.user_id=? AND p.status='active' GROUP BY p.property_type",[$userId]);
        $tv=array_sum(array_column($rows,'total_value'));$tc=array_sum(array_column($rows,'total_cost'));$tl=array_sum(array_column($rows,'total_loan'));$tr=array_sum(array_column($rows,'total_rental'));
        json_response(true,'ok',['by_type'=>$rows,'total_value'=>round($tv,2),'total_cost'=>round($tc,2),'total_equity'=>round($tv-$tl,2),'total_loan'=>round($tl,2),'total_rental'=>round($tr,2),'gain'=>round($tv-$tc,2),'gain_pct'=>$tc>0?round(($tv-$tc)/$tc*100,2):0,'rental_yield'=>$tv>0?round(($tr*12)/$tv*100,2):0]);break;
    }
    case 'property_valuation_add':{
        csrf_verify();$pid=(int)($_POST['property_id']??0);$val=(float)($_POST['current_value']??0);
        $own=DB::fetchVal("SELECT id FROM properties WHERE id=? AND user_id=?",[$pid,$userId]);if(!$own)json_response(false,'Not found.');if($val<=0)json_response(false,'Value required.');
        DB::execute("INSERT INTO property_valuations(property_id,current_value,valuation_date,source,notes,created_at) VALUES(?,?,?,?,?,NOW())",[$pid,$val,clean($_POST['valuation_date']??date('Y-m-d')),clean($_POST['source']??'manual'),clean($_POST['notes']??'')]);
        json_response(true,'Valuation added.');break;
    }
    case 'property_valuation_history':{
        $pid=(int)($_GET['property_id']??0);$own=DB::fetchVal("SELECT id FROM properties WHERE id=? AND user_id=?",[$pid,$userId]);if(!$own)json_response(false,'Not found.');
        $rows=DB::fetchAll("SELECT * FROM property_valuations WHERE property_id=? ORDER BY valuation_date ASC",[$pid]);json_response(true,'ok',['valuations'=>$rows]);break;
    }
    default:json_response(false,'Unknown action.',[],400);
}
