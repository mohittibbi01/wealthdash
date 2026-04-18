<?php
/**
 * WealthDash — GodMode Unified API
 * Path: wealthdash/gmu_api.php
 *
 * Schema-aware: detects actual column names at runtime.
 * Actual nav_download_progress has: id, fund_id, scheme_code, status,
 *   total_records, last_nav_date, error_message, updated_at, peak_calculated
 * Statuses in use: pending | in_progress | needs_update | completed | error
 *
 * "Done" tab = completed + needs_update (both were downloaded at least once)
 */

ob_start();
error_reporting(0);
ini_set('display_errors', '0');

define('DB_HOST',   'localhost');
define('DB_USER',   'root');
define('DB_PASS',   '');
define('DB_NAME',   'wealthdash');
define('STOP_KEY',  'gmu_stop');
define('STATUS_KEY','gmu_status');

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

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

function dbCols(PDO $pdo, string $tbl): array {
    $c = [];
    try { foreach ($pdo->query("SHOW COLUMNS FROM `{$tbl}`") as $r) $c[] = $r['Field']; } catch (Exception $e) {}
    return $c;
}
function safeVal(PDO $pdo, string $sql, array $p = []) {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
    catch (Exception $e) { return null; }
}

// ── Schema detection (done once, used by all actions) ───────────────────────
$cols        = dbCols($pdo, 'nav_download_progress');
$hasSchCode  = in_array('scheme_code',     $cols);
$hasPeakCol  = in_array('peak_calculated', $cols);
$hasErrCol   = in_array('error_message',   $cols);

// Records column name varies between schema versions
$colRec  = in_array('total_records',        $cols) ? 'total_records'
         : (in_array('records_saved',        $cols) ? 'records_saved' : null);
// Date column name varies
$colDate = in_array('last_nav_date',         $cols) ? 'last_nav_date'
         : (in_array('last_downloaded_date', $cols) ? 'last_downloaded_date' : null);

// JOIN condition and scheme_code select depend on schema
$joinOn   = $hasSchCode ? "f.scheme_code = p.scheme_code" : "f.id = p.fund_id";
$scSelect = $hasSchCode ? "p.scheme_code" : "COALESCE(f.scheme_code,'') AS scheme_code";

$action = $_GET['action'] ?? 'summary';

