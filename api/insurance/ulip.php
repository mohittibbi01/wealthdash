<?php
/**
 * WealthDash — t323: ULIP Tracker
 * File: api/insurance/ulip.php
 * Actions: ulip_list, ulip_add, ulip_update, ulip_delete, ulip_summary
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    case 'ulip_list': {
        $rows = DB::fetchAll(
            "SELECT u.*, COUNT(f.id) AS fund_count
             FROM ulip_policies u
             LEFT JOIN ulip_fund_values f ON f.ulip_id = u.id
             WHERE u.user_id=?
             GROUP BY u.id
             ORDER BY u.created_at DESC",
            [$userId]
        );
        foreach ($rows as &$r) {
            $r['premium_amount']   = (float)$r['premium_amount'];
            $r['sum_assured']      = (float)$r['sum_assured'];
            $r['current_fund_value']= (float)($r['current_fund_value'] ?? 0);
            $r['total_premium_paid']= (float)($r['total_premium_paid'] ?? 0);
            $r['gain'] = $r['current_fund_value'] - $r['total_premium_paid'];
            $r['gain_pct'] = $r['total_premium_paid'] > 0
                ? round($r['gain'] / $r['total_premium_paid'] * 100, 2) : 0;
        }
        json_response(true, 'ok', ['ulips' => $rows]);
        break;
    }

    case 'ulip_add': {
        csrf_verify();
        $name   = clean($_POST['policy_name']    ?? '');
        $ins    = clean($_POST['insurer']         ?? '');
        $polno  = clean($_POST['policy_number']   ?? '');
        $prem   = (float)($_POST['premium_amount']?? 0);
        $freq   = clean($_POST['premium_frequency']?? 'annual');
        $sa     = (float)($_POST['sum_assured']   ?? 0);
        $start  = clean($_POST['start_date']      ?? '');
        $mat    = clean($_POST['maturity_date']   ?? '') ?: null;
        $cv     = (float)($_POST['current_fund_value'] ?? 0);
        $tpp    = (float)($_POST['total_premium_paid'] ?? 0);
        $lock   = (int)($_POST['lock_in_years']   ?? 5);
        $notes  = clean($_POST['notes']           ?? '');
        if (!$name) json_response(false, 'Policy name required.');
        DB::execute(
            "INSERT INTO ulip_policies(user_id,policy_name,insurer,policy_number,premium_amount,premium_frequency,sum_assured,start_date,maturity_date,current_fund_value,total_premium_paid,lock_in_years,status,notes,created_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'active',?,NOW())",
            [$userId,$name,$ins,$polno,$prem,$freq,$sa,$start,$mat,$cv,$tpp,$lock,$notes]
        );
        json_response(true, 'ULIP added.', ['id' => DB::lastInsertId()]);
        break;
    }

    case 'ulip_update': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM ulip_policies WHERE id=? AND user_id=?", [$id,$userId]);
        if (!$own) json_response(false, 'Not found.');
        $allowed = ['policy_name','insurer','premium_amount','current_fund_value','total_premium_paid','maturity_date','status','notes'];
        $sets=[]; $params=[];
        foreach($allowed as $f){if(isset($_POST[$f])){$sets[]="$f=?";$params[]=clean($_POST[$f]);}}
        if(!$sets)json_response(false,'Nothing to update.');
        $params[]=$id;
        DB::execute("UPDATE ulip_policies SET ".implode(',',$sets).",updated_at=NOW() WHERE id=?", $params);
        json_response(true,'ULIP updated.');
        break;
    }

    case 'ulip_delete': {
        csrf_verify();
        $id=(int)($_POST['id']??0);
        $own=DB::fetchVal("SELECT id FROM ulip_policies WHERE id=? AND user_id=?",[$id,$userId]);
        if(!$own)json_response(false,'Not found.');
        DB::execute("DELETE FROM ulip_policies WHERE id=?",[$id]);
        json_response(true,'Deleted.');
        break;
    }

    case 'ulip_summary': {
        $rows=DB::fetchAll("SELECT SUM(premium_amount) AS total_prem,SUM(current_fund_value) AS total_cv,SUM(total_premium_paid) AS total_tpp,SUM(sum_assured) AS total_sa,COUNT(*) AS count FROM ulip_policies WHERE user_id=? AND status='active'",[$userId]);
        $r=$rows[0]??[];
        json_response(true,'ok',['total_premium'=>(float)$r['total_prem'],'total_fund_value'=>(float)$r['total_cv'],'total_invested'=>(float)$r['total_tpp'],'total_cover'=>(float)$r['total_sa'],'count'=>(int)$r['count'],'gain'=>round((float)$r['total_cv']-(float)$r['total_tpp'],2)]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
