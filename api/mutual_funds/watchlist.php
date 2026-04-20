<?php
/**
 * WealthDash — Watchlist API
 * Tasks: t68, tv10, t405
 * Actions: wl_list | wl_toggle | wl_remove
 *          wl_alerts_list | wl_alert_save | wl_alert_delete
 *          wl_alert_check | wl_alert_simulate | wl_alert_snooze
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'wl_list';
$db          = DB::conn();

// Ensure tables
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mf_watchlist (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL, fund_id INT UNSIGNED NOT NULL,
            notes VARCHAR(300) DEFAULT NULL, added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_fund (user_id, fund_id), INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS mf_watchlist_alerts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL, fund_id INT UNSIGNED NOT NULL,
            alert_type ENUM('nav_above','nav_below','return_1y_above','return_3y_above',
                            'sharpe_above','aum_above','expense_below','multi_condition') NOT NULL,
            target_value DECIMAL(12,4) DEFAULT NULL,
            conditions_json TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_triggered DATETIME DEFAULT NULL, snooze_until DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS mf_alert_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            alert_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            fund_id INT UNSIGNED NOT NULL, trigger_val DECIMAL(12,4) DEFAULT NULL,
            triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_alert (alert_id), INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

switch ($action) {

// ── WATCHLIST ──────────────────────────────────────────────────────────
case 'wl_list':
    $stmt = $db->prepare("
        SELECT w.id, w.fund_id, w.notes, w.added_at,
               f.fund_name, f.fund_house, f.category, f.current_nav, f.nav_date,
               f.returns_1y, f.returns_3y, f.sharpe_ratio, f.expense_ratio,
               f.rating_stars, f.momentum_score,
               (SELECT COUNT(*) FROM mf_watchlist_alerts a WHERE a.fund_id=w.fund_id AND a.user_id=? AND a.is_active=1) AS alert_count
        FROM mf_watchlist w
        JOIN funds f ON f.id = w.fund_id
        WHERE w.user_id = ? ORDER BY w.added_at DESC
    ");
    $stmt->execute([$userId, $userId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case 'wl_toggle':
    $fundId = (int)($_POST['fund_id'] ?? 0);
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }
    $exists = $db->prepare("SELECT id FROM mf_watchlist WHERE user_id=? AND fund_id=?");
    $exists->execute([$userId, $fundId]);
    if ($exists->fetchColumn()) {
        $db->prepare("DELETE FROM mf_watchlist WHERE user_id=? AND fund_id=?")->execute([$userId, $fundId]);
        echo json_encode(['success' => true, 'action' => 'removed']);
    } else {
        $db->prepare("INSERT INTO mf_watchlist (user_id, fund_id) VALUES (?,?)")->execute([$userId, $fundId]);
        echo json_encode(['success' => true, 'action' => 'added']);
    }
    break;

// ── ALERTS ────────────────────────────────────────────────────────────
case 'wl_alerts_list':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    $sql    = "SELECT a.*, f.fund_name, f.current_nav FROM mf_watchlist_alerts a
               JOIN funds f ON f.id = a.fund_id WHERE a.user_id = ?";
    $p = [$userId];
    if ($fundId) { $sql .= " AND a.fund_id = ?"; $p[] = $fundId; }
    $sql .= " ORDER BY a.created_at DESC";
    $stmt = $db->prepare($sql); $stmt->execute($p);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

case 'wl_alert_save':
    $fundId  = (int)($_POST['fund_id']    ?? 0);
    $type    = $_POST['alert_type']  ?? '';
    $val     = isset($_POST['target_value']) ? (float)$_POST['target_value'] : null;
    $conds   = $_POST['conditions_json'] ?? null;

    $validTypes = ['nav_above','nav_below','return_1y_above','return_3y_above',
                   'sharpe_above','aum_above','expense_below','multi_condition'];
    if (!$fundId || !in_array($type, $validTypes)) {
        echo json_encode(['success' => false, 'msg' => 'Invalid fund_id or alert_type']); break;
    }
    $db->prepare("
        INSERT INTO mf_watchlist_alerts (user_id, fund_id, alert_type, target_value, conditions_json)
        VALUES (?,?,?,?,?)
    ")->execute([$userId, $fundId, $type, $val, $conds]);
    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    break;

case 'wl_alert_delete':
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM mf_watchlist_alerts WHERE id=? AND user_id=?")->execute([$id, $userId]);
    echo json_encode(['success' => true]);
    break;

case 'wl_alert_snooze':
    $id   = (int)($_POST['id'] ?? 0);
    $days = (int)($_POST['days'] ?? 7);
    $db->prepare("UPDATE mf_watchlist_alerts SET snooze_until=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=? AND user_id=?")
       ->execute([$days, $id, $userId]);
    echo json_encode(['success' => true]);
    break;

case 'wl_alert_check':
    // Check all active alerts for this user — called by cron or on-demand
    $alerts = $db->prepare("
        SELECT a.*, f.current_nav, f.returns_1y, f.returns_3y, f.sharpe_ratio, f.aum, f.expense_ratio, f.fund_name
        FROM mf_watchlist_alerts a
        JOIN funds f ON f.id = a.fund_id
        WHERE a.user_id=? AND a.is_active=1
          AND (a.snooze_until IS NULL OR a.snooze_until < NOW())
    ");
    $alerts->execute([$userId]);
    $triggered = [];

    foreach ($alerts->fetchAll(PDO::FETCH_ASSOC) as $alert) {
        $fVal    = null;
        $matches = false;

        switch ($alert['alert_type']) {
            case 'nav_above':    $fVal = (float)$alert['current_nav']; $matches = $fVal >= (float)$alert['target_value']; break;
            case 'nav_below':    $fVal = (float)$alert['current_nav']; $matches = $fVal <= (float)$alert['target_value']; break;
            case 'return_1y_above': $fVal = (float)$alert['returns_1y']; $matches = $fVal >= (float)$alert['target_value']; break;
            case 'return_3y_above': $fVal = (float)$alert['returns_3y']; $matches = $fVal >= (float)$alert['target_value']; break;
            case 'sharpe_above': $fVal = (float)$alert['sharpe_ratio']; $matches = $fVal >= (float)$alert['target_value']; break;
            case 'expense_below': $fVal = (float)$alert['expense_ratio']; $matches = $alert['expense_ratio'] !== null && $fVal <= (float)$alert['target_value']; break;
            case 'multi_condition':
                if ($alert['conditions_json']) {
                    $conds = json_decode($alert['conditions_json'], true);
                    $matches = true;
                    foreach ($conds as $c) {
                        $cv = (float)($alert[$c['field']] ?? 0);
                        if ($c['op'] === '>=' && $cv < (float)$c['value']) { $matches = false; break; }
                        if ($c['op'] === '<=' && $cv > (float)$c['value']) { $matches = false; break; }
                    }
                }
                break;
        }

        if ($matches) {
            $db->prepare("UPDATE mf_watchlist_alerts SET last_triggered=NOW() WHERE id=?")->execute([$alert['id']]);
            $db->prepare("INSERT INTO mf_alert_history (alert_id, user_id, fund_id, trigger_val) VALUES (?,?,?,?)")
               ->execute([$alert['id'], $userId, $alert['fund_id'], $fVal]);
            $triggered[] = ['fund_name' => $alert['fund_name'], 'type' => $alert['alert_type'], 'value' => $fVal];
        }
    }
    echo json_encode(['success' => true, 'triggered' => $triggered, 'count' => count($triggered)]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}
