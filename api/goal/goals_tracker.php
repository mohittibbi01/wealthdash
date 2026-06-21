<?php
/**
 * WealthDash — tg005: Goals vs Actual Tracking
 * File: api/goal/goals_tracker.php
 * Actions: goals_vs_actual, goal_monthly_checkin, goal_checkin_history,
 *          goal_checkin_save, goal_projection
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    // ── GOALS VS ACTUAL: main dashboard data ─────────────────────────
    case 'goals_vs_actual': {
        $goals = DB::fetchAll(
            "SELECT g.*,
                    COALESCE(SUM(ga.amount),0) AS actual_invested,
                    MAX(ga.checkin_date)        AS last_checkin
             FROM goals g
             LEFT JOIN goal_checkins ga ON ga.goal_id = g.id
             WHERE g.user_id = ? AND g.status = 'active'
             GROUP BY g.id
             ORDER BY g.target_date ASC",
            [$userId]
        );

        $today = date('Y-m-d');
        $result = [];
        foreach ($goals as $g) {
            $target   = (float)$g['target_amount'];
            $invested = (float)$g['actual_invested'];
            $startDate  = $g['start_date']  ?? $g['created_at'];
            $targetDate = $g['target_date'];

            // Time progress
            $totalDays   = max(1, (strtotime($targetDate) - strtotime($startDate)) / 86400);
            $elapsedDays = max(0, (strtotime($today) - strtotime($startDate)) / 86400);
            $timeProgress = min(100, round(($elapsedDays / $totalDays) * 100, 1));

            // Amount progress
            $amtProgress = $target > 0 ? min(100, round(($invested / $target) * 100, 1)) : 0;

            // Expected invested by now (linear)
            $expectedByNow = $target * ($elapsedDays / $totalDays);

            // On-track status
            $variance = $invested - $expectedByNow;
            $status = match(true) {
                $amtProgress >= 100       => 'completed',
                $variance >= 0            => 'on_track',
                abs($variance) <= ($expectedByNow * 0.1) => 'slightly_behind',
                default                   => 'behind',
            };

            // Monthly target SIP needed from today
            $monthsLeft = max(1, (strtotime($targetDate) - strtotime($today)) / (86400 * 30.44));
            $remaining  = max(0, $target - $invested);
            $monthlyNeeded = $remaining / $monthsLeft;

            // Current monthly SIP linked to this goal
            $currentSIP = (float)(DB::fetchVal(
                "SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips
                 WHERE user_id=? AND goal_id=? AND status='active'",
                [$userId, $g['id']]
            ) ?? 0);

            $result[] = [
                'id'              => (int)$g['id'],
                'goal_name'       => $g['goal_name'],
                'goal_type'       => $g['goal_type']   ?? 'general',
                'target_amount'   => $target,
                'actual_invested' => $invested,
                'expected_by_now' => round($expectedByNow),
                'variance'        => round($variance),
                'time_progress'   => $timeProgress,
                'amt_progress'    => $amtProgress,
                'months_left'     => round($monthsLeft),
                'monthly_needed'  => round($monthlyNeeded),
                'current_sip'     => $currentSIP,
                'sip_gap'         => round(max(0, $monthlyNeeded - $currentSIP)),
                'target_date'     => $targetDate,
                'start_date'      => $startDate,
                'status'          => $status,
                'last_checkin'    => $g['last_checkin'],
                'priority'        => $g['priority'] ?? 'medium',
                'notes'           => $g['notes']    ?? '',
            ];
        }

        // Summary counts
        $summary = [
            'total'           => count($result),
            'on_track'        => count(array_filter($result, fn($g) => $g['status'] === 'on_track')),
            'behind'          => count(array_filter($result, fn($g) => in_array($g['status'], ['behind','slightly_behind']))),
            'completed'       => count(array_filter($result, fn($g) => $g['status'] === 'completed')),
            'total_target'    => array_sum(array_column($result, 'target_amount')),
            'total_invested'  => array_sum(array_column($result, 'actual_invested')),
        ];

        json_response(true, 'ok', ['goals' => $result, 'summary' => $summary]);
        break;
    }

    // ── MONTHLY CHECKIN: log actual investment for a goal this month ──
    case 'goal_checkin_save': {
        csrf_verify();
        $goalId      = (int)($_POST['goal_id']     ?? 0);
        $amount      = (float)($_POST['amount']    ?? 0);
        $checkinDate = clean($_POST['checkin_date'] ?? date('Y-m-d'));
        $notes       = clean($_POST['notes']        ?? '');

        if (!$goalId || $amount <= 0) {
            json_response(false, 'goal_id and amount required.');
        }
        $own = DB::fetchVal("SELECT id FROM goals WHERE id=? AND user_id=?", [$goalId, $userId]);
        if (!$own) json_response(false, 'Goal not found.');

        $month = substr($checkinDate, 0, 7); // YYYY-MM

        // Upsert: one checkin per goal per month
        $existing = DB::fetchVal(
            "SELECT id FROM goal_checkins WHERE goal_id=? AND DATE_FORMAT(checkin_date,'%Y-%m')=?",
            [$goalId, $month]
        );
        if ($existing) {
            DB::execute(
                "UPDATE goal_checkins SET amount=?, notes=?, checkin_date=?, updated_at=NOW() WHERE id=?",
                [$amount, $notes, $checkinDate, $existing]
            );
            json_response(true, 'Checkin updated.', ['id' => $existing]);
        } else {
            DB::execute(
                "INSERT INTO goal_checkins (goal_id, user_id, amount, checkin_date, notes, created_at, updated_at)
                 VALUES (?,?,?,?,?,NOW(),NOW())",
                [$goalId, $userId, $amount, $checkinDate, $notes]
            );
            json_response(true, 'Checkin saved.', ['id' => DB::lastInsertId()]);
        }
        break;
    }

    // ── CHECKIN HISTORY for a goal ────────────────────────────────────
    case 'goal_checkin_history': {
        $goalId = (int)($_GET['goal_id'] ?? $_POST['goal_id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM goals WHERE id=? AND user_id=?", [$goalId, $userId]);
        if (!$own) json_response(false, 'Goal not found.');

        $rows = DB::fetchAll(
            "SELECT id, amount, checkin_date, notes, created_at
             FROM goal_checkins WHERE goal_id=?
             ORDER BY checkin_date DESC LIMIT 60",
            [$goalId]
        );
        $total = (float)DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM goal_checkins WHERE goal_id=?", [$goalId]);
        json_response(true, 'ok', ['checkins' => $rows, 'total' => $total]);
        break;
    }

    // ── PROJECTION: when will goal be achieved at current pace ───────
    case 'goal_checkin_projection': {
        $goalId = (int)($_GET['goal_id'] ?? $_POST['goal_id'] ?? 0);
        $own = DB::fetchRow("SELECT * FROM goals WHERE id=? AND user_id=?", [$goalId, $userId]);
        if (!$own) json_response(false, 'Goal not found.');

        $target   = (float)$own['target_amount'];
        $invested = (float)(DB::fetchVal("SELECT COALESCE(SUM(amount),0) FROM goal_checkins WHERE goal_id=?", [$goalId]) ?? 0);

        // Average monthly from last 3 checkins
        $recent = DB::fetchAll(
            "SELECT amount FROM goal_checkins WHERE goal_id=? ORDER BY checkin_date DESC LIMIT 3",
            [$goalId]
        );
        $avgMonthly = count($recent)
            ? array_sum(array_column($recent, 'amount')) / count($recent)
            : 0;

        $remaining = max(0, $target - $invested);
        $monthsToGoal = ($avgMonthly > 0) ? ceil($remaining / $avgMonthly) : null;

        $projectedDate = null;
        if ($monthsToGoal !== null) {
            $d = new DateTime();
            $d->modify("+{$monthsToGoal} months");
            $projectedDate = $d->format('Y-m-d');
        }

        $onTimeStatus = null;
        if ($projectedDate && $own['target_date']) {
            $onTimeStatus = $projectedDate <= $own['target_date'] ? 'on_time' : 'delayed';
        }

        json_response(true, 'ok', [
            'target_amount'   => $target,
            'invested'        => $invested,
            'remaining'       => $remaining,
            'avg_monthly'     => round($avgMonthly),
            'months_to_goal'  => $monthsToGoal,
            'projected_date'  => $projectedDate,
            'target_date'     => $own['target_date'],
            'on_time_status'  => $onTimeStatus,
        ]);
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}
