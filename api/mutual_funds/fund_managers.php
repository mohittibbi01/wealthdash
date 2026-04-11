<?php
/**
 * WealthDash — t180: Fund Manager Track Record API
 * GET ?action=fund_managers&fund_id=XXX   → manager history for a fund
 * GET ?action=fund_managers&manager=NAME  → all funds by a manager + alpha
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

set_exception_handler(function(Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

try {
    $db        = DB::conn();
    $fundId    = (int)($_GET['fund_id']  ?? 0);
    $managerQ  = trim($_GET['manager']   ?? '');

    // Check if fund_managers table exists
    $tableExists = false;
    try {
        $db->query("SELECT 1 FROM fund_managers LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {}

    // ── Mode 1: Fund manager history ────────────────────────────────
    if ($fundId) {
        $fund = $db->prepare("SELECT id, scheme_name, fund_manager, manager_since, returns_1y, returns_3y, returns_5y, category FROM funds WHERE id = ?");
        $fund->execute([$fundId]);
        $fund = $fund->fetch();
        if (!$fund) throw new RuntimeException('Fund not found');

        $managers = [];
        if ($tableExists) {
            $stmt = $db->prepare("
                SELECT fm.id, fm.manager_name, fm.from_date, fm.to_date, fm.is_current,
                       f.returns_1y, f.returns_3y, f.returns_5y
                FROM fund_managers fm
                LEFT JOIN funds f ON f.id = fm.fund_id
                WHERE fm.fund_id = ?
                ORDER BY fm.from_date DESC
            ");
            $stmt->execute([$fundId]);
            $managers = $stmt->fetchAll();
        }

        // If no history table data, build from funds.fund_manager column
        if (empty($managers) && $fund['fund_manager']) {
            $managers = [[
                'id'           => null,
                'manager_name' => $fund['fund_manager'],
                'from_date'    => $fund['manager_since'],
                'to_date'      => null,
                'is_current'   => 1,
                'returns_1y'   => $fund['returns_1y'],
                'returns_3y'   => $fund['returns_3y'],
                'returns_5y'   => $fund['returns_5y'],
            ]];
        }

        // Recent change alert
        $recentChangeAlert = null;
        if (!empty($managers)) {
            $current = array_values(array_filter($managers, fn($m) => $m['is_current'] == 1));
            if (!empty($current) && !empty($current[0]['from_date'])) {
                $monthsAgo = (int)((time() - strtotime($current[0]['from_date'])) / (30 * 86400));
                if ($monthsAgo <= 6) {
                    $recentChangeAlert = "⚠️ Fund manager {$monthsAgo} months pehle change hua — performance monitor karo";
                }
            }
        }

        ob_clean();
        echo json_encode([
            'success'             => true,
            'mode'                => 'fund',
            'fund_id'             => $fundId,
            'fund_name'           => $fund['scheme_name'],
            'current_manager'     => $fund['fund_manager'],
            'manager_since'       => $fund['manager_since'],
            'managers'            => array_values(array_map(fn($m) => [
                'manager_name' => $m['manager_name'],
                'from_date'    => $m['from_date'],
                'to_date'      => $m['to_date'],
                'is_current'   => (bool)$m['is_current'],
                'tenure_days'  => $m['from_date']
                    ? (int)(($m['to_date'] ? strtotime($m['to_date']) : time()) - strtotime($m['from_date'])) / 86400
                    : null,
                'returns_1y'   => $m['returns_1y'] !== null ? round((float)$m['returns_1y'], 2) : null,
                'returns_3y'   => $m['returns_3y'] !== null ? round((float)$m['returns_3y'], 2) : null,
                'returns_5y'   => $m['returns_5y'] !== null ? round((float)$m['returns_5y'], 2) : null,
            ], $managers)),
            'recent_change_alert' => $recentChangeAlert,
            'table_exists'        => $tableExists,
        ], JSON_UNESCAPED_UNICODE);

    // ── Mode 2: Manager profile — all funds + 5Y alpha ranking ──────
    } elseif ($managerQ !== '') {
        $funds = [];
        if ($tableExists) {
            $stmt = $db->prepare("
                SELECT fm.manager_name, fm.from_date, fm.to_date, fm.is_current,
                       f.id AS fund_id, f.scheme_name, f.category,
                       f.returns_1y, f.returns_3y, f.returns_5y,
                       f.category_avg_1y, f.category_avg_3y,
                       COALESCE(fh.short_name, fh.name) AS fund_house
                FROM fund_managers fm
                JOIN funds f ON f.id = fm.fund_id
                LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
                WHERE fm.manager_name LIKE ?
                ORDER BY fm.is_current DESC, fm.from_date DESC
                LIMIT 50
            ");
            $stmt->execute(['%' . $managerQ . '%']);
            $funds = $stmt->fetchAll();
        } else {
            // Fallback: from funds.fund_manager column
            $stmt = $db->prepare("
                SELECT f.id AS fund_id, f.scheme_name, f.category, f.fund_manager AS manager_name,
                       f.manager_since AS from_date, NULL AS to_date, 1 AS is_current,
                       f.returns_1y, f.returns_3y, f.returns_5y,
                       f.category_avg_1y, f.category_avg_3y,
                       COALESCE(fh.short_name, fh.name) AS fund_house
                FROM funds f
                LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
                WHERE f.fund_manager LIKE ? AND f.is_active = 1
                LIMIT 50
            ");
            $stmt->execute(['%' . $managerQ . '%']);
            $funds = $stmt->fetchAll();
        }

        // 5Y Alpha = returns_5y - category_avg (proxy for benchmark alpha)
        $alpha5y = [];
        foreach ($funds as $f) {
            if ($f['returns_5y'] !== null && $f['category_avg_3y'] !== null) {
                $alpha5y[] = (float)$f['returns_5y'] - (float)$f['category_avg_3y'];
            }
        }
        $avgAlpha = !empty($alpha5y) ? round(array_sum($alpha5y) / count($alpha5y), 2) : null;

        ob_clean();
        echo json_encode([
            'success'      => true,
            'mode'         => 'manager',
            'manager'      => $managerQ,
            'funds_count'  => count($funds),
            'avg_alpha_5y' => $avgAlpha,
            'funds'        => array_values(array_map(fn($f) => [
                'fund_id'      => (int)$f['fund_id'],
                'scheme_name'  => $f['scheme_name'],
                'category'     => $f['category'],
                'fund_house'   => $f['fund_house'],
                'is_current'   => (bool)$f['is_current'],
                'from_date'    => $f['from_date'],
                'to_date'      => $f['to_date'],
                'returns_1y'   => $f['returns_1y'] !== null ? round((float)$f['returns_1y'], 2) : null,
                'returns_3y'   => $f['returns_3y'] !== null ? round((float)$f['returns_3y'], 2) : null,
                'returns_5y'   => $f['returns_5y'] !== null ? round((float)$f['returns_5y'], 2) : null,
                'alpha_vs_cat' => ($f['returns_5y'] !== null && $f['category_avg_3y'] !== null)
                    ? round((float)$f['returns_5y'] - (float)$f['category_avg_3y'], 2) : null,
            ], $funds)),
            'table_exists' => $tableExists,
        ], JSON_UNESCAPED_UNICODE);

    } else {
        throw new InvalidArgumentException('fund_id or manager param required');
    }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
