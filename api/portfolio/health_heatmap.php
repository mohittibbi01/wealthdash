<?php
/**
 * WealthDash — t446: Portfolio Health Heatmap
 * File: api/portfolio/health_heatmap.php
 * Actions: portfolio_health_heatmap
 *
 * DIFFERENT from t300 (return-based heatmap of individual holdings).
 * This is a HEALTH SCORE heatmap across dimensions:
 *   - Diversification, Cost efficiency, SIP health, Goal alignment,
 *     Tax efficiency, Risk concentration
 * Each dimension scored 0-100 and colored.
 * No mf_funds table dependency.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'portfolio_health_heatmap') { json_response(false,'Unknown action.',[],400); }

function _guessCatHH(string $name): string {
    $n = strtolower($name);
    if (str_contains($n,'small cap')) return 'Small Cap';
    if (str_contains($n,'mid cap'))   return 'Mid Cap';
    if (str_contains($n,'large cap')||str_contains($n,'bluechip')) return 'Large Cap';
    if (str_contains($n,'flexi')||str_contains($n,'multi cap'))    return 'Flexi Cap';
    if (str_contains($n,'debt')||str_contains($n,'bond')||str_contains($n,'gilt')||str_contains($n,'liquid')) return 'Debt';
    if (str_contains($n,'hybrid')||str_contains($n,'balanced'))    return 'Hybrid';
    if (str_contains($n,'gold'))    return 'Gold';
    if (str_contains($n,'elss')||str_contains($n,'tax'))           return 'ELSS';
    if (str_contains($n,'international')||str_contains($n,'global')) return 'International';
    return 'Equity';
}

$holdings = DB::fetchAll(
    "SELECT h.fund_name, h.units,
            h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS current_value,
            h.units * h.avg_cost_per_unit AS invested_value,
            DATEDIFF(NOW(), COALESCE(h.first_purchase_date, h.created_at)) AS holding_days
     FROM mf_holdings h
     LEFT JOIN mf_nav_latest n ON n.mf_id = h.mf_id
     WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
    [$userId, $portfolioId]
);

$totalValue = array_sum(array_column($holdings, 'current_value'));
$totalInvested = array_sum(array_column($holdings, 'invested_value'));

// ── Dimension 1: Diversification (category spread) ─────────────
$catValues = [];
foreach ($holdings as $h) {
    $cat = _guessCatHH($h['fund_name']);
    $catValues[$cat] = ($catValues[$cat] ?? 0) + (float)$h['current_value'];
}
$catCount = count($catValues);
// Score: more categories = better, but penalize over-concentration
$maxConcentration = $totalValue > 0 ? max($catValues) / $totalValue * 100 : 100;
$diversificationScore = min(100, max(0, round(
    ($catCount >= 4 ? 50 : $catCount * 12) +
    (100 - $maxConcentration) * 0.5
)));

// ── Dimension 2: Number of holdings (over/under-diversified) ────
$holdingCount = count($holdings);
$holdingCountScore = match(true) {
    $holdingCount === 0 => 0,
    $holdingCount <= 2  => 40,
    $holdingCount <= 5  => 80,
    $holdingCount <= 10 => 100,
    $holdingCount <= 15 => 80,
    default             => 50, // too many funds = over-diversified
};

// ── Dimension 3: SIP Health ──────────────────────────────────────
$activeSIPs = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]) ?? 0);
$sipScore = match(true) {
    $activeSIPs === 0 => 20,
    $activeSIPs <= 2  => 60,
    $activeSIPs <= 5  => 100,
    default           => 80,
};

// ── Dimension 4: Goal Alignment ──────────────────────────────────
$activeGoals = (int)(DB::fetchVal("SELECT COUNT(*) FROM goals WHERE user_id=? AND status='active'", [$userId]) ?? 0);
$goalScore = min(100, $activeGoals * 33);

// ── Dimension 5: Holding Period Health (long-term vs churning) ──
$avgHoldingDays = $holdings ? array_sum(array_column($holdings,'holding_days')) / count($holdings) : 0;
$holdingPeriodScore = match(true) {
    $avgHoldingDays >= 730 => 100, // 2+ years
    $avgHoldingDays >= 365 => 80,
    $avgHoldingDays >= 180 => 60,
    $avgHoldingDays >= 90  => 40,
    default                => 20,
};

// ── Dimension 6: Returns Health ──────────────────────────────────
$gainPct = $totalInvested > 0 ? (($totalValue-$totalInvested)/$totalInvested*100) : 0;
$returnsScore = match(true) {
    $gainPct >= 15 => 100,
    $gainPct >= 10 => 85,
    $gainPct >= 5  => 70,
    $gainPct >= 0  => 50,
    $gainPct >= -5 => 30,
    default        => 10,
};

$dimensions = [
    ['key'=>'diversification', 'label'=>'Diversification',    'icon'=>'📊', 'score'=>$diversificationScore, 'detail'=>"{$catCount} categories, top: " . round($maxConcentration,1) . "%"],
    ['key'=>'holding_count',   'label'=>'Holdings Count',     'icon'=>'📋', 'score'=>$holdingCountScore,    'detail'=>"{$holdingCount} funds"],
    ['key'=>'sip_health',      'label'=>'SIP Health',         'icon'=>'🔁', 'score'=>$sipScore,             'detail'=>"{$activeSIPs} active SIPs"],
    ['key'=>'goal_alignment',  'label'=>'Goal Alignment',      'icon'=>'🎯', 'score'=>$goalScore,            'detail'=>"{$activeGoals} active goals"],
    ['key'=>'holding_period',  'label'=>'Holding Period',      'icon'=>'⏳', 'score'=>$holdingPeriodScore,   'detail'=>round($avgHoldingDays) . " avg days"],
    ['key'=>'returns',         'label'=>'Returns Health',      'icon'=>'📈', 'score'=>round($returnsScore),  'detail'=>round($gainPct,1) . "% return"],
];

$overallScore = round(array_sum(array_column($dimensions, 'score')) / count($dimensions));

// Category breakdown for secondary heatmap
$categoryBreakdown = [];
foreach ($catValues as $cat => $val) {
    $pct = $totalValue > 0 ? round($val/$totalValue*100,1) : 0;
    $categoryBreakdown[] = ['category'=>$cat, 'value'=>round($val), 'pct'=>$pct];
}
usort($categoryBreakdown, fn($a,$b) => $b['pct'] <=> $a['pct']);

json_response(true,'ok',[
    'overall_score' => $overallScore,
    'dimensions'    => $dimensions,
    'categories'    => $categoryBreakdown,
    'total_value'   => round($totalValue),
    'holding_count' => $holdingCount,
]);
