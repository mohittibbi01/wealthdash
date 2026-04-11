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
$sortinoMin = isset($_GET['sortino_min']) && is_numeric($_GET['sortino_min']) ? (float)$_GET['sortino_min'] : null; // t126
$styleBoxes = (array)($_GET['style_box'] ?? []);  // t179
$minStars   = isset($_GET['min_stars']) && is_numeric($_GET['min_stars']) ? (int)$_GET['min_stars'] : null; // t111

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

// t126: Sortino Ratio filter (only if column exists)
if ($sortinoMin !== null) {
    try {
        $db->query("SELECT sortino_ratio FROM funds LIMIT 1");
        $where[]  = 'f.sortino_ratio >= ?';
        $params[] = $sortinoMin;
        $whereSQL = 'WHERE ' . implode(' AND ', $where);
    } catch(Exception $e) {}
}

// t179: Style Box filter (only if column exists)
if (!empty($styleBoxes)) {
    $styleBoxes = array_filter($styleBoxes, fn($s) => preg_match('/^(large|mid|small)_(value|blend|growth)$/', $s));
    if (!empty($styleBoxes)) {
        try {
            $db->query("SELECT style_size FROM funds LIMIT 1");
            // Build OR conditions: (style_size='large' AND style_value='growth') OR ...
            $styleConds = [];
            foreach ($styleBoxes as $sb) {
                [$sz, $sv] = explode('_', $sb, 2);
                $styleConds[] = "(f.style_size = ? AND f.style_value = ?)";
                $params[] = $sz; $params[] = $sv;
            }
            $where[]  = '(' . implode(' OR ', $styleConds) . ')';
            $whereSQL = 'WHERE ' . implode(' AND ', $where);
        } catch(Exception $e) {}
    }
}

// t111: WD Star Rating filter (uses denorm wd_stars column on funds)
if ($minStars !== null && $minStars >= 1 && $minStars <= 5) {
    try {
        $db->query("SELECT wd_stars FROM funds LIMIT 1");
        $where[]  = 'f.wd_stars >= ?';
        $params[] = $minStars;
        $whereSQL = 'WHERE ' . implode(' AND ', $where);
    } catch(Exception $e) {
        // wd_stars column not yet created — run migration 23_fund_ratings.sql
    }
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
    // t164+t166: Sharpe + Max Drawdown sorts
    'sharpe_desc'   => 'IF(f.sharpe_ratio IS NULL,1,0) ASC, f.sharpe_ratio DESC',
    'sharpe_asc'    => 'IF(f.sharpe_ratio IS NULL,1,0) ASC, f.sharpe_ratio ASC',
    'mdd_asc'       => 'IF(f.max_drawdown IS NULL,1,0) ASC, f.max_drawdown ASC',
    'mdd_desc'      => 'IF(f.max_drawdown IS NULL,1,0) ASC, f.max_drawdown DESC',
    // t27: 1Y/3Y/5Y return sorts
    'ret1y_desc'    => 'IF(f.returns_1y IS NULL,1,0) ASC, f.returns_1y DESC',
    'ret1y_asc'     => 'IF(f.returns_1y IS NULL,1,0) ASC, f.returns_1y ASC',
    'ret3y_desc'    => 'IF(f.returns_3y IS NULL,1,0) ASC, f.returns_3y DESC',
    'ret3y_asc'     => 'IF(f.returns_3y IS NULL,1,0) ASC, f.returns_3y ASC',
    'ret5y_desc'    => 'IF(f.returns_5y IS NULL,1,0) ASC, f.returns_5y DESC',
    'ret5y_asc'     => 'IF(f.returns_5y IS NULL,1,0) ASC, f.returns_5y ASC',
    'wd_stars_desc' => 'IF(f.wd_stars IS NULL,1,0) ASC, f.wd_stars DESC, f.scheme_name ASC',  // t111
    'wd_stars_asc'  => 'IF(f.wd_stars IS NULL,1,0) ASC, f.wd_stars ASC, f.scheme_name ASC',   // t111
];
// expense/risk/returns sort only if column exists
if (!$hasExpCol   && in_array($sort, ['expense','expense_desc'])) $sort = 'name';
if (!$hasSharpCol && in_array($sort, ['sharpe_desc','sharpe_asc','mdd_asc','mdd_desc'])) $sort = 'name';
if (!$hasRetCol   && in_array($sort, ['ret1y_desc','ret1y_asc','ret3y_desc','ret3y_asc','ret5y_desc','ret5y_asc'])) $sort = 'name';
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
$hasRetCol = false; try { $db->query("SELECT returns_1y FROM funds LIMIT 1"); $hasRetCol=true; } catch(Exception $e){}
// t164+t166: Sharpe Ratio + Max Drawdown (optional columns)
$hasSharpCol  = false; try { $db->query("SELECT sharpe_ratio  FROM funds LIMIT 1"); $hasSharpCol=true;  } catch(Exception $e){}
$hasSortinoCol= false; try { $db->query("SELECT sortino_ratio FROM funds LIMIT 1"); $hasSortinoCol=true;} catch(Exception $e){}
$hasCatAvgCol = false; try { $db->query("SELECT category_avg_1y FROM funds LIMIT 1"); $hasCatAvgCol=true;} catch(Exception $e){}
// t179: Style Box columns
$hasStyleCol  = false; try { $db->query("SELECT style_size FROM funds LIMIT 1"); $hasStyleCol=true; } catch(Exception $e){}
$styleColSQL  = $hasStyleCol ? ', f.style_size, f.style_value, f.style_drift_note' : '';
// t111: WD Star Rating column
$hasStarsCol  = false; try { $db->query("SELECT wd_stars FROM funds LIMIT 1"); $hasStarsCol=true; } catch(Exception $e){}
$starsColSQL  = $hasStarsCol ? ', f.wd_stars' : '';
$mgrColSQL   = $hasMgrCol   ? ', f.fund_manager, f.manager_since' : '';
$incColSQL   = $hasIncCol   ? ', f.inception_date' : '';
$retColSQL   = $hasRetCol   ? ', f.returns_1y, f.returns_3y, f.returns_5y, f.returns_updated_at' : '';
$sharpColSQL = ($hasSharpCol ? ', f.sharpe_ratio, f.max_drawdown, f.max_drawdown_date' : '')
             . ($hasSortinoCol ? ', f.sortino_ratio' : '')
             . ($hasCatAvgCol  ? ', f.category_avg_1y, f.category_avg_3y' : '')
             . $styleColSQL
             . $starsColSQL;

