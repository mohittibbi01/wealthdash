<?php
/**
 * WealthDash — NAV 1-Day Change API (Fixed)
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

    $chk = $db->prepare("SELECT id FROM portfolios WHERE id = ? AND user_id = ?");
    $chk->execute([$portfolioId, $userId]);
    if (!$chk->fetchColumn()) {
        ob_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

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

    $fundIds = array_values(array_unique(array_column($holdings, 'fund_id')));

    $unitsMap = [];
    foreach ($holdings as $h) {
        $fid = $h['fund_id'];
        $unitsMap[$fid] = ($unitsMap[$fid] ?? 0) + (float)$h['units'];
    }

    // FIX: Use separate $in placeholders for each query
    $in1 = implode(',', array_fill(0, count($fundIds), '?'));
    $in2 = implode(',', array_fill(0, count($fundIds), '?'));
    $in3 = implode(',', array_fill(0, count($fundIds), '?'));

    // Latest NAV per fund — uses $in1
    $latestStmt = $db->prepare("
        SELECT n1.fund_id, n1.nav AS nav_today, n1.nav_date AS date_today
        FROM nav_history n1
        INNER JOIN (
            SELECT fund_id, MAX(nav_date) AS max_date
            FROM nav_history
            WHERE fund_id IN ($in1)
            GROUP BY fund_id
        ) latest ON n1.fund_id = latest.fund_id AND n1.nav_date = latest.max_date
    ");
    $latestStmt->execute($fundIds);
    $latestNavs = [];
    foreach ($latestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $latestNavs[$row['fund_id']] = $row;
    }

    // Previous NAV per fund — uses $in2 and $in3
    // Get the 2nd most recent nav_date per fund
    $prevStmt = $db->prepare("
        SELECT n1.fund_id, n1.nav AS nav_prev, n1.nav_date AS date_prev
        FROM nav_history n1
        INNER JOIN (
            SELECT fund_id, MAX(nav_date) AS max_date
            FROM nav_history
            WHERE fund_id IN ($in2)
            GROUP BY fund_id
        ) latest ON n1.fund_id = latest.fund_id
        INNER JOIN (
            SELECT n2.fund_id, MAX(n2.nav_date) AS prev_date
            FROM nav_history n2
            INNER JOIN (
                SELECT fund_id, MAX(nav_date) AS max_date
                FROM nav_history
                WHERE fund_id IN ($in3)
                GROUP BY fund_id
            ) lmax ON n2.fund_id = lmax.fund_id AND n2.nav_date < lmax.max_date
            GROUP BY n2.fund_id
        ) prev ON n1.fund_id = prev.fund_id AND n1.nav_date = prev.prev_date
    ");
    // Pass fundIds THREE times (for $in2 and $in3 outer + inner)
    $prevStmt->execute(array_merge($fundIds, $fundIds, $fundIds));
    $prevNavs = [];
    foreach ($prevStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $prevNavs[$row['fund_id']] = $row;
    }

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
