<?php
/**
 * WealthDash — t383: AI Tax Optimizer
 * Full AI-powered tax optimization engine.
 *
 * Actions:
 *   ai_tax_get_suggestions  — Main suggestions list (rule-based fast)
 *   ai_tax_full_analysis    — Detailed analysis + checklist + deadlines
 *   ai_tax_income_update    — Update income for session
 *   ai_tax_checklist        — FY-end tax checklist
 *   ai_tax_deadline_alerts  — Upcoming tax deadlines
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

set_exception_handler(function (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

$action      = $_GET['action'] ?? $_POST['action'] ?? 'ai_tax_get_suggestions';
$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$income      = (float)($_GET['income'] ?? $_POST['income'] ?? $_SESSION['wd_tax_income'] ?? 0);

if ($income > 0) {
    $_SESSION['wd_tax_income'] = $income;
}

// ─── Helpers ───────────────────────────────────────────────────────────────
function t383_fy_start(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y : $y - 1) . '-04-01';
}
function t383_fy_end(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y + 1 : $y) . '-03-31';
}
function t383_current_fy(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    $fy = $m >= 4 ? $y : $y - 1;
    return "FY {$fy}-" . ($fy + 1);
}
function t383_days_left(): int {
    return max(0, (int)(new DateTime())->diff(new DateTime(t383_fy_end()))->days);
}
function t383_slab(float $income): float {
    return $income > 1500000 ? 0.30 : ($income > 1200000 ? 0.20
         : ($income > 900000 ? 0.15 : ($income > 600000 ? 0.10
         : ($income > 300000 ? 0.05 : 0))));
}
function t383_fmt(float $n): string {
    if ($n >= 10000000) return 'Rs.' . number_format($n / 10000000, 2) . 'Cr';
    if ($n >= 100000)   return 'Rs.' . number_format($n / 100000, 2) . 'L';
    return 'Rs.' . number_format($n, 0);
}
function t383_urgency(int $month, string $base): string {
    return $month === 3 ? 'critical' : ($month >= 2 ? 'high' : $base);
}

// ─── Pull portfolio deductions automatically ───────────────────────────────
function t383_pull_deductions(PDO $db, int $userId, int $portfolioId): array {
    $fy = t383_fy_start();
    $d  = ['elss'=>0,'nps_80c'=>0,'nps_80ccd1b'=>0,'epf'=>0,'ppf'=>0,
            'fd_interest'=>0,'savings_int'=>0,'lic_premium'=>0,
            'mf_ltcg_gain'=>0,'mf_stcg_gain'=>0,'mf_loss'=>0,'hl_interest'=>0];

    $pW  = $portfolioId > 0 ? 'AND t.portfolio_id = ?' : 'AND p.user_id = ?';
    $pP  = $portfolioId > 0 ? $portfolioId : $userId;
    $pW2 = $portfolioId > 0 ? 'AND p.id = ?'  : 'AND p.user_id = ?';

    // ELSS
    try {
        $s = $db->prepare("SELECT COALESCE(SUM(t.value_at_cost),0)
            FROM mf_transactions t JOIN funds f ON f.id=t.fund_id
            JOIN portfolios p ON p.id=t.portfolio_id
            WHERE t.transaction_type IN ('buy','sip') AND t.txn_date>=?
              AND (LOWER(f.category) LIKE '%elss%' OR LOWER(f.category) LIKE '%tax sav%') $pW");
        $s->execute([$fy,$pP]); $d['elss']=(float)$s->fetchColumn();
    } catch(Exception $e){}

    // NPS
    try {
        $s = $db->prepare("SELECT COALESCE(SUM(t.amount),0) FROM nps_transactions t
            JOIN portfolios p ON p.id=t.portfolio_id WHERE t.txn_date>=? $pW2");
        $s->execute([$fy,$pP]); $nps=(float)$s->fetchColumn();
        $d['nps_80c']=(float)min($nps,150000);
        $d['nps_80ccd1b']=(float)min(max(0,$nps-150000),50000);
    } catch(Exception $e){}

    // EPF
    try {
        $s=$db->prepare("SELECT COALESCE(SUM(employee_contribution),0) FROM epf_accounts WHERE user_id=?");
        $s->execute([$userId]); $d['epf']=(float)$s->fetchColumn();
    } catch(Exception $e){}

    // PPF
    try {
        $s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM ppf_transactions WHERE user_id=? AND txn_date>=?");
        $s->execute([$userId,$fy]); $d['ppf']=(float)$s->fetchColumn();
    } catch(Exception $e){}

    // FD interest
    try {
        $pW3=$portfolioId>0?'AND fa.portfolio_id=?':'AND p.user_id=?';
        $s=$db->prepare("SELECT COALESCE(SUM(ROUND(fa.principal*(fa.interest_rate/100)*
            DATEDIFF(LEAST(fa.maturity_date,CURDATE()),GREATEST(fa.start_date,?))/365,2)),0)
            FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id WHERE fa.status='active' $pW3");
        $s->execute([$fy,$pP]); $d['fd_interest']=(float)$s->fetchColumn();
    } catch(Exception $e){}

    // MF unrealized gains/losses
    try {
        $pW4=$portfolioId>0?'AND p.id=?':'AND p.user_id=?';
        $s=$db->prepare("SELECT mh.avg_cost_nav,mh.units,fn.nav AS cur_nav,mh.first_investment_date,f.asset_class
            FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id
            JOIN portfolios p ON p.id=mh.portfolio_id
            LEFT JOIN fund_navs fn ON fn.fund_id=mh.fund_id
            WHERE mh.units>0 $pW4");
        $s->execute([$pP]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
            if(!$r['cur_nav']||!$r['avg_cost_nav']) continue;
            $gain=($r['cur_nav']-$r['avg_cost_nav'])*$r['units'];
            $days=$r['first_investment_date']?(int)(new DateTime())->diff(new DateTime($r['first_investment_date']))->days:0;
            $isLT=in_array($r['asset_class'],['equity','elss'])?$days>=365:$days>=1095;
            if($gain>0){ if($isLT) $d['mf_ltcg_gain']+=$gain; else $d['mf_stcg_gain']+=$gain; }
            elseif($gain<0) $d['mf_loss']+=abs($gain);
        }
    } catch(Exception $e){}

    // Home loan interest
    try {
        $s=$db->prepare("SELECT COALESCE(SUM(r.interest_component),0)
            FROM loan_repayments r JOIN loans l ON l.id=r.loan_id
            WHERE l.user_id=? AND l.loan_type='home_loan' AND r.payment_date>=?");
        $s->execute([$userId,t383_fy_start()]); $d['hl_interest']=(float)$s->fetchColumn();
    } catch(Exception $e){}

    // Health insurance premium
    try {
        $s=$db->prepare("SELECT COALESCE(SUM(annual_premium),0) FROM health_insurance WHERE user_id=? AND status='active'");
        $s->execute([$userId]); $d['health_premium']=(float)$s->fetchColumn();
    } catch(Exception $e){ $d['health_premium']=0; }

    return $d;
}

// ─── Tax calculators ───────────────────────────────────────────────────────
function t383_old_tax(float $income, array $d): float {
    $deduct = min(($d['elss']??0)+($d['nps_80c']??0)+($d['epf']??0)+($d['ppf']??0)+($d['lic_premium']??0),150000)
            + min($d['nps_80ccd1b']??0,50000);
    $taxable = max(0,$income-$deduct-50000);
    if($taxable<=250000) return 0;
    $tax=0;
    if($taxable>1000000){$tax+=($taxable-1000000)*0.30;$taxable=1000000;}
    if($taxable>500000) {$tax+=($taxable-500000)*0.20;$taxable=500000;}
    if($taxable>250000) {$tax+=($taxable-250000)*0.05;}
    return $tax*1.04;
}
function t383_new_tax(float $income): float {
    $taxable=max(0,$income-75000);
    if($taxable<=400000) return 0;
    $tax=0;
    foreach([[400000,800000,0.05],[800000,1200000,0.10],[1200000,1600000,0.15],
             [1600000,2000000,0.20],[2000000,2400000,0.25],[2400000,PHP_INT_MAX,0.30]] as [$lo,$hi,$r])
        if($taxable>$lo) $tax+=(min($taxable,$hi)-$lo)*$r;
    if($taxable<=700000) $tax=0;
    return $tax*1.04;
}

// ─── Main suggestion engine ────────────────────────────────────────────────
function t383_suggestions(PDO $db, int $userId, int $portfolioId, float $income, array $d): array {
    $slab    = t383_slab($income);
    $dLeft   = t383_days_left();
    $month   = (int)date('n');
    $sugg    = [];

    // 1. 80C headroom
    $used80c = ($d['elss']??0)+($d['nps_80c']??0)+($d['epf']??0)+($d['ppf']??0)+($d['lic_premium']??0);
    $rem80c  = max(0, 150000-$used80c);
    if($rem80c>=1000 && $income>300000) {
        $saving=(int)round($rem80c*$slab);
        $sugg[]=['id'=>'opt_80c','category'=>'80C Limit','icon'=>'🏦','priority'=>1,
            'urgency'=>t383_urgency($month,'medium'),
            'title'=>t383_fmt($rem80c).' 80C limit abhi bachi hai',
            'action'=>'ELSS SIP shuru karo ya PPF mein deposit karo',
            'detail'=>"Is FY mein ".t383_fmt($rem80c)." aur invest karo. Tax saving: ".t383_fmt($saving)." at ".($slab*100)."% slab. ELSS: 3yr lock-in, equity upside. PPF: risk-free, 7.1%.",
            'saving'=>$saving,'days_left'=>$dLeft,
            'options'=>[['name'=>'ELSS MF','why'=>'3yr lock-in + equity returns'],['name'=>'PPF','why'=>'Safe, tax-free maturity, 7.1%'],['name'=>'NSC 5yr','why'=>'7.7% guaranteed']]];
    }

    // 2. NPS 80CCD(1B) extra ₹50K
    $rem1b=max(0,50000-($d['nps_80ccd1b']??0));
    if($rem1b>=500 && $income>500000) {
        $saving=(int)round($rem1b*$slab);
        $sugg[]=['id'=>'opt_nps_1b','category'=>'NPS 80CCD(1B)','icon'=>'🏛️','priority'=>2,
            'urgency'=>t383_urgency($month,'medium'),
            'title'=>'NPS se '.t383_fmt($rem1b).' EXTRA deduction — 80C ke upar',
            'action'=>'NPS Tier-I mein aur invest karo (additional ₹50K allowed)',
            'detail'=>"80CCD(1B): ₹50,000 extra deduction — 80C se bilkul alag. ".t383_fmt($rem1b)." abhi baki. Tax saving: ".t383_fmt($saving).".",
            'saving'=>$saving,'days_left'=>$dLeft,
            'options'=>[['name'=>'NPS Tier I','why'=>'Max tax benefit, pension at 60']]];
    }

    // 3. LTCG harvesting
    $ltcgBW=max(0,125000-($d['mf_ltcg_gain']??0));
    if($ltcgBW>5000) {
        $saving=(int)round($ltcgBW*0.125);
        $sugg[]=['id'=>'opt_ltcg_harvest','category'=>'LTCG Harvesting','icon'=>'🌾','priority'=>3,
            'urgency'=>$month===3?'critical':'low',
            'title'=>t383_fmt($ltcgBW).' LTCG bandwidth free hai this FY',
            'action'=>'Equity MF units redeem karo aur rebuy karo — tax-free profit booking',
            'detail'=>"₹1.25L LTCG pe zero tax. Abhi tak ".t383_fmt($d['mf_ltcg_gain']??0)." book kiya. ".t383_fmt($ltcgBW)." remaining. Redeem → 3 din wait → rebuy. Cost base reset.",
            'saving'=>$saving,'days_left'=>$dLeft];
    }

    // 4. Loss harvesting
    $mfLoss=$d['mf_loss']??0;
    if($mfLoss>2000) {
        $saving=(int)round($mfLoss*0.15);
        $sugg[]=['id'=>'opt_loss_harvest','category'=>'Loss Harvesting','icon'=>'📉','priority'=>4,
            'urgency'=>t383_urgency($month,'medium'),
            'title'=>t383_fmt($mfLoss).' unrealized loss — book karke gains offset karo',
            'action'=>'Loss-making funds redeem karo → gains se set-off hoga',
            'detail'=>"Portfolio mein ".t383_fmt($mfLoss)." unrealized loss. Redeem karo → STCG/LTCG se set-off. Potential saving: ~".t383_fmt($saving).".",
            'saving'=>$saving,'days_left'=>$dLeft];
    }

    // 5. FD TDS
    $fdInt=$d['fd_interest']??0;
    if($fdInt>40000) {
        $saving=(int)round(min($fdInt-40000,80000)*$slab);
        $sugg[]=['id'=>'opt_fd_tds','category'=>'FD TDS','icon'=>'💰','priority'=>5,
            'urgency'=>'medium',
            'title'=>'FD interest '.t383_fmt($fdInt).' — TDS lag raha hai',
            'action'=>'FD split karo across FY boundary, ya Form 15G/H submit karo',
            'detail'=>"₹40K+ FD interest pe 10% TDS. New FD banate waqt March/April maturity boundary cross karo. Potential saving: ".t383_fmt($saving).".",
            'saving'=>$saving,'days_left'=>$dLeft];
    }

    // 6. Home loan 24b
    $hlInt=$d['hl_interest']??0;
    if($hlInt>0) {
        $deduct=min($hlInt,200000);
        $saving=(int)round($deduct*$slab);
        $sugg[]=['id'=>'opt_hl_24b','category'=>'Section 24b','icon'=>'🏠','priority'=>6,
            'urgency'=>'low',
            'title'=>'Home loan interest '.t383_fmt($hlInt).' — 24b claim karo',
            'action'=>'ITR mein Section 24b claim karo — '.t383_fmt($deduct).' deductible',
            'detail'=>"Self-occupied: ₹2L interest deductible under 24b. Your interest: ".t383_fmt($hlInt).". Tax saving: ".t383_fmt($saving).". Bank se interest certificate lo.",
            'saving'=>$saving,'days_left'=>$dLeft];
    }

    // 7. 80D
    $hp=$d['health_premium']??0;
    $rem80d=max(0,25000-$hp);
    if($rem80d>2000 && $income>300000) {
        $saving=(int)round($rem80d*$slab);
        $sugg[]=['id'=>'opt_80d','category'=>'80D Health Insurance','icon'=>'🏥','priority'=>7,
            'urgency'=>t383_urgency($month,'low'),
            'title'=>t383_fmt($rem80d).' 80D limit baki hai',
            'action'=>'Health insurance top-up lo ya health check-up karo (₹5K extra)',
            'detail'=>"Section 80D: ₹25,000 premium deductible (₹50K for senior citizens). Abhi ".t383_fmt($hp)." premium hai. Tax saving potential: ".t383_fmt($saving).".",
            'saving'=>$saving,'days_left'=>$dLeft];
    }

    // 8. HRA reminder
    if($income>600000) {
        $sugg[]=['id'=>'opt_hra','category'=>'HRA Deduction','icon'=>'🏘️','priority'=>8,
            'urgency'=>'low',
            'title'=>'HRA claim kiya? Rent receipts ready karo',
            'action'=>'Rent receipts collect karo + landlord PAN (if rent >₹1L/yr)',
            'detail'=>"HRA = Min(actual HRA, 50%/40% of basic, rent paid minus 10% of basic). Old regime only. Form 12BB employer ko submit karo.",
            'saving'=>0,'days_left'=>$dLeft];
    }

    // 9. Regime recommendation
    if($income>300000) {
        $old=t383_old_tax($income,$d); $new=t383_new_tax($income);
        $diff=$old-$new;
        if(abs($diff)>2000) {
            $better=$diff>0?'New Regime':'Old Regime';
            $saving=abs((int)round($diff));
            $sugg[]=['id'=>'opt_regime','category'=>'Tax Regime','icon'=>'⚖️','priority'=>9,
                'urgency'=>'high',
                'title'=>$better.' mein '.t383_fmt($saving).' zyada bachenge',
                'action'=>"ITR file karte waqt $better choose karo",
                'detail'=>"Old Regime tax: Rs.".number_format(round($old)).". New Regime: Rs.".number_format(round($new)).". ".($diff>0?"Deductions new regime mein kaam nahi aatein.":"80C/NPS deductions old regime ko better banate hain."),
                'saving'=>$saving,'days_left'=>$dLeft,
                'regime_detail'=>['old_tax'=>round($old),'new_tax'=>round($new)]];
        }
    }

    $uOrd=['critical'=>0,'high'=>1,'medium'=>2,'low'=>3];
    usort($sugg,function($a,$b) use($uOrd){
        $ua=$uOrd[$a['urgency']]??9; $ub=$uOrd[$b['urgency']]??9;
        if($ua!==$ub) return $ua-$ub;
        return ($b['saving']??0)-($a['saving']??0);
    });
    return $sugg;
}

// ─── Checklist ────────────────────────────────────────────────────────────
function t383_checklist(): array {
    return [
        ['section'=>'80C',      'deadline'=>'31 March',   'task'=>'80C investments complete karo (ELSS / PPF / NSC / NPS)'],
        ['section'=>'NPS',      'deadline'=>'31 March',   'task'=>'NPS 80CCD(1B) ₹50K invest karo (above 80C)'],
        ['section'=>'80D',      'deadline'=>'31 March',   'task'=>'Health insurance premium pay karo'],
        ['section'=>'LTCG',     'deadline'=>'31 March',   'task'=>'LTCG harvesting — ₹1.25L free bandwidth use karo'],
        ['section'=>'Losses',   'deadline'=>'31 March',   'task'=>'Loss harvesting — unrealized losses book karo'],
        ['section'=>'HRA',      'deadline'=>'31 March',   'task'=>'Rent receipts collect karo + landlord PAN'],
        ['section'=>'TDS',      'deadline'=>'April start','task'=>'Form 15G/15H submit karo (FD interest — if applicable)'],
        ['section'=>'24b',      'deadline'=>'June',       'task'=>'Home loan interest certificate lo (Section 24b)'],
        ['section'=>'Documents','deadline'=>'June',       'task'=>'Form 16 ya salary certificate lo'],
        ['section'=>'ITR',      'deadline'=>'July',       'task'=>'AIS / 26AS check karo — discrepancies note karo'],
        ['section'=>'ITR',      'deadline'=>'July',       'task'=>'Capital gains statement download karo (CAMS / KFintech)'],
        ['section'=>'ITR',      'deadline'=>'July',       'task'=>'Old vs New regime compare karke ITR file karo (31 July)'],
    ];
}

// ─── Deadlines ────────────────────────────────────────────────────────────
function t383_deadlines(): array {
    $y=(int)date('n')>=4?(int)date('Y'):(int)date('Y')-1; $n=$y+1;
    $today=new DateTime();
    $dl=[
        ['date'=>"{$y}-06-15",'label'=>'Advance Tax Q1','desc'=>'15% of estimated annual tax'],
        ['date'=>"{$y}-09-15",'label'=>'Advance Tax Q2','desc'=>'45% cumulative'],
        ['date'=>"{$y}-12-15",'label'=>'Advance Tax Q3','desc'=>'75% cumulative'],
        ['date'=>"{$n}-03-15",'label'=>'Advance Tax Q4','desc'=>'100% — final installment'],
        ['date'=>"{$n}-03-31",'label'=>'FY End','desc'=>'80C / NPS / LTCG harvest last date'],
        ['date'=>"{$n}-07-31",'label'=>'ITR Deadline','desc'=>'File ITR for current FY'],
    ];
    foreach($dl as &$item){
        $dt=new DateTime($item['date']);
        $diff=(int)$today->diff($dt)->days;
        $item['days_away']=$dt>$today?$diff:-$diff;
        $item['passed']=$dt<$today;
        $item['urgent']=!$item['passed']&&$diff<=30;
    }
    return array_values(array_filter($dl,fn($d)=>!$d['passed']||$d['days_away']>-90));
}

// ─── Router ───────────────────────────────────────────────────────────────
try {
    switch($action) {
        case 'ai_tax_get_suggestions':
            $ded=$d=t383_pull_deductions($db,$userId,$portfolioId);
            $sugg=t383_suggestions($db,$userId,$portfolioId,$income,$ded);
            ob_clean(); echo json_encode([
                'success'=>true,'fy'=>t383_current_fy(),'income'=>$income,
                'slab_pct'=>t383_slab($income)*100,'days_left'=>t383_days_left(),
                'suggestions'=>$sugg,'suggestion_count'=>count($sugg),
                'total_potential_saving'=>array_sum(array_column($sugg,'saving')),
                'deductions'=>$ded,'generated_at'=>date('Y-m-d H:i:s'),
            ]); break;

        case 'ai_tax_full_analysis':
            $ded=t383_pull_deductions($db,$userId,$portfolioId);
            $sugg=t383_suggestions($db,$userId,$portfolioId,$income,$ded);
            $old=t383_old_tax($income,$ded); $new=t383_new_tax($income);
            ob_clean(); echo json_encode([
                'success'=>true,'fy'=>t383_current_fy(),'income'=>$income,
                'slab_pct'=>t383_slab($income)*100,'days_left'=>t383_days_left(),
                'suggestions'=>$sugg,'checklist'=>t383_checklist(),
                'deadlines'=>array_values(t383_deadlines()),
                'deductions'=>$ded,
                'regime_compare'=>['old_tax'=>round($old),'new_tax'=>round($new),
                    'better'=>$old<$new?'Old Regime':'New Regime','saving'=>abs((int)round($old-$new))],
                'total_potential_saving'=>array_sum(array_column($sugg,'saving')),
                'generated_at'=>date('Y-m-d H:i:s'),
            ]); break;

        case 'ai_tax_checklist':
            ob_clean(); echo json_encode(['success'=>true,'checklist'=>t383_checklist(),'fy'=>t383_current_fy()]); break;

        case 'ai_tax_deadline_alerts':
            ob_clean(); echo json_encode(['success'=>true,'deadlines'=>array_values(t383_deadlines()),'fy'=>t383_current_fy()]); break;

        case 'ai_tax_income_update':
            $income=(float)($_POST['income']??0);
            $_SESSION['wd_tax_income']=$income;
            ob_clean(); echo json_encode(['success'=>true,'income'=>$income,'slab_pct'=>t383_slab($income)*100]); break;

        default:
            ob_clean(); echo json_encode(['success'=>false,'message'=>"Unknown action: {$action}"]);
    }
} catch(Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
