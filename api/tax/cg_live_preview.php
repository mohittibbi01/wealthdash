<?php
/**
 * WealthDash — tv09: Capital Gains Tax Preview — Live MF Holdings
 * Budget 2024 rates: STCG 20%, LTCG 12.5% (>1.25L exempt)
 * Actions: cg_live_preview | cg_holding_detail | cg_tax_if_sold | cg_harvest_suggest
 */
defined('WEALTHDASH') or die('Direct access not permitted.');

$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? 'cg_live_preview';

const CGPV_STCG_RATE    = 20.0;
const CGPV_LTCG_RATE    = 12.5;
const CGPV_LTCG_EXEMPT  = 125000;
const CGPV_CESS         = 0.04;
const CGPV_EQUITY_KW    = ['Equity','ELSS','Aggressive Hybrid','Flexi Cap','Large Cap','Small Cap','Mid Cap','Thematic','Sectoral','Index','ETF'];

function _cgpv_is_equity(string $cat): bool {
    foreach (CGPV_EQUITY_KW as $kw) { if (stripos($cat,$kw)!==false) return true; }
    return false;
}

function _cgpv_tax(float $gain, int $days, bool $eq): array {
    if ($gain <= 0) return ['gain'=>$gain,'gain_type'=>$days>=365?'LTCL':'STCL','is_equity'=>$eq,'holding_days'=>$days,'tax_rate'=>0,'taxable_gain'=>0,'exempt'=>0,'tax'=>0,'cess'=>0,'total_tax'=>0,'note'=>'Loss — set off against gains'];
    if (!$eq) return ['gain'=>round($gain,2),'gain_type'=>'SLAB','is_equity'=>false,'holding_days'=>$days,'tax_rate'=>'Slab','taxable_gain'=>round($gain,2),'exempt'=>0,'tax'=>0,'cess'=>0,'total_tax'=>0,'note'=>'Debt: slab rate, add to income (ITR Schedule OS)'];
    if ($days < 365) {
        $t = round($gain*CGPV_STCG_RATE/100,2); $cs = round($t*CGPV_CESS,2);
        return ['gain'=>round($gain,2),'gain_type'=>'STCG','is_equity'=>true,'holding_days'=>$days,'holding_months'=>round($days/30,1),'tax_rate'=>CGPV_STCG_RATE,'taxable_gain'=>round($gain,2),'exempt'=>0,'tax'=>$t,'cess'=>$cs,'total_tax'=>round($t+$cs,2),'note'=>'STCG @ 20% (Budget 2024)'];
    }
    $tg = max(0,$gain-CGPV_LTCG_EXEMPT); $t = round($tg*CGPV_LTCG_RATE/100,2); $cs = round($t*CGPV_CESS,2);
    return ['gain'=>round($gain,2),'gain_type'=>'LTCG','is_equity'=>true,'holding_days'=>$days,'holding_months'=>round($days/30,1),'tax_rate'=>CGPV_LTCG_RATE,'exempt_limit'=>CGPV_LTCG_EXEMPT,'exempt'=>round(min($gain,CGPV_LTCG_EXEMPT),2),'taxable_gain'=>round($tg,2),'tax'=>$t,'cess'=>$cs,'total_tax'=>round($t+$cs,2),'note'=>'LTCG @ 12.5% on gains > ₹1,25,000 (Budget 2024)'];
}

function _cgpv_holdings(int $userId, ?int $pid): array {
    $cond = $pid ? "AND mh.portfolio_id=?" : "AND p.user_id=?";
    $val  = $pid ?? $userId;
    return DB::fetchAll("SELECT mh.id,mh.portfolio_id,mh.fund_id,mh.units,mh.avg_nav,mh.invested_amount,mh.current_value,mh.first_investment_date,f.scheme_name,f.scheme_code,f.scheme_category,f.nav AS current_nav,f.nav_date,fh.name AS fund_house FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE mh.units>0 {$cond} ORDER BY mh.current_value DESC",[$val]);
}

