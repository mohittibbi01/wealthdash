<?php
/**
 * WealthDash — t262: Rolling Returns Chart
 *
 * Calculates 1Y/3Y/5Y rolling return windows from nav_history.
 * Shows best/worst/median rolling periods.
 * Consistency insight: "87% of 1Y windows were positive"
 *
 * GET /api/mutual_funds/rolling_returns.php
 *   ?fund_id=X
 *   &period=1y|3y|5y          (rolling window, default 1y)
 *   &compare_fund_id=Y        (optional: second fund overlay)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

require_auth();

set_exception_handler(function (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
});

try {
    $db            = DB::conn();
    $fundId        = (int)($_GET['fund_id'] ?? 0);
    $period        = $_GET['period'] ?? '1y';
    $compareFundId = (int)($_GET['compare_fund_id'] ?? 0);

    if (!$fundId) throw new InvalidArgumentException('fund_id required');

    $periodDays = match ($period) {
        '3y' => 1095,
        '5y' => 1825,
        default => 365, // 1y
    };

    // Fetch fund info
    $stmt = $db->prepare("SELECT id, scheme_name, category FROM funds WHERE id = ?");
    $stmt->execute([$fundId]);
    $fund = $stmt->fetch();
    if (!$fund) throw new RuntimeException('Fund not found');

    // Need at least 2x the period of data
    $totalDays = $periodDays * 2 + 90;
    $navData   = fetch_nav($db, $fundId, $totalDays);

    $rolling = calc_rolling_returns($navData, $periodDays);

    $response = [
        'success'       => true,
        'fund_id'       => $fundId,
        'scheme_name'   => $fund['scheme_name'],
        'category'      => $fund['category'],
        'period'        => $period,
        'period_days'   => $periodDays,
        'rolling'       => $rolling,
    ];

    // Compare fund
    if ($compareFundId && $compareFundId !== $fundId) {
        $stmt2 = $db->prepare("SELECT id, scheme_name FROM funds WHERE id = ?");
        $stmt2->execute([$compareFundId]);
        $fund2 = $stmt2->fetch();
        if ($fund2) {
            $navData2 = fetch_nav($db, $compareFundId, $totalDays);
            $rolling2 = calc_rolling_returns($navData2, $periodDays);
            $response['compare'] = [
                'fund_id'     => $compareFundId,
                'scheme_name' => $fund2['scheme_name'],
                'rolling'     => $rolling2,
            ];
        }
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function fetch_nav(PDO $db, int $fundId, int $days): array
{
    $stmt = $db->prepare("
        SELECT nav_date, nav FROM nav_history
        WHERE fund_id = ? AND nav_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ORDER BY nav_date ASC
    ");
    $stmt->execute([$fundId, $days]);
    return $stmt->fetchAll();
}

function calc_rolling_returns(array $navData, int $periodDays): array
{
    if (count($navData) < 30) {
        return ['error' => 'Insufficient NAV history', 'data_points' => count($navData)];
    }

    $dates = array_column($navData, 'nav_date');
    $navs  = array_column($navData, 'nav');
    $n     = count($navs);

    $returns = [];

    for ($i = 0; $i < $n; $i++) {
        // Find NAV ~periodDays ago
        $targetDate = date('Y-m-d', strtotime($dates[$i] . " -{$periodDays} days"));
        $startIdx   = find_nearest_idx($dates, $targetDate, $i - 1);

        if ($startIdx === null || $startIdx >= $i) continue;
        if ((float)$navs[$startIdx] <= 0) continue;

        $retPct = round(((float)$navs[$i] - (float)$navs[$startIdx]) / (float)$navs[$startIdx] * 100, 2);
        // Annualize
        $actualDays = (new DateTime($dates[$startIdx]))->diff(new DateTime($dates[$i]))->days;
        if ($actualDays < $periodDays * 0.8) continue; // skip if too short

        $cagr = $actualDays > 0
            ? round((((float)$navs[$i] / (float)$navs[$startIdx]) ** (365 / $actualDays) - 1) * 100, 2)
            : $retPct;

        $returns[] = [
            'date'       => $dates[$i],
            'start_date' => $dates[$startIdx],
            'return_pct' => $cagr,
            'end_nav'    => (float)$navs[$i],
            'start_nav'  => (float)$navs[$startIdx],
        ];
    }

    if (empty($returns)) {
        return ['error' => 'Insufficient overlapping data for rolling calculation'];
    }

    $values   = array_column($returns, 'return_pct');
    sort($values);

    $n        = count($values);
    $median   = $n % 2 === 0 ? ($values[$n/2-1] + $values[$n/2]) / 2 : $values[(int)($n/2)];
    $positive = count(array_filter($values, fn($v) => $v > 0));
    $positivePct = round($positive / $n * 100, 1);

    // Chart data: downsample to max 100 points for performance
    $chartData = $returns;
    if (count($returns) > 100) {
        $step = (int)ceil(count($returns) / 100);
        $chartData = array_values(array_filter($returns, fn($_, $k) => $k % $step === 0, ARRAY_FILTER_USE_BOTH));
    }

    return [
        'data_points'      => $n,
        'best'             => ['return' => max($values), 'date' => $returns[array_search(max($values), $values)]['date'] ?? null],
        'worst'            => ['return' => min($values), 'date' => $returns[array_search(min($values), $values)]['date'] ?? null],
        'median'           => round($median, 2),
        'average'          => round(array_sum($values) / $n, 2),
        'positive_windows' => $positive,
        'total_windows'    => $n,
        'positive_pct'     => $positivePct,
        'consistency_insight' => "{$positivePct}% of rolling windows gave positive returns. " .
            ($positivePct >= 90 ? "Highly consistent fund." :
            ($positivePct >= 75 ? "Good consistency." :
            ($positivePct >= 60 ? "Moderate consistency." : "High variability — timing matters."))),
        'chart_data'       => $chartData,
        'percentiles'      => [
            'p10' => round($values[(int)($n * 0.10)], 2),
            'p25' => round($values[(int)($n * 0.25)], 2),
            'p50' => round($median, 2),
            'p75' => round($values[(int)($n * 0.75)], 2),
            'p90' => round($values[(int)($n * 0.90)], 2),
        ],
    ];
}

function find_nearest_idx(array $dates, string $target, int $maxIdx): ?int
{
    $best = null;
    $bestDiff = PHP_INT_MAX;
    $limit = min($maxIdx, count($dates) - 1);

    for ($i = 0; $i <= $limit; $i++) {
        $diff = abs(strtotime($dates[$i]) - strtotime($target));
        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $best = $i;
        } elseif ($diff > $bestDiff) {
            break; // dates are sorted, getting farther
        }
    }

    // Only accept if within 30 days of target
    if ($best !== null && $bestDiff <= 30 * 86400) return $best;
    return null;
}
