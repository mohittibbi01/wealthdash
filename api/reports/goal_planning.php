<?php
/**
 * WealthDash — Goal Planning API
 * Actions: goal_list | goal_add | goal_edit | goal_delete | goal_projection | goal_contribute
 */
declare(strict_types=1);

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$userId      = (int) $_SESSION['user_id'];
$isAdmin     = is_admin();
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── List Goals ────────────────────────────────────────────
    case 'goal_list':
        $goals = DB::fetchAll(
            "SELECT g.*,
                    COALESCE((SELECT SUM(amount) FROM goal_contributions WHERE goal_id=g.id),0)
                        AS contributions_sum
             FROM investment_goals g
             WHERE g.portfolio_id = ?
             ORDER BY g.is_achieved ASC, g.priority DESC, g.target_date ASC",
            [$portfolioId]
        );

        foreach ($goals as &$goal) {
            $goal = _enrich_goal($goal);
        }
        unset($goal);

        // Summary
        $totalTargets  = array_sum(array_column($goals, 'target_amount'));
        $totalSaved    = array_sum(array_column($goals, 'effective_saved'));
        $achieved      = count(array_filter($goals, fn($g) => $g['is_achieved']));

        json_response(true, '', [
            'goals'         => $goals,
            'total_targets' => round($totalTargets, 2),
            'total_saved'   => round($totalSaved, 2),
            'achieved_count'=> $achieved,
        ]);

    // ── Goal Projection Calculator (standalone) ───────────────
    case 'goal_projection':
        $targetAmount  = (float) ($_POST['target_amount']   ?? $_GET['target_amount']   ?? 0);
        $returnPct     = (float) ($_POST['return_pct']      ?? $_GET['return_pct']      ?? 12);
        $months        = (int)   ($_POST['months']          ?? $_GET['months']          ?? 120);
        $currentSaved  = (float) ($_POST['current_saved']   ?? $_GET['current_saved']   ?? 0);

        if ($targetAmount <= 0 || $months <= 0) {
            json_response(false, 'target_amount and months are required.');
        }

        $result = _calculate_projection($targetAmount, $returnPct, $months, $currentSaved);
        json_response(true, '', $result);

    // ── Add Goal ──────────────────────────────────────────────
    case 'goal_add':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $name        = clean($_POST['name']        ?? '');
        $desc        = clean($_POST['description'] ?? '');
        $target      = (float) ($_POST['target_amount'] ?? 0);
        $targetDate  = date_to_db(clean($_POST['target_date'] ?? ''));
        $returnPct   = (float) ($_POST['expected_return_pct'] ?? 12);
        $priority    = in_array($_POST['priority'] ?? '', ['high','medium','low'])
                       ? $_POST['priority'] : 'medium';
        $color       = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '')
                       ? $_POST['color'] : '#2563EB';
        $icon        = clean($_POST['icon'] ?? 'target');

        if (!$name || $target <= 0 || !$targetDate) {
            json_response(false, 'Name, target amount, and target date are required.');
        }

        // Auto-calculate monthly SIP needed
        $monthsLeft = _months_to_date($targetDate);
        $monthlySip = $monthsLeft > 0
            ? _sip_needed($target, $returnPct, $monthsLeft, 0)
            : null;

        DB::run(
            'INSERT INTO investment_goals
             (portfolio_id, name, description, target_amount, target_date,
              expected_return_pct, priority, color, icon, monthly_sip_needed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$portfolioId, $name, $desc ?: null, $target, $targetDate,
             $returnPct, $priority, $color, $icon, $monthlySip]
        );
        $id = (int) DB::lastInsertId();
        audit_log('goal_add', 'investment_goals', $id);
        json_response(true, 'Goal created.', ['id' => $id]);

    // ── Edit Goal ─────────────────────────────────────────────
    case 'goal_edit':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId     = (int) ($_POST['goal_id'] ?? 0);
        $name       = clean($_POST['name']     ?? '');
        $target     = (float) ($_POST['target_amount']      ?? 0);
        $targetDate = date_to_db(clean($_POST['target_date'] ?? ''));
        $returnPct  = (float) ($_POST['expected_return_pct'] ?? 12);
        $priority   = in_array($_POST['priority'] ?? '', ['high','medium','low'])
                      ? $_POST['priority'] : 'medium';
        $color      = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '')
                      ? $_POST['color'] : '#2563EB';

        $goal = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        $monthsLeft = _months_to_date($targetDate);
        $currentSaved = (float) DB::fetchVal(
            'SELECT COALESCE(SUM(amount),0) FROM goal_contributions WHERE goal_id=?',
            [$goalId]
        );
        $monthlySip = $monthsLeft > 0
            ? _sip_needed($target, $returnPct, $monthsLeft, $currentSaved)
            : null;

        DB::run(
            'UPDATE investment_goals
             SET name=?, target_amount=?, target_date=?, expected_return_pct=?,
                 priority=?, color=?, monthly_sip_needed=?
             WHERE id=?',
            [$name, $target, $targetDate, $returnPct, $priority, $color, $monthlySip, $goalId]
        );
        audit_log('goal_edit', 'investment_goals', $goalId);
        json_response(true, 'Goal updated.');

    // ── Delete Goal ───────────────────────────────────────────
    case 'goal_delete':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $goal   = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run('DELETE FROM investment_goals WHERE id = ?', [$goalId]);
        audit_log('goal_delete', 'investment_goals', $goalId);
        json_response(true, 'Goal deleted.');

    // ── Mark Goal Achieved ────────────────────────────────────
    case 'goal_mark_achieved':
        csrf_verify();
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $goal   = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run(
            'UPDATE investment_goals SET is_achieved=1, achieved_at=CURDATE() WHERE id=?',
            [$goalId]
        );
        audit_log('goal_mark_achieved', 'investment_goals', $goalId);
        json_response(true, '🎉 Goal marked as achieved!');

    // ── Add Contribution ──────────────────────────────────────
    case 'goal_contribute':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId  = (int)   ($_POST['goal_id'] ?? 0);
        $amount  = (float) ($_POST['amount']  ?? 0);
        $date    = date_to_db(clean($_POST['date'] ?? date('d-m-Y')));
        $note    = clean($_POST['note'] ?? '');

        if (!$goalId || $amount <= 0) json_response(false, 'Goal ID and amount required.');

        $goal = DB::fetchOne(
            'SELECT id, target_amount FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run(
            'INSERT INTO goal_contributions (goal_id, amount, contribution_date, note)
             VALUES (?, ?, ?, ?)',
            [$goalId, $amount, $date, $note ?: null]
        );

        // Check if goal achieved now
        $totalSaved = (float) DB::fetchVal(
            'SELECT COALESCE(SUM(amount),0) FROM goal_contributions WHERE goal_id=?',
            [$goalId]
        );
        if ($totalSaved >= (float) $goal['target_amount']) {
            DB::run(
                'UPDATE investment_goals SET is_achieved=1, achieved_at=CURDATE() WHERE id=? AND is_achieved=0',
                [$goalId]
            );
        }

        json_response(true, 'Contribution recorded.', ['total_saved' => round($totalSaved, 2)]);

    default:
        json_response(false, 'Unknown goal action.', [], 400);
}

