<?php
/**
 * WealthDash — NAV History Downloader API
 * Path: wealthdash/nav_history_downloader/api.php
 */

header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'wealthdash');

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]); exit;
}

$action = $_GET['action'] ?? 'summary';

// ── STOP ───────────────────────────────────────────────────────
if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Set stop flag — processor checks this every batch
    $pdo->prepare("UPDATE app_settings SET setting_val='stop_requested' WHERE setting_key='nav_history_status'")->execute();
    $pdo->prepare("UPDATE app_settings SET setting_val='' WHERE setting_key='nav_history_current_batch'")->execute();
    // Revert any in_progress back to pending so next run continues from where stopped
    $pdo->exec("UPDATE nav_download_progress SET status='pending' WHERE status='in_progress'");
    echo json_encode(['success' => true, 'message' => 'Stop requested. Current batch will finish then processor will stop.']);
    exit;
}

// ── SUMMARY ────────────────────────────────────────────────────
if ($action === 'summary') {
    $counts = $pdo->query("
        SELECT
            COUNT(*)                                    AS total,
            SUM(status='pending')                       AS pending,
            SUM(status='in_progress')                   AS working,
            SUM(status='completed')                     AS completed,
            SUM(status='error')                         AS errors,
            SUM(records_saved)                          AS total_records,
            MIN(last_downloaded_date)                   AS oldest_date,
            MAX(last_downloaded_date)                   AS latest_date
        FROM nav_download_progress
    ")->fetch();

    $fromDate = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_from_date'")->fetchColumn();
    $status   = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_status'")->fetchColumn();
    $lastRun  = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_last_run'")->fetchColumn();
    $currBatch= $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_current_batch'")->fetchColumn();

    $total     = (int)$counts['total'];
    $completed = (int)$counts['completed'];
    $pct       = $total > 0 ? round($completed / $total * 100, 1) : 0;

    // Nav_history table stats — filter by from_date so old data doesn't confuse
    $nhStats = $pdo->prepare("
        SELECT COUNT(*) as total_rows,
               MIN(nav_date) as oldest,
               MAX(nav_date) as newest,
               COUNT(DISTINCT fund_id) as funds_with_data
        FROM nav_history
        WHERE nav_date >= ?
    ");
    $nhStats->execute([$fromDate ?: '2000-01-01']);
    $nhStats = $nhStats->fetch();

    // In-progress funds detail — what's currently being downloaded
    $inProgressFunds = $pdo->query("
        SELECT p.scheme_code, f.scheme_name, p.from_date
        FROM nav_download_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE p.status = 'in_progress'
        ORDER BY p.updated_at DESC
        LIMIT 8
    ")->fetchAll();

    echo json_encode([
        'counts'          => $counts,
        'pct'             => $pct,
        'from_date'       => $fromDate ?: '2025-01-01',
        'run_status'      => $status ?: 'idle',
        'last_run'        => $lastRun ?: '—',
        'current_batch'   => $currBatch ?: '',
        'in_progress_funds' => $inProgressFunds,
        'nav_history'     => $nhStats,
        'today'           => date('Y-m-d'),
        'timestamp'       => date('H:i:s'),
    ]);
    exit;
}

// ── TABLE ──────────────────────────────────────────────────────
if ($action === 'table') {
    $tab    = $_GET['tab']  ?? 'pending';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 50;
    $offset = ($page - 1) * $limit;

    $whereMap = [
        'pending'   => "p.status = 'pending'",
        'working'   => "p.status = 'in_progress'",
        'completed' => "p.status = 'completed'",
        'errors'    => "p.status = 'error'",
    ];
    $where = $whereMap[$tab] ?? "p.status='pending'";

    $total = $pdo->query("SELECT COUNT(*) FROM nav_download_progress p WHERE {$where}")->fetchColumn();
    $pages = max(1, (int)ceil($total / $limit));

    $rows = $pdo->query("
        SELECT
            p.scheme_code,
            p.status,
            p.from_date,
            p.last_downloaded_date,
            p.records_saved,
            p.error_message,
            p.updated_at,
            f.scheme_name
        FROM nav_download_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE {$where}
        ORDER BY p.updated_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ")->fetchAll();

    echo json_encode([
        'rows'       => $rows,
        'page'       => (int)$page,
        'pages'      => $pages,
        'total_rows' => (int)$total,
    ]);
    exit;
}

// ── SET FROM DATE (Admin action) ───────────────────────────────
if ($action === 'set_from_date' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = json_decode(file_get_contents('php://input'), true);
    $fromDate = $input['from_date'] ?? '';

    // Validate date
    $d = DateTime::createFromFormat('Y-m-d', $fromDate);
    if (!$d || $d->format('Y-m-d') !== $fromDate) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']); exit;
    }

    $pdo->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='nav_history_from_date'")->execute([$fromDate]);
    $pdo->prepare("UPDATE app_settings SET setting_val='' WHERE setting_key='nav_history_current_batch'")->execute();

    // Reset ALL funds to pending with new from_date
    $pdo->prepare("
        UPDATE nav_download_progress
        SET status='pending', from_date=?, last_downloaded_date=NULL, records_saved=0, error_message=NULL
    ")->execute([$fromDate]);

    echo json_encode(['success' => true, 'message' => "From date set to {$fromDate}. All funds reset to pending."]);
    exit;
}

// ── EXTEND FROM DATE (admin extends further back) ──────────────
if ($action === 'extend_from_date' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = json_decode(file_get_contents('php://input'), true);
    $fromDate = $input['from_date'] ?? '';

    $d = DateTime::createFromFormat('Y-m-d', $fromDate);
    if (!$d || $d->format('Y-m-d') !== $fromDate) {
        echo json_encode(['success' => false, 'message' => 'Invalid date']); exit;
    }

    // Update setting
    $pdo->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='nav_history_from_date'")->execute([$fromDate]);

    // Reset only completed funds — re-download from new earlier date
    $pdo->prepare("
        UPDATE nav_download_progress
        SET status='pending', from_date=?, last_downloaded_date=NULL, records_saved=0, error_message=NULL
        WHERE status = 'completed' OR status = 'error'
    ")->execute([$fromDate]);

    // Also delete existing nav_history records before old from_date that are now needed
    // (they don't exist, so nothing to delete — just mark for re-download)

    $affected = $pdo->rowCount();
    echo json_encode(['success' => true, 'message' => "{$affected} funds queued for re-download from {$fromDate}."]);
    exit;
}

// ── RESEED (add any new funds not yet in progress table) ───────
if ($action === 'reseed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromDate = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_from_date'")->fetchColumn();

    $pdo->prepare("
        INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status, from_date)
        SELECT f.scheme_code, f.id, 'pending', ?
        FROM funds f
        WHERE f.is_active = 1
    ")->execute([$fromDate ?: '2025-01-01']);

    $added = $pdo->rowCount();
    echo json_encode(['success' => true, 'message' => "{$added} new funds added to download queue."]);
    exit;
}

// ── RETRY ERRORS ───────────────────────────────────────────────
if ($action === 'retry_errors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromDate = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_from_date'")->fetchColumn();
    $pdo->prepare("UPDATE nav_download_progress SET status='pending', from_date=?, error_message=NULL WHERE status='error'")
        ->execute([$fromDate ?: '2025-01-01']);
    $n = $pdo->rowCount();
    echo json_encode(['success' => true, 'message' => "{$n} error funds reset to pending."]);
    exit;
}

// ── RESET ALL ──────────────────────────────────────────────────
if ($action === 'reset_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromDate = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_from_date'")->fetchColumn();
    $pdo->exec("UPDATE nav_download_progress SET status='pending', last_downloaded_date=NULL, records_saved=0, error_message=NULL");
    echo json_encode(['success' => true, 'message' => 'All funds reset to pending. Ready to re-download.']);
    exit;
}

// ── ERRORS LIST ────────────────────────────────────────────────
if ($action === 'errors') {
    $rows = $pdo->query("
        SELECT p.scheme_code, f.scheme_name, p.error_message, p.updated_at
        FROM nav_download_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE p.status = 'error'
        ORDER BY p.updated_at DESC
        LIMIT 200
    ")->fetchAll();
    echo json_encode(['rows' => $rows]);
    exit;
}

// ── EXPORT ─────────────────────────────────────────────────────
if ($action === 'export') {
    $filter = $_GET['filter'] ?? 'all';
    $where  = $filter === 'completed' ? "WHERE p.status='completed'" : '';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="nav_download_' . date('Ymd_His') . '.csv"');

    $rows = $pdo->query("
        SELECT p.scheme_code, f.scheme_name, p.status, p.from_date,
               p.last_downloaded_date, p.records_saved, p.error_message
        FROM nav_download_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        {$where}
        ORDER BY p.status, p.scheme_code
    ")->fetchAll();

    echo "Scheme Code,Fund Name,Status,From Date,Last Downloaded,Records Saved,Error\n";
    foreach ($rows as $r) {
        echo implode(',', [
            $r['scheme_code'],
            '"'.str_replace('"','""',$r['scheme_name']??'').'"',
            $r['status'],
            $r['from_date']??'',
            $r['last_downloaded_date']??'',
            $r['records_saved']??0,
            '"'.str_replace('"','""',$r['error_message']??'').'"',
        ])."\n";
    }
    exit;
}

echo json_encode(['error' => 'Unknown action']);