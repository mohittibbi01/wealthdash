<?php
/**
 * WealthDash — NAV 1-Day Change API
 * Returns today's vs yesterday's NAV change for all holdings in a portfolio.
 * Called by load1DayChange() in mf.js
 *
 * GET /api/nav/nav_1d_change.php?portfolio_id=X
 *
 * Response:
 * { "success": true, "data": { "fund_id": { "day_change_amt": X, "day_change_pct": X, ... } } }
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
$userId      = (int)$currentUser['id'];

set_exception_handler(function (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

try {
    $db          = DB::conn();
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);

    // If no portfolio_id, get default portfolio
    if (!$portfolioId) {
        $stmt = $db->prepare("SELECT id FROM portfolios WHERE user_id = ? AND is_default = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $portfolioId = (int)$stmt->fetchColumn();
    }

    if (!$portfolioId) {
        ob_clean();
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    // Verify portfolio belongs to user
    $chk = $db->prepare("SELECT id FROM portfolios WHERE id = ? AND user_id = ?");
    $chk->execute([$portfolioId, $userId]);
    if (!$chk->fetchColumn()) {
        ob_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    // Get active holdings
    $holdingsStmt = $db->prepare("
        SELECT mh.fund_id, mh.units
        FROM mf_holdings mh
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
    ");
    $holdingsStmt->execute([$portfolioId]);
    $holdings = $holdingsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($holdings)) {
        ob_clean();
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $fundIds = array_unique(array_column($holdings, 'fund_id'));
    $in      = implode(',', array_fill(0, count($fundIds), '?'));

    // Build units map
    $unitsMap = [];
    foreach ($holdings as $h) {
        $fid = $h['fund_id'];
        $unitsMap[$fid] = ($unitsMap[$fid] ?? 0) + (float)$h['units'];
    }

    // Latest NAV per fund
    $latestStmt = $db->prepare("
        SELECT n1.fund_id, n1.nav AS nav_today, n1.nav_date AS date_today
        FROM nav_history n1
        WHERE n1.fund_id IN ($in)
          AND n1.nav_date = (SELECT MAX(n2.nav_date) FROM nav_history n2 WHERE n2.fund_id = n1.fund_id)
    ");
    $latestStmt->execute($fundIds);
    $latestNavs = [];
    foreach ($latestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $latestNavs[$row['fund_id']] = $row;
    }

    // Previous NAV per fund
    $prevStmt = $db->prepare("
        SELECT n1.fund_id, n1.nav AS nav_prev, n1.nav_date AS date_prev
        FROM nav_history n1
        WHERE n1.fund_id IN ($in)
          AND n1.nav_date = (
              SELECT MAX(n2.nav_date) FROM nav_history n2
              WHERE n2.fund_id = n1.fund_id
                AND n2.nav_date < (SELECT MAX(n3.nav_date) FROM nav_history n3 WHERE n3.fund_id = n1.fund_id)
          )
    ");
    $prevStmt->execute($fundIds);
    $prevNavs = [];
    foreach ($prevStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $prevNavs[$row['fund_id']] = $row;
    }

    // Build result
    $result = [];
    foreach ($fundIds as $fundId) {
        $latest = $latestNavs[$fundId] ?? null;
        $prev   = $prevNavs[$fundId]   ?? null;
        $units  = $unitsMap[$fundId]   ?? 0;

        if (!$latest) {
            $result[$fundId] = ['day_change_amt' => null, 'day_change_pct' => null, 'nav_today' => null, 'nav_prev' => null];
            continue;
        }

        $navToday = (float)$latest['nav_today'];
        $navPrev  = $prev ? (float)$prev['nav_prev'] : null;

        if ($navPrev !== null && $navPrev > 0) {
            $navChangePct = round(($navToday - $navPrev) / $navPrev * 100, 4);
            $navChangeAmt = round(($navToday - $navPrev) * $units, 2);
        } else {
            $navChangePct = null;
            $navChangeAmt = null;
        }

        $result[$fundId] = [
            'day_change_amt' => $navChangeAmt,
            'day_change_pct' => $navChangePct,
            'nav_today'      => $navToday,
            'nav_prev'       => $navPrev,
            'date_today'     => $latest['date_today'],
            'date_prev'      => $prev['date_prev'] ?? null,
        ];
    }

    ob_clean();
    echo json_encode(['success' => true, 'data' => $result]);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
