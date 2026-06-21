<?php
/**
 * WealthDash — t155: Child Education Planner
 * File: api/goal/education_planner.php
 * Actions: edu_plans_list, edu_plan_save, edu_plan_delete, edu_plan_calculate
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    case 'edu_plan_calculate': {
        $childName      = clean($_POST['child_name']      ?? 'Child');
        $childAge       = (int)($_POST['child_age']       ?? 5);
        $targetAge      = (int)($_POST['target_age']      ?? 18);   // age when education starts
        $currentCost    = (float)($_POST['current_cost']  ?? 1500000); // today's cost
        $inflation      = (float)($_POST['inflation']     ?? 8.0);  // education inflation %
        $returnRate     = (float)($_POST['return_rate']   ?? 12.0); // investment return %
        $existingCorpus = (float)($_POST['existing_corpus']?? 0);
        $monthlySIP     = (float)($_POST['monthly_sip']   ?? 0);

        $years  = max(1, $targetAge - $childAge);
        $months = $years * 12;

        // Future cost adjusted for education inflation
        $futureCost = $currentCost * pow(1 + $inflation/100, $years);

        // FV of existing corpus
        $fvExisting = $existingCorpus * pow(1 + $returnRate/100, $years);

        // FV of monthly SIP
        $mr = ($returnRate/100) / 12;
        $fvSIP = $monthlySIP > 0 && $mr > 0
            ? $monthlySIP * (pow(1+$mr, $months)-1) / $mr
            : $monthlySIP * $months;

        $projectedCorpus = $fvExisting + $fvSIP;
        $gap             = max(0, $futureCost - $projectedCorpus);

        // SIP needed to fill gap
        $sipNeeded = 0;
        if ($gap > 0 && $months > 0) {
            $sipNeeded = $mr > 0
                ? $gap * $mr / (pow(1+$mr,$months)-1)
                : $gap / $months;
        }

        // Year-wise projection for chart
        $yearly = [];
        for ($y = 0; $y <= $years; $y++) {
            $fvE = $existingCorpus * pow(1+$returnRate/100,$y);
            $m   = $y*12;
            $fvS = $monthlySIP>0&&$mr>0&&$m>0 ? $monthlySIP*(pow(1+$mr,$m)-1)/$mr : $monthlySIP*$m;
            $yearly[] = ['year'=>$childAge+$y,'corpus'=>round($fvE+$fvS),'target'=>round($futureCost)];
        }

        json_response(true,'ok',[
            'child_name'       => $childName,
            'years'            => $years,
            'current_cost'     => round($currentCost),
            'future_cost'      => round($futureCost),
            'fv_existing'      => round($fvExisting),
            'fv_sip'           => round($fvSIP),
            'projected_corpus' => round($projectedCorpus),
            'gap'              => round($gap),
            'sip_needed'       => round($sipNeeded),
            'on_track'         => $projectedCorpus >= $futureCost,
            'yearly'           => $yearly,
        ]);
        break;
    }

    case 'edu_plans_list': {
        $rows = DB::fetchAll("SELECT * FROM education_plans WHERE user_id=? ORDER BY target_age ASC", [$userId]);
        foreach ($rows as &$r) {
            $r['inputs']  = json_decode($r['inputs']  ?? '{}', true);
            $r['results'] = json_decode($r['results'] ?? '{}', true);
        }
        json_response(true, 'ok', ['plans' => $rows]);
        break;
    }

    case 'edu_plan_save': {
        csrf_verify();
        $childName  = clean($_POST['child_name'] ?? '');
        $targetAge  = (int)($_POST['target_age'] ?? 18);
        $inputs     = $_POST['inputs']  ?? '{}';
        $results    = $_POST['results'] ?? '{}';

        $existing = DB::fetchVal("SELECT id FROM education_plans WHERE user_id=? AND child_name=?", [$userId, $childName]);
        if ($existing) {
            DB::execute("UPDATE education_plans SET target_age=?,inputs=?,results=?,updated_at=NOW() WHERE id=?",
                [$targetAge,$inputs,$results,$existing]);
            json_response(true,'Plan updated.',['id'=>$existing]);
        } else {
            DB::execute("INSERT INTO education_plans(user_id,child_name,target_age,inputs,results,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())",
                [$userId,$childName,$targetAge,$inputs,$results]);
            json_response(true,'Plan saved.',['id'=>DB::lastInsertId()]);
        }
        break;
    }

    case 'edu_plan_delete': {
        csrf_verify();
        $id = (int)($_POST['plan_id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM education_plans WHERE id=? AND user_id=?", [$id,$userId]);
        if (!$own) json_response(false,'Plan not found.');
        DB::execute("DELETE FROM education_plans WHERE id=?",[$id]);
        json_response(true,'Plan deleted.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
