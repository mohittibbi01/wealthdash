<?php
/**
 * WealthDash — t503: Credit Card Optimizer — Points + Interest
 * File: api/budget/credit_card_optimizer.php
 * Actions: cc_list, cc_add, cc_update, cc_delete, cc_optimize_spend,
 *          cc_interest_calculator
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    // ── List user's credit cards ─────────────────────────────────────
    case 'cc_list': {
        $rows = DB::fetchAll("SELECT * FROM credit_cards WHERE user_id=? ORDER BY created_at DESC", [$userId]);
        foreach ($rows as &$r) {
            $r['credit_limit']    = (float)$r['credit_limit'];
            $r['outstanding']     = (float)$r['outstanding'];
            $r['reward_rate']     = (float)$r['reward_rate'];
            $r['interest_rate']   = (float)$r['interest_rate'];
            $r['utilization_pct'] = $r['credit_limit'] > 0 ? round($r['outstanding']/$r['credit_limit']*100, 1) : 0;
        }
        json_response(true,'ok',['cards'=>$rows]);
        break;
    }

    case 'cc_add': {
        csrf_verify();
        $name = clean($_POST['card_name'] ?? '');
        if (!$name) json_response(false, 'Card name required.');
        DB::execute(
            "INSERT INTO credit_cards(user_id,card_name,bank,credit_limit,outstanding,reward_rate,reward_type,interest_rate,due_date,annual_fee,created_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                $userId, $name, clean($_POST['bank'] ?? ''),
                (float)($_POST['credit_limit'] ?? 0), (float)($_POST['outstanding'] ?? 0),
                (float)($_POST['reward_rate'] ?? 1), clean($_POST['reward_type'] ?? 'cashback'),
                (float)($_POST['interest_rate'] ?? 42), (int)($_POST['due_date'] ?? 1),
                (float)($_POST['annual_fee'] ?? 0),
            ]
        );
        json_response(true, 'Card added.', ['id' => DB::lastInsertId()]);
        break;
    }

    case 'cc_update': {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM credit_cards WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Not found.');
        $sets=[]; $params=[];
        foreach (['card_name','bank','credit_limit','outstanding','reward_rate','reward_type','interest_rate','due_date','annual_fee'] as $f) {
            if (isset($_POST[$f])) { $sets[]="$f=?"; $params[]=clean($_POST[$f]); }
        }
        if (!$sets) json_response(false, 'Nothing to update.');
        $params[] = $id;
        DB::execute("UPDATE credit_cards SET ".implode(',',$sets)." WHERE id=?", $params);
        json_response(true,'Updated.');
        break;
    }

    case 'cc_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM credit_cards WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Not found.');
        DB::execute("DELETE FROM credit_cards WHERE id=?", [$id]);
        json_response(true,'Deleted.');
        break;
    }

    // ── Optimizer: given a spend amount + category, which card to use? ──
    case 'cc_optimize_spend': {
        $amount   = (float)($_POST['amount']   ?? 0);
        $category = clean($_POST['category']   ?? 'general');

        $cards = DB::fetchAll("SELECT * FROM credit_cards WHERE user_id=?", [$userId]);
        if (!$cards) json_response(false, 'No credit cards added yet.');

        // Category-specific bonus multipliers (simplified — real cards vary)
        $categoryBonus = [
            'dining'    => ['multiplier' => 2.0, 'keywords' => ['dining','food','restaurant']],
            'travel'    => ['multiplier' => 2.5, 'keywords' => ['travel','flight','hotel']],
            'fuel'      => ['multiplier' => 1.5, 'keywords' => ['fuel','petrol']],
            'online'    => ['multiplier' => 1.8, 'keywords' => ['online','shopping','ecommerce']],
            'general'   => ['multiplier' => 1.0, 'keywords' => []],
        ];

        $results = [];
        foreach ($cards as $c) {
            $baseRate = (float)$c['reward_rate'];
            // Check if card name/type hints at category bonus (simplified heuristic)
            $bonusMult = $categoryBonus[$category]['multiplier'] ?? 1.0;
            $effectiveRate = $baseRate * (str_contains(strtolower($c['card_name'].$c['reward_type']), $category) ? $bonusMult : 1.0);

            $rewardValue = $amount * ($effectiveRate / 100);
            $utilizationAfter = (float)$c['credit_limit'] > 0
                ? round(((float)$c['outstanding'] + $amount) / (float)$c['credit_limit'] * 100, 1)
                : 0;

            $results[] = [
                'card_id'   => (int)$c['id'],
                'card_name' => $c['card_name'],
                'reward_rate' => $effectiveRate,
                'reward_type' => $c['reward_type'],
                'estimated_reward' => round($rewardValue, 2),
                'utilization_after' => $utilizationAfter,
                'warning' => $utilizationAfter > 30 ? 'High utilization may impact credit score' : null,
            ];
        }

        usort($results, fn($a,$b) => $b['estimated_reward'] <=> $a['estimated_reward']);

        json_response(true,'ok',[
            'amount'=>$amount,'category'=>$category,
            'best_card'=>$results[0] ?? null,
            'all_cards'=>$results,
        ]);
        break;
    }

    // ── Interest calculator: cost of carrying balance ──────────────────
    case 'cc_interest_calculator': {
        $outstanding = (float)($_POST['outstanding'] ?? 0);
        $interestRate = (float)($_POST['interest_rate'] ?? 42); // annual %, typical Indian CC rate
        $monthlyPayment = (float)($_POST['monthly_payment'] ?? 0);

        if ($outstanding <= 0) json_response(false, 'Outstanding amount required.');

        $monthlyRate = $interestRate / 12 / 100;
        $minPayment = $outstanding * 0.05; // typical 5% minimum due

        if ($monthlyPayment <= 0) $monthlyPayment = max($minPayment, 1000);

        $schedule = [];
        $balance = $outstanding;
        $totalInterest = 0;
        $months = 0;

        while ($balance > 0 && $months < 120) {
            $interest = $balance * $monthlyRate;
            $principal = min($balance, $monthlyPayment - $interest);
            if ($principal <= 0) break; // payment doesn't cover interest — infinite debt trap
            $balance -= $principal;
            $totalInterest += $interest;
            $months++;
            if ($months <= 24) {
                $schedule[] = ['month'=>$months,'balance'=>round(max(0,$balance),2),'interest_paid'=>round($interest,2)];
            }
        }

        $trapWarning = $monthlyPayment <= ($outstanding * $monthlyRate)
            ? "⚠️ Your payment barely covers interest — you'll never pay off this debt at this rate!"
            : null;

        json_response(true,'ok',[
            'outstanding' => $outstanding,
            'monthly_payment' => $monthlyPayment,
            'min_payment_estimate' => round($minPayment, 2),
            'months_to_payoff' => $months,
            'total_interest_paid' => round($totalInterest, 2),
            'total_paid' => round($outstanding + $totalInterest, 2),
            'schedule' => $schedule,
            'trap_warning' => $trapWarning,
        ]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
