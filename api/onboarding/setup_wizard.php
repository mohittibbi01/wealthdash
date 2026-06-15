<?php
/**
 * WealthDash — t454: Onboarding Flow — New User Guided Setup Wizard
 * File: api/onboarding/setup_wizard.php
 * Actions: setup_wizard_status, setup_wizard_save_step, setup_wizard_complete
 *
 * DIFFERENT from t240 (which is a dashboard checklist of 6 steps with links
 * to other pages). This is a MODAL WIZARD shown on first login that collects
 * essential profile info directly (name confirm, risk profile, first goal,
 * theme preference) in 4 quick steps — completable in under 2 minutes.
 *
 * Reuses user_onboarding table from t240 but adds a separate 'wizard_data' column.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    // ── Check if wizard should be shown ──────────────────────────────
    case 'setup_wizard_status': {
        $row = DB::fetchRow("SELECT wizard_completed, wizard_data FROM user_onboarding WHERE user_id=?", [$userId]);
        $completed = $row ? (bool)$row['wizard_completed'] : false;
        json_response(true,'ok',[
            'show_wizard' => !$completed,
            'data'        => $row ? json_decode($row['wizard_data'] ?? '{}', true) : [],
        ]);
        break;
    }

    // ── Save wizard step data (called after each step) ────────────────
    case 'setup_wizard_save_step': {
        csrf_verify();
        $step = clean($_POST['step'] ?? '');
        $stepData = $_POST['data'] ?? '{}';
        $decoded = json_decode($stepData, true) ?: [];

        $row = DB::fetchRow("SELECT id, wizard_data FROM user_onboarding WHERE user_id=?", [$userId]);
        $existingData = $row ? json_decode($row['wizard_data'] ?? '{}', true) : [];
        $existingData[$step] = $decoded;
        $newJson = json_encode($existingData);

        if ($row) {
            DB::execute("UPDATE user_onboarding SET wizard_data=?,updated_at=NOW() WHERE id=?", [$newJson, $row['id']]);
        } else {
            DB::execute("INSERT INTO user_onboarding(user_id,completed_steps,wizard_data,started_at,updated_at) VALUES(?,'[]',?,NOW(),NOW())", [$userId, $newJson]);
        }

        // Apply specific step effects
        if ($step === 'profile' && !empty($decoded['name'])) {
            DB::execute("UPDATE users SET name=? WHERE id=?", [clean($decoded['name']), $userId]);
        }
        if ($step === 'theme' && !empty($decoded['theme'])) {
            DB::execute("UPDATE users SET theme=? WHERE id=?", [clean($decoded['theme']), $userId]);
        }
        if ($step === 'risk_profile' && !empty($decoded['risk_profile'])) {
            $existing = DB::fetchVal("SELECT id FROM finance_profiles WHERE user_id=?", [$userId]);
            if ($existing) {
                DB::execute("UPDATE finance_profiles SET risk_profile=? WHERE id=?", [clean($decoded['risk_profile']), $existing]);
            } else {
                DB::execute("INSERT INTO finance_profiles(user_id,risk_profile,created_at,updated_at) VALUES(?,?,NOW(),NOW())", [$userId, clean($decoded['risk_profile'])]);
            }
        }
        if ($step === 'first_goal' && !empty($decoded['goal_name']) && !empty($decoded['target_amount'])) {
            DB::execute(
                "INSERT INTO goals(user_id,goal_name,target_amount,target_date,status,created_at) VALUES(?,?,?,?,'active',NOW())",
                [$userId, clean($decoded['goal_name']), (float)$decoded['target_amount'], clean($decoded['target_date'] ?? date('Y-m-d', strtotime('+5 years')))]
            );
        }

        json_response(true,'Step saved.');
        break;
    }

    // ── Mark wizard complete ───────────────────────────────────────────
    case 'setup_wizard_complete': {
        csrf_verify();
        $row = DB::fetchVal("SELECT id FROM user_onboarding WHERE user_id=?", [$userId]);
        if ($row) {
            DB::execute("UPDATE user_onboarding SET wizard_completed=1, updated_at=NOW() WHERE id=?", [$row]);
        } else {
            DB::execute("INSERT INTO user_onboarding(user_id,completed_steps,wizard_completed,started_at,updated_at) VALUES(?,'[]',1,NOW(),NOW())", [$userId]);
        }
        audit_log($userId, 'onboarding_wizard_complete', 'New user setup wizard completed');
        json_response(true,'Welcome to WealthDash! 🎉');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
