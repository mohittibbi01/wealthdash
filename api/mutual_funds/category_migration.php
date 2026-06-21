<?php
/**
 * WealthDash — t368: Fund Category Migration Alert
 *
 * Detects when a fund changes its SEBI category (recategorization).
 * Alerts users holding affected funds. Logs category changes history.
 *
 * GET /api/mutual_funds/category_migration.php
 *   ?action=alerts          ← Alerts for user-held funds
 *   ?action=check&fund_id=X ← Check specific fund
 *   ?action=history         ← All logged category changes
 *   ?action=impact&fund_id=X ← Impact analysis of category change
 *
 * POST ?action=log           ← Admin: log a category change
 *   Body: {fund_id, old_category, new_category, effective_date, notes}
 *
 * POST ?action=check_all     ← Admin: scan all funds for category drift
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

$currentUser = require_auth();

set_exception_handler(function (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
});

try {
    $db     = DB::conn();
    $action = $_GET['action'] ?? 'alerts';
    $fundId = (int)($_GET['fund_id'] ?? 0);
    $userId = (int)$currentUser['id'];

    // ── Ensure fund_category_changes table exists ────────────────────────
    ensure_table($db);

    $response = ['success' => true, 'action' => $action];

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        switch ($action) {
            case 'log':
                if ($currentUser['role'] !== 'admin') { abort(403, 'Admin only'); }
                $response += log_category_change($db, $body);
                break;
            case 'check_all':
                if ($currentUser['role'] !== 'admin') { abort(403, 'Admin only'); }
                $response += scan_for_category_drift($db);
                break;
            default:
                throw new InvalidArgumentException("Unknown POST action: $action");
        }
    } else {
        switch ($action) {
            case 'alerts':
                $response['alerts'] = get_user_alerts($db, $userId);
                break;
            case 'check':
                if (!$fundId) throw new InvalidArgumentException('fund_id required');
                $response['fund_history'] = get_fund_category_history($db, $fundId);
                break;
            case 'history':
                $response['history'] = get_all_changes($db);
                break;
            case 'impact':
                if (!$fundId) throw new InvalidArgumentException('fund_id required');
                $response['impact'] = get_migration_impact($db, $fundId, $userId);
                break;
            default:
                $response['alerts'] = get_user_alerts($db, $userId);
        }
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// TABLE SETUP
// ═══════════════════════════════════════════════════════════════════════════
function ensure_table(PDO $db): void
{
    try { $db->query("SELECT 1 FROM fund_category_changes LIMIT 1"); }
    catch (Exception $e) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS fund_category_changes (
                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fund_id        INT UNSIGNED NOT NULL,
                old_category   VARCHAR(120)  NOT NULL,
                new_category   VARCHAR(120)  NOT NULL,
                effective_date DATE          NOT NULL,
                source         VARCHAR(50)   DEFAULT 'manual',
                notes          TEXT,
                is_sebi        TINYINT(1)    DEFAULT 0,
                created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fund (fund_id),
                INDEX idx_date (effective_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Ensure alert_dismissed table for per-user dismissals
    try { $db->query("SELECT 1 FROM category_change_alerts LIMIT 1"); }
    catch (Exception $e) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS category_change_alerts (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id     INT UNSIGNED NOT NULL,
                change_id   INT UNSIGNED NOT NULL,
                dismissed   TINYINT(1) DEFAULT 0,
                dismissed_at TIMESTAMP NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_change (user_id, change_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

function abort(int $code, string $msg): never
{
    ob_clean(); http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]); exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// GET USER ALERTS — Changes affecting user's held funds
// ═══════════════════════════════════════════════════════════════════════════
function get_user_alerts(PDO $db, int $userId): array
{
    try {
        // Get all category changes for funds the user holds
        $stmt = $db->prepare("
            SELECT DISTINCT
                fcc.id          AS change_id,
                fcc.fund_id,
                fcc.old_category,
                fcc.new_category,
                fcc.effective_date,
                fcc.notes,
                fcc.is_sebi,
                fcc.source,
                f.scheme_name,
                COALESCE(fh.short_name, fh.name) AS fund_house,
                h.units,
                h.avg_cost_nav,
                f.latest_nav,
                COALESCE(cca.dismissed, 0) AS dismissed
            FROM fund_category_changes fcc
            JOIN funds f ON f.id = fcc.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            JOIN mf_holdings h ON h.fund_id = fcc.fund_id
            JOIN portfolios p ON p.id = h.portfolio_id AND p.user_id = ?
            LEFT JOIN category_change_alerts cca ON cca.change_id = fcc.id AND cca.user_id = ?
            WHERE h.units > 0
            ORDER BY fcc.effective_date DESC
        ");
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return ['count' => 0, 'items' => [], 'note' => 'No changes logged yet.'];
    }

    $alerts = array_map(function ($r) {
        $cv      = (float)$r['units'] * (float)($r['latest_nav'] ?? 0);
        $impact  = assess_category_change_impact($r['old_category'], $r['new_category']);
        return [
            'change_id'       => (int)$r['change_id'],
            'fund_id'         => (int)$r['fund_id'],
            'scheme_name'     => $r['scheme_name'],
            'fund_house'      => $r['fund_house'],
            'old_category'    => $r['old_category'],
            'new_category'    => $r['new_category'],
            'effective_date'  => $r['effective_date'],
            'is_sebi'         => (bool)$r['is_sebi'],
            'notes'           => $r['notes'],
            'your_value'      => round($cv, 2),
            'your_units'      => (float)$r['units'],
            'dismissed'       => (bool)$r['dismissed'],
            'impact'          => $impact,
            'action_needed'   => $impact['severity'] === 'high' ? '⚠️ Review and consider exit or switch' : '📋 Monitor — category changed',
        ];
    }, $rows);

    $active = array_filter($alerts, fn($a) => !$a['dismissed']);

    return [
        'count'        => count($active),
        'total'        => count($alerts),
        'items'        => array_values($alerts),
        'active_items' => array_values($active),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ASSESS IMPACT OF CATEGORY CHANGE
// ═══════════════════════════════════════════════════════════════════════════
function assess_category_change_impact(string $oldCat, string $newCat): array
{
    $old = strtolower($oldCat);
    $new = strtolower($newCat);

    // Risk level change
    $riskLevel = ['liquid' => 1, 'overnight' => 1, 'debt' => 2, 'short duration' => 2,
                  'gilt' => 3, 'credit risk' => 4, 'hybrid' => 5, 'arbitrage' => 3,
                  'large cap' => 6, 'index' => 6, 'flexi cap' => 7, 'multi cap' => 7,
                  'large & mid cap' => 7, 'mid cap' => 8, 'small cap' => 9,
                  'sectoral' => 9, 'thematic' => 9];

    $oldRisk = 5; // default medium
    $newRisk = 5;
    foreach ($riskLevel as $cat => $level) {
        if (str_contains($old, $cat)) $oldRisk = $level;
        if (str_contains($new, $cat)) $newRisk = $level;
    }

    $riskChange = $newRisk - $oldRisk;
    $riskLabel  = match (true) {
        $riskChange >= 2  => 'Significantly higher risk',
        $riskChange === 1 => 'Slightly higher risk',
        $riskChange === 0 => 'Similar risk level',
        $riskChange === -1=> 'Slightly lower risk',
        default           => 'Significantly lower risk',
    };

    // Tax change detection
    $oldIsEquity = str_contains($old, 'equity') || str_contains($old, 'cap') || str_contains($old, 'flexi');
    $newIsEquity = str_contains($new, 'equity') || str_contains($new, 'cap') || str_contains($new, 'flexi');
    $taxChange   = $oldIsEquity !== $newIsEquity;

    $severity = match (true) {
        $taxChange || abs($riskChange) >= 3 => 'high',
        abs($riskChange) >= 2              => 'medium',
        default                            => 'low',
    };

    $implications = [];
    if ($taxChange) {
        $implications[] = $newIsEquity
            ? 'Tax treatment changed → Now equity taxation (12.5% LTCG after 1yr, 20% STCG)'
            : 'Tax treatment changed → Now debt taxation (slab rate STCG, 20% LTCG after 3yr)';
    }
    if ($riskChange > 0) {
        $implications[] = "$riskLabel — your portfolio risk has increased";
    }
    if (str_contains($old, 'large') && str_contains($new, 'mid')) {
        $implications[] = 'Fund moving from Large Cap to Mid Cap — higher volatility expected';
    }
    if (str_contains($old, 'debt') && !str_contains($new, 'debt')) {
        $implications[] = 'Asset class change: Debt → Equity — major risk profile shift!';
    }

    return [
        'old_risk_level'  => $oldRisk,
        'new_risk_level'  => $newRisk,
        'risk_change'     => $riskChange,
        'risk_label'      => $riskLabel,
        'tax_change'      => $taxChange,
        'severity'        => $severity,
        'severity_color'  => match ($severity) {
            'high'   => '#dc2626',
            'medium' => '#d97706',
            default  => '#16a34a',
        },
        'implications'    => $implications,
        'sebi_note'       => 'SEBI requires 30-day notice for recategorization. You can exit at NAV without exit load during this window in many cases.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// FUND CATEGORY HISTORY
// ═══════════════════════════════════════════════════════════════════════════
function get_fund_category_history(PDO $db, int $fundId): array
{
    $stmt = $db->prepare("SELECT scheme_name, category, fund_house_id FROM funds WHERE id = ?");
    $stmt->execute([$fundId]);
    $fund = $stmt->fetch();

    $history = [];
    try {
        $stmt = $db->prepare("
            SELECT old_category, new_category, effective_date, source, notes, is_sebi
            FROM fund_category_changes WHERE fund_id = ? ORDER BY effective_date DESC
        ");
        $stmt->execute([$fundId]);
        $history = $stmt->fetchAll();
    } catch (Exception $e) {}

    return [
        'fund_id'          => $fundId,
        'scheme_name'      => $fund['scheme_name'] ?? 'Unknown',
        'current_category' => $fund['category'] ?? 'Unknown',
        'change_count'     => count($history),
        'history'          => $history,
        'is_stable'        => count($history) === 0,
        'stability_note'   => count($history) === 0
            ? 'No category changes logged for this fund.'
            : count($history) . ' category change(s) detected. Review history before investing.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ALL CHANGES
// ═══════════════════════════════════════════════════════════════════════════
function get_all_changes(PDO $db): array
{
    try {
        $rows = $db->query("
            SELECT fcc.*, f.scheme_name, COALESCE(fh.short_name, fh.name) AS fund_house
            FROM fund_category_changes fcc
            JOIN funds f ON f.id = fcc.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            ORDER BY fcc.effective_date DESC
            LIMIT 200
        ")->fetchAll();
        return ['count' => count($rows), 'changes' => $rows];
    } catch (Exception $e) {
        return ['count' => 0, 'changes' => []];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// MIGRATION IMPACT for a specific user+fund
// ═══════════════════════════════════════════════════════════════════════════
function get_migration_impact(PDO $db, int $fundId, int $userId): array
{
    $history = get_fund_category_history($db, $fundId);
    if (empty($history['history'])) {
        return ['message' => 'No category migration recorded for this fund.'];
    }

    $lastChange = $history['history'][0];
    $impact     = assess_category_change_impact($lastChange['old_category'], $lastChange['new_category']);

    // Does user hold this fund?
    try {
        $stmt = $db->prepare("
            SELECT h.units, h.avg_cost_nav, f.latest_nav
            FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.fund_id = ? AND p.user_id = ? AND h.units > 0
        ");
        $stmt->execute([$fundId, $userId]);
        $holding = $stmt->fetch();
    } catch (Exception $e) { $holding = null; }

    $your_value = null;
    if ($holding) {
        $your_value = round((float)$holding['units'] * (float)$holding['latest_nav'], 2);
    }

    return [
        'fund_id'        => $fundId,
        'scheme_name'    => $history['scheme_name'],
        'last_change'    => $lastChange,
        'impact'         => $impact,
        'you_hold_it'    => $holding !== null,
        'your_value'     => $your_value,
        'recommendations'=> build_impact_recommendations($impact, $lastChange, $your_value),
    ];
}

function build_impact_recommendations(array $impact, array $change, ?float $yourValue): array
{
    $recs = [];

    if ($impact['tax_change']) {
        $recs[] = [
            'priority' => 'high',
            'action'   => 'Review tax implications',
            'detail'   => 'Category change has altered the tax treatment of this fund. Consult your CA before next redemption.',
        ];
    }

    if ($impact['risk_change'] >= 2) {
        $recs[] = [
            'priority' => 'high',
            'action'   => 'Reassess risk fit',
            'detail'   => "Fund is now in a significantly higher risk category. Review if this still fits your risk profile.",
        ];
    }

    if ($yourValue && $yourValue >= 50000 && $impact['severity'] === 'high') {
        $recs[] = [
            'priority' => 'high',
            'action'   => 'Consider exiting or switching',
            'detail'   => "You have ₹" . number_format((int)$yourValue, 0) . " in this fund. " .
                         "SEBI recategorization gives a window to exit without penalty in some cases.",
        ];
    }

    if (empty($recs)) {
        $recs[] = [
            'priority' => 'low',
            'action'   => 'Monitor',
            'detail'   => 'Category change has low impact. Continue SIP but monitor performance vs new category peers.',
        ];
    }

    return $recs;
}

// ═══════════════════════════════════════════════════════════════════════════
// LOG CATEGORY CHANGE (admin)
// ═══════════════════════════════════════════════════════════════════════════
function log_category_change(PDO $db, array $body): array
{
    $required = ['fund_id', 'old_category', 'new_category', 'effective_date'];
    foreach ($required as $key) {
        if (empty($body[$key])) throw new InvalidArgumentException("$key is required");
    }

    $stmt = $db->prepare("
        INSERT INTO fund_category_changes
            (fund_id, old_category, new_category, effective_date, source, notes, is_sebi)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$body['fund_id'],
        $body['old_category'],
        $body['new_category'],
        $body['effective_date'],
        $body['source'] ?? 'manual',
        $body['notes']  ?? null,
        isset($body['is_sebi']) ? (int)$body['is_sebi'] : 0,
    ]);

    // Also update funds table category
    if (!empty($body['update_fund_category'])) {
        $db->prepare("UPDATE funds SET category = ? WHERE id = ?")
           ->execute([$body['new_category'], (int)$body['fund_id']]);
    }

    return ['logged' => true, 'change_id' => (int)$db->lastInsertId()];
}

// ═══════════════════════════════════════════════════════════════════════════
// SCAN FOR CATEGORY DRIFT (admin: detect name-based category mismatches)
// ═══════════════════════════════════════════════════════════════════════════
function scan_for_category_drift(PDO $db): array
{
    // Detect funds where scheme_name suggests a different category than stored
    $funds = $db->query("
        SELECT id, scheme_name, category FROM funds WHERE is_active = 1 LIMIT 500
    ")->fetchAll();

    $suspects = [];
    foreach ($funds as $f) {
        $inferred = infer_category_from_name($f['scheme_name']);
        if ($inferred && $inferred !== $f['category']) {
            // Check if not already logged
            $suspects[] = [
                'fund_id'           => (int)$f['id'],
                'scheme_name'       => $f['scheme_name'],
                'stored_category'   => $f['category'],
                'inferred_category' => $inferred,
                'note'              => 'Fund name suggests different category than stored. May need manual review.',
            ];
        }
    }

    return ['suspects' => $suspects, 'count' => count($suspects)];
}

function infer_category_from_name(string $name): ?string
{
    $n = strtolower($name);
    if (str_contains($n, 'small cap'))      return 'Small Cap';
    if (str_contains($n, 'mid cap'))        return 'Mid Cap';
    if (str_contains($n, 'large & mid') || str_contains($n, 'large and mid')) return 'Large & Mid Cap';
    if (str_contains($n, 'large cap'))      return 'Large Cap';
    if (str_contains($n, 'flexi cap'))      return 'Flexi Cap';
    if (str_contains($n, 'multi cap'))      return 'Multi Cap';
    if (str_contains($n, 'elss') || str_contains($n, 'tax saver')) return 'ELSS';
    if (str_contains($n, 'liquid'))         return 'Liquid';
    if (str_contains($n, 'overnight'))      return 'Overnight';
    if (str_contains($n, 'gilt'))           return 'Gilt';
    if (str_contains($n, 'arbitrage'))      return 'Arbitrage';
    return null;
}
