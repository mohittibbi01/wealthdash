<?php
/**
 * WealthDash — t48: PPF + NPS + EPF 80C Tracker
 * 80C limit: ₹1,50,000 | 80CCD(1B) extra NPS: ₹50,000
 * Actions: tax_80c_summary|tax_80c_gap_analysis|tax_80c_fy_detail|tax_80c_history|
 *          tax_80c_manual_save|tax_80c_manual_delete
 */
defined('WEALTHDASH') or die('Direct access not permitted.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'tax_80c_summary';

const T80C_LIMIT=150000; const T80C_NPS_EXTRA=50000; const T80C_MAX=200000;

function _80fy(?string $fy=null): array { if ($fy&&preg_match('/^(\d{4})-(\d{4})$/',$fy,$m)) return [(int)$m[1],$m[1].'-04-01',$m[2].'-03-31',$fy]; $yr=(int)date('n')>=4?(int)date('Y'):(int)date('Y')-1; return [$yr,$yr.'-04-01',($yr+1).'-03-31',$yr.'-'.($yr+1)]; }

function _80epf(int $uid, string $fs, string $fe): array {
    $accs=DB::fetchAll("SELECT ea.id,ea.employer_name,ea.uan,ea.employee_contribution,ea.vpf_rate,ea.basic_salary FROM epf_accounts ea JOIN portfolios p ON p.id=ea.portfolio_id WHERE p.user_id=? AND ea.is_active=1",[$uid]);
    $total=0; $det=[];
    foreach ($accs as $a) { $r=DB::fetchRow("SELECT COALESCE(SUM(employee_contribution+vpf_contribution),0) AS c FROM epf_monthly_log WHERE epf_account_id=? AND log_month BETWEEN ? AND ?",[$a['id'],$fs,$fe]); $c=(float)($r['c']??0); if($c==0){$mo=(float)$a['employee_contribution']+((float)$a['basic_salary']*(float)($a['vpf_rate']??0)/100); $c=round($mo*12,2);} $det[]=['account_id'=>$a['id'],'employer_name'=>$a['employer_name'],'uan'=>$a['uan'],'contribution'=>round($c,2),'source'=>($r['c']??0)>0?'monthly_log':'estimated']; $total+=$c; }
    return ['instrument'=>'EPF (Employee)','section'=>'80C','total'=>round(min($total,T80C_LIMIT),2),'actual_total'=>round($total,2),'details'=>$det,'note'=>'Employee EPF+VPF. Capped at ₹1.5L.','icon'=>'🏢'];
}

function _80ppf(int $uid, int $fyr, string $fs, string $fe): array {
    $accs=DB::fetchAll("SELECT po.id,po.holder_name,po.account_number,COALESCE(d.total_deposited,0) AS dep FROM po_schemes po JOIN portfolios p ON p.id=po.portfolio_id LEFT JOIN ppf_fy_deposits d ON d.ppf_scheme_id=po.id AND d.fy_year=? WHERE p.user_id=? AND LOWER(po.scheme_type)='ppf' AND po.status='active'",[$fyr,$uid]);
    $total=array_sum(array_column($accs,'dep'));
    $det=array_map(fn($a)=>['scheme_id'=>$a['id'],'holder_name'=>$a['holder_name'],'contribution'=>round((float)$a['dep'],2)],$accs);
    return ['instrument'=>'PPF','section'=>'80C','total'=>round(min($total,T80C_LIMIT),2),'actual_total'=>round($total,2),'details'=>$det,'max_limit'=>150000,'note'=>'PPF annual deposit. Max ₹1.5L.','icon'=>'🏦'];
}

function _80nps(int $uid, string $fs, string $fe): array {
    $self=(float)(DB::fetchVal("SELECT COALESCE(SUM(nt.amount),0) FROM nps_transactions nt JOIN nps_holdings nh ON nh.id=nt.holding_id JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=? AND nt.txn_type='contribution' AND nt.contributor='self' AND nt.txn_date BETWEEN ? AND ?",[$uid,$fs,$fe])??0);
    $er=(float)(DB::fetchVal("SELECT COALESCE(SUM(nt.amount),0) FROM nps_transactions nt JOIN nps_holdings nh ON nh.id=nt.holding_id JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=? AND nt.txn_type='contribution' AND nt.contributor='employer' AND nt.txn_date BETWEEN ? AND ?",[$uid,$fs,$fe])??0);
    $ccd1=round(min($self,T80C_LIMIT),2); $ccd1b=round(min(max(0,$self-T80C_LIMIT),T80C_NPS_EXTRA),2);
    return ['instrument'=>'NPS','section'=>'80C + 80CCD(1B)','self_contribution'=>round($self,2),'employer_contribution'=>round($er,2),'80ccd1_within_80c'=>$ccd1,'80ccd1b_extra'=>$ccd1b,'80ccd2_employer'=>round($er,2),'total_80c_part'=>$ccd1,'total_extra_80ccd1b'=>$ccd1b,'note'=>'NPS self: 80CCD(1) ₹1.5L + 80CCD(1B) ₹50K extra. Employer: 80CCD(2).','icon'=>'📊'];
}

function _80nsc(int $uid, string $fyl): array {
    $logs=DB::fetchAll("SELECT nl.amount,po.holder_name FROM nsc_80c_log nl JOIN po_schemes po ON po.id=nl.nsc_scheme_id JOIN portfolios p ON p.id=po.portfolio_id WHERE p.user_id=? AND nl.fy=?",[$uid,$fyl]);
    return ['instrument'=>'NSC (Deemed Interest)','section'=>'80C','total'=>round(array_sum(array_column($logs,'amount')),2),'details'=>$logs,'note'=>'NSC Year 1-4 deemed reinvestment.','icon'=>'📜'];
}

switch ($action) {
    case 'tax_80c_summary': {
        $fy=clean($_GET['fy']??''); [$fyr,$fs,$fe,$fyl]=_80fy($fy);
        $epf=_80epf($userId,$fs,$fe); $ppf=_80ppf($userId,$fyr,$fs,$fe); $nps=_80nps($userId,$fs,$fe); $nsc=_80nsc($userId,$fyl);
        $man=DB::fetchAll("SELECT * FROM tax_80c_manual_entries WHERE user_id=? AND fy_year=? AND is_active=1 ORDER BY category ASC",[$userId,$fyr]);
        $mt=array_sum(array_column($man,'amount'));
        $t80=$epf['total']+$ppf['total']+$nps['total_80c_part']+$nsc['total']+$mt;
        $u80=min($t80,T80C_LIMIT); $r80=max(0,T80C_LIMIT-$u80);
        $u1b=min($nps['80ccd1b_extra'],T80C_NPS_EXTRA); $r1b=max(0,T80C_NPS_EXTRA-$u1b);
        $td=$u80+$u1b;
        json_response(true,'',['fy'=>$fyl,'breakdown'=>['epf'=>$epf,'ppf'=>$ppf,'nps'=>$nps,'nsc'=>$nsc,'manual'=>['instrument'=>'Other','section'=>'80C','total'=>round($mt,2),'entries'=>$man,'icon'=>'📋']],'80c'=>['limit'=>T80C_LIMIT,'used'=>round($u80,2),'remaining'=>round($r80,2),'pct_used'=>round($u80/T80C_LIMIT*100,1),'overflow'=>round(max(0,$t80-T80C_LIMIT),2),'status'=>$r80<=0?'maxed':($r80<25000?'near_max':'under')],'80ccd1b'=>['limit'=>T80C_NPS_EXTRA,'used'=>round($u1b,2),'remaining'=>round($r1b,2),'pct_used'=>round($u1b/T80C_NPS_EXTRA*100,1),'status'=>$r1b<=0?'maxed':($u1b>0?'partial':'unused')],'totals'=>['total_80c_contributions'=>round($t80,2),'deduction_80c'=>round($u80,2),'deduction_80ccd1b'=>round($u1b,2),'total_deduction'=>round($td,2),'max_possible'=>T80C_MAX,'gap'=>round(T80C_MAX-$td,2),'est_tax_save_20pct'=>round($td*0.20*1.04,2),'est_tax_save_30pct'=>round($td*0.30*1.04,2)],'current_fy'=>$fyl]);
    }

    case 'tax_80c_gap_analysis': {
        $inc=max(0,(float)($_GET['income']??0)); $fy=clean($_GET['fy']??''); [$fyr,$fs,$fe,$fyl]=_80fy($fy);
        $epf=_80epf($userId,$fs,$fe); $ppf=_80ppf($userId,$fyr,$fs,$fe); $nps=_80nps($userId,$fs,$fe); $nsc=_80nsc($userId,$fyl);
        $mt=(float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM tax_80c_manual_entries WHERE user_id=? AND fy_year=? AND is_active=1",[$userId,$fyr])??0);
        $t80=$epf['total']+$ppf['total']+$nps['total_80c_part']+$nsc['total']+$mt;
        $g80=max(0,T80C_LIMIT-min($t80,T80C_LIMIT)); $u1b=min($nps['80ccd1b_extra']??0,T80C_NPS_EXTRA); $g1b=max(0,T80C_NPS_EXTRA-$u1b);
        $sl=30; if($inc>0){if($inc<=300000)$sl=0;elseif($inc<=600000)$sl=5;elseif($inc<=900000)$sl=10;elseif($inc<=1200000)$sl=15;elseif($inc<=1500000)$sl=20;}
        $sugg=[];
        if ($g80>0) { $pa=min($g80,150000-$ppf['total']); if($pa>0){$sugg[]=['priority'=>1,'instrument'=>'PPF','amount'=>round($pa,2),'section'=>'80C','tax_saving'=>round($pa*$sl/100*1.04,2),'note'=>"PPF ₹".number_format($pa,0)." deposit karo — EEE, no risk",'icon'=>'🏦']; $g80-=$pa;} if($g80>0){$sugg[]=['priority'=>2,'instrument'=>'ELSS','amount'=>round($g80,2),'section'=>'80C','tax_saving'=>round($g80*$sl/100*1.04,2),'note'=>"ELSS ₹".number_format($g80,0)." — 3yr lock-in, equity returns",'icon'=>'📈'];} }
        if ($g1b>0) $sugg[]=['priority'=>3,'instrument'=>'NPS 80CCD(1B)','amount'=>round($g1b,2),'section'=>'80CCD(1B)','tax_saving'=>round($g1b*$sl/100*1.04,2),'note'=>"NPS ₹50K extra — over 80C limit exclusively",'icon'=>'📊'];
        json_response(true,'',['fy'=>$fyl,'income'=>$inc,'slab_pct'=>$sl,'current_80c_used'=>round(min($t80,T80C_LIMIT),2),'current_80c_gap'=>round(max(0,T80C_LIMIT-min($t80,T80C_LIMIT)),2),'current_80ccd1b_used'=>round($u1b,2),'current_80ccd1b_gap'=>round($g1b,2),'suggestions'=>$sugg,'total_suggested'=>round(array_sum(array_column($sugg,'amount')),2),'potential_tax_saving'=>round(array_sum(array_column($sugg,'tax_saving')),2),'max_deduction_possible'=>T80C_MAX]);
    }

    case 'tax_80c_fy_detail': {
        $fy=clean($_GET['fy']??''); [$fyr,$fs,$fe,$fyl]=_80fy($fy);
        $epf=_80epf($userId,$fs,$fe); $ppf=_80ppf($userId,$fyr,$fs,$fe); $nps=_80nps($userId,$fs,$fe); $nsc=_80nsc($userId,$fyl);
        $man=DB::fetchAll("SELECT * FROM tax_80c_manual_entries WHERE user_id=? AND fy_year=? AND is_active=1",[$userId,$fyr]);
        $t80=$epf['total']+$ppf['total']+$nps['total_80c_part']+$nsc['total']+array_sum(array_column($man,'amount'));
        json_response(true,'',['fy'=>$fyl,'epf'=>$epf,'ppf'=>$ppf,'nps'=>$nps,'nsc'=>$nsc,'manual'=>$man,'total_80c'=>round(min($t80,T80C_LIMIT),2),'total_80ccd1b'=>round(min($nps['80ccd1b_extra']??0,T80C_NPS_EXTRA),2),'limit_80c'=>T80C_LIMIT,'limit_80ccd1b'=>T80C_NPS_EXTRA,'itr_note'=>['80C'=>'ITR Part C → 80C → EPF+PPF+ELSS+NSC+LIC','80CCD1'=>'ITR → 80CCD(1) → Employee NPS (within ₹1.5L)','80CCD1B'=>'ITR → 80CCD(1B) → Additional NPS ₹50K','80CCD2'=>'ITR → 80CCD(2) → Employer NPS (no limit)']]);
    }

    case 'tax_80c_history': {
        $yrs=min(5,max(1,(int)($_GET['years']??3))); $hist=[]; $cur=_80fy();
        for($i=0;$i<$yrs;$i++){$fy=$cur[0]-$i;$fs=$fy.'-04-01';$fe=($fy+1).'-03-31';$fyl=$fy.'-'.($fy+1);$epf=_80epf($userId,$fs,$fe);$ppf=_80ppf($userId,$fy,$fs,$fe);$nps=_80nps($userId,$fs,$fe);$nsc=_80nsc($userId,$fyl);$mt=(float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM tax_80c_manual_entries WHERE user_id=? AND fy_year=? AND is_active=1",[$userId,$fy])??0);$t=$epf['total']+$ppf['total']+$nps['total_80c_part']+$nsc['total']+$mt;$hist[]=['fy_year'=>$fy,'fy_label'=>$fyl,'epf'=>$epf['total'],'ppf'=>$ppf['total'],'nps_80c'=>$nps['total_80c_part'],'nps_80ccd1b'=>$nps['80ccd1b_extra']??0,'nsc'=>$nsc['total'],'manual'=>round($mt,2),'total_80c'=>round(min($t,T80C_LIMIT),2),'utilisation_pct'=>round(min($t,T80C_LIMIT)/T80C_LIMIT*100,1)];}
        json_response(true,'',['history'=>$hist,'limit'=>T80C_LIMIT]);
    }

    case 'tax_80c_manual_save': {
        require_csrf();
        $fyr=(int)($_POST['fy_year']??date('Y')); $cat=clean($_POST['category']??'other'); $amt=(float)($_POST['amount']??0); $desc=substr(clean($_POST['description']??''),0,255); $sec=clean($_POST['section']??'80C'); $eid=(int)($_POST['id']??0);
        $vc=['lic_premium','elss','home_loan_principal','tuition_fee','nsc_purchase','sukanya_samridhi','stamp_duty','unit_linked_insurance','other'];
        $vs=['80C','80CCD(1B)','80D','80E'];
        if (!in_array($cat,$vc)) $cat='other'; if (!in_array($sec,$vs)) $sec='80C'; if ($amt<=0) json_response(false,'Amount required.');
        if ($eid) { DB::run("UPDATE tax_80c_manual_entries SET category=?,amount=?,description=?,section=? WHERE id=? AND user_id=?",[$cat,$amt,$desc?:null,$sec,$eid,$userId]); }
        else { $eid=DB::insert("INSERT INTO tax_80c_manual_entries (user_id,fy_year,category,amount,description,section) VALUES (?,?,?,?,?,?)",[$userId,$fyr,$cat,$amt,$desc?:null,$sec]); }
        json_response(true,'Entry saved.',['id'=>(int)$eid]);
    }

    case 'tax_80c_manual_delete': {
        require_csrf();
        $id=(int)($_POST['id']??0); if (!$id) json_response(false,'id required.');
        $d=DB::run("DELETE FROM tax_80c_manual_entries WHERE id=? AND user_id=?",[$id,$userId]);
        json_response((bool)$d,$d?'Deleted.':'Not found.');
    }

    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
