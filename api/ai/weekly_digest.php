<?php
/**
 * WealthDash — t329: AI Weekly Digest
 * File: api/ai/weekly_digest.php
 * Actions: ai_weekly_digest, ai_digest_history
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    case 'ai_weekly_digest': {
        RateLimit::check('ai_portfolio_review', $userId);

        $weekAgo  = date('Y-m-d', strtotime('-7 days'));
        $today    = date('Y-m-d');
        $forceRegen = (bool)($_POST['force'] ?? false);

        // Check cached digest (1 per week per user)
        $weekKey = date('Y-W'); // ISO week number
        if (!$forceRegen) {
            $cached = DB::fetchRow(
                "SELECT id, digest_json, created_at FROM ai_weekly_digests
                 WHERE user_id=? AND week_key=?",
                [$userId, $weekKey]
            );
            if ($cached) {
                $data = json_decode($cached['digest_json'], true);
                $data['_cached']     = true;
                $data['_created_at'] = $cached['created_at'];
                json_response(true, 'ok', $data);
            }
        }

        // Portfolio snapshot
        $holdings = DB::fetchAll(
            "SELECT h.fund_name, h.units,
                    h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS current_value,
                    (h.units * COALESCE(n.nav, h.avg_cost_per_unit)) - (h.units * h.avg_cost_per_unit) AS gain
             FROM mf_holdings h
             LEFT JOIN mf_nav_latest n ON n.mf_id = h.mf_id
             WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0
             ORDER BY current_value DESC LIMIT 10",
            [$userId, $portfolioId]
        );

        $totalValue    = array_sum(array_column($holdings, 'current_value'));
        $totalGain     = array_sum(array_column($holdings, 'gain'));
        $totalInvested = $totalValue - $totalGain;
        $gainPct       = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0;

        // Recent transactions (last 7 days)
        $recentTxns = DB::fetchAll(
            "SELECT txn_date, txn_type, amount, fund_name
             FROM mf_transactions
             WHERE user_id=? AND portfolio_id=? AND txn_date >= ?
             ORDER BY txn_date DESC LIMIT 10",
            [$userId, $portfolioId, $weekAgo]
        );

        // Active SIPs
        $activeSIPs = (int)(DB::fetchVal(
            "SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",
            [$userId, $portfolioId]
        ) ?? 0);

        // Monthly SIP total
        $sipTotal = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",
            [$userId, $portfolioId]
        ) ?? 0);

        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');

        $holdingLines = implode("\n", array_map(
            fn($h) => "- {$h['fund_name']}: ₹" . number_format((float)$h['current_value'], 0) .
                      " (gain: " . ($h['gain'] >= 0 ? '+' : '') . "₹" . number_format((float)$h['gain'], 0) . ")",
            $holdings
        ));
        $txnLines = $recentTxns
            ? implode("\n", array_map(fn($t) => "- {$t['txn_date']} {$t['txn_type']}: {$t['fund_name']} ₹" . number_format((float)$t['amount'], 0), $recentTxns))
            : "- No transactions this week";

        $prompt = "Create a friendly weekly financial digest for an Indian investor.

PORTFOLIO SUMMARY (Week of {$today}):
Total Value: ₹" . number_format($totalValue, 0) . "
Total Gain: ₹" . number_format($totalGain, 0) . " ({$gainPct}%)
Active SIPs: {$activeSIPs} (₹" . number_format($sipTotal, 0) . "/month)

TOP HOLDINGS:
{$holdingLines}

THIS WEEK'S TRANSACTIONS:
{$txnLines}

Write a weekly digest in Hinglish with:
1. Portfolio performance this week (2-3 sentences)
2. Notable market events relevant to Indian investors (general knowledge)
3. 2-3 actionable tips for this week
4. One motivational line about wealth building

Return ONLY valid JSON:
{
  \"headline\": \"Short catchy headline\",
  \"week\": \"{$weekKey}\",
  \"portfolio_update\": \"2-3 sentences about portfolio\",
  \"market_highlights\": [\"highlight 1\", \"highlight 2\", \"highlight 3\"],
  \"action_items\": [
    {\"item\": \"Action description\", \"priority\": \"high\"},
    {\"item\": \"Action description\", \"priority\": \"medium\"},
    {\"item\": \"Action description\", \"priority\": \"low\"}
  ],
  \"motivation\": \"One motivational line\",
  \"stats\": {
    \"total_value\": " . round($totalValue) . ",
    \"total_gain\": " . round($totalGain) . ",
    \"gain_pct\": {$gainPct},
    \"active_sips\": {$activeSIPs},
    \"txn_count\": " . count($recentTxns) . "
  }
}";

        if (!$apiKey) {
            // Rule-based fallback
            $digest = [
                'headline'          => "Week of " . date('d M Y') . " — Portfolio Update",
                'week'              => $weekKey,
                'portfolio_update'  => "Aapka portfolio is week ₹" . number_format($totalValue, 0) . " pe hai. Total gain " . ($gainPct >= 0 ? '+' : '') . "{$gainPct}% hai. " . ($activeSIPs > 0 ? "{$activeSIPs} active SIP chal raha hai." : ''),
                'market_highlights' => ["Indian equity markets remain resilient", "RBI monetary policy focus on inflation", "SIP culture growing strongly in India"],
                'action_items'      => [
                    ['item' => 'Review your SIP amounts — consider step-up', 'priority' => 'medium'],
                    ['item' => 'Check if any FD is maturing this month', 'priority' => 'medium'],
                    ['item' => 'Verify nominee details in all investments', 'priority' => 'low'],
                ],
                'motivation'        => "Consistency beats timing — roz thoda invest karo, bada result milega.",
                'stats'             => [
                    'total_value'  => round($totalValue),
                    'total_gain'   => round($totalGain),
                    'gain_pct'     => $gainPct,
                    'active_sips'  => $activeSIPs,
                    'txn_count'    => count($recentTxns),
                ],
                '_mode' => 'rule_based',
            ];
        } else {
            $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
                stream_context_create(['http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",
                    'content' => json_encode(['model' => 'claude-sonnet-4-20250514', 'max_tokens' => 600, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
                    'timeout' => 25,
                ]]));

            if (!$resp) json_response(false, 'AI service unavailable.');
            $text   = json_decode($resp, true)['content'][0]['text'] ?? '{}';
            $text   = preg_replace('/^```json\s*/m', '', trim($text));
            $text   = preg_replace('/^```\s*/m', '', $text);
            $digest = json_decode(trim($text), true);
            if (!$digest) json_response(false, 'AI response parse failed.');
            $digest['_mode'] = 'ai';
        }

        // Save digest
        $digestJson = json_encode($digest);
        $existing   = DB::fetchVal("SELECT id FROM ai_weekly_digests WHERE user_id=? AND week_key=?", [$userId, $weekKey]);
        if ($existing) {
            DB::execute("UPDATE ai_weekly_digests SET digest_json=?,updated_at=NOW() WHERE id=?", [$digestJson, $existing]);
        } else {
            DB::execute("INSERT INTO ai_weekly_digests(user_id,week_key,digest_json,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())", [$userId, $weekKey, $digestJson]);
        }

        $digest['_cached'] = false;
        json_response(true, 'ok', $digest);
        break;
    }

    case 'ai_digest_history': {
        $rows = DB::fetchAll(
            "SELECT week_key, digest_json, created_at FROM ai_weekly_digests
             WHERE user_id=? ORDER BY week_key DESC LIMIT 8",
            [$userId]
        );
        $history = array_map(function($r) {
            $d = json_decode($r['digest_json'], true);
            return [
                'week_key'   => $r['week_key'],
                'headline'   => $d['headline'] ?? '',
                'stats'      => $d['stats'] ?? [],
                'created_at' => $r['created_at'],
            ];
        }, $rows);
        json_response(true, 'ok', ['history' => $history]);
        break;
    }

    default: json_response(false, 'Unknown action.', [], 400);
}
