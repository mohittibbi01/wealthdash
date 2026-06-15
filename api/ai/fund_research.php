<?php
/**
 * WealthDash — t382: AI Fund Research — Natural Language Search
 * File: api/ai/fund_research.php
 * Actions: ai_fund_research
 *
 * No mf_funds table — searches mf_holdings + mf_transactions fund names
 * for "have I invested in X" queries, plus general AI fund knowledge.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'ai_fund_research') { json_response(false,'Unknown action.',[],400); }

RateLimit::check('ai_chat', $userId);

$query = trim($_POST['query'] ?? '');
if (!$query) json_response(false, 'Query required.');
if (mb_strlen($query) > 500) json_response(false, 'Query too long (max 500 chars).');

// Search user's own holdings/transactions for fund name matches
$searchTerm = '%' . $query . '%';
$ownHoldings = DB::fetchAll(
    "SELECT DISTINCT fund_name, units,
            units * COALESCE(n.nav, h.avg_cost_per_unit) AS current_value
     FROM mf_holdings h
     LEFT JOIN mf_nav_latest n ON n.mf_id = h.mf_id
     WHERE h.user_id=? AND h.portfolio_id=? AND (h.fund_name LIKE ?)
     LIMIT 5",
    [$userId, $portfolioId, $searchTerm]
);

// Category guess for context
function _guessCatFR(string $name): string {
    $n = strtolower($name);
    if (str_contains($n,'small cap')) return 'Small Cap Equity';
    if (str_contains($n,'mid cap'))   return 'Mid Cap Equity';
    if (str_contains($n,'large cap')||str_contains($n,'bluechip')) return 'Large Cap Equity';
    if (str_contains($n,'flexi')||str_contains($n,'multi cap'))    return 'Flexi/Multi Cap Equity';
    if (str_contains($n,'debt')||str_contains($n,'bond')||str_contains($n,'gilt')) return 'Debt Fund';
    if (str_contains($n,'liquid')||str_contains($n,'overnight'))   return 'Liquid Fund';
    if (str_contains($n,'hybrid')||str_contains($n,'balanced'))    return 'Hybrid Fund';
    if (str_contains($n,'gold'))    return 'Gold Fund';
    if (str_contains($n,'elss')||str_contains($n,'tax'))           return 'ELSS / Tax Saver';
    if (str_contains($n,'international')||str_contains($n,'global')) return 'International Fund';
    if (str_contains($n,'index')||str_contains($n,'nifty')||str_contains($n,'sensex')) return 'Index Fund';
    return 'Equity Fund';
}

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');

$ownContext = '';
if ($ownHoldings) {
    $ownContext = "USER'S OWN HOLDINGS MATCHING THIS QUERY:\n" . implode("\n", array_map(
        fn($h) => "- {$h['fund_name']}: ₹" . number_format((float)$h['current_value'], 0) . " ({$h['units']} units) — Category: " . _guessCatFR($h['fund_name']),
        $ownHoldings
    ));
} else {
    $ownContext = "User does not currently hold any fund matching this query in their portfolio.";
}

if (!$apiKey) {
    // Rule-based response
    $reply = $ownHoldings
        ? "Aapke portfolio mein '{$query}' se related ye fund(s) milte hain:\n\n" . implode("\n", array_map(fn($h) => "• {$h['fund_name']}: ₹" . number_format((float)$h['current_value'],0) . " (" . _guessCatFR($h['fund_name']) . ")", $ownHoldings)) . "\n\nDetailed fund research ke liye AI key configure karo (ANTHROPIC_API_KEY)."
        : "Aapke portfolio mein '{$query}' se matching koi fund nahi mila. Naye fund research ke liye, AMC website (e.g. groww.in, AMFI India) check karo. Detailed AI-powered research ke liye ANTHROPIC_API_KEY configure karo.";

    json_response(true,'ok',[
        'query'         => $query,
        'reply'         => $reply,
        'own_holdings'  => $ownHoldings,
        'mode'          => 'rule_based',
    ]);
}

$prompt = "You are WealthDash's AI Fund Research Assistant for Indian mutual fund investors.

USER QUERY: \"{$query}\"

{$ownContext}

Provide helpful research in Hinglish (max 250 words):
- If the query is about a specific fund/category, explain what it is, typical risk level, and who it suits
- If user already holds it, acknowledge their holding and give a brief assessment
- If it's a comparison query (e.g. 'X vs Y'), give a balanced comparison
- Always end with: 'Yeh general information hai — investment decision se pehle fund's latest factsheet aur apne financial advisor se consult karein.'

Be factual, avoid making up specific NAV/return numbers you don't know.";

$resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
    stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>500,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>25]]));

if (!$resp) json_response(false, 'AI service unavailable. Try again.');
$reply = json_decode($resp,true)['content'][0]['text'] ?? 'Unable to research this query.';

json_response(true,'ok',[
    'query'        => $query,
    'reply'        => $reply,
    'own_holdings' => $ownHoldings,
    'mode'         => 'ai',
]);
