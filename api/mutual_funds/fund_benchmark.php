<?php
/**
 * WealthDash — Fund Benchmark Comparison API (tv11)
 *
 * Enhanced benchmark_proxy.php to support:
 *   1. Per-fund benchmark_index assignment (stored in funds.benchmark_index)
 *   2. Return comparison: fund NAV vs benchmark index over 1M/3M/6M/1Y/3Y/5Y
 *   3. Alpha calculation (fund return - benchmark return)
 *   4. Category default benchmarks
 *
 * Supported benchmarks (stooq.com, no API key):
 *   Nifty 50 (^NSEI), Nifty Midcap 150 (^NSMIDCP), Nifty Smallcap 250 (^NSSC),
 *   Sensex (^BSESN), Nifty Next 50 (^NSMIDCP)
 *
 * Actions:
 *   GET  ?action=benchmark_compare&fund_id=X&period=1Y   → fund vs benchmark
 *   GET  ?action=benchmark_defaults                       → category → benchmark mapping
 *   POST action=benchmark_assign&fund_id=X&benchmark=^NSEI → assign benchmark to fund
 *   GET  ?action=benchmark_alpha&fund_id=X               → alpha across periods
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$db          = DB::conn();

// ── Ensure benchmark_index column exists ──────────────────────────────────
try { $db->exec("ALTER TABLE `funds` ADD COLUMN IF NOT EXISTS `benchmark_index` VARCHAR(20) DEFAULT NULL COMMENT 'Index symbol: ^NSEI, ^BSESN, ^NSMIDCP, etc.'"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE `funds` ADD INDEX IF NOT EXISTS `idx_funds_benchmark` (`benchmark_index`)"); } catch(Exception $e) {}

// ── Constants ─────────────────────────────────────────────────────────────
const BENCHMARKS = [
    '^NSEI'    => ['name' => 'Nifty 50',          'stooq' => '^nsei',    'label' => 'Nifty 50'],
    '^BSESN'   => ['name' => 'BSE Sensex',         'stooq' => '^bsesn',   'label' => 'Sensex'],
    '^NSMIDCP' => ['name' => 'Nifty Midcap 150',  'stooq' => '^nsmidcp', 'label' => 'Nifty Midcap 150'],
    '^NSSC250' => ['name' => 'Nifty Smallcap 250','stooq' => '^nssc250', 'label' => 'Nifty Smallcap 250'],
    '^NSNXT50' => ['name' => 'Nifty Next 50',     'stooq' => '^nsnxt50', 'label' => 'Nifty Next 50'],
    '^CRISIL'  => ['name' => 'CRISIL Composite',  'stooq' => '^nsei',    'label' => 'CRISIL Bond (proxy)'],
];

const PERIODS = [
    '1M'  => '-1 month',
    '3M'  => '-3 months',
    '6M'  => '-6 months',
    '1Y'  => '-1 year',
    '3Y'  => '-3 years',
    '5Y'  => '-5 years',
];

// ── Category → default benchmark mapping ─────────────────────────────────
function default_benchmark(string $category): string {
    $c = strtolower($category);
    if (str_contains($c, 'mid cap') || str_contains($c, 'midcap') || str_contains($c, 'mid & small'))
        return '^NSMIDCP';
    if (str_contains($c, 'small cap') || str_contains($c, 'smallcap'))
        return '^NSSC250';
    if (str_contains($c, 'large & mid'))
        return '^NSEI'; // Nifty 100 proxy
    if (str_contains($c, 'debt') || str_contains($c, 'liquid') || str_contains($c, 'overnight') ||
        str_contains($c, 'gilt') || str_contains($c, 'credit') || str_contains($c, 'duration') ||
        str_contains($c, 'money market') || str_contains($c, 'floater') || str_contains($c, 'banking and psu') ||
        str_contains($c, 'corporate bond'))
        return '^CRISIL';
    // Default: Nifty 50 for all equity, index, ELSS, hybrid
    return '^NSEI';
}

// ── Fetch benchmark index data from stooq.com ─────────────────────────────
function fetch_benchmark_data(string $symbol, string $from, string $to): array {
    $info = BENCHMARKS[$symbol] ?? BENCHMARKS['^NSEI'];
    $stooqSym = $info['stooq'];

    // File cache (1 day)
    $cacheKey  = 'bm_' . preg_replace('/[^a-z0-9]/', '_', strtolower($stooqSym)) . '_' . $from . '_' . $to;
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cached = @file_get_contents($cacheFile);
        if ($cached) return json_decode($cached, true) ?? [];
    }

    $fromFmt = str_replace('-', '', $from);
    $toFmt   = str_replace('-', '', $to);
    $url = "https://stooq.com/q/d/l/?s={$stooqSym}&d1={$fromFmt}&d2={$toFmt}&i=d";

    $ctx = stream_context_create(['http' => [
        'timeout'    => 8,
        'user_agent' => 'Mozilla/5.0 WealthDash/2.0',
        'header'     => "Accept: text/csv\r\n",
    ], 'ssl' => ['verify_peer' => false]]);

    $csv = @file_get_contents($url, false, $ctx);
    if (!$csv || strpos($csv, 'Date') === false || str_contains($csv, 'No data')) {
        return [];
    }

    $lines = array_filter(explode("\n", trim($csv)));
    array_shift($lines); // remove header
    $data = [];
    foreach ($lines as $line) {
        $cols = str_getcsv($line);
        if (count($cols) < 5 || !$cols[0] || !is_numeric($cols[4])) continue;
        $data[] = ['date' => $cols[0], 'close' => (float)$cols[4]];
    }
    usort($data, fn($a, $b) => strcmp($a['date'], $b['date']));

    @file_put_contents($cacheFile, json_encode($data));
    return $data;
}

/**
 * Fetch fund NAV history from nav_history table.
 */
