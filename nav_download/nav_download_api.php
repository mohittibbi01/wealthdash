<?php
/**
 * WealthDash — Full NAV History Download API
 * Path: wealthdash/nav_download/api.php
 */

header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'wealthdash');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]); exit;
}

$action = $_GET['action'] ?? 'summary';

// ── SETUP: Seed nav_download_progress from funds ───────────────────────────
if ($action === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $inserted = $pdo->exec("
        INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status)
        SELECT f.scheme_code, f.id, 'pending'
        FROM funds f
        WHERE NOT EXISTS (
            SELECT 1 FROM nav_download_progress p WHERE p.scheme_code = f.scheme_code
        )
    ");
    $total = (int)$pdo->query("SELECT COUNT(*) FROM nav_download_progress")->fetchColumn();
    echo json_encode(['ok' => true, 'inserted' => $inserted, 'total' => $total]);
    exit;
}

// ── SUMMARY ────────────────────────────────────────────────────────────────
if ($action === 'summary') {
    $counts = $pdo->query("
        SELECT
            COUNT(*)                                    AS total,
            SUM(status = 'pending')                     AS pending,
            SUM(status = 'in_progress')                 AS working,
            SUM(status = 'completed')                   AS completed,
            SUM(status = 'error')                       AS errors,
            SUM(records_saved)                          AS total_records,
            MIN(CASE WHEN status='completed' THEN last_downloaded_date END) AS oldest_dl,
            MAX(CASE WHEN status='completed' THEN last_downloaded_date END) AS latest_dl
        FROM nav_download_progress
    ")->fetch(PDO::FETCH_ASSOC);

    $total     = (int)$counts['total'];
    $completed = (int)$counts['completed'];
    $pending   = (int)$counts['pending'];
    $working   = (int)$counts['working'];
    $errors    = (int)$counts['errors'];

    $pct = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

    // Current fund being downloaded (in_progress)
    $current = $pdo->query("
        SELECT p.scheme_code, f.scheme_name, p.from_date, p.last_downloaded_date, p.records_saved
        FROM nav_download_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE p.status = 'in_progress'
        ORDER BY p.updated_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Stop flag
    $stopFlag = $pdo->query(
        "SELECT setting_val FROM app_settings WHERE setting_key='nav_dl_stop'"
    )->fetchColumn();

    // Seeded?
    $seeded = $total > 0;

    echo json_encode([
        'counts'        => $counts,
        'pct'           => $pct,
        'total'         => $total,
        'completed'     => $completed,
        'pending'       => $pending,
        'working'       => $working,
        'errors'        => $errors,
        'total_records' => (int)($counts['total_records'] ?? 0),
        'current_funds' => $current,
        'stop_flag'     => $stopFlag ?: '0',
        'seeded'        => $seeded,
        'timestamp'     => date('H:i:s'),
        'today'         => date('Y-m-d'),
    ]);
    exit;
}

// ── TABLE ──────────────────────────────────────────────────────────────────
if ($action === 'table') {
    $tab    = $_GET['tab']    ?? 'pending';
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

    $total = (int)$pdo->query(
        "SELECT COUNT(*) FROM nav_download_progress p WHERE {$where}"
    )->fetchColumn();
    $pages = max(1, (int)ceil($total / $limit));

    $rows = $pdo->query("
        SELECT
            p.scheme_code,
            f.scheme_name,
            f.category,
            p.status,
            p.from_date,
            p.last_downloaded_date,
            p.records_saved,
            p.error_message,
            p.updated_at
        FROM nav_download_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE {$where}
        ORDER BY p.updated_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'rows'       => $rows,
        'page'       => $page,
        'pages'      => $pages,
        'total_rows' => $total,
    ]);
    exit;
}

// ── STOP ───────────────────────────────────────────────────────────────────
if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('nav_dl_stop','1') ON DUPLICATE KEY UPDATE setting_val='1'");
    $pdo->exec("UPDATE nav_download_progress SET status='pending' WHERE status='in_progress'");
    echo json_encode(['ok' => true, 'message' => 'Stop requested. Processor will halt after current batch.']);
    exit;
}

// ── CLEAR STOP FLAG ────────────────────────────────────────────────────────
if ($action === 'clear_stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('nav_dl_stop','0') ON DUPLICATE KEY UPDATE setting_val='0'");
    echo json_encode(['ok' => true]);
    exit;
}

// ── RETRY ERRORS ───────────────────────────────────────────────────────────
if ($action === 'retry_errors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = $pdo->exec("UPDATE nav_download_progress SET status='pending', error_message=NULL WHERE status='error'");
    echo json_encode(['ok' => true, 'count' => $count]);
    exit;
}

// ── FULL RESET ─────────────────────────────────────────────────────────────
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->exec("UPDATE nav_download_progress SET status='pending', from_date=NULL, last_downloaded_date=NULL, records_saved=0, error_message=NULL");
    echo json_encode(['ok' => true]);
    exit;
}

// ── EXPORT CSV ─────────────────────────────────────────────────────────────
if ($action === 'export') {
    $rows = $pdo->query("
        SELECT
            p.scheme_code, f.scheme_name, f.category,
            p.status, p.from_date, p.last_downloaded_date,
            p.records_saved, p.error_message, p.updated_at
        FROM nav_download_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        ORDER BY p.status, f.scheme_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nav_download_status_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Scheme Code','Scheme Name','Category','Status','From Date','Last Downloaded','Records Saved','Error','Updated At']);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
