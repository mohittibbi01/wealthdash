<?php
/**
 * WealthDash — t331: AI SIP Optimizer — Smart Allocation
 * File: api/ai/sip_optimizer.php
 * Actions: ai_sip_optimize
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'ai_sip_optimize') { json_response(false,'Unknown action.',[],400); }

RateLimit::check('ai_portfolio_review', $userId);

// Current SIPs
$sips = DB::fetchAll(
    "SELECT s.id, s.fund_name, s.sip_amount, s.sip_frequency, s.sip_date, s.start_date,
            h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS current_value,
            (h.units * COALESCE(n.nav, h.avg_cost_per_unit)) - (h.units * h.avg_cost_per_unit) AS gain
     FROM mf_sips s
     LEFT JOIN mf_holdings h ON h.mf_id = s.mf_id AND h.user_id = s.user_id AND h.portfolio_id = s.portfolio_id
     LEFT JOIN mf_nav_latest n ON n.mf_id = s.mf_id
     WHERE s.user_id=? AND s.portfolio_id=? AND s.status='active'",
    [$userId, $portfolioId]
);

$profile = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?", [$userId]);
$totalSIP = array_sum(array_column($sips, 'sip_amount'));

// Category distribution (rule-based from fund names)
function _guessCatSIP(string $name): string {
    $n = strtolower($name);
    if (str_contains($n,'small cap')||str_contains($n,'smallcap')) return 'Small Cap';
    if (str_contains($n,'mid cap')||str_contains($n,'midcap'))     return 'Mid Cap';
    if (str_contains($n,'large cap')||str_contains($n,'largecap')||str_contains($n,'bluechip')) return 'Large Cap';
    if (str_contains($n,'flexi')||str_contains($n,'multi cap'))    return 'Flexi Cap';
    if (str_contains($n,'debt')||str_contains($n,'bond')||str_contains($n,'gilt')) return 'Debt';
    if (str_contains($n,'hybrid')||str_contains($n,'balanced'))    return 'Hybrid';
    if (str_contains($n,'gold'))    return 'Gold';
    if (str_contains($n,'elss')||str_contains($n,'tax')) return 'ELSS';
    if (str_contains($n,'international')||str_contains($n,'global')) return 'International';
    return 'Large Cap';
}

$sipByCategory = [];
foreach ($sips as $s) {
    $cat = _guessCatSIP($s['fund_name']);
    $sipByCategory[$cat] = ($sipByCategory[$cat] ?? 0) + (float)$s['sip_amount'];
}

$riskProfile = $profile['risk_profile'] ?? 'moderate';
$monthlyIncome = (float)($profile['annual_income'] ?? 0) / 12;

// Ideal SIP allocation % by risk profile
$idealSIPAlloc = [
    'conservative'          => ['Large Cap'=>40,'Debt'=>40,'Hybrid'=>15,'Gold'=>5],
    'moderate'              => ['Large Cap'=>40,'Mid Cap'=>20,'Debt'=>25,'Hybrid'=>10,'Gold'=>5],
    'moderately_aggressive' => ['Large Cap'=>35,'Mid Cap'=>25,'Small Cap'=>15,'Debt'=>15,'Gold'=>5,'International'=>5],
    'aggressive'            => ['Large Cap'=>30,'Mid Cap'=>25,'Small Cap'=>20,'International'=>10,'Debt'=>10,'Gold'=>5],
];
$ideal = $idealSIPAlloc[$riskProfile] ?? $idealSIPAlloc['moderate'];

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');

// Build analysis (rule-based always computed)
$currentAlloc = [];
foreach ($sipByCategory as $cat => $amt) {
    $currentAlloc[$cat] = $totalSIP > 0 ? round($amt / $totalSIP * 100, 1) : 0;
}

$recommendations = [];
foreach ($ideal as $cat => $idealPct) {
    $currPct = $currentAlloc[$cat] ?? 0;
    $gap     = $idealPct - $currPct;
    $amtGap  = round($totalSIP * $gap / 100);
    if ($gap > 8) {
        $recommendations[] = [
            'category'    => $cat,
            'current_pct' => $currPct,
            'ideal_pct'   => $idealPct,
            'gap_pct'     => round($gap, 1),
            'action'      => 'increase',
            'amount'      => abs($amtGap),
            'suggestion'  => "Add ₹" . number_format(abs($amtGap), 0) . "/month to $cat fund",
        ];
    } elseif ($gap < -10) {
        $recommendations[] = [
            'category'    => $cat,
            'current_pct' => $currPct,
            'ideal_pct'   => $idealPct,
            'gap_pct'     => round($gap, 1),
            'action'      => 'reduce',
            'amount'      => abs($amtGap),
            'suggestion'  => "Consider reducing $cat allocation by ₹" . number_format(abs($amtGap), 0) . "/month",
        ];
    }
}
usort($recommendations, fn($a,$b) => abs($b['gap_pct']) <=> abs($a['gap_pct']));

// Step-up suggestion (10% annual)
$stepUpSuggestion = round($totalSIP * 0.10);

// Savings rate check
$savingsRate = $monthlyIncome > 0 ? round($totalSIP / $monthlyIncome * 100, 1) : 0;
$idealSavingsRate = 20; // 20% of income recommended

$narrative = '';
if ($apiKey) {
    $sipLines = implode("\n", array_map(fn($s) => "- {$s['fund_name']}: ₹" . number_format((float)$s['sip_amount'],0) . "/month", $sips));
    $prompt = "You are a WealthDash SIP optimizer for an Indian investor. Analyze their SIP portfolio and provide smart optimization advice.

CURRENT SIPs:
{$sipLines}
Total Monthly SIP: ₹" . number_format($totalSIP, 0) . "
Risk Profile: {$riskProfile}
Monthly Income: ₹" . number_format($monthlyIncome, 0) . "
Savings Rate: {$savingsRate}%

CURRENT ALLOCATION: " . json_encode($currentAlloc) . "
IDEAL ALLOCATION: " . json_encode($ideal) . "

Provide SIP optimization advice in Hinglish (max 150 words):
1. Is savings rate theek hai?
2. Kaunse fund mein step-up/start karna chahiye?
3. Ek quick action this month

Keep it specific and actionable.";

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
        stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>300,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>20]]));
    if ($resp) $narrative = json_decode($resp, true)['content'][0]['text'] ?? '';
}

json_response(true,'ok',[
    'sips'              => $sips,
    'total_sip'         => round($totalSIP),
    'sip_count'         => count($sips),
    'sip_by_category'   => $sipByCategory,
    'current_alloc_pct' => $currentAlloc,
    'ideal_alloc_pct'   => $ideal,
    'risk_profile'      => $riskProfile,
    'recommendations'   => $recommendations,
    'step_up_suggestion'=> $stepUpSuggestion,
    'savings_rate_pct'  => $savingsRate,
    'savings_rate_ok'   => $savingsRate >= $idealSavingsRate,
    'ai_narrative'      => $narrative,
    'mode'              => $apiKey ? 'ai' : 'rule_based',
]);
