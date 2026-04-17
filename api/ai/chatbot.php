<?php
/**
 * WealthDash — t381: AI Chat Assistant
 * Portfolio ke baare mein natural language chat using Claude API
 * Actions: ai_chat_send | ai_chat_history | ai_chat_clear
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'ai_chat_send';

// Ensure chat history table
try {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS ai_chat_history (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            role       ENUM('user','assistant') NOT NULL,
            message    TEXT NOT NULL,
            context_id VARCHAR(36) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_ctx (user_id, context_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

RateLimit::check('ai_chat', $userId);

// ── Get user's portfolio summary for AI context ────────────────
function getPortfolioContext(int $userId): string {
    try {
        // MF Holdings
        $mf = DB::fetchAll(
            "SELECT f.fund_name, mh.units, mh.current_nav, mh.current_value, mh.invested_amount,
                    ROUND((mh.current_value - mh.invested_amount) / mh.invested_amount * 100, 2) AS gain_pct
             FROM mf_holdings mh
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ? AND mh.is_active = 1
             ORDER BY mh.current_value DESC LIMIT 15",
            [$userId]
        );

        // FDs
        $fds = DB::fetchAll(
            "SELECT bank_name, principal_amount, interest_rate, maturity_date, maturity_amount
             FROM fd_investments WHERE user_id = ? AND status = 'active' LIMIT 10",
            [$userId]
        );

        // NPS
        $nps = DB::fetchAll(
            "SELECT pfm_name, tier, current_value, invested_amount FROM nps_accounts WHERE user_id = ? AND is_active = 1",
            [$userId]
        );

        // Net worth summary
        $mfTotal  = array_sum(array_column($mf, 'current_value'));
        $fdTotal  = array_sum(array_column($fds, 'principal_amount'));
        $npsTotal = array_sum(array_column($nps, 'current_value'));

        $lines = [];
        $lines[] = "=== WealthDash Portfolio Summary (User #{$userId}) ===";
        $lines[] = "Net Worth (approx): ₹" . number_format($mfTotal + $fdTotal + $npsTotal, 0);
        $lines[] = "";

        if ($mf) {
            $lines[] = "MUTUAL FUNDS (Top " . count($mf) . "):";
            foreach ($mf as $f) {
                $sign = $f['gain_pct'] >= 0 ? '+' : '';
                $lines[] = "  • {$f['fund_name']}: ₹" . number_format($f['current_value'], 0)
                         . " invested ₹" . number_format($f['invested_amount'], 0)
                         . " ({$sign}{$f['gain_pct']}%)";
            }
            $lines[] = "  Total MF: ₹" . number_format($mfTotal, 0);
            $lines[] = "";
        }

        if ($fds) {
            $lines[] = "FIXED DEPOSITS:";
            foreach ($fds as $f) {
                $lines[] = "  • {$f['bank_name']}: ₹" . number_format($f['principal_amount'], 0)
                         . " @ {$f['interest_rate']}% matures " . date('d M Y', strtotime($f['maturity_date']));
            }
            $lines[] = "  Total FD: ₹" . number_format($fdTotal, 0);
            $lines[] = "";
        }

        if ($nps) {
            $lines[] = "NPS:";
            foreach ($nps as $n) {
                $lines[] = "  • {$n['pfm_name']} Tier-{$n['tier']}: ₹" . number_format($n['current_value'], 0);
            }
            $lines[] = "  Total NPS: ₹" . number_format($npsTotal, 0);
        }

        return implode("\n", $lines);

    } catch (Exception $e) {
        return "Portfolio data fetch karne mein error aaya: " . $e->getMessage();
    }
}

$SYSTEM_PROMPT = <<<PROMPT
Tum WealthDash ke AI Financial Assistant ho. Tum ek knowledgeable, helpful, aur honest Indian financial advisor ki tarah behave karo.

KEY RULES:
1. ALWAYS respond in Hinglish (Hindi + English mix) jaise is user ke saath baat ho rahi hai — friendly aur simple
2. Indian tax laws, SEBI rules, aur Indian market context use karo
3. Specific numbers provide karo jab available ho (portfolio data neeche diya hai)
4. Disclaimer: "Main SEBI registered advisor nahi hoon — major decisions se pehle professional se milein"
5. Concise raho — 3-5 paragraphs maximum unless user detail maange
6. Numbers Indian format mein (₹ sign, lakhs/crores preferred)
7. Tax FY April-March follow karo

PORTFOLIO DATA:
{PORTFOLIO_CONTEXT}

Agar koi cheez portfolio data mein nahi hai to honestly batao. Assumptions mat banao.
PROMPT;

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── SEND MESSAGE ───────────────────────────────────────────
    case 'ai_chat_send':
        csrf_verify();
        $message   = trim($_POST['message'] ?? '');
        $contextId = clean($_POST['context_id'] ?? bin2hex(random_bytes(8)));

        if (!$message) json_response(false, 'Message empty hai.');
        if (mb_strlen($message) > 2000) json_response(false, 'Message bahut lamba hai (max 2000 chars).');

        // Get recent history (last 8 messages for context)
        $history = DB::fetchAll(
            "SELECT role, message FROM ai_chat_history
             WHERE user_id = ? AND context_id = ?
             ORDER BY created_at DESC LIMIT 8",
            [$userId, $contextId]
        );
        $history = array_reverse($history);

        // Build messages array for Claude
        $messages = [];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['message']];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        // Get portfolio context
        $portfolioCtx = getPortfolioContext($userId);
        $systemPrompt = str_replace('{PORTFOLIO_CONTEXT}', $portfolioCtx, $SYSTEM_PROMPT);

        // Save user message
        DB::run(
            "INSERT INTO ai_chat_history (user_id, role, message, context_id) VALUES (?, 'user', ?, ?)",
            [$userId, $message, $contextId]
        );

        // Call Claude API
        $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?? '';
        if (!$apiKey) {
            json_response(false, 'AI service configure nahi hai. Admin se contact karo.');
        }

        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1024,
            'system'     => $systemPrompt,
            'messages'   => $messages,
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $resp    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            json_response(false, 'AI service se response nahi aaya. Thodi der baad try karo.');
        }

        $data = json_decode($resp, true);
        $reply = $data['content'][0]['text'] ?? '';

        if (!$reply) {
            json_response(false, 'AI ka response empty tha. Dobara try karo.');
        }

        // Save assistant reply
        DB::run(
            "INSERT INTO ai_chat_history (user_id, role, message, context_id) VALUES (?, 'assistant', ?, ?)",
            [$userId, $reply, $contextId]
        );

        json_response(true, '', [
            'reply'      => $reply,
            'context_id' => $contextId,
            'tokens_used'=> $data['usage']['output_tokens'] ?? 0,
        ]);

    // ── GET HISTORY ────────────────────────────────────────────
    case 'ai_chat_history':
        $contextId = clean($_GET['context_id'] ?? '');
        $limit     = min((int) ($_GET['limit'] ?? 50), 100);

        $query  = $contextId
            ? "SELECT role, message, context_id, created_at FROM ai_chat_history WHERE user_id = ? AND context_id = ? ORDER BY created_at ASC LIMIT ?"
            : "SELECT role, message, context_id, created_at FROM ai_chat_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
        $params = $contextId ? [$userId, $contextId, $limit] : [$userId, $limit];

        $messages = DB::fetchAll($query, $params);
        json_response(true, '', ['messages' => $messages]);

    // ── CLEAR HISTORY ──────────────────────────────────────────
    case 'ai_chat_clear':
        csrf_verify();
        $contextId = clean($_POST['context_id'] ?? '');
        if ($contextId) {
            DB::run("DELETE FROM ai_chat_history WHERE user_id = ? AND context_id = ?", [$userId, $contextId]);
        } else {
            DB::run("DELETE FROM ai_chat_history WHERE user_id = ?", [$userId]);
        }
        json_response(true, 'Chat history clear ho gayi.');

    default:
        json_response(false, 'Unknown AI chat action.', [], 400);
}
