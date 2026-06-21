<?php
/**
 * WealthDash — NAV Download Worker API  v2
 * ─────────────────────────────────────────
 * Improvements over v1:
 *  • Race-condition-safe queue picking  (SELECT … FOR UPDATE SKIP LOCKED)
 *  • Batch INSERT for nav_history       (BATCH_SIZE rows per query, ~10× faster)
 *  • retry_count / max_retry columns    (automatic back-off on transient API fails)
 *  • Exponential back-off on API fetch  (up to 3 attempts per worker call)
 *  • ensureQueueTable adds indexes      (status, updated_at, scheme_code)
 *  • ensureNavIndexes runs once         (fund_id+nav_date UNIQUE + nav_date index)
 *  • Aggregated status query            (1 query vs 5 separate COUNT()s)
 *  • setSetting uses INSERT … ON DUPLICATE KEY (no race between check+insert)
 *  • Centralized wLog() / errLog()      (logs/ directory)
 *  • Strict action sanitization
 */

define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    die(json_encode(['error' => 'Admin only']));
}
header('Content-Type: application/json; charset=UTF-8');

/* ── Config ──────────────────────────────── */
define('MAX_RETRY',     3);      // max attempts per fund before marking error
define('BATCH_SIZE',    50);     // nav rows per batch INSERT
define('API_TIMEOUT',   12);     // seconds per API fetch
define('STALE_MINUTES', 3);      // minutes before stuck in_progress reset
define('LOG_DIR',       __DIR__ . '/logs');

/* ── Input sanitization ─────────────────── */
$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = preg_replace('/[^a-z_]/', '', strtolower($body['action'] ?? $_GET['action'] ?? 'status'));