// ════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════
if ($action === 'summary') {
    try {
        $total = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress");

        $recExpr  = $colRec    ? "COALESCE(SUM(p.{$colRec}),0)"    : "0";
        $peakExpr = $hasPeakCol ? "COALESCE(SUM(p.peak_calculated),0)" : "0";

        $counts = $pdo->query("
            SELECT
                COUNT(*)                                   AS total,
                SUM(status IN ('completed','needs_update')) AS done,
                SUM(status = 'needs_update')                AS needs_update,
                SUM(status = 'pending')                     AS pending,
                SUM(status = 'in_progress')                 AS working,
                SUM(status = 'error')                       AS errors,
                {$recExpr}                                  AS total_records,
                {$peakExpr}                                 AS peaks_done
            FROM nav_download_progress p
        ")->fetch(PDO::FETCH_ASSOC);

        $done        = (int)($counts['done']         ?? 0);
        $needsUpdate = (int)($counts['needs_update'] ?? 0);
        $pending     = (int)($counts['pending']      ?? 0);
        $working     = (int)($counts['working']      ?? 0);
        $errors      = (int)($counts['errors']       ?? 0);
        $totalRecs   = (int)($counts['total_records']?? 0);
        $peaksDone   = (int)($counts['peaks_done']   ?? 0);
        $pct         = $total > 0 ? round(($done / $total) * 100, 1) : 0;

        // Fallbacks
        if ($totalRecs === 0)
            $totalRecs = (int)(safeVal($pdo, "SELECT COUNT(*) FROM nav_history") ?? 0);
        if ($peaksDone === 0 && !$hasPeakCol)
            $peaksDone = (int)(safeVal($pdo, "SELECT COUNT(*) FROM funds WHERE highest_nav > 0") ?? 0);

        $stopFlag   = safeVal($pdo, "SELECT setting_val FROM app_settings WHERE setting_key='" . STOP_KEY  . "'") ?? '0';
        $statusFlag = safeVal($pdo, "SELECT setting_val FROM app_settings WHERE setting_key='" . STATUS_KEY . "'") ?? 'idle';
        $lastDone   = safeVal($pdo, "SELECT setting_val FROM app_settings WHERE setting_key='gmu_last_completed'") ?? '';

        $recSel  = $colRec    ? "p.{$colRec} AS records_saved" : "0 AS records_saved";
        $peakSel = $hasPeakCol ? "p.peak_calculated"           : "0 AS peak_calculated";

        $current = $pdo->query("
            SELECT {$scSelect},
                   COALESCE(f.scheme_name, CONCAT('Fund #',p.fund_id)) AS scheme_name,
                   {$recSel}, {$peakSel}, p.updated_at
            FROM nav_download_progress p
            LEFT JOIN funds f ON {$joinOn}
            WHERE p.status = 'in_progress'
            ORDER BY p.updated_at DESC LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recent = $pdo->query("
            SELECT {$scSelect},
                   COALESCE(f.scheme_name, CONCAT('Fund #',p.fund_id)) AS scheme_name,
                   {$recSel}, {$peakSel}, p.updated_at
            FROM nav_download_progress p
            LEFT JOIN funds f ON {$joinOn}
            WHERE p.status IN ('completed','needs_update')
            ORDER BY p.updated_at DESC LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'total'         => $total,
            'completed'     => $done,
            'needs_update'  => $needsUpdate,
            'pending'       => $pending,
            'working'       => $working,
            'errors'        => $errors,
            'total_records' => $totalRecs,
            'peaks_done'    => $peaksDone,
            'pct'           => $pct,
            'stop_flag'     => $stopFlag,
            'status'        => $statusFlag,
            'last_completed'=> $lastDone,
            'current_funds' => $current,
            'recent_done'   => $recent,
            'timestamp'     => date('H:i:s'),
        ]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════
// START
// ════════════════════════════════════════════════════════════════
if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $parallel = max(1, min(50, (int)($_POST['parallel'] ?? $_GET['parallel'] ?? 8)));

        $pdo->exec("UPDATE nav_download_progress SET status='pending', updated_at=NOW()
                    WHERE status='in_progress' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('" . STOP_KEY . "','0')
                    ON DUPLICATE KEY UPDATE setting_val='0'");

        $pending = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('pending','error','needs_update')");
        $total   = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress");

        if ($pending === 0) {
            $done = (int) safeVal($pdo, "SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('completed','needs_update')");
            echo json_encode(['ok' => false,
                'message'  => $total === 0 ? 'Progress table empty.' : "Sab {$done}/{$total} funds complete.",
                'all_done' => $total > 0]);
            exit;
        }

        $script = escapeshellarg(__DIR__ . '/gmu_processor.php');
        $php    = escapeshellarg(PHP_BINARY ?: 'php');
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B {$php} {$script} {$parallel}", 'r'));
        } else {
            exec("{$php} {$script} {$parallel} > /dev/null 2>&1 &");
        }
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('" . STATUS_KEY . "','running')
                    ON DUPLICATE KEY UPDATE setting_val='running'");

        echo json_encode(['ok' => true, 'message' => "⚡ Pipeline started — {$pending} funds queued.", 'pending' => $pending]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════
// STOP
// ════════════════════════════════════════════════════════════════
if ($action === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('" . STOP_KEY . "','1') ON DUPLICATE KEY UPDATE setting_val='1'");
        $pdo->exec("UPDATE nav_download_progress SET status='pending', updated_at=NOW() WHERE status='in_progress'");
        echo json_encode(['ok' => true, 'message' => 'Pipeline stopping... Progress saved.']);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ════════════════════════════════════════════════════════════════
// RETRY ERRORS
// ════════════════════════════════════════════════════════════════
if ($action === 'retry_errors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $errParts = ["status='pending'", "updated_at=NOW()"];
        if ($hasErrCol) $errParts[] = "error_message=NULL";
        $count = $pdo->exec("UPDATE nav_download_progress SET " . implode(', ', $errParts) . " WHERE status='error'");
        echo json_encode(['ok' => true, 'count' => $count, 'message' => "{$count} error funds queued for retry."]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ════════════════════════════════════════════════════════════════
// RESET
// ════════════════════════════════════════════════════════════════
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $parts = ["status='pending'", "updated_at=NOW()"];
        if ($hasErrCol)                              $parts[] = "error_message=NULL";
        if ($colDate)                                $parts[] = "{$colDate}=NULL";
        if (in_array('from_date',         $cols))    $parts[] = "from_date=NULL";
        if ($colRec)                                 $parts[] = "{$colRec}=0";
        if ($hasPeakCol)                             $parts[] = "peak_calculated=0";
        $pdo->exec("UPDATE nav_download_progress SET " . implode(', ', $parts));
        $pdo->exec("UPDATE app_settings SET setting_val='idle' WHERE setting_key='" . STATUS_KEY . "'");
        $pdo->exec("UPDATE app_settings SET setting_val='0'    WHERE setting_key='" . STOP_KEY   . "'");
        echo json_encode(['ok' => true, 'message' => 'Full reset done. All funds marked pending.']);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ════════════════════════════════════════════════════════════════
// TABLE
// ════════════════════════════════════════════════════════════════
if ($action === 'table') {
    try {
        $tab    = $_GET['tab']  ?? 'pending';
        $page   = max(1, (int)($_GET['page']   ?? 1));
        $search = trim($_GET['search'] ?? '');
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $whereMap = [
            'pending' => "p.status = 'pending'",
            'working' => "p.status = 'in_progress'",
            'errors'  => "p.status = 'error'",
            'done'    => "p.status IN ('completed','needs_update')",
        ];
        $where  = $whereMap[$tab] ?? "p.status='pending'";
        $params = [];

        if ($search !== '') {
            $like    = '%' . $search . '%';
            $where  .= " AND (f.scheme_code LIKE ? OR f.scheme_name LIKE ?)";
            $params  = [$like, $like];
        }

        $recSel  = $colRec     ? "p.{$colRec} AS records_saved"                        : "0 AS records_saved";
        $dateSel = $colDate    ? "DATE_FORMAT(p.{$colDate},'%d %b %y') AS last_dl"     : "NULL AS last_dl";
        $peakSel = $hasPeakCol ? "p.peak_calculated"                                   : "0 AS peak_calculated";
        $errSel  = $hasErrCol  ? "p.error_message"                                     : "NULL AS error_message";

        $cntSql = "SELECT COUNT(*) FROM nav_download_progress p LEFT JOIN funds f ON {$joinOn} WHERE {$where}";
        $cntStmt = $pdo->prepare($cntSql);
        $cntStmt->execute($params);
        $totalRows = (int)$cntStmt->fetchColumn();
        $pages     = max(1, (int)ceil($totalRows / $limit));

        $stmt = $pdo->prepare("
            SELECT {$scSelect},
                   COALESCE(f.scheme_name,'—') AS scheme_name,
                   COALESCE(f.category,'—')    AS category,
                   p.status,
                   {$recSel},
                   {$peakSel},
                   {$dateSel},
                   {$errSel},
                   DATE_FORMAT(p.updated_at,'%d %b %y %H:%i') AS updated_at
            FROM nav_download_progress p
            LEFT JOIN funds f ON {$joinOn}
            WHERE {$where}
            ORDER BY p.updated_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total_rows' => $totalRows]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage(), 'rows' => [], 'page' => 1, 'pages' => 1, 'total_rows' => 0]);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
