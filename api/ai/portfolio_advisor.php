<?php
/**
 * WealthDash — t58: AI Portfolio Advisor — Full AI Assistant
 * File: api/ai/portfolio_advisor.php
 * Actions: ai_advisor_full_review, ai_advisor_ask, ai_advisor_action_plan
 *
 * The MASTER AI feature — combines insights from fund_recommend, tax_optimizer,
 * goal_advisor, sip_optimizer, anomaly_detector, report_card into ONE
 * comprehensive holistic advisory session with conversational follow-up.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    // ── Full holistic review — combines all data sources ────────────
    case 'ai_advisor_full_review': {
        RateLimit::check('ai_portfolio_review', $userId);

        // Gather everything
        $holdings = DB::fetchAll(
            "SELECT h.fund_name, h.units, h.units*COALESCE(n.nav,h.avg_cost_per_unit) AS cv, h.units*h.avg_cost_per_unit AS iv
             FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id
             WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0", [$userId, $portfolioId]
        );
        $totalValue = array_sum(array_column($holdings,'cv'));
        $totalInv   = array_sum(array_column($holdings,'iv'));
        $gainPct    = $totalInv>0 ? round(($totalValue-$totalInv)/$totalInv*100,2) : 0;

        $sips        = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
        $sipTotal    = (float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
        $goals       = DB::fetchAll("SELECT goal_name,target_amount,target_date,(SELECT COALESCE(SUM(amount),0) FROM goal_checkins WHERE goal_id=g.id) AS invested FROM goals g WHERE user_id=? AND status='active'",[$userId]);
        $profile     = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?",[$userId]);
        $insurance   = (float)(DB::fetchVal("SELECT COALESCE(SUM(sum_assured),0) FROM insurance_policies WHERE user_id=? AND status='active'",[$userId])??0);
        $loans       = (float)(DB::fetchVal("SELECT COALESCE(SUM(outstanding_principal),0) FROM loans WHERE user_id=? AND status='active'",[$userId])??0);

        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');

        $holdingLines = implode("\n", array_map(fn($h)=>"- {$h['fund_name']}: ".number_format((float)$h['cv'],0),$holdings));
        $goalLines = implode("\n", array_map(fn($g)=>"- {$g['goal_name']}: Target ".number_format((float)$g['target_amount'],0).", Invested ".number_format((float)$g['invested'],0)." by {$g['target_date']}",$goals));

        $prompt = "You are WealthDash's senior AI Portfolio Advisor for an Indian investor. Provide a COMPREHENSIVE holistic financial review.

PORTFOLIO SNAPSHOT:
- Total Value: ₹".number_format($totalValue,0)." | Invested: ₹".number_format($totalInv,0)." | Return: {$gainPct}%
- Active SIPs: {$sips} (₹".number_format($sipTotal,0)."/month)
- Risk Profile: ".($profile['risk_profile']??'moderate')."
- Insurance Cover: ₹".number_format($insurance,0)."
- Loan Outstanding: ₹".number_format($loans,0)."

HOLDINGS:
{$holdingLines}

GOALS:
{$goalLines}

Provide a comprehensive advisory covering:
1. Overall financial health (1 sentence verdict)
2. Portfolio strengths (2-3 points)
3. Areas of concern (2-3 points)
4. Insurance adequacy (rule of thumb: cover should be 10-15x annual income)
5. Top 3 prioritized action items for next 30 days
6. One long-term strategic suggestion (1-3 years)

Return ONLY valid JSON:
{\"verdict\":\"...\",\"health_score\":75,\"strengths\":[\"...\"],\"concerns\":[\"...\"],\"insurance_comment\":\"...\",\"action_items\":[{\"priority\":\"high\",\"action\":\"...\",\"reason\":\"...\"}],\"strategic_suggestion\":\"...\",\"disclaimer\":\"Main SEBI registered advisor nahi hoon...\"}";

        if (!$apiKey) {
            $healthScore = min(100, round(($gainPct>=10?30:15) + ($sips>=2?25:10) + (count($goals)>=1?25:0) + ($insurance>0?20:0)));
            json_response(true,'ok',[
                'mode'=>'rule_based',
                'verdict'=>$healthScore>=70?"Portfolio is in good shape!":"Portfolio needs attention in a few areas.",
                'health_score'=>$healthScore,
                'strengths'=>array_filter([$gainPct>=10?'Strong returns above 10%':null,$sips>=2?'Good SIP discipline':null,count($goals)>0?'Goals are defined':null]),
                'concerns'=>array_filter([$gainPct<5?'Returns below market average':null,$sips<2?'Limited SIP diversification':null,$insurance<=0?'No insurance coverage recorded':null]),
                'insurance_comment'=>$insurance>0?'Insurance cover recorded: ₹'.number_format($insurance,0):'No insurance policies found — consider term + health cover.',
                'action_items'=>[['priority'=>'high','action'=>'Complete your finance profile for accurate AI advice','reason'=>'Enables risk-based recommendations']],
                'strategic_suggestion'=>'Review asset allocation annually and rebalance based on life stage changes.',
                'disclaimer'=>'Main SEBI registered advisor nahi hoon — major decisions se pehle professional se milein.',
                'stats'=>['total_value'=>round($totalValue),'gain_pct'=>$gainPct,'sips'=>$sips,'goals'=>count($goals)],
            ]);
        }

        $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
            stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>900,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>30]]));
        if (!$resp) json_response(false,'AI service unavailable.');
        $text = json_decode($resp,true)['content'][0]['text'] ?? '{}';
        $text = trim(preg_replace(['/^```json\s*/m','/^```\s*/m'],'',$text));
        $parsed = json_decode($text, true);
        if (!$parsed) json_response(false,'AI response parse failed.');
        $parsed['mode'] = 'ai';
        $parsed['stats'] = ['total_value'=>round($totalValue),'gain_pct'=>$gainPct,'sips'=>$sips,'goals'=>count($goals)];

        // Save to history
        DB::execute("INSERT INTO ai_advisor_sessions(user_id,session_type,response_json,created_at) VALUES(?,?,?,NOW())",[$userId,'full_review',json_encode($parsed)]);

        json_response(true,'ok',$parsed);
        break;
    }

    // ── Conversational follow-up question ────────────────────────────
    case 'ai_advisor_ask': {
        csrf_verify();
        RateLimit::check('ai_chat', $userId);
        $question = trim($_POST['question'] ?? '');
        if (!$question) json_response(false,'Question required.');

        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');
        if (!$apiKey) json_response(false,'AI service not configured.');

        // Get last full review for context
        $lastReview = DB::fetchRow("SELECT response_json FROM ai_advisor_sessions WHERE user_id=? AND session_type='full_review' ORDER BY created_at DESC LIMIT 1",[$userId]);
        $context = $lastReview ? $lastReview['response_json'] : '{}';

        $prompt = "You are WealthDash's AI Portfolio Advisor continuing a conversation with an Indian investor.

PREVIOUS REVIEW CONTEXT:
{$context}

FOLLOW-UP QUESTION: \"{$question}\"

Answer in Hinglish, max 150 words, specific and actionable. End with disclaimer if giving investment advice.";

        $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
            stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>400,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>20]]));
        if (!$resp) json_response(false,'AI service unavailable.');
        $answer = json_decode($resp,true)['content'][0]['text'] ?? 'Unable to answer.';

        DB::execute("INSERT INTO ai_advisor_sessions(user_id,session_type,response_json,created_at) VALUES(?,?,?,NOW())",[$userId,'follow_up',json_encode(['question'=>$question,'answer'=>$answer])]);

        json_response(true,'ok',['answer'=>$answer]);
        break;
    }

    // ── Action plan history ──────────────────────────────────────────
    case 'ai_advisor_action_plan': {
        $rows = DB::fetchAll("SELECT response_json, created_at FROM ai_advisor_sessions WHERE user_id=? AND session_type='full_review' ORDER BY created_at DESC LIMIT 5",[$userId]);
        json_response(true,'ok',['sessions'=>array_map(fn($r)=>array_merge(json_decode($r['response_json'],true),['created_at'=>$r['created_at']]),$rows)]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
