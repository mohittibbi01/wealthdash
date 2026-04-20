<?php
/**
 * WealthDash — NAV Download Status API
 * Tasks: t193 — Live dashboard for NAV download progress
 * Actions: ndl_status | ndl_start | ndl_reset_failed | ndl_summary
 */

define('WEALTHDASH', true);
require_once __DIR__ . '/../config/database.php';

// Simple auth check for local use
session_start();
if (empty($_SESSION['user_id']) && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$db     = DB::conn();
$action = $_GET['action'] ?? $_POST['action'] ?? 'ndl_status';

switch ($action) {

case 'ndl_status':
    $total     = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress")->fetchColumn();
    $done      = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress WHERE status='done'")->fetchColumn();
    $failed    = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress WHERE status='failed'")->fetchColumn();
    $inProg    = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress WHERE status='in_progress'")->fetchColumn();
    $pending   = $total - $done - $failed - $inProg;
    $pct       = $total > 0 ? round($done / $total * 100, 1) : 0;

    // Recent completions
    $recent = $db->query("SELECT p.fund_id, p.scheme_code, p.nav_count, f.fund_name FROM nav_download_progress p
                          JOIN funds f ON f.id=p.fund_id WHERE p.status='done' ORDER BY p.last_attempt DESC LIMIT 10")
                 ->fetchAll(PDO::FETCH_ASSOC);

    // Failed list
    $failedList = $db->query("SELECT p.fund_id, p.scheme_code, p.error_msg, f.fund_name FROM nav_download_progress p
                              JOIN funds f ON f.id=p.fund_id WHERE p.status='failed' LIMIT 20")
                     ->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'total' => $total, 'done' => $done, 'failed' => $failed,
        'in_progress' => $inProg, 'pending' => $pending,
        'pct' => $pct, 'recent' => $recent, 'failed_list' => $failedList,
    ]);
    break;

case 'ndl_start':
    // Spawn processor in background
    $workers = (int)($_POST['workers'] ?? 4);
    $logFile = __DIR__ . '/../logs/nav_download.log';
    @mkdir(dirname($logFile), 0755, true);
    exec("php " . __DIR__ . "/processor.php --workers=$workers > $logFile 2>&1 &");
    echo json_encode(['success' => true, 'msg' => "Processor started with $workers workers"]);
    break;

case 'ndl_reset_failed':
    $db->exec("UPDATE nav_download_progress SET status='pending' WHERE status='failed'");
    echo json_encode(['success' => true]);
    break;

case 'ndl_summary':
    $navTotal = (int)$db->query("SELECT COUNT(*) FROM nav_history")->fetchColumn();
    $fundsWithHistory = (int)$db->query("SELECT COUNT(DISTINCT fund_id) FROM nav_history")->fetchColumn();
    $oldest = $db->query("SELECT MIN(nav_date) FROM nav_history")->fetchColumn();
    $latest = $db->query("SELECT MAX(nav_date) FROM nav_history")->fetchColumn();
    echo json_encode(compact('navTotal', 'fundsWithHistory', 'oldest', 'latest'));
    break;

default:
    echo json_encode(['error' => "Unknown action: $action"]);
}
