<?php
/**
 * WealthDash — t383 / t245: AI Tax Optimizer
 * Best redemption sequence + March-end tax saving actions
 * Actions: ai_tax_optimize | ai_tax_harvest | ai_tax_regime_compare
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'ai_tax_optimize';

RateLimit::check('ai_tax_optimize', $userId);

// ── Build tax context data ─────────────────────────────────────
function buildTaxContext(int $userId): array {
    $ctx = [];
    $fy  = (date('n') >= 4) ? date('Y') : (date('Y') - 1);
    $fyStart = $fy . '-04-01';
    $fyEnd   = ($fy + 1) . '-03-31';

    try {
        // MF holdings with LTCG/STCG data
        $ctx['mf_holdings'] = DB::fetchAll(
            "SELECT f.fund_name, f.asset_class, f.sub_category,
                    mh.units, mh.current_value, mh.invested_amount,
                    mh.first_investment_date,
                    DATEDIFF(NOW(), mh.first_investment_date) AS holding_days,
                    (mh.current_value - mh.invested_amount) AS unrealised_gain
             FROM mf_holdings mh
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ? AND mh.is_active = 1
             ORDER BY unrealised_gain DESC",
            [$userId]
        );

        // Equity funds held > 1 year = LTCG; else STCG
        foreach ($ctx['mf_holdings'] as &$h) {
            $isEquity      = in_array($h['asset_class'] ?? '', ['equity', 'hybrid']);
            $h['is_equity'] = $isEquity;
            if ($isEquity) {
                $h['tax_type']   = $h['holding_days'] >= 365 ? 'LTCG' : 'STCG';
                $h['tax_rate']   = $h['holding_days'] >= 365 ? '10% above ₹1L' : '15%';
            } else {
                $h['tax_type'] = 'Slab Rate (debt)';
                $h['tax_rate'] = 'As per income slab';
            }
        }
        unset($h);

        // Realised gains this FY
        $ctx['realised_gains_fy'] = DB::fetchRow(
            "SELECT
               SUM(CASE WHEN t.asset_class = 'equity' AND DATEDIFF(t.sell_date, t.buy_date) >= 365
                        THEN t.gain_amount ELSE 0 END) AS equity_ltcg,
               SUM(CASE WHEN t.asset_class = 'equity' AND DATEDIFF(t.sell_date, t.buy_date) < 365
                        THEN t.gain_amount ELSE 0 END) AS equity_stcg,
               SUM(CASE WHEN t.asset_class != 'equity' THEN t.gain_amount ELSE 0 END) AS debt_gains
             FROM (
               SELECT mh.first_investment_date AS buy_date, tr.transaction_date AS sell_date,
                      f.asset_class,
                      (tr.amount - tr.units * mh.avg_cost_nav) AS gain_amount
               FROM mf_transactions tr
               JOIN mf_holdings mh ON mh.id = tr.holding_id
               JOIN funds f ON f.id = mh.fund_id
               WHERE tr.transaction_type = 'redeem'
                 AND tr.transaction_date BETWEEN ? AND ?
                 AND mh.portfolio_id IN (SELECT id FROM portfolios WHERE user_id = ?)
             ) t",
            [$fyStart, $fyEnd, $userId]
        ) ?? [];

        // User info
        $ctx['user'] = DB::fetchRow(
            "SELECT age, is_senior_citizen FROM users WHERE id = ?",
            [$userId]
        ) ?? [];

        // 80C investments this FY
        $ctx['fy']        = "FY {$fy}-" . ($fy + 1);
        $ctx['fy_start']  = $fyStart;
        $ctx['fy_end']    = $fyEnd;
        $ctx['days_left_in_fy'] = (int) ceil((strtotime($fyEnd) - time()) / 86400);

    } catch (Exception $e) {
        $ctx['error'] = $e->getMessage();
    }

    return $ctx;
}

function callClaudeForTax(array $taxData, string $type): array {
    $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?? '';
    if (!$apiKey) return ['error' => 'API key missing'];

    $json = json_encode($taxData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $prompts = [
        'optimize' => "Tum Indian tax expert ho. Is investor ka best tax redemption strategy batao — LTCG ₹1L exemption use karo, tax harvesting suggest karo, aur specific kaunsa fund kab redeem karna chahiye ye batao.",
        'harvest'  => "Tax Loss Harvesting opportunity identify karo — kaunse funds mein loss hai jo is FY mein book karke future tax save kar sakte hain?",
        'regime'   => "Old vs New tax regime comparison karo is investor ke liye — deductions consider karo aur kaunsa better hai clearly batao.",
    ];

    $typePrompt = $prompts[$type] ?? $prompts['optimize'];

    $prompt = <<<PROMPT
{$typePrompt}

TAX & PORTFOLIO DATA:
{$json}

Respond ONLY in valid JSON (no markdown):
{
  "headline": "Short main recommendation in Hinglish",
  "tax_situation": "Current FY tax situation summary",
  "recommendations": [
    {
      "priority": "high",
      "action": "Specific action — exact fund name if possible",
      "amount": "Amount to redeem/invest",
      "tax_saving": "Estimated tax saving",
      "deadline": "When to do this by",
      "reason": "Why important"
    }
  ],
  "ltcg_status": {
    "exemption_used": 0,
    "exemption_remaining": 100000,
    "comment": "..."
  },
  "march_checklist": ["item1", "item2", "item3"],
  "warning": "Any urgent action needed?",
  "disclaimer": "Main SEBI/tax advisor nahi hoon..."
}
PROMPT;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1200,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return ['error' => 'API error ' . $httpCode];

    $data = json_decode($resp, true);
    $text = preg_replace('/^```json\s*/m', '', $data['content'][0]['text'] ?? '');
    $text = trim(preg_replace('/^```\s*/m', '', $text));

    $parsed = json_decode($text, true);
    return $parsed ?: ['error' => 'Parse failed', 'raw' => $text];
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    case 'ai_tax_optimize':
        csrf_verify();
        $taxData = buildTaxContext($userId);
        $result  = callClaudeForTax($taxData, 'optimize');
        if (isset($result['error'])) json_response(false, $result['error']);
        json_response(true, '', $result);

    case 'ai_tax_harvest':
        csrf_verify();
        $taxData = buildTaxContext($userId);
        $result  = callClaudeForTax($taxData, 'harvest');
        if (isset($result['error'])) json_response(false, $result['error']);
        json_response(true, '', $result);

    case 'ai_tax_regime_compare':
        csrf_verify();
        $taxData = buildTaxContext($userId);
        // Add income data from POST
        $taxData['income'] = [
            'salary'          => (float) ($_POST['salary'] ?? 0),
            'other_income'    => (float) ($_POST['other_income'] ?? 0),
            'hra_claimed'     => (float) ($_POST['hra_claimed'] ?? 0),
            'sec80c'          => (float) ($_POST['sec80c'] ?? 0),
            'sec80d'          => (float) ($_POST['sec80d'] ?? 0),
            'home_loan_int'   => (float) ($_POST['home_loan_int'] ?? 0),
            'nps_80ccd'       => (float) ($_POST['nps_80ccd'] ?? 0),
        ];
        $result = callClaudeForTax($taxData, 'regime');
        if (isset($result['error'])) json_response(false, $result['error']);
        json_response(true, '', $result);

    default:
        json_response(false, 'Unknown tax action.', [], 400);
}
