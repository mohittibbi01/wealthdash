<?php
/**
 * WealthDash — t381: AI Chat Assistant — Portfolio Q&A
 * Conversational AI for portfolio questions using Anthropic Claude
 *
 * Actions (read-only — CSRF exempt):
 *   ai_chat_history      — list past sessions
 *   ai_chat_session_get  — get messages of a session
 *   ai_chat_usage        — today's usage stats
 *
 * Actions (write — CSRF required):
 *   ai_chat_message      — send message, get AI reply
 *   ai_chat_session_new  — start fresh session
 *   ai_chat_session_delete — delete session
 *   ai_chat_clear_all    — clear all sessions
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'ai_chat_history';

// ── CONFIG ─────────────────────────────────────────────────────────────────
const AI_CHAT_MODEL         = 'claude-sonnet-4-20250514';
const AI_CHAT_MAX_TOKENS    = 1024;
const AI_CHAT_DAILY_LIMIT   = 30;   // messages per day
const AI_CHAT_MAX_HISTORY   = 20;   // context messages sent to API
const AI_CHAT_SESSION_LIMIT = 50;   // max sessions kept per user

function _chat_api_key(): string {
    return defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY
        : (getenv('ANTHROPIC_API_KEY') ?: '');
}

// ── HELPERS ────────────────────────────────────────────────────────────────

function _chat_portfolio_id(int $userId): int {
    return (int)(DB::fetchVal(
        "SELECT id FROM portfolios WHERE user_id=? LIMIT 1",
        [$userId]
    ) ?? 0);
}

/**
 * Compact portfolio context for AI system prompt
 */
function _chat_portfolio_context(int $userId, int $portfolioId): string {
    $mf_total = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(current_value),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    ) ?? 0);
    $mf_invested = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(invested_amount),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    ) ?? 0);
    $sip_monthly = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(monthly_amount),0) FROM sip_schedules WHERE portfolio_id=? AND status='active'",
        [$portfolioId]
    ) ?? 0);
    $top_funds = DB::fetchAll(
        "SELECT f.fund_name, f.category, ROUND(mh.current_value,0) AS value
         FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id
         WHERE mh.portfolio_id=? AND mh.is_active=1
         ORDER BY mh.current_value DESC LIMIT 8",
        [$portfolioId]
    );
    $nps = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(nh.current_value),0) FROM nps_holdings nh
         JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=?",
        [$userId]
    ) ?? 0);
    $fd = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(principal),0) FROM fd_accounts WHERE portfolio_id=? AND status='active'",
        [$portfolioId]
    ) ?? 0);
    $goals = DB::fetchAll(
        "SELECT name, target_amount, current_amount, target_date
         FROM goal_buckets WHERE user_id=? AND is_achieved=0 ORDER BY target_date LIMIT 5",
        [$userId]
    );

    $fundsText = implode(', ', array_map(
        fn($f) => "{$f['fund_name']} ({$f['category']}: ₹" . number_format($f['value'], 0) . ")",
        $top_funds
    ));
    $goalsText = implode('; ', array_map(
        fn($g) => "{$g['name']}: ₹" . number_format($g['target_amount'], 0) . " by {$g['target_date']}",
        $goals
    ));

    return <<<CTX
USER PORTFOLIO (as of today {date('Y-m-d')}):
- Total MF Value: ₹{$mf_total} | Invested: ₹{$mf_invested}
- Monthly SIP: ₹{$sip_monthly}
- NPS: ₹{$nps} | FD: ₹{$fd}
- Top Holdings: {$fundsText}
- Goals: {$goalsText}
CTX;
}

/**
 * Build system prompt for chat
 */
function _chat_system_prompt(string $portfolioContext, string $lang): string {
    $langNote = $lang === 'hi-en'
        ? 'Always respond in Hinglish (mix of Hindi + English) — casual, friendly, like a CA dost baat kar raha ho.'
        : 'Respond in clear, friendly English.';

    return <<<SYSPROMPT
You are WealthDash AI — a personal financial advisor for an Indian investor.
{$langNote}

{$portfolioContext}

YOUR RULES:
1. Only answer questions related to personal finance, investments, taxes, mutual funds, NPS, FD, goals, market, etc.
2. For off-topic questions, politely redirect: "Yaar, main sirf investment aur finance ke baare mein help kar sakta hoon!"
3. Give specific, actionable advice based on the portfolio data above.
4. Always mention India-specific context: SEBI rules, Indian tax laws, AMFI, RBI, etc.
5. Keep replies concise — max 3-4 paragraphs. Use bullet points for lists.
6. Never recommend specific stocks or promise returns.
7. Add a brief disclaimer for major financial decisions: "Please consult a SEBI-registered advisor for large decisions."

Date: {date('Y-m-d')}
SYSPROMPT;
}

/**
 * Call Anthropic API for chat
 */
