<?php
/**
 * WealthDash — t364: SIP Pause/Resume Intelligence
 *
 * Smart guidance on WHEN to pause/resume SIPs:
 *  - Emergency detection: large withdrawal → suggest pause instead
 *  - Pause impact calculator: 3/6/12 month pause → goal delay
 *  - Market crash context: "Pause nahi karo, badhao" in -20%+ markets
 *  - Resume reminder logic
 *  - Historical analysis: 2020 COVID pause vs continue
 *
 * GET /api/mutual_funds/sip_pause_intelligence.php
 *   ?action=pause_impact     &sip_id=X &pause_months=6
 *   ?action=market_context
 *   ?action=emergency_check  &withdrawal_amount=X &portfolio_id=Y
 *   ?action=resume_analysis  &portfolio_id=Y
 *   ?action=dashboard        &portfolio_id=Y      ← full view (default)
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
    $action      = $_GET['action'] ?? 'dashboard';
    $sipId       = (int)($_GET['sip_id'] ?? 0);
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $pauseMonths = max(1, min(24, (int)($_GET['pause_months'] ?? 6)));
    $withdrawAmt = (float)($_GET['withdrawal_amount'] ?? 0);
    $userId      = (int)$currentUser['id'];

    $response = ['success' => true, 'action' => $action];

    switch ($action) {
        case 'pause_impact':
            if (!$sipId) throw new InvalidArgumentException('sip_id required');
            $response['pause_impact'] = calc_pause_impact($db, $sipId, $pauseMonths);
            break;

        case 'market_context':
            $response['market_context'] = get_market_context($db);
            break;

        case 'emergency_check':
            $response['emergency_check'] = emergency_check($db, $userId, $portfolioId, $withdrawAmt);
            break;

        case 'resume_analysis':
            $response['resume_analysis'] = get_resume_analysis($db, $userId, $portfolioId);
            break;

        case 'dashboard':
        default:
            $response['market_context']  = get_market_context($db);
            $response['resume_analysis'] = get_resume_analysis($db, $userId, $portfolioId);
            $response['historical']      = covid_pause_analysis();
            $response['rules']           = pause_rules();
            if ($withdrawAmt > 0) {
                $response['emergency_check'] = emergency_check($db, $userId, $portfolioId, $withdrawAmt);
            }
            break;
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// PAUSE IMPACT CALCULATOR
// ═══════════════════════════════════════════════════════════════════════════
function calc_pause_impact(PDO $db, int $sipId, int $pauseMonths): array
{
    // Fetch SIP details
    $sip = null;
    try {
        $sip = $db->prepare("
            SELECT s.id, s.fund_id, s.sip_amount, s.sip_date, s.start_date, s.goal_id,
                   f.scheme_name, f.category, f.returns_1y, f.returns_3y, f.returns_5y
            FROM sip_tracker s
            JOIN funds f ON f.id = s.fund_id
            WHERE s.id = ?
        ");
        $sip->execute([$sipId]);
        $sip = $sip->fetch();
    } catch (Exception $e) {}

    if (!$sip) {
        return ['error' => 'SIP not found', 'sip_id' => $sipId];
    }

    $monthly     = (float)$sip['sip_amount'];
    $expectedRet = ((float)($sip['returns_3y'] ?? 12)) / 100 / 12; // monthly rate

    // Assume 10 years remaining as default (or fetch from goal)
    $remainingMonths = 120;
    $goalAmount = null;
    if ($sip['goal_id']) {
        try {
            $goal = $db->prepare("SELECT target_amount, target_date FROM goals WHERE id = ?");
            $goal->execute([$sip['goal_id']]);
            $goal = $goal->fetch();
            if ($goal) {
                $goalAmount  = (float)$goal['target_amount'];
                $targetDate  = new DateTime($goal['target_date']);
                $now         = new DateTime();
                $remainingMonths = max(1, (int)$now->diff($targetDate)->days / 30);
            }
        } catch (Exception $e) {}
    }

    // Corpus WITHOUT pause
    $corpusNoPause   = sip_fv($monthly, $expectedRet, $remainingMonths);
    $investedNoPause = $monthly * $remainingMonths;

    // Corpus WITH pause (contributions stop for pauseMonths, then resume)
    $corpusAfterPause = sip_fv($monthly, $expectedRet, $remainingMonths - $pauseMonths);
    // The corpus at pause point grows for pauseMonths without new contributions
    // Approximate: all existing corpus grows, missing SIPs are forgone
    $lostContributions = $monthly * $pauseMonths;
    $corpusLost        = sip_fv($monthly, $expectedRet, $pauseMonths); // what those paused SIPs would've become

    $finalWithPause    = $corpusAfterPause;
    $finalWithoutPause = $corpusNoPause;
    $corpusLoss        = max(0, $finalWithoutPause - $finalWithPause);
    $goalDelayMonths   = 0;

    // Calculate goal delay
    if ($goalAmount && $expectedRet > 0) {
        // Months to reach goal without pause
        $monthsNP = months_to_goal($monthly, $expectedRet, $goalAmount);
        // Months to reach goal with pause
        $monthsWP = $monthsNP + $pauseMonths + 2; // rough: pause + slight delay from compounding loss
        $goalDelayMonths = $monthsWP - $monthsNP;
    }

    // Pause impact severity
    $severity = match (true) {
        $pauseMonths >= 12 => 'severe',
        $pauseMonths >= 6  => 'significant',
        $pauseMonths >= 3  => 'moderate',
        default            => 'mild',
    };

    // Smart alternatives to pausing
    $alternatives = [
        ['option' => 'Reduce SIP amount by 50%', 'impact' => 'Lose only half the compounding benefit vs full pause'],
        ['option' => 'Pause 1 fund, continue others', 'impact' => 'Protects goal-linked SIPs'],
        ['option' => 'Move to liquid fund SIP temporarily', 'impact' => 'Preserves habit, lower return but no market loss'],
    ];

    return [
        'sip_id'            => $sipId,
        'scheme_name'       => $sip['scheme_name'],
        'monthly_amount'    => $monthly,
        'pause_months'      => $pauseMonths,
        'remaining_months'  => $remainingMonths,
        'expected_return'   => round($expectedRet * 12 * 100, 1),
        'corpus_without_pause'  => round($finalWithoutPause, 0),
        'corpus_with_pause'     => round($finalWithPause, 0),
        'corpus_loss'           => round($corpusLoss, 0),
        'corpus_loss_pct'       => $finalWithoutPause > 0 ? round($corpusLoss / $finalWithoutPause * 100, 1) : 0,
        'lost_contributions'    => round($lostContributions, 0),
        'invested_without_pause'=> round($investedNoPause, 0),
        'goal_delay_months'     => $goalDelayMonths,
        'goal_delay_label'      => $goalDelayMonths > 0 ? "{$goalDelayMonths} months delay" : null,
        'severity'              => $severity,
        'verdict'               => pause_verdict($severity, $pauseMonths, $corpusLoss),
        'alternatives'          => $alternatives,
    ];
}

function pause_verdict(string $severity, int $months, float $loss): string
{
    $lossStr = '₹' . number_format($loss, 0);
    return match ($severity) {
        'severe'      => "Pausing for {$months} months will significantly impact your goal by {$lossStr}. Strongly consider reducing SIP amount instead of stopping completely.",
        'significant' => "A {$months}-month pause will cost ~{$lossStr} in lost compounding. Consider reducing to 50% instead of full pause.",
        'moderate'    => "A {$months}-month pause has moderate impact (~{$lossStr}). Acceptable if genuine emergency, but try to resume quickly.",
        default       => "Short pause of {$months} months is manageable (~{$lossStr} impact). Resume as soon as possible.",
    };
}

// SIP future value: M × [(1+r)^n - 1] / r × (1+r)
function sip_fv(float $monthly, float $r, int $n): float
{
    if ($r <= 0) return $monthly * $n;
    return $monthly * ((((1 + $r) ** $n) - 1) / $r) * (1 + $r);
}

function months_to_goal(float $monthly, float $r, float $goal): int
{
    // Binary search for n
    $lo = 1; $hi = 600;
    while ($lo < $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if (sip_fv($monthly, $r, $mid) >= $goal) $hi = $mid;
        else $lo = $mid + 1;
    }
    return $lo;
}

// ═══════════════════════════════════════════════════════════════════════════
// MARKET CONTEXT
// ═══════════════════════════════════════════════════════════════════════════
function get_market_context(PDO $db): array
{
    // Fetch recent Nifty 52-week performance to detect crash
    $niftyChange = null;
    $contextLabel = 'Normal Market';
    $advice = 'Continue SIP normally. No action needed.';

    try {
        // Try to get Nifty performance from index_data or funds table
        $row = $db->query("
            SELECT f.returns_1y, f.latest_nav, f.highest_nav
            FROM funds f
            WHERE f.scheme_name LIKE '%Nifty 50 Index%' AND f.scheme_name LIKE '%Direct%'
            LIMIT 1
        ")->fetch();
        if ($row) {
            $niftyChange = (float)($row['returns_1y'] ?? 0);
            $drawdownFromPeak = $row['highest_nav'] > 0
                ? round(((float)$row['latest_nav'] - (float)$row['highest_nav']) / (float)$row['highest_nav'] * 100, 1)
                : null;

            [$contextLabel, $advice, $color] = market_context_label($niftyChange, $drawdownFromPeak);
        }
    } catch (Exception $e) {}

    return [
        'nifty_1y_return'     => $niftyChange,
        'context'             => $contextLabel,
        'advice'              => $advice,
        'color'               => $color ?? '#d97706',
        'market_rule'         => 'Market crash mein SIP pause karna sabse buri galti hai. Cheap NAV mein zyada units milti hain.',
        'covid_lesson'        => 'March 2020 mein jinhone SIP continue kiya, unhe next 12 months mein 70%+ return mila. Jo pause kar gaye, woh ye opportunity miss kar gaye.',
        'scenarios'           => [
            ['market' => 'Nifty down 10%',  'action' => 'Continue SIP',          'reason' => 'Normal correction, not a crash'],
            ['market' => 'Nifty down 20%',  'action' => 'Increase SIP if possible','reason' => 'Market is cheap, buy more units'],
            ['market' => 'Nifty down 30%+', 'action' => 'Absolutely continue SIP', 'reason' => 'Deep crash = maximum buying opportunity'],
            ['market' => 'Nifty up 30%+',   'action' => 'Continue SIP, avoid lumpsum', 'reason' => 'Market expensive, let SIP average'],
        ],
    ];
}

function market_context_label(?float $change, ?float $drawdown): array
{
    if ($change === null) {
        return ['Market data unavailable', 'Continue SIP normally.', '#6b7280'];
    }
    return match (true) {
        $change <= -30 => ['Bear Market / Crash 🔴', '🚨 DO NOT PAUSE! This is the BEST time to continue SIP. Cheap NAVs mean more units per rupee. Consider increasing SIP amount if possible.', '#dc2626'],
        $change <= -20 => ['Deep Correction 🟠',     '🚫 Do not pause SIP. Market has corrected significantly — this is when SIP works best. Continue or increase.', '#ea580c'],
        $change <= -10 => ['Correction 🟡',           '⚠️ Market in correction. SIP should continue uninterrupted. No action needed.', '#d97706'],
        $change <= 10  => ['Sideways Market 🟢',      '✅ Normal market conditions. Continue SIP as planned.', '#65a30d'],
        $change <= 25  => ['Bull Market 🟢',           '✅ Market doing well. Continue SIP. Avoid large lumpsums.', '#16a34a'],
        default        => ['High Valuation 🟠',        '⚠️ Market at elevated levels. SIP is fine. Avoid lumpsum. Consider booking some profits on overweight positions.', '#ea580c'],
    };
}

// ═══════════════════════════════════════════════════════════════════════════
// EMERGENCY CHECK
// ═══════════════════════════════════════════════════════════════════════════
function emergency_check(PDO $db, int $userId, int $portfolioId, float $withdrawAmt): array
{
    if ($withdrawAmt <= 0) return ['error' => 'withdrawal_amount required'];

    // Get total active SIP monthly outflow
    $totalSipMonthly = 0;
    try {
        $pWhere = $portfolioId > 0 ? 'AND s.portfolio_id = ?' : 'AND p.user_id = ?';
        $pParam = $portfolioId > 0 ? $portfolioId : $userId;
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(s.sip_amount), 0) AS total
            FROM sip_tracker s JOIN portfolios p ON p.id = s.portfolio_id
            WHERE s.is_active = 1 $pWhere
        ");
        $stmt->execute([$pParam]);
        $totalSipMonthly = (float)$stmt->fetchColumn();
    } catch (Exception $e) {}

    // Compare withdrawal to SIP
    $sipMonthsEquivalent = $totalSipMonthly > 0 ? round($withdrawAmt / $totalSipMonthly, 1) : null;

    $recommendation = 'continue';
    $message = 'Withdrawal amount is small relative to your SIP commitments. Continue SIP normally.';

    if ($sipMonthsEquivalent !== null && $sipMonthsEquivalent >= 3) {
        $recommendation = 'pause_consider';
        $message = "The withdrawal (₹" . number_format($withdrawAmt, 0) . ") equals ~{$sipMonthsEquivalent} months of your SIP. " .
                   "Consider pausing SIP for 2-3 months to rebuild emergency fund, but resume ASAP.";
    } elseif ($withdrawAmt >= 100000) {
        $recommendation = 'partial_pause';
        $message = "Large withdrawal detected. Consider pausing lowest-priority SIPs for 1-2 months rather than all SIPs.";
    }

    return [
        'withdrawal_amount'      => $withdrawAmt,
        'total_sip_monthly'      => $totalSipMonthly,
        'sip_months_equivalent'  => $sipMonthsEquivalent,
        'recommendation'         => $recommendation,
        'message'                => $message,
        'priority_to_pause'      => 'Pause step-up/extra SIPs first. Keep goal-linked SIPs running.',
        'emergency_fund_rule'    => '3-6 months of expenses should be in liquid fund before investing in equity SIPs.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// RESUME ANALYSIS
// ═══════════════════════════════════════════════════════════════════════════
function get_resume_analysis(PDO $db, int $userId, int $portfolioId): array
{
    // Find paused SIPs
    $paused = [];
    try {
        $pWhere = $portfolioId > 0 ? 'AND s.portfolio_id = ?' : 'AND p.user_id = ?';
        $pParam = $portfolioId > 0 ? $portfolioId : $userId;
        $stmt = $db->prepare("
            SELECT s.id, s.fund_id, s.sip_amount, s.paused_date, s.pause_reason, s.next_review_date,
                   f.scheme_name, f.category, f.returns_1y
            FROM sip_tracker s
            JOIN funds f ON f.id = s.fund_id
            JOIN portfolios p ON p.id = s.portfolio_id
            WHERE s.is_active = 0 AND s.paused_date IS NOT NULL $pWhere
        ");
        $stmt->execute([$pParam]);
        $paused = $stmt->fetchAll();
    } catch (Exception $e) {}

    if (empty($paused)) {
        return ['paused_count' => 0, 'message' => 'No paused SIPs found. All SIPs are active. 🎉'];
    }

    $today = new DateTime();
    $result = array_map(function ($s) use ($today) {
        $pausedDate = new DateTime($s['paused_date']);
        $daysPaused = (int)$today->diff($pausedDate)->days;
        $approxLoss = (float)$s['sip_amount'] * floor($daysPaused / 30) * 0.012; // rough 12% annualized loss

        return [
            'sip_id'         => (int)$s['id'],
            'scheme_name'    => $s['scheme_name'],
            'amount'         => (float)$s['sip_amount'],
            'paused_date'    => $s['paused_date'],
            'days_paused'    => $daysPaused,
            'months_paused'  => floor($daysPaused / 30),
            'approx_corpus_loss' => round($approxLoss, 0),
            'next_review'    => $s['next_review_date'],
            'urgency'        => $daysPaused >= 90 ? 'high' : ($daysPaused >= 30 ? 'medium' : 'low'),
            'action'         => "Resume {$s['scheme_name']} immediately to stop compounding loss.",
        ];
    }, $paused);

    return [
        'paused_count'    => count($paused),
        'paused_sips'     => $result,
        'total_monthly_paused' => array_sum(array_column($paused, 'sip_amount')),
        'message'         => count($paused) . ' SIP(s) currently paused. Resuming soon minimizes compounding loss.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// COVID HISTORICAL ANALYSIS
// ═══════════════════════════════════════════════════════════════════════════
function covid_pause_analysis(): array
{
    return [
        'title'    => '2020 COVID — Pause vs Continue Analysis',
        'scenario' => [
            'crash_date'    => '2020-03-23',
            'nifty_low'     => 7511,
            'nifty_pre'     => 12362,
            'crash_pct'     => -39.3,
            'recovery_date' => '2020-11-05',
            'nifty_recovery'=> 12631,
            'months_to_recover' => 7.5,
        ],
        'comparison' => [
            'paused' => [
                'action'  => 'Paused SIP March-June 2020 (3 months)',
                'missed'  => '3 SIPs at COVID bottom — cheapest NAVs in a decade',
                'return'  => 'Missed ~60% rise from March lows',
                'verdict' => 'Major opportunity missed',
            ],
            'continued' => [
                'action'  => 'Continued SIP through crash',
                'benefit' => 'Bought units at 30-40% discount for 3-4 months',
                'return'  => '70%+ CAGR over next 12 months from March 2020 SIPs',
                'verdict' => '✅ Best decision in that period',
            ],
            'increased' => [
                'action'  => 'Increased SIP amount during crash + small lumpsum',
                'benefit' => 'Maximized cheap unit accumulation',
                'return'  => '80%+ CAGR over next 12 months',
                'verdict' => '🏆 Optimal strategy',
            ],
        ],
        'lesson' => 'Crashes are when SIP earns its maximum benefit. NAV at crash = cheap units = high future corpus. Never pause SIP during market downturns.',
    ];
}

function pause_rules(): array
{
    return [
        'pause_ok' => [
            'Genuine medical emergency with no liquid funds',
            'Job loss — rebuild emergency fund first',
            'SIP amount now exceeds affordable EMI',
            'Fund consistently underperforming category for 2+ years (switch instead)',
        ],
        'do_not_pause' => [
            'Market crash or correction — worst time to pause',
            'News-driven panic',
            'Short-term cash crunch <30 days — take personal loan instead',
            'Planning to "retime" market entry — timing never works',
        ],
        'alternatives_to_pause' => [
            'Reduce SIP amount by 50% instead of stopping',
            'Pause step-up SIPs, keep base SIP running',
            'Pause non-goal SIPs, continue goal-linked ones',
            'Liquidate FD/liquid fund before pausing equity SIP',
        ],
    ];
}