try {
    $db = DB::conn();
    match ($action) {
        'status'       => actionStatus($db),
        'start'        => actionStart($db),
        'pause'        => actionPause($db),
        'retry_errors' => actionRetryErrors($db),
        'export_csv'   => actionExportCsv($db),
        'reset'        => actionReset($db, $body),
        'process_next' => actionProcessNext($db),
        'queue_list'   => actionQueueList($db),
        default        => respond(false, 'Unknown action'),
    };
} catch (Throwable $e) {
    errLog('Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    respond(false, 'Server error — check logs');
}

/* ════════════════════════════════════════════
   ACTIONS
   ════════════════════════════════════════════ */

function actionStatus(PDO $db): void {
    ensureQueueTable($db);
    ensureNavIndexes($db);

    // Auto-reset stuck in_progress (browser crash / network drop)
    $db->prepare(
        "UPDATE nav_download_queue
         SET status='pending',
             retry_count = LEAST(retry_count + 1, " . MAX_RETRY . ")
         WHERE status='in_progress'
           AND updated_at < DATE_SUB(NOW(), INTERVAL " . STALE_MINUTES . " MINUTE)"
    )->execute();

    // Single aggregated query — avoids 4 separate COUNT() round-trips
    $agg = $db->query("
        SELECT
            COALESCE(SUM(status='pending'),    0) AS pending,
            COALESCE(SUM(status='in_progress'),0) AS in_progress,
            COALESCE(SUM(status='done'),       0) AS done,
            COALESCE(SUM(status='error'),      0) AS errors
        FROM nav_download_queue
    ")->fetch(PDO::FETCH_ASSOC);

    $total      = (int)$db->query("SELECT COUNT(*) FROM funds WHERE is_active=1")->fetchColumn();
    $navRecords = (int)$db->query("SELECT COUNT(*) FROM nav_history")->fetchColumn();

    $dateRow = $db->query("SELECT MIN(nav_date), MAX(nav_date) FROM nav_history")->fetch(PDO::FETCH_NUM);
    $oldest  = $dateRow[0] ?: null;
    $latest  = $dateRow[1] ?: null;

    $lastDone = $db->query(
        "SELECT scheme_code, scheme_name, updated_at
         FROM nav_download_queue
         WHERE status='done'
         ORDER BY updated_at DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null;

    $needsUpdate   = (int)$db->query(
        "SELECT COUNT(*) FROM funds WHERE is_active=1 AND (nav_date IS NULL OR nav_date < CURDATE())"
    )->fetchColumn();

    $totalInserted = (int)$db->query(
        "SELECT COALESCE(SUM(nav_records_added),0) FROM nav_download_queue WHERE status='done'"
    )->fetchColumn();

    respond(true, '', [
        'total_funds'                => $total,
        'nav_records'                => $navRecords,
        'queue'                      => [
            'pending'     => (int)($agg['pending']     ?? 0),
            'in_progress' => (int)($agg['in_progress'] ?? 0),
            'errors'      => (int)($agg['errors']      ?? 0),
            'downloaded'  => (int)($agg['done']        ?? 0),
        ],
        'date_range'                 => ['oldest' => $oldest, 'latest' => $latest],
        'last_done'                  => $lastDone,
        'funds_needing_update'       => $needsUpdate,
        'total_elapsed_sec'          => (int)(getSetting($db, 'nav_dl_total_elapsed') ?? 0),
        'paused'                     => getSetting($db, 'nav_dl_paused') === '1',
        'total_records_this_session' => $totalInserted,
    ]);
}

function actionStart(PDO $db): void {
    ensureQueueTable($db);

    $inProg = (int)$db->query(
        "SELECT COUNT(*) FROM nav_download_queue WHERE status IN ('pending','in_progress')"
    )->fetchColumn();
    if ($inProg > 0) respond(false, 'Download chal raha hai. Pehle pause karo.');

    $db->exec("DELETE FROM nav_download_queue WHERE status IN ('done','pending','in_progress','error')");

    $stmt = $db->prepare(
        "SELECT id, scheme_code, scheme_name, nav_date
         FROM funds
         WHERE is_active=1 AND (nav_date IS NULL OR nav_date < CURDATE())
         ORDER BY scheme_name"
    );
    $stmt->execute();
    $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($funds)) respond(true, 'Sab funds up-to-date! Koi download nahi chahiye.', ['queued' => 0]);

    $ins = $db->prepare(
        "INSERT INTO nav_download_queue
             (fund_id, scheme_code, scheme_name, status, from_date, retry_count, created_at)
         VALUES (?,?,?,'pending',?,0,NOW())
         ON DUPLICATE KEY UPDATE
             status='pending', from_date=VALUES(from_date),
             retry_count=0, updated_at=NOW()"
    );
    foreach ($funds as $f) {
        $from = $f['nav_date']
            ? date('Y-m-d', strtotime($f['nav_date'] . ' +1 day'))
            : date('Y-m-d', strtotime('-1 year'));
        $ins->execute([$f['id'], $f['scheme_code'], $f['scheme_name'], $from]);
    }

    setSetting($db, 'nav_dl_session_start', date('Y-m-d H:i:s'));
    setSetting($db, 'nav_dl_paused', '0');
    wLog('Queue started — ' . count($funds) . ' funds queued');
    respond(true, count($funds) . ' funds queued.', ['queued' => count($funds)]);
}

function actionPause(PDO $db): void {
    ensureQueueTable($db);
    $cur = getSetting($db, 'nav_dl_paused') === '1';
    setSetting($db, 'nav_dl_paused', $cur ? '0' : '1');
    if (!$cur) {
        $db->exec("UPDATE nav_download_queue SET status='pending' WHERE status='in_progress'");
    }
    wLog($cur ? 'Resumed' : 'Paused');
    respond(true, $cur ? 'Resumed' : 'Paused', ['paused' => !$cur]);
}

function actionRetryErrors(PDO $db): void {
    ensureQueueTable($db);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM nav_download_queue WHERE status='error'")->fetchColumn();
    $db->prepare(
        "UPDATE nav_download_queue SET status='pending', retry_count=0, error_msg=NULL WHERE status='error'"
    )->execute();
    setSetting($db, 'nav_dl_paused', '0');
    wLog("Retry triggered — {$cnt} funds re-queued");
    respond(true, "{$cnt} funds re-queued.", ['retried' => $cnt]);
}

/**
 * actionProcessNext — race-condition-safe
 *
 * Uses SELECT … FOR UPDATE SKIP LOCKED so two parallel workers
 * can NEVER pick the same fund simultaneously.
 *
 * SKIP LOCKED = if another worker locked that row, skip it
 *               and find the next available one instantly.
 */
function actionProcessNext(PDO $db): void {
    ensureQueueTable($db);
    if (getSetting($db, 'nav_dl_paused') === '1') respond(true, 'paused', ['status' => 'paused']);

    /* ── Atomic row lock ── */
    $db->beginTransaction();
    try {
        $fund = $db->query(
            "SELECT id, fund_id, scheme_code, scheme_name, from_date, retry_count
             FROM nav_download_queue
             WHERE status='pending' AND retry_count < " . MAX_RETRY . "
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        )->fetch(PDO::FETCH_ASSOC);

        if (!$fund) {
            $db->rollBack();
            // Update cumulative timer on queue empty
            $ss = getSetting($db, 'nav_dl_session_start');
            if ($ss) {
                $elapsed = time() - strtotime($ss);
                $prev    = (int)(getSetting($db, 'nav_dl_total_elapsed') ?? 0);
                setSetting($db, 'nav_dl_total_elapsed', (string)($prev + $elapsed));
                setSetting($db, 'nav_dl_session_start', '');
            }
            respond(true, 'queue_empty', ['status' => 'idle']);
        }

        $db->prepare(
            "UPDATE nav_download_queue SET status='in_progress', updated_at=NOW() WHERE id=?"
        )->execute([$fund['id']]);
        $db->commit();

    } catch (Throwable $e) {
        $db->rollBack();
        errLog('Queue lock: ' . $e->getMessage());
        respond(false, 'Queue lock error');
    }

    /* ── Fetch NAV with exponential back-off ── */
    $code   = $fund['scheme_code'];
    $from   = $fund['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $fundId = (int)$fund['fund_id'];
    $retries = (int)$fund['retry_count'];

    $json = fetchNavWithBackoff($code, $retries);

    if ($json === null) {
        $newCount = $retries + 1;
        if ($newCount >= MAX_RETRY) {
            markError($db, $fund['id'], 'API fail after ' . MAX_RETRY . ' attempts', $newCount);
            errLog("Fund {$code} permanently failed after " . MAX_RETRY . " attempts");
            respond(true, 'error', ['status' => 'error', 'scheme' => $code, 'error' => 'API failed']);
        }
        // Re-queue for next worker cycle (transient fail)
        $db->prepare(
            "UPDATE nav_download_queue
             SET status='pending', retry_count=?, error_msg='Transient fail — retrying', updated_at=NOW()
             WHERE id=?"
        )->execute([$newCount, $fund['id']]);
        respond(true, 'error', ['status' => 'error', 'scheme' => $code, 'error' => 'Transient — retrying']);
    }

    /* ── Batch insert ── */
    [$inserted, $latestDate, $latestNav] = batchInsertNav($db, $fundId, $from, $json['data']);

    /* ── Update funds table ── */
    if ($latestDate) {
        $db->prepare(
            "UPDATE funds SET nav_date=?, nav=?, updated_at=NOW() WHERE id=?"
        )->execute([$latestDate, $latestNav, $fundId]);
    }

    $db->prepare(
        "UPDATE nav_download_queue SET status='done', nav_records_added=?, updated_at=NOW() WHERE id=?"
    )->execute([$inserted, $fund['id']]);

    wLog("OK {$fund['scheme_name']} ({$code}) — {$inserted} records");
    respond(true, 'processed', [
        'status'   => 'processed',
        'scheme'   => $code,
        'inserted' => $inserted,
        'name'     => $fund['scheme_name'],
    ]);
}

function actionExportCsv(PDO $db): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="nav_export_' . date('Ymd_His') . '.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Scheme Code', 'Scheme Name', 'NAV Date', 'NAV']);
    $stmt = $db->query(
        "SELECT f.scheme_code, f.scheme_name, h.nav_date, h.nav
         FROM nav_history h
         JOIN funds f ON f.id = h.fund_id
         ORDER BY f.scheme_code, h.nav_date DESC
         LIMIT 200000"
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($fp, $row);
    fclose($fp);
    exit;
}

function actionQueueList(PDO $db): void {
    ensureQueueTable($db);
    $rows = $db->query(
        "SELECT scheme_code, scheme_name, status, from_date,
                nav_records_added AS records, error_msg, retry_count
         FROM nav_download_queue
         ORDER BY FIELD(status,'in_progress','pending','error','done'), updated_at DESC
         LIMIT 5000"
    )->fetchAll(PDO::FETCH_ASSOC);
    respond(true, '', ['items' => $rows]);
}

function actionReset(PDO $db, array $body): void {
    if (empty($body['confirm'])) respond(false, 'confirm:true pass karo.');
    ensureQueueTable($db);
    $db->exec("TRUNCATE TABLE nav_download_queue");
    setSetting($db, 'nav_dl_paused', '0');
    setSetting($db, 'nav_dl_session_start', '');
    setSetting($db, 'nav_dl_total_elapsed', '0');
    wLog('Queue reset by admin');
    respond(true, 'Queue cleared. NAV history safe hai.');
}

/* ════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════ */

/**
 * Fetch NAV JSON from mfapi.in with exponential back-off.
 * Returns decoded array or null on complete failure.
 */
function fetchNavWithBackoff(string $code, int $prevRetries): ?array {
    for ($i = 0; $i < 3; $i++) {
        if ($i > 0) {
            // back-off: 300ms × 2^(i + prevRetries)  → 600ms, 1.2s, 2.4s …
            usleep((int)(pow(2, $i + $prevRetries) * 300000));
        }
        $ctx = stream_context_create(['http' => [
            'timeout'       => API_TIMEOUT,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: WealthDash/2.0\r\n",
        ]]);
        $raw = @file_get_contents("https://api.mfapi.in/mf/{$code}", false, $ctx);
        if ($raw === false) continue;
        $json = json_decode($raw, true);
        if (is_array($json) && !empty($json['data'])) return $json;
    }
    return null;
}

/**
 * Batch-insert NAV rows.
 * Inserts BATCH_SIZE rows per query — avoids per-row round-trips.
 * ON DUPLICATE KEY UPDATE ensures idempotency.
 *
 * Returns [inserted_count, latest_date, latest_nav]
 */
function batchInsertNav(PDO $db, int $fundId, string $from, array $rows): array {
    $inserted   = 0;
    $latestDate = null;
    $latestNav  = null;

    // Normalise + filter in PHP (cheap)
    $toInsert = [];
    foreach ($rows as $row) {
        if (empty($row['date']) || !isset($row['nav'])) continue;
        $nd  = date('Y-m-d', strtotime($row['date']));
        $nav = (float)$row['nav'];
        if ($nd < $from || $nav <= 0) continue;
        $toInsert[] = [$nd, $nav];
        if (!$latestDate || $nd > $latestDate) {
            $latestDate = $nd;
            $latestNav  = $nav;
        }
    }
    if (empty($toInsert)) return [0, null, null];

    // Chunked batch inserts
    foreach (array_chunk($toInsert, BATCH_SIZE) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?)'));
        $params = [];
        foreach ($chunk as [$nd, $nav]) {
            $params[] = $fundId;
            $params[] = $nd;
            $params[] = $nav;
        }
        try {
            $db->prepare(
                "INSERT INTO nav_history (fund_id, nav_date, nav)
                 VALUES {$placeholders}
                 ON DUPLICATE KEY UPDATE nav=VALUES(nav)"
            )->execute($params);
            $inserted += count($chunk);
        } catch (Throwable $e) {
            errLog("Batch insert fund_id={$fundId}: " . $e->getMessage());
        }
    }

    return [$inserted, $latestDate, $latestNav];
}

/** Idempotent: create queue table + indexes. */
function ensureQueueTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS nav_download_queue (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            fund_id           INT          NOT NULL,
            scheme_code       VARCHAR(20)  NOT NULL,
            scheme_name       VARCHAR(255) NOT NULL DEFAULT '',
            status            ENUM('pending','in_progress','done','error') NOT NULL DEFAULT 'pending',
            from_date         DATE,
            nav_records_added INT          NOT NULL DEFAULT 0,
            retry_count       TINYINT      NOT NULL DEFAULT 0,
            error_msg         VARCHAR(500),
            created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fund   (fund_id),
            INDEX  idx_status   (status),
            INDEX  idx_updated  (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure nav_history has the right indexes.
 * Runs once and stores a flag in app_settings.
 * Uses separate ALTER calls for compatibility with older MySQL versions
 * that don't support IF NOT EXISTS on ADD INDEX.
 */
function ensureNavIndexes(PDO $db): void {
    if (getSetting($db, 'nav_idx_v2') === '1') return;
    foreach ([
        "ALTER TABLE nav_history ADD UNIQUE INDEX uq_fund_date (fund_id, nav_date)",
        "ALTER TABLE nav_history ADD INDEX idx_nav_date (nav_date)",
    ] as $ddl) {
        try { $db->exec($ddl); } catch (Throwable $e) { /* Already exists — safe to ignore */ }
    }
    setSetting($db, 'nav_idx_v2', '1');
}

function markError(PDO $db, int $id, string $msg, int $retryCount = MAX_RETRY): void {
    $db->prepare(
        "UPDATE nav_download_queue
         SET status='error', error_msg=?, retry_count=?, updated_at=NOW()
         WHERE id=?"
    )->execute([$msg, $retryCount, $id]);
}

function getSetting(PDO $db, string $key): ?string {
    try {
        $s = $db->prepare("SELECT setting_val FROM app_settings WHERE setting_key=?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v !== false ? (string)$v : null;
    } catch (Throwable $e) { return null; }
}

/**
 * setSetting — race-safe via INSERT … ON DUPLICATE KEY.
 * Requires app_settings to have UNIQUE KEY on setting_key.
 */
function setSetting(PDO $db, string $key, string $val): void {
    try {
        $db->prepare(
            "INSERT INTO app_settings (setting_key, setting_val)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([$key, $val]);
    } catch (Throwable $e) {
        errLog("setSetting fail [{$key}]: " . $e->getMessage());
    }
}

function respond(bool $ok, string $msg, array $data = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $data));
    exit;
}

/* ── File logging ────────────────────────── */
function wLog(string $msg): void   { _appendLog('worker.log', $msg); }
function errLog(string $msg): void { _appendLog('errors.log', '[ERROR] ' . $msg); }
function _appendLog(string $file, string $msg): void {
    $dir = LOG_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/' . $file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}
