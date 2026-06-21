<?php
/**
 * WealthDash — t443: Morning Briefing — Daily Market Digest
 * File: api/dashboard/morning_briefing.php
 * Actions: morning_briefing_get
 *
 * Combines market_pulse.php data + portfolio snapshot + AI summary.
 * Cached once per day per user.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

if ($action !== 'morning_briefing_get') { json_response(false,'Unknown action.',[],400); }

$today = date('Y-m-d');
$force = (bool)($_POST['force'] ?? false);

if (!$force) {
    $cached = DB::fetchRow("SELECT briefing_json FROM morning_briefings WHERE user_id=? AND briefing_date=?", [$userId, $today]);
    if ($cached) {
        $data = json_decode($cached['briefing_json'], true);
        $data['_cached'] = true;
        json_response(true,'ok',$data);
    }
}

// 1. Market pulse — call internal function if available, else basic fetch
$marketData = [];
try {
    // Reuse cached session market pulse if present (from market_pulse.php pattern)
    if (!empty($_SESSION['market_pulse_cache']) && (time() - ($_SESSION['market_pulse_ts'] ?? 0)) < 3600) {
        $marketData = $_SESSION['market_pulse_cache'];
    } else {
        // Lightweight fetch — Yahoo Finance Nifty + Sensex only (fast)
        $symbols = ['^NSEI' => 'NIFTY', '^BSESN' => 'SENSEX'];
        foreach ($symbols as $sym => $label) {
            $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$sym}?interval=1d&range=2d";
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'Mozilla/5.0']]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body) {
                $d = json_decode($body, true);
                $meta = $d['chart']['result'][0]['meta'] ?? null;
                if ($meta) {
                    $price = (float)($meta['regularMarketPrice'] ?? 0);
                    $prev  = (float)($meta['chartPreviousClose'] ?? $price);
                    $chgPct = $prev > 0 ? round(($price-$prev)/$prev*100,2) : 0;
                    $marketData[] = ['symbol'=>$label,'name'=>$label==='NIFTY'?'Nifty 50':'Sensex','value'=>number_format($price,2),'change_pct'=>$chgPct];
                }
            }
        }
    }
} catch (\Throwable $e) {}

// 2. Portfolio snapshot
$portfolioValue = (float)(DB::fetchVal(
    "SELECT COALESCE(SUM(h.units * COALESCE(n.nav, h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
    [$userId, $portfolioId]
) ?? 0);
$invested = (float)(DB::fetchVal(
    "SELECT COALESCE(SUM(h.units * h.avg_cost_per_unit),0) FROM mf_holdings h WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
    [$userId, $portfolioId]
) ?? 0);
$gainPct = $invested > 0 ? round(($portfolioValue-$invested)/$invested*100,2) : 0;

// 3. Today's events
$sipToday = DB::fetchAll(
    "SELECT fund_name, sip_amount FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active' AND sip_date=?",
    [$userId, $portfolioId, (int)date('j')]
);

$alertsCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM smart_alerts WHERE user_id=? AND is_read=0", [$userId]) ?? 0);

// 4. AI summary
$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');
$greeting = match(true) {
    (int)date('G') < 12 => 'Good Morning',
    (int)date('G') < 17 => 'Good Afternoon',
    default => 'Good Evening',
};

$narrative = '';
if ($apiKey) {
    $marketLines = implode(", ", array_map(fn($m) => "{$m['name']}: {$m['value']} ({$m['change_pct']>=0?'+':''}{$m['change_pct']}%)", $marketData));
    $prompt = "Create a short morning briefing for an Indian mutual fund investor.

Date: " . date('d M Y, l') . "
MARKET: {$marketLines}
PORTFOLIO: ₹" . number_format($portfolioValue,0) . " (Return: {$gainPct}%)
SIPs today: " . count($sipToday) . "
Unread alerts: {$alertsCount}

Write 2-3 sentences in Hinglish — market mood + one quick portfolio thought + a positive note for the day. Keep it brief and energizing.";

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
        stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>150,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>15]]));
    if ($resp) $narrative = json_decode($resp,true)['content'][0]['text'] ?? '';
}

if (!$narrative) {
    $marketMood = !empty($marketData) && ($marketData[0]['change_pct'] ?? 0) >= 0 ? 'green' : 'red';
    $narrative = "{$greeting}! Aaj market " . ($marketMood==='green' ? 'green mein hai 📈' : 'thoda neeche hai 📉') . ". Aapka portfolio ₹" . number_format($portfolioValue,0) . " ka hai (" . ($gainPct>=0?'+':'') . "{$gainPct}%). " . (count($sipToday) ? count($sipToday) . " SIP aaj process hoga." : "Aaj koi SIP scheduled nahi hai.") . " Have a productive day! 🚀";
}

$briefing = [
    'greeting'    => $greeting,
    'date'        => date('l, d M Y'),
    'market'      => $marketData,
    'portfolio'   => ['value'=>round($portfolioValue),'gain_pct'=>$gainPct],
    'sips_today'  => $sipToday,
    'alerts_count'=> $alertsCount,
    'narrative'   => $narrative,
    'mode'        => $apiKey ? 'ai' : 'rule_based',
];

// Cache
$json = json_encode($briefing);
$existing = DB::fetchVal("SELECT id FROM morning_briefings WHERE user_id=? AND briefing_date=?", [$userId, $today]);
if ($existing) DB::execute("UPDATE morning_briefings SET briefing_json=? WHERE id=?", [$json, $existing]);
else DB::execute("INSERT INTO morning_briefings(user_id,briefing_date,briefing_json,created_at) VALUES(?,?,?,NOW())", [$userId, $today, $json]);

$briefing['_cached'] = false;
json_response(true,'ok',$briefing);
