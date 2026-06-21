<?php
/**
 * WealthDash — t242: Investor Streak & Milestones — Gamification
 * File: api/gamification/streaks.php
 * Actions: streak_status, streak_checkin, milestones_list, badges_list,
 *          leaderboard_opt_in
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

function _badge_registry(): array {
    return [
        'first_sip'       => ['label'=>'First SIP',          'icon'=>'🌱','desc'=>'Started your first SIP'],
        'sip_streak_3'    => ['label'=>'3-Month Streak',      'icon'=>'🔥','desc'=>'3 consecutive months of SIP investing'],
        'sip_streak_6'    => ['label'=>'6-Month Streak',      'icon'=>'🔥','desc'=>'6 consecutive months of SIP investing'],
        'sip_streak_12'   => ['label'=>'1-Year Streak',       'icon'=>'🏆','desc'=>'12 consecutive months of SIP investing'],
        'first_goal'      => ['label'=>'Goal Setter',         'icon'=>'🎯','desc'=>'Set your first financial goal'],
        'goal_achiever'   => ['label'=>'Goal Achiever',       'icon'=>'🏅','desc'=>'Completed a financial goal'],
        'diversified'     => ['label'=>'Diversified Investor','icon'=>'📊','desc'=>'Hold 5+ different funds'],
        'lakh_club'       => ['label'=>'Lakh Club',           'icon'=>'💰','desc'=>'Portfolio crossed ₹1 Lakh'],
        'ten_lakh_club'   => ['label'=>'10 Lakh Club',        'icon'=>'💎','desc'=>'Portfolio crossed ₹10 Lakh'],
        'crorepati'       => ['label'=>'Crorepati Club',      'icon'=>'🚀','desc'=>'Portfolio crossed ₹1 Crore'],
        'insured'         => ['label'=>'Protected',           'icon'=>'🛡','desc'=>'Added an insurance policy'],
        'budgeter'        => ['label'=>'Budget Master',       'icon'=>'💹','desc'=>'Tracked budget for a full month'],
        'early_bird'      => ['label'=>'Early Bird',          'icon'=>'🐦','desc'=>'Logged in before 9 AM, 5 times'],
        'profile_complete'=> ['label'=>'All Set Up',          'icon'=>'✅','desc'=>'Completed your finance profile'],
    ];
}

switch ($action) {

    // ── Compute current streak + check milestones ───────────────────
    case 'streak_status': {
        // SIP streak: consecutive months with at least 1 SIP transaction
        $months = DB::fetchAll(
            "SELECT DISTINCT DATE_FORMAT(txn_date,'%Y-%m') AS ym
             FROM mf_transactions WHERE user_id=? AND portfolio_id=? AND txn_type IN ('sip','purchase')
             ORDER BY ym DESC LIMIT 24",
            [$userId, $portfolioId]
        );
        $monthSet = array_column($months, 'ym');

        $streak = 0;
        $cursor = date('Y-m');
        while (in_array($cursor, $monthSet)) {
            $streak++;
            $cursor = date('Y-m', strtotime("$cursor-01 -1 month"));
        }

        // Check/award streak badges
        $newBadges = [];
        if ($streak >= 3)  $newBadges[] = _award_badge($userId, 'sip_streak_3');
        if ($streak >= 6)  $newBadges[] = _award_badge($userId, 'sip_streak_6');
        if ($streak >= 12) $newBadges[] = _award_badge($userId, 'sip_streak_12');
        if (count($months) >= 1) $newBadges[] = _award_badge($userId, 'first_sip');

        $newBadges = array_filter($newBadges);

        // Update streak record
        $existing = DB::fetchVal("SELECT id FROM investor_streaks WHERE user_id=?", [$userId]);
        if ($existing) {
            DB::execute("UPDATE investor_streaks SET current_streak=?,longest_streak=GREATEST(longest_streak,?),last_checked=NOW() WHERE id=?", [$streak, $streak, $existing]);
        } else {
            DB::execute("INSERT INTO investor_streaks(user_id,current_streak,longest_streak,last_checked) VALUES(?,?,?,NOW())", [$userId, $streak, $streak]);
        }

        $longest = (int)(DB::fetchVal("SELECT longest_streak FROM investor_streaks WHERE user_id=?", [$userId]) ?? $streak);

        json_response(true,'ok',[
            'current_streak' => $streak,
            'longest_streak' => max($streak, $longest),
            'new_badges'     => array_values($newBadges),
        ]);
        break;
    }

    // ── Check-in (manual daily engagement — optional gamification) ───
    case 'streak_checkin': {
        csrf_verify();
        $today = date('Y-m-d');
        $exists = DB::fetchVal("SELECT id FROM daily_checkins WHERE user_id=? AND checkin_date=?", [$userId, $today]);
        if ($exists) json_response(true, 'Already checked in today.', ['already'=>true]);

        DB::execute("INSERT INTO daily_checkins(user_id,checkin_date,created_at) VALUES(?,?,NOW())", [$userId, $today]);

        // Early bird badge: 5 check-ins before 9 AM
        if ((int)date('G') < 9) {
            $earlyCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM daily_checkins WHERE user_id=? AND HOUR(created_at)<9", [$userId]) ?? 0);
            if ($earlyCount >= 5) _award_badge($userId, 'early_bird');
        }

        json_response(true, 'Checked in! 🎉', ['already'=>false]);
        break;
    }

    // ── List all milestones with progress ──────────────────────────
    case 'milestones_list': {
        $portfolioValue = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(h.units*COALESCE(n.nav,h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
            [$userId, $portfolioId]
        ) ?? 0);

        // Check value-based badges
        if ($portfolioValue >= 100000)    _award_badge($userId, 'lakh_club');
        if ($portfolioValue >= 1000000)   _award_badge($userId, 'ten_lakh_club');
        if ($portfolioValue >= 10000000)  _award_badge($userId, 'crorepati');

        $holdingCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_holdings WHERE user_id=? AND portfolio_id=? AND units>0",[$userId,$portfolioId]) ?? 0);
        if ($holdingCount >= 5) _award_badge($userId, 'diversified');

        $goalCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM goals WHERE user_id=?",[$userId]) ?? 0);
        if ($goalCount >= 1) _award_badge($userId, 'first_goal');
        $completedGoals = (int)(DB::fetchVal("SELECT COUNT(*) FROM goals WHERE user_id=? AND status='completed'",[$userId]) ?? 0);
        if ($completedGoals >= 1) _award_badge($userId, 'goal_achiever');

        $insuranceCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM insurance_policies WHERE user_id=?",[$userId]) ?? 0);
        if ($insuranceCount >= 1) _award_badge($userId, 'insured');

        $profile = DB::fetchRow("SELECT * FROM finance_profiles WHERE user_id=?",[$userId]);
        if ($profile && !empty($profile['risk_profile']) && !empty($profile['annual_income'])) _award_badge($userId, 'profile_complete');

        $milestones = [
            ['label'=>'₹1 Lakh',  'target'=>100000,    'achieved'=>$portfolioValue>=100000],
            ['label'=>'₹5 Lakh',  'target'=>500000,    'achieved'=>$portfolioValue>=500000],
            ['label'=>'₹10 Lakh', 'target'=>1000000,   'achieved'=>$portfolioValue>=1000000],
            ['label'=>'₹25 Lakh', 'target'=>2500000,   'achieved'=>$portfolioValue>=2500000],
            ['label'=>'₹50 Lakh', 'target'=>5000000,   'achieved'=>$portfolioValue>=5000000],
            ['label'=>'₹1 Crore', 'target'=>10000000,  'achieved'=>$portfolioValue>=10000000],
        ];
        $nextMilestone = null;
        foreach ($milestones as $m) { if (!$m['achieved']) { $nextMilestone = $m; break; } }

        json_response(true,'ok',[
            'portfolio_value' => round($portfolioValue),
            'milestones'      => $milestones,
            'next_milestone'  => $nextMilestone,
            'progress_to_next'=> $nextMilestone ? round($portfolioValue/$nextMilestone['target']*100,1) : 100,
        ]);
        break;
    }

    // ── List earned + available badges ───────────────────────────────
    case 'badges_list': {
        $earned = DB::fetchAll("SELECT badge_key, earned_at FROM user_badges WHERE user_id=? ORDER BY earned_at DESC", [$userId]);
        $earnedKeys = array_column($earned, 'badge_key');
        $earnedMap  = array_column($earned, 'earned_at', 'badge_key');

        $registry = _badge_registry();
        $all = [];
        foreach ($registry as $key => $b) {
            $all[] = array_merge($b, ['key'=>$key,'earned'=>in_array($key,$earnedKeys),'earned_at'=>$earnedMap[$key]??null]);
        }
        usort($all, fn($a,$b) => $b['earned'] <=> $a['earned']);

        json_response(true,'ok',['badges'=>$all,'earned_count'=>count($earnedKeys),'total_count'=>count($registry)]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}

// ── Helper: award badge if not already earned ──────────────────────
function _award_badge(int $userId, string $key): ?array {
    $exists = DB::fetchVal("SELECT id FROM user_badges WHERE user_id=? AND badge_key=?", [$userId, $key]);
    if ($exists) return null;
    DB::execute("INSERT INTO user_badges(user_id,badge_key,earned_at) VALUES(?,?,NOW())", [$userId, $key]);
    $registry = _badge_registry();
    return $registry[$key] ?? null;
}
