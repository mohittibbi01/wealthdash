<?php
/**
 * WealthDash — t244: AI Portfolio Narrative
 * File: api/ai/portfolio_narrative.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=$_POST['action']??$_GET['action']??''; $action=clean($action);
$userId=(int)$_SESSION['user_id']; $portfolioId=get_user_portfolio_id($userId);

if($action==='ai_portfolio_narrative'){
    RateLimit::check('ai_narrative',3,60);
    $month=clean($_POST['month']??date('Y-m'));
    $holdings=DB::fetchAll("SELECT h.fund_name,h.units,h.avg_cost_per_unit,h.units*COALESCE(n.nav,h.avg_cost_per_unit) AS current_value,(h.units*COALESCE(n.nav,h.avg_cost_per_unit))-(h.units*h.avg_cost_per_unit) AS gain,f.category FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id LEFT JOIN mf_funds f ON f.id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0 ORDER BY current_value DESC LIMIT 10",[$userId,$portfolioId]);
    $totalInvested=array_sum(array_column($holdings,'current_value'))-array_sum(array_column($holdings,'gain'));
    $totalValue=array_sum(array_column($holdings,'current_value'));
    $totalGain=array_sum(array_column($holdings,'gain'));
    $gainPct=$totalInvested>0?round($totalGain/$totalInvested*100,2):0;
    $topGainer=count($holdings)?max(array_map(fn($h)=>['name'=>$h['fund_name'],'gain'=>(float)$h['gain']],$holdings),fn($a,$b)=>$a['gain']<=>$b['gain']):'None';
    $apiKey=defined('ANTHROPIC_API_KEY')?ANTHROPIC_API_KEY:($_ENV['ANTHROPIC_API_KEY']??'');
    $holdingLines=implode("\n",array_map(fn($h)=>sprintf('%s (%s): Value %s, Gain %s',esc($h['fund_name']),$h['category']??'?',number_format((float)$h['current_value'],0),number_format((float)$h['gain'],0)),$holdings));
    $prompt="Write a friendly monthly portfolio summary for an Indian investor for $month.\n\nPortfolio Overview:\n- Total Value: ₹".number_format($totalValue,0)."\n- Total Invested: ₹".number_format($totalInvested,0)."\n- Total Gain: ₹".number_format($totalGain,0)." ($gainPct%)\n\nTop Holdings:\n$holdingLines\n\nWrite 3-4 sentences: performance summary, standout performer, one market insight relevant to India, and one suggestion. Tone: professional but warm. Max 150 words.";
    if(!$apiKey){json_response(true,'ok',['mode'=>'rule_based','month'=>$month,'narrative'=>"Your portfolio stands at ₹".number_format($totalValue,0)." with a total gain of ₹".number_format($totalGain,0)." ($gainPct%). ".($gainPct>0?"Markets have been supportive this period.":"Markets faced headwinds this period.")." Your top holding ".($holdings[0]['fund_name']??'')." continues to anchor the portfolio. Stay invested and review your SIP step-up annually.",'stats'=>['total_value'=>$totalValue,'total_invested'=>$totalInvested,'total_gain'=>$totalGain,'gain_pct'=>$gainPct]]);break 1;}
    $resp=@file_get_contents('https://api.anthropic.com/v1/messages',false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: $apiKey\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>300,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>20]]));
    $text=$resp?json_decode($resp,true)['content'][0]['text']??'':"AI unavailable.";
    json_response(true,'ok',['mode'=>'ai','month'=>$month,'narrative'=>$text,'stats'=>['total_value'=>$totalValue,'total_invested'=>$totalInvested,'total_gain'=>$totalGain,'gain_pct'=>$gainPct]]);
}else{json_response(false,'Unknown action.',[],400);}