function _chat_call_ai(string $systemPrompt, array $messages, int &$tokensUsed): string {
    $apiKey = _chat_api_key();
    if (!$apiKey) return 'Sorry, AI service abhi configure nahi hua. Admin se baat karo. 🙏';

    // Keep only last N messages for context window
    $contextMessages = array_slice($messages, -AI_CHAT_MAX_HISTORY);

    $payload = [
        'model'      => AI_CHAT_MODEL,
        'max_tokens' => AI_CHAT_MAX_TOKENS,
        'system'     => $systemPrompt,
        'messages'   => $contextMessages,
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 45,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return 'AI service se response nahi aaya. Thodi der baad try karo. 🔄';
    }

    $data       = json_decode($response, true);
    $tokensUsed = (int)($data['usage']['output_tokens'] ?? 0);
    return $data['content'][0]['text'] ?? 'Kuch problem aa gayi. Dobara try karo!';
}

/**
 * Ensure session belongs to user
 */
function _chat_session_owned(int $userId, string $sessionKey): ?array {
    return DB::fetchOne(
        "SELECT * FROM ai_chat_sessions WHERE session_key=? AND user_id=?",
        [$sessionKey, $userId]
    ) ?: null;
}

function _chat_usage_today(int $userId): int {
    return (int)(DB::fetchVal(
        "SELECT request_count FROM ai_usage_log WHERE user_id=? AND feature='chat' AND usage_date=CURDATE()",
        [$userId]
    ) ?? 0);
}

// ── ACTIONS ────────────────────────────────────────────────────────────────

