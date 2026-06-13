<?php
/**
 * WealthDash — t332: AI Goal Advisor
 * File: api/ai/goal_advisor.php
 * Actions: ai_goal_advice
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'ai_goal_advice') { json_response(false,'Unknown action.',[],400); }

RateLimit::check('ai_goal_advice', $userId);

// Goals data
$goals = DB::fetchAll(
    "SELECT g.id, g.goal_name, g.target_amount, g.target_date, g.status,
            COALESCE(SUM(gc.amount),0) AS actual_invested,
            COUNT(gc.id) AS checkin_count
     FROM goals g
     LEFT JOIN goal_checkins gc ON gc.goal_id = g.id
     WHERE g.user_id=? AND g.status='active'
     GROUP BY g.id
     ORDER BY g.target_date ASC",
    [$userId]
);

$profile = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?", [$userId]);
$totalSIP = (float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]) ?? 0);
$portfolioValue = (float)(DB::fetchVal(
    "SELECT COALESCE(SUM(h.units * COALESCE(n.nav, h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
    [$userId, $portfolioId]
) ?? 0);

$today = date('Y-m-d');
$goalAnalysis = [];
foreach ($goals as $g) {
    $target   = (float)$g['target_amount'];
    $invested = (float)$g['actual_invested'];
    $pct      = $target > 0 ? round($invested / $target * 100, 1) : 0;

    $totalDays   = max(1, (strtotime($g['target_date']) - strtotime('2020-01-01')) / 86400);
    $elapsedDays = max(0, (strtotime($today) - strtotime('2020-01-01')) / 86400);
    $timeProgress = min(100, round($elapsedDays / $totalDays * 100, 1));

    $monthsLeft  = max(0, round((strtotime($g['target_date']) - time()) / (86400 * 30.44)));
    $remaining   = max(0, $target - $invested);
    $sipNeeded   = $monthsLeft > 0 ? round($remaining / $monthsLeft) : 0;

    $variance    = $pct - $timeProgress;
    $status      = match(true) {
        $pct >= 100         => 'completed',
        $variance >= 0      => 'on_track',
        abs($variance) <= 10 => 'slightly_behind',
        default             => 'behind',
    };

    $goalAnalysis[] = [
        'id'           => (int)$g['id'],
        'goal_name'    => $g['goal_name'],
        'target_amount'=> $target,
        'invested'     => $invested,
        'progress_pct' => $pct,
        'time_progress'=> $timeProgress,
        'variance'     => round($variance, 1),
        'months_left'  => $monthsLeft,
        'sip_needed'   => $sipNeeded,
        'status'       => $status,
        'target_date'  => $g['target_date'],
    ];
}

$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');
$narrative = '';

if ($apiKey && count($goalAnalysis) > 0) {
    $goalLines = implode("\n", array_map(fn($g) =>
        "- {$g['goal_name']}: Target ₹" . number_format($g['target_amount'],0) .
        ", Invested ₹" . number_format($g['invested'],0) .
        " ({$g['progress_pct']}%), Status: {$g['status']}, {$g['months_left']} months left, Need: ₹" . number_format($g['sip_needed'],0) . "/mo",
        $goalAnalysis
    ), $goalAnalysis);

    $prompt = "You are a WealthDash goal advisor for an Indian investor. Analyze their financial goals and give smart advice.

GOALS:
{$goalLines}

CURRENT PORTFOLIO: ₹" . number_format($portfolioValue,0) . "
MONTHLY SIP: ₹" . number_format($totalSIP,0) . "
RISK PROFILE: " . ($profile['risk_profile'] ?? 'moderate') . "

Provide goal advice in Hinglish (max 200 words):
1. Kaunsa goal on track hai, kaunsa behind?
2. Most urgent action item (specific)
3. Kaunse goal ke liye SIP amount badhana chahiye?
4. Overall goal achievement probability

Be specific, use numbers, stay actionable.";

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
        stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>350,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>20]]));
    if ($resp) $narrative = json_decode($resp,true)['content'][0]['text'] ?? '';
}

// Rule-based summary
$onTrack  = count(array_filter($goalAnalysis, fn($g) => $g['status'] === 'on_track'));
$behind   = count(array_filter($goalAnalysis, fn($g) => in_array($g['status'], ['behind','slightly_behind'])));
$totalNeededSIP = array_sum(array_column(array_filter($goalAnalysis, fn($g) => $g['status'] !== 'completed'), 'sip_needed'));

json_response(true,'ok',[
    'goals'             => $goalAnalysis,
    'total_goals'       => count($goalAnalysis),
    'on_track'          => $onTrack,
    'behind'            => $behind,
    'current_sip'       => round($totalSIP),
    'total_sip_needed'  => round($totalNeededSIP),
    'sip_gap'           => round(max(0, $totalNeededSIP - $totalSIP)),
    'portfolio_value'   => round($portfolioValue),
    'ai_narrative'      => $narrative,
    'mode'              => $apiKey ? 'ai' : 'rule_based',
]);
