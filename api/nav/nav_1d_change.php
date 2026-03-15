<?php
/**
 * WealthDash — Live 1-Day NAV Change
 * GET /api/nav/nav_1d_change.php
 *
 * Logic:
 *  1. User ke active holdings ke funds fetch karo
 *  2. mfapi.in se per-fund last 2 NAVs fetch karo (last 2 working days)
 *  3. Difference = 1D Change (weekends/holidays automatically skip)
 *  4. nav_history + funds table bhi update karo side-effect se
 *
 * mfapi.in: https://api.mfapi.in/mf/{scheme_code} — free, no auth
 * Returns: newest first, sirf working days ka data
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json');
$currentUser = require_auth();

$ctx = stream_context_create([
    'http' => ['timeout' => 15, 'user_agent' => 'WealthDash/1.0'],
    'ssl'  => ['verify_peer' => false],
]);

try {
    $db = DB::conn();

    // ── Step 1: User ke active holdings fetch karo ──
    $stmt = $db->prepare("
        SELECT
            f.id               AS fund_id,
            f.scheme_code,
            f.latest_nav       AS stored_nav,
            f.latest_nav_date  AS stored_date,
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

    $result = [];

    foreach ($holdings as $h) {
        $fundId     = (int)$h['fund_id'];
        $code       = $h['scheme_code'];
        $totalUnits = (float)$h['total_units'];

        $latestNav  = null;
        $latestDate = null;
        $prevNav    = null;
        $prevDate   = null;

        // ── mfapi.in se last 2 working day NAVs fetch karo ──
        // newest first, sirf working days — weekends skip hote hain automatically
        $raw = @file_get_contents("https://api.mfapi.in/mf/{$code}", false, $ctx);

        if ($raw) {
            $json    = @json_decode($raw, true);
            $navData = $json['data'] ?? [];

            if (isset($navData[0])) {
                $d0 = DateTime::createFromFormat('d-m-Y', $navData[0]['date'] ?? '');
                $latestNav  = (float)$navData[0]['nav'];
                $latestDate = $d0 ? $d0->format('Y-m-d') : null;
            }
            if (isset($navData[1])) {
                $d1 = DateTime::createFromFormat('d-m-Y', $navData[1]['date'] ?? '');
                $prevNav  = (float)$navData[1]['nav'];
                $prevDate = $d1 ? $d1->format('Y-m-d') : null;
            }
        }

        // Fallback to stored nav
        if (!$latestNav && $h['stored_nav']) {
            $latestNav  = (float)$h['stored_nav'];
            $latestDate = $h['stored_date'];
        }

        // Fallback prev: nav_history se
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
            }
        }

        // ── Calculate 1D Change ──
        $dayChangeAmt = null;
        $dayChangePct = null;
        if ($latestNav && $prevNav && $prevNav > 0 && round($latestNav,4) !== round($prevNav,4)) {
            $diff         = $latestNav - $prevNav;
            $dayChangePct = round(($diff / $prevNav) * 100, 3);
            $dayChangeAmt = round($diff * $totalUnits, 2);
        }

        $result[$fundId] = [
            'latest_nav'     => $latestNav,
            'latest_date'    => $latestDate,
            'prev_nav'       => $prevNav,
            'prev_date'      => $prevDate,
            'day_change_amt' => $dayChangeAmt,
            'day_change_pct' => $dayChangePct,
        ];

        // ── Side effect: nav_history + funds table update ──
        if ($latestNav && $latestDate) {
            try {
                $db->prepare("INSERT IGNORE INTO nav_history (fund_id,nav_date,nav) VALUES(?,?,?)")
                   ->execute([$fundId, $latestDate, $latestNav]);
                if ($prevNav && $prevDate) {
                    $db->prepare("INSERT IGNORE INTO nav_history (fund_id,nav_date,nav) VALUES(?,?,?)")
                       ->execute([$fundId, $prevDate, $prevNav]);
                }
                $db->prepare("
                    UPDATE funds SET
                        prev_nav        = IF(latest_nav_date IS NULL OR latest_nav_date < ?, latest_nav, prev_nav),
                        prev_nav_date   = IF(latest_nav_date IS NULL OR latest_nav_date < ?, latest_nav_date, prev_nav_date),
                        latest_nav      = IF(latest_nav_date IS NULL OR latest_nav_date < ?, ?, latest_nav),
                        latest_nav_date = IF(latest_nav_date IS NULL OR latest_nav_date < ?, ?, latest_nav_date),
                        updated_at      = NOW()
                    WHERE id = ?
                ")->execute([$latestDate,$latestDate,$latestDate,$latestNav,$latestDate,$latestDate,$fundId]);
            } catch (Exception $ignored) {}
        }
    }

    echo json_encode([
        'success' => true,
        'source'  => 'mfapi',
        'date'    => date('Y-m-d'),
        'count'   => count($result),
        'data'    => $result,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}