<?php
/**
 * WealthDash — Fund Screener API (clean rewrite)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
// Suppress PHP warnings/notices from corrupting JSON
error_reporting(0);
ini_set('display_errors', '0');
// Buffer output so stray warnings/errors don't corrupt JSON
ob_start();
require_auth();

// Global exception handler — always return JSON, never HTML
set_exception_handler(function(Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});

try {

$db = DB::conn();

// ── Inputs ──────────────────────────────────────────────
$q          = trim($_GET['q'] ?? '');
$categories = (array)($_GET['category']    ?? []);
$fundHouses = (array)($_GET['fund_house']  ?? []);
$optionType = $_GET['option_type'] ?? 'all';
$planType   = $_GET['plan_type']   ?? 'all';
$ltcgDays   = (int)($_GET['ltcg_days']    ?? 0);
$hasLockin  = isset($_GET['has_lockin']) ? (int)$_GET['has_lockin'] : -1;
$expMin     = isset($_GET['exp_min']) && is_numeric($_GET['exp_min']) ? (float)$_GET['exp_min'] : null;
$expMax     = isset($_GET['exp_max']) && is_numeric($_GET['exp_max']) ? (float)$_GET['exp_max'] : null;
$hasTer     = isset($_GET['has_ter']) && $_GET['has_ter'] === '1';
$sort       = $_GET['sort']     ?? 'name';
$page       = max(1, (int)($_GET['page']     ?? 1));
$perPage    = min(max(1,(int)($_GET['per_page'] ?? 50)), 100);
$offset     = ($page - 1) * $perPage;
$manager    = trim($_GET['manager']   ?? '');  // t67
$fundAge    = trim($_GET['fund_age']  ?? '');  // t98

// ── WHERE builder ───────────────────────────────────────
$where  = ['f.is_active = 1'];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $where[]  = '(f.scheme_name LIKE ? OR f.scheme_code LIKE ? OR fh.short_name LIKE ? OR fh.name LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if (!empty($categories)) {
    $ph = implode(',', array_fill(0, count($categories), '?'));
    $where[] = "f.category IN ($ph)";
    array_push($params, ...$categories);
}
if (!empty($fundHouses)) {
    $ph = implode(',', array_fill(0, count($fundHouses), '?'));
    $where[] = "COALESCE(fh.short_name, fh.name) IN ($ph)";
    array_push($params, ...$fundHouses);
}
if ($optionType === 'growth') { $where[] = "f.option_type = 'growth'"; }
elseif ($optionType === 'idcw') { $where[] = "f.option_type IN ('idcw','dividend')"; }

if ($planType === 'direct')  { $where[] = 'f.scheme_name LIKE ?'; $params[] = '%Direct%'; }
elseif ($planType === 'regular') { $where[] = 'f.scheme_name NOT LIKE ?'; $params[] = '%Direct%'; }

if ($ltcgDays > 0) { $where[] = 'f.min_ltcg_days = ?'; $params[] = $ltcgDays; }
if ($hasLockin === 1) { $where[] = 'f.lock_in_days > 0'; }
elseif ($hasLockin === 0) { $where[] = 'f.lock_in_days = 0'; }

// Expense ratio filters — only if column exists
$hasExpCol = false;
try {
    $db->query("SELECT expense_ratio FROM funds LIMIT 1");
    $hasExpCol = true;
} catch (Exception $e) { $hasExpCol = false; }

if ($hasExpCol) {
    if ($expMin !== null) { $where[] = 'f.expense_ratio >= ?'; $params[] = $expMin; }
    if ($expMax !== null) { $where[] = 'f.expense_ratio <= ?'; $params[] = $expMax; }
    if ($hasTer) { $where[] = 'f.expense_ratio IS NOT NULL AND f.expense_ratio > 0'; }
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// t67: Fund manager filter (only if column exists)
if ($manager !== '') {
    try {
        $db->query("SELECT fund_manager FROM funds LIMIT 1");
        $where[] = 'f.fund_manager LIKE ?';
        $params[] = '%' . $manager . '%';
        $whereSQL = 'WHERE ' . implode(' AND ', $where);
    } catch(Exception $e) {}
}

// t98: Fund age filter (only if inception_date column exists)
if ($fundAge !== '') {
    try {
        $db->query("SELECT inception_date FROM funds LIMIT 1");
        $today = date('Y-m-d');
        if ($fundAge === '1') {
            $where[] = "f.inception_date >= DATE_SUB(?, INTERVAL 1 YEAR)"; $params[] = $today;
        } elseif ($fundAge === '3') {
            $where[] = "f.inception_date BETWEEN DATE_SUB(?, INTERVAL 3 YEAR) AND DATE_SUB(?, INTERVAL 1 YEAR)";
            $params[] = $today; $params[] = $today;
        } elseif ($fundAge === '5') {
            $where[] = "f.inception_date BETWEEN DATE_SUB(?, INTERVAL 5 YEAR) AND DATE_SUB(?, INTERVAL 3 YEAR)";
            $params[] = $today; $params[] = $today;
        } elseif ($fundAge === '5+') {
            $where[] = "f.inception_date <= DATE_SUB(?, INTERVAL 5 YEAR)"; $params[] = $today;
        }
        $whereSQL = 'WHERE ' . implode(' AND ', $where);
    } catch(Exception $e) {}
}

// ── ORDER BY ─────────────────────────────────────────────
$sortMap = [
    'name'          => 'IF(f.latest_nav IS NULL OR f.latest_nav=0,1,0) ASC, f.scheme_name ASC',
    'name_desc'     => 'f.scheme_name DESC',
    'nav_desc'      => 'IF(f.latest_nav IS NULL OR f.latest_nav=0,1,0) ASC, f.latest_nav DESC',
    'nav_asc'       => 'IF(f.latest_nav IS NULL OR f.latest_nav=0,1,0) ASC, f.latest_nav ASC',
    'ltcg'          => 'f.min_ltcg_days ASC, f.scheme_name ASC',
    'ltcg_desc'     => 'f.min_ltcg_days DESC, f.scheme_name ASC',
    'dd_asc'        => 'CASE WHEN f.highest_nav>0 THEN (f.highest_nav-f.latest_nav)/f.highest_nav ELSE 1 END ASC',
    'dd_desc'       => 'CASE WHEN f.highest_nav>0 THEN (f.highest_nav-f.latest_nav)/f.highest_nav ELSE 1 END DESC',
    'expense'       => 'IF(f.expense_ratio IS NULL,1,0) ASC, f.expense_ratio ASC',
    'expense_desc'  => 'IF(f.expense_ratio IS NULL,1,0) ASC, f.expense_ratio DESC',
    'peak_nav_asc'  => 'IF(f.highest_nav IS NULL,1,0) ASC, f.highest_nav ASC',
    'peak_nav_desc' => 'IF(f.highest_nav IS NULL,1,0) ASC, f.highest_nav DESC',
    'house'         => "COALESCE(fh.short_name,fh.name,'') ASC, f.scheme_name ASC",
];
// expense sort only if column exists
if (!$hasExpCol && in_array($sort, ['expense','expense_desc'])) $sort = 'name';
$orderSQL = $sortMap[$sort] ?? $sortMap['name'];

// ── COUNT ────────────────────────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM funds f LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id $whereSQL");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// ── MAIN SELECT ──────────────────────────────────────────
// Build column list dynamically based on available columns
$hasPrevNav = false;
try { $db->query("SELECT prev_nav FROM funds LIMIT 1"); $hasPrevNav = true; } catch(Exception $e){}

// Check for risk_level and aum_crore columns
$hasRiskCol = false;
try { $db->query("SELECT risk_level FROM funds LIMIT 1"); $hasRiskCol = true; } catch(Exception $e){}
$hasAumCol = false;
try { $db->query("SELECT aum_crore FROM funds LIMIT 1"); $hasAumCol = true; } catch(Exception $e){}

$expColSQL  = $hasExpCol  ? ', f.expense_ratio, f.exit_load_pct, f.exit_load_days' : '';
$riskColSQL = $hasRiskCol ? ', f.risk_level' : '';
$aumColSQL  = $hasAumCol  ? ', f.aum_crore'  : '';
$prevNavSQL = $hasPrevNav ? ', f.prev_nav, f.prev_nav_date' : '';
// t67+t98: fund_manager and inception_date (optional columns)
$hasMgrCol = false; try { $db->query("SELECT fund_manager FROM funds LIMIT 1"); $hasMgrCol=true; } catch(Exception $e){}
$hasIncCol = false; try { $db->query("SELECT inception_date FROM funds LIMIT 1"); $hasIncCol=true; } catch(Exception $e){}
$mgrColSQL = $hasMgrCol ? ', f.fund_manager, f.manager_since' : '';
$incColSQL = $hasIncCol ? ', f.inception_date' : '';

$mainSQL = "
    SELECT f.id, f.scheme_code, f.scheme_name, f.category, f.option_type,
           f.latest_nav, f.latest_nav_date, f.min_ltcg_days, f.lock_in_days,
           f.highest_nav, f.highest_nav_date
           $expColSQL $prevNavSQL $riskColSQL $aumColSQL $mgrColSQL $incColSQL,
           COALESCE(fh.short_name, fh.name, '') AS fund_house
    FROM funds f LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id
    $whereSQL ORDER BY $orderSQL LIMIT ? OFFSET ?
";
$allParams = array_merge($params, [(int)$perPage, (int)$offset]);
$stmt = $db->prepare($mainSQL);
$stmt->execute($allParams);
$rows = $stmt->fetchAll();

// ── HELPER FUNCTIONS ─────────────────────────────────────
function broad_type(string $cat): string {
    $c = strtolower($cat);
    if (str_contains($c,'elss')||str_contains($c,'tax sav')) return 'Equity';
    if (str_contains($c,'debt')||str_contains($c,'liquid')||str_contains($c,'overnight')||
        str_contains($c,'money market')||str_contains($c,'gilt')||str_contains($c,'credit')||
        str_contains($c,'duration')||str_contains($c,'floater')||str_contains($c,'corporate bond')||
        str_contains($c,'banking and psu')) return 'Debt';
    if (str_contains($c,'gold')||str_contains($c,'silver')||str_contains($c,'commodit')) return 'Commodity';
    if (str_contains($c,'fund of fund')||str_contains($c,'overseas')||str_contains($c,'international')||
        str_contains($c,'fof')) return 'FoF/Intl';
    if (str_contains($c,'retirement')||str_contains($c,'children')) return 'Solution';
    if (str_contains($c,'equity')||str_contains($c,'index')||str_contains($c,'etf')||
        str_contains($c,'hybrid')||str_contains($c,'arbitrage')||str_contains($c,'multi cap')||
        str_contains($c,'large cap')||str_contains($c,'mid cap')||str_contains($c,'small cap')||
        str_contains($c,'flexi')||str_contains($c,'balanced')||str_contains($c,'thematic')||
        str_contains($c,'sectoral')) return 'Equity';
    return 'Other';
}

function cat_short(string $cat): string {
    $cat = preg_replace('/\b(fund|scheme|mutual)\b/i', '', $cat);
    return trim(preg_replace('/\s+/', ' ', $cat));
}

function is_direct(string $name): bool {
    return stripos($name, 'direct') !== false;
}

function title_case(string $name): string {
    if ($name !== strtoupper($name)) return $name;
    $name = mb_convert_case(strtolower($name), MB_CASE_TITLE);
    return preg_replace_callback('/\b(etf|elss|fof|idcw|bse|nse|uti|hdfc|icici|sbi|dsp|psu|us|uk|nfo|ipo)\b/i',
        fn($m) => strtoupper($m[0]), $name);
}

// ── MAP ROWS ─────────────────────────────────────────────
$funds = array_map(function($r) use ($hasPrevNav, $hasExpCol, $hasRiskCol, $hasAumCol) {
    $latNav  = $r['latest_nav']  ? (float)$r['latest_nav']  : null;
    $highNav = $r['highest_nav'] ? (float)$r['highest_nav'] : null;
    $prevNav = ($hasPrevNav && !empty($r['prev_nav'])) ? (float)$r['prev_nav'] : null;

    $drawdown = null;
    if ($latNav > 0 && $highNav > 0 && $highNav >= $latNav) {
        $dd = round(($highNav - $latNav) / $highNav * 100, 2);
        if ($dd <= 99) $drawdown = $dd;
    }

    $navChange = null;
    if ($prevNav && $prevNav > 0 && $latNav) {
        $navChange = round(($latNav - $prevNav) / $prevNav * 100, 2);
    }

    return [
        'id'              => (int)$r['id'],
        'scheme_code'     => $r['scheme_code'],
        'scheme_name'     => title_case($r['scheme_name'] ?? ''),
        'fund_house'      => $r['fund_house'] ?? '',
        'category'        => $r['category'] ?? '',
        'category_short'  => cat_short($r['category'] ?? ''),
        'broad_type'      => broad_type($r['category'] ?? ''),
        'option_type'     => $r['option_type'],
        'plan_type'       => is_direct($r['scheme_name']) ? 'direct' : 'regular',
        'latest_nav'      => $latNav,
        'latest_nav_date' => $r['latest_nav_date'] ?? null,
        'highest_nav'     => $highNav,
        'highest_nav_date'=> $r['highest_nav_date'] ?? null,
        'drawdown_pct'    => $drawdown,
        'nav_change_pct'  => $navChange,
        'min_ltcg_days'   => (int)$r['min_ltcg_days'],
        'lock_in_days'    => (int)$r['lock_in_days'],
        'expense_ratio'   => ($hasExpCol && isset($r['expense_ratio'])) ? (float)$r['expense_ratio'] ?: null : null,
        'exit_load_pct'   => ($hasExpCol && isset($r['exit_load_pct']))  ? (float)$r['exit_load_pct']  : null,
        'exit_load_days'  => ($hasExpCol && isset($r['exit_load_days'])) ? (int)$r['exit_load_days']   : null,
        'risk_level'      => ($hasRiskCol && isset($r['risk_level']))    ? $r['risk_level']             : null,
        'aum_crore'       => ($hasAumCol  && isset($r['aum_crore']))     ? (float)$r['aum_crore']       : null,
    ];
}, $rows);

// ── FACETS (first page, no filters) ──────────────────────
$sendFacets = $page === 1 && $q === '' && empty($categories) && empty($fundHouses)
              && $optionType === 'all' && $planType === 'all'
              && $ltcgDays === 0 && $hasLockin === -1
              && $expMin === null && $expMax === null && !$hasTer;

$facets = null;
if ($sendFacets) {
    $fhRows = $db->query("
        SELECT COALESCE(fh.short_name, fh.name) AS amc, COUNT(*) AS cnt
        FROM funds f LEFT JOIN fund_houses fh ON fh.id=f.fund_house_id
        WHERE f.is_active=1 AND COALESCE(fh.short_name,fh.name,'') != ''
        GROUP BY fh.id ORDER BY cnt DESC LIMIT 80
    ")->fetchAll();
    $fhFacets = [];
    foreach ($fhRows as $r) $fhFacets[$r['amc']] = (int)$r['cnt'];

    $catRows = $db->query("
        SELECT category, COUNT(*) AS cnt FROM funds
        WHERE is_active=1 AND category IS NOT NULL AND category != ''
        GROUP BY category ORDER BY cnt DESC LIMIT 120
    ")->fetchAll();
    $catFacets = [];
    foreach ($catRows as $r) $catFacets[] = ['category'=>$r['category'],'short'=>cat_short($r['category']),'count'=>(int)$r['cnt']];

    $ltcgRows = $db->query("SELECT min_ltcg_days, COUNT(*) AS cnt FROM funds WHERE is_active=1 GROUP BY min_ltcg_days")->fetchAll();
    $ltcgFacets = [];
    foreach ($ltcgRows as $r) $ltcgFacets[(int)$r['min_ltcg_days']] = (int)$r['cnt'];

    $facets = ['fund_houses'=>$fhFacets,'categories'=>$catFacets,'ltcg_days'=>$ltcgFacets];
}

// ── RESPONSE ─────────────────────────────────────────────
ob_clean(); // discard any stray output before JSON
echo json_encode([
    'success' => true,
    'total'   => $total,
    'page'    => $page,
    'per_page'=> $perPage,
    'pages'   => (int)ceil($total / max(1,$perPage)),
    'data'    => $funds,
    'facets'  => $facets,
], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'DB error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}