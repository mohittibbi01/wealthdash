<?php
/**
 * WealthDash — MF NAV History API
 * Tasks: t94 (Rolling Returns), t95 (Benchmark Chart), t160-t163
 * Actions: nav_chart | nav_proxy | rolling_returns | benchmark_compare | fund_age_since_inception
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

require_once ROOT . '/includes/holding_calculator.php';

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'nav_chart';
$db          = DB::conn();

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// nav_chart — NAV history for chart (1M/3M/6M/1Y/3Y/5Y/All)
// ══════════════════════════════════════════════════════════════════════════
case 'nav_chart':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    $period = $_GET['period'] ?? '1Y';
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }

    $periodMap = [
        '1M'  => '-1 month',
        '3M'  => '-3 months',
        '6M'  => '-6 months',
        '1Y'  => '-1 year',
        '3Y'  => '-3 years',
        '5Y'  => '-5 years',
        'All' => null,
    ];
    $fromDate = isset($periodMap[$period]) && $periodMap[$period]
        ? date('Y-m-d', strtotime($periodMap[$period]))
        : '1990-01-01';

    $stmt = $db->prepare("
        SELECT nav_date, nav
        FROM nav_history
        WHERE fund_id = ? AND nav_date >= ?
        ORDER BY nav_date ASC
    ");
    $stmt->execute([$fundId, $fromDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Downsample if more than 300 points
    if (count($rows) > 300) {
        $step   = ceil(count($rows) / 300);
        $rows   = array_values(array_filter($rows, fn($_, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
        $rows[] = end($stmt->fetchAll(PDO::FETCH_ASSOC)); // always include latest
    }

    // Period return %
    $periodReturn = null;
    if (!empty($rows)) {
        $first = (float)$rows[0]['nav'];
        $last  = (float)end($rows)['nav'];
        if ($first > 0) $periodReturn = round(($last / $first - 1) * 100, 2);
    }

    echo json_encode(['success' => true, 'data' => $rows, 'period_return' => $periodReturn]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// rolling_returns — t94
// ══════════════════════════════════════════════════════════════════════════
case 'rolling_returns':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    $period = in_array($_GET['period'] ?? '3Y', ['1Y','3Y','5Y']) ? $_GET['period'] : '3Y';
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }

    $periodDays = ['1Y' => 365, '3Y' => 1095, '5Y' => 1825][$period];

    $navRows = $db->prepare("SELECT nav_date, nav FROM nav_history WHERE fund_id=? ORDER BY nav_date ASC");
    $navRows->execute([$fundId]);
    $navData = $navRows->fetchAll(PDO::FETCH_ASSOC);

    if (count($navData) < $periodDays + 30) {
        echo json_encode(['success' => false, 'msg' => "Insufficient history for $period rolling returns"]);
        break;
    }

    $returns = [];
    $step    = max(1, intval(count($navData) / 150)); // max 150 data points for chart

    for ($i = 0; $i < count($navData) - $periodDays; $i += $step) {
        $startNav  = (float)$navData[$i]['nav'];
        // Find the nav closest to i + periodDays
        $endIdx    = min($i + $periodDays, count($navData) - 1);
        $endNav    = (float)$navData[$endIdx]['nav'];
        $years     = $periodDays / 365;
        if ($startNav > 0) {
            $cagr = round((pow($endNav / $startNav, 1 / $years) - 1) * 100, 2);
            $returns[] = ['date' => $navData[$endIdx]['nav_date'], 'return' => $cagr];
        }
    }

    if (empty($returns)) { echo json_encode(['success' => false, 'msg' => 'No data']); break; }

    $values      = array_column($returns, 'return');
    $positive    = count(array_filter($values, fn($v) => $v > 0));
    $stats = [
        'min'          => min($values),
        'max'          => max($values),
        'avg'          => round(array_sum($values) / count($values), 2),
        'median'       => median($values),
        'positive_pct' => round($positive / count($values) * 100, 1),
        'total_windows'=> count($values),
    ];

    echo json_encode(['success' => true, 'data' => $returns, 'stats' => $stats, 'period' => $period]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// benchmark_compare — t95: Fund NAV vs benchmark
// ══════════════════════════════════════════════════════════════════════════
case 'benchmark_compare':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    $period = $_GET['period'] ?? '1Y';
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }

    $periodMap = ['1M' => '-1 month', '3M' => '-3 months', '6M' => '-6 months',
                  '1Y' => '-1 year',  '3Y' => '-3 years',  '5Y' => '-5 years'];
    $fromDate  = date('Y-m-d', strtotime($periodMap[$period] ?? '-1 year'));

    // Fund NAV
    $fundNavStmt = $db->prepare("SELECT nav_date, nav FROM nav_history WHERE fund_id=? AND nav_date>=? ORDER BY nav_date ASC");
    $fundNavStmt->execute([$fundId, $fromDate]);
    $fundNav = $fundNavStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get benchmark for this fund
    $bm = $db->prepare("SELECT b.code, b.benchmark_name FROM fund_benchmark_map b WHERE b.fund_id=? LIMIT 1");
    $bm->execute([$fundId]);
    $bmInfo = $bm->fetch(PDO::FETCH_ASSOC);
    $bmCode = $bmInfo['code'] ?? 'NIFTY50';
    $bmName = $bmInfo['benchmark_name'] ?? 'Nifty 50';

    $bmNavStmt = $db->prepare("SELECT nav_date, nav_value AS nav FROM benchmark_nav WHERE code=? AND nav_date>=? ORDER BY nav_date ASC");
    $bmNavStmt->execute([$bmCode, $fromDate]);
    $bmNav = $bmNavStmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalise to 100
    $normalise = function(array $rows): array {
        if (empty($rows)) return [];
        $base = (float)$rows[0]['nav'];
        if ($base <= 0) return [];
        return array_map(fn($r) => ['date' => $r['nav_date'], 'value' => round((float)$r['nav'] / $base * 100, 3)], $rows);
    };

    $fundNorm = $normalise($fundNav);
    $bmNorm   = $normalise($bmNav);

    // Period returns
    $fundReturn = $bmReturn = null;
    if (count($fundNav) >= 2) {
        $f = (float)$fundNav[0]['nav']; $l = (float)end($fundNav)['nav'];
        if ($f > 0) $fundReturn = round(($l / $f - 1) * 100, 2);
    }
    if (count($bmNav) >= 2) {
        $f = (float)$bmNav[0]['nav']; $l = (float)end($bmNav)['nav'];
        if ($f > 0) $bmReturn = round(($l / $f - 1) * 100, 2);
    }

    echo json_encode(['success' => true,
        'fund' => $fundNorm, 'benchmark' => $bmNorm,
        'benchmark_name' => $bmName,
        'fund_return'    => $fundReturn,
        'bm_return'      => $bmReturn,
        'outperformance' => ($fundReturn !== null && $bmReturn !== null)
                             ? round($fundReturn - $bmReturn, 2) : null,
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// nav_proxy — t163: Local cache for MFAPI responses
// ══════════════════════════════════════════════════════════════════════════
case 'nav_proxy':
    $schemeCode = preg_replace('/[^0-9]/', '', $_GET['scheme_code'] ?? '');
    if (!$schemeCode) { echo json_encode(['success' => false, 'msg' => 'scheme_code required']); break; }

    $fund = $db->prepare("SELECT id FROM funds WHERE scheme_code=? LIMIT 1");
    $fund->execute([$schemeCode]);
    $fundId = (int)$fund->fetchColumn();

    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'Fund not found']); break; }

    // Return cached NAV history
    $rows = $db->prepare("SELECT nav_date AS date, nav FROM nav_history WHERE fund_id=? ORDER BY nav_date DESC LIMIT 2000");
    $rows->execute([$fundId]);
    $data = $rows->fetchAll(PDO::FETCH_ASSOC);

    // Format like MFAPI response
    $formatted = array_map(fn($r) => [
        'date' => date('d-m-Y', strtotime($r['date'])),
        'nav'  => (string)$r['nav'],
    ], $data);

    echo json_encode(['success' => true, 'data' => $formatted, 'source' => 'cache']);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}

// Helper
function median(array $arr): float {
    sort($arr);
    $n = count($arr);
    return $n % 2 === 0
        ? ($arr[$n/2 - 1] + $arr[$n/2]) / 2
        : $arr[intdiv($n, 2)];
}
