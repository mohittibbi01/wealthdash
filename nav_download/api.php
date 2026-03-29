<?php
/**
 * WealthDash — Full NAV History Download API
 * Path: wealthdash/nav_download/api.php
 *
 * FIX: JSON break hone se bachao — ob_start + error_reporting suppress
 * FIX: app_settings / from_date safe queries
 */

// ── 1. Output buffer: koi bhi PHP warning/notice JSON mein nahi jayegi ──────
ob_start();

// ── 2. PHP errors suppress karo (log hoti rahein, display nahi) ─────────────
error_reporting(0);
ini_set('display_errors', '0');

// ── 3. DB constants ──────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'wealthdash');

// ── 4. DB connect ────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'DB connect failed: ' . $e->getMessage()]);
    exit;
}

// ── 5. Buffer clear + JSON header ────────────────────────────────────────────
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// ── Helper: safe fetchColumn (NULL return karo on failure) ──────────────────
function safeVal(PDO $pdo, string $sql, array $params = []) {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

// ── Helper: ensure app_settings row exists ──────────────────────────────────
function ensureStopFlag(PDO $pdo): string {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_val TEXT NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            INSERT IGNORE INTO app_settings (setting_key, setting_val)
            VALUES ('nav_dl_stop', '0')
        ");
        $val = safeVal($pdo, "SELECT setting_val FROM app_settings WHERE setting_key='nav_dl_stop'");
        return $val ?? '0';
    } catch (Exception $e) {
        return '0';
    }
}

// ── Helper: ensure from_date column exists in nav_download_progress ─────────
function ensureFromDateCol(PDO $pdo): void {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM nav_download_progress LIKE 'from_date'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE nav_download_progress ADD COLUMN from_date DATE NULL AFTER last_downloaded_date");
        }
    } catch (Exception $e) { /* table nahi hai — ignore */ }
}

$action = $_GET['action'] ?? 'summary';

