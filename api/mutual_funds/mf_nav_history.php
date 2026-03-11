<?php
/**
 * WealthDash — MF NAV History
 * GET /api/mutual_funds/mf_nav_history.php?fund_id=&date=YYYY-MM-DD
 * GET /api/mutual_funds/mf_nav_history.php?fund_id=&from=&to=    (range)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json');
$currentUser = require_auth();

$fund_id = (int)($_GET['fund_id'] ?? 0);
$date    = trim($_GET['date'] ?? '');
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');

if ($fund_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'fund_id required']);
    exit;
}

try {
    $db = DB::conn();

    if (!empty($date)) {
        // ── Single date lookup ──────────────────────────────
        // Try exact, else nearest previous date
        $stmt = $db->prepare("
            SELECT nav_date, nav FROM nav_history
            WHERE fund_id = ? AND nav_date <= ?
            ORDER BY nav_date DESC
            LIMIT 1
        ");
        $stmt->execute([$fund_id, $date]);
        $row = $stmt->fetch();

        if ($row) {
            echo json_encode([
                'success'  => true,
                'fund_id'  => $fund_id,
                'nav_date' => $row['nav_date'],
                'nav'      => (float)$row['nav'],
                'exact'    => ($row['nav_date'] === $date)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No NAV found for this date']);
        }

    } elseif (!empty($from) && !empty($to)) {
        // ── Date range ──────────────────────────────────────
        $stmt = $db->prepare("
            SELECT nav_date, nav FROM nav_history
            WHERE fund_id = ? AND nav_date BETWEEN ? AND ?
            ORDER BY nav_date ASC
        ");
        $stmt->execute([$fund_id, $from, $to]);
        $rows = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'fund_id' => $fund_id,
            'from'    => $from,
            'to'      => $to,
            'count'   => count($rows),
            'data'    => array_map(fn($r) => [
                'date' => $r['nav_date'],
                'nav'  => (float)$r['nav']
            ], $rows)
        ]);

    } else {
        // ── Latest NAV ──────────────────────────────────────
        $stmt = $db->prepare("
            SELECT f.latest_nav, f.latest_nav_date, f.scheme_name,
                   f.highest_nav, f.highest_nav_date
            FROM funds f WHERE f.id = ?
        ");
        $stmt->execute([$fund_id]);
        $fund = $stmt->fetch();

        if ($fund) {
            echo json_encode([
                'success'          => true,
                'fund_id'          => $fund_id,
                'scheme_name'      => $fund['scheme_name'],
                'latest_nav'       => $fund['latest_nav'] ? (float)$fund['latest_nav'] : null,
                'latest_nav_date'  => $fund['latest_nav_date'],
                'highest_nav'      => $fund['highest_nav'] ? (float)$fund['highest_nav'] : null,
                'highest_nav_date' => $fund['highest_nav_date'],
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Fund not found']);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

