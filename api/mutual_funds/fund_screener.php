<?php
/**
 * WealthDash — Fund Screener API
 * Tasks: t63–t69, t93, t96, t98, t108, t111, tv01, tv04
 * Actions: fund_screener | fund_detail | fund_top_performers
 *          fund_compare | fund_filter_meta | recommend_funds
 *          style_box | saved_screens_list | saved_screen_save
 *          saved_screen_delete | fund_house_rankings
 */

// Allow direct AJAX access + router include
if (!defined('WEALTHDASH')) {
    define('WEALTHDASH', true);
    ob_start();
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once APP_ROOT . '/includes/auth_check.php';
    require_once APP_ROOT . '/includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Global error → JSON handler
set_exception_handler(function(Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'fund_screener';
$db          = DB::conn();

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// fund_screener — Main screener with all filters + sort
// ══════════════════════════════════════════════════════════════════════════
case 'fund_screener':
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    // Build WHERE conditions
    $where  = ["f.is_active = 1"];
    $params = [];

    // Category filter
    if (!empty($_GET['category'])) {
        $where[]  = "f.scheme_category = ?";
        $params[] = $_GET['category'];
    }
    // Sub-category filter
    if (!empty($_GET['sub_category'])) {
        $where[]  = "f.scheme_sub_category = ?";
        $params[] = $_GET['sub_category'];
    }
    // Fund house filter
    if (!empty($_GET['fund_house'])) {
        $where[]  = "f.scheme_name LIKE ?";
        $params[] = $_GET['fund_house'];
    }
    // Risk level
    if (!empty($_GET['risk'])) {
        $where[]  = "f.risk_level = ?";
        $params[] = $_GET['risk'];
    }
    // Plan type
    if (!empty($_GET['plan'])) {
        // plan_type not in schema — skip
        // $where[]  = "f.scheme_type = ?";
        $params[] = $_GET['plan'];
    }
    // Returns filters
    foreach (['return_1y','return_3y','return_5y','expense_ratio'] as $col) {
        $minK = "{$col}_min"; $maxK = "{$col}_max";
        if (isset($_GET[$minK]) && is_numeric($_GET[$minK])) {
            $where[] = "f.$col >= ?"; $params[] = (float)$_GET[$minK];
        }
        if (isset($_GET[$maxK]) && is_numeric($_GET[$maxK])) {
            $where[] = "f.$col <= ?"; $params[] = (float)$_GET[$maxK];
        }
    }
    // Alpha > 0 filter
    if (isset($_GET['alpha_positive']) && $_GET['alpha_positive'] == '1') {
        $where[] = "NULL > 0";
    }
    // Rating filter
    if (!empty($_GET['min_stars']) && is_numeric($_GET['min_stars'])) {
        $where[] = "NULL >= ?"; $params[] = (int)$_GET['min_stars'];
    }
    // Fund age filter
    if (!empty($_GET['min_age_years']) && is_numeric($_GET['min_age_years'])) {
        $where[] = "f.inception_date <= ?";
        $params[] = date('Y-m-d', strtotime('-' . (int)$_GET['min_age_years'] . ' years'));
    }
    // AUM filter
    if (!empty($_GET['min_aum']) && is_numeric($_GET['min_aum'])) {
        $where[] = "f.aum_cr >= ?"; $params[] = (float)$_GET['min_aum'];
    }
    // Manager filter (partial name)
    if (!empty($_GET['manager'])) {
        $where[] = "f.fund_manager LIKE ?"; $params[] = '%' . $_GET['manager'] . '%';
    }
    // Text search
    if (!empty($_GET['q'])) {
        $where[] = "(f.scheme_name LIKE ? OR f.scheme_name LIKE ? OR f.scheme_code LIKE ?)";
        $like = '%' . $_GET['q'] . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }

    $whereSQL = implode(' AND ', $where);

    // Sort
    // Only sort by columns guaranteed to exist in the funds table
    $sortable = ['scheme_name','scheme_category','nav','nav_date','expense_ratio'];
    $sortBy  = in_array($_GET['sort'] ?? '', $sortable) ? $_GET['sort'] : 'scheme_name';
    $sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM funds f WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Use f.* — only columns that exist in DB are returned
    // sortBy validated above; use COALESCE for missing optional cols
    $dataStmt = $db->prepare("
        SELECT f.*,
               f.id AS fund_id,
               DATEDIFF(CURDATE(), f.inception_date) / 365 AS fund_age_years,
               (SELECT 1 FROM mf_watchlist wl WHERE wl.fund_id = f.id AND wl.user_id = ? LIMIT 1) AS in_watchlist
        FROM funds f
        WHERE $whereSQL
        ORDER BY f.scheme_name ASC
        LIMIT $perPage OFFSET $offset
    ");
    $dataStmt->execute(array_merge([$userId], $params));
    $funds = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $funds,
        'meta'    => [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => ceil($total / $perPage),
        ],
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// fund_detail — Single fund full details
// ══════════════════════════════════════════════════════════════════════════
case 'fund_detail':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }

    $fund = $db->prepare("SELECT f.*, DATEDIFF(CURDATE(), f.inception_date)/365 AS fund_age_years FROM funds f WHERE f.id = ?");
    $fund->execute([$fundId]);
    $data = $fund->fetch(PDO::FETCH_ASSOC);
    if (!$data) { echo json_encode(['success' => false, 'msg' => 'Fund not found']); break; }

    // Top holdings
    $month = date('Y-m', strtotime('-1 month'));
    $holdings = $db->prepare("
        SELECT stock_name, sector, weight_pct
        FROM fund_portfolio_holdings
        WHERE fund_id = ? AND month_year = ?
        ORDER BY weight_pct DESC LIMIT 10
    ");
    $holdings->execute([$fundId, $month]);
    $data['top_holdings'] = $holdings->fetchAll(PDO::FETCH_ASSOC);

    // Sector allocation
    $sectors = $db->prepare("
        SELECT sector, SUM(weight_pct) AS total_pct
        FROM fund_portfolio_holdings
        WHERE fund_id = ? AND month_year = ? AND sector IS NOT NULL
        GROUP BY sector ORDER BY total_pct DESC
    ");
    $sectors->execute([$fundId, $month]);
    $data['sector_allocation'] = $sectors->fetchAll(PDO::FETCH_ASSOC);

    // Watchlist flag
    $wl = $db->prepare("SELECT 1 FROM mf_watchlist WHERE fund_id = ? AND user_id = ?");
    $wl->execute([$fundId, $userId]);
    $data['in_watchlist'] = (bool)$wl->fetchColumn();

    echo json_encode(['success' => true, 'data' => $data]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// fund_compare — t96: Max 3 funds comparison
// ══════════════════════════════════════════════════════════════════════════
case 'fund_compare':
    $ids = array_map('intval', explode(',', $_GET['fund_ids'] ?? ''));
    $ids = array_filter($ids, fn($id) => $id > 0);
    $ids = array_slice(array_unique($ids), 0, 3);

    if (empty($ids)) { echo json_encode(['success' => false, 'msg' => 'fund_ids required (max 3)']); break; }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $funds = $db->prepare("
        SELECT f.*,
               DATEDIFF(CURDATE(), f.inception_date)/365 AS fund_age_years,
               (SELECT 1 FROM mf_watchlist wl WHERE wl.fund_id=f.id AND wl.user_id=? LIMIT 1) AS in_watchlist
        FROM funds f WHERE f.id IN ($in)
    ");
    $funds->execute(array_merge([$userId], $ids));
    $data = $funds->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $data]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// fund_top_performers — t28
// ══════════════════════════════════════════════════════════════════════════
case 'fund_top_performers':
    $period   = in_array($_GET['period'] ?? '1y', ['1y','3y','5y']) ? $_GET['period'] : '1y';
    $category = $_GET['category'] ?? null;
    $colMap   = ['1y' => 'returns_1y', '3y' => 'returns_3y', '5y' => 'returns_5y'];
    $col      = $colMap[$period];

    $where  = ["f.is_active = 1", "f.$col IS NOT NULL"];
    $params = [];
    if ($category) { $where[] = "f.scheme_category = ?"; $params[] = $category; }

    $stmt = $db->prepare("
        SELECT f.id, f.scheme_name AS fund_name, f.scheme_category AS category,
               f.$col AS period_return, f.expense_ratio, NULL, f.expense_ratio
        FROM funds f
        WHERE " . implode(' AND ', $where) . "
        ORDER BY f.$col DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by category
    $byCategory = [];
    foreach ($data as $f) {
        $byCategory[$f['category']][] = $f;
    }

    echo json_encode(['success' => true, 'data' => $data, 'by_category' => $byCategory]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// recommend_funds — tmfi02
// ══════════════════════════════════════════════════════════════════════════
case 'recommend_funds':
    $age     = (int)($_POST['age']  ?? 35);
    $risk    = $_POST['risk']  ?? 'moderate'; // conservative|moderate|aggressive
    $goal    = $_POST['goal']  ?? 'wealth';   // wealth|retirement|tax|child

    // Age-based equity allocation
    $eqPct = $age < 30 ? 80 : ($age < 45 ? 65 : ($age < 55 ? 50 : 35));
    if ($risk === 'aggressive') $eqPct = min(90, $eqPct + 10);
    if ($risk === 'conservative') $eqPct = max(20, $eqPct - 20);

    // Determine categories to recommend
    $cats = [];
    if ($eqPct >= 70) {
        $cats[] = ['category' => 'Large Cap', 'allocation' => 40, 'reason' => 'Stability with growth'];
        $cats[] = ['category' => 'Mid Cap',   'allocation' => 25, 'reason' => 'Higher growth potential'];
        $cats[] = ['category' => 'Flexi Cap', 'allocation' => 20, 'reason' => 'Diversified equity'];
    } elseif ($eqPct >= 50) {
        $cats[] = ['category' => 'Large Cap',    'allocation' => 35, 'reason' => 'Core equity holding'];
        $cats[] = ['category' => 'Hybrid',        'allocation' => 30, 'reason' => 'Balanced growth + stability'];
        $cats[] = ['category' => 'Debt',           'allocation' => 20, 'reason' => 'Capital protection'];
    } else {
        $cats[] = ['category' => 'Hybrid',   'allocation' => 40, 'reason' => 'Conservative balanced'];
        $cats[] = ['category' => 'Debt',      'allocation' => 40, 'reason' => 'Capital preservation'];
        $cats[] = ['category' => 'Large Cap', 'allocation' => 20, 'reason' => 'Modest equity exposure'];
    }
    if ($goal === 'tax') {
        $cats[] = ['category' => 'ELSS', 'allocation' => 15, 'reason' => '80C tax benefit + equity returns'];
    }

    // Fetch top fund per category
    $recommendations = [];
    foreach ($cats as $cat) {
        $stmt = $db->prepare("
            SELECT f.id, f.scheme_name AS fund_name, f.scheme_category AS category, f.return_1y, f.return_3y,
                   f.expense_ratio, f.expense_ratio, NULL, f.scheme_type, f.aum
            FROM funds f
            WHERE f.is_active = 1 AND f.scheme_category LIKE ? AND f.scheme_type = 'Direct'
              AND f.return_1y IS NOT NULL AND f.aum_cr >= 500 AND f.expense_ratio < 1.0
            ORDER BY CASE WHEN f.expense_ratio IS NULL THEN 1 ELSE 0 END, f.expense_ratio DESC, CASE WHEN f.return_3y IS NULL THEN 1 ELSE 0 END, f.return_3y DESC
            LIMIT 3
        ");
        $stmt->execute(['%' . $cat['category'] . '%']);
        $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($funds)) {
            $recommendations[] = [
                'category'   => $cat['category'],
                'allocation' => $cat['allocation'],
                'reason'     => $cat['reason'],
                'funds'      => $funds,
            ];
        }
    }

    echo json_encode(['success' => true, 'data' => $recommendations,
                      'profile' => compact('age', 'risk', 'goal', 'eqPct')]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// fund_house_rankings — t168
// ══════════════════════════════════════════════════════════════════════════
case 'fund_house_rankings':
    $period = in_array($_GET['period'] ?? '3y', ['1y','3y','5y']) ? $_GET['period'] : '3y';
    $col    = "returns_{$period}";

    $stmt = $db->query("
        SELECT f.scheme_name AS fund_house,
               COUNT(*) AS fund_count,
               AVG(f.$col) AS avg_return,
               AVG(f.expense_ratio) AS avg_sharpe,
               AVG(f.expense_ratio) AS avg_er,
               SUM(f.aum_cr) AS total_aum,
               AVG(NULL) AS avg_stars
        FROM funds f
        WHERE f.is_active = 1 AND f.$col IS NOT NULL AND f.scheme_name IS NOT NULL
        GROUP BY f.scheme_name
        HAVING fund_count >= 3
        ORDER BY avg_return DESC
        LIMIT 30
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// saved_screens_list / saved_screen_save / saved_screen_delete — t110
// ══════════════════════════════════════════════════════════════════════════
case 'saved_screens_list':
    $stmt = $db->prepare("
        SELECT id, name, filters_json, is_public, share_token, created_at
        FROM mf_saved_screens
        WHERE user_id = ? ORDER BY updated_at DESC
    ");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case 'saved_screen_save':
    $name    = trim($_POST['name'] ?? '');
    $filters = $_POST['filters_json'] ?? '';
    if (!$name || !$filters) { echo json_encode(['success' => false, 'msg' => 'name and filters required']); break; }
    $token = bin2hex(random_bytes(16));
    $stmt  = $db->prepare("
        INSERT INTO mf_saved_screens (user_id, name, filters_json, share_token, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE name=VALUES(name), filters_json=VALUES(filters_json), updated_at=NOW()
    ");
    $stmt->execute([$userId, $name, $filters, $token]);
    echo json_encode(['success' => true, 'id' => $db->lastInsertId(), 'share_token' => $token]);
    break;

case 'saved_screen_delete':
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM mf_saved_screens WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
    echo json_encode(['success' => true]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// filter_meta — Get unique values for filters
// ══════════════════════════════════════════════════════════════════════════
case 'filter_meta':
    $categories = $db->query("SELECT DISTINCT category FROM funds WHERE category IS NOT NULL ORDER BY category")
                     ->fetchAll(PDO::FETCH_COLUMN);
    $subCats    = $db->query("SELECT DISTINCT sub_category FROM funds WHERE sub_category IS NOT NULL ORDER BY sub_category")
                     ->fetchAll(PDO::FETCH_COLUMN);
    $houses     = $db->query("SELECT DISTINCT fund_house FROM funds WHERE fund_house IS NOT NULL ORDER BY fund_house")
                     ->fetchAll(PDO::FETCH_COLUMN);
    $risks      = $db->query("SELECT DISTINCT risk_level FROM funds WHERE risk_level IS NOT NULL ORDER BY risk_level")
                     ->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(compact('categories', 'subCats', 'houses', 'risks'));
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}
