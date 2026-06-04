<?php
/**
 * WealthDash — t33: FD List with Pagination
 * Actions: fd_list | fd_add | fd_delete | fd_mature | fd_maturity | fd_ladder
 */
defined('WEALTHDASH') or die('Direct access not permitted.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'fd_list';

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

function _fd_owned(int $userId, int $fdId): bool {
    return (bool)DB::fetchVal(
        "SELECT fa.id FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id WHERE fa.id=? AND p.user_id=?",
        [$fdId, $userId]
    );
}

function _fd_maturity_amount(float $principal, float $rate, string $compounding, string $s, string $e): float {
    $days  = max(1, (int)((strtotime($e) - strtotime($s)) / 86400));
    $years = $days / 365;
    $n = match($compounding) { 'monthly'=>12,'quarterly'=>4,'half_yearly'=>2,'yearly'=>1, default=>0 };
    if ($n > 0) return round($principal * pow(1 + ($rate/100)/$n, $n*$years), 2);
    return round($principal * (1 + ($rate/100)*$years), 2);
}

function _fd_accrued(float $p, float $r, string $c, string $s): float {
    $today = date('Y-m-d');
    if ($today <= $s) return 0.0;
    return _fd_maturity_amount($p, $r, $c, $s, $today) - $p;
}

switch ($action) {

    case 'fd_list': {
        $page    = max(1, (int)($_GET['page']    ?? 1));
        $perPage = max(5, min(100, (int)($_GET['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;
        $status  = clean($_GET['status']  ?? 'active');
        $search  = clean($_GET['search']  ?? '');
        $sortBy  = clean($_GET['sort_by'] ?? 'maturity_date');
        $sortDir = strtoupper(clean($_GET['sort_dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $allowed = ['maturity_date','start_date','principal_amount','interest_rate','bank_name','created_at'];
        if (!in_array($sortBy, $allowed)) $sortBy = 'maturity_date';

        $where = "WHERE 1=1 {$portCond}"; $params = [];
        if ($status !== 'all') { $where .= " AND fa.status=?"; $params[] = $status; }
        if ($search) { $where .= " AND (fa.bank_name LIKE ? OR fa.folio_number LIKE ?)"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

        $total = (int)DB::fetchVal("SELECT COUNT(*) FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id {$where}", $params);

        $pageParams = array_merge($params, [$perPage, $offset]);
        $rows = DB::fetchAll(
            "SELECT fa.*, p.name AS portfolio_name,
                    DATEDIFF(fa.maturity_date,CURDATE()) AS days_to_maturity,
                    DATEDIFF(CURDATE(),fa.start_date) AS days_held
             FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id
             {$where} ORDER BY fa.{$sortBy} {$sortDir} LIMIT ? OFFSET ?",
            $pageParams
        );

        $sumPrincipal = 0; $sumValue = 0;
        foreach ($rows as &$r) {
            $r['accrued_interest']     = _fd_accrued((float)$r['principal_amount'],(float)$r['interest_rate'],$r['compounding'],$r['start_date']);
            $r['current_value']        = round((float)$r['principal_amount'] + $r['accrued_interest'], 2);
            $r['maturity_amount_calc'] = _fd_maturity_amount((float)$r['principal_amount'],(float)$r['interest_rate'],$r['compounding'],$r['start_date'],$r['maturity_date']);
            $r['is_maturing_soon']     = (int)($r['days_to_maturity']??999) <= 30 && $r['status']==='active';
            $r['is_overdue']           = (int)($r['days_to_maturity']??1) < 0 && $r['status']==='active';
            $r['account_masked']       = $r['folio_number'] ? '••••'.substr($r['folio_number'],-4) : null;
            if ($r['status']==='active') { $sumPrincipal += (float)$r['principal_amount']; $sumValue += $r['current_value']; }
        }
        unset($r);

        $totalPages = $total > 0 ? (int)ceil($total/$perPage) : 1;
        json_response(true, '', [
            'data'       => $rows,
            'pagination' => ['page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>$totalPages,
                             'has_prev'=>$page>1,'has_next'=>$page<$totalPages,
                             'from'=>$total?$offset+1:0,'to'=>min($total,$offset+$perPage)],
            'filters'    => ['status'=>$status,'search'=>$search,'sort_by'=>$sortBy,'sort_dir'=>$sortDir],
            'summary'    => ['active_principal'=>round($sumPrincipal,2),'active_value'=>round($sumValue,2),'active_gain'=>round($sumValue-$sumPrincipal,2)],
        ]);
    }

    case 'fd_add': {
        require_csrf();
        $pId = (int)($_POST['portfolio_id'] ?? 0);
        $principal = (float)($_POST['principal_amount']??0);
        $rate      = (float)($_POST['interest_rate']??0);
        $startDate = clean($_POST['start_date']??date('Y-m-d'));
        $matDate   = clean($_POST['maturity_date']??'');
        $comp      = clean($_POST['compounding']??'quarterly');
        $fdType    = clean($_POST['fd_type']??'cumulative');
        if ($principal<=0) json_response(false,'Principal required.');
        if ($rate<=0)      json_response(false,'Interest rate required.');
        if (!$matDate)     json_response(false,'Maturity date required.');
        $matAmt = _fd_maturity_amount($principal,$rate,$comp,$startDate,$matDate);
        $id = DB::insert(
            "INSERT INTO fd_accounts (portfolio_id,bank_name,fd_type,principal_amount,interest_rate,compounding,start_date,maturity_date,maturity_amount,auto_renew,folio_number,nominee,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$pId,clean($_POST['bank_name']??''),$fdType,$principal,$rate,$comp,$startDate,$matDate,$matAmt,(int)(bool)($_POST['auto_renew']??0),clean($_POST['folio_number']??'')?:null,clean($_POST['nominee']??'')?:null,clean($_POST['notes']??'')?:null]
        );
        json_response(true,'FD added.',['id'=>(int)$id,'maturity_amount'=>$matAmt]);
    }

    case 'fd_delete': {
        require_csrf();
        $id = (int)($_POST['id']??0);
        if (!$id||!_fd_owned($userId,$id)) json_response(false,'FD not found.',[], 404);
        DB::run("DELETE FROM fd_accounts WHERE id=?",[$id]);
        json_response(true,'FD deleted.');
    }

    case 'fd_mature': {
        require_csrf();
        $id = (int)($_POST['id']??0);
        if (!$id||!_fd_owned($userId,$id)) json_response(false,'FD not found.',[], 404);
        DB::run("UPDATE fd_accounts SET status='matured',interest_earned=?,updated_at=NOW() WHERE id=?",[(float)($_POST['interest_earned']??0),$id]);
        json_response(true,'FD marked matured.');
    }

    case 'fd_maturity': {
        $id = (int)($_GET['id']??0);
        if (!$id||!_fd_owned($userId,$id)) json_response(false,'FD not found.',[], 404);
        $fd = DB::fetchOne("SELECT fa.* FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id WHERE fa.id=? AND p.user_id=?",[$id,$userId]);
        $matAmt  = _fd_maturity_amount((float)$fd['principal_amount'],(float)$fd['interest_rate'],$fd['compounding'],$fd['start_date'],$fd['maturity_date']);
        $accrued = _fd_accrued((float)$fd['principal_amount'],(float)$fd['interest_rate'],$fd['compounding'],$fd['start_date']);
        $tds     = $accrued>40000 ? round($accrued*0.10,2) : 0;
        json_response(true,'',array_merge($fd,['maturity_amount_calc'=>$matAmt,'total_interest'=>round($matAmt-(float)$fd['principal_amount'],2),'accrued_till_today'=>round($accrued,2),'days_to_maturity'=>max(0,(int)ceil((strtotime($fd['maturity_date'])-time())/86400)),'tds_estimate'=>$tds,'net_at_maturity'=>round($matAmt-$tds,2)]));
    }

    case 'fd_ladder': {
        $months = min(60,max(3,(int)($_GET['months']??24)));
        $toDate = date('Y-m-d',strtotime("+{$months} months"));
        $ladder = DB::fetchAll("SELECT fa.*,p.name AS portfolio_name,DATEDIFF(fa.maturity_date,CURDATE()) AS days_to_maturity FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id WHERE p.user_id=? AND fa.status='active' AND fa.maturity_date<=? ORDER BY fa.maturity_date ASC",[$userId,$toDate]);
        $total = 0; $buckets = [];
        foreach ($ladder as &$l) {
            $m = _fd_maturity_amount((float)$l['principal_amount'],(float)$l['interest_rate'],$l['compounding'],$l['start_date'],$l['maturity_date']);
            $l['maturity_amount_calc']=$m; $l['interest_total']=round($m-(float)$l['principal_amount'],2);
            $total += $m;
            $b = date('Y-m',strtotime($l['maturity_date'])); $buckets[$b]=($buckets[$b]??0)+$m;
        } unset($l); ksort($buckets);
        json_response(true,'',['ladder'=>$ladder,'monthly_buckets'=>$buckets,'total_maturing'=>round($total,2),'count'=>count($ladder),'through_months'=>$months]);
    }

    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
