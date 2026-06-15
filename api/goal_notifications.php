<?php
/**
 * WealthDash — t358: Goal Notification Engine
 * File: api/notifications/goal_notifications.php
 * Actions: goal_notifications_list, goal_notification_dismiss,
 *          goal_notifications_check
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    case 'goal_notifications_check': {
        // Run milestone checks and generate new notifications
        $goals = DB::fetchAll(
            "SELECT g.id, g.goal_name, g.target_amount, g.target_date,
                    COALESCE(SUM(gc.amount),0) AS invested
             FROM goals g
             LEFT JOIN goal_checkins gc ON gc.goal_id = g.id
             WHERE g.user_id=? AND g.status='active'
             GROUP BY g.id",
            [$userId]
        );

        $newNotifs = [];
        $milestones = [10, 25, 50, 75, 90, 100];

        foreach ($goals as $g) {
            $target   = (float)$g['target_amount'];
            $invested = (float)$g['invested'];
            if ($target <= 0) continue;
            $pct = ($invested / $target) * 100;

            foreach ($milestones as $m) {
                if ($pct >= $m) {
                    // Check if this milestone notification already sent
                    $exists = DB::fetchVal(
                        "SELECT id FROM goal_notifications WHERE user_id=? AND goal_id=? AND milestone_pct=?",
                        [$userId, $g['id'], $m]
                    );
                    if (!$exists) {
                        $emoji   = $m >= 100 ? '🏆' : ($m >= 75 ? '🌟' : ($m >= 50 ? '🎯' : '🎉'));
                        $message = match(true) {
                            $m >= 100 => "Congratulations! {$g['goal_name']} goal achieved! 🏆",
                            $m >= 75  => "{$m}% milestone reached for {$g['goal_name']}! Almost there! 🌟",
                            $m >= 50  => "Halfway there! {$g['goal_name']} is {$m}% complete! 🎯",
                            default   => "{$g['goal_name']} is {$m}% funded! Keep going! 🎉",
                        };
                        DB::execute(
                            "INSERT INTO goal_notifications(user_id,goal_id,milestone_pct,message,emoji,is_read,created_at)
                             VALUES(?,?,?,?,?,0,NOW())",
                            [$userId, $g['id'], $m, $message, $emoji]
                        );
                        $newNotifs[] = ['goal' => $g['goal_name'], 'milestone' => $m, 'message' => $message];
                    }
                }
            }

            // Days remaining check
            if ($g['target_date']) {
                $daysLeft = (int)ceil((strtotime($g['target_date']) - time()) / 86400);
                if ($daysLeft > 0 && $daysLeft <= 30 && $pct < 100) {
                    $key = "deadline_{$daysLeft}";
                    $exists = DB::fetchVal(
                        "SELECT id FROM goal_notifications WHERE user_id=? AND goal_id=? AND milestone_pct=?",
                        [$userId, $g['id'], -$daysLeft]
                    );
                    if (!$exists) {
                        DB::execute(
                            "INSERT INTO goal_notifications(user_id,goal_id,milestone_pct,message,emoji,is_read,created_at)
                             VALUES(?,?,?,?,?,0,NOW())",
                            [$userId, $g['id'], -$daysLeft,
                             "⏰ {$g['goal_name']} deadline in {$daysLeft} days! Only " . round($pct, 0) . "% done.",
                             '⏰']
                        );
                    }
                }
            }
        }

        json_response(true, 'ok', ['new_count' => count($newNotifs), 'new' => $newNotifs]);
        break;
    }

    case 'goal_notifications_list': {
        $rows = DB::fetchAll(
            "SELECT n.*, g.goal_name, g.target_amount
             FROM goal_notifications n
             LEFT JOIN goals g ON g.id = n.goal_id
             WHERE n.user_id=?
             ORDER BY n.created_at DESC LIMIT 50",
            [$userId]
        );
        $unread = (int)(DB::fetchVal("SELECT COUNT(*) FROM goal_notifications WHERE user_id=? AND is_read=0", [$userId]) ?? 0);
        json_response(true, 'ok', ['notifications' => $rows, 'unread' => $unread]);
        break;
    }

    case 'goal_notification_dismiss': {
        csrf_verify();
        $id  = (int)($_POST['id']  ?? 0);
        $all = (int)($_POST['all'] ?? 0);
        if ($all) {
            DB::execute("UPDATE goal_notifications SET is_read=1 WHERE user_id=?", [$userId]);
        } else {
            DB::execute("UPDATE goal_notifications SET is_read=1 WHERE id=? AND user_id=?", [$id, $userId]);
        }
        json_response(true, 'ok');
        break;
    }

    default: json_response(false, 'Unknown action.', [], 400);
}
