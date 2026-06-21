<?php
/**
 * WealthDash — Notifications Center API (t57 / t81)
 * Actions: notif_list, notif_mark_read, notif_mark_all_read,
 *          notif_clear_all, notif_unread_count, notif_prefs_get, notif_prefs_save
 */
define('WEALTHDASH', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0); ini_set('display_errors', '0');
ob_start();
$user   = require_auth();
$userId = (int)$user['id'];
$db     = DB::conn();

// Check if table exists
$tableOk = false;
try { $db->query("SELECT 1 FROM notifications LIMIT 1"); $tableOk = true; } catch(Exception $e) {}

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

try {

    /* ── GET: unread count (fast, called on every page load) ── */
    if ($action === 'notif_unread_count') {
        $count = $tableOk
            ? (int)$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0")
                      ->execute([$userId]) ? $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0")->execute([$userId]) : 0
            : 0;
        // Simpler:
        if ($tableOk) {
            $s = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $s->execute([$userId]);
            $count = (int)$s->fetchColumn();
        } else { $count = 0; }
        ob_clean();
        echo json_encode(['success' => true, 'count' => $count]);
        exit;
    }

    /* ── GET: list notifications ── */
    if ($action === 'notif_list') {
        $limit  = min((int)($_GET['limit'] ?? 30), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $items  = [];
        $total  = 0;
        $unread = 0;
        if ($tableOk) {
            $s = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY triggered_at DESC LIMIT ? OFFSET ?");
            $s->execute([$userId, $limit, $offset]);
            $items = $s->fetchAll();
            $total  = (int)$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?")->execute([$userId]);
            $s2 = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
            $s2->execute([$userId]); $total = (int)$s2->fetchColumn();
            $s3 = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $s3->execute([$userId]); $unread = (int)$s3->fetchColumn();
        }
        ob_clean();
        echo json_encode(['success' => true, 'items' => $items, 'total' => $total, 'unread' => $unread, 'table_exists' => $tableOk]);
        exit;
    }

    /* ── POST: mark one as read ── */
    if ($action === 'notif_mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($tableOk && $id) {
            $s = $db->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?");
            $s->execute([$id, $userId]);
        }
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── POST: mark all read ── */
    if ($action === 'notif_mark_all_read') {
        if ($tableOk) {
            $s = $db->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0");
            $s->execute([$userId]);
        }
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── POST: clear all ── */
    if ($action === 'notif_clear_all') {
        if ($tableOk) {
            $s = $db->prepare("DELETE FROM notifications WHERE user_id=?");
            $s->execute([$userId]);
        }
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── GET: prefs ── */
    if ($action === 'notif_prefs_get') {
        $prefs = ['nav_alerts'=>1,'fd_maturity'=>1,'sip_reminder'=>1,'drawdown_alerts'=>1,'nfo_alerts'=>1,'system'=>1,'goal'=>1,'tax'=>1];
        try {
            $s = $db->prepare("SELECT * FROM notification_prefs WHERE user_id=?");
            $s->execute([$userId]);
            $row = $s->fetch();
            if ($row) $prefs = array_merge($prefs, $row);
        } catch(Exception $e) {}
        ob_clean();
        echo json_encode(['success' => true, 'prefs' => $prefs]);
        exit;
    }

    /* ── POST: save prefs ── */
    if ($action === 'notif_prefs_save') {
        $fields = ['nav_alerts','fd_maturity','sip_reminder','drawdown_alerts','nfo_alerts'];
        $vals   = [];
        foreach ($fields as $f) $vals[$f] = isset($_POST[$f]) ? 1 : 0;
        try {
            $cols = implode(',', array_keys($vals));
            $phs  = implode(',', array_fill(0, count($vals), '?'));
            $upds = implode(',', array_map(fn($c) => "$c=VALUES($c)", array_keys($vals)));
            $stmt = $db->prepare("INSERT INTO notification_prefs (user_id,$cols) VALUES (?,$phs) ON DUPLICATE KEY UPDATE $upds");
            $stmt->execute(array_merge([$userId], array_values($vals)));
        } catch(Exception $e) {}
        ob_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unknown action']);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
