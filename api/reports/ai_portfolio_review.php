<?php
/**
 * WealthDash — t380: AI Portfolio Review
 * Monthly AI-generated portfolio analysis via Anthropic Claude
 *
 * Actions (read-only — CSRF exempt):
 *   ai_review_get        — get latest review for current portfolio
 *   ai_review_history    — list past reviews
 *   ai_review_status     — check if generation is in progress
 *   ai_review_prefs_get  — get user AI prefs
 *
 * Actions (write — CSRF required):
 *   ai_review_generate   — trigger new review generation
 *   ai_review_prefs_save — save user AI prefs
 *   ai_review_delete     — delete a review
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'ai_review_get';

// ── CONFIG ─────────────────────────────────────────────────────────────────
const AI_REVIEW_MODEL       = 'claude-sonnet-4-20250514';
const AI_REVIEW_MAX_TOKENS  = 2048;
const AI_DAILY_REVIEW_LIMIT = 3;   // max on-demand reviews per day

// Anthropic API key from app config
function _ai_api_key(): string {
    return defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY
        : (getenv('ANTHROPIC_API_KEY') ?: '');
}

// ── HELPERS ────────────────────────────────────────────────────────────────

function _ai_portfolio_id(int $userId): int {
    return (int)(DB::fetchVal(
        "SELECT id FROM portfolios WHERE user_id=? LIMIT 1",
        [$userId]
    ) ?? 0);
}

/**
 * Build a compact portfolio snapshot to send to AI
 */
function _build_portfolio_snapshot(int $userId, int $portfolioId): array {
    // Total net-worth
    $mf = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(current_value),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    ) ?? 0);
    $mf_invested = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(invested_amount),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    ) ?? 0);

    // MF breakdown by category
    $mf_by_cat = DB::fetchAll(
        "SELECT f.category, COUNT(*) AS count,
                ROUND(SUM(mh.current_value),0) AS value,
                ROUND(SUM(mh.invested_amount),0) AS invested
         FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id
         WHERE mh.portfolio_id=? AND mh.is_active=1
         GROUP BY f.category",
        [$portfolioId]
    );

    // Top 5 holdings
    $top5 = DB::fetchAll(
        "SELECT f.fund_name, f.category, mh.current_value, mh.invested_amount,
                ROUND((mh.current_value - mh.invested_amount)/NULLIF(mh.invested_amount,0)*100,1) AS xirr_approx
         FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id
         WHERE mh.portfolio_id=? AND mh.is_active=1
         ORDER BY mh.current_value DESC LIMIT 5",
        [$portfolioId]
    );

    // Active SIPs
    $sip_total = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(monthly_amount),0) FROM sip_schedules WHERE portfolio_id=? AND status='active'",
        [$portfolioId]
    ) ?? 0);
    $sip_count = (int)(DB::fetchVal(
        "SELECT COUNT(*) FROM sip_schedules WHERE portfolio_id=? AND status='active'",
        [$portfolioId]
    ) ?? 0);

    // NPS
    $nps_value = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(current_value),0) FROM nps_holdings nh
         JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=?",
        [$userId]
    ) ?? 0);

    // FD
    $fd_value = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(fd.principal),0) FROM fd_accounts fd
         JOIN portfolios p ON p.id=fd.portfolio_id WHERE p.user_id=? AND fd.status='active'",
        [$userId]
    ) ?? 0);

    // Goals progress
    $goals = DB::fetchAll(
        "SELECT name, target_amount, current_amount, target_date,
                ROUND(current_amount/NULLIF(target_amount,0)*100,1) AS pct
         FROM goal_buckets WHERE user_id=? AND is_achieved=0 ORDER BY target_date LIMIT 5",
        [$userId]
    );

    // Recent LTCG check (current FY)
    $fy_start = (date('n') >= 4 ? date('Y') : date('Y')-1) . '-04-01';
    $ltcg = (float)(DB::fetchVal(
        "SELECT COALESCE(SUM(tr.amount - tr.units*mh.avg_cost_nav),0)
         FROM mf_transactions tr
         JOIN mh ON mh.id=tr.holding_id
         JOIN portfolios p ON p.id=mh.portfolio_id
         WHERE p.user_id=? AND tr.transaction_type IN('redeem','switch_out')
           AND tr.transaction_date >= ? AND f.asset_class='equity'
           AND DATEDIFF(tr.transaction_date,mh.first_investment_date)>=365",
        [$userId, $fy_start]
    ) ?? 0);

    $totalValue    = $mf + $nps_value + $fd_value;
    $totalInvested = $mf_invested;
    $overallReturn = $totalInvested > 0 ? round(($totalValue - $totalInvested) / $totalInvested * 100, 1) : 0;

    return [
        'total_value'     => round($totalValue, 0),
        'total_invested'  => round($totalInvested, 0),
        'overall_return_pct' => $overallReturn,
        'mf_value'        => round($mf, 0),
        'mf_invested'     => round($mf_invested, 0),
        'mf_by_category'  => $mf_by_cat,
        'top5_holdings'   => $top5,
        'active_sip_count'=> $sip_count,
        'monthly_sip_total' => round($sip_total, 0),
        'nps_value'       => round($nps_value, 0),
        'fd_value'        => round($fd_value, 0),
        'ltcg_realised_fy'=> round($ltcg, 0),
        'goals'           => $goals,
        'snapshot_date'   => date('Y-m-d'),
    ];
}