switch ($action) {

    // ── ai_chat_message ───────────────────────────────────────────────────
    case 'ai_chat_message': {
        require_csrf();

        // Rate limit check
        $usageToday = _chat_usage_today($userId);
        if ($usageToday >= AI_CHAT_DAILY_LIMIT) {
            json_response(false, 'Daily chat limit reached! (' . AI_CHAT_DAILY_LIMIT . ' messages). Kal phir aana! 😊', [
                'limit'     => AI_CHAT_DAILY_LIMIT,
                'used'      => $usageToday,
                'remaining' => 0,
            ], 429);
        }

        $userMessage = trim(clean($_POST['message'] ?? ''));
        $sessionKey  = clean($_POST['session_key'] ?? '');
        $lang        = clean($_POST['lang'] ?? 'hi-en');

        if (!$userMessage) json_response(false, 'Message empty hai bhai!');
        if (mb_strlen($userMessage) > 1000) {
            json_response(false, 'Message too long. Max 1000 characters.');
        }

        $portfolioId = _chat_portfolio_id($userId);

        // Get or create session
        if ($sessionKey) {
            $session = _chat_session_owned($userId, $sessionKey);
        }

        if (empty($session)) {
            // Create new session
            $sessionKey = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $ctxSnap    = _chat_portfolio_context($userId, $portfolioId);
            $sessionId  = DB::insert(
                "INSERT INTO ai_chat_sessions (user_id, portfolio_id, session_key, context_snap) VALUES (?,?,?,?)",
                [$userId, $portfolioId, $sessionKey, $ctxSnap]
            );
            $session = ['id' => $sessionId, 'session_key' => $sessionKey, 'context_snap' => $ctxSnap, 'message_count' => 0];
        }

        $sessionId = (int)$session['id'];

        // Load message history for context
        $history = DB::fetchAll(
            "SELECT role, content FROM ai_chat_messages WHERE session_id=? ORDER BY created_at ASC",
            [$sessionId]
        );

        // Build messages array for API
        $apiMessages = array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $history);
        $apiMessages[] = ['role' => 'user', 'content' => $userMessage];

        // System prompt with portfolio context
        $ctxSnap    = $session['context_snap'] ?? _chat_portfolio_context($userId, $portfolioId);
        $systemPmt  = _chat_system_prompt($ctxSnap, $lang);

        // Call AI
        $tokensUsed  = 0;
        $aiReply     = _chat_call_ai($systemPmt, $apiMessages, $tokensUsed);

        // Save user message
        DB::insert(
            "INSERT INTO ai_chat_messages (session_id, role, content) VALUES (?,?,?)",
            [$sessionId, 'user', $userMessage]
        );

        // Save AI reply
        $replyMsgId = DB::insert(
            "INSERT INTO ai_chat_messages (session_id, role, content, tokens_used) VALUES (?,?,?,?)",
            [$sessionId, 'assistant', $aiReply, $tokensUsed]
        );

        // Update session
        $newCount = (int)$session['message_count'] + 2;

        // Auto-title from first user message
        $titleUpdate = '';
        if ($newCount <= 2) {
            $autoTitle = mb_substr($userMessage, 0, 60) . (mb_strlen($userMessage) > 60 ? '…' : '');
            DB::run(
                "UPDATE ai_chat_sessions SET message_count=?, title=?, last_activity=NOW() WHERE id=?",
                [$newCount, $autoTitle, $sessionId]
            );
        } else {
            DB::run(
                "UPDATE ai_chat_sessions SET message_count=?, last_activity=NOW() WHERE id=?",
                [$newCount, $sessionId]
            );
        }

        // Update usage
        DB::run(
            "INSERT INTO ai_usage_log (user_id, feature, usage_date, request_count, tokens_total)
             VALUES (?,?,CURDATE(),1,?)
             ON DUPLICATE KEY UPDATE request_count=request_count+1, tokens_total=tokens_total+?",
            [$userId, 'chat', $tokensUsed, $tokensUsed]
        );

        $usedNow = $usageToday + 1;

        json_response(true, '', [
            'reply'        => $aiReply,
            'reply_msg_id' => (int)$replyMsgId,
            'session_key'  => $sessionKey,
            'tokens'       => $tokensUsed,
            'usage_today'  => $usedNow,
            'remaining'    => max(0, AI_CHAT_DAILY_LIMIT - $usedNow),
        ]);
    }

    // ── ai_chat_session_new ───────────────────────────────────────────────
    case 'ai_chat_session_new': {
        require_csrf();

        // Enforce session cap — prune oldest if over limit
        $sessionCount = (int)(DB::fetchVal(
            "SELECT COUNT(*) FROM ai_chat_sessions WHERE user_id=?",
            [$userId]
        ) ?? 0);

        if ($sessionCount >= AI_CHAT_SESSION_LIMIT) {
            // Delete oldest sessions
            DB::run(
                "DELETE FROM ai_chat_sessions WHERE user_id=?
                 ORDER BY last_activity ASC LIMIT ?",
                [$userId, $sessionCount - AI_CHAT_SESSION_LIMIT + 10]
            );
        }

        $portfolioId = _chat_portfolio_id($userId);
        $sessionKey  = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $ctxSnap = _chat_portfolio_context($userId, $portfolioId);
        $sessionId = DB::insert(
            "INSERT INTO ai_chat_sessions (user_id, portfolio_id, session_key, context_snap) VALUES (?,?,?,?)",
            [$userId, $portfolioId, $sessionKey, $ctxSnap]
        );

        json_response(true, 'New session started.', [
            'session_id'  => (int)$sessionId,
            'session_key' => $sessionKey,
        ]);
    }

    // ── ai_chat_session_get ───────────────────────────────────────────────
    case 'ai_chat_session_get': {
        $sessionKey = clean($_GET['session_key'] ?? $_POST['session_key'] ?? '');
        if (!$sessionKey) json_response(false, 'session_key required.');

        $session = _chat_session_owned($userId, $sessionKey);
        if (!$session) json_response(false, 'Session not found.', [], 404);

        $messages = DB::fetchAll(
            "SELECT id, role, content, tokens_used, created_at
             FROM ai_chat_messages WHERE session_id=? ORDER BY created_at ASC",
            [(int)$session['id']]
        );

        $session['context_snap'] = null; // don't expose raw context
        json_response(true, '', ['session' => $session, 'messages' => $messages]);
    }

    // ── ai_chat_history ───────────────────────────────────────────────────
    case 'ai_chat_history': {
        $limit = min(50, (int)($_GET['limit'] ?? 20));

        $sessions = DB::fetchAll(
            "SELECT id, session_key, title, message_count, last_activity, created_at
             FROM ai_chat_sessions WHERE user_id=?
             ORDER BY last_activity DESC LIMIT ?",
            [$userId, $limit]
        );

        $usageToday = _chat_usage_today($userId);

        json_response(true, '', [
            'sessions'    => $sessions,
            'usage_today' => $usageToday,
            'daily_limit' => AI_CHAT_DAILY_LIMIT,
            'remaining'   => max(0, AI_CHAT_DAILY_LIMIT - $usageToday),
        ]);
    }

    // ── ai_chat_usage ─────────────────────────────────────────────────────
    case 'ai_chat_usage': {
        $usageToday = _chat_usage_today($userId);

        // Last 7 days usage
        $weekUsage = DB::fetchAll(
            "SELECT usage_date, request_count, tokens_total
             FROM ai_usage_log
             WHERE user_id=? AND feature='chat' AND usage_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             ORDER BY usage_date DESC",
            [$userId]
        );

        json_response(true, '', [
            'usage_today'  => $usageToday,
            'daily_limit'  => AI_CHAT_DAILY_LIMIT,
            'remaining'    => max(0, AI_CHAT_DAILY_LIMIT - $usageToday),
            'week_history' => $weekUsage,
        ]);
    }

    // ── ai_chat_session_delete ────────────────────────────────────────────
    case 'ai_chat_session_delete': {
        require_csrf();
        $sessionKey = clean($_POST['session_key'] ?? '');
        if (!$sessionKey) json_response(false, 'session_key required.');

        $deleted = DB::run(
            "DELETE FROM ai_chat_sessions WHERE session_key=? AND user_id=?",
            [$sessionKey, $userId]
        );

        json_response((bool)$deleted, $deleted ? 'Session deleted.' : 'Session not found.');
    }

    // ── ai_chat_clear_all ─────────────────────────────────────────────────
    case 'ai_chat_clear_all': {
        require_csrf();

        DB::run("DELETE FROM ai_chat_sessions WHERE user_id=?", [$userId]);

        json_response(true, 'Sab sessions delete ho gaye. Fresh start! 🌱');
    }

    default:
        json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);
}
