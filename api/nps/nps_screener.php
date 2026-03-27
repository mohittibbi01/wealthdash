<?php
/**
 * WealthDash — NPS Screener API (t100)
 * Standalone (accessed directly, like mf_screener)
 * GET params: q, pfm, tier, asset_class, sort, page, per_page, compare
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();
require_auth();

try {

$q          = trim($_GET['q']          ?? '');
$pfm        = clean($_GET['pfm']       ?? '');
$tier       = clean($_GET['tier']      ?? '');
$assetClass = clean($_GET['asset_class'] ?? '');
$sort       = clean($_GET['sort']      ?? 'return_1y');
$page       = max(1, (int)($_GET['page']     ?? 1));
$perPage    = min(max(1, (int)($_GET['per_page'] ?? 40)), 100);
$offset     = ($page - 1) * $perPage;
$compare    = array_filter(array_map('intval', explode(',', $_GET['compare'] ?? '')));

// ── WHERE builder ─────────────────────────────────────────────────────────────
$where  = ['s.is_active = 1'];
$params = [];

if ($q !== '') {
    $like    = '%' . $q . '%';
    $where[] = '(s.scheme_name LIKE ? OR s.pfm_name LIKE ? OR s.scheme_code LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($pfm)        { $where[] = 's.pfm_name = ?';    $params[] = $pfm; }
if ($tier)       { $where[] = 's.tier = ?';         $params[] = $tier; }
if ($assetClass) { $where[] = 's.asset_class = ?';  $params[] = $assetClass; }

$whereStr = implode(' AND ', $where);

// ── SORT ─────────────────────────────────────────────────────────────────────
$sortMap = [
    'return_1y'     => 's.return_1y DESC',
    'return_3y'     => 's.return_3y DESC',
    'return_5y'     => 's.return_5y DESC',
    'return_since'  => 's.return_since DESC',
    'nav_desc'      => 's.latest_nav DESC',
    'nav_asc'       => 's.latest_nav ASC',
    'name'          => 's.scheme_name ASC',
    'aum'           => 's.aum_cr DESC',
];
$orderBy = $sortMap[$sort] ?? 's.return_1y DESC';

// ── TOTAL COUNT ───────────────────────────────────────────────────────────────
$totalCount = (int)(DB::fetchVal(
    "SELECT COUNT(*) FROM nps_schemes s WHERE {$whereStr}", $params
) ?: 0);

// ── MAIN QUERY ────────────────────────────────────────────────────────────────
$schemes = DB::fetchAll(
    "SELECT s.id, s.scheme_name, s.pfm_name, s.tier, s.asset_class,
            s.scheme_code, s.latest_nav, s.latest_nav_date,
            s.return_1y, s.return_3y, s.return_5y, s.return_since,
            s.aum_cr, s.nav_returns_updated_at,
            (SELECT COUNT(*) FROM nps_nav_history nh WHERE nh.scheme_id=s.id) AS nav_history_count
     FROM nps_schemes s
     WHERE {$whereStr}
     ORDER BY {$orderBy}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// ── COMPARE MODE (up to 2 schemes side-by-side) ───────────────────────────────
$compareData = [];
if (!empty($compare)) {
    $ids = implode(',', array_slice($compare, 0, 2));
    $compareSchemes = DB::fetchAll(
        "SELECT s.*,
                (SELECT COUNT(*) FROM nps_nav_history nh WHERE nh.scheme_id=s.id) AS nav_history_count
         FROM nps_schemes s WHERE s.id IN ({$ids})"
    );
    foreach ($compareSchemes as $cs) {
        // Get NAV chart data (last 1 year, monthly points)
        $navPoints = DB::fetchAll(
            "SELECT nav_date, nav FROM nps_nav_history
             WHERE scheme_id=? AND nav_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
             ORDER BY nav_date ASC",
            [$cs['id']]
        );
        $cs['nav_chart'] = $navPoints;
        $compareData[]   = $cs;
    }
}

// ── META (for filter dropdowns) ────────────────────────────────────────────────
$pfmList        = DB::fetchAll("SELECT DISTINCT pfm_name FROM nps_schemes WHERE is_active=1 ORDER BY pfm_name");
$assetClassList = DB::fetchAll("SELECT DISTINCT asset_class FROM nps_schemes WHERE is_active=1 ORDER BY asset_class");
$statsRow       = DB::fetchRow(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN return_1y IS NOT NULL THEN 1 ELSE 0 END) AS with_returns,
            SUM(CASE WHEN latest_nav_date = CURDATE() THEN 1 ELSE 0 END) AS nav_today
     FROM nps_schemes WHERE is_active=1"
);

ob_end_clean();
echo json_encode([
    'success'  => true,
    'schemes'  => $schemes,
    'total'    => $totalCount,
    'page'     => $page,
    'per_page' => $perPage,
    'pages'    => (int)ceil($totalCount / $perPage),
    'compare'  => $compareData,
    'meta' => [
        'pfms'         => array_column($pfmList, 'pfm_name'),
        'asset_classes'=> array_column($assetClassList, 'asset_class'),
        'stats'        => $statsRow,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
