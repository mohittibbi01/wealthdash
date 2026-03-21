<?php
/**
 * WealthDash — 1-Day NAV Change
 *
 * Fast path (default): funds.prev_nav from DB — instant, no HTTP calls
 * Slow path (fallback): nav_history table for missing prev_nav
 * Last resort: mfapi.in parallel curl for funds still missing data
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json');
$currentUser = require_auth();

try {
    $db = DB::conn();

    // ── Fetch active holdings with prev_nav already stored in funds table ──
    $stmt = $db->prepare("
        SELECT
            f.id               AS fund_id,
            f.scheme_code,
            f.latest_nav,
            f.latest_nav_date,
            f.prev_nav,
            f.prev_nav_date,
            SUM(h.total_units) AS total_units
        FROM mf_holdings h
        JOIN funds f ON f.id = h.fund_id
        JOIN portfolios p ON p.id = h.portfolio_id
        WHERE p.user_id = ? AND h.is_active = 1 AND h.total_units > 0
        GROUP BY f.id
    ");
    $stmt->execute([$currentUser['id']]);
    $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($holdings)) {
        echo json_encode(['success' => true, 'data' => [], 'source' => 'no_holdings']);
        exit;
    }

    $result      = [];

    foreach ($holdings as $h) {
        $fundId     = (int)$h['fund_id'];
        $totalUnits = (float)$h['total_units'];
        $latestNav  = $h['latest_nav']  ? (float)$h['latest_nav']  : null;
        $latestDate = $h['latest_nav_date'] ?? null;
        $prevNav    = $h['prev_nav']    ? (float)$h['prev_nav']    : null;
        $prevDate   = $h['prev_nav_date'] ?? null;

        // ── Fallback 1: nav_history table ──
        if (!$prevNav && $latestDate) {
            $ps = $db->prepare("
                SELECT nav, nav_date FROM nav_history
                WHERE fund_id = ? AND nav_date < ?
                ORDER BY nav_date DESC LIMIT 1
            ");
            $ps->execute([$fundId, $latestDate]);
            $pr = $ps->fetch();
            if ($pr) {
                $prevNav  = (float)$pr['nav'];
                $prevDate = $pr['nav_date'];
                // Persist so next time it's instant
                $db->prepare("UPDATE funds SET prev_nav=?, prev_nav_date=? WHERE id=? AND (prev_nav IS NULL OR prev_nav=0)")
                   ->execute([$prevNav, $prevDate, $fundId]);
            }
        }

    // ── Fallback 2: nav_history table (second try with broader date range) ──
    if (!$prevNav && $latestNav) {
        $ps2 = $db->prepare("
            SELECT nav, nav_date FROM nav_history
            WHERE fund_id = ? AND nav > 0
            ORDER BY nav_date DESC LIMIT 2
        ");
        $ps2->execute([$fundId]);
        $rows = $ps2->fetchAll();
        // Pick the row that isn't the latest
        foreach ($rows as $row) {
            if (round((float)$row['nav'], 4) !== round($latestNav, 4)) {
                $prevNav  = (float)$row['nav'];
                $prevDate = $row['nav_date'];
                $db->prepare("UPDATE funds SET prev_nav=?, prev_nav_date=? WHERE id=? AND (prev_nav IS NULL OR prev_nav=0)")
                   ->execute([$prevNav, $prevDate, $fundId]);
                break;
            }
        }
    }

    if (!$prevNav || !$latestNav) continue; // skip, not enough data

    $result[$fundId] = _calc($latestNav, $latestDate, $prevNav, $prevDate, $totalUnits);
}

    echo json_encode([
        'success'      => true,
        'source'       => 'db_cache',
        'date'         => date('Y-m-d'),
        'count'        => count($result),
        'data'         => $result,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function _calc(?float $latestNav, ?string $latestDate, ?float $prevNav, ?string $prevDate, float $units): array {
    $dayChangeAmt = null;
    $dayChangePct = null;
    if ($latestNav && $prevNav && $prevNav > 0 && round($latestNav,4) !== round($prevNav,4)) {
        $diff         = $latestNav - $prevNav;
        $dayChangePct = round(($diff / $prevNav) * 100, 3);
        $dayChangeAmt = round($diff * $units, 2);
    }
    return [
        'latest_nav'     => $latestNav,
        'latest_date'    => $latestDate,
        'prev_nav'       => $prevNav,
        'prev_date'      => $prevDate,
        'day_change_amt' => $dayChangeAmt,
        'day_change_pct' => $dayChangePct,
    ];
}