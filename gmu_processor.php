<?php
/**
 * WealthDash — GodMode Unified Processor
 * Path: wealthdash/gmu_processor.php
 *
 * Schema: nav_download_progress has
 *   id, fund_id, scheme_code, status, total_records,
 *   last_nav_date, error_message, updated_at, peak_calculated
 * Statuses: pending | in_progress | needs_update | completed | error
 *
 * Flow per fund:
 *   1. Fetch full NAV history from mfapi.in
 *   2. INSERT IGNORE into nav_history
 *   3. Calculate peak NAV inline
 *   4. UPDATE funds.highest_nav / highest_nav_date
 *   5. Mark status = 'completed', update total_records, peak_calculated=1
 */

define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'wealthdash');
define('EXEC_LIMIT', 110);
define('API_TIMEOUT',25);
define('MFAPI_BASE', 'https://api.mfapi.in/mf/');
define('STOP_KEY',   'gmu_stop');
define('STATUS_KEY', 'gmu_status');

$isCLI    = php_sapi_name() === 'cli';
$PARALLEL = $isCLI
    ? (isset($argv[1]) ? max(1, min(50, (int)$argv[1])) : 8)
    : max(1, min(50, (int)($_GET['parallel'] ?? 8)));

set_time_limit(0);
if (!$isCLI) {
    header('Content-Type: text/plain; charset=utf-8');
    if (ob_get_level()) ob_end_clean();
}

$runStart = time();

function lm(string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . "\n"; flush(); }
function overtime(): bool { global $runStart; return (time() - $runStart) >= EXEC_LIMIT; }

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) { die('[FATAL] DB: ' . $e->getMessage() . "\n"); }

lm('=== GodMode Unified Pipeline ===');
lm("Workers: {$PARALLEL}");

// ── Detect actual column names ───────────────────────────────────────────────
$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM nav_download_progress") as $c) $cols[] = $c['Field'];

$hasSchCode  = in_array('scheme_code',      $cols);
$hasPeakCol  = in_array('peak_calculated',  $cols);
$hasErrCol   = in_array('error_message',    $cols);
$colRec      = in_array('total_records',    $cols) ? 'total_records'
             : (in_array('records_saved',   $cols) ? 'records_saved' : null);
$colDate     = in_array('last_nav_date',    $cols) ? 'last_nav_date'
             : (in_array('last_downloaded_date', $cols) ? 'last_downloaded_date' : null);

// JOIN and scheme_code select
$joinOn   = $hasSchCode ? "f.scheme_code = p.scheme_code" : "f.id = p.fund_id";
$scSelect = $hasSchCode ? "p.scheme_code" : "f.scheme_code AS scheme_code";

lm("Columns detected — rec:{$colRec}, date:{$colDate}, peak:" . ($hasPeakCol?'yes':'no'));

// ── Add peak_calculated column if missing ────────────────────────────────────
if (!$hasPeakCol) {
    try {
        $pdo->exec("ALTER TABLE nav_download_progress ADD COLUMN peak_calculated TINYINT(1) DEFAULT 0");
        $hasPeakCol = true;
        lm("Added peak_calculated column.");
    } catch (Exception $e) { lm("Could not add peak_calculated: " . $e->getMessage()); }
}

// ── Clear flags ───────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('" . STATUS_KEY . "','running') ON DUPLICATE KEY UPDATE setting_val='running'");
$pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('" . STOP_KEY   . "','0')       ON DUPLICATE KEY UPDATE setting_val='0'");

// ── Reset stale in_progress ───────────────────────────────────────────────────
$pdo->exec("UPDATE nav_download_progress SET status='pending' WHERE status='in_progress' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");

$today = date('Y-m-d');