// ── Helpers ──────────────────────────────────────────────────

function _enrich_goal(array $goal): array {
    $effectiveSaved = max(
        (float) $goal['current_saved'],
        (float) $goal['contributions_sum']
    );
    $goal['effective_saved'] = $effectiveSaved;

    $target    = (float) $goal['target_amount'];
    $pct       = $target > 0 ? min(round(($effectiveSaved / $target) * 100, 1), 100) : 0;
    $remaining = max($target - $effectiveSaved, 0);

    $monthsLeft = _months_to_date($goal['target_date']);
    $projection = _calculate_projection($target, (float)$goal['expected_return_pct'], $monthsLeft, $effectiveSaved);

    $goal['progress_pct']    = $pct;
    $goal['amount_remaining']= round($remaining, 2);
    $goal['months_left']     = $monthsLeft;
    $goal['monthly_sip_needed'] = $projection['sip_needed'];
    $goal['on_track']        = $projection['on_track'];
    $goal['projected_value'] = $projection['projected_value'];
    return $goal;
}

function _months_to_date(string $targetDate): int {
    $today  = new DateTime();
    $target = new DateTime($targetDate);
    if ($target <= $today) return 0;
    $diff = $today->diff($target);
    return $diff->y * 12 + $diff->m;
}

/**
 * Calculate SIP needed to reach targetAmount in N months
 * given current savings and expected annual return.
 */
function _sip_needed(float $target, float $annualReturnPct, int $months, float $currentSaved): float {
    if ($months <= 0) return 0.0;
    $r = ($annualReturnPct / 100) / 12; // monthly rate

    // Future value of current savings
    $fvCurrent = $currentSaved * pow(1 + $r, $months);
    $remaining = $target - $fvCurrent;
    if ($remaining <= 0) return 0.0;

    if ($r == 0) return round($remaining / $months, 2);

    // SIP formula: SIP = FV * r / ((1+r)^n - 1)
    $sip = $remaining * $r / (pow(1 + $r, $months) - 1);
    return round($sip, 2);
}

function _calculate_projection(float $target, float $annualReturnPct, int $months, float $currentSaved): array {
    if ($months <= 0) {
        return [
            'sip_needed'       => 0,
            'projected_value'  => round($currentSaved, 2),
            'on_track'         => $currentSaved >= $target,
            'months'           => 0,
        ];
    }

    $r   = ($annualReturnPct / 100) / 12;
    $sipNeeded = _sip_needed($target, $annualReturnPct, $months, $currentSaved);

    // Project monthly chart data (12 points)
    $chartPoints = [];
    $step = max(1, (int) ceil($months / 12));
    $running = $currentSaved;
    for ($m = 0; $m <= $months; $m += $step) {
        $fv = $currentSaved * pow(1 + $r, $m);
        if ($sipNeeded > 0 && $r > 0) {
            $fv += $sipNeeded * (pow(1 + $r, $m) - 1) / $r;
        } elseif ($sipNeeded > 0) {
            $fv += $sipNeeded * $m;
        }
        $chartPoints[] = [
            'month'     => $m,
            'projected' => round($fv, 2),
            'target'    => round($target, 2),
        ];
    }

    // Final projected value with suggested SIP
    $projectedFinal = $currentSaved * pow(1 + $r, $months);
    if ($sipNeeded > 0 && $r > 0) {
        $projectedFinal += $sipNeeded * (pow(1 + $r, $months) - 1) / $r;
    } elseif ($sipNeeded > 0) {
        $projectedFinal += $sipNeeded * $months;
    }

    return [
        'sip_needed'      => $sipNeeded,
        'projected_value' => round($projectedFinal, 2),
        'on_track'        => $projectedFinal >= $target,
        'months'          => $months,
        'chart'           => $chartPoints,
        'return_pct'      => $annualReturnPct,
    ];
}

