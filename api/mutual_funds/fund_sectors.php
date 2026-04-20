<?php
/**
 * WealthDash — Fund Sector Allocation API
 * Task: t97 (Sector Allocation), t177 (Sector Allocation v2)
 * Actions: fund_sectors | portfolio_sectors | sector_filter_list | fetch_sectors_amfi
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'fund_sectors';
$db          = DB::conn();

// Ensure table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS fund_portfolio_holdings (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fund_id    INT UNSIGNED NOT NULL,
            stock_name VARCHAR(100) NOT NULL,
            isin       VARCHAR(12)  DEFAULT NULL,
            sector     VARCHAR(60)  DEFAULT NULL,
            weight_pct DECIMAL(5,2) DEFAULT NULL,
            month_year VARCHAR(7)   NOT NULL,
            UNIQUE KEY uk_fund_stock_month (fund_id, isin, month_year),
            INDEX idx_fund  (fund_id),
            INDEX idx_month (month_year),
            INDEX idx_sector (sector)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// fund_sectors — Top holdings + sector allocation for a fund
// ══════════════════════════════════════════════════════════════════════════
case 'fund_sectors':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    $month  = $_GET['month'] ?? date('Y-m', strtotime('-1 month'));
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }

    // Top holdings
    $holdings = $db->prepare("
        SELECT stock_name, isin, sector, weight_pct
        FROM fund_portfolio_holdings
        WHERE fund_id = ? AND month_year = ?
        ORDER BY weight_pct DESC LIMIT 15
    ");
    $holdings->execute([$fundId, $month]);
    $topHoldings = $holdings->fetchAll(PDO::FETCH_ASSOC);

    // Sector aggregation
    $sectors = $db->prepare("
        SELECT sector, SUM(weight_pct) AS total_pct, COUNT(*) AS stock_count
        FROM fund_portfolio_holdings
        WHERE fund_id = ? AND month_year = ? AND sector IS NOT NULL
        GROUP BY sector ORDER BY total_pct DESC
    ");
    $sectors->execute([$fundId, $month]);
    $sectorData = $sectors->fetchAll(PDO::FETCH_ASSOC);

    // If no real data, use category-based proxy
    if (empty($topHoldings)) {
        $catStmt = $db->prepare("SELECT category, sub_category FROM funds WHERE id=?");
        $catStmt->execute([$fundId]);
        $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
        $sectorData  = getCategoryProxySectors($cat['category'] ?? 'Equity');
        $topHoldings = [];
        $isProxy = true;
    }

    echo json_encode([
        'success'      => true,
        'fund_id'      => $fundId,
        'month'        => $month,
        'top_holdings' => $topHoldings,
        'sectors'      => $sectorData,
        'is_proxy'     => $isProxy ?? false,
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// portfolio_sectors — Combined sector exposure across all holdings (t178)
// ══════════════════════════════════════════════════════════════════════════
case 'portfolio_sectors':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $month       = date('Y-m', strtotime('-1 month'));

    // Get holdings with their weights in portfolio
    $holdings = $db->prepare("
        SELECT mh.fund_id, mh.current_value, f.category
        FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id
        JOIN portfolios p ON p.id=mh.portfolio_id
        WHERE p.user_id=? AND mh.is_active=1
    ");
    $holdings->execute([$userId]);
    $holdingRows = $holdings->fetchAll(PDO::FETCH_ASSOC);

    $totalValue = array_sum(array_column($holdingRows, 'current_value'));
    if ($totalValue <= 0) { echo json_encode(['success' => true, 'sectors' => []]); break; }

    $combinedSectors = [];
    foreach ($holdingRows as $h) {
        $weight = (float)$h['current_value'] / $totalValue; // Portfolio weight

        $sects = $db->prepare("SELECT sector, weight_pct FROM fund_portfolio_holdings WHERE fund_id=? AND month_year=? AND sector IS NOT NULL");
        $sects->execute([$h['fund_id'], $month]);
        $fundSectors = $sects->fetchAll(PDO::FETCH_ASSOC);

        if (empty($fundSectors)) {
            // Use proxy
            $fundSectors = getCategoryProxySectors($h['category'] ?? 'Equity');
        }

        foreach ($fundSectors as $s) {
            $sector = $s['sector'];
            $contribution = $weight * (float)($s['total_pct'] ?? $s['weight_pct'] ?? 0);
            $combinedSectors[$sector] = ($combinedSectors[$sector] ?? 0) + $contribution;
        }
    }

    arsort($combinedSectors);
    $result = array_map(fn($s, $p) => ['sector' => $s, 'weight_pct' => round($p, 2)],
                        array_keys($combinedSectors), $combinedSectors);

    echo json_encode(['success' => true, 'sectors' => array_values($result), 'month' => $month]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sector_filter_list — Get unique sectors for screener filter
// ══════════════════════════════════════════════════════════════════════════
case 'sector_filter_list':
    $month    = date('Y-m', strtotime('-1 month'));
    $sectors  = $db->query("SELECT DISTINCT sector FROM fund_portfolio_holdings WHERE sector IS NOT NULL AND month_year='$month' ORDER BY sector")
                   ->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'sectors' => $sectors]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// fetch_sectors_amfi — Admin: Fetch top holdings from AMFI portfolio disclosures
// ══════════════════════════════════════════════════════════════════════════
case 'fetch_sectors_amfi':
    if (($currentUser['role'] ?? 'user') !== 'admin') {
        echo json_encode(['success' => false, 'msg' => 'Admin only']); break;
    }
    $fundId = (int)($_POST['fund_id'] ?? 0);
    $code   = '';
    if ($fundId) {
        $f = $db->prepare("SELECT scheme_code FROM funds WHERE id=?");
        $f->execute([$fundId]); $code = $f->fetchColumn();
    }

    // MFAPI portfolio holdings endpoint
    $url  = "https://api.mfapi.in/mf/{$code}/portfolio";
    $ch   = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || !$resp) {
        echo json_encode(['success' => false, 'msg' => 'AMFI data not available for this fund']); break;
    }

    $data     = json_decode($resp, true);
    $holdings = $data['data'] ?? [];
    $month    = date('Y-m');
    $inserted = 0;

    $ins = $db->prepare("INSERT IGNORE INTO fund_portfolio_holdings (fund_id, stock_name, isin, sector, weight_pct, month_year) VALUES (?,?,?,?,?,?)");
    foreach ($holdings as $h) {
        $ins->execute([$fundId, $h['company_name'] ?? '', $h['isin'] ?? null,
                       $h['sector'] ?? null, (float)($h['percentage'] ?? 0), $month]);
        $inserted++;
    }
    echo json_encode(['success' => true, 'inserted' => $inserted, 'month' => $month]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}

// ── Helpers ───────────────────────────────────────────────────────────────

function getCategoryProxySectors(string $category): array {
    $cat = strtolower($category);
    if (str_contains($cat, 'large cap') || str_contains($cat, 'index') || str_contains($cat, 'nifty')) {
        return [
            ['sector' => 'Financial Services', 'total_pct' => 33.0],
            ['sector' => 'Information Technology', 'total_pct' => 14.0],
            ['sector' => 'Oil & Gas', 'total_pct' => 9.5],
            ['sector' => 'Consumer Goods', 'total_pct' => 8.0],
            ['sector' => 'Healthcare', 'total_pct' => 6.5],
            ['sector' => 'Automobiles', 'total_pct' => 6.0],
            ['sector' => 'Metals', 'total_pct' => 5.5],
            ['sector' => 'Others', 'total_pct' => 17.5],
        ];
    }
    if (str_contains($cat, 'mid cap')) {
        return [
            ['sector' => 'Financial Services', 'total_pct' => 20.0],
            ['sector' => 'Healthcare', 'total_pct' => 12.0],
            ['sector' => 'Consumer Goods', 'total_pct' => 11.0],
            ['sector' => 'Capital Goods', 'total_pct' => 10.0],
            ['sector' => 'Information Technology', 'total_pct' => 9.0],
            ['sector' => 'Chemicals', 'total_pct' => 8.0],
            ['sector' => 'Others', 'total_pct' => 30.0],
        ];
    }
    if (str_contains($cat, 'debt') || str_contains($cat, 'liquid') || str_contains($cat, 'gilt')) {
        return [
            ['sector' => 'Government Securities', 'total_pct' => 45.0],
            ['sector' => 'Corporate Bonds AAA', 'total_pct' => 35.0],
            ['sector' => 'Money Market', 'total_pct' => 15.0],
            ['sector' => 'Others', 'total_pct' => 5.0],
        ];
    }
    return [
        ['sector' => 'Financial Services', 'total_pct' => 25.0],
        ['sector' => 'Information Technology', 'total_pct' => 15.0],
        ['sector' => 'Consumer Goods', 'total_pct' => 12.0],
        ['sector' => 'Healthcare', 'total_pct' => 10.0],
        ['sector' => 'Others', 'total_pct' => 38.0],
    ];
}

function getOrCreatePortfolio(PDO $db, int $userId): int {
    $s = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $s->execute([$userId]); $pid = $s->fetchColumn();
    if ($pid) return (int)$pid;
    $db->prepare("INSERT INTO portfolios (user_id, name, is_default, created_at) VALUES (?,?,1,NOW())")->execute([$userId, 'My Portfolio']);
    return (int)$db->lastInsertId();
}
