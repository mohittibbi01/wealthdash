<?php
/**
 * WealthDash — Goals v2 & SIP Discipline APIs
 *
 * t355 — Goal Buckets (holdings link to goal) — DB schema + API
 * t356 — Goal Simulator (SIP change → goal date change)
 * t357 — Retirement Planner v2 (income replacement model)
 * t359 — SWP Calculator (retirement withdrawal planner)
 * tj002 — SIP Streak Tracker (consecutive months, badges)
 *
 * GET /api/goals/goals_advanced.php
 *   ?action=simulator        &goal_id=X &sip_amount=X
 *   ?action=retirement_planner &income=X &current_age=X &portfolio_id=X
 *   ?action=swp_calculator   &corpus=X &monthly_withdrawal=X &years=X
 *   ?action=sip_streak       &portfolio_id=X
 *   ?action=goal_progress    &portfolio_id=X
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

$currentUser = require_auth();

set_exception_handler(function (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
});

try {
    $db          = DB::conn();
    $action      = $_GET['action'] ?? 'goal_progress';
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];

    $response = ['success' => true, 'action' => $action];

    switch ($action) {
        case 'simulator':
            $goalId   = (int)($_GET['goal_id'] ?? 0);
            $newSip   = (float)($_GET['sip_amount'] ?? 0);
            $response['simulator'] = goal_simulator($db, $userId, $goalId, $newSip);
            break;

        case 'retirement_planner':
            $income     = (float)($_GET['income'] ?? 0);
            $curAge     = (int)($_GET['current_age'] ?? 30);
            $retireAge  = (int)($_GET['retirement_age'] ?? 60);
            $lifeExp    = (int)($_GET['life_expectancy'] ?? 85);
            $response['retirement'] = retirement_planner($db, $userId, $portfolioId, $income, $curAge, $retireAge, $lifeExp);
            break;

        case 'swp_calculator':
            $corpus     = (float)($_GET['corpus'] ?? 0);
            $withdrawal = (float)($_GET['monthly_withdrawal'] ?? 0);
            $years      = (int)($_GET['years'] ?? 25);
            $returnRate = (float)($_GET['return_rate'] ?? 8.0);
            $inflation  = (float)($_GET['inflation'] ?? 6.0);
            $response['swp'] = swp_calculator($corpus, $withdrawal, $years, $returnRate, $inflation);
            break;

        case 'sip_streak':
            $response['streaks'] = sip_streak_tracker($db, $userId, $portfolioId);
            break;

        case 'goal_progress':
        default:
            $response['goal_progress'] = goal_progress_summary($db, $userId, $portfolioId);
            $response['streaks']       = sip_streak_tracker($db, $userId, $portfolioId);
            break;
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// t356 — GOAL SIMULATOR
// ═══════════════════════════════════════════════════════════════════════════
function goal_simulator(PDO $db, int $userId, int $goalId, float $newSip): array
{
    // Fetch goal
    $goal = null;
    try {
        $stmt = $db->prepare("SELECT * FROM goal_buckets WHERE id = ? AND user_id = ?");
        $stmt->execute([$goalId, $userId]);
        $goal = $stmt->fetch();
    } catch (Exception $e) {}

    if (!$goal) {
        // Return generic simulation without specific goal
        return generic_sip_simulator($newSip);
    }

    $targetAmount  = (float)$goal['target_amount'];
    $targetDate    = $goal['target_date'] ? new DateTime($goal['target_date']) : null;
    $today         = new DateTime();
    $yearsLeft     = $targetDate ? max(0.5, round($today->diff($targetDate)->days / 365, 1)) : 10;

    // Current linked SIP total
    $currentSip = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(s.sip_amount), 0)
            FROM sip_tracker s
            JOIN goal_fund_links gfl ON gfl.sip_id = s.id
            WHERE gfl.goal_id = ? AND s.is_active = 1
        ");
        $stmt->execute([$goalId]);
        $currentSip = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // Current corpus linked to goal
    $currentCorpus = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(h.units * f.latest_nav), 0)
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            JOIN goal_fund_links gfl ON gfl.fund_id = h.fund_id
            WHERE gfl.goal_id = ? AND h.units > 0
        ");
        $stmt->execute([$goalId]);
        $currentCorpus = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    $expectedReturn = 0.12; // 12% equity MF
    $monthly        = $expectedReturn / 12;

    // Scenarios
    $scenarios = [];
    $sipAmounts = [$currentSip, $currentSip * 1.1, $currentSip * 1.25, $newSip];
    if ($newSip <= 0) $sipAmounts = [$currentSip, $currentSip * 1.10, $currentSip * 1.25, $currentSip * 1.50];

    foreach (array_unique($sipAmounts) as $sip) {
        if ($sip <= 0) continue;
        $months = (int)($yearsLeft * 12);

        // FV = current corpus grown + SIP FV
        $fvCorpus = $currentCorpus * ((1 + $expectedReturn) ** $yearsLeft);
        $fvSip    = $sip * ((((1 + $monthly) ** $months) - 1) / $monthly) * (1 + $monthly);
        $total    = round($fvCorpus + $fvSip, 0);

        // Months to reach goal with this SIP
        $monthsToGoal = months_to_target($currentCorpus, $sip, $monthly, $targetAmount);

        $scenarios[] = [
            'sip_amount'         => round($sip, 0),
            'projected_corpus'   => $total,
            'will_achieve'       => $total >= $targetAmount,
            'achievement_pct'    => round($total / $targetAmount * 100, 1),
            'months_to_goal'     => $monthsToGoal,
            'years_to_goal'      => round($monthsToGoal / 12, 1),
            'goal_date_estimate' => date('M Y', strtotime("+{$monthsToGoal} months")),
            'shortfall'          => max(0, (int)round($targetAmount - $total)),
        ];
    }

    // Required SIP to meet goal in time
    $requiredSip = calc_required_sip_for_goal($currentCorpus, $yearsLeft, $monthly, $targetAmount);

    return [
        'goal_name'        => $goal['name'],
        'target_amount'    => $targetAmount,
        'target_date'      => $goal['target_date'],
        'years_left'       => $yearsLeft,
        'current_corpus'   => round($currentCorpus, 0),
        'current_sip'      => round($currentSip, 0),
        'required_sip'     => round($requiredSip, 0),
        'sip_gap'          => round(max(0, $requiredSip - $currentSip), 0),
        'scenarios'        => $scenarios,
        'assumption'       => '12% annualized return (equity MF), monthly compounding',
    ];
}

function calc_required_sip_for_goal(float $corpus, float $years, float $r, float $goal): float
{
    $months    = $years * 12;
    $fvCorpus  = $corpus * ((1 + $r * 12) ** $years);
    $remaining = max(0, $goal - $fvCorpus);
    if ($remaining <= 0) return 0;
    return $r > 0
        ? $remaining * $r / ((((1 + $r) ** $months) - 1) * (1 + $r))
        : $remaining / $months;
}

function months_to_target(float $corpus, float $sip, float $r, float $target): int
{
    for ($n = 1; $n <= 600; $n++) {
        $fvC = $corpus * ((1 + $r) ** $n);
        $fvS = $sip * ((((1 + $r) ** $n) - 1) / $r) * (1 + $r);
        if ($fvC + $fvS >= $target) return $n;
    }
    return 600;
}

function generic_sip_simulator(float $sip): array
{
    $results = [];
    foreach ([5, 10, 15, 20] as $years) {
        $months = $years * 12;
        $r      = 0.12 / 12;
        $fv     = $sip * ((((1 + $r) ** $months) - 1) / $r) * (1 + $r);
        $invested = $sip * $months;
        $results[] = [
            'years'     => $years,
            'corpus'    => round($fv, 0),
            'invested'  => round($invested, 0),
            'gain'      => round($fv - $invested, 0),
            'cagr_pct'  => 12.0,
        ];
    }
    return ['sip_amount' => $sip, 'projections' => $results, 'assumption' => '12% annual return'];
}

// ═══════════════════════════════════════════════════════════════════════════
// t357 — RETIREMENT PLANNER v2
// ═══════════════════════════════════════════════════════════════════════════
function retirement_planner(PDO $db, int $userId, int $portfolioId, float $income, int $curAge, int $retireAge, int $lifeExp): array
{
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    $yearsToRetire   = max(1, $retireAge - $curAge);
    $retirementYears = max(1, $lifeExp - $retireAge);

    // Pull current net worth from WealthDash
    $currentNetWorth = 0;
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(h.units * f.latest_nav), 0)
            FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.units > 0 $pWhere
        ");
        $stmt->execute([$pParam]);
        $currentNetWorth = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // Monthly expenses at retirement (inflation-adjusted)
    $monthlyExpenseNow   = max(1, $income * 0.70 / 12); // 70% of income as expenses
    $inflation           = 0.06;
    $monthlyExpenseRetire= round($monthlyExpenseNow * ((1 + $inflation) ** $yearsToRetire), 0);

    // Income replacement: target corpus to generate monthly expense via SWP
    $postReturnRate = 0.08; // conservative 8% post-retirement
    $annualExpense  = $monthlyExpenseRetire * 12;
    $corpusNeeded   = round($annualExpense * 25, 0); // 4% safe withdrawal = 25x expenses

    // Income sources at retirement
    $npsCorpus = 0;
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(current_value), 0) FROM nps_holdings h JOIN portfolios p ON p.id = h.portfolio_id WHERE 1=1 $pWhere");
        $stmt->execute([$pParam]);
        $npsCorpus = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    $fvMfCorpus  = round($currentNetWorth * ((1.12) ** $yearsToRetire), 0);
    $fvNps       = round($npsCorpus * ((1.10) ** $yearsToRetire), 0);
    $totalCorpus = $fvMfCorpus + $fvNps;

    $shortfall = max(0, $corpusNeeded - $totalCorpus);
    $additionalSipNeeded = $shortfall > 0
        ? round(calc_required_sip_for_goal(0, $yearsToRetire, 0.12/12, $shortfall), 0)
        : 0;

    // SWP sustainability check
    $swpSustainable = swp_calculator($totalCorpus, $monthlyExpenseRetire, $retirementYears, $postReturnRate * 100, $inflation * 100);

    return [
        'inputs' => [
            'current_age'        => $curAge,
            'retirement_age'     => $retireAge,
            'life_expectancy'    => $lifeExp,
            'current_income'     => $income,
            'years_to_retire'    => $yearsToRetire,
            'retirement_years'   => $retirementYears,
        ],
        'expense_projection' => [
            'monthly_now'        => round($monthlyExpenseNow, 0),
            'monthly_at_retire'  => $monthlyExpenseRetire,
            'inflation_assumed'  => '6% per annum',
            'annual_at_retire'   => $annualExpense,
        ],
        'corpus_needed'      => $corpusNeeded,
        'corpus_rule'        => '25x annual expenses (4% Safe Withdrawal Rate). India: use 3.5% (6% inflation) → 28x for safety.',
        'current_assets' => [
            'mf_corpus_now'      => round($currentNetWorth, 0),
            'nps_corpus_now'     => round($npsCorpus, 0),
            'fv_mf_at_retire'    => $fvMfCorpus,
            'fv_nps_at_retire'   => $fvNps,
            'total_at_retire'    => $totalCorpus,
        ],
        'gap_analysis' => [
            'corpus_needed'      => $corpusNeeded,
            'corpus_expected'    => $totalCorpus,
            'shortfall'          => $shortfall,
            'on_track'           => $shortfall <= 0,
            'additional_sip_needed' => $additionalSipNeeded,
            'surplus'            => max(0, $totalCorpus - $corpusNeeded),
        ],
        'swp_sustainability' => $swpSustainable,
        'income_sources'     => [
            ['source' => 'MF SWP',      'monthly' => round($fvMfCorpus * 0.04 / 12, 0), 'notes' => '4% SWR from equity MF'],
            ['source' => 'NPS Pension',  'monthly' => round($fvNps * 0.40 * 0.055 / 12, 0), 'notes' => '40% annuity at 5.5%'],
            ['source' => 'EPF Corpus',   'monthly' => null, 'notes' => 'Include EPF lumpsum in overall corpus'],
        ],
        'india_specific'     => [
            'safe_withdrawal_rate' => '3-3.5% (higher inflation than West)',
            'corpus_multiplier'    => '28-33x annual expenses for Indian retirees',
            'nps_rule'             => '40% mandatory annuity at 60 provides fixed monthly income',
            'bucket_strategy'      => '1yr cash, 5yr debt, rest equity — reduces sequence of return risk',
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// t359 — SWP CALCULATOR
// ═══════════════════════════════════════════════════════════════════════════
function swp_calculator(float $corpus, float $monthly, int $years, float $returnPct, float $inflationPct): array
{
    if ($corpus <= 0) {
        return ['error' => 'corpus required (?corpus=XXXXXX)'];
    }

    $r = $returnPct / 100 / 12;
    $inf = $inflationPct / 100 / 12;

    // Simulate month-by-month
    $balance  = $corpus;
    $timeline = [];
    $monthlyWithdrawal = $monthly;
    $totalWithdrawn = 0;
    $depletedMonth = null;

    for ($m = 1; $m <= $years * 12; $m++) {
        $balance = $balance * (1 + $r) - $monthlyWithdrawal;
        $totalWithdrawn += $monthlyWithdrawal;

        // Inflation-adjust withdrawal annually
        if ($m % 12 === 0) $monthlyWithdrawal *= (1 + $inf * 12);

        if ($balance <= 0 && $depletedMonth === null) {
            $depletedMonth = $m;
            $balance = 0;
        }

        if ($m % 12 === 0) { // record annually
            $yr = $m / 12;
            $timeline[] = [
                'year'           => $yr,
                'balance'        => max(0, round($balance, 0)),
                'monthly_withdrawal' => round($monthlyWithdrawal, 0),
                'annual_withdrawn'   => round($totalWithdrawn, 0),
            ];
        }
    }

    $sustainable = $depletedMonth === null;
    $safeWithdrawal = round($corpus * ($returnPct - $inflationPct) / 100 / 12, 0); // approx safe withdrawal

    // Find maximum sustainable monthly withdrawal
    $lo = 1000; $hi = $corpus / 12;
    for ($i = 0; $i < 30; $i++) {
        $mid = ($lo + $hi) / 2;
        $b   = $corpus;
        $survived = true;
        for ($m = 1; $m <= $years * 12; $m++) {
            $b = $b * (1 + $r) - $mid * ((1 + $inf) ** floor($m / 12));
            if ($b <= 0) { $survived = false; break; }
        }
        if ($survived) $lo = $mid; else $hi = $mid;
    }
    $maxSustainable = round(($lo + $hi) / 2, 0);

    return [
        'inputs' => [
            'corpus'             => round($corpus, 0),
            'monthly_withdrawal' => round($monthly, 0),
            'years'              => $years,
            'return_rate_pa'     => $returnPct,
            'inflation_pa'       => $inflationPct,
        ],
        'sustainable'            => $sustainable,
        'depleted_month'         => $depletedMonth,
        'depleted_year'          => $depletedMonth ? round($depletedMonth / 12, 1) : null,
        'final_balance'          => max(0, round($balance, 0)),
        'total_withdrawn'        => round($totalWithdrawn, 0),
        'max_sustainable_monthly'=> $maxSustainable,
        'safe_withdrawal_rate'   => round($maxSustainable / $corpus * 12 * 100, 2),
        'recommended_withdrawal' => round($corpus * 0.035 / 12, 0), // 3.5% SWR for India
        'timeline'               => $timeline,
        'verdict' => $sustainable
            ? "✅ ₹{$monthly}/month is sustainable for {$years} years. Final corpus: ₹" . number_format((int)max(0, $balance), 0)
            : "⚠️ Corpus depletes in " . round($depletedMonth / 12, 1) . " years at ₹{$monthly}/month. Reduce to ₹{$maxSustainable}/month for full {$years} years.",
        'tips' => [
            'Withdraw 3-3.5% annually (not 4%) for Indian portfolios — higher inflation',
            'Use bucket strategy: keep 2yr expenses in FD, rest in equity',
            'Increase withdrawal by inflation % each year, not more',
            'Review portfolio annually and rebalance',
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// tj002 — SIP STREAK TRACKER
// ═══════════════════════════════════════════════════════════════════════════
function sip_streak_tracker(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    // Get all active SIPs
    $sips = [];
    try {
        $stmt = $db->prepare("
            SELECT s.id, s.fund_id, s.sip_amount, s.start_date, s.sip_date,
                   f.scheme_name, f.category
            FROM sip_tracker s JOIN funds f ON f.id = s.fund_id
            JOIN portfolios p ON p.id = s.portfolio_id
            WHERE s.is_active = 1 $pWhere
        ");
        $stmt->execute([$pParam]);
        $sips = $stmt->fetchAll();
    } catch (Exception $e) {}

    if (empty($sips)) {
        return ['count' => 0, 'message' => 'No active SIPs found.', 'sips' => []];
    }

    $results = [];
    $maxStreak = 0;
    $maxStreakSip = null;

    foreach ($sips as $sip) {
        $sipId     = (int)$sip['id'];
        $fundId    = (int)$sip['fund_id'];
        $startDate = $sip['start_date'];

        // Get all SIP transaction dates
        try {
            $stmt = $db->prepare("
                SELECT DATE_FORMAT(txn_date, '%Y-%m') AS ym
                FROM mf_transactions
                WHERE fund_id = ? AND portfolio_id IN (
                    SELECT id FROM portfolios WHERE user_id = ?
                ) AND transaction_type = 'sip'
                ORDER BY txn_date ASC
            ");
            $stmt->execute([$fundId, $userId]);
            $months = array_column($stmt->fetchAll(), 'ym');
        } catch (Exception $e) { $months = []; }

        // Calculate current streak
        $streak = calc_streak($months);
        $longestStreak = calc_longest_streak($months);

        // Badge
        $badge = match (true) {
            $streak >= 60 => '🔥 5-Year Champion',
            $streak >= 36 => '⭐ 3-Year Master',
            $streak >= 24 => '💎 2-Year Pro',
            $streak >= 12 => '🏆 1-Year Club',
            $streak >= 6  => '🥈 6-Month Streak',
            $streak >= 3  => '🥉 3-Month Streak',
            $streak >= 1  => '🌱 Started',
            default       => '⏸ Streak broken',
        };

        if ($streak > $maxStreak) {
            $maxStreak = $streak;
            $maxStreakSip = $sip['scheme_name'];
        }

        $results[] = [
            'sip_id'         => $sipId,
            'scheme_name'    => $sip['scheme_name'],
            'category'       => $sip['category'],
            'amount'         => (float)$sip['sip_amount'],
            'start_date'     => $startDate,
            'current_streak' => $streak,
            'longest_streak' => $longestStreak,
            'total_months'   => count($months),
            'badge'          => $badge,
            'next_milestone' => next_milestone($streak),
        ];
    }

    usort($results, fn($a, $b) => $b['current_streak'] <=> $a['current_streak']);

    return [
        'count'               => count($results),
        'sips'                => $results,
        'max_streak'          => $maxStreak,
        'max_streak_sip'      => $maxStreakSip,
        'header_message'      => $maxStreak > 0
            ? "Your longest active streak: {$maxStreak} months 🔥"
            : "Start your SIP streak today!",
        'milestones'          => ['3m 🥉', '6m 🥈', '12m 🏆', '24m 💎', '36m ⭐', '60m 🔥'],
    ];
}

function calc_streak(array $months): int
{
    if (empty($months)) return 0;

    $streak = 0;
    $today  = new DateTime();
    $check  = clone $today;
    $check->modify('first day of this month');

    while (true) {
        $ym = $check->format('Y-m');
        if (in_array($ym, $months)) {
            $streak++;
            $check->modify('-1 month');
        } else {
            break;
        }
    }
    return $streak;
}

function calc_longest_streak(array $months): int
{
    if (empty($months)) return 0;
    sort($months);
    $max = 1; $cur = 1;
    for ($i = 1; $i < count($months); $i++) {
        $prev = new DateTime($months[$i-1] . '-01');
        $curr = new DateTime($months[$i]   . '-01');
        $prev->modify('+1 month');
        if ($prev->format('Y-m') === $curr->format('Y-m')) {
            $cur++;
            $max = max($max, $cur);
        } else {
            $cur = 1;
        }
    }
    return $max;
}

function next_milestone(int $streak): array
{
    $milestones = [3, 6, 12, 24, 36, 60, 120];
    foreach ($milestones as $m) {
        if ($streak < $m) {
            return ['target' => $m, 'remaining' => $m - $streak, 'label' => "{$m} months"];
        }
    }
    return ['target' => 120, 'remaining' => 0, 'label' => '10-Year Club — Legend!'];
}

// ═══════════════════════════════════════════════════════════════════════════
// GOAL PROGRESS SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
function goal_progress_summary(PDO $db, int $userId, int $portfolioId): array
{
    $goals = [];
    try {
        $stmt = $db->prepare("
            SELECT g.*, COUNT(gfl.id) AS linked_count
            FROM goal_buckets g
            LEFT JOIN goal_fund_links gfl ON gfl.goal_id = g.id
            WHERE g.user_id = ?
            GROUP BY g.id ORDER BY g.target_date ASC
        ");
        $stmt->execute([$userId]);
        $goals = $stmt->fetchAll();
    } catch (Exception $e) { return ['goals' => [], 'message' => 'Goals table not set up yet']; }

    $result = [];
    foreach ($goals as $g) {
        $targetAmt  = (float)$g['target_amount'];
        $targetDate = $g['target_date'];
        $today      = new DateTime();

        // Current corpus linked
        $corpus = 0;
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(h.units * f.latest_nav), 0)
                FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
                JOIN goal_fund_links gfl ON gfl.fund_id = h.fund_id
                WHERE gfl.goal_id = ? AND h.units > 0
            ");
            $stmt->execute([(int)$g['id']]);
            $corpus = (float)$stmt->fetchColumn();
        } catch (Exception $e) {}

        $pctDone = $targetAmt > 0 ? min(100, round($corpus / $targetAmt * 100, 1)) : 0;

        $daysLeft = null;
        $yearsLeft = null;
        if ($targetDate) {
            $td = new DateTime($targetDate);
            $daysLeft  = max(0, (int)$today->diff($td)->days);
            $yearsLeft = round($daysLeft / 365, 1);
        }

        $status = match (true) {
            $pctDone >= 100 => 'achieved',
            $pctDone >= 80  => 'on_track',
            $pctDone >= 50  => 'progressing',
            $pctDone >= 25  => 'behind',
            default         => 'needs_attention',
        };

        $result[] = [
            'goal_id'      => (int)$g['id'],
            'name'         => $g['name'],
            'emoji'        => $g['emoji'] ?? '🎯',
            'target_amount'=> $targetAmt,
            'current_corpus'=> round($corpus, 0),
            'pct_done'     => $pctDone,
            'target_date'  => $targetDate,
            'days_left'    => $daysLeft,
            'years_left'   => $yearsLeft,
            'linked_funds' => (int)$g['linked_count'],
            'status'       => $status,
            'status_color' => match ($status) {
                'achieved'       => '#16a34a',
                'on_track'       => '#65a30d',
                'progressing'    => '#d97706',
                'behind'         => '#ea580c',
                default          => '#dc2626',
            },
            'shortfall'    => max(0, round($targetAmt - $corpus, 0)),
        ];
    }

    return [
        'goals'         => $result,
        'total_goals'   => count($result),
        'on_track'      => count(array_filter($result, fn($g) => in_array($g['status'], ['achieved','on_track']))),
        'at_risk'       => count(array_filter($result, fn($g) => in_array($g['status'], ['behind','needs_attention']))),
    ];
}
