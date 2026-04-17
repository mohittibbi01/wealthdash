<?php
/**
 * WealthDash — t380: AI Portfolio Review + t333: AI Report Card
 * Monthly intelligent analysis with letter grade
 * Actions: ai_portfolio_review | ai_report_card | ai_review_history
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'ai_portfolio_review';

RateLimit::check('ai_portfolio_review', $userId);

// Ensure table
try {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS ai_portfolio_reviews (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      INT UNSIGNED NOT NULL,
            review_month VARCHAR(7)   NOT NULL,
            review_type  VARCHAR(30)  NOT NULL DEFAULT 'monthly',
            grade        CHAR(2),
            score        TINYINT UNSIGNED,
            summary      TEXT,
            strengths    TEXT,
            weaknesses   TEXT,
            actions      TEXT,
            raw_response TEXT,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_month (user_id, review_month, review_type),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── Build detailed portfolio data for AI ─────────────────────
function buildFullPortfolioData(int $userId): array {
    $data = [];

    try {
        // MF Summary
        $data['mutual_funds'] = DB::fetchAll(
            "SELECT f.fund_name, f.category, f.sub_category,
                    mh.units, mh.current_nav, mh.current_value, mh.invested_amount,
                    mh.xirr, mh.abs_return_pct,
                    DATEDIFF(NOW(), mh.first_investment_date) AS holding_days
             FROM mf_holdings mh
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ? AND mh.is_active = 1",
            [$userId]
        );

        // FDs
        $data['fixed_deposits'] = DB::fetchAll(
            "SELECT bank_name, principal_amount, interest_rate, tenure_days, maturity_date, fd_type
             FROM fd_investments WHERE user_id = ? AND status = 'active'",
            [$userId]
        );

        // NPS
        $data['nps'] = DB::fetchAll(
            "SELECT pfm_name, tier, current_value, invested_amount, equity_pct, debt_pct
             FROM nps_accounts WHERE user_id = ? AND is_active = 1",
            [$userId]
        );

        // Active SIPs
        $data['active_sips'] = DB::fetchAll(
            "SELECT f.fund_name, s.amount, s.frequency FROM sip_swp s
             JOIN mf_holdings mh ON mh.id = s.holding_id
             JOIN funds f ON f.id = mh.fund_id
             WHERE s.user_id = ? AND s.type = 'SIP' AND s.status = 'active'",
            [$userId]
        );

        // Goals
        $data['goals'] = DB::fetchAll(
            "SELECT goal_name, target_amount, current_amount, target_date,
                    ROUND(current_amount/target_amount*100,1) AS progress_pct
             FROM goals WHERE user_id = ? AND status = 'active'",
            [$userId]
        );

        // User profile
        $user = DB::fetchRow("SELECT age, is_senior_citizen FROM users WHERE id = ?", [$userId]);
        $data['user_profile'] = $user ?? [];

    } catch (Exception $e) {
        $data['error'] = $e->getMessage();
    }

    return $data;
}

function callClaudeForReview(array $portfolioData, string $reviewType, int $userId): array {
    $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?? '';
    if (!$apiKey) return ['error' => 'API key not configured'];

    $portfolio_json = json_encode($portfolioData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $month          = date('F Y');
    $fy             = (date('n') >= 4) ? date('Y') . '-' . (date('Y') + 1) : (date('Y') - 1) . '-' . date('Y');

    $prompt = <<<PROMPT
Tum WealthDash ka AI Portfolio Analyst ho. Neeche diye gaye Indian investor ke portfolio ka {$month} monthly review karo.

PORTFOLIO DATA (JSON):
{$portfolio_json}

Current Financial Year: FY {$fy}
Review Type: {$reviewType}

Tumhe respond karna hai **sirf valid JSON mein** (no markdown, no extra text), is exact format mein:

{
  "grade": "B+",
  "score": 74,
  "summary": "2-3 sentence portfolio overview in Hinglish",
  "strengths": ["strength 1", "strength 2", "strength 3"],
  "weaknesses": ["weakness 1", "weakness 2"],
  "actions": [
    {"priority": "high", "action": "Specific action item", "reason": "Why important"},
    {"priority": "medium", "action": "Another action", "reason": "Reason"}
  ],
  "allocation_comment": "Asset allocation ke baare mein comment",
  "tax_comment": "Tax efficiency ke baare mein comment",
  "goal_comment": "Goal progress ke baare mein comment",
  "disclaimer": "Main SEBI registered advisor nahi hoon..."
}

GRADING SCALE:
A+ (95-100): Exceptional portfolio
A  (85-94): Very strong
B+ (75-84): Good with minor gaps
B  (65-74): Average, needs work
C+ (55-64): Below average
C  (45-54): Poor, major changes needed
D  (<45): Urgent overhaul needed

Score honestly. Return ONLY the JSON object.
PROMPT;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1500,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return ['error' => 'API error: ' . $httpCode];

    $apiData = json_decode($resp, true);
    $text    = $apiData['content'][0]['text'] ?? '';

    // Strip markdown fences if present
    $text = preg_replace('/^```json\s*/m', '', $text);
    $text = preg_replace('/^```\s*/m', '', $text);
    $text = trim($text);

    $parsed = json_decode($text, true);
    if (!$parsed) return ['error' => 'AI response parse nahi hua', 'raw' => $text];

    return $parsed;
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── GENERATE PORTFOLIO REVIEW ──────────────────────────────
    case 'ai_portfolio_review':
    case 'ai_report_card':
        csrf_verify();
        $reviewType  = ($action === 'ai_report_card') ? 'report_card' : 'monthly';
        $reviewMonth = date('Y-m');
        $force       = (bool) ($_POST['force'] ?? false);

        // Check if already generated this month
        if (!$force) {
            $existing = DB::fetchRow(
                "SELECT id, grade, score, summary, strengths, weaknesses, actions, created_at
                 FROM ai_portfolio_reviews
                 WHERE user_id = ? AND review_month = ? AND review_type = ?",
                [$userId, $reviewMonth, $reviewType]
            );
            if ($existing) {
                $existing['strengths'] = json_decode($existing['strengths'] ?? '[]', true);
                $existing['weaknesses'] = json_decode($existing['weaknesses'] ?? '[]', true);
                $existing['actions']   = json_decode($existing['actions']   ?? '[]', true);
                json_response(true, '', array_merge($existing, ['cached' => true]));
            }
        }

        $portfolioData = buildFullPortfolioData($userId);
        $review        = callClaudeForReview($portfolioData, $reviewType, $userId);

        if (isset($review['error'])) {
            json_response(false, 'AI review generate nahi hua: ' . $review['error']);
        }

        // Save to DB
        DB::run(
            "INSERT INTO ai_portfolio_reviews
             (user_id, review_month, review_type, grade, score, summary, strengths, weaknesses, actions, raw_response)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               grade=VALUES(grade), score=VALUES(score), summary=VALUES(summary),
               strengths=VALUES(strengths), weaknesses=VALUES(weaknesses),
               actions=VALUES(actions), raw_response=VALUES(raw_response), created_at=NOW()",
            [
                $userId, $reviewMonth, $reviewType,
                $review['grade'] ?? 'N/A',
                $review['score'] ?? 0,
                $review['summary'] ?? '',
                json_encode($review['strengths'] ?? []),
                json_encode($review['weaknesses'] ?? []),
                json_encode($review['actions'] ?? []),
                json_encode($review),
            ]
        );

        json_response(true, 'Portfolio review ready hai!', array_merge($review, ['cached' => false]));

    // ── REVIEW HISTORY ─────────────────────────────────────────
    case 'ai_review_history':
        $reviews = DB::fetchAll(
            "SELECT review_month, review_type, grade, score, summary, created_at
             FROM ai_portfolio_reviews WHERE user_id = ? ORDER BY review_month DESC LIMIT 12",
            [$userId]
        );
        json_response(true, '', ['reviews' => $reviews]);

    default:
        json_response(false, 'Unknown AI review action.', [], 400);
}
