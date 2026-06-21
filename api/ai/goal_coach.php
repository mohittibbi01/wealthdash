<?php
/**
 * WealthDash — t385: AI Goal Coach — Personalized Nudges
 * File: api/ai/goal_coach.php
 * Actions: ai_goal_coach_nudges, ai_goal_coach_dismiss
 *
 * Generates short, personalized "nudge" messages (different from t332's
 * full goal advisor analysis) — designed for dashboard widget display.
 * Nudges are cached daily per user.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    case 'ai_goal_coach_nudges': {
        $today = date('Y-m-d');
        $force = (bool)($_POST['force'] ?? false);

        if (!$force) {
            $cached = DB::fetchRow("SELECT nudges_json FROM ai_goal_coach_nudges WHERE user_id=? AND nudge_date=?", [$userId, $today]);
            if ($cached) {
                json_response(true,'ok',['nudges'=>json_decode($cached['nudges_json'],true),'_cached'=>true]);
            }
        }

        RateLimit::check('ai_goal_advice', $userId);

        $goals = DB::fetchAll(
            "SELECT g.id, g.goal_name, g.target_amount, g.target_date,
                    COALESCE(SUM(gc.amount),0) AS invested,
                    MAX(gc.checkin_date) AS last_checkin
             FROM goals g
             LEFT JOIN goal_checkins gc ON gc.goal_id = g.id
             WHERE g.user_id=? AND g.status='active'
             GROUP BY g.id",
            [$userId]
        );

        $nudges = [];

        if (!$goals) {
            $nudges[] = ['icon'=>'🎯','type'=>'setup','message'=>'Aapka koi active goal nahi hai. Pehla financial goal set karo — retirement, home, education, etc.','cta'=>'Add Goal','goal_id'=>null];
        }

        foreach ($goals as $g) {
            $target   = (float)$g['target_amount'];
            $invested = (float)$g['invested'];
            $pct      = $target > 0 ? round($invested/$target*100,1) : 0;
            $daysLeft = $g['target_date'] ? (int)((strtotime($g['target_date']) - time())/86400) : null;
            $daysSinceCheckin = $g['last_checkin'] ? (int)((time() - strtotime($g['last_checkin']))/86400) : 9999;

            // Nudge: no checkin in 30+ days
            if ($daysSinceCheckin > 30) {
                $nudges[] = ['icon'=>'⏰','type'=>'checkin','message'=>"{$g['goal_name']} ke liye {$daysSinceCheckin} din se koi update nahi hai. Quick check-in karo!",'cta'=>'Update Now','goal_id'=>$g['id']];
            }

            // Nudge: milestone close (within 5% of next milestone)
            foreach ([25,50,75,100] as $m) {
                if ($pct >= $m - 5 && $pct < $m) {
                    $nudges[] = ['icon'=>'🎉','type'=>'milestone_close','message'=>"{$g['goal_name']} {$m}% milestone se sirf ".round($m-$pct,1)."% door hai! Thoda extra invest karke achieve karo.",'cta'=>'View Goal','goal_id'=>$g['id']];
                    break;
                }
            }

            // Nudge: behind schedule with deadline approaching
            if ($daysLeft !== null && $daysLeft > 0 && $daysLeft < 365) {
                $totalDays = max(1, $daysLeft + (time() - strtotime('2024-01-01'))/86400);
                $timeProgress = min(100, round((1 - $daysLeft/$totalDays)*100,1));
                if ($pct < $timeProgress - 15) {
                    $monthsLeft = max(1, round($daysLeft/30.44));
                    $gap = $target - $invested;
                    $extraSip = round($gap / $monthsLeft);
                    $nudges[] = ['icon'=>'⚠️','type'=>'behind','message'=>"{$g['goal_name']} thoda peeche hai ({$pct}% vs expected {$timeProgress}%). ₹".number_format($extraSip,0)."/month extra invest karne se {$monthsLeft} mahine mein catch up ho sakta hai.",'cta'=>'Adjust SIP','goal_id'=>$g['id']];
                }
            }

            // Nudge: goal completed
            if ($pct >= 100) {
                $nudges[] = ['icon'=>'🏆','type'=>'completed','message'=>"Congratulations! {$g['goal_name']} goal complete ho gaya! Naya goal set karne ka time hai.",'cta'=>'New Goal','goal_id'=>$g['id']];
            }
        }

        // SIP step-up nudge (general)
        $sipCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId,$portfolioId]) ?? 0);
        if ($sipCount > 0) {
            $month = (int)date('n');
            if ($month === 4) { // April — new FY, suggest step-up
                $nudges[] = ['icon'=>'📈','type'=>'stepup','message'=>'Naya financial year shuru hua hai — apne SIPs mein 10% step-up consider karo. Income badhi hai toh investment bhi badhana chahiye!','cta'=>'Review SIPs','goal_id'=>null];
            }
        }

        // Limit to top 5, prioritize by type
        $priority = ['behind'=>1,'milestone_close'=>2,'completed'=>3,'checkin'=>4,'stepup'=>5,'setup'=>6];
        usort($nudges, fn($a,$b) => ($priority[$a['type']]??9) <=> ($priority[$b['type']]??9));
        $nudges = array_slice($nudges, 0, 5);

        if (!$nudges) {
            $nudges[] = ['icon'=>'✅','type'=>'all_good','message'=>'Sab goals on track hain! Great job — consistency maintain rakho. 👏','cta'=>null,'goal_id'=>null];
        }

        // Save daily cache
        $nudgesJson = json_encode($nudges);
        $existing = DB::fetchVal("SELECT id FROM ai_goal_coach_nudges WHERE user_id=? AND nudge_date=?", [$userId,$today]);
        if ($existing) {
            DB::execute("UPDATE ai_goal_coach_nudges SET nudges_json=? WHERE id=?", [$nudgesJson, $existing]);
        } else {
            DB::execute("INSERT INTO ai_goal_coach_nudges(user_id,nudge_date,nudges_json,created_at) VALUES(?,?,?,NOW())", [$userId,$today,$nudgesJson]);
        }

        json_response(true,'ok',['nudges'=>$nudges,'_cached'=>false]);
        break;
    }

    case 'ai_goal_coach_dismiss': {
        csrf_verify();
        $today = date('Y-m-d');
        DB::execute("DELETE FROM ai_goal_coach_nudges WHERE user_id=? AND nudge_date=?", [$userId,$today]);
        json_response(true,'Dismissed for today.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
