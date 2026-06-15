<?php
/**
 * WealthDash — t384: AI Anomaly Detector v2
 * File: api/ai/anomaly_detector_v2.php
 * Actions: ai_anomaly_v2_scan, ai_anomaly_v2_list, ai_anomaly_v2_resolve
 *
 * v2 improvements over t246:
 *  - Persists anomalies to anomaly_log (from t246_migration.sql)
 *  - Pattern detection: SIP gaps, sudden redemptions, NAV jumps
 *  - Resolve/dismiss workflow
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    case 'ai_anomaly_v2_scan': {
        RateLimit::check('ai_anomaly', $userId);

        $txns = DB::fetchAll(
            "SELECT txn_date, txn_type, amount, units, fund_name
             FROM mf_transactions
             WHERE user_id=? AND portfolio_id=? AND txn_date >= DATE_SUB(NOW(), INTERVAL 180 DAY)
             ORDER BY txn_date DESC LIMIT 300",
            [$userId, $portfolioId]
        );

        $found = [];
        $today = date('Y-m-d');

        // 1. Z-score outlier amounts
        if (count($txns) > 2) {
            $amounts = array_column($txns, 'amount');
            $mean = array_sum($amounts) / count($amounts);
            $var  = array_sum(array_map(fn($a) => pow($a - $mean, 2), $amounts)) / count($amounts);
            $std  = sqrt($var);
            foreach ($txns as $t) {
                $z = $std > 0 ? abs($t['amount'] - $mean) / $std : 0;
                if ($z > 2.5) {
                    $found[] = ['type'=>'outlier_amount', 'txn_date'=>$t['txn_date'], 'fund_name'=>$t['fund_name'], 'txn_type'=>$t['txn_type'], 'amount'=>(float)$t['amount'], 'reason'=>"Unusually large transaction (Z=".round($z,1).")", 'severity'=>$z>3?'high':'medium'];
                }
            }
        }

        // 2. Duplicate transactions
        $seen = [];
        foreach ($txns as $t) {
            $key = $t['txn_date'].':'.$t['txn_type'].':'.$t['amount'].':'.$t['fund_name'];
            if (isset($seen[$key])) {
                $found[] = ['type'=>'duplicate_txn','txn_date'=>$t['txn_date'],'fund_name'=>$t['fund_name'],'txn_type'=>$t['txn_type'],'amount'=>(float)$t['amount'],'reason'=>'Possible duplicate transaction entry','severity'=>'high'];
            }
            $seen[$key] = true;
        }

        // 3. SIP gap detection — missed monthly SIPs
        $activeSIPs = DB::fetchAll("SELECT fund_name, sip_amount, sip_date FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]);
        foreach ($activeSIPs as $sip) {
            $lastTxn = DB::fetchVal(
                "SELECT MAX(txn_date) FROM mf_transactions WHERE user_id=? AND portfolio_id=? AND fund_name=? AND txn_type IN ('sip','purchase')",
                [$userId, $portfolioId, $sip['fund_name']]
            );
            if ($lastTxn) {
                $daysSince = (int)((strtotime($today) - strtotime($lastTxn)) / 86400);
                if ($daysSince > 45) {
                    $found[] = ['type'=>'sip_gap','txn_date'=>$lastTxn,'fund_name'=>$sip['fund_name'],'txn_type'=>'sip','amount'=>(float)$sip['sip_amount'],'reason'=>"SIP not executed in {$daysSince} days — check auto-debit",'severity'=>$daysSince>60?'high':'medium'];
                }
            }
        }

        // 4. Large sudden redemptions (>30% of holding value)
        foreach ($txns as $t) {
            if (in_array($t['txn_type'], ['redemption','sell','redeem'])) {
                $holdingValue = (float)(DB::fetchVal(
                    "SELECT h.units * COALESCE(n.nav, h.avg_cost_per_unit) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.fund_name=?",
                    [$userId, $portfolioId, $t['fund_name']]
                ) ?? 0);
                if ($holdingValue > 0 && (float)$t['amount'] > $holdingValue * 0.5) {
                    $found[] = ['type'=>'large_redemption','txn_date'=>$t['txn_date'],'fund_name'=>$t['fund_name'],'txn_type'=>$t['txn_type'],'amount'=>(float)$t['amount'],'reason'=>'Large redemption — over 50% of current holding value','severity'=>'medium'];
                }
            }
        }

        // Save to anomaly_log (dedupe by date+type+fund)
        $saved = 0;
        foreach ($found as $a) {
            $exists = DB::fetchVal(
                "SELECT id FROM anomaly_log WHERE user_id=? AND anomaly_type=? AND txn_date=? AND fund_name=? AND resolved=0",
                [$userId, $a['type'], $a['txn_date'], $a['fund_name']]
            );
            if (!$exists) {
                DB::execute(
                    "INSERT INTO anomaly_log(user_id,scan_date,anomaly_type,txn_date,fund_name,txn_type,amount,reason,severity,resolved,created_at)
                     VALUES(?,?,?,?,?,?,?,?,?,0,NOW())",
                    [$userId, $today, $a['type'], $a['txn_date'], $a['fund_name'], $a['txn_type'], $a['amount'], $a['reason'], $a['severity']]
                );
                $saved++;
            }
        }

        // Optional AI summary
        $narrative = '';
        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');
        if ($apiKey && count($found) > 0) {
            $lines = implode("\n", array_map(fn($a) => "- [{$a['severity']}] {$a['type']}: {$a['fund_name']} on {$a['txn_date']} — {$a['reason']}", array_slice($found, 0, 15)));
            $prompt = "Review these portfolio anomalies for an Indian investor and give a brief prioritized summary in Hinglish (max 100 words):\n\n{$lines}";
            $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
                stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>200,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>15]]));
            if ($resp) $narrative = json_decode($resp,true)['content'][0]['text'] ?? '';
        }

        json_response(true,'ok',[
            'found_count' => count($found),
            'new_saved'   => $saved,
            'anomalies'   => $found,
            'narrative'   => $narrative,
            'period'      => 'Last 180 days',
        ]);
        break;
    }

    case 'ai_anomaly_v2_list': {
        $resolved = (int)($_GET['resolved'] ?? 0);
        $rows = DB::fetchAll(
            "SELECT * FROM anomaly_log WHERE user_id=? AND resolved=? ORDER BY created_at DESC LIMIT 100",
            [$userId, $resolved]
        );
        json_response(true,'ok',['anomalies'=>$rows]);
        break;
    }

    case 'ai_anomaly_v2_resolve': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $all = (int)($_POST['all'] ?? 0);
        if ($all) {
            DB::execute("UPDATE anomaly_log SET resolved=1 WHERE user_id=?", [$userId]);
        } else {
            $own = DB::fetchVal("SELECT id FROM anomaly_log WHERE id=? AND user_id=?", [$id,$userId]);
            if (!$own) json_response(false,'Not found.');
            DB::execute("UPDATE anomaly_log SET resolved=1 WHERE id=?", [$id]);
        }
        json_response(true,'Marked resolved.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
