<?php
/**
 * WealthDash — SIP vs Lumpsum Historical Backtest API
 * Task: t234 (needs: t160 — nav_history table)
 *
 * Actions:
 *   GET ?action=backtest&fund_id=X&amount=5000&period=10Y&sip_day=1
 *       → Full SIP vs Lumpsum backtest on real NAV history
 *   GET ?action=rolling_backtest&fund_id=X&period=5Y&window=1Y
 *       → Rolling window backtest: for every start date, who won?
 *   GET ?action=best_entry&fund_id=X&amount=100000
 *       → Best/worst lumpsum entry points from history
 *   GET ?action=summary&fund_id=X
 *       → Quick summary card (used by holdings page widget)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action  = $_GET['action'] ?? 'backtest';
$fundId  = (int)($_GET['fund_id'] ?? 0);
$period  = strtoupper(trim($_GET['period'] ?? '10Y'));
$amount  = max(500, (float)($_GET['amount'] ?? 5000));
$sipDay  = max(1, min(28, (int)($_GET['sip_day'] ?? 1)));
$window  = strtoupper(trim($_GET['window'] ?? '3Y'));

header('Content-Type: application/json; charset=utf-8');
ob_start();

set_exception_handler(function (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

// ── Period → months map ────────────────────────────────────────────────────
$periodMonths = [
    '1Y' => 12,  '2Y' => 24,  '3Y' => 36,
    '5Y' => 60,  '7Y' => 84,  '10Y' => 120,
    '15Y' => 180,'20Y' => 240,
];
$windowMonths = [
    '1Y' => 12, '3Y' => 36, '5Y' => 60, '10Y' => 120,
];

if (!isset($periodMonths[$period])) $period = '10Y';
$months = $periodMonths[$period];
$years  = $months / 12;

// ── Helpers ────────────────────────────────────────────────────────────────
function load_nav_history(PDO $db, int $fundId): array {
    $stmt = $db->prepare("
        SELECT nav_date AS date, CAST(nav AS DECIMAL(14,4)) AS nav
        FROM nav_history
        WHERE fund_id = ?
        ORDER BY nav_date ASC
    ");
    $stmt->execute([$fundId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fund_info(PDO $db, int $fundId): ?array {
    $stmt = $db->prepare("
        SELECT id, scheme_name, category, latest_nav, latest_nav_date, returns_1y, returns_3y, returns_5y
        FROM funds WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$fundId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Find closest NAV row index on or after a given date string.
 */
function find_nav_idx(array $navRows, string $targetDate, int $startIdx = 0): int {
    $n = count($navRows);
    for ($i = $startIdx; $i < $n; $i++) {
        if ($navRows[$i]['date'] >= $targetDate) return $i;
    }
    return $n - 1;
}

/**
 * Simulate SIP: invest $amount every month on sipDay, from $startIdx.
 * Returns [units, invested, monthly_values_arr].
 */
function simulate_sip(array $navRows, int $startIdx, int $months, float $amount, int $sipDay): array {
    $units         = 0.0;
    $invested      = 0.0;
    $monthsInvested = 0;
    $monthlyValues = [];

    $startDate  = $navRows[$startIdx]['date'];
    $startYear  = (int)substr($startDate, 0, 4);
    $startMonth = (int)substr($startDate, 5, 2);

    $n = count($navRows);

    for ($m = 0; $m < $months; $m++) {
        $tm = $startMonth + $m;
        $ty = $startYear + intdiv($tm - 1, 12);
        $mo = (($tm - 1) % 12) + 1;
        $sipDate = sprintf('%04d-%02d-%02d', $ty, $mo, $sipDay);

        // Find closest trading day on or after sipDate
        $idx = find_nav_idx($navRows, $sipDate, $startIdx);
        if ($idx >= $n) break;

        $nav = (float)$navRows[$idx]['nav'];
        if ($nav <= 0) continue;

        $units    += $amount / $nav;
        $invested += $amount;
        $monthsInvested++;

        // Record portfolio value at this point
        $endIdx   = min($idx + 1, $n - 1); // snapshot at next available day
        $currentVal = $units * (float)$navRows[$endIdx]['nav'];
        $monthlyValues[] = [
            'date'      => $navRows[$idx]['date'],
            'invested'  => round($invested, 2),
            'value'     => round($currentVal, 2),
        ];
    }

    return ['units' => $units, 'invested' => $invested, 'months' => $monthsInvested, 'monthly_values' => $monthlyValues];
}

