<?php
/**
 * WealthDash — Peak NAV AJAX API
 * Path: wealthdash/peak_nav/api.php
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

// ── SUMMARY ────────────────────────────────────────────
if ($action === 'summary') {
    $counts = $pdo->query("
        SELECT
            COUNT(*)                                                     AS total,
            SUM(status='pending')                                        AS pending,
            SUM(status='in_progress')                                    AS working,
            SUM(status='completed')                                      AS completed,
            SUM(status='error')                                          AS errors,
            SUM(status='completed' AND last_processed_date = CURDATE()) AS up_to_date,
            SUM(status='completed' AND last_processed_date < CURDATE()) AS needs_update,
            MIN(last_processed_date)                                     AS oldest_update,
            MAX(last_processed_date)                                     AS latest_update
        FROM mf_peak_progress
    ")->fetch(PDO::FETCH_ASSOC);

    $total       = (int)$counts['total'];
    $completed   = (int)$counts['completed'];    // up_to_date + needs_update
    $upToDate    = (int)$counts['up_to_date'];   // done TODAY  → progress "done"
    $needsUpdate = (int)$counts['needs_update']; // stale completed → treated as not-done
    $pending     = (int)$counts['pending'];
    $working     = (int)$counts['working'];
    $errors      = (int)$counts['errors'];

    // ── PROGRESS FORMULA ────────────────────────────────────────────────────
    // effective_total = done(today) + needs_update + pending + working
    // pct             = done(today) / effective_total × 100
    // errors are excluded — they sit in retry queue, unrelated to progress
    $effectiveTotal = $upToDate + $needsUpdate + $pending + $working;
    $pct = $effectiveTotal > 0
        ? round(($upToDate / $effectiveTotal) * 100, 1)
        : 0;

    $notDone = $pending + $working + $needsUpdate; // all remaining real work

    // Stop flag
    $stopFlag = $pdo->query(
        "SELECT setting_val FROM app_settings WHERE setting_key='peak_nav_stop'"
    )->fetchColumn();

    echo json_encode([
        'counts'          => $counts,
        'pct'             => $pct,
        'not_done'        => $notDone,
        'effective_total' => $effectiveTotal,
        'up_to_date'      => $upToDate,      // JS convenience — avoids parsing counts.up_to_date
        'needs_update'    => $needsUpdate,   // JS convenience — breakdown table ke liye
        'stop_flag'       => $stopFlag ?: '',
        'timestamp'       => date('H:i:s'),
        'today'           => date('Y-m-d'),
    ]);
    exit;
}

// ── STOP ───────────────────────────────────────────────
if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('peak_nav_stop','1') ON DUPLICATE KEY UPDATE setting_val='1'");
        $pdo->exec("UPDATE mf_peak_progress SET status='pending' WHERE status='in_progress'");
        echo json_encode(['ok' => true, 'message' => 'Stop requested. Processor will halt after current batch.']);
    } catch(Exception $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── CLEAR STOP FLAG ────────────────────────────────────
if ($action === 'clear_stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('peak_nav_stop','0') ON DUPLICATE KEY UPDATE setting_val='0'");
    echo json_encode(['ok' => true]);
    exit;
}

// ── TABLE ──────────────────────────────────────────────
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

    $total = $pdo->query("SELECT COUNT(*) FROM mf_peak_progress p WHERE {$where}")->fetchColumn();
    $pages = max(1, (int)ceil($total / $limit));

    $rows = $pdo->query("
        SELECT
            p.scheme_code,
            f.scheme_name,
            f.highest_nav,
            f.highest_nav_date,
            p.status,
            p.error_message,
            p.last_processed_date,
            p.updated_at
        FROM mf_peak_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE {$where}
        ORDER BY p.updated_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total_rows'=>(int)$total]);
    exit;
}

// ── ERRORS ONLY ────────────────────────────────────────
if ($action === 'errors') {
    $rows = $pdo->query("
        SELECT p.scheme_code, f.scheme_name, p.error_message, p.updated_at
        FROM mf_peak_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE p.status = 'error'
        ORDER BY p.updated_at DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['rows' => $rows]);
    exit;
}

// ── EXCEL EXPORT ───────────────────────────────────────
if ($action === 'export') {
    $filter = $_GET['filter'] ?? 'all';

    $whereMap = [
        'all'       => "1=1",
        'completed' => "p.status='completed'",
        'errors'    => "p.status='error'",
        'pending'   => "p.status IN ('pending','in_progress')",
    ];
    $where = $whereMap[$filter] ?? '1=1';

    $rows = $pdo->query("
        SELECT
            f.scheme_code,
            f.scheme_name,
            f.category,
            f.option_type,
            f.highest_nav,
            f.highest_nav_date,
            f.latest_nav,
            f.latest_nav_date,
            p.status,
            p.last_processed_date,
            p.error_message
        FROM mf_peak_progress p
        LEFT JOIN funds f ON f.scheme_code = p.scheme_code
        WHERE {$where}
        ORDER BY f.scheme_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="peak_nav_export_'.date('Y-m-d').'.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

    fputcsv($out, [
        'Scheme Code', 'Scheme Name', 'Category', 'Option Type',
        'Highest NAV (Rs)', 'Peak Date',
        'Latest NAV (Rs)', 'Latest NAV Date',
        'Status', 'Last Processed Date', 'Error Message'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['scheme_code'],
            $r['scheme_name'],
            $r['category'],
            $r['option_type'],
            $r['highest_nav'],
            $r['highest_nav_date'],
            $r['latest_nav'],
            $r['latest_nav_date'],
            $r['status'],
            $r['last_processed_date'],
            $r['error_message'],
        ]);
    }
    fclose($out);
    exit;
}

// ── RESET ──────────────────────────────────────────────
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->exec("UPDATE mf_peak_progress SET status='pending', last_processed_date=NULL, error_message=NULL");
    $pdo->exec("UPDATE funds SET highest_nav=NULL, highest_nav_date=NULL WHERE is_active=1");
    echo json_encode(['ok' => true]);
    exit;
}

// ── RETRY ERRORS ───────────────────────────────────────
if ($action === 'retry_errors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = $pdo->exec("UPDATE mf_peak_progress SET status='pending', error_message=NULL WHERE status='error'");
    echo json_encode(['ok' => true, 'count' => $count]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);