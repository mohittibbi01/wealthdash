<?php
/**
 * WealthDash — MF Advanced Metrics API
 *
 * t263 — Volatility Meter (Standard Deviation visual gauge)
 * t264 — Sortino Ratio (downside risk adjusted return)
 * t265 — Calmar Ratio (Return per unit of Max Drawdown)
 * t266 — Fund Age Analysis (inception date, since-inception CAGR)
 * t270 — Momentum Score (weighted recent returns 0-100)
 *
 * GET /api/mutual_funds/mf_metrics.php
 *   ?action=volatility       &fund_id=X
 *   ?action=sortino          &fund_id=X
 *   ?action=calmar           &fund_id=X
 *   ?action=fund_age         &fund_id=X
 *   ?action=momentum         &fund_id=X
 *   ?action=all              &fund_id=X   ← all metrics in one call
 *   ?action=bulk_calmar                   ← admin: recalculate all (no fund_id needed)
 *   ?action=bulk_momentum                 ← admin: recalculate all
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
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

try {
    $db     = DB::conn();
    $action = $_GET['action'] ?? 'all';
    $fundId = (int)($_GET['fund_id'] ?? 0);

    // ── Bulk admin actions ────────────────────────────────────────────────
    if ($action === 'bulk_calmar') {
        if ($currentUser['role'] !== 'admin') {
            ob_clean(); http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin only']); exit;
        }
        $result = bulk_calmar($db);
        ob_clean();
        echo json_encode(['success' => true] + $result);
        exit;
    }
    if ($action === 'bulk_momentum') {
        if ($currentUser['role'] !== 'admin') {
            ob_clean(); http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin only']); exit;
        }
        $result = bulk_momentum($db);
        ob_clean();
        echo json_encode(['success' => true] + $result);
        exit;
    }

    if (!$fundId) throw new InvalidArgumentException('fund_id required');

    // ── Fetch fund base info ──────────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT f.id, f.scheme_name, f.scheme_code, f.category, f.option_type,
               f.latest_nav, f.inception_nav, f.inception_date,
               f.returns_1y, f.returns_3y, f.returns_5y,
               f.max_drawdown, f.sharpe_ratio, f.sortino_ratio, f.calmar_ratio,
               f.aum_crore, f.expense_ratio,
               COALESCE(fh.short_name, fh.name) AS fund_house
        FROM funds f
        LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
        WHERE f.id = ?
    ");
    $stmt->execute([$fundId]);
    $fund = $stmt->fetch();
    if (!$fund) throw new RuntimeException('Fund not found');

    // ── NAV History (up to 5 years) for calculations ──────────────────────
    $navHistory = fetch_nav_history($db, $fundId, 1825); // 5yr = 1825 days

    $response = ['success' => true, 'fund_id' => $fundId, 'scheme_name' => $fund['scheme_name']];

    switch ($action) {
        case 'volatility': $response += compute_volatility($fund, $navHistory); break;
        case 'sortino':    $response += compute_sortino($fund, $navHistory);    break;
        case 'calmar':     $response += compute_calmar($fund, $navHistory);     break;
        case 'fund_age':   $response += compute_fund_age($fund, $navHistory);   break;
        case 'momentum':   $response += compute_momentum($fund, $navHistory);   break;
        case 'all':
        default:
            $response += compute_volatility($fund, $navHistory);
            $response += compute_sortino($fund, $navHistory);
            $response += compute_calmar($fund, $navHistory);
            $response += compute_fund_age($fund, $navHistory);
            $response += compute_momentum($fund, $navHistory);
            break;
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPER — Fetch NAV history
// ═══════════════════════════════════════════════════════════════════════════
function fetch_nav_history(PDO $db, int $fundId, int $days = 365): array
{
    try {
        $stmt = $db->prepare("
            SELECT nav_date, nav
            FROM nav_history
            WHERE fund_id = ?
              AND nav_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY nav_date ASC
        ");
        $stmt->execute([$fundId, $days]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// t263 — VOLATILITY METER
// ═══════════════════════════════════════════════════════════════════════════
function compute_volatility(array $fund, array $navHistory): array
{
    // Calculate monthly returns from nav_history
    $monthly = monthly_returns($navHistory);

    if (count($monthly) < 3) {
        return ['volatility' => null, 'volatility_label' => null, 'volatility_color' => null];
    }

    $n    = count($monthly);
    $mean = array_sum($monthly) / $n;
    $variance = array_sum(array_map(fn($r) => ($r - $mean) ** 2, $monthly)) / max(1, $n - 1);
    $monthlyStdDev = sqrt($variance);

    // Annualize: monthly_std_dev × √12
    $annualVol = round($monthlyStdDev * sqrt(12) * 100, 2);

    // Category average (rough proxy by broad type)
    $cat = strtolower($fund['category'] ?? '');
    $catAvgVol = match (true) {
        str_contains($cat, 'liquid') || str_contains($cat, 'overnight') => 0.5,
        str_contains($cat, 'debt') || str_contains($cat, 'gilt')        => 4.0,
        str_contains($cat, 'hybrid') || str_contains($cat, 'balanced')  => 12.0,
        str_contains($cat, 'index')                                     => 16.0,
        str_contains($cat, 'large cap')                                 => 16.0,
        str_contains($cat, 'flexi') || str_contains($cat, 'multi')      => 18.0,
        str_contains($cat, 'mid cap')                                   => 20.0,
        str_contains($cat, 'small cap')                                 => 25.0,
        str_contains($cat, 'sectoral') || str_contains($cat, 'thematic')=> 28.0,
        default                                                         => 18.0,
    };

    // Label & color
    [$label, $color, $level] = match (true) {
        $annualVol < 6   => ['Very Low',  '#16a34a', 1],
        $annualVol < 12  => ['Low',       '#65a30d', 2],
        $annualVol < 18  => ['Moderate',  '#d97706', 3],
        $annualVol < 25  => ['High',      '#ea580c', 4],
        default          => ['Very High', '#dc2626', 5],
    };

    return [
        'volatility' => [
            'annual_std_dev'     => $annualVol,
            'monthly_std_dev'    => round($monthlyStdDev * 100, 3),
            'label'              => $label,
            'color'              => $color,
            'level'              => $level,          // 1-5 for gauge
            'vs_category_avg'    => round($annualVol - $catAvgVol, 2),
            'category_avg_vol'   => $catAvgVol,
            'data_months'        => $n,
            'interpretation'     => vol_interpretation($label, $annualVol, $catAvgVol),
        ],
    ];
}

function vol_interpretation(string $label, float $vol, float $catAvg): string
{
    $diff = $vol - $catAvg;
    $cmp  = $diff > 2 ? 'higher than' : ($diff < -2 ? 'lower than' : 'in line with');
    return "This fund has $label volatility ({$vol}% annualized std dev), $cmp its category average ({$catAvg}%).";
}

// ═══════════════════════════════════════════════════════════════════════════
// t264 — SORTINO RATIO
// ═══════════════════════════════════════════════════════════════════════════
function compute_sortino(array $fund, array $navHistory): array
{
    // Use DB value if already computed by cron
    if (isset($fund['sortino_ratio']) && $fund['sortino_ratio'] !== null) {
        $sortino = (float)$fund['sortino_ratio'];
        return ['sortino' => [
            'ratio'      => round($sortino, 3),
            'source'     => 'db',
            'label'      => sortino_label($sortino),
            'interpretation' => sortino_interp($sortino),
        ]];
    }

    // Calculate on-the-fly
    $monthly = monthly_returns($navHistory);
    if (count($monthly) < 6) {
        return ['sortino' => null];
    }

    $rf   = 0.055 / 12; // ~5.5% annual risk-free, monthly
    $excess = array_map(fn($r) => $r - $rf, $monthly);
    $avgExcess = array_sum($excess) / count($excess);

    // Downside deviation: only negative excess returns
    $negReturns = array_filter($monthly, fn($r) => $r < $rf);
    if (empty($negReturns)) {
        return ['sortino' => ['ratio' => 999, 'label' => 'Excellent', 'source' => 'calc',
            'interpretation' => 'No negative periods found — exceptionally consistent returns.']];
    }
    $downDev = sqrt(array_sum(array_map(fn($r) => ($r - $rf) ** 2, $negReturns)) / count($monthly));

    $sortino = $downDev > 0 ? round(($avgExcess * 12) / ($downDev * sqrt(12)), 3) : null;

    return ['sortino' => [
        'ratio'          => $sortino,
        'source'         => 'calc',
        'label'          => $sortino !== null ? sortino_label($sortino) : null,
        'interpretation' => $sortino !== null ? sortino_interp($sortino) : 'Insufficient data',
        'data_months'    => count($monthly),
    ]];
}

function sortino_label(float $v): string
{
    return match (true) {
        $v >= 2.0 => 'Excellent',
        $v >= 1.0 => 'Good',
        $v >= 0.5 => 'Acceptable',
        $v >= 0   => 'Below Average',
        default   => 'Poor',
    };
}
function sortino_interp(float $v): string
{
    $label = sortino_label($v);
    return "Sortino Ratio of {$v} — $label. Unlike Sharpe, only downside volatility is penalized. Higher is better; >1.0 is generally considered good for equity funds.";
}

// ═══════════════════════════════════════════════════════════════════════════
// t265 — CALMAR RATIO
// ═══════════════════════════════════════════════════════════════════════════
function compute_calmar(array $fund, array $navHistory): array
{
    // Use DB value if available
    if (isset($fund['calmar_ratio']) && $fund['calmar_ratio'] !== null) {
        $calmar = (float)$fund['calmar_ratio'];
        return ['calmar' => [
            'ratio'          => round($calmar, 3),
            'source'         => 'db',
            'label'          => calmar_label($calmar),
            'interpretation' => calmar_interp($calmar, null, null),
        ]];
    }

    // On-the-fly: Calmar = Annualized Return / |Max Drawdown|
    $ret3y  = isset($fund['returns_3y']) ? (float)$fund['returns_3y'] : null;
    $maxDD  = isset($fund['max_drawdown']) ? abs((float)$fund['max_drawdown']) : null;

    // Calculate max drawdown from nav history if not in DB
    if ($maxDD === null && count($navHistory) >= 20) {
        $maxDD = abs(calc_max_drawdown($navHistory));
    }

    if ($ret3y === null || $maxDD === null || $maxDD < 0.001) {
        return ['calmar' => null];
    }

    $calmar = round($ret3y / $maxDD, 3);

    return ['calmar' => [
        'ratio'            => $calmar,
        'source'           => 'calc',
        'annualized_return'=> $ret3y,
        'max_drawdown_pct' => round($maxDD, 2),
        'label'            => calmar_label($calmar),
        'interpretation'   => calmar_interp($calmar, $ret3y, $maxDD),
    ]];
}

function calc_max_drawdown(array $navHistory): float
{
    $navs = array_column($navHistory, 'nav');
    $peak = PHP_FLOAT_MIN; $maxDD = 0;
    foreach ($navs as $nav) {
        $nav   = (float)$nav;
        $peak  = max($peak, $nav);
        $dd    = $peak > 0 ? ($nav - $peak) / $peak * 100 : 0;
        $maxDD = min($maxDD, $dd);
    }
    return $maxDD; // negative number
}

function calmar_label(float $v): string
{
    return match (true) {
        $v >= 1.5 => 'Excellent',
        $v >= 0.8 => 'Good',
        $v >= 0.4 => 'Average',
        $v >= 0   => 'Below Average',
        default   => 'Poor',
    };
}
function calmar_interp(float $v, ?float $ret, ?float $dd): string
{
    $label = calmar_label($v);
    $base  = "Calmar Ratio of {$v} — $label. ";
    if ($ret !== null && $dd !== null) {
        $base .= "Earned {$ret}% 3Y CAGR for {$dd}% max drawdown. ";
    }
    $base .= "Higher Calmar = better return per unit of drawdown risk. >1.0 is excellent.";
    return $base;
}

// ═══════════════════════════════════════════════════════════════════════════
// t266 — FUND AGE ANALYSIS
// ═══════════════════════════════════════════════════════════════════════════
function compute_fund_age(array $fund, array $navHistory): array
{
    $inceptionDate = $fund['inception_date'] ?? null;

    // Fallback: use earliest NAV history record
    if (!$inceptionDate && !empty($navHistory)) {
        $inceptionDate = $navHistory[0]['nav_date'];
    }

    if (!$inceptionDate) {
        return ['fund_age' => null];
    }

    $inception = new DateTime($inceptionDate);
    $now       = new DateTime();
    $diff      = $inception->diff($now);
    $ageYears  = round($diff->y + $diff->m / 12, 1);

    // Since inception CAGR
    $inceptionNav = !empty($fund['inception_nav']) ? (float)$fund['inception_nav'] : null;
    if ($inceptionNav === null && !empty($navHistory)) {
        $inceptionNav = (float)$navHistory[0]['nav'];
    }
    $currentNav = !empty($fund['latest_nav']) ? (float)$fund['latest_nav'] : null;
    if ($currentNav === null && !empty($navHistory)) {
        $currentNav = (float)end($navHistory)['nav'];
    }

    $sinceInceptionCagr = null;
    if ($inceptionNav && $currentNav && $ageYears >= 1) {
        $sinceInceptionCagr = round((($currentNav / $inceptionNav) ** (1 / $ageYears) - 1) * 100, 2);
    }

    // Badge
    $badge = match (true) {
        $ageYears >= 15 => '15+ yr track record',
        $ageYears >= 10 => '10+ yr track record',
        $ageYears >= 5  => '5+ yr track record',
        $ageYears >= 3  => '3+ yr track record',
        $ageYears >= 1  => '1+ yr track record',
        default         => 'New fund (<1 yr)',
    };

    $reliability = match (true) {
        $ageYears >= 10 => 'High — multiple market cycles tested',
        $ageYears >= 5  => 'Moderate — seen at least one full cycle',
        $ageYears >= 3  => 'Limited — needs more track record',
        default         => 'Insufficient — too new to evaluate',
    };

    // Performance by age bucket (category benchmark proxy)
    $ageBucket = match (true) {
        $ageYears < 1  => '<1yr',
        $ageYears < 3  => '1-3yr',
        $ageYears < 5  => '3-5yr',
        $ageYears < 10 => '5-10yr',
        default        => '10+yr',
    };

    return ['fund_age' => [
        'inception_date'       => $inceptionDate,
        'age_years'            => $ageYears,
        'age_bucket'           => $ageBucket,
        'badge'                => $badge,
        'reliability'          => $reliability,
        'since_inception_cagr' => $sinceInceptionCagr,
        'inception_nav'        => $inceptionNav,
        'current_nav'          => $currentNav,
        'interpretation'       => "Fund is {$ageYears} years old ({$badge}). $reliability." .
            ($sinceInceptionCagr ? " Since inception CAGR: {$sinceInceptionCagr}%." : ''),
    ]];
}

// ═══════════════════════════════════════════════════════════════════════════
// t270 — MOMENTUM SCORE
// ═══════════════════════════════════════════════════════════════════════════
function compute_momentum(array $fund, array $navHistory): array
{
    // Weighted momentum: 40% 1M + 30% 3M + 20% 6M + 10% 12M
    $navs = array_column($navHistory, 'nav');
    $dates = array_column($navHistory, 'nav_date');
    $n = count($navs);

    if ($n < 30) {
        return ['momentum' => null];
    }

    // Use DB returns if available
    $r1y = isset($fund['returns_1y']) && $fund['returns_1y'] !== null ? (float)$fund['returns_1y'] : null;

    // Calculate returns from nav history
    $ret1m  = nav_period_return($navs, $dates, 30);
    $ret3m  = nav_period_return($navs, $dates, 90);
    $ret6m  = nav_period_return($navs, $dates, 180);
    $ret12m = $r1y ?? nav_period_return($navs, $dates, 365);

    if ($ret1m === null || $ret3m === null || $ret6m === null || $ret12m === null) {
        return ['momentum' => null];
    }

    // Raw momentum score (weighted)
    $rawScore = 0.40 * $ret1m + 0.30 * $ret3m + 0.20 * $ret6m + 0.10 * $ret12m;

    // Normalize to 0-100: assume range -30% to +60% typical for equity
    $normalized = max(0, min(100, round(($rawScore + 30) / 90 * 100, 1)));

    $label = match (true) {
        $normalized >= 75 => 'Strong Momentum',
        $normalized >= 55 => 'Moderate Momentum',
        $normalized >= 35 => 'Neutral',
        $normalized >= 20 => 'Weak Momentum',
        default           => 'Negative Momentum',
    };
    $color = match (true) {
        $normalized >= 75 => '#16a34a',
        $normalized >= 55 => '#65a30d',
        $normalized >= 35 => '#d97706',
        $normalized >= 20 => '#ea580c',
        default           => '#dc2626',
    };

    return ['momentum' => [
        'score'             => $normalized,
        'raw_score'         => round($rawScore, 2),
        'label'             => $label,
        'color'             => $color,
        'returns_1m'        => round($ret1m, 2),
        'returns_3m'        => round($ret3m, 2),
        'returns_6m'        => round($ret6m, 2),
        'returns_12m'       => round($ret12m, 2),
        'weights'           => ['1m' => 40, '3m' => 30, '6m' => 20, '12m' => 10],
        'interpretation'    => "$label — Score {$normalized}/100. " .
            "1M: {$ret1m}%, 3M: {$ret3m}%, 6M: {$ret6m}%, 12M: {$ret12m}%. " .
            "Caveat: Past momentum is not a guarantee of future performance.",
        'caveat'            => 'Past momentum is not a guarantee of future performance.',
    ]];
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Convert daily NAV data to monthly return series
 * Returns array of decimal returns (e.g., 0.015 = 1.5%)
 */