/**
 * Simulate Lumpsum: invest lump amount at startIdx.
 * Lump = amount * months (same total as SIP).
 */
function simulate_lumpsum(array $navRows, int $startIdx, int $months, float $monthlyAmount): array {
    $lumpAmount = $monthlyAmount * $months;
    $startNav   = (float)$navRows[$startIdx]['nav'];
    if ($startNav <= 0) return ['units' => 0, 'invested' => $lumpAmount, 'monthly_values' => []];

    $units         = $lumpAmount / $startNav;
    $n             = count($navRows);
    $startYear     = (int)substr($navRows[$startIdx]['date'], 0, 4);
    $startMonth    = (int)substr($navRows[$startIdx]['date'], 5, 2);
    $monthlyValues = [];

    for ($m = 0; $m < $months; $m++) {
        $tm = $startMonth + $m;
        $ty = $startYear + intdiv($tm - 1, 12);
        $mo = (($tm - 1) % 12) + 1;
        $snapDate = sprintf('%04d-%02d-01', $ty, $mo);
        $idx      = find_nav_idx($navRows, $snapDate, $startIdx);
        if ($idx >= $n) break;
        $monthlyValues[] = [
            'date'     => $navRows[$idx]['date'],
            'invested' => round($lumpAmount, 2),
            'value'    => round($units * (float)$navRows[$idx]['nav'], 2),
        ];
    }

    return ['units' => $units, 'invested' => $lumpAmount, 'monthly_values' => $monthlyValues];
}

/**
 * XIRR approximation using Newton-Raphson (for SIP CAGR equivalent).
 */
function xirr_approx(array $cashflows, array $dates): float {
    if (count($cashflows) < 2) return 0.0;
    $base = strtotime($dates[0]);
    $n    = count($cashflows);
    $rate = 0.1;
    for ($iter = 0; $iter < 100; $iter++) {
        $npv   = 0.0;
        $dnpv  = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $t    = (strtotime($dates[$i]) - $base) / 365.25;
            $denom = pow(1 + $rate, $t);
            if ($denom == 0) continue;
            $npv  += $cashflows[$i] / $denom;
            $dnpv -= $t * $cashflows[$i] / ($denom * (1 + $rate));
        }
        if (abs($dnpv) < 1e-12) break;
        $newRate = $rate - $npv / $dnpv;
        if (abs($newRate - $rate) < 1e-7) { $rate = $newRate; break; }
        $rate = $newRate;
        if ($rate < -0.999) $rate = -0.999;
        if ($rate > 100)    $rate = 100;
    }
    return round($rate * 100, 2);
}

function cagr(float $invested, float $finalValue, float $years): float {
    if ($invested <= 0 || $finalValue <= 0 || $years <= 0) return 0.0;
    return round((pow($finalValue / $invested, 1 / $years) - 1) * 100, 2);
}

