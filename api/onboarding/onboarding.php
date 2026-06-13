<?php
/**
 * WealthDash — t240: Onboarding Flow
 * File: api/onboarding/onboarding.php
 * Actions: onboarding_status, onboarding_complete_step, onboarding_skip
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

// Onboarding steps definition
function _steps(): array {
    return [
        'profile'       => ['order'=>1, 'title'=>'Complete Your Profile',       'desc'=>'Add your name, age, and tax details.',      'page'=>'finance_profile',    'icon'=>'👤'],
        'first_holding' => ['order'=>2, 'title'=>'Add Your First Investment',    'desc'=>'Add a mutual fund or FD to get started.',   'page'=>'mf_holdings',        'icon'=>'📈'],
        'first_goal'    => ['order'=>3, 'title'=>'Set a Financial Goal',         'desc'=>'Retirement, education, or any goal.',       'page'=>'goals_tracker',      'icon'=>'🎯'],
        'insurance'     => ['order'=>4, 'title'=>'Add an Insurance Policy',      'desc'=>'Track your life or health insurance.',      'page'=>'insurance',          'icon'=>'🛡'],
        'first_sip'     => ['order'=>5, 'title'=>'Set Up a SIP',                 'desc'=>'Automate your monthly investment.',         'page'=>'mf_holdings',        'icon'=>'🔁'],
        'explore_ai'    => ['order'=>6, 'title'=>'Try AI Insights',              'desc'=>'Get fund recommendations or portfolio AI.', 'page'=>'ai_fund_recommend',  'icon'=>'🤖'],
    ];
}

switch ($action) {

    case 'onboarding_status': {
        $row = DB::fetchRow("SELECT completed_steps, skipped, started_at FROM user_onboarding WHERE user_id=?", [$userId]);
        $completedSteps = $row ? json_decode($row['completed_steps'] ?? '[]', true) : [];
        $skipped        = $row ? (bool)$row['skipped'] : false;

        $steps = _steps();
        foreach ($steps as $key => &$step) {
            $step['key']       = $key;
            $step['completed'] = in_array($key, $completedSteps);
        }
        uasort($steps, fn($a,$b) => $a['order'] - $b['order']);

        $totalSteps     = count($steps);
        $completedCount = count($completedSteps);
        $pct            = round($completedCount / $totalSteps * 100);

        json_response(true, 'ok', [
            'steps'           => array_values($steps),
            'completed_count' => $completedCount,
            'total'           => $totalSteps,
            'pct'             => $pct,
            'done'            => $completedCount >= $totalSteps,
            'skipped'         => $skipped,
        ]);
        break;
    }

    case 'onboarding_complete_step': {
        $step = clean($_POST['step'] ?? '');
        if (!isset(_steps()[$step])) json_response(false, 'Unknown step.');

        $row = DB::fetchRow("SELECT id, completed_steps FROM user_onboarding WHERE user_id=?", [$userId]);
        if ($row) {
            $steps = json_decode($row['completed_steps'] ?? '[]', true);
            if (!in_array($step, $steps)) {
                $steps[] = $step;
                DB::execute("UPDATE user_onboarding SET completed_steps=?,updated_at=NOW() WHERE id=?",
                    [json_encode($steps), $row['id']]);
            }
        } else {
            DB::execute("INSERT INTO user_onboarding(user_id,completed_steps,started_at,updated_at) VALUES(?,?,NOW(),NOW())",
                [$userId, json_encode([$step])]);
        }
        json_response(true, 'Step completed.');
        break;
    }

    case 'onboarding_skip': {
        $row = DB::fetchRow("SELECT id FROM user_onboarding WHERE user_id=?", [$userId]);
        if ($row) {
            DB::execute("UPDATE user_onboarding SET skipped=1,updated_at=NOW() WHERE id=?", [$row['id']]);
        } else {
            DB::execute("INSERT INTO user_onboarding(user_id,completed_steps,skipped,started_at,updated_at) VALUES(?,?,1,NOW(),NOW())",
                [$userId, '[]']);
        }
        json_response(true, 'Onboarding skipped.');
        break;
    }

    default: json_response(false, 'Unknown action.', [], 400);
}
