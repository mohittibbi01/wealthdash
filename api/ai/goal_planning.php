<?php
/**
 * WealthDash — t61: AI Goal-based Planning — Full Multi-Goal Planner
 * File: api/ai/goal_planning.php
 * Actions: ai_goal_plan_create, ai_goal_plan_list, ai_goal_plan_optimize,
 *          ai_goal_priority_simulate
 *
 * DIFFERENT from t332 (goal_advisor - status/variance analysis) and t385
 * (goal_coach - daily nudges). This is a FORWARD-LOOKING PLANNER: given
 * multiple goals + available monthly surplus, allocates optimal SIP amounts
 * across goals based on priority, time horizon, and required corpus.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    // ── Create a new goal plan (goal + horizon + priority) ──────────
    case 'ai_goal_plan_create': {
        csrf_verify();
        $goalName   = clean($_POST['goal_name']    ?? '');
        $target     = (float)($_POST['target_amount'] ?? 0);
        $targetDate = clean($_POST['target_date']   ?? '');
        $priority   = clean($_POST['priority']      ?? 'medium'); // high/medium/low
        $existingCorpus = (float)($_POST['existing_corpus'] ?? 0);

        if (!$goalName || !$target || !$targetDate) json_response(false,'Goal name, target amount and date required.');

        // Create the goal (reuses existing goals table)
        DB::execute(
            "INSERT INTO goals(user_id,goal_name,target_amount,target_date,status,created_at) VALUES(?,?,?,?,'active',NOW())",
            [$userId, $goalName, $target, $targetDate]
        );
        $goalId = DB::lastInsertId();

        // Save planning metadata
        DB::execute(
            "INSERT INTO goal_plan_meta(goal_id,user_id,priority,existing_corpus,created_at) VALUES(?,?,?,?,NOW())",
            [$goalId, $userId, $priority, $existingCorpus]
        );

        // If existing corpus given, seed a checkin
        if ($existingCorpus > 0) {
            DB::execute("INSERT INTO goal_checkins(goal_id,amount,checkin_date,notes,created_at) VALUES(?,?,CURDATE(),?,NOW())",
                [$goalId, $existingCorpus, 'Initial corpus']);
        }

        json_response(true,'Goal plan created.',['goal_id'=>$goalId]);
        break;
    }

    // ── List all goal plans with computed required SIP ───────────────
    case 'ai_goal_plan_list': {
        $goals = DB::fetchAll(
            "SELECT g.id, g.goal_name, g.target_amount, g.target_date,
                    COALESCE(SUM(gc.amount),0) AS invested,
                    COALESCE(gpm.priority,'medium') AS priority
             FROM goals g
             LEFT JOIN goal_checkins gc ON gc.goal_id=g.id
             LEFT JOIN goal_plan_meta gpm ON gpm.goal_id=g.id
             WHERE g.user_id=? AND g.status='active'
             GROUP BY g.id",
            [$userId]
        );

        $returnRate = 0.12; // assumed annual return for SIP projection
        $result = [];
        foreach ($goals as $g) {
            $target   = (float)$g['target_amount'];
            $invested = (float)$g['invested'];
            $monthsLeft = max(1, round((strtotime($g['target_date'])-time())/(86400*30.44)));
            $remaining  = max(0, $target - $invested);

            $mr = $returnRate/12;
            $requiredSip = $remaining>0 && $monthsLeft>0
                ? ($mr>0 ? $remaining*$mr/(pow(1+$mr,$monthsLeft)-1) : $remaining/$monthsLeft)
                : 0;

            $result[] = [
                'id'=>(int)$g['id'],'goal_name'=>$g['goal_name'],'target_amount'=>$target,
                'invested'=>$invested,'remaining'=>round($remaining,2),
                'months_left'=>$monthsLeft,'priority'=>$g['priority'],
                'required_sip'=>round($requiredSip,2),
                'progress_pct'=>$target>0?round($invested/$target*100,1):0,
            ];
        }

        usort($result, fn($a,$b) => ['high'=>0,'medium'=>1,'low'=>2][$a['priority']] <=> ['high'=>0,'medium'=>1,'low'=>2][$b['priority']]);

        json_response(true,'ok',['goals'=>$result,'total_required_sip'=>round(array_sum(array_column($result,'required_sip')),2)]);
        break;
    }

    // ── Optimize: given available monthly surplus, allocate across goals ──
    case 'ai_goal_plan_optimize': {
        $surplus = (float)($_POST['monthly_surplus'] ?? 0);
        if ($surplus <= 0) json_response(false,'Monthly surplus required.');

        $goals = DB::fetchAll(
            "SELECT g.id, g.goal_name, g.target_amount, g.target_date,
                    COALESCE(SUM(gc.amount),0) AS invested,
                    COALESCE(gpm.priority,'medium') AS priority
             FROM goals g
             LEFT JOIN goal_checkins gc ON gc.goal_id=g.id
             LEFT JOIN goal_plan_meta gpm ON gpm.goal_id=g.id
             WHERE g.user_id=? AND g.status='active'
             GROUP BY g.id",
            [$userId]
        );

        $returnRate = 0.12; $mr = $returnRate/12;
        $priorityWeight = ['high'=>3,'medium'=>2,'low'=>1];

        $needs = [];
        foreach ($goals as $g) {
            $target = (float)$g['target_amount']; $invested = (float)$g['invested'];
            $monthsLeft = max(1, round((strtotime($g['target_date'])-time())/(86400*30.44)));
            $remaining = max(0, $target-$invested);
            $requiredSip = $remaining>0 ? ($mr>0 ? $remaining*$mr/(pow(1+$mr,$monthsLeft)-1) : $remaining/$monthsLeft) : 0;
            $needs[] = ['id'=>(int)$g['id'],'goal_name'=>$g['goal_name'],'required_sip'=>round($requiredSip,2),'priority'=>$g['priority'],'weight'=>$priorityWeight[$g['priority']]??2,'months_left'=>$monthsLeft];
        }

        $totalRequired = array_sum(array_column($needs,'required_sip'));

        $allocation = [];
        if ($totalRequired <= $surplus) {
            // Surplus covers everything — fund all goals fully + distribute extra by weight
            $extra = $surplus - $totalRequired;
            $totalWeight = array_sum(array_column($needs,'weight'));
            foreach ($needs as $n) {
                $extraShare = $totalWeight>0 ? $extra * ($n['weight']/$totalWeight) : 0;
                $allocation[] = ['goal_id'=>$n['id'],'goal_name'=>$n['goal_name'],'allocated'=>round($n['required_sip']+$extraShare,2),'fully_funded'=>true];
            }
        } else {
            // Not enough — allocate proportional to weight × urgency (1/months_left)
            $scores = array_map(fn($n) => $n['weight'] * (1/max(1,$n['months_left'])), $needs);
            $totalScore = array_sum($scores);
            foreach ($needs as $i => $n) {
                $share = $totalScore>0 ? $scores[$i]/$totalScore : 0;
                $allocated = round($surplus * $share, 2);
                $allocation[] = ['goal_id'=>$n['id'],'goal_name'=>$n['goal_name'],'allocated'=>$allocated,'fully_funded'=>$allocated>=$n['required_sip'],'shortfall'=>round(max(0,$n['required_sip']-$allocated),2)];
            }
        }

        json_response(true,'ok',[
            'surplus'=>$surplus,'total_required'=>round($totalRequired,2),
            'fully_covered'=>$totalRequired<=$surplus,
            'allocation'=>$allocation,
        ]);
        break;
    }

    // ── Simulate: what if priority order changes? ─────────────────────
    case 'ai_goal_priority_simulate': {
        csrf_verify();
        $goalId   = (int)($_POST['goal_id'] ?? 0);
        $priority = clean($_POST['priority'] ?? 'medium');
        $own = DB::fetchVal("SELECT id FROM goals WHERE id=? AND user_id=?", [$goalId,$userId]);
        if (!$own) json_response(false,'Goal not found.');

        $ex = DB::fetchVal("SELECT id FROM goal_plan_meta WHERE goal_id=?", [$goalId]);
        if ($ex) DB::execute("UPDATE goal_plan_meta SET priority=? WHERE id=?", [$priority,$ex]);
        else     DB::execute("INSERT INTO goal_plan_meta(goal_id,user_id,priority,existing_corpus,created_at) VALUES(?,?,?,0,NOW())", [$goalId,$userId,$priority]);

        json_response(true,'Priority updated.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