// ══════════════════════════════════════════════════════════════════════════
// ACTION: backtest
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'backtest') {
    if (!$fundId) { ob_clean(); echo json_encode(['success' => false, 'message' => 'fund_id required']); exit; }

    $fund = fund_info($db, $fundId);
    if (!$fund) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Fund not found']); exit; }

    $navRows = load_nav_history($db, $fundId);
    $n       = count($navRows);

    if ($n < 60) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => "Insufficient NAV history ({$n} days). Need at least 60 days."]);
        exit;
    }

    // Start from as early as possible (respect period)
    // Use the last available `months` months of history for the main backtest
    $endIdx   = $n - 1;
    $endDate  = $navRows[$endIdx]['date'];
    $startTarget = date('Y-m-d', strtotime("-{$months} months", strtotime($endDate)));
    $startIdx = find_nav_idx($navRows, $startTarget);

    // SIP simulation
    $sipResult  = simulate_sip($navRows, $startIdx, $months, $amount, $sipDay);
    $sipFinal   = $sipResult['units'] * (float)$navRows[$endIdx]['nav'];
    $sipInvested = $sipResult['invested'];

    // SIP XIRR
    $sipCfs    = [];
    $sipDates  = [];
    foreach ($sipResult['monthly_values'] as $mv) {
        $sipCfs[]   = -$amount;
        $sipDates[] = $mv['date'];
    }
    if (!empty($sipDates)) {
        $sipCfs[]   = $sipFinal;
        $sipDates[] = $endDate;
    }
    $sipXirr = !empty($sipCfs) ? xirr_approx($sipCfs, $sipDates) : 0;

    // Lumpsum simulation (same total outlay)
    $lsResult  = simulate_lumpsum($navRows, $startIdx, $months, $amount);
    $lsFinal   = $lsResult['units'] * (float)$navRows[$endIdx]['nav'];
    $lsInvested = $lsResult['invested'];
    $lsCagr    = cagr($lsInvested, $lsFinal, $years);

    $winner        = $sipFinal >= $lsFinal ? 'sip' : 'lumpsum';
    $sipGain       = round($sipFinal - $sipInvested, 2);
    $lsGain        = round($lsFinal - $lsInvested, 2);
    $sipGainPct    = $sipInvested > 0 ? round($sipGain / $sipInvested * 100, 2) : 0;
    $lsGainPct     = $lsInvested > 0 ? round($lsGain / $lsInvested * 100, 2) : 0;

    // Downsample chart data (max 120 points)
    $sipChart = $sipResult['monthly_values'];
    $lsChart  = $lsResult['monthly_values'];
    // Align by date
    $chartData = [];
    $lsMap = [];
    foreach ($lsChart as $pt) $lsMap[$pt['date']] = $pt;
    foreach ($sipChart as $pt) {
        $chartData[] = [
            'date'      => $pt['date'],
            'sip_value' => $pt['value'],
            'ls_value'  => $lsMap[$pt['date']]['value'] ?? null,
            'invested'  => $pt['invested'],
        ];
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'fund'    => [
            'id'          => $fund['id'],
            'name'        => $fund['scheme_name'],
            'category'    => $fund['category'],
            'latest_nav'  => $fund['latest_nav'],
        ],
        'params' => [
            'period'        => $period,
            'months'        => $months,
            'monthly_amount'=> $amount,
            'lumpsum_amount'=> $lsInvested,
            'sip_day'       => $sipDay,
            'start_date'    => $navRows[$startIdx]['date'],
            'end_date'      => $endDate,
        ],
        'sip' => [
            'invested'       => round($sipInvested, 2),
            'final_value'    => round($sipFinal, 2),
            'gain'           => $sipGain,
            'gain_pct'       => $sipGainPct,
            'xirr'           => $sipXirr,
            'months_invested'=> $sipResult['months'],
        ],
        'lumpsum' => [
            'invested'   => round($lsInvested, 2),
            'final_value'=> round($lsFinal, 2),
            'gain'       => $lsGain,
            'gain_pct'   => $lsGainPct,
            'cagr'       => $lsCagr,
        ],
        'comparison' => [
            'winner'            => $winner,
            'sip_advantage'     => round($sipFinal - $lsFinal, 2),
            'sip_xirr_vs_ls_cagr'=> round($sipXirr - $lsCagr, 2),
            'verdict'           => $winner === 'sip'
                ? "SIP outperformed Lumpsum by ₹" . number_format(abs($sipFinal - $lsFinal), 0) . " over {$period}"
                : "Lumpsum outperformed SIP by ₹" . number_format(abs($sipFinal - $lsFinal), 0) . " over {$period}",
        ],
        'chart_data' => $chartData,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// ACTION: rolling_backtest
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'rolling_backtest') {
    if (!$fundId) { ob_clean(); echo json_encode(['success' => false, 'message' => 'fund_id required']); exit; }

    $fund = fund_info($db, $fundId);
    if (!$fund) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Fund not found']); exit; }

    $winMonths = $windowMonths[$window] ?? 36;
    $navRows   = load_nav_history($db, $fundId);
    $n         = count($navRows);

    if ($n < $winMonths * 30) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Insufficient NAV history for rolling backtest']);
        exit;
    }

    $results    = [];
    $sipWins    = 0;
    $lsWins     = 0;
    $sipAdvantages = [];
    $lsAdvantages  = [];

    // Step through every 30 days as a starting point
    $step = max(1, intdiv($n, 60)); // max 60 windows
    for ($i = 0; $i + ($winMonths * 22) < $n; $i += $step) {
        $endIdx  = min($i + $winMonths * 22, $n - 1);
        $endDate = $navRows[$endIdx]['date'];

        $sipR    = simulate_sip($navRows, $i, $winMonths, $amount, $sipDay);
        $sipFin  = $sipR['units'] * (float)$navRows[$endIdx]['nav'];
        $sipInv  = $sipR['invested'];

        $lsR     = simulate_lumpsum($navRows, $i, $winMonths, $amount);
        $lsFin   = $lsR['units'] * (float)$navRows[$endIdx]['nav'];
        $lsInv   = $lsR['invested'];

        $winner  = $sipFin >= $lsFin ? 'sip' : 'lumpsum';
        $sipXirr = cagr($sipInv, $sipFin, $winMonths / 12);
        $lsCagr  = cagr($lsInv, $lsFin, $winMonths / 12);

        if ($winner === 'sip') {
            $sipWins++;
            $sipAdvantages[] = round(($sipFin - $lsFin) / $lsFin * 100, 2);
        } else {
            $lsWins++;
            $lsAdvantages[] = round(($lsFin - $sipFin) / $sipFin * 100, 2);
        }

        $results[] = [
            'start_date'   => $navRows[$i]['date'],
            'end_date'     => $endDate,
            'winner'       => $winner,
            'sip_cagr'     => $sipXirr,
            'ls_cagr'      => $lsCagr,
            'sip_final'    => round($sipFin, 2),
            'ls_final'     => round($lsFin, 2),
        ];
    }

    $total = $sipWins + $lsWins;
    ob_clean();
    echo json_encode([
        'success'    => true,
        'fund_name'  => $fund['scheme_name'],
        'window'     => $window,
        'window_months' => $winMonths,
        'monthly_amount'=> $amount,
        'total_windows' => $total,
        'sip_wins'   => $sipWins,
        'ls_wins'    => $lsWins,
        'sip_win_pct'=> $total > 0 ? round($sipWins / $total * 100, 1) : 0,
        'ls_win_pct' => $total > 0 ? round($lsWins / $total * 100, 1) : 0,
        'avg_sip_advantage_pct' => !empty($sipAdvantages) ? round(array_sum($sipAdvantages) / count($sipAdvantages), 2) : 0,
        'avg_ls_advantage_pct'  => !empty($lsAdvantages)  ? round(array_sum($lsAdvantages)  / count($lsAdvantages),  2) : 0,
        'verdict' => $sipWins >= $lsWins
            ? "SIP won in {$sipWins}/{$total} rolling {$window} windows (" . round($sipWins/$total*100, 1) . "%)"
            : "Lumpsum won in {$lsWins}/{$total} rolling {$window} windows (" . round($lsWins/$total*100, 1) . "%)",
        'windows' => $results,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// ACTION: best_entry
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'best_entry') {
    if (!$fundId) { ob_clean(); echo json_encode(['success' => false, 'message' => 'fund_id required']); exit; }

    $fund    = fund_info($db, $fundId);
    if (!$fund) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Fund not found']); exit; }

    $navRows = load_nav_history($db, $fundId);
    $n       = count($navRows);
    $lsAmount = (float)($_GET['amount'] ?? 100000);

    if ($n < 60) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Insufficient NAV history']);
        exit;
    }

    $endIdx  = $n - 1;
    $endNav  = (float)$navRows[$endIdx]['nav'];

    $entries = [];
    $step    = max(1, intdiv($n, 200));
    for ($i = 0; $i < $n - 30; $i += $step) {
        $startNav = (float)$navRows[$i]['nav'];
        if ($startNav <= 0) continue;
        $units    = $lsAmount / $startNav;
        $finalVal = $units * $endNav;
        $ret      = round(($finalVal - $lsAmount) / $lsAmount * 100, 2);
        $daysHeld = (int)((strtotime($navRows[$endIdx]['date']) - strtotime($navRows[$i]['date'])) / 86400);
        $yrs      = $daysHeld / 365.25;
        $cagrVal  = $yrs > 0 ? cagr($lsAmount, $finalVal, $yrs) : 0;
        $entries[] = [
            'date'       => $navRows[$i]['date'],
            'nav'        => $startNav,
            'final_value'=> round($finalVal, 2),
            'return_pct' => $ret,
            'cagr'       => $cagrVal,
            'days_held'  => $daysHeld,
        ];
    }

    if (empty($entries)) { ob_clean(); echo json_encode(['success' => false, 'message' => 'No data']); exit; }

    usort($entries, fn($a, $b) => $b['cagr'] <=> $a['cagr']);
    $best5  = array_slice($entries, 0, 5);
    $worst5 = array_slice(array_reverse($entries), 0, 5);

    // Sort by date for chart
    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']));

    ob_clean();
    echo json_encode([
        'success'     => true,
        'fund_name'   => $fund['scheme_name'],
        'amount'      => $lsAmount,
        'end_date'    => $navRows[$endIdx]['date'],
        'end_nav'     => $endNav,
        'best_entries'  => $best5,
        'worst_entries' => $worst5,
        'all_entries'   => $entries,
        'insight' => "Best entry: " . $best5[0]['date'] . " (CAGR: {$best5[0]['cagr']}%). " .
                     "Worst entry: " . $worst5[0]['date'] . " (CAGR: {$worst5[0]['cagr']}%). " .
                     "This shows the cost of market timing — time in market beats timing the market.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// ACTION: summary
// ══════════════════════════════════════════════════════════════════════════
if ($action === 'summary') {
    if (!$fundId) { ob_clean(); echo json_encode(['success' => false, 'message' => 'fund_id required']); exit; }

    $fund    = fund_info($db, $fundId);
    if (!$fund) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Fund not found']); exit; }

    $navRows = load_nav_history($db, $fundId);
    $n       = count($navRows);

    if ($n < 60) {
        ob_clean();
        echo json_encode([
            'success'   => true,
            'fund_name' => $fund['scheme_name'],
            'available' => false,
            'message'   => 'Insufficient NAV history for backtest',
        ]);
        exit;
    }

    // Quick 5Y / 10Y comparison
    $summaries = [];
    foreach ([60 => '5Y', 120 => '10Y'] as $mo => $label) {
        $endIdx   = $n - 1;
        $endDate  = $navRows[$endIdx]['date'];
        $startTgt = date('Y-m-d', strtotime("-{$mo} months", strtotime($endDate)));
        $si       = find_nav_idx($navRows, $startTgt);
        if ($si >= $n - 30) continue;

        $sipR    = simulate_sip($navRows, $si, $mo, 5000, 1);
        $sipFin  = $sipR['units'] * (float)$navRows[$endIdx]['nav'];
        $lsR     = simulate_lumpsum($navRows, $si, $mo, 5000);
        $lsFin   = $lsR['units'] * (float)$navRows[$endIdx]['nav'];

        $summaries[$label] = [
            'period'      => $label,
            'sip_final'   => round($sipFin, 2),
            'ls_final'    => round($lsFin, 2),
            'sip_xirr'    => cagr($sipR['invested'], $sipFin, $mo / 12),
            'ls_cagr'     => cagr($lsR['invested'],  $lsFin,  $mo / 12),
            'winner'      => $sipFin >= $lsFin ? 'sip' : 'lumpsum',
        ];
    }

    ob_clean();
    echo json_encode([
        'success'    => true,
        'available'  => true,
        'fund_name'  => $fund['scheme_name'],
        'nav_count'  => $n,
        'summaries'  => $summaries,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_clean();
echo json_encode(['success' => false, 'message' => "Unknown action: {$action}"]);
