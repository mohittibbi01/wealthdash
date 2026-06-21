<?php
/**
 * WealthDash — tg003: Retirement Corpus Calculator
 * File: api/goal/retirement_calculator.php
 * Actions: retirement_calculate, retirement_save_plan, retirement_load_plan, retirement_delete_plan
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    // ── CALCULATE: pure math, no DB write ────────────────────────────
    case 'retirement_calculate': {
        $currentAge      = (int)($_POST['current_age']       ?? 0);
        $retirementAge   = (int)($_POST['retirement_age']    ?? 60);
        $lifeExpectancy  = (int)($_POST['life_expectancy']   ?? 85);
        $monthlyExpenses = (float)($_POST['monthly_expenses'] ?? 0);
        $inflation       = (float)($_POST['inflation_rate']   ?? 6.0);   // %
        $preReturnRate   = (float)($_POST['pre_return_rate']  ?? 12.0);  // % CAGR pre-retirement
        $postReturnRate  = (float)($_POST['post_return_rate'] ?? 7.0);   // % post-retirement
        $existingCorpus  = (float)($_POST['existing_corpus']  ?? 0);
        $monthlySIP      = (float)($_POST['monthly_sip']      ?? 0);

        if ($currentAge < 18 || $retirementAge <= $currentAge || $lifeExpectancy <= $retirementAge) {
            json_response(false, 'Invalid age inputs.');
        }
        if ($monthlyExpenses <= 0) json_response(false, 'Monthly expenses required.');

        $yearsToRetirement  = $retirementAge - $currentAge;
        $retirementDuration = $lifeExpectancy - $retirementAge;

        // 1. Inflation-adjusted monthly expenses at retirement
        $inflationFactor    = pow(1 + $inflation/100, $yearsToRetirement);
        $monthlyAtRetirement = $monthlyExpenses * $inflationFactor;
        $annualAtRetirement  = $monthlyAtRetirement * 12;

        // 2. Required corpus at retirement (Present Value of annuity, real rate)
        // Real return post retirement
        $realPostReturn = (($postReturnRate / 100) - ($inflation / 100)) / (1 + $inflation / 100);
        if (abs($realPostReturn) < 0.0001) {
            // Zero real rate — simple multiplication
            $requiredCorpus = $annualAtRetirement * $retirementDuration;
        } else {
            // PV of growing annuity / ordinary annuity at real rate
            $requiredCorpus = $annualAtRetirement
                * (1 - pow(1 + $realPostReturn, -$retirementDuration))
                / $realPostReturn;
        }

        // 3. Future value of existing corpus
        $fvExisting = $existingCorpus * pow(1 + $preReturnRate/100, $yearsToRetirement);

        // 4. Gap after existing corpus
        $corpusGap = max(0, $requiredCorpus - $fvExisting);

        // 5. Monthly SIP needed to fill gap
        $monthlyRate  = ($preReturnRate/100) / 12;
        $totalMonths  = $yearsToRetirement * 12;
        $sipNeeded    = 0;
        if ($corpusGap > 0 && $monthlyRate > 0 && $totalMonths > 0) {
            $sipNeeded = $corpusGap * $monthlyRate
                       / (pow(1 + $monthlyRate, $totalMonths) - 1);
        } elseif ($corpusGap > 0 && $totalMonths > 0) {
            $sipNeeded = $corpusGap / $totalMonths;
        }

        // 6. FV of current monthly SIP (if provided)
        $fvSIP = 0;
        if ($monthlySIP > 0 && $monthlyRate > 0) {
            $fvSIP = $monthlySIP * (pow(1 + $monthlyRate, $totalMonths) - 1) / $monthlyRate;
        } elseif ($monthlySIP > 0) {
            $fvSIP = $monthlySIP * $totalMonths;
        }

        $projectedCorpus  = $fvExisting + $fvSIP;
        $surplusDeficit   = $projectedCorpus - $requiredCorpus;
        $onTrack          = $surplusDeficit >= 0;

        // 7. Year-wise wealth accumulation for chart
        $yearlyData = [];
        for ($y = 0; $y <= $yearsToRetirement; $y++) {
            $fvE = $existingCorpus * pow(1 + $preReturnRate/100, $y);
            $fvS = 0;
            $months = $y * 12;
            if ($monthlySIP > 0 && $monthlyRate > 0 && $months > 0) {
                $fvS = $monthlySIP * (pow(1 + $monthlyRate, $months) - 1) / $monthlyRate;
            } elseif ($monthlySIP > 0) {
                $fvS = $monthlySIP * $months;
            }
            $yearlyData[] = [
                'year'      => $currentAge + $y,
                'corpus'    => round($fvE + $fvS),
                'required'  => round($requiredCorpus),
            ];
        }

        json_response(true, 'ok', [
            'years_to_retirement'   => $yearsToRetirement,
            'retirement_duration'   => $retirementDuration,
            'monthly_at_retirement' => round($monthlyAtRetirement),
            'required_corpus'       => round($requiredCorpus),
            'fv_existing'           => round($fvExisting),
            'fv_sip'                => round($fvSIP),
            'projected_corpus'      => round($projectedCorpus),
            'corpus_gap'            => round(max(0, $requiredCorpus - $projectedCorpus)),
            'surplus_deficit'       => round($surplusDeficit),
            'sip_needed'            => round($sipNeeded),
            'on_track'              => $onTrack,
            'yearly_data'           => $yearlyData,
            'inflation_factor'      => round($inflationFactor, 2),
        ]);
        break;
    }

    // ── SAVE PLAN ─────────────────────────────────────────────────────
    case 'retirement_save_plan': {
        csrf_verify();
        $planName = clean($_POST['plan_name'] ?? 'My Retirement Plan');
        $inputs   = $_POST['inputs']   ?? '';
        $results  = $_POST['results']  ?? '';

        if (!$inputs || !$results) json_response(false, 'inputs and results required.');

        // Validate JSON
        json_decode($inputs);  if (json_last_error()) json_response(false, 'Invalid inputs JSON.');
        json_decode($results); if (json_last_error()) json_response(false, 'Invalid results JSON.');

        // Check if plan exists for this user (upsert by name)
        $existingId = DB::fetchVal(
            "SELECT id FROM retirement_plans WHERE user_id=? AND plan_name=?",
            [$userId, $planName]
        );
        if ($existingId) {
            DB::execute(
                "UPDATE retirement_plans SET inputs=?, results=?, updated_at=NOW() WHERE id=?",
                [$inputs, $results, $existingId]
            );
            json_response(true, 'Plan updated.', ['id' => $existingId]);
        } else {
            DB::execute(
                "INSERT INTO retirement_plans (user_id, plan_name, inputs, results, created_at, updated_at)
                 VALUES (?,?,?,?,NOW(),NOW())",
                [$userId, $planName, $inputs, $results]
            );
            json_response(true, 'Plan saved.', ['id' => DB::lastInsertId()]);
        }
        break;
    }

    // ── LOAD PLANS LIST ───────────────────────────────────────────────
    case 'retirement_load_plan': {
        $planId = (int)($_GET['plan_id'] ?? $_POST['plan_id'] ?? 0);
        if ($planId) {
            $row = DB::fetchRow(
                "SELECT * FROM retirement_plans WHERE id=? AND user_id=?",
                [$planId, $userId]
            );
            if (!$row) json_response(false, 'Plan not found.');
            $row['inputs']  = json_decode($row['inputs'],  true);
            $row['results'] = json_decode($row['results'], true);
            json_response(true, 'ok', ['plan' => $row]);
        } else {
            $rows = DB::fetchAll(
                "SELECT id, plan_name, created_at, updated_at FROM retirement_plans
                 WHERE user_id=? ORDER BY updated_at DESC",
                [$userId]
            );
            json_response(true, 'ok', ['plans' => $rows]);
        }
        break;
    }

    // ── DELETE PLAN ───────────────────────────────────────────────────
    case 'retirement_delete_plan': {
        csrf_verify();
        $planId = (int)($_POST['plan_id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM retirement_plans WHERE id=? AND user_id=?", [$planId, $userId]);
        if (!$own) json_response(false, 'Plan not found.');
        DB::execute("DELETE FROM retirement_plans WHERE id=?", [$planId]);
        json_response(true, 'Plan deleted.');
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}
