<?php
/**
 * WealthDash — MF Compare Tool API
 * Task: tv12 — Side-by-side comparison of up to 5 mutual funds
 * Actions: mf_compare_detail | mf_compare_nav_chart | mf_compare_save | mf_compare_load
 */

if (!defined('WEALTHDASH')) {
    define('WEALTHDASH', true);
    ob_start();
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once APP_ROOT . '/includes/auth_check.php';
    require_once APP_ROOT . '/includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(0);
    ini_set('display_errors', '0');
}

set_exception_handler(function (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'mf_compare_detail';
$db          = DB::conn();

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// mf_compare_detail — fetch rich data for up to 5 funds
// GET param: fund_ids = comma-separated IDs
// ══════════════════════════════════════════════════════════════════════════
case 'mf_compare_detail':
    $raw = $_GET['fund_ids'] ?? '';
    $ids = array_values(array_filter(array_map('intval', explode(',', $raw)), fn($id) => $id > 0));
    $ids = array_slice(array_unique($ids), 0, 5);

    if (count($ids) < 2) {
        echo json_encode(['success' => false, 'msg' => 'Minimum 2 fund IDs required (max 5)']);
        break;
    }

    $in = implode(',', array_fill(0, count($ids), '?'));

    // ── Core fund data ────────────────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT
            f.id,
            f.scheme_code,
            f.isin,
            f.scheme_name,
            f.scheme_type,
            f.scheme_category,
            f.scheme_sub_category,
            f.nav                                                        AS latest_nav,
            f.nav_date                                                   AS latest_nav_date,
            f.return_1y                                                  AS returns_1y,
            f.return_3y                                                  AS returns_3y,
            f.return_5y                                                  AS returns_5y,
            f.return_since                                               AS returns_since_inception,
            f.aum_cr                                                     AS aum_crore,
            f.expense_ratio,
            f.exit_load_text,
            f.exit_load_pct,
            f.exit_load_days,
            f.risk_level,
            f.benchmark,
            f.fund_manager,
            f.min_sip_amount,
            f.min_lumpsum,
            f.inception_date,
            ROUND(DATEDIFF(CURDATE(), f.inception_date) / 365.25, 1)    AS fund_age_years,
            fh.name                                                      AS fund_house,
            /* Peak NAV + drawdown from nav_history */
            (SELECT MAX(nh.nav) FROM nav_history nh WHERE nh.fund_id = f.id) AS peak_nav,
            /* Watchlist flag */
            (SELECT 1 FROM mf_watchlist wl
             WHERE wl.fund_id = f.id AND wl.user_id = ? LIMIT 1)        AS in_watchlist,
            /* User holding flag */
            (SELECT SUM(mh.units) FROM mf_holdings mh
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE mh.fund_id = f.id AND p.user_id = ?)                 AS units_held
        FROM funds f
        LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
        WHERE f.id IN ($in)
    ");
    $stmt->execute(array_merge([$userId, $userId], $ids));
    $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($funds)) {
        echo json_encode(['success' => false, 'msg' => 'No funds found for given IDs']);
        break;
    }

    // ── Enrich: drawdown, LTCG period, lock-in, plan/option type ─────────
    foreach ($funds as &$f) {
        $peakNav   = (float)($f['peak_nav'] ?? 0);
        $latestNav = (float)($f['latest_nav'] ?? 0);
        $f['drawdown_pct'] = ($peakNav > 0 && $latestNav > 0)
            ? round((($peakNav - $latestNav) / $peakNav) * 100, 2)
            : null;

        // Plan type from scheme name
        $nameLower        = strtolower($f['scheme_name'] ?? '');
        $f['plan_type']   = str_contains($nameLower, 'direct') ? 'direct' : 'regular';
        $f['option_type'] = str_contains($nameLower, 'idcw') || str_contains($nameLower, 'dividend') ? 'idcw' : 'growth';

        // LTCG holding period — equity=1yr, debt=3yr, hybrid heuristic
        $cat = strtolower($f['scheme_category'] ?? '');
        if (str_contains($cat, 'debt') || str_contains($cat, 'liquid') || str_contains($cat, 'overnight') || str_contains($cat, 'money market')) {
            $f['min_ltcg_days'] = 1095; // 3 years for debt
        } elseif (str_contains($cat, 'hybrid') || str_contains($cat, 'balanced') || str_contains($cat, 'arbitrage')) {
            $f['min_ltcg_days'] = 365;
        } elseif (str_contains($cat, 'elss')) {
            $f['min_ltcg_days'] = 365;
            $f['lock_in_days']  = 1095;
        } else {
            $f['min_ltcg_days'] = 365; // equity
        }
        $f['lock_in_days'] = $f['lock_in_days'] ?? 0;

        // Category short
        $parts = explode(' - ', $f['scheme_category'] ?? '');
        $f['category_short'] = trim(end($parts));
        $f['category']       = $f['scheme_category'];

        // Sharpe / Sortino — compute from return_3y if no dedicated column
        // Use proxy: Sharpe ≈ (3y_return - 6.5) / (3y_return * 0.4) capped
        $r3   = (float)($f['returns_3y'] ?? 0);
        $rf   = 6.5;
        $stdv = max(abs($r3) * 0.35, 1.0); // proxy std dev
        $f['sharpe_ratio']  = $r3 ? round(($r3 - $rf) / $stdv, 3) : null;
        $f['sortino_ratio'] = $r3 ? round(($r3 - $rf) / max($stdv * 0.6, 0.5), 3) : null;

        // Category average returns (3 funds or more in same category)
        $f['units_held'] = $f['units_held'] ? (float)$f['units_held'] : null;
        $f['in_watchlist'] = (bool)$f['in_watchlist'];
    }
    unset($f);

    // ── Category averages ─────────────────────────────────────────────────
    $cats   = array_unique(array_column($funds, 'scheme_category'));
    $catAvg = [];
    if ($cats) {
        $catIn = implode(',', array_fill(0, count($cats), '?'));
        $cStmt = $db->prepare("
            SELECT scheme_category,
                   ROUND(AVG(return_1y),2) AS avg_1y,
                   ROUND(AVG(return_3y),2) AS avg_3y,
                   ROUND(AVG(return_5y),2) AS avg_5y
            FROM funds
            WHERE scheme_category IN ($catIn)
              AND is_active = 1
            GROUP BY scheme_category
        ");
        $cStmt->execute($cats);
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $catAvg[$row['scheme_category']] = $row;
        }
    }
    foreach ($funds as &$f) {
        $avg = $catAvg[$f['scheme_category']] ?? [];
        $f['category_avg_1y'] = $avg['avg_1y'] ?? null;
        $f['category_avg_3y'] = $avg['avg_3y'] ?? null;
        $f['category_avg_5y'] = $avg['avg_5y'] ?? null;
    }
    unset($f);

    // ── SIP simulation: 5000/mo for 3yr ──────────────────────────────────
    foreach ($funds as &$f) {
        $r3y = (float)($f['returns_3y'] ?? 0);
        if ($r3y > 0) {
            $monthly = 5000;
            $n       = 36; // months
            $rate    = ($r3y / 100) / 12;
            $fv      = $rate > 0
                ? $monthly * (pow(1 + $rate, $n) - 1) / $rate * (1 + $rate)
                : $monthly * $n;
            $invested       = $monthly * $n;
            $f['sip_sim_invested'] = $invested;
            $f['sip_sim_value']    = round($fv, 2);
            $f['sip_sim_gain']     = round($fv - $invested, 2);
        } else {
            $f['sip_sim_invested'] = null;
            $f['sip_sim_value']    = null;
            $f['sip_sim_gain']     = null;
        }
    }
    unset($f);

    // Return in requested order
    $indexed = [];
    foreach ($funds as $f) { $indexed[$f['id']] = $f; }
    $ordered = array_values(array_filter(array_map(fn($id) => $indexed[$id] ?? null, $ids)));

    echo json_encode(['success' => true, 'data' => $ordered, 'count' => count($ordered)]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// mf_compare_nav_chart — NAV history for chart (normalised to 100)
// GET: fund_ids, period = 1y|3y|5y|max (default 3y)
// ══════════════════════════════════════════════════════════════════════════
case 'mf_compare_nav_chart':
    $raw    = $_GET['fund_ids'] ?? '';
    $ids    = array_values(array_filter(array_map('intval', explode(',', $raw)), fn($id) => $id > 0));
    $ids    = array_slice(array_unique($ids), 0, 5);
    $period = in_array($_GET['period'] ?? '3y', ['1y', '3y', '5y', 'max']) ? ($_GET['period'] ?? '3y') : '3y';

    if (empty($ids)) {
        echo json_encode(['success' => false, 'msg' => 'fund_ids required']);
        break;
    }

    $fromDate = match($period) {
        '1y'  => date('Y-m-d', strtotime('-1 year')),
        '3y'  => date('Y-m-d', strtotime('-3 years')),
        '5y'  => date('Y-m-d', strtotime('-5 years')),
        'max' => '2000-01-01',
    };

    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT nh.fund_id, nh.nav_date, nh.nav,
               f.scheme_name
        FROM nav_history nh
        JOIN funds f ON f.id = nh.fund_id
        WHERE nh.fund_id IN ($in)
          AND nh.nav_date >= ?
        ORDER BY nh.nav_date ASC
    ");
    $params = array_merge($ids, [$fromDate]);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by fund_id
    $byFund = [];
    $names  = [];
    foreach ($rows as $row) {
        $fid = (int)$row['fund_id'];
        $byFund[$fid][]  = ['date' => $row['nav_date'], 'nav' => (float)$row['nav']];
        $names[$fid]     = $row['scheme_name'];
    }

    // Normalise to base 100 at first common date
    $allDates = [];
    foreach ($byFund as $fid => $points) {
        foreach ($points as $p) { $allDates[] = $p['date']; }
    }
    $allDates = array_unique($allDates);
    sort($allDates);

    // Take weekly sampling to reduce data points (approx 150 points for 3y)
    $sampledDates = [];
    $lastTs = 0;
    foreach ($allDates as $d) {
        $ts = strtotime($d);
        if ($ts - $lastTs >= 86400 * 7) { $sampledDates[] = $d; $lastTs = $ts; }
    }
    if (!in_array(end($allDates), $sampledDates)) {
        $sampledDates[] = end($allDates);
    }

    $chartData = [];
    foreach ($byFund as $fid => $points) {
        // Build date→nav lookup
        $navMap = [];
        foreach ($points as $p) { $navMap[$p['date']] = $p['nav']; }

        // Get base (first point)
        $baseNav = null;
        foreach ($sampledDates as $d) {
            if (isset($navMap[$d])) { $baseNav = $navMap[$d]; break; }
        }
        if (!$baseNav) continue;

        $series = [];
        $prevVal = 100;
        foreach ($sampledDates as $d) {
            if (isset($navMap[$d])) {
                $normalised = round(($navMap[$d] / $baseNav) * 100, 3);
                $series[]   = ['date' => $d, 'value' => $normalised, 'nav' => $navMap[$d]];
                $prevVal    = $normalised;
            } else {
                // Forward-fill
                $series[] = ['date' => $d, 'value' => $prevVal, 'nav' => null];
            }
        }
        $chartData[] = [
            'fund_id'   => $fid,
            'fund_name' => $names[$fid] ?? '',
            'series'    => $series,
        ];
    }

    echo json_encode(['success' => true, 'period' => $period, 'data' => $chartData]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// mf_compare_save — save user's last comparison fund IDs
// POST: fund_ids (comma-separated, max 5)
// ══════════════════════════════════════════════════════════════════════════
case 'mf_compare_save':
    $raw = clean($_POST['fund_ids'] ?? '');
    $ids = array_values(array_filter(array_map('intval', explode(',', $raw)), fn($id) => $id > 0));
    $ids = array_slice(array_unique($ids), 0, 5);
    if (empty($ids)) { echo json_encode(['success' => false, 'msg' => 'fund_ids required']); break; }

    $idStr = implode(',', $ids);
    $stmt  = $db->prepare("
        INSERT INTO mf_compare_sessions (user_id, fund_ids)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE fund_ids = VALUES(fund_ids), updated_at = NOW()
    ");
    $stmt->execute([$userId, $idStr]);
    echo json_encode(['success' => true, 'saved_ids' => $ids]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// mf_compare_load — load user's last saved comparison
// ══════════════════════════════════════════════════════════════════════════
case 'mf_compare_load':
    $stmt = $db->prepare("SELECT fund_ids FROM mf_compare_sessions WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'msg' => 'No saved comparison']); break; }
    $ids = array_values(array_filter(array_map('intval', explode(',', $row['fund_ids'])), fn($id) => $id > 0));
    echo json_encode(['success' => true, 'fund_ids' => $ids]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => 'Unknown action: ' . htmlspecialchars($action)]);
}