/**
 * Build the AI prompt for portfolio review
 */
function _build_review_prompt(array $snap, array $prefs): string {
    $lang     = $prefs['preferred_language'] ?? 'hi-en';
    $risk     = $prefs['risk_appetite']     ?? 'moderate';
    $goalText = $prefs['financial_goal']    ?? 'long-term wealth creation';
    $langNote = $lang === 'hi-en'
        ? 'Respond in Hinglish (mix of Hindi and English) — casual, friendly, like a CA dost.'
        : 'Respond in clear English. Professional yet approachable tone.';

    $snapJson = json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    return <<<PROMPT
You are WealthDash's AI financial advisor. Analyze this Indian investor's portfolio and give a thorough monthly review.

{$langNote}

INVESTOR PROFILE:
- Risk Appetite: {$risk}
- Financial Goal: {$goalText}

PORTFOLIO SNAPSHOT (as of {$snap['snapshot_date']}):
{$snapJson}

IMPORTANT CONTEXT (India-specific):
- LTCG on equity MF > 1 year: 10% on gains above ₹1L
- STCG on equity MF < 1 year: 15%
- Debt fund gains: slab rate (post Apr 2023)
- ELSS: 3-year lock-in, 80C benefit up to ₹1.5L
- NPS: 60% lump sum on retirement (40% annuity mandatory)

YOUR OUTPUT must be valid JSON with exactly these keys:
{
  "summary": "2-3 line overall portfolio health summary",
  "score": <integer 0-100 portfolio health score>,
  "strengths": ["strength 1", "strength 2", "strength 3"],
  "concerns": ["concern 1", "concern 2"],
  "action_items": [
    {"priority": "high|medium|low", "action": "specific actionable step", "impact": "expected outcome"},
    ...
  ],
  "tax_tip": "One specific tax-saving tip for this FY",
  "sip_advice": "Advice on current SIP amount and allocation",
  "next_review_focus": "What to focus on in next month's review"
}

Respond ONLY with the JSON object. No preamble, no markdown fences.
PROMPT;
}

/**
 * Call Anthropic API (blocking — use for on-demand reviews)
 */
function _call_anthropic(string $prompt, int &$tokensUsed): string {
    $apiKey = _ai_api_key();
    if (!$apiKey) return json_encode(['error' => 'AI API key not configured.']);

    $payload = [
        'model'      => AI_REVIEW_MODEL,
        'max_tokens' => AI_REVIEW_MAX_TOKENS,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
        'system'     => 'You are a certified financial planner specializing in Indian personal finance. Always respond with valid JSON only.',
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
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return json_encode(['error' => 'AI service error (HTTP ' . $httpCode . ')']);
    }

    $data = json_decode($response, true);
    $tokensUsed = (int)($data['usage']['output_tokens'] ?? 0);
    return $data['content'][0]['text'] ?? json_encode(['error' => 'Empty AI response.']);
}

/**
 * Rate limit check
 */
function _check_rate_limit(int $userId, string $feature): bool {
    $count = (int)(DB::fetchVal(
        "SELECT request_count FROM ai_usage_log WHERE user_id=? AND feature=? AND usage_date=CURDATE()",
        [$userId, $feature]
    ) ?? 0);
    return $count < AI_DAILY_REVIEW_LIMIT;
}

function _increment_usage(int $userId, string $feature, int $tokens): void {
    DB::run(
        "INSERT INTO ai_usage_log (user_id, feature, usage_date, request_count, tokens_total)
         VALUES (?,?,CURDATE(),1,?)
         ON DUPLICATE KEY UPDATE request_count=request_count+1, tokens_total=tokens_total+?",
        [$userId, $feature, $tokens, $tokens]
    );
}

// ── ACTIONS ────────────────────────────────────────────────────────────────

