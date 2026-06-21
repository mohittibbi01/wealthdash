<?php
/**
 * WealthDash — t136: AIS / TIS Reconciliation — Income Tax Portal
 * AIS = Annual Information Statement | TIS = Taxpayer Information Summary
 *
 * Actions (read):  ais_list | ais_summary | ais_mismatch_report
 * Actions (write): ais_entry_save | ais_entry_delete | ais_import_json | ais_mark_feedback
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'ais_list';

function _ais_fy(?string $fy=null): array {
    if ($fy&&preg_match('/^(\d{4})-(\d{4})$/',$fy,$m)) return [$m[1].'-04-01',$m[2].'-03-31',$fy,(int)$m[1]];
    $yr=(int)date('n')>=4?(int)date('Y'):(int)date('Y')-1;
    return [$yr.'-04-01',($yr+1).'-03-31',$yr.'-'.($yr+1),$yr];
}

switch ($action) {
    case 'ais_list': {
        $fy=clean($_GET['fy']??''); [$fs,$fe,$fyl,$fyr]=_ais_fy($fy);
        $category=clean($_GET['category']??'');
        $w="WHERE user_id=? AND fy_year=?"; $p=[$userId,$fyr];
        if ($category) { $w.=" AND category=?"; $p[]=$category; }
        $rows=DB::fetchAll("SELECT * FROM ais_entries {$w} ORDER BY category ASC, transaction_date DESC",$p);
        $totals=[]; foreach($rows as $r) { $c=$r['category']; if(!isset($totals[$c])) $totals[$c]=0; $totals[$c]+=(float)$r['reported_amount']; }
        json_response(true,'',['fy'=>$fyl,'entries'=>$rows,'category_totals'=>$totals,'count'=>count($rows)]);
    }
    case 'ais_summary': {
        $fy=clean($_GET['fy']??''); [$fs,$fe,$fyl,$fyr]=_ais_fy($fy);
        $rows=DB::fetchAll("SELECT category,SUM(reported_amount) AS ais_total,SUM(user_declared_amount) AS user_total,COUNT(*) AS cnt,SUM(CASE WHEN feedback_status='incorrect' THEN 1 ELSE 0 END) AS disputes FROM ais_entries WHERE user_id=? AND fy_year=? GROUP BY category ORDER BY category",[$userId,$fyr]);
        $mismatches=array_filter($rows,fn($r)=>abs((float)$r['ais_total']-(float)$r['user_total'])>1);
        $totalReported=array_sum(array_column($rows,'ais_total'));
        json_response(true,'',['fy'=>$fyl,'categories'=>$rows,'total_ais_reported'=>round($totalReported,2),'mismatch_count'=>count($mismatches),'mismatches'=>array_values($mismatches),'itr_note'=>'AIS mein jo income hai vo ITR mein declare karo — mismatch pe notice aa sakta hai']);
    }
    case 'ais_mismatch_report': {
        $fy=clean($_GET['fy']??''); [$fs,$fe,$fyl,$fyr]=_ais_fy($fy);
        $rows=DB::fetchAll("SELECT * FROM ais_entries WHERE user_id=? AND fy_year=? AND ABS(reported_amount-COALESCE(user_declared_amount,0))>1 ORDER BY ABS(reported_amount-COALESCE(user_declared_amount,0)) DESC",[$userId,$fyr]);
        $totalGap=array_sum(array_map(fn($r)=>abs((float)$r['reported_amount']-(float)($r['user_declared_amount']??0)),$rows));
        json_response(true,'',['fy'=>$fyl,'mismatches'=>$rows,'total_gap'=>round($totalGap,2),'count'=>count($rows),'action_required'=>count($rows)>0]);
    }
    case 'ais_entry_save': {
        require_csrf();
        $id=(int)($_POST['id']??0); $fyr=(int)($_POST['fy_year']??date('Y')); $cat=clean($_POST['category']??'other');
        $desc=substr(clean($_POST['description']??''),0,255); $amt=(float)($_POST['reported_amount']??0);
        $userAmt=isset($_POST['user_declared_amount'])&&$_POST['user_declared_amount']!==''?(float)$_POST['user_declared_amount']:null;
        $deductor=substr(clean($_POST['deductor_name']??''),0,150); $tds=(float)($_POST['tds_deducted']??0);
        $txnDate=clean($_POST['transaction_date']??''); $feedback=clean($_POST['feedback_status']??'accepted');
        $notes=substr(clean($_POST['notes']??''),0,255);
        $validCats=['salary','interest','dividend','mutual_fund_redemption','property_sale','rental_income','other_income','tds_deducted','advance_tax','self_assessment_tax','refund','other'];
        $validFb=['accepted','incorrect','not_mine','duplicate','other'];
        if (!in_array($cat,$validCats)) $cat='other';
        if (!in_array($feedback,$validFb)) $feedback='accepted';
        if ($id) {
            DB::run("UPDATE ais_entries SET fy_year=?,category=?,description=?,reported_amount=?,user_declared_amount=?,deductor_name=?,tds_deducted=?,transaction_date=?,feedback_status=?,notes=? WHERE id=? AND user_id=?",[$fyr,$cat,$desc,$amt,$userAmt,$deductor?:null,$tds,$txnDate?:null,$feedback,$notes?:null,$id,$userId]);
        } else {
            $id=DB::insert("INSERT INTO ais_entries (user_id,fy_year,category,description,reported_amount,user_declared_amount,deductor_name,tds_deducted,transaction_date,feedback_status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)",[$userId,$fyr,$cat,$desc,$amt,$userAmt,$deductor?:null,$tds,$txnDate?:null,$feedback,$notes?:null]);
        }
        json_response(true,'AIS entry saved.',['id'=>(int)$id]);
    }
    case 'ais_entry_delete': {
        require_csrf();
        $id=(int)($_POST['id']??0); if(!$id) json_response(false,'id required.');
        $ok=DB::run("DELETE FROM ais_entries WHERE id=? AND user_id=?",[$id,$userId]);
        json_response((bool)$ok,$ok?'Deleted.':'Not found.');
    }
    case 'ais_import_json': {
        require_csrf();
        $raw=clean($_POST['json_data']??''); if(!$raw) json_response(false,'json_data required.');
        $data=json_decode($raw,true); if(!$data||!is_array($data)) json_response(false,'Invalid JSON format.');
        $fyr=(int)($_POST['fy_year']??date('Y')); $imp=0;
        foreach($data as $row) {
            if (!isset($row['category'],$row['amount'])) continue;
            DB::insert("INSERT IGNORE INTO ais_entries (user_id,fy_year,category,description,reported_amount,deductor_name,tds_deducted,transaction_date) VALUES (?,?,?,?,?,?,?,?)",[$userId,$fyr,$row['category']??'other',$row['description']??'',(float)$row['amount'],$row['deductor']??null,(float)($row['tds']??0),$row['date']??null]);
            $imp++;
        }
        json_response(true,"{$imp} entries imported.",['imported'=>$imp]);
    }
    case 'ais_mark_feedback': {
        require_csrf();
        $id=(int)($_POST['id']??0); $fb=clean($_POST['feedback_status']??'accepted');
        $valid=['accepted','incorrect','not_mine','duplicate','other'];
        if (!in_array($fb,$valid)) json_response(false,'Invalid feedback_status.');
        $ok=DB::run("UPDATE ais_entries SET feedback_status=? WHERE id=? AND user_id=?",[$fb,$id,$userId]);
        json_response((bool)$ok,$ok?'Feedback updated.':'Not found.');
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
