<?php
/**
 * WealthDash — Fund Screener API
 * Tasks: t63–t69, t93, t96, t98, t108, t111, tv01, tv04
 * Actions: fund_screener | fund_detail | fund_top_performers
 *          fund_compare | fund_filter_meta | recommend_funds
 *          style_box | saved_screens_list | saved_screen_save
 *          saved_screen_delete | fund_house_rankings
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

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
        $where[]  = "f.category = ?";
        $params[] = $_GET['category'];
    }
    // Sub-category filter
    if (!empty($_GET['sub_category'])) {
        $where[]  = "f.sub_category = ?";
        $params[] = $_GET['sub_category'];
    }
    // Fund house filter
    if (!empty($_GET['fund_house'])) {
        $where[]  = "f.fund_house = ?";
        $params[] = $_GET['fund_house'];
    }
    // Risk level
    if (!empty($_GET['risk'])) {
        $where[]  = "f.risk_level = ?";
        $params[] = $_GET['risk'];
    }
    // Plan type
    if (!empty($_GET['plan'])) {
        $where[]  = "f.plan_type = ?";
        $params[] = $_GET['plan'];
    }
    // Returns filters
    foreach (['returns_1y','returns_3y','returns_5y','sharpe_ratio','alpha','expense_ratio'] as $col) {
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
        $where[] = "f.alpha > 0";
    }
    // Rating filter
    if (!empty($_GET['min_stars']) && is_numeric($_GET['min_stars'])) {
        $where[] = "f.rating_stars >= ?"; $params[] = (int)$_GET['min_stars'];
    }
    // Fund age filter
    if (!empty($_GET['min_age_years']) && is_numeric($_GET['min_age_years'])) {
        $where[] = "f.inception_date <= ?";
        $params[] = date('Y-m-d', strtotime('-' . (int)$_GET['min_age_years'] . ' years'));
    }
    // AUM filter
    if (!empty($_GET['min_aum']) && is_numeric($_GET['min_aum'])) {
        $where[] = "f.aum >= ?"; $params[] = (float)$_GET['min_aum'];
    }
    // Manager filter (partial name)
    if (!empty($_GET['manager'])) {
        $where[] = "f.manager_name LIKE ?"; $params[] = '%' . $_GET['manager'] . '%';
    }
    // Text search
    if (!empty($_GET['q'])) {
        $where[] = "(f.fund_name LIKE ? OR f.fund_house LIKE ? OR f.scheme_code LIKE ?)";
        $like = '%' . $_GET['q'] . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }

    $whereSQL = implode(' AND ', $where);

    // Sort
    $sortable = ['returns_1y','returns_3y','returns_5y','sharpe_ratio','sortino_ratio',
                 'alpha','beta','max_drawdown','expense_ratio','momentum_score',
                 'aum','fund_name','rating_stars','health_score'];
    $sortBy  = in_array($_GET['sort'] ?? '', $sortable) ? $_GET['sort'] : 'returns_1y';
    $sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM funds f WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $dataStmt = $db->prepare("
        SELECT f.id AS fund_id, f.scheme_code, f.fund_name, f.fund_house,
               f.category, f.sub_category, f.risk_level, f.plan_type,
               f.current_nav, f.nav_date, f.aum,
               f.expense_ratio, f.exit_load_percent, f.lock_in_months,
               f.returns_1y, f.returns_3y, f.returns_5y, f.returns_6m, f.returns_1m,
               f.sharpe_ratio, f.sortino_ratio, f.calmar_ratio,
               f.max_drawdown, f.standard_deviation,
               f.alpha, f.beta, f.momentum_score,
               f.category_avg_1y, f.category_avg_3y, f.category_avg_5y,
               f.rating_stars, f.health_score,
               f.inception_date, f.manager_name, f.manager_since,
               DATEDIFF(CURDATE(), f.inception_date) / 365 AS fund_age_years,
               DATEDIFF(CURDATE(), f.manager_since)  / 365 AS manager_tenure_years,
               (SELECT 1 FROM mf_watchlist wl WHERE wl.fund_id = f.id AND wl.user_id = ? LIMIT 1) AS in_watchlist
        FROM funds f
        WHERE $whereSQL
        ORDER BY f.$sortBy $sortDir NULLS LAST
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
    if ($category) { $where[] = "f.category = ?"; $params[] = $category; }

    $stmt = $db->prepare("
        SELECT f.id, f.fund_name, f.fund_house, f.category, f.plan_type,
               f.$col AS period_return, f.expense_ratio, f.rating_stars, f.sharpe_ratio
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
            SELECT f.id, f.fund_name, f.fund_house, f.category, f.returns_1y, f.returns_3y,
                   f.expense_ratio, f.sharpe_ratio, f.rating_stars, f.plan_type, f.aum
            FROM funds f
            WHERE f.is_active = 1 AND f.category LIKE ? AND f.plan_type = 'Direct'
              AND f.returns_1y IS NOT NULL AND f.aum >= 500 AND f.expense_ratio < 1.0
            ORDER BY f.sharpe_ratio DESC NULLS LAST, f.returns_3y DESC NULLS LAST
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
        SELECT f.fund_house,
               COUNT(*) AS fund_count,
               AVG(f.$col) AS avg_return,
               AVG(f.sharpe_ratio) AS avg_sharpe,
               AVG(f.expense_ratio) AS avg_er,
               SUM(f.aum) AS total_aum,
               AVG(f.rating_stars) AS avg_stars
        FROM funds f
        WHERE f.is_active = 1 AND f.$col IS NOT NULL AND f.fund_house IS NOT NULL
        GROUP BY f.fund_house
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