switch ($action) {

    // ── ai_review_generate ───────────────────────────────────────────────
    case 'ai_review_generate': {
        require_csrf();

        if (!_check_rate_limit($userId, 'review')) {
            json_response(false, 'Daily limit reached. Max ' . AI_DAILY_REVIEW_LIMIT . ' reviews per day.', [
                'limit' => AI_DAILY_REVIEW_LIMIT,
                'reset' => 'Tomorrow 12:00 AM',
            ], 429);
        }

        $portfolioId = _ai_portfolio_id($userId);
        if (!$portfolioId) json_response(false, 'No portfolio found.', [], 404);

        $reviewType = clean($_POST['review_type'] ?? 'on_demand');
        $reviewMonth = date('Y-m');

        // Get user prefs
        $prefs = DB::fetchOne(
            "SELECT * FROM ai_review_prefs WHERE user_id=?",
            [$userId]
        ) ?: [];

        // Build snapshot
        $snapshot = _build_portfolio_snapshot($userId, $portfolioId);
        $prompt   = _build_review_prompt($snapshot, $prefs);

        // Upsert review row as 'generating'
        $existing = DB::fetchVal(
            "SELECT id FROM ai_portfolio_reviews WHERE portfolio_id=? AND review_month=? AND review_type=?",
            [$portfolioId, $reviewMonth, $reviewType]
        );

        if ($existing) {
            DB::run(
                "UPDATE ai_portfolio_reviews SET status='generating', prompt_snapshot=?, error_message=NULL WHERE id=?",
                [json_encode($snapshot), $existing]
            );
            $reviewId = $existing;
        } else {
            $reviewId = DB::insert(
                "INSERT INTO ai_portfolio_reviews
                   (user_id, portfolio_id, review_month, review_type, status, prompt_snapshot, model_used)
                 VALUES (?,?,?,?,'generating',?,?)",
                [$userId, $portfolioId, $reviewMonth, $reviewType, json_encode($snapshot), AI_REVIEW_MODEL]
            );
        }

        // Call AI (synchronous)
        $tokensUsed = 0;
        $aiRaw      = _call_anthropic($prompt, $tokensUsed);

        // Parse AI response
        $parsed = json_decode($aiRaw, true);
        $hasError = isset($parsed['error']) || !$parsed;

        if ($hasError) {
            DB::run(
                "UPDATE ai_portfolio_reviews SET status='error', error_message=?, generated_at=NOW() WHERE id=?",
                [$aiRaw, $reviewId]
            );
            json_response(false, 'AI review generation failed.', ['error' => $aiRaw], 500);
        }

        $score = max(0, min(100, (int)($parsed['score'] ?? 50)));

        DB::run(
            "UPDATE ai_portfolio_reviews
             SET status='done', ai_response=?, parsed_sections=?, portfolio_score=?,
                 tokens_used=?, model_used=?, generated_at=NOW()
             WHERE id=?",
            [$aiRaw, json_encode($parsed), $score, $tokensUsed, AI_REVIEW_MODEL, $reviewId]
        );

        _increment_usage($userId, 'review', $tokensUsed);

        json_response(true, 'Review generated! 🎉', [
            'review_id'  => (int)$reviewId,
            'score'      => $score,
            'review'     => $parsed,
            'month'      => $reviewMonth,
            'tokens'     => $tokensUsed,
        ]);
    }

    // ── ai_review_get ─────────────────────────────────────────────────────
    case 'ai_review_get': {
        $portfolioId = _ai_portfolio_id($userId);
        $reviewMonth = clean($_GET['month'] ?? date('Y-m'));
        $reviewType  = clean($_GET['type']  ?? '');

        $whereType = $reviewType ? "AND review_type=?" : "";
        $params    = $reviewType
            ? [$portfolioId, $reviewMonth, $reviewType]
            : [$portfolioId, $reviewMonth];

        $review = DB::fetchOne(
            "SELECT * FROM ai_portfolio_reviews
             WHERE portfolio_id=? AND review_month=? {$whereType}
             ORDER BY generated_at DESC LIMIT 1",
            $params
        );

        if (!$review) {
            json_response(true, '', ['review' => null, 'has_review' => false, 'month' => $reviewMonth]);
        }

        $review['parsed_sections'] = json_decode($review['parsed_sections'] ?? '{}', true);
        $review['prompt_snapshot'] = null; // don't expose

        json_response(true, '', [
            'review'     => $review,
            'has_review' => true,
            'month'      => $reviewMonth,
        ]);
    }

    // ── ai_review_history ─────────────────────────────────────────────────
    case 'ai_review_history': {
        $portfolioId = _ai_portfolio_id($userId);
        $limit       = min(24, (int)($_GET['limit'] ?? 12));

        $reviews = DB::fetchAll(
            "SELECT id, review_month, review_type, status, portfolio_score,
                    tokens_used, model_used, generated_at,
                    JSON_EXTRACT(parsed_sections,'$.summary') AS summary_preview
             FROM ai_portfolio_reviews
             WHERE portfolio_id=? AND status='done'
             ORDER BY generated_at DESC LIMIT ?",
            [$portfolioId, $limit]
        );

        foreach ($reviews as &$r) {
            $r['summary_preview'] = trim($r['summary_preview'] ?? '', '"');
        }
        unset($r);

        json_response(true, '', ['reviews' => $reviews, 'total' => count($reviews)]);
    }

    // ── ai_review_status ──────────────────────────────────────────────────
    case 'ai_review_status': {
        $reviewId = (int)($_GET['review_id'] ?? 0);
        if (!$reviewId) json_response(false, 'review_id required.');

        $row = DB::fetchOne(
            "SELECT id, status, portfolio_score, error_message, generated_at
             FROM ai_portfolio_reviews
             WHERE id=? AND user_id=?",
            [$reviewId, $userId]
        );

        if (!$row) json_response(false, 'Review not found.', [], 404);

        // Usage today
        $usageToday = (int)(DB::fetchVal(
            "SELECT request_count FROM ai_usage_log WHERE user_id=? AND feature='review' AND usage_date=CURDATE()",
            [$userId]
        ) ?? 0);

        json_response(true, '', [
            'status'         => $row['status'],
            'score'          => $row['portfolio_score'],
            'error'          => $row['error_message'],
            'generated_at'   => $row['generated_at'],
            'usage_today'    => $usageToday,
            'remaining_today'=> max(0, AI_DAILY_REVIEW_LIMIT - $usageToday),
        ]);
    }

    // ── ai_review_prefs_get ───────────────────────────────────────────────
    case 'ai_review_prefs_get': {
        $prefs = DB::fetchOne("SELECT * FROM ai_review_prefs WHERE user_id=?", [$userId]);

        $usageToday = (int)(DB::fetchVal(
            "SELECT request_count FROM ai_usage_log WHERE user_id=? AND feature='review' AND usage_date=CURDATE()",
            [$userId]
        ) ?? 0);

        json_response(true, '', [
            'prefs' => $prefs ?: [
                'auto_generate_monthly' => 0,
                'notify_on_ready'       => 1,
                'preferred_language'    => 'hi-en',
                'risk_appetite'         => 'moderate',
                'financial_goal'        => '',
            ],
            'usage_today'     => $usageToday,
            'daily_limit'     => AI_DAILY_REVIEW_LIMIT,
            'remaining_today' => max(0, AI_DAILY_REVIEW_LIMIT - $usageToday),
        ]);
    }

    // ── ai_review_prefs_save ──────────────────────────────────────────────
    case 'ai_review_prefs_save': {
        require_csrf();

        $autoMonthly = (int)(bool)($_POST['auto_generate_monthly'] ?? 0);
        $notifyReady = (int)(bool)($_POST['notify_on_ready']       ?? 1);
        $lang        = in_array($_POST['preferred_language'] ?? '', ['hi-en','en']) ? $_POST['preferred_language'] : 'hi-en';
        $risk        = in_array($_POST['risk_appetite'] ?? '', ['conservative','moderate','aggressive'])
            ? $_POST['risk_appetite'] : 'moderate';
        $goal        = substr(trim(clean($_POST['financial_goal'] ?? '')), 0, 255);

        DB::run(
            "INSERT INTO ai_review_prefs
               (user_id, auto_generate_monthly, notify_on_ready, preferred_language, risk_appetite, financial_goal)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               auto_generate_monthly=VALUES(auto_generate_monthly),
               notify_on_ready=VALUES(notify_on_ready),
               preferred_language=VALUES(preferred_language),
               risk_appetite=VALUES(risk_appetite),
               financial_goal=VALUES(financial_goal)",
            [$userId, $autoMonthly, $notifyReady, $lang, $risk, $goal ?: null]
        );

        json_response(true, 'Preferences saved.');
    }

    // ── ai_review_delete ──────────────────────────────────────────────────
    case 'ai_review_delete': {
        require_csrf();
        $reviewId = (int)($_POST['review_id'] ?? 0);
        if (!$reviewId) json_response(false, 'review_id required.');

        $deleted = DB::run(
            "DELETE FROM ai_portfolio_reviews WHERE id=? AND user_id=?",
            [$reviewId, $userId]
        );

        json_response((bool)$deleted, $deleted ? 'Review deleted.' : 'Review not found.');
    }

    default:
        json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);
}
