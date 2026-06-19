<?php
/**
 * WealthDash — t461: ULIP Tracker Full
 * File: api/insurance/ulip_full.php
 * Actions: ulip_fund_list, ulip_fund_add, ulip_fund_delete, ulip_switch, ulip_fund_history, ulip_performance
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {
    case 'ulip_fund_list': {
        $ulipId = (int)($_GET['ulip_id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM ulip_policies WHERE id=? AND user_id=?", [$ulipId,$userId]);
        if (!$own) json_response(false,'Policy not found.');
        $rows = DB::fetchAll("SELECT f1.fund_name, f1.units, f1.nav, f1.value_date, (f1.units*f1.nav) AS current_value FROM ulip_fund_values f1 WHERE f1.ulip_id=? AND f1.value_date=(SELECT MAX(f2.value_date) FROM ulip_fund_values f2 WHERE f2.ulip_id=f1.ulip_id AND f2.fund_name=f1.fund_name) ORDER BY current_value DESC", [$ulipId]);
        $total = array_sum(array_column($rows,'current_value'));
        foreach ($rows as &$r) { $r['units']=(float)$r['units'];$r['nav']=(float)$r['nav'];$r['current_value']=(float)$r['current_value'];$r['weight_pct']=$total>0?round($r['current_value']/$total*100,1):0; }
        json_response(true,'ok',['funds'=>$rows,'total_value'=>round($total,2)]);
        break;
    }
    case 'ulip_fund_add': {
        csrf_verify();
        $ulipId=(int)($_POST['ulip_id']??0);
        $own=DB::fetchVal("SELECT id FROM ulip_policies WHERE id=? AND user_id=?",[$ulipId,$userId]);
        if(!$own) json_response(false,'Policy not found.');
        $fn=clean($_POST['fund_name']??''); $units=(float)($_POST['units']??0); $nav=(float)($_POST['nav']??0); $vd=clean($_POST['value_date']??date('Y-m-d'));
        if(!$fn||!$units||!$nav) json_response(false,'Fund name, units and NAV required.');
        $ex=DB::fetchVal("SELECT id FROM ulip_fund_values WHERE ulip_id=? AND fund_name=? AND value_date=?",[$ulipId,$fn,$vd]);
        if($ex) DB::execute("UPDATE ulip_fund_values SET units=?,nav=? WHERE id=?",[$units,$nav,$ex]);
        else DB::execute("INSERT INTO ulip_fund_values(ulip_id,fund_name,units,nav,value_date,created_at) VALUES(?,?,?,?,?,NOW())",[$ulipId,$fn,$units,$nav,$vd]);
        _recalc($ulipId); json_response(true,'Fund value recorded.');
        break;
    }
    case 'ulip_fund_delete': {
        csrf_verify();
        $id=(int)($_POST['id']??0);
        $row=DB::fetchRow("SELECT f.ulip_id FROM ulip_fund_values f JOIN ulip_policies p ON p.id=f.ulip_id WHERE f.id=? AND p.user_id=?",[$id,$userId]);
        if(!$row) json_response(false,'Not found.');
        DB::execute("DELETE FROM ulip_fund_values WHERE id=?",[$id]);
        _recalc($row['ulip_id']); json_response(true,'Deleted.');
        break;
    }
    case 'ulip_switch': {
        csrf_verify();
        $ulipId=(int)($_POST['ulip_id']??0); $from=clean($_POST['from_fund']??''); $to=clean($_POST['to_fund']??''); $amt=(float)($_POST['amount']??0); $toNav=(float)($_POST['to_nav']??0); $sd=clean($_POST['switch_date']??date('Y-m-d'));
        $own=DB::fetchVal("SELECT id FROM ulip_policies WHERE id=? AND user_id=?",[$ulipId,$userId]);
        if(!$own) json_response(false,'Policy not found.'); if(!$from||!$to||!$amt||!$toNav) json_response(false,'All fields required.'); if($from===$to) json_response(false,'Funds must differ.');
        $fr=DB::fetchRow("SELECT units,nav FROM ulip_fund_values WHERE ulip_id=? AND fund_name=? ORDER BY value_date DESC LIMIT 1",[$ulipId,$from]);
        if(!$fr) json_response(false,'Source fund not found.');
        $fv=(float)$fr['units']*(float)$fr['nav'];
        if($amt>$fv) json_response(false,'Amount exceeds fund value.');
        $newFU=(float)$fr['units']-$amt/(float)$fr['nav'];
        DB::execute("INSERT INTO ulip_fund_values(ulip_id,fund_name,units,nav,value_date,created_at) VALUES(?,?,?,?,?,NOW())",[$ulipId,$from,$newFU,$fr['nav'],$sd]);
        $tr=DB::fetchRow("SELECT units FROM ulip_fund_values WHERE ulip_id=? AND fund_name=? ORDER BY value_date DESC LIMIT 1",[$ulipId,$to]);
        $newTU=($tr?(float)$tr['units']:0)+$amt/$toNav;
        DB::execute("INSERT INTO ulip_fund_values(ulip_id,fund_name,units,nav,value_date,created_at) VALUES(?,?,?,?,?,NOW())",[$ulipId,$to,$newTU,$toNav,$sd]);
        DB::execute("INSERT INTO ulip_switches(ulip_id,from_fund,to_fund,amount,switch_date,created_at) VALUES(?,?,?,?,?,NOW())",[$ulipId,$from,$to,$amt,$sd]);
        _recalc($ulipId); json_response(true,"Switched ".number_format($amt,2)." from {$from} to {$to}.");
        break;
    }
    case 'ulip_fund_history': {
        $ulipId=(int)($_GET['ulip_id']??0);
        $own=DB::fetchVal("SELECT id FROM ulip_policies WHERE id=? AND user_id=?",[$ulipId,$userId]);
        if(!$own) json_response(false,'Policy not found.');
        $rows=DB::fetchAll("SELECT value_date,fund_name,units,nav,(units*nav) AS value FROM ulip_fund_values WHERE ulip_id=? ORDER BY value_date ASC",[$ulipId]);
        $byDate=[];
        foreach($rows as $r) $byDate[$r['value_date']]=($byDate[$r['value_date']]??0)+(float)$r['value'];
        $history=array_map(fn($d,$v)=>['date'=>$d,'value'=>round($v,2)],array_keys($byDate),array_values($byDate));
        json_response(true,'ok',['history'=>$history,'raw'=>$rows]);
        break;
    }
    case 'ulip_performance': {
        $policies=DB::fetchAll("SELECT id,policy_name,current_fund_value,total_premium_paid,start_date FROM ulip_policies WHERE user_id=? AND status='active'",[$userId]);
        $result=[];
        foreach($policies as $p){$cv=(float)$p['current_fund_value'];$tp=(float)$p['total_premium_paid'];$years=$p['start_date']?max(0.1,(time()-strtotime($p['start_date']))/(365.25*86400)):1;$cagr=$tp>0&&$years>0?(pow($cv/$tp,1/$years)-1)*100:0;$result[]=['id'=>$p['id'],'policy_name'=>$p['policy_name'],'current_value'=>$cv,'invested'=>$tp,'gain'=>round($cv-$tp,2),'gain_pct'=>$tp>0?round(($cv-$tp)/$tp*100,2):0,'years'=>round($years,1),'cagr'=>round($cagr,2)];}
        json_response(true,'ok',['policies'=>$result]);
        break;
    }
    default: json_response(false,'Unknown action.',[],400);
}
function _recalc(int $ulipId): void {
    $total=(float)(DB::fetchVal("SELECT COALESCE(SUM(f1.units*f1.nav),0) FROM ulip_fund_values f1 WHERE f1.ulip_id=? AND f1.value_date=(SELECT MAX(f2.value_date) FROM ulip_fund_values f2 WHERE f2.ulip_id=f1.ulip_id AND f2.fund_name=f1.fund_name)",[$ulipId])??0);
    DB::execute("UPDATE ulip_policies SET current_fund_value=?,updated_at=NOW() WHERE id=?",[round($total,2),$ulipId]);
}