// ── SETUP ───────────────────────────────────────────────────────────────────
if ($action === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ensureFromDateCol($pdo);
        $pdo->exec("
            INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status)
            SELECT f.scheme_code, f.id, 'pending' FROM funds f
        ");
        $total = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress");
        echo json_encode(['ok' => true, 'total' => $total]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── SUMMARY ─────────────────────────────────────────────────────────────────
if ($action === 'summary') {
    try {
        ensureFromDateCol($pdo);

        // Auto-seed if empty
        $total = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress");
        if ($total === 0) {
            $pdo->exec("
                INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status)
                SELECT scheme_code, id, 'pending' FROM funds
            ");
            $total = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress");
        }

        $counts = $pdo->query("
            SELECT
                COUNT(*)                                        AS total,
                SUM(status = 'pending')                         AS pending,
                SUM(status = 'in_progress')                     AS working,
                SUM(status = 'completed')                       AS completed,
                SUM(status = 'error')                           AS errors,
                COALESCE(SUM(records_saved), 0)                 AS total_records,
                MIN(CASE WHEN status='completed' THEN last_downloaded_date END) AS oldest_dl,
                MAX(CASE WHEN status='completed' THEN last_downloaded_date END) AS latest_dl
            FROM nav_download_progress
        ")->fetch(PDO::FETCH_ASSOC);

        $completed = (int)($counts['completed'] ?? 0);
        $pending   = (int)($counts['pending']   ?? 0);
        $working   = (int)($counts['working']   ?? 0);
        $errors    = (int)($counts['errors']    ?? 0);
        $pct       = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        // Currently in_progress funds
        $current = $pdo->query("
            SELECT p.scheme_code, f.scheme_name,
                   p.from_date, p.last_downloaded_date, p.records_saved
            FROM nav_download_progress p
            LEFT JOIN funds f ON f.scheme_code = p.scheme_code
            WHERE p.status = 'in_progress'
            ORDER BY p.updated_at DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stopFlag = ensureStopFlag($pdo);

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
            'stop_flag'     => $stopFlag,
            'seeded'        => $total > 0,
            'timestamp'     => date('H:i:s'),
            'today'         => date('Y-m-d'),
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── TABLE ───────────────────────────────────────────────────────────────────
if ($action === 'table') {
    try {
        $tab    = $_GET['tab']    ?? 'pending';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 50;
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $sort   = $_GET['sort'] ?? '';
        $dir    = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $whereMap = [
            'pending'   => "p.status = 'pending'",
            'working'   => "p.status = 'in_progress'",
            'completed' => "p.status = 'completed'",
            'errors'    => "p.status = 'error'",
        ];
        $where  = $whereMap[$tab] ?? "p.status='pending'";
        $params = [];

        if ($search !== '') {
            $like    = '%' . $search . '%';
            $where  .= " AND (p.scheme_code LIKE ? OR f.scheme_name LIKE ? OR f.category LIKE ?)";
            $params  = [$like, $like, $like];
        }

        $sortMap = [
            'scheme_code'          => 'p.scheme_code',
            'scheme_name'          => 'f.scheme_name',
            'category'             => 'f.category',
            'from_date'            => 'p.from_date',
            'last_downloaded_date' => 'p.last_downloaded_date',
            'records_saved'        => 'p.records_saved',
        ];
        $orderBy = isset($sortMap[$sort]) ? "{$sortMap[$sort]} {$dir}" : "p.updated_at DESC";

        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nav_download_progress p
             LEFT JOIN funds f ON f.scheme_code = p.scheme_code
             WHERE {$where}"
        );
        $cntStmt->execute($params);
        $totalRows = (int)$cntStmt->fetchColumn();
        $pages     = max(1, (int)ceil($totalRows / $limit));

        $stmt = $pdo->prepare("
            SELECT
                p.scheme_code,
                COALESCE(f.scheme_name, '—')  AS scheme_name,
                COALESCE(f.category,   '—')   AS category,
                p.status,
                DATE_FORMAT(p.from_date,            '%Y-%m-%d') AS from_date,
                DATE_FORMAT(p.last_downloaded_date, '%Y-%m-%d') AS last_downloaded_date,
                COALESCE(p.records_saved, 0)        AS records_saved,
                p.error_message,
                p.updated_at
            FROM nav_download_progress p
            LEFT JOIN funds f ON f.scheme_code = p.scheme_code
            WHERE {$where}
            ORDER BY {$orderBy}
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'rows'       => $rows,
            'page'       => $page,
            'pages'      => $pages,
            'total_rows' => $totalRows,
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage(), 'rows' => [], 'page' => 1, 'pages' => 1, 'total_rows' => 0]);
    }
    exit;
}

// ── START PROCESSOR (background) ────────────────────────────────────────────
if ($action === 'start_processor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $parallel = max(1, min(50, (int)($_GET['parallel'] ?? 8)));

        // Stale in_progress reset
        $pdo->exec("
            UPDATE nav_download_progress
            SET status='pending'
            WHERE status='in_progress'
              AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");

        $pending   = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('pending','error')");
        $completed = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress WHERE status = 'completed'");
        $total     = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress");

        if ($pending === 0) {
            if ($total === 0) {
                $msg = 'nav_download_progress table empty hai. Pehle "Initialize" button click karo.';
            } elseif ($completed > 0) {
                $msg = "Sab {$completed} schemes already download ho chuki hain.";
            } else {
                $msg = 'Koi pending scheme nahi hai.';
            }
            echo json_encode(['ok' => false, 'message' => $msg, 'completed' => $completed, 'total' => $total]);
            exit;
        }

        $script = escapeshellarg(dirname(__FILE__) . '/processor.php');
        $php    = PHP_BINARY ?: 'php';
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B {$php} {$script} {$parallel}", 'r'));
        } else {
            exec("{$php} {$script} {$parallel} > /dev/null 2>&1 &");
        }

        $_SESSION['nav_dl_running'] = true;
        echo json_encode(['ok' => true, 'message' => "⚙️ Background mein start hua — {$pending} schemes pending."]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── STOP ────────────────────────────────────────────────────────────────────
if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('nav_dl_stop','1') ON DUPLICATE KEY UPDATE setting_val='1'");
        $pdo->exec("UPDATE nav_download_progress SET status='pending' WHERE status='in_progress'");
        echo json_encode(['ok' => true, 'message' => 'Stop requested.']);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── CLEAR STOP FLAG ──────────────────────────────────────────────────────────
if ($action === 'clear_stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('nav_dl_stop','0') ON DUPLICATE KEY UPDATE setting_val='0'");
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── RETRY ERRORS ─────────────────────────────────────────────────────────────
if ($action === 'retry_errors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $count = $pdo->exec("UPDATE nav_download_progress SET status='pending', error_message=NULL WHERE status='error'");
        echo json_encode(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── FULL RESET ───────────────────────────────────────────────────────────────
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("UPDATE nav_download_progress SET status='pending', from_date=NULL, last_downloaded_date=NULL, records_saved=0, error_message=NULL");
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── EXPORT CSV ───────────────────────────────────────────────────────────────
if ($action === 'export') {
    try {
        $rows = $pdo->query("
            SELECT p.scheme_code, COALESCE(f.scheme_name,'') AS scheme_name,
                   COALESCE(f.category,'') AS category,
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
    } catch (Exception $e) {
        echo 'Export error: ' . $e->getMessage();
    }
    exit;
}

echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);