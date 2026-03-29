<?php
/**
 * WealthDash — NPS NAV History
 * GET /api/?action=nps_nav_history&scheme_id=&from=YYYY-MM-DD&to=YYYY-MM-DD
 * GET /api/?action=nps_nav_history&scheme_id=&date=YYYY-MM-DD   (single)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json');

$schemeId = (int)($_GET['scheme_id'] ?? 0);
$date     = trim($_GET['date'] ?? '');
$from     = trim($_GET['from'] ?? '');
$to       = trim($_GET['to']   ?? '');

if ($schemeId <= 0) {
    echo json_encode(['success' => false, 'message' => 'scheme_id required']);
    exit;
}

try {
    $db = DB::conn();

    if (!empty($date)) {
        // ── Single date — exact or nearest previous ──────────────────────
        $stmt = $db->prepare("
            SELECT nav_date, nav FROM nps_nav_history
            WHERE scheme_id = ? AND nav_date <= ?
            ORDER BY nav_date DESC
            LIMIT 1
        ");
        $stmt->execute([$schemeId, $date]);
        $row = $stmt->fetch();

        if ($row) {
            echo json_encode([
                'success'   => true,
                'scheme_id' => $schemeId,
                'nav_date'  => $row['nav_date'],
                'nav'       => (float)$row['nav'],
                'exact'     => ($row['nav_date'] === $date),
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No NAV found for this date']);
        }

    } elseif (!empty($from) && !empty($to)) {
        // ── Date range ───────────────────────────────────────────────────
        $stmt = $db->prepare("
            SELECT nav_date, nav FROM nps_nav_history
            WHERE scheme_id = ? AND nav_date BETWEEN ? AND ?
            ORDER BY nav_date ASC
        ");
        $stmt->execute([$schemeId, $from, $to]);
        $rows = $stmt->fetchAll();

        // Also pull scheme meta + contributions in this period
        $meta = DB::fetchOne(
            "SELECT scheme_name, pfm_name, tier, asset_class, latest_nav, latest_nav_date FROM nps_schemes WHERE id = ?",
            [$schemeId]
        );

        echo json_encode([
            'success'   => true,
            'scheme_id' => $schemeId,
            'from'      => $from,
            'to'        => $to,
            'count'     => count($rows),
            'meta'      => $meta ?: [],
            'data'      => array_map(fn($r) => [
                'date' => $r['nav_date'],
                'nav'  => (float)$r['nav'],
            ], $rows),
        ]);

    } else {
        // ── Latest NAV fallback ──────────────────────────────────────────
        $scheme = DB::fetchOne(
            "SELECT scheme_name, pfm_name, tier, asset_class, latest_nav, latest_nav_date FROM nps_schemes WHERE id = ?",
            [$schemeId]
        );

        if ($scheme) {
            echo json_encode([
                'success'         => true,
                'scheme_id'       => $schemeId,
                'scheme_name'     => $scheme['scheme_name'],
                'pfm_name'        => $scheme['pfm_name'],
                'tier'            => $scheme['tier'],
                'asset_class'     => $scheme['asset_class'],
                'latest_nav'      => $scheme['latest_nav'] ? (float)$scheme['latest_nav'] : null,
                'latest_nav_date' => $scheme['latest_nav_date'],
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Scheme not found']);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
