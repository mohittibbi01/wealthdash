<?php
/**
 * WealthDash — t333: AI Portfolio Report Card — Monthly Grade
 * File: api/ai/report_card.php
 * Actions: ai_report_card, ai_report_card_history
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    case 'ai_report_card': {
        RateLimit::check('ai_portfolio_review', $userId);

        $reviewMonth = date('Y-m');
        $force       = (bool)($_POST['force'] ?? false);

        // Check cache
        if (!$force) {
            $cached = DB::fetchRow(
                "SELECT * FROM ai_portfolio_reviews WHERE user_id=? AND review_month=? AND review_type='report_card'",
                [$userId, $reviewMonth]
            );
            if ($cached) {
                json_response(true,'ok',[
                    'grade'       => $cached['grade'],
                    'score'       => (int)$cached['score'],
                    'summary'     => $cached['summary'],
                    'strengths'   => json_decode($cached['strengths']  ?? '[]', true),
                    'weaknesses'  => json_decode($cached['weaknesses'] ?? '[]', true),
                    'actions'     => json_decode($cached['actions']    ?? '[]', true),
                    'raw'         => json_decode($cached['raw_response'] ?? '{}', true),
                    '_cached'     => true,
                    '_month'      => $reviewMonth,
                ]);
            }
        }

        // Build portfolio data
        $holdings = DB::fetchAll(
            "SELECT h.fund_name, h.units,
                    h.avg_cost_per_unit AS avg_cost,
                    COALESCE(n.nav, h.avg_cost_per_unit) AS current_nav,
                    h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS current_value,
                    h.units * h.avg_cost_per_unit AS invested_value
             FROM mf_holdings h
             LEFT JOIN mf_nav_latest n ON n.mf_id = h.mf_id
             WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
            [$userId, $portfolioId]
        );

        $totalValue    = array_sum(array_column($holdings, 'current_value'));
        $totalInvested = array_sum(array_column($holdings, 'invested_value'));
        $totalGain     = $totalValue - $totalInvested;
        $gainPct       = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0;

        $activeSIPs = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]) ?? 0);
        $sipTotal   = (float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]) ?? 0);
        $activeGoals= (int)(DB::fetchVal("SELECT COUNT(*) FROM goals WHERE user_id=? AND status='active'", [$userId]) ?? 0);
        $profile    = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?", [$userId]);

        // Score components (rule-based)
        $scores = [];

        // 1. Returns (25 pts): above 12% = full marks, scales down
        $returnScore = min(25, max(0, round($gainPct >= 12 ? 25 : ($gainPct / 12) * 25)));
        $scores['returns'] = ['label' => 'Investment Returns', 'score' => $returnScore, 'max' => 25,
            'detail' => "{$gainPct}% overall return", 'icon' => '📈'];

        // 2. Diversification (20 pts)
        $holdingCount = count($holdings);
        $divScore = min(20, $holdingCount >= 5 ? 20 : $holdingCount * 4);
        $scores['diversification'] = ['label' => 'Diversification', 'score' => $divScore, 'max' => 20,
            'detail' => "{$holdingCount} funds", 'icon' => '📊'];

        // 3. SIP Consistency (20 pts)
        $sipScore = $activeSIPs >= 3 ? 20 : ($activeSIPs * 7);
        $scores['sip'] = ['label' => 'SIP Discipline', 'score' => min(20, $sipScore), 'max' => 20,
            'detail' => "{$activeSIPs} active SIPs, ₹" . number_format($sipTotal, 0) . "/mo", 'icon' => '🔁'];

        // 4. Goal Alignment (20 pts)
        $goalScore = $activeGoals >= 2 ? 20 : ($activeGoals * 10);
        $scores['goals'] = ['label' => 'Goal Alignment', 'score' => min(20, $goalScore), 'max' => 20,
            'detail' => "{$activeGoals} active goals", 'icon' => '🎯'];

        // 5. Profile Completeness (15 pts)
        $profileFields = ['age','annual_income','tax_slab','risk_profile','investment_horizon','monthly_expenses','monthly_savings'];
        $profileComplete = $profile ? count(array_filter($profileFields, fn($f) => !empty($profile[$f]))) : 0;
        $profileScore = round($profileComplete / count($profileFields) * 15);
        $scores['profile'] = ['label' => 'Profile Completeness', 'score' => $profileScore, 'max' => 15,
            'detail' => "{$profileComplete}/" . count($profileFields) . " fields", 'icon' => '👤'];

        $totalScore = array_sum(array_column($scores, 'score'));
        $grade = match(true) {
            $totalScore >= 90 => 'A+',
            $totalScore >= 80 => 'A',
            $totalScore >= 70 => 'B+',
            $totalScore >= 60 => 'B',
            $totalScore >= 50 => 'C+',
            $totalScore >= 40 => 'C',
            default           => 'D',
        };

        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');

        // Rule-based strengths/weaknesses
        $strengths  = array_values(array_map(fn($k,$s) => $s['icon'].' '.$s['label'].": {$s['detail']}", array_keys(array_filter($scores, fn($s) => $s['score'] / $s['max'] >= 0.7)), array_filter($scores, fn($s) => $s['score'] / $s['max'] >= 0.7)));
        $weaknesses = array_values(array_map(fn($k,$s) => $s['icon'].' '.$s['label'].": Only {$s['score']}/{$s['max']} pts — improve this", array_keys(array_filter($scores, fn($s) => $s['score'] / $s['max'] < 0.5)), array_filter($scores, fn($s) => $s['score'] / $s['max'] < 0.5)));
        $summary    = "Portfolio grade: {$grade} ({$totalScore}/100). " . ($gainPct >= 0 ? "Returns are " . ($gainPct >= 12 ? "strong" : "moderate") : "Portfolio is in loss") . " at {$gainPct}%. {$activeSIPs} SIPs chal rahe hain.";

        $aiActions = [];
        if ($apiKey) {
            $holdingLines = implode(", ", array_map(fn($h) => $h['fund_name'] . " (" . round((float)$h['current_value'] / max(1, $totalValue) * 100, 0) . "%)", array_slice($holdings, 0, 6)));
            $prompt = "Indian investor portfolio report card ke liye summary do.

SCORE: {$totalScore}/100 (Grade: {$grade})
COMPONENTS:
" . implode("\n", array_map(fn($s) => "- {$s['label']}: {$s['score']}/{$s['max']} — {$s['detail']}", $scores)) . "

PORTFOLIO: ₹" . number_format($totalValue,0) . " | Return: {$gainPct}% | SIPs: {$activeSIPs}
TOP HOLDINGS: {$holdingLines}

Hinglish mein batao (max 150 words):
1. Grade justify karo
2. Top 3 strengths
3. Top 2 improvements needed

Return ONLY valid JSON:
{\"summary\":\"...\",\"strengths\":[\"...\",\"...\",\"...\"],\"weaknesses\":[\"...\",\"...\"],\"actions\":[{\"priority\":\"high\",\"action\":\"...\",\"reason\":\"...\"}],\"grade_justification\":\"...\"}";

            $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
                stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>500,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>25]]));
            if ($resp) {
                $text = json_decode($resp,true)['content'][0]['text'] ?? '{}';
                $text = trim(preg_replace(['/^```json\s*/m','/^```\s*/m'], '', $text));
                $parsed = json_decode($text, true);
                if ($parsed) {
                    $summary    = $parsed['summary']   ?? $summary;
                    $strengths  = $parsed['strengths'] ?? $strengths;
                    $weaknesses = $parsed['weaknesses']?? $weaknesses;
                    $aiActions  = $parsed['actions']   ?? [];
                }
            }
        }

        // Default actions if none
        if (!$aiActions) {
            if ($profileScore < 10) $aiActions[] = ['priority'=>'high',   'action'=>'Finance profile complete karo', 'reason'=>'Score badhega'];
            if ($activeSIPs < 2)    $aiActions[] = ['priority'=>'high',   'action'=>'Ek aur SIP start karo',       'reason'=>'Consistency builds wealth'];
            if ($activeGoals < 1)   $aiActions[] = ['priority'=>'medium', 'action'=>'Ek financial goal set karo',  'reason'=>'Focused investing better hai'];
        }

        // Save
        $rawData = json_encode(compact('scores','totalScore','grade','gainPct','totalValue'));
        $existing = DB::fetchVal("SELECT id FROM ai_portfolio_reviews WHERE user_id=? AND review_month=? AND review_type='report_card'", [$userId, $reviewMonth]);
        if ($existing) {
            DB::execute("UPDATE ai_portfolio_reviews SET grade=?,score=?,summary=?,strengths=?,weaknesses=?,actions=?,raw_response=?,created_at=NOW() WHERE id=?",
                [$grade,$totalScore,$summary,json_encode($strengths),json_encode($weaknesses),json_encode($aiActions),$rawData,$existing]);
        } else {
            DB::execute("INSERT INTO ai_portfolio_reviews(user_id,review_month,review_type,grade,score,summary,strengths,weaknesses,actions,raw_response,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())",
                [$userId,$reviewMonth,'report_card',$grade,$totalScore,$summary,json_encode($strengths),json_encode($weaknesses),json_encode($aiActions),$rawData]);
        }

        json_response(true,'ok',[
            'grade'      => $grade,
            'score'      => $totalScore,
            'summary'    => $summary,
            'strengths'  => $strengths,
            'weaknesses' => $weaknesses,
            'actions'    => $aiActions,
            'scores'     => array_values($scores),
            'month'      => $reviewMonth,
            'stats'      => ['total_value'=>round($totalValue),'total_gain'=>round($totalGain),'gain_pct'=>$gainPct,'sips'=>$activeSIPs,'goals'=>$activeGoals],
            '_cached'    => false,
            '_mode'      => $apiKey ? 'ai' : 'rule_based',
        ]);
        break;
    }

    case 'ai_report_card_history': {
        $rows = DB::fetchAll(
            "SELECT review_month, grade, score, summary, created_at FROM ai_portfolio_reviews
             WHERE user_id=? AND review_type='report_card' ORDER BY review_month DESC LIMIT 12",
            [$userId]
        );
        json_response(true,'ok',['history'=>$rows]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
