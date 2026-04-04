<?php
/**
 * WealthDash — NAV Proxy API (t163)
 * Local cache for MFAPI responses — serves screener drawer charts faster
 * 
 * GET /api/mutual_funds/nav_proxy.php?fund_id=123&period=1Y
 * Periods: 1M, 3M, 6M, 1Y, 3Y, 5Y, ALL
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

$fund_id = (int)($_GET['fund_id'] ?? 0);
$period  = strtoupper(trim($_GET['period'] ?? '1Y'));
$scheme_code = trim($_GET['scheme_code'] ?? '');

$validPeriods = ['1M','3M','6M','1Y','3Y','5Y','ALL'];
if (!$fund_id || !in_array($period, $validPeriods)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid params']);
    exit;
}

try {
    $db = getDB();

    // ── 1. Check DB cache (TTL = 24 hours) ───────────────────────────────
    $cache = $db->prepare("
        SELECT data_json, data_points, source, cached_at
        FROM nav_proxy_cache
        WHERE fund_id = ? AND period = ?
    ");
    $cache->execute([$fund_id, $period]);
    $cached = $cache->fetch(PDO::FETCH_ASSOC);

    if ($cached && (time() - strtotime($cached['cached_at'])) < 86400) {
        $result = json_decode($cached['data_json'], true);
        $result['_cache'] = 'hit';
        $result['_points'] = (int)$cached['data_points'];
        $result['_source'] = $cached['source'];
        echo json_encode($result);
        exit;
    }

    // ── 2. Build from nav_history (fast, local) ───────────────────────────
    $dayMap = [
        '1M'  => 30,
        '3M'  => 90,
        '6M'  => 180,
        '1Y'  => 365,
        '3Y'  => 1095,
        '5Y'  => 1825,
        'ALL' => 36500,
    ];
    $days = $dayMap[$period] ?? 365;

    if ($period === 'ALL') {
        $rows = $db->prepare("
            SELECT nav_date AS `date`, ROUND(nav, 4) AS nav
            FROM nav_history
            WHERE fund_id = ?
            ORDER BY nav_date ASC
        ");
        $rows->execute([$fund_id]);
    } else {
        $rows = $db->prepare("
            SELECT nav_date AS `date`, ROUND(nav, 4) AS nav
            FROM nav_history
            WHERE fund_id = ?
              AND nav_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY nav_date ASC
        ");
        $rows->execute([$fund_id, $days]);
    }

    $data = $rows->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. Fallback: MFAPI if insufficient local data ─────────────────────
    $source = 'nav_history';
    if (count($data) < 5 && $scheme_code) {
        $mfapiUrl = "https://api.mfapi.in/mf/{$scheme_code}";
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => "User-Agent: WealthDash/1.0\r\n",
        ]]);
        $raw = @file_get_contents($mfapiUrl, false, $ctx);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (!empty($decoded['data'])) {
                $allNav = $decoded['data'];
                // Filter by period
                if ($period !== 'ALL') {
                    $cutoff = date('d-m-Y', strtotime("-{$days} days"));
                    $allNav = array_filter($allNav, function($r) use ($cutoff) {
                        return strtotime(str_replace('-', '/', $r['date'])) >= strtotime(str_replace('-', '/', $cutoff));
                    });
                }
                // Convert date format DD-MM-YYYY → YYYY-MM-DD
                $data = array_values(array_map(function($r) {
                    [$d,$m,$y] = explode('-', $r['date']);
                    return ['date' => "{$y}-{$m}-{$d}", 'nav' => $r['nav']];
                }, $allNav));
                // Sort ascending
                usort($data, fn($a,$b) => strcmp($a['date'],$b['date']));
                $source = 'mfapi';
            }
        }
    }

    // ── 4. Calculate period return ────────────────────────────────────────
    $periodReturn = null;
    if (count($data) >= 2) {
        $first = (float)$data[0]['nav'];
        $last  = (float)$data[count($data)-1]['nav'];
        if ($first > 0) {
            $periodReturn = round(($last - $first) / $first * 100, 2);
        }
    }

    // ── 5. Cache the result ───────────────────────────────────────────────
    $payload = [
        'success'       => true,
        'data'          => $data,
        'points'        => count($data),
        'period'        => $period,
        'period_return' => $periodReturn,
        'source'        => $source,
        '_cache'        => 'miss',
    ];

    if (count($data) > 0) {
        $jsonStr = json_encode(['success' => true, 'data' => $data, 'period_return' => $periodReturn]);
        try {
            $upsert = $db->prepare("
                INSERT INTO nav_proxy_cache (fund_id, period, data_json, data_points, source)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    data_json = VALUES(data_json),
                    data_points = VALUES(data_points),
                    source = VALUES(source),
                    cached_at = NOW()
            ");
            $upsert->execute([$fund_id, $period, $jsonStr, count($data), $source]);
        } catch (Exception $e) {
            // Cache write fail — non-fatal
        }
    }

    echo json_encode($payload);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