function fetch_fund_nav(PDO $db, int $fundId, string $from, string $to): array {
    try {
        $stmt = $db->prepare("
            SELECT nav_date AS date, nav AS close
            FROM nav_history
            WHERE fund_id = ? AND nav_date BETWEEN ? AND ?
            ORDER BY nav_date ASC
        ");
        $stmt->execute([$fundId, $from, $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        return [];
    }
}

/**
 * Calculate return % between first and last data point.
 */
function calc_return(array $data): ?float {
    if (count($data) < 2) return null;
    $first = (float)$data[0]['close'];
    $last  = (float)end($data)['close'];
    if ($first <= 0) return null;
    return round(($last - $first) / $first * 100, 2);
}

/**
 * Resample data to weekly (reduces chart points, keeps trend accurate).
 */
function resample_weekly(array $data): array {
    $weekly = [];
    $lastWeek = null;
    foreach ($data as $pt) {
        $week = date('Y-W', strtotime($pt['date']));
        if ($week !== $lastWeek) {
            $weekly[] = $pt;
            $lastWeek = $week;
        }
    }
    return $weekly;
}

/**
 * Normalize series to 100 at start (for chart overlay).
 */
function normalize_series(array $data): array {
    if (empty($data)) return [];
    $base = (float)$data[0]['close'];
    if ($base <= 0) return $data;
    return array_map(fn($p) => ['date' => $p['date'], 'close' => round((float)$p['close'] / $base * 100, 4)], $data);
}

// ── Route ─────────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET fund vs benchmark comparison ─────────────────────────────────
    case 'benchmark_compare': {
        $fundId = (int)($_GET['fund_id'] ?? 0);
        $period = strtoupper(trim($_GET['period'] ?? '1Y'));
        if ($fundId <= 0) json_response(false, 'fund_id required');
        if (!isset(PERIODS[$period])) $period = '1Y';

        // Fund info
        $stmt = $db->prepare("
            SELECT f.id, f.scheme_name, f.category, f.benchmark_index,
                   f.latest_nav, f.latest_nav_date
            FROM funds f WHERE f.id = ? AND f.is_active = 1
        ");
        $stmt->execute([$fundId]);
        $fund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fund) json_response(false, 'Fund not found');

        $benchmarkSymbol = $fund['benchmark_index'] ?: default_benchmark($fund['category'] ?? '');
        $benchmarkInfo   = BENCHMARKS[$benchmarkSymbol] ?? BENCHMARKS['^NSEI'];

        $to   = date('Y-m-d');
        $from = date('Y-m-d', strtotime(PERIODS[$period]));

        // Fetch data
        $fundNav      = fetch_fund_nav($db, $fundId, $from, $to);
        $benchmarkNav = fetch_benchmark_data($benchmarkSymbol, $from, $to);

        // Calculate returns
        $fundReturn      = calc_return($fundNav);
        $benchmarkReturn = calc_return($benchmarkNav);
        $alpha           = ($fundReturn !== null && $benchmarkReturn !== null)
                         ? round($fundReturn - $benchmarkReturn, 2)
                         : null;

        // Chart data: normalize to 100 + resample
        $fundChart  = normalize_series(resample_weekly($fundNav));
        $benchChart = normalize_series(resample_weekly($benchmarkNav));

        json_response(true, '', [
            'fund_id'          => $fundId,
            'scheme_name'      => $fund['scheme_name'],
            'category'         => $fund['category'],
            'period'           => $period,
            'from'             => $from,
            'to'               => $to,
            'benchmark_symbol' => $benchmarkSymbol,
            'benchmark_name'   => $benchmarkInfo['name'],
            'fund_return_pct'  => $fundReturn,
            'bench_return_pct' => $benchmarkReturn,
            'alpha_pct'        => $alpha,
            'alpha_label'      => $alpha !== null ? ($alpha >= 0 ? "▲ {$alpha}% vs benchmark" : "▼ " . abs($alpha) . "% vs benchmark") : null,
            'outperformed'     => $alpha !== null ? $alpha >= 0 : null,
            'fund_nav_data'    => $fundChart,
            'benchmark_data'   => $benchChart,
            'data_source'      => empty($benchmarkNav) ? 'nav_only' : 'full',
        ]);
        break;
    }

    // ── GET alpha across all periods ──────────────────────────────────────
    case 'benchmark_alpha': {
        $fundId = (int)($_GET['fund_id'] ?? 0);
        if ($fundId <= 0) json_response(false, 'fund_id required');

        $stmt = $db->prepare("SELECT id, scheme_name, category, benchmark_index FROM funds WHERE id=? AND is_active=1");
        $stmt->execute([$fundId]);
        $fund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fund) json_response(false, 'Fund not found');

        $benchmarkSymbol = $fund['benchmark_index'] ?: default_benchmark($fund['category'] ?? '');
        $benchmarkInfo   = BENCHMARKS[$benchmarkSymbol] ?? BENCHMARKS['^NSEI'];

        $to = date('Y-m-d');
        $alphas = [];

        foreach (PERIODS as $label => $offset) {
            $from = date('Y-m-d', strtotime($offset));
            $fundNav  = fetch_fund_nav($db, $fundId, $from, $to);
            $bmNav    = fetch_benchmark_data($benchmarkSymbol, $from, $to);

            $fundRet = calc_return($fundNav);
            $bmRet   = calc_return($bmNav);
            $alpha   = ($fundRet !== null && $bmRet !== null) ? round($fundRet - $bmRet, 2) : null;

            $alphas[$label] = [
                'fund_return'  => $fundRet,
                'bench_return' => $bmRet,
                'alpha'        => $alpha,
                'outperformed' => $alpha !== null ? $alpha >= 0 : null,
            ];
        }

        // Overall outperformance score (how many periods beat benchmark)
        $periodsWithData = array_filter($alphas, fn($a) => $a['alpha'] !== null);
        $beatingPeriods  = count(array_filter($periodsWithData, fn($a) => $a['outperformed']));
        $totalPeriods    = count($periodsWithData);

        json_response(true, '', [
            'fund_id'          => $fundId,
            'scheme_name'      => $fund['scheme_name'],
            'benchmark_symbol' => $benchmarkSymbol,
            'benchmark_name'   => $benchmarkInfo['name'],
            'periods'          => $alphas,
            'consistency'      => [
                'beating'  => $beatingPeriods,
                'total'    => $totalPeriods,
                'score'    => $totalPeriods > 0 ? round($beatingPeriods / $totalPeriods * 100) : null,
                'label'    => $totalPeriods > 0 ? "{$beatingPeriods}/{$totalPeriods} periods beat benchmark" : 'Insufficient data',
            ],
        ]);
        break;
    }

    // ── GET category → default benchmark mapping ──────────────────────────
    case 'benchmark_defaults': {
        $stmt = $db->query("SELECT DISTINCT category FROM funds WHERE is_active=1 AND category IS NOT NULL ORDER BY category");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $defaults = [];
        foreach ($categories as $cat) {
            $sym = default_benchmark($cat);
            $info = BENCHMARKS[$sym] ?? [];
            $defaults[] = [
                'category'         => $cat,
                'benchmark_symbol' => $sym,
                'benchmark_name'   => $info['name'] ?? $sym,
            ];
        }

        json_response(true, '', [
            'defaults'   => $defaults,
            'benchmarks' => array_map(fn($k, $v) => array_merge(['symbol' => $k], $v), array_keys(BENCHMARKS), BENCHMARKS),
        ]);
        break;
    }

    // ── POST assign benchmark to fund ─────────────────────────────────────
    case 'benchmark_assign': {
        $fundId    = (int)($_POST['fund_id'] ?? 0);
        $benchmark = strtoupper(trim($_POST['benchmark'] ?? ''));

        if ($fundId <= 0) json_response(false, 'fund_id required');
        if (!isset(BENCHMARKS[$benchmark])) {
            json_response(false, 'Invalid benchmark. Allowed: ' . implode(', ', array_keys(BENCHMARKS)));
        }

        // Verify fund belongs to user's portfolio or user is admin
        if ($currentUser['role'] !== 'admin') {
            $check = $db->prepare("
                SELECT COUNT(*) FROM mf_transactions t
                JOIN portfolios p ON p.id = t.portfolio_id
                WHERE t.fund_id = ? AND p.user_id = ?
            ");
            $check->execute([$fundId, (int)$currentUser['id']]);
            if ((int)$check->fetchColumn() === 0) {
                json_response(false, 'Fund not in your portfolio', [], 403);
            }
        }

        $db->prepare("UPDATE funds SET benchmark_index=? WHERE id=?")->execute([$benchmark, $fundId]);
        json_response(true, 'Benchmark assigned', ['fund_id' => $fundId, 'benchmark' => $benchmark, 'name' => BENCHMARKS[$benchmark]['name']]);
        break;
    }

    // ── POST bulk assign benchmarks by category ───────────────────────────
    case 'benchmark_bulk_assign': {
        if ($currentUser['role'] !== 'admin') {
            json_response(false, 'Admin access required', [], 403);
        }

        // Only assign where benchmark_index is NULL
        $stmt = $db->query("SELECT id, category FROM funds WHERE is_active=1 AND benchmark_index IS NULL");
        $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updated = 0;
        $upStmt  = $db->prepare("UPDATE funds SET benchmark_index=? WHERE id=?");
        foreach ($funds as $f) {
            $bm = default_benchmark($f['category'] ?? '');
            $upStmt->execute([$bm, (int)$f['id']]);
            $updated++;
        }

        json_response(true, "Assigned benchmarks to {$updated} funds", ['updated' => $updated]);
        break;
    }

    default:
        json_response(false, 'Unknown action');
}