function monthly_returns(array $navHistory): array
{
    if (empty($navHistory)) return [];

    // Group by year-month, take last nav of each month
    $byMonth = [];
    foreach ($navHistory as $row) {
        $ym = substr($row['nav_date'], 0, 7);
        $byMonth[$ym] = (float)$row['nav'];
    }
    ksort($byMonth);
    $navs = array_values($byMonth);

    $returns = [];
    for ($i = 1; $i < count($navs); $i++) {
        if ($navs[$i - 1] > 0) {
            $returns[] = ($navs[$i] - $navs[$i - 1]) / $navs[$i - 1];
        }
    }
    return $returns;
}

/**
 * Calculate return over N days from nav history
 */
function nav_period_return(array $navs, array $dates, int $days): ?float
{
    if (empty($navs)) return null;
    $endNav   = (float)end($navs);
    $cutoff   = date('Y-m-d', strtotime("-{$days} days"));

    // Find first nav on or after cutoff
    foreach ($dates as $i => $d) {
        if ($d >= $cutoff) {
            $startNav = (float)$navs[$i];
            if ($startNav > 0) {
                return round(($endNav - $startNav) / $startNav * 100, 2);
            }
        }
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// BULK OPS (admin cron triggers)
// ═══════════════════════════════════════════════════════════════════════════

function bulk_calmar(PDO $db): array
{
    // Ensure calmar_ratio column exists
    try { $db->query("SELECT calmar_ratio FROM funds LIMIT 1"); }
    catch (Exception $e) {
        $db->exec("ALTER TABLE funds ADD COLUMN calmar_ratio DECIMAL(10,4) DEFAULT NULL AFTER sortino_ratio");
    }

    $funds = $db->query("
        SELECT f.id, f.returns_3y, f.max_drawdown, f.inception_nav, f.inception_date, f.latest_nav
        FROM funds f WHERE f.is_active = 1
    ")->fetchAll();

    $stmt = $db->prepare("UPDATE funds SET calmar_ratio = ? WHERE id = ?");
    $updated = 0; $skipped = 0;

    foreach ($funds as $fund) {
        $ret   = isset($fund['returns_3y'])   ? (float)$fund['returns_3y']   : null;
        $maxDD = isset($fund['max_drawdown'])  ? abs((float)$fund['max_drawdown']) : null;

        if ($ret === null || $maxDD === null || $maxDD < 0.001) { $skipped++; continue; }

        $calmar = round($ret / $maxDD, 4);
        $stmt->execute([$calmar, $fund['id']]);
        $updated++;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'total' => count($funds)];
}

function bulk_momentum(PDO $db): array
{
    // Ensure momentum_score column exists
    try { $db->query("SELECT momentum_score FROM funds LIMIT 1"); }
    catch (Exception $e) {
        $db->exec("ALTER TABLE funds ADD COLUMN momentum_score DECIMAL(5,2) DEFAULT NULL");
    }

    $funds = $db->query("
        SELECT id, returns_1y FROM funds WHERE is_active = 1
    ")->fetchAll();

    $stmt = $db->prepare("UPDATE funds SET momentum_score = ? WHERE id = ?");
    $updated = 0; $skipped = 0;

    foreach ($funds as $fund) {
        // Get recent NAV history for this fund
        $navRows = fetch_nav_history($db, (int)$fund['id'], 400);
        if (count($navRows) < 30) { $skipped++; continue; }

        $navs  = array_column($navRows, 'nav');
        $dates = array_column($navRows, 'nav_date');
        $r1y   = isset($fund['returns_1y']) ? (float)$fund['returns_1y'] : null;

        $ret1m  = nav_period_return($navs, $dates, 30);
        $ret3m  = nav_period_return($navs, $dates, 90);
        $ret6m  = nav_period_return($navs, $dates, 180);
        $ret12m = $r1y ?? nav_period_return($navs, $dates, 365);

        if ($ret1m === null || $ret3m === null) { $skipped++; continue; }

        $raw  = 0.40 * ($ret1m ?? 0) + 0.30 * ($ret3m ?? 0)
              + 0.20 * ($ret6m ?? 0) + 0.10 * ($ret12m ?? 0);
        $norm = max(0, min(100, round(($raw + 30) / 90 * 100, 2)));

        $stmt->execute([$norm, $fund['id']]);
        $updated++;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'total' => count($funds)];
}