// ── Fetch actionable funds ────────────────────────────────────────────────────
// needs_update = downloaded before but stale; treat same as pending
$dateWhere = $colDate ? "OR (p.status='completed' AND p.{$colDate} < CURDATE())" : "";
$schemes = $pdo->query("
    SELECT p.fund_id,
           {$scSelect},
           " . ($colDate ? "p.{$colDate} AS last_date" : "NULL AS last_date") . ",
           " . ($colRec  ? "p.{$colRec}  AS saved_count" : "0 AS saved_count") . ",
           COALESCE(f.highest_nav, 0)  AS current_peak_nav,
           f.highest_nav_date          AS current_peak_date
    FROM nav_download_progress p
    INNER JOIN funds f ON {$joinOn}
    WHERE p.status IN ('pending','error','needs_update') {$dateWhere}
    ORDER BY CASE p.status WHEN 'pending' THEN 1 WHEN 'needs_update' THEN 2 WHEN 'error' THEN 3 ELSE 4 END, p.fund_id
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($schemes);
if ($total === 0) {
    lm("All funds already up to date.");
    $pdo->exec("UPDATE app_settings SET setting_val='idle' WHERE setting_key='" . STATUS_KEY . "'");
    echo "ALL_COMPLETE\n"; exit;
}
lm("Funds to process: {$total}");

// ── Prepared statements (built dynamically for schema) ─────────────────────
$stmtInsertNav = $pdo->prepare(
    "INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?, ?, ?)"
);

// Build UPDATE for done — uses actual column names
$doneSetParts = ["status='completed'", "updated_at=NOW()", "peak_calculated=1"];
if ($colRec)    $doneSetParts[] = "{$colRec}={$colRec}+?";  // increment
if ($colDate)   $doneSetParts[] = "{$colDate}=?";
if ($hasErrCol) $doneSetParts[] = "error_message=NULL";

// We'll use fund_id as the WHERE key (always exists)
$stmtMarkDone = $pdo->prepare(
    "UPDATE nav_download_progress SET " . implode(', ', $doneSetParts) . " WHERE fund_id=?"
);

$stmtMarkWork = $pdo->prepare("UPDATE nav_download_progress SET status='in_progress', updated_at=NOW() WHERE fund_id=?");
$errSet = $hasErrCol ? "status='error', error_message=?, updated_at=NOW()" : "status='error', updated_at=NOW()";
$stmtMarkError = $hasErrCol
    ? $pdo->prepare("UPDATE nav_download_progress SET {$errSet} WHERE fund_id=?")
    : $pdo->prepare("UPDATE nav_download_progress SET {$errSet} WHERE fund_id=?");

$stmtUpdatePeak = $pdo->prepare("UPDATE funds SET highest_nav=?, highest_nav_date=?, updated_at=NOW() WHERE id=?");
$stmtCheckStop  = $pdo->prepare("SELECT setting_val FROM app_settings WHERE setting_key='" . STOP_KEY . "'");

// ── Parallel fetch ────────────────────────────────────────────────────────────
function parallelFetch(array $batch, int $timeout, int $parallel): array {
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $parallel);
    $handles = [];
    foreach ($batch as $row) {
        $sc = $row['scheme_code'];
        $ch = curl_init(MFAPI_BASE . $sc);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,   CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'WealthDash-GMU/1.0',
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => 'gzip',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$sc] = $ch;
    }
    $running = null;
    do { $s = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); }
    while ($running > 0 && $s == CURLM_OK);
    $results = [];
    foreach ($handles as $sc => $ch) {
        $body = curl_multi_getcontent($ch); $err = curl_error($ch);
        $results[$sc] = ($err || !$body) ? null : $body;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

// ── Process one fund: NAV insert + Peak calc + single mark-done ───────────────
function processOne(
    array $row, ?string $raw, PDO $pdo, string $today,
    $stmtInsertNav, $stmtMarkDone, $stmtMarkError, $stmtUpdatePeak,
    bool $hasRecCol, bool $hasDateCol
): bool {
    $fundId = (int)$row['fund_id'];
    if ($raw === null) return false;

    $json = json_decode($raw, true);
    if (empty($json['data'])) {
        // No data — mark done with 0 increment
        $params = [];
        if ($hasRecCol)  $params[] = 0;
        if ($hasDateCol) $params[] = $today;
        $params[] = $fundId;
        $stmtMarkDone->execute($params);
        return true;
    }

    $lastDate    = $row['last_date']         ?? null;
    $peakNAV     = (float)($row['current_peak_nav']  ?? 0);
    $peakDate    = $row['current_peak_date']  ?? null;
    $inserted    = 0;

    $pdo->beginTransaction();
    try {
        foreach ($json['data'] as $entry) {
            $parts = explode('-', trim($entry['date'] ?? ''));
            if (count($parts) !== 3) continue;
            $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            if ($lastDate && $isoDate <= $lastDate) continue;
            $nav = (float)($entry['nav'] ?? 0);
            if ($nav <= 0) continue;

            $stmtInsertNav->execute([$fundId, $isoDate, $nav]);
            if ($stmtInsertNav->rowCount() > 0) $inserted++;

            if ($nav > $peakNAV) { $peakNAV = $nav; $peakDate = $isoDate; }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        if ($hasRecCol) $stmtMarkError->execute(['DB error: ' . $e->getMessage(), $fundId]);
        else            $stmtMarkError->execute([$fundId]);
        return false;
    }

    // Update peak in funds table
    if ($peakNAV > (float)($row['current_peak_nav'] ?? 0) && $peakNAV > 0) {
        $stmtUpdatePeak->execute([$peakNAV, $peakDate, $fundId]);
    }

    // Mark done — build params matching prepared statement
    $params = [];
    if ($hasRecCol)  $params[] = $inserted;
    if ($hasDateCol) $params[] = $today;
    $params[] = $fundId;
    $stmtMarkDone->execute($params);
    return true;
}

// ── Main loop ─────────────────────────────────────────────────────────────────
$done   = 0;
$errs   = 0;
$chunks = array_chunk($schemes, $PARALLEL);

foreach ($chunks as $idx => $chunk) {

    $stmtCheckStop->execute();
    if ($stmtCheckStop->fetchColumn() === '1') {
        $ids = array_column($chunk, 'fund_id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending', updated_at=NOW() WHERE fund_id IN ({$ph}) AND status='in_progress'")->execute($ids);
        $pdo->exec("UPDATE app_settings SET setting_val='0'    WHERE setting_key='" . STOP_KEY  . "'");
        $pdo->exec("UPDATE app_settings SET setting_val='idle' WHERE setting_key='" . STATUS_KEY . "'");
        lm("⛔ Stopped at chunk #{$idx}. Resume by clicking Start.");
        echo "STOPPED\n"; exit;
    }

    if (overtime()) {
        $ids = array_column($chunk, 'fund_id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending', updated_at=NOW() WHERE fund_id IN ({$ph}) AND status='in_progress'")->execute($ids);
        lm("⏱ Time limit at chunk #{$idx}. System will continue on next poll.");
        break;
    }

    foreach ($chunk as $row) $stmtMarkWork->execute([$row['fund_id']]);

    $results = parallelFetch($chunk, API_TIMEOUT, $PARALLEL);
    $failed  = [];

    foreach ($chunk as $row) {
        $raw = $results[$row['scheme_code']] ?? null;
        if (processOne($row, $raw, $pdo, $today, $stmtInsertNav, $stmtMarkDone, $stmtMarkError, $stmtUpdatePeak, (bool)$colRec, (bool)$colDate)) {
            $done++;
        } else {
            $failed[] = $row;
        }
    }

    if (!empty($failed)) {
        usleep(400000);
        $retry = parallelFetch($failed, API_TIMEOUT + 10, max(1, intdiv($PARALLEL, 2)));
        foreach ($failed as $row) {
            $raw = $retry[$row['scheme_code']] ?? null;
            if (processOne($row, $raw, $pdo, $today, $stmtInsertNav, $stmtMarkDone, $stmtMarkError, $stmtUpdatePeak, (bool)$colRec, (bool)$colDate)) {
                $done++;
            } else {
                if ($hasErrCol) $stmtMarkError->execute(['API timeout after retry', $row['fund_id']]);
                else            $stmtMarkError->execute([$row['fund_id']]);
                $errs++;
            }
        }
    }

    if (($idx + 1) % 10 === 0) {
        $e = time() - $runStart;
        lm("Done:{$done} | Errors:{$errs} | " . round($done/max(1,$e),1) . " funds/s");
    }
}

$elapsed   = time() - $runStart;
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('pending','error','in_progress','needs_update')")->fetchColumn();
$totalRecs = (int)($pdo->query("SELECT COUNT(*) FROM nav_history")->fetchColumn());

lm("--- Done:{$done} | Errors:{$errs} | Time:{$elapsed}s | NAV records:" . number_format($totalRecs));
$pdo->exec("UPDATE app_settings SET setting_val='idle' WHERE setting_key='" . STATUS_KEY . "'");
if ($remaining === 0) {
    $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('gmu_last_completed',NOW()) ON DUPLICATE KEY UPDATE setting_val=NOW()");
    echo "ALL_COMPLETE\n";
} else {
    echo "TIME_LIMIT\n";
}
$pdo = null;
