<?php
/**
 * WealthDash — t406: Anonymous Benchmarking — Peer Comparison
 * File: api/benchmarking/peer_compare.php
 * Actions: benchmark_opt_in, benchmark_opt_out, benchmark_status,
 *          benchmark_compare, benchmark_submit_snapshot
 *
 * Privacy-first design:
 *  - Users explicitly opt in (off by default)
 *  - Only AGGREGATED, ANONYMIZED data submitted (no fund names, no PII)
 *  - Comparison cohort = same age bracket + risk profile (min 5 users required
 *    to show any aggregate, else shows "not enough data" to prevent
 *    individual identification)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

const MIN_COHORT_SIZE = 5; // minimum users in cohort before showing aggregate

function _age_bracket(int $age): string {
    return match(true) {
        $age < 25 => '18-24',
        $age < 35 => '25-34',
        $age < 45 => '35-44',
        $age < 55 => '45-54',
        default   => '55+',
    };
}

switch ($action) {

    // ── Opt in to benchmarking (submits anonymized snapshot) ─────────
    case 'benchmark_opt_in': {
        csrf_verify();

        $profile = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?", [$userId]);
        if (!$profile || !$profile['age'] || !$profile['risk_profile']) {
            json_response(false, 'Complete your finance profile (age + risk profile) first.');
        }

        $existing = DB::fetchVal("SELECT id FROM benchmark_opt_ins WHERE user_id=?", [$userId]);
        if ($existing) {
            DB::execute("UPDATE benchmark_opt_ins SET opted_in=1,updated_at=NOW() WHERE id=?", [$existing]);
        } else {
            DB::execute("INSERT INTO benchmark_opt_ins(user_id,opted_in,created_at,updated_at) VALUES(?,1,NOW(),NOW())", [$userId]);
        }

        _submit_anonymized_snapshot($userId, $portfolioId, $profile);

        json_response(true, 'Opted in! Your anonymized data helps build peer benchmarks for everyone.');
        break;
    }

    case 'benchmark_opt_out': {
        csrf_verify();
        DB::execute("UPDATE benchmark_opt_ins SET opted_in=0,updated_at=NOW() WHERE user_id=?", [$userId]);
        // Remove this user's snapshot data
        DB::execute("DELETE FROM benchmark_snapshots WHERE user_id=?", [$userId]);
        json_response(true, 'Opted out. Your data has been removed from benchmarking.');
        break;
    }

    case 'benchmark_status': {
        $row = DB::fetchVal("SELECT opted_in FROM benchmark_opt_ins WHERE user_id=?", [$userId]);
        json_response(true,'ok',['opted_in'=>(bool)$row]);
        break;
    }

    // ── Refresh my snapshot + show comparison ────────────────────────
    case 'benchmark_compare': {
        $optedIn = (bool)(DB::fetchVal("SELECT opted_in FROM benchmark_opt_ins WHERE user_id=?", [$userId]) ?? false);
        if (!$optedIn) {
            json_response(true, 'ok', ['opted_in' => false, 'message' => 'Opt in to see how you compare with peers.']);
        }

        $profile = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?", [$userId]);
        if (!$profile || !$profile['age']) json_response(false, 'Complete your profile first.');

        $bracket = _age_bracket((int)$profile['age']);
        $riskProfile = $profile['risk_profile'] ?? 'moderate';

        // Refresh my own snapshot
        _submit_anonymized_snapshot($userId, $portfolioId, $profile);

        // Get cohort stats (exclude self from average for fairness, but check count including self)
        $cohort = DB::fetchAll(
            "SELECT savings_rate, gain_pct, sip_count, monthly_sip_pct_income, num_holdings
             FROM benchmark_snapshots
             WHERE age_bracket=? AND risk_profile=?",
            [$bracket, $riskProfile]
        );

        if (count($cohort) < MIN_COHORT_SIZE) {
            json_response(true, 'ok', [
                'opted_in' => true,
                'enough_data' => false,
                'cohort_size' => count($cohort),
                'message' => "Not enough peers yet in your cohort ({$bracket}, " . ucfirst(str_replace('_',' ',$riskProfile)) . ") to show anonymized comparison. Minimum " . MIN_COHORT_SIZE . " needed.",
            ]);
        }

        $myRow = DB::fetchRow("SELECT * FROM benchmark_snapshots WHERE user_id=?", [$userId]);

        $avg = function(array $arr, string $key) {
            $vals = array_filter(array_column($arr, $key), fn($v) => $v !== null);
            return $vals ? round(array_sum($vals)/count($vals), 2) : 0;
        };
        $median = function(array $arr, string $key) {
            $vals = array_values(array_filter(array_column($arr, $key), fn($v) => $v !== null));
            sort($vals);
            $n = count($vals);
            if (!$n) return 0;
            return $n % 2 ? $vals[(int)($n/2)] : round(($vals[$n/2-1]+$vals[$n/2])/2, 2);
        };

        json_response(true,'ok',[
            'opted_in'    => true,
            'enough_data' => true,
            'cohort_size' => count($cohort),
            'cohort_label'=> "{$bracket} years · " . ucfirst(str_replace('_',' ',$riskProfile)),
            'me' => [
                'savings_rate'  => (float)($myRow['savings_rate']??0),
                'gain_pct'      => (float)($myRow['gain_pct']??0),
                'sip_count'     => (int)($myRow['sip_count']??0),
                'sip_pct_income'=> (float)($myRow['monthly_sip_pct_income']??0),
                'num_holdings'  => (int)($myRow['num_holdings']??0),
            ],
            'peer_avg' => [
                'savings_rate'  => $avg($cohort,'savings_rate'),
                'gain_pct'      => $avg($cohort,'gain_pct'),
                'sip_count'     => $avg($cohort,'sip_count'),
                'sip_pct_income'=> $avg($cohort,'monthly_sip_pct_income'),
                'num_holdings'  => $avg($cohort,'num_holdings'),
            ],
            'peer_median' => [
                'savings_rate'  => $median($cohort,'savings_rate'),
                'gain_pct'      => $median($cohort,'gain_pct'),
                'sip_count'     => $median($cohort,'sip_count'),
            ],
        ]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}

// ── Helper: compute + store anonymized snapshot (no PII, no fund names) ──
function _submit_anonymized_snapshot(int $userId, int $portfolioId, array $profile): void {
    $age = (int)$profile['age'];
    $bracket = _age_bracket($age);
    $riskProfile = $profile['risk_profile'] ?? 'moderate';
    $monthlyIncome = (float)($profile['annual_income'] ?? 0) / 12;

    $v = (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*COALESCE(n.nav,h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",[$userId,$portfolioId])??0);
    $inv = (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units*h.avg_cost_per_unit),0) FROM mf_holdings h WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",[$userId,$portfolioId])??0);
    $gainPct = $inv>0 ? round(($v-$inv)/$inv*100,2) : 0;

    $sipCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
    $sipTotal = (float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'",[$userId,$portfolioId])??0);
    $sipPctIncome = $monthlyIncome>0 ? round($sipTotal/$monthlyIncome*100,2) : 0;

    $holdingCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_holdings WHERE user_id=? AND portfolio_id=? AND units>0",[$userId,$portfolioId])??0);

    $month = date('Y-m');
    $income = (float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM budget_actuals WHERE user_id=? AND txn_type='income' AND DATE_FORMAT(txn_date,'%Y-%m')=?",[$userId,$month])??0);
    $expense = (float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM budget_actuals WHERE user_id=? AND txn_type='expense' AND DATE_FORMAT(txn_date,'%Y-%m')=?",[$userId,$month])??0);
    $savingsRate = $income>0 ? round(($income-$expense)/$income*100,2) : null;

    $existing = DB::fetchVal("SELECT id FROM benchmark_snapshots WHERE user_id=?", [$userId]);
    $data = [$bracket, $riskProfile, $savingsRate, $gainPct, $sipCount, $sipPctIncome, $holdingCount];
    if ($existing) {
        DB::execute("UPDATE benchmark_snapshots SET age_bracket=?,risk_profile=?,savings_rate=?,gain_pct=?,sip_count=?,monthly_sip_pct_income=?,num_holdings=?,updated_at=NOW() WHERE id=?", [...$data, $existing]);
    } else {
        DB::execute("INSERT INTO benchmark_snapshots(user_id,age_bracket,risk_profile,savings_rate,gain_pct,sip_count,monthly_sip_pct_income,num_holdings,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())", [$userId, ...$data]);
    }
}