switch ($action) {
    case 'cg_live_preview': {
        $pid      = (int)($_GET['portfolio_id']??0);
        $holdings = _cgpv_holdings($userId,$pid?:null);
        $sum = ['stcg_gain'=>0,'stcg_tax'=>0,'ltcg_gain'=>0,'ltcg_tax'=>0,'ltcg_exempt'=>0,'debt_gain'=>0,'invested'=>0,'value'=>0,'stcg_loss'=>0,'ltcg_loss'=>0];
        $details = [];
        foreach ($holdings as $h) {
            $cv    = (float)$h['current_value']?:(float)$h['units']*(float)$h['current_nav'];
            $inv   = (float)$h['invested_amount'];
            $gain  = $cv - $inv;
            $days  = $h['first_investment_date'] ? max(0,(int)floor((time()-strtotime($h['first_investment_date']))/86400)) : 0;
            $eq    = _cgpv_is_equity($h['scheme_category']??'');
            $tc    = _cgpv_tax($gain,$days,$eq);
            $sum['invested']+=$inv; $sum['value']+=$cv;
            if ($gain<0) { if ($eq&&$days>=365) $sum['ltcg_loss']+=abs($gain); elseif($eq) $sum['stcg_loss']+=abs($gain); }
            elseif ($tc['gain_type']==='STCG') { $sum['stcg_gain']+=$gain; $sum['stcg_tax']+=$tc['total_tax']; }
            elseif ($tc['gain_type']==='LTCG') { $sum['ltcg_gain']+=$gain; $sum['ltcg_exempt']+=$tc['exempt']; $sum['ltcg_tax']+=$tc['tax']; }
            else { $sum['debt_gain']+=$gain; }
            $details[] = ['holding_id'=>$h['id'],'fund_name'=>$h['scheme_name'],'fund_house'=>$h['fund_house'],'scheme_category'=>$h['scheme_category'],'is_equity'=>$eq,'units'=>(float)$h['units'],'avg_nav'=>(float)$h['avg_nav'],'current_nav'=>(float)$h['current_nav'],'nav_date'=>$h['nav_date'],'invested'=>round($inv,2),'current_value'=>round($cv,2),'unrealised_gain'=>round($gain,2),'gain_pct'=>$inv>0?round($gain/$inv*100,2):0,'first_investment_date'=>$h['first_investment_date'],'holding_days'=>$days,'days_to_ltcg'=>($days<365&&$eq)?max(0,365-$days):0,'tax'=>$tc];
        }
        usort($details,fn($a,$b)=>$b['unrealised_gain']<=>$a['unrealised_gain']);
        $ltcgNet = max(0,$sum['ltcg_gain']-CGPV_LTCG_EXEMPT);
        $ltcgTax = round($ltcgNet*CGPV_LTCG_RATE/100*(1+CGPV_CESS),2);
        $stcgTax = round($sum['stcg_tax'],2);
        json_response(true,'',['holdings'=>$details,'count'=>count($details),'as_of_date'=>date('Y-m-d'),'summary'=>['total_invested'=>round($sum['invested'],2),'total_current_value'=>round($sum['value'],2),'total_unrealised_gain'=>round($sum['value']-$sum['invested'],2),'total_gain_pct'=>$sum['invested']>0?round(($sum['value']-$sum['invested'])/$sum['invested']*100,2):0,'equity_stcg_gain'=>round($sum['stcg_gain'],2),'equity_stcg_tax'=>$stcgTax,'equity_ltcg_gain'=>round($sum['ltcg_gain'],2),'equity_ltcg_exempt'=>round(min($sum['ltcg_gain'],CGPV_LTCG_EXEMPT),2),'equity_ltcg_taxable'=>round($ltcgNet,2),'equity_ltcg_tax'=>$ltcgTax,'debt_gain_slab'=>round($sum['debt_gain'],2),'stcg_loss'=>round($sum['stcg_loss'],2),'ltcg_loss'=>round($sum['ltcg_loss'],2),'grand_total_tax'=>round($stcgTax+$ltcgTax,2),'ltcg_exempt_limit'=>CGPV_LTCG_EXEMPT,'stcg_rate'=>CGPV_STCG_RATE,'ltcg_rate'=>CGPV_LTCG_RATE],'tax_rates'=>['equity_stcg'=>'20% + 4% cess (Budget 2024)','equity_ltcg'=>'12.5% on gains > ₹1,25,000 (Budget 2024)','debt'=>'Slab rate — IFOS','cess'=>'4%'],'disclaimer'=>'FIFO method assumed. Consult CA for final ITR filing.']);
    }

    case 'cg_holding_detail': {
        $hid = (int)($_GET['holding_id']??0);
        if (!$hid) json_response(false,'holding_id required.');
        $h = DB::fetchOne("SELECT mh.*,f.scheme_name,f.scheme_category,f.nav AS current_nav,f.nav_date,fh.name AS fund_house FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE mh.id=? AND p.user_id=?",[$hid,$userId]);
        if (!$h) json_response(false,'Holding not found.',[],404);
        $txns = DB::fetchAll("SELECT txn_date,units,nav,amount,txn_type FROM mf_transactions WHERE portfolio_id=? AND fund_id=? AND txn_type IN ('purchase','sip','switch_in','dividend_reinvest') ORDER BY txn_date ASC",[$h['portfolio_id'],$h['fund_id']]);
        $cnav = (float)$h['current_nav']; $eq = _cgpv_is_equity($h['scheme_category']??'');
        $lots = []; $totGain = 0; $totTax = 0;
        foreach ($txns as $t) {
            $u = (float)$t['units']; $n = (float)$t['nav'];
            $d = max(0,(int)floor((time()-strtotime($t['txn_date']))/86400));
            $g = ($cnav-$n)*$u; $tc = _cgpv_tax($g,$d,$eq);
            $lots[] = ['txn_date'=>$t['txn_date'],'units'=>$u,'buy_nav'=>$n,'current_nav'=>$cnav,'invested'=>round($u*$n,2),'current_value'=>round($u*$cnav,2),'gain'=>round($g,2),'holding_days'=>$d,'tax'=>$tc];
            $totGain+=$g; $totTax+=$tc['total_tax'];
        }
        json_response(true,'',['holding'=>$h,'is_equity'=>$eq,'lots'=>$lots,'total_gain'=>round($totGain,2),'total_tax'=>round($totTax,2)]);
    }

    case 'cg_tax_if_sold': {
        $hid  = (int)($_GET['holding_id']??0);
        $uts  = (float)($_GET['units']??0);
        $snav = (float)($_GET['sell_nav']??0);
        if (!$hid) json_response(false,'holding_id required.');
        $h = DB::fetchOne("SELECT mh.*,f.scheme_name,f.scheme_category,f.nav AS current_nav FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id JOIN portfolios p ON p.id=mh.portfolio_id WHERE mh.id=? AND p.user_id=?",[$hid,$userId]);
        if (!$h) json_response(false,'Holding not found.',[],404);
        $units = min((float)$h['units'],$uts?:(float)$h['units']); $nav = $snav?:(float)$h['current_nav'];
        $saleVal = round($units*$nav,2); $cost = round($units*(float)$h['avg_nav'],2); $gain = $saleVal-$cost;
        $days = $h['first_investment_date'] ? max(0,(int)floor((time()-strtotime($h['first_investment_date']))/86400)) : 0;
        $eq   = _cgpv_is_equity($h['scheme_category']??''); $tc = _cgpv_tax($gain,$days,$eq);
        json_response(true,'',['holding_id'=>$hid,'fund_name'=>$h['scheme_name'],'units_to_sell'=>$units,'sell_nav'=>$nav,'sale_value'=>$saleVal,'cost_value'=>$cost,'gain'=>round($gain,2),'tax'=>$tc,'net_proceeds'=>round($saleVal-$tc['total_tax'],2)]);
    }

    case 'cg_harvest_suggest': {
        $pid  = (int)($_GET['portfolio_id']??0);
        $hold = _cgpv_holdings($userId,$pid?:null);
        $harvest=[]; $loss=[];
        $remaining = CGPV_LTCG_EXEMPT;
        foreach ($hold as $h) {
            $cv   = (float)$h['current_value']?:(float)$h['units']*(float)$h['current_nav'];
            $inv  = (float)$h['invested_amount']; $gain = $cv-$inv;
            $days = $h['first_investment_date']?max(0,(int)floor((time()-strtotime($h['first_investment_date']))/86400)):0;
            $eq   = _cgpv_is_equity($h['scheme_category']??'');
            if ($eq&&$days>=365&&$gain>0&&$remaining>0) {
                $hg = min($gain,$remaining); $gpu = (float)$h['units']>0?$gain/(float)$h['units']:0;
                $uh = $gpu>0?round($hg/$gpu,3):0;
                $harvest[] = ['holding_id'=>$h['id'],'fund_name'=>$h['scheme_name'],'units_to_redeem'=>$uh,'gain_to_harvest'=>round($hg,2),'tax_saving'=>round($hg*CGPV_LTCG_RATE/100,2),'action'=>"Redeem {$uh} units → book ₹".number_format($hg,0)." tax-free → reinvest same day"];
                $remaining -= $hg;
            }
            if ($gain<-5000&&$eq) {
                $loss[] = ['holding_id'=>$h['id'],'fund_name'=>$h['scheme_name'],'loss_amount'=>round(abs($gain),2),'holding_days'=>$days,'loss_type'=>$days>=365?'LTCL':'STCL','action'=>"Book ₹".number_format(abs($gain),0)." loss — set off against gains"];
            }
        }
        json_response(true,'',['ltcg_exempt_limit'=>CGPV_LTCG_EXEMPT,'ltcg_exempt_remaining'=>round($remaining,2),'gain_harvest'=>$harvest,'loss_harvest'=>$loss,'total_harvestable_gain'=>round(array_sum(array_column($harvest,'gain_to_harvest')),2),'total_tax_saving'=>round(array_sum(array_column($harvest,'tax_saving')),2),'total_harvestable_loss'=>round(array_sum(array_column($loss,'loss_amount')),2),'tip'=>'Gain harvest: Sell → same-day reinvest → cost basis reset. Loss harvest: Sell → book loss → reinvest after 30 days.']);
    }

    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}
