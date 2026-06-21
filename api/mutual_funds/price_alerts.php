<?php
/**
 * WealthDash — MF Price Alerts API (t77 — DB-persistent)
 * Actions: pa_list, pa_add, pa_delete, pa_toggle
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0); ini_set('display_errors', '0');
ob_start();
$user   = require_auth();
$userId = (int)$user['id'];
$db     = DB::conn();
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

// Check if table exists
$tableOk = false;
try { $db->query("SELECT 1 FROM price_alerts LIMIT 1"); $tableOk = true; } catch(Exception $e) {}

try {

    /* ── LIST alerts for user ── */
    if ($action === 'pa_list') {
        $items = [];
        if ($tableOk) {
            $s = $db->prepare("
                SELECT pa.*, f.scheme_name, f.latest_nav, f.fund_house_id,
                       COALESCE(fh.short_name, fh.name, 'Unknown') AS fund_house
                FROM price_alerts pa
                JOIN funds f ON f.id = pa.fund_id
                LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
                WHERE pa.user_id = ?
                ORDER BY pa.is_active DESC, pa.created_at DESC
                LIMIT 100
            ");
            $s->execute([$userId]);
            $items = $s->fetchAll();
        }
        ob_clean();
        echo json_encode(['success' => true, 'items' => $items, 'table_exists' => $tableOk]);
        exit;
    }

    /* ── ADD alert ── */
    if ($action === 'pa_add') {
        if (!$tableOk) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Run migration 11 first']); exit; }
        $fundId    = (int)($_POST['fund_id'] ?? 0);
        $type      = in_array($_POST['type'] ?? '', ['above','below']) ? $_POST['type'] : 'above';
        $targetNav = (float)($_POST['target_nav'] ?? 0);
        $note      = substr(clean($_POST['note'] ?? ''), 0, 300);
        if (!$fundId || $targetNav <= 0) {
            ob_clean(); echo json_encode(['success' => false, 'message' => 'fund_id and target_nav required']); exit;
        }
        // Verify fund exists
        $fund = $db->prepare("SELECT id, scheme_name, latest_nav FROM funds WHERE id=?");
        $fund->execute([$fundId]); $fund = $fund->fetch();
        if (!$fund) { ob_clean(); echo json_encode(['success' => false, 'message' => 'Fund not found']); exit; }
        // Check duplicate
        $dup = $db->prepare("SELECT id FROM price_alerts WHERE user_id=? AND fund_id=? AND type=? AND is_active=1");
        $dup->execute([$userId, $fundId, $type]);
        if ($dup->fetch()) {
            // Update existing
            $upd = $db->prepare("UPDATE price_alerts SET target_nav=?, note=?, triggered_at=NULL, created_at=NOW() WHERE user_id=? AND fund_id=? AND type=? AND is_active=1");
            $upd->execute([$targetNav, $note, $userId, $fundId, $type]);
            ob_clean(); echo json_encode(['success' => true, 'message' => 'Alert updated', 'action' => 'updated']); exit;
        }
        $ins = $db->prepare("INSERT INTO price_alerts (user_id, fund_id, type, target_nav, note, is_active) VALUES (?,?,?,?,?,1)");
        $ins->execute([$userId, $fundId, $type, $targetNav, $note]);
        $newId = $db->lastInsertId();
        ob_clean();
        echo json_encode(['success' => true, 'message' => "Alert set for {$fund['scheme_name']}", 'id' => $newId]);
        exit;
    }

    /* ── DELETE alert ── */
    if ($action === 'pa_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($tableOk && $id) {
            $s = $db->prepare("DELETE FROM price_alerts WHERE id=? AND user_id=?");
            $s->execute([$id, $userId]);
        }
        ob_clean(); echo json_encode(['success' => true]); exit;
    }

    /* ── TOGGLE active/inactive ── */
    if ($action === 'pa_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($tableOk && $id) {
            $s = $db->prepare("UPDATE price_alerts SET is_active = 1 - is_active, triggered_at = NULL WHERE id=? AND user_id=?");
            $s->execute([$id, $userId]);
        }
        ob_clean(); echo json_encode(['success' => true]); exit;
    }

    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