$mainSQL = "
    SELECT f.id, f.scheme_code, f.scheme_name, f.category, f.option_type,
           f.latest_nav, f.latest_nav_date, f.min_ltcg_days, f.lock_in_days,
           f.highest_nav, f.highest_nav_date
           $expColSQL $prevNavSQL $riskColSQL $aumColSQL $mgrColSQL $incColSQL $retColSQL $sharpColSQL,
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
        'returns_1y'      => ($hasRetCol  && isset($r['returns_1y'])  && $r['returns_1y']  !== null) ? round((float)$r['returns_1y'],  2) : null,
        'returns_3y'      => ($hasRetCol  && isset($r['returns_3y'])  && $r['returns_3y']  !== null) ? round((float)$r['returns_3y'],  2) : null,
        'returns_5y'      => ($hasRetCol  && isset($r['returns_5y'])  && $r['returns_5y']  !== null) ? round((float)$r['returns_5y'],  2) : null,
        'returns_updated' => ($hasRetCol  && isset($r['returns_updated_at'])) ? $r['returns_updated_at'] : null,
        // t164+t166: Sharpe + Max Drawdown from cron
        'sharpe_ratio'    => ($hasSharpCol && isset($r['sharpe_ratio'])   && $r['sharpe_ratio']   !== null) ? round((float)$r['sharpe_ratio'], 3)  : null,
        'max_drawdown'    => ($hasSharpCol && isset($r['max_drawdown'])    && $r['max_drawdown']    !== null) ? round((float)$r['max_drawdown'], 2)   : null,
        'max_drawdown_date'=> ($hasSharpCol && isset($r['max_drawdown_date'])) ? $r['max_drawdown_date'] : null,
        // t165: Sortino Ratio
        'sortino_ratio'   => ($hasSortinoCol && isset($r['sortino_ratio']) && $r['sortino_ratio']  !== null) ? round((float)$r['sortino_ratio'], 3) : null,
        // t167: Category Averages for peer comparison
        'category_avg_1y' => ($hasCatAvgCol  && isset($r['category_avg_1y']) && $r['category_avg_1y'] !== null) ? round((float)$r['category_avg_1y'], 2) : null,
        'category_avg_3y' => ($hasCatAvgCol  && isset($r['category_avg_3y']) && $r['category_avg_3y'] !== null) ? round((float)$r['category_avg_3y'], 2) : null,
        // t179: Style Box
        'style_size'       => ($hasStyleCol && isset($r['style_size']))       ? $r['style_size']       : null,
        'style_value'      => ($hasStyleCol && isset($r['style_value']))      ? $r['style_value']      : null,
        'style_drift_note' => ($hasStyleCol && isset($r['style_drift_note'])) ? $r['style_drift_note'] : null,
        // t111: WD Star Rating (from cron-calculated fund_ratings table)
        'wd_stars'         => ($hasStarsCol && isset($r['wd_stars']) && $r['wd_stars'] !== null) ? (int)$r['wd_stars'] : null,
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