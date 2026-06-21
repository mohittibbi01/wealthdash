<?php
/**
 * WealthDash — t314: Monthly P&L Statement
 * Portfolio P&L: MF gains, dividends, interest, fees for any month
 * Actions: monthly_pl | monthly_pl_history | monthly_pl_chart
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'monthly_pl';

function _mpl_month_range(string $month): array {
    $dt=DateTime::createFromFormat('Y-m',$month)?:new DateTime();
    $from=$dt->format('Y-m-01'); $to=$dt->format('Y-m-t');
    return [$from,$to,$dt->format('M Y')];
}

switch ($action) {
    case 'monthly_pl': {
        $month=clean($_GET['month']??date('Y-m')); [$from,$to,$label]=_mpl_month_range($month);
        $pid=(int)($_GET['portfolio_id']??0);
        $portCond=$pid?"AND p.id=? AND p.user_id=?":'AND p.user_id=?';
        $portParams=$pid?[$pid,$userId]:[$userId];

        // MF Realised gains
        $mfGains=DB::fetchVal("SELECT COALESCE(SUM(tr.amount - tr.units*mh.avg_nav),0) FROM mf_transactions tr JOIN mf_holdings mh ON mh.id=tr.holding_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE tr.transaction_type IN('redeem','switch_out') AND tr.transaction_date BETWEEN ? AND ? {$portCond}",array_merge([$from,$to],$portParams))??0;

        // MF Unrealised change (current value vs start of month)
        $mfCurrentVal=DB::fetchVal("SELECT COALESCE(SUM(mh.current_value),0) FROM mf_holdings mh JOIN portfolios p ON p.id=mh.portfolio_id WHERE mh.units>0 {$portCond}",$portParams)??0;

        // Dividends
        $dividends=DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM mf_dividends WHERE user_id=? AND dividend_date BETWEEN ? AND ?",[$userId,$from,$to])??0;

        // FD Interest this month
        $fdInterest=DB::fetchVal("SELECT COALESCE(SUM(interest_earned),0) FROM fd_interest_log WHERE user_id=? AND credit_date BETWEEN ? AND ?",[$userId,$from,$to])??0;

        // Savings interest
        $savingsInt=DB::fetchVal("SELECT COALESCE(SUM(interest_amount),0) FROM savings_interest WHERE user_id=? AND interest_date BETWEEN ? AND ?",[$userId,$from,$to])??0;

        // MF Purchases this month (cash out)
        $mfPurchases=DB::fetchVal("SELECT COALESCE(SUM(tr.amount),0) FROM mf_transactions tr JOIN mf_holdings mh ON mh.id=tr.holding_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE tr.transaction_type IN('purchase','sip') AND tr.transaction_date BETWEEN ? AND ? {$portCond}",array_merge([$from,$to],$portParams))??0;

        // NPS contributions
        $npsContrib=DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM nps_transactions nt JOIN portfolios p ON p.id=nt.portfolio_id WHERE nt.txn_type='purchase' AND nt.txn_date BETWEEN ? AND ? {$portCond}",array_merge([$from,$to],$portParams))??0;

        // Total income vs outflow
        $totalIncome=round((float)$mfGains+(float)$dividends+(float)$fdInterest+(float)$savingsInt,2);
        $totalOutflow=round((float)$mfPurchases+(float)$npsContrib,2);
        $netPl=round($totalIncome,2);

        json_response(true,'',['month'=>$month,'month_label'=>$label,'income'=>['mf_realised_gains'=>round((float)$mfGains,2),'dividends'=>round((float)$dividends,2),'fd_interest'=>round((float)$fdInterest,2),'savings_interest'=>round((float)$savingsInt,2),'total_income'=>$totalIncome],'investments'=>['mf_purchases'=>round((float)$mfPurchases,2),'nps_contributions'=>round((float)$npsContrib,2),'total_invested'=>$totalOutflow],'portfolio_value'=>round((float)$mfCurrentVal,2),'net_pl'=>$netPl,'pl_note'=>$netPl>=0?'Profitable month ✅':'Loss month — review karo']);
    }
    case 'monthly_pl_history': {
        $months=min(24,max(3,(int)($_GET['months']??12))); $history=[];
        for ($i=0;$i<$months;$i++) {
            $m=date('Y-m',strtotime("-{$i} months")); [$from,$to,$label]=_mpl_month_range($m);
            $gains=(float)(DB::fetchVal("SELECT COALESCE(SUM(tr.amount-tr.units*mh.avg_nav),0) FROM mf_transactions tr JOIN mf_holdings mh ON mh.id=tr.holding_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE tr.transaction_type IN('redeem','switch_out') AND tr.transaction_date BETWEEN ? AND ? AND p.user_id=?",[$from,$to,$userId])??0);
            $fdInt=(float)(DB::fetchVal("SELECT COALESCE(SUM(interest_earned),0) FROM fd_interest_log WHERE user_id=? AND credit_date BETWEEN ? AND ?",[$userId,$from,$to])??0);
            $divs=(float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM mf_dividends WHERE user_id=? AND dividend_date BETWEEN ? AND ?",[$userId,$from,$to])??0);
            $history[]=(['month'=>$m,'label'=>$label,'realised_gains'=>round($gains,2),'fd_interest'=>round($fdInt,2),'dividends'=>round($divs,2),'total'=>round($gains+$fdInt+$divs,2)]);
        }
        json_response(true,'',['history'=>array_reverse($history),'months'=>$months]);
    }
    case 'monthly_pl_chart': {
        $months=min(24,max(3,(int)($_GET['months']??12))); $chart=[];
        for ($i=$months-1;$i>=0;$i--) {
            $m=date('Y-m',strtotime("-{$i} months")); [$from,$to,$label]=_mpl_month_range($m);
            $gains=(float)(DB::fetchVal("SELECT COALESCE(SUM(tr.amount-tr.units*mh.avg_nav),0) FROM mf_transactions tr JOIN mf_holdings mh ON mh.id=tr.holding_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE tr.transaction_type IN('redeem','switch_out') AND tr.transaction_date BETWEEN ? AND ? AND p.user_id=?",[$from,$to,$userId])??0);
            $chart[]=['month'=>$m,'label'=>$label,'value'=>round($gains,2)];
        }
        json_response(true,'',['chart'=>$chart]);
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
