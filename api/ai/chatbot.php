<?php
/**
 * WealthDash — t330: AI Chatbot — Ask About Investments
 * File: api/ai/chatbot.php
 * Actions: ai_chat_send, ai_chat_history, ai_chat_clear, ai_chat_sessions
 *
 * Builds on existing chatbot.php pattern from project.
 * Uses Claude API with portfolio context. Falls back to rule-based.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

// System prompt
$SYSTEM_PROMPT = "Tum WealthDash ke AI Financial Assistant ho. Ek friendly, knowledgeable Indian financial advisor ki tarah baat karo.

RULES:
1. Hinglish mein respond karo (Hindi + English mix) — simple aur clear
2. Indian context: SEBI, RBI, BSE/NSE, Indian tax laws, FY April-March
3. Numbers: ₹ sign, Indian format (lakhs/crores)
4. Concise: 3-5 sentences max unless detail maanga ho
5. Disclaimer add karo: 'Main SEBI registered advisor nahi hoon'
6. Portfolio data ke baare mein specific answers do
7. Agar data nahi hai to honestly batao

PORTFOLIO CONTEXT:
__PORTFOLIO__

Agar user koi aise cheez pooche jo portfolio data mein nahi hai, honestly batao.";

switch ($action) {

    case 'ai_chat_send': {
        csrf_verify();
        RateLimit::check('ai_chat', $userId);

        $message   = trim($_POST['message'] ?? '');
        $contextId = clean($_POST['context_id'] ?? bin2hex(random_bytes(8)));

        if (!$message)             json_response(false, 'Message empty hai.');
        if (mb_strlen($message) > 2000) json_response(false, 'Message bahut lamba hai (max 2000 chars).');

        // Portfolio context
        $holdings = DB::fetchAll(
            "SELECT h.fund_name, h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS value,
                    (h.units * COALESCE(n.nav, h.avg_cost_per_unit)) - (h.units * h.avg_cost_per_unit) AS gain
             FROM mf_holdings h
             LEFT JOIN mf_nav_latest n ON n.mf_id = h.mf_id
             WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0
             ORDER BY value DESC LIMIT 10",
            [$userId, $portfolioId]
        );
        $totalValue = array_sum(array_column($holdings, 'value'));
        $totalGain  = array_sum(array_column($holdings, 'gain'));
        $gainPct    = ($totalValue - $totalGain) > 0 ? round($totalGain / ($totalValue - $totalGain) * 100, 2) : 0;

        $activeSIPs = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]) ?? 0);
        $sipTotal   = (float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]) ?? 0);

        $portfolioCtx = "Portfolio Value: ₹" . number_format($totalValue, 0) .
                        "\nTotal Gain: ₹" . number_format($totalGain, 0) . " ({$gainPct}%)" .
                        "\nActive SIPs: {$activeSIPs} (₹" . number_format($sipTotal, 0) . "/month)" .
                        "\nTop Holdings:\n" . implode("\n", array_map(
                            fn($h) => "- {$h['fund_name']}: ₹" . number_format((float)$h['value'], 0),
                            array_slice($holdings, 0, 5)
                        ));

        $systemPrompt = str_replace('__PORTFOLIO__', $portfolioCtx, $SYSTEM_PROMPT);

        // Get recent history
        $history = DB::fetchAll(
            "SELECT role, message FROM ai_chat_history
             WHERE user_id=? AND context_id=?
             ORDER BY created_at DESC LIMIT 10",
            [$userId, $contextId]
        );
        $history = array_reverse($history);

        // Save user message
        DB::execute(
            "INSERT INTO ai_chat_history(user_id, role, message, context_id, created_at) VALUES(?,?,?,?,NOW())",
            [$userId, 'user', $message, $contextId]
        );

        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');

        if (!$apiKey) {
            // Rule-based fallback
            $reply = _chatbot_fallback($message, $totalValue, $gainPct, $activeSIPs, $sipTotal, $holdings);
            DB::execute("INSERT INTO ai_chat_history(user_id,role,message,context_id,created_at) VALUES(?,?,?,?,NOW())", [$userId,'assistant',$reply,$contextId]);
            json_response(true,'ok',['reply'=>$reply,'context_id'=>$contextId,'mode'=>'rule_based']);
        }

        $messages = array_map(fn($h) => ['role'=>$h['role'],'content'=>$h['message']], $history);
        $messages[] = ['role'=>'user','content'=>$message];

        $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
            stream_context_create(['http'=>[
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",
                'content' => json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>600,'system'=>$systemPrompt,'messages'=>$messages]),
                'timeout' => 25,
            ]]));

        if (!$resp) json_response(false, 'AI service se response nahi aaya. Thodi der baad try karo.');

        $data  = json_decode($resp, true);
        $reply = $data['content'][0]['text'] ?? '';
        if (!$reply) json_response(false, 'AI response empty tha.');

        DB::execute("INSERT INTO ai_chat_history(user_id,role,message,context_id,created_at) VALUES(?,?,?,?,NOW())", [$userId,'assistant',$reply,$contextId]);

        json_response(true,'ok',[
            'reply'       => $reply,
            'context_id'  => $contextId,
            'mode'        => 'ai',
            'tokens_used' => $data['usage']['output_tokens'] ?? 0,
        ]);
        break;
    }

    case 'ai_chat_history': {
        $contextId = clean($_GET['context_id'] ?? $_POST['context_id'] ?? '');
        $limit     = min((int)($_GET['limit'] ?? 50), 100);
        if ($contextId) {
            $msgs = DB::fetchAll("SELECT role,message,created_at FROM ai_chat_history WHERE user_id=? AND context_id=? ORDER BY created_at ASC LIMIT ?", [$userId,$contextId,$limit]);
        } else {
            $msgs = DB::fetchAll("SELECT role,message,context_id,created_at FROM ai_chat_history WHERE user_id=? ORDER BY created_at DESC LIMIT ?", [$userId,$limit]);
        }
        json_response(true,'ok',['messages'=>$msgs]);
        break;
    }

    case 'ai_chat_sessions': {
        $sessions = DB::fetchAll(
            "SELECT context_id, MIN(created_at) AS started_at, COUNT(*) AS msg_count,
                    MAX(created_at) AS last_msg
             FROM ai_chat_history WHERE user_id=? GROUP BY context_id ORDER BY last_msg DESC LIMIT 10",
            [$userId]
        );
        json_response(true,'ok',['sessions'=>$sessions]);
        break;
    }

    case 'ai_chat_clear': {
        csrf_verify();
        $contextId = clean($_POST['context_id'] ?? '');
        if ($contextId) DB::execute("DELETE FROM ai_chat_history WHERE user_id=? AND context_id=?", [$userId,$contextId]);
        else            DB::execute("DELETE FROM ai_chat_history WHERE user_id=?", [$userId]);
        json_response(true,'Chat clear ho gayi.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}

// Rule-based fallback responses
function _chatbot_fallback(string $msg, float $totalValue, float $gainPct, int $sips, float $sipTotal, array $holdings): string {
    $msg = strtolower($msg);
    if (str_contains($msg, 'portfolio') || str_contains($msg, 'value')) {
        return "Aapka portfolio abhi ₹" . number_format($totalValue, 0) . " ka hai, total return " . ($gainPct >= 0 ? '+' : '') . "{$gainPct}% hai. {$sips} active SIP chal raha hai ₹" . number_format($sipTotal, 0) . "/month ke saath. (Note: AI service configure nahi hai — ANTHROPIC_API_KEY set karo for full AI chat.)";
    }
    if (str_contains($msg, 'sip')) {
        return "Aapke paas {$sips} active SIP hain, total ₹" . number_format($sipTotal, 0) . "/month. SIP mein consistency sabse important hai — market ups and downs mein bhi invest karte rehna chahiye.";
    }
    if (str_contains($msg, 'tax') || str_contains($msg, 'ltcg') || str_contains($msg, 'stcg')) {
        return "Equity mutual funds mein 1 saal se zyada hold karne par LTCG @12.5% (₹1.25L tak exempt), 1 saal se kam par STCG @20% lagta hai. Debt funds par income tax slab ke hisaab se tax lagta hai. Specific advice ke liye CA se milein.";
    }
    if (str_contains($msg, 'market') || str_contains($msg, 'nifty') || str_contains($msg, 'sensex')) {
        return "Main real-time market data nahi de sakta, lekin Indian equity markets long term mein 12-15% CAGR dete aaye hain. SIP ke zariye market timing ki chinta nahi karni chahiye — rupee cost averaging automatically kaam karta hai.";
    }
    return "Main AI chat ke liye ready hoon, lekin abhi AI key configure nahi hai. Aap ANTHROPIC_API_KEY .env mein set karo. Tab main aapke portfolio ke baare mein detail mein baat kar sakta hoon. Koi bhi investment related sawaal pooch sakte hain!";
}
