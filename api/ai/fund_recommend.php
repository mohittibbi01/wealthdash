<?php
/**
 * WealthDash — t243: AI Fund Recommendation (FIXED)
 * File: api/ai/fund_recommend.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'ai_fund_recommend') { json_response(false,'Unknown action.',[],400); }

RateLimit::check('ai_fund_recommend', 5, 60);

// Fetch holdings — no mf_funds JOIN (table doesn't exist)
$holdings = DB::fetchAll(
    "SELECT h.mf_id, h.fund_name, h.units,
            h.avg_cost_per_unit,
            h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS current_value
     FROM mf_holdings h
     LEFT JOIN mf_nav_latest n ON n.mf_id = h.mf_id
     WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
    [$userId, $portfolioId]
);

$profile    = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?", [$userId]);
$totalValue = array_sum(array_column($holdings, 'current_value'));

// Rule-based category guess from fund name
function _guessCat(string $name): string {
    $n = strtolower($name);
    if (str_contains($n,'liquid')||str_contains($n,'overnight')||str_contains($n,'money market')) return 'Liquid/Debt';
    if (str_contains($n,'debt')||str_contains($n,'bond')||str_contains($n,'gilt')||str_contains($n,'income')) return 'Debt';
    if (str_contains($n,'small cap')||str_contains($n,'smallcap')) return 'Small Cap';
    if (str_contains($n,'mid cap')||str_contains($n,'midcap')) return 'Mid Cap';
    if (str_contains($n,'large cap')||str_contains($n,'largecap')||str_contains($n,'bluechip')) return 'Large Cap';
    if (str_contains($n,'flexi')||str_contains($n,'multi cap')||str_contains($n,'multicap')) return 'Flexi Cap';
    if (str_contains($n,'hybrid')||str_contains($n,'balanced')||str_contains($n,'aggressive hybrid')) return 'Hybrid';
    if (str_contains($n,'gold')||str_contains($n,'silver')) return 'Gold/Commodity';
    if (str_contains($n,'international')||str_contains($n,'global')||str_contains($n,'us ')||str_contains($n,'nasdaq')) return 'International';
    if (str_contains($n,'elss')||str_contains($n,'tax')) return 'ELSS/Tax Saver';
    return 'Large Cap'; // default
}

$allocationByCategory = [];
foreach ($holdings as $h) {
    $cat = _guessCat($h['fund_name']);
    $allocationByCategory[$cat] = ($allocationByCategory[$cat] ?? 0) + (float)$h['current_value'];
}

$riskProfile = $profile['risk_profile'] ?? 'moderate';
$idealAllocations = [
    'conservative'         => ['Large Cap'=>30,'Debt'=>50,'Gold/Commodity'=>10,'Hybrid'=>10],
    'moderate'             => ['Large Cap'=>35,'Mid Cap'=>15,'Debt'=>30,'Gold/Commodity'=>10,'Hybrid'=>10],
    'moderately_aggressive'=> ['Large Cap'=>35,'Mid Cap'=>25,'Small Cap'=>10,'Debt'=>20,'Gold/Commodity'=>10],
    'aggressive'           => ['Large Cap'=>30,'Mid Cap'=>30,'Small Cap'=>20,'International'=>10,'Debt'=>10],
];
$ideal = $idealAllocations[$riskProfile] ?? $idealAllocations['moderate'];

$holdingsSummary = array_map(fn($h) =>
    $h['fund_name'] . ': ₹' . number_format((float)$h['current_value'],0),
    $holdings
);
$currentAlloc = array_map(fn($cat,$val) =>
    "$cat: " . ($totalValue>0 ? round($val/$totalValue*100,1) : 0) . '%',
    array_keys($allocationByCategory), $allocationByCategory
);
$idealAlloc = array_map(fn($cat,$pct) => "$cat: $pct%", array_keys($ideal), $ideal);

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');

// Rule-based gap analysis (always compute)
$gaps = [];
foreach ($ideal as $cat => $idealPct) {
    $currentPct = $totalValue > 0 ? (($allocationByCategory[$cat] ?? 0) / $totalValue * 100) : 0;
    $gap = $idealPct - $currentPct;
    if ($gap > 5) $gaps[] = ['category'=>$cat,'gap_pct'=>round($gap,1),'target_pct'=>$idealPct,'current_pct'=>round($currentPct,1)];
}
usort($gaps, fn($a,$b) => $b['gap_pct'] <=> $a['gap_pct']);

if (!$apiKey) {
    json_response(true,'ok',[
        'mode'        => 'rule_based',
        'risk_profile'=> $riskProfile,
        'gaps'        => $gaps,
        'narrative'   => count($gaps)
            ? "Portfolio under-allocated in: ".implode(', ',array_column($gaps,'category')).". Consider adding funds in these categories to match your $riskProfile profile."
            : "Portfolio allocation looks well-balanced for your $riskProfile profile!",
        'allocation'  => ['current'=>$allocationByCategory,'ideal'=>$ideal],
    ]);
}

$prompt = "You are WealthDash's AI investment advisor for an Indian investor.\n\nRisk Profile: ".strtoupper($riskProfile)."\nTotal Portfolio: ₹".number_format($totalValue,0)."\n\nHoldings:\n".implode("\n",$holdingsSummary)."\n\nCurrent Allocation: ".implode(", ",$currentAlloc)."\nIdeal Allocation: ".implode(", ",$idealAlloc)."\n\nProvide 3 specific fund categories to add, any over-allocated areas, and one action for this month. India-focused. Max 200 words.";

$resp = @file_get_contents('https://api.anthropic.com/v1/messages',false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: $apiKey\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>350,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>20]]));
if (!$resp) json_response(false,'AI service unavailable. Try again.');
$narrative = json_decode($resp,true)['content'][0]['text'] ?? 'Unable to generate.';

json_response(true,'ok',[
    'mode'        => 'ai',
    'risk_profile'=> $riskProfile,
    'narrative'   => $narrative,
    'gaps'        => $gaps,
    'allocation'  => ['current'=>$allocationByCategory,'ideal'=>$ideal],
]);
