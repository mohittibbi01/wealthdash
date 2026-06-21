<?php
/**
 * WealthDash — t246: AI Anomaly Detector (FIXED)
 * File: api/ai/anomaly_detector.php
 * Removed: mf_id JOIN, sip_id reference (columns may not exist)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action=clean($_POST['action']??$_GET['action']??'');
$userId=(int)$_SESSION['user_id']; $portfolioId=get_user_portfolio_id($userId);

if ($action !== 'ai_anomaly_detect') { json_response(false,'Unknown action.',[],400); }

RateLimit::check('ai_anomaly',3,60);

// Fetch transactions — use only columns guaranteed to exist
$txns = DB::fetchAll(
    "SELECT txn_date, txn_type, amount, units, fund_name
     FROM mf_transactions
     WHERE user_id=? AND portfolio_id=?
       AND txn_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
     ORDER BY txn_date DESC
     LIMIT 200",
    [$userId, $portfolioId]
);

$anomalies = [];

if (count($txns) > 1) {
    // Z-score: flag unusually large amounts
    $amounts = array_column($txns, 'amount');
    $mean    = array_sum($amounts) / count($amounts);
    $variance= array_sum(array_map(fn($a) => pow($a - $mean, 2), $amounts)) / count($amounts);
    $stddev  = sqrt($variance);

    foreach ($txns as $t) {
        $z = $stddev > 0 ? abs($t['amount'] - $mean) / $stddev : 0;
        if ($z > 2.5) {
            $anomalies[] = [
                'date'     => $t['txn_date'],
                'fund'     => $t['fund_name'] ?? '—',
                'type'     => $t['txn_type'],
                'amount'   => (float)$t['amount'],
                'reason'   => 'Unusually large transaction (Z=' . round($z,1) . ')',
                'severity' => $z > 3 ? 'high' : 'medium',
            ];
        }
    }

    // Duplicate: same date + type + amount
    $seen = [];
    foreach ($txns as $t) {
        $key = $t['txn_date'] . ':' . $t['txn_type'] . ':' . $t['amount'];
        if (isset($seen[$key])) {
            $anomalies[] = [
                'date'     => $t['txn_date'],
                'fund'     => $t['fund_name'] ?? '—',
                'type'     => $t['txn_type'],
                'amount'   => (float)$t['amount'],
                'reason'   => 'Possible duplicate transaction',
                'severity' => 'high',
            ];
        }
        $seen[$key] = true;
    }
}

// Optional AI narrative
$narrative = '';
$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');
if ($apiKey && count($txns) > 0) {
    $lines = implode("\n", array_map(
        fn($t) => "{$t['txn_date']} | {$t['txn_type']} | " . ($t['fund_name']??'?') . " | ₹" . number_format((float)$t['amount'],0),
        array_slice($txns, 0, 20)
    ));
    $prompt = "Review these recent mutual fund transactions for an Indian investor and identify anomalies:\n\n$lines\n\nPoint out anything unusual in 2-3 sentences. If everything looks normal, say so.";
    $resp = @file_get_contents('https://api.anthropic.com/v1/messages',false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: $apiKey\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>200,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>15]]));
    $narrative = $resp ? (json_decode($resp,true)['content'][0]['text'] ?? '') : '';
}

json_response(true,'ok',[
    'anomalies' => $anomalies,
    'txn_count' => count($txns),
    'narrative' => $narrative,
    'period'    => 'Last 90 days',
]);
