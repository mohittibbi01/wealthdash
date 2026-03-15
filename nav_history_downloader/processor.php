<?php
/**
 * WealthDash — NAV History Processor
 * Path: wealthdash/nav_history_downloader/processor.php
 *
 * Fetches historical NAV from mfapi.in for all funds
 * Saves to nav_history table
 * Called via XHR from status.php — runs in background
 */

define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'wealthdash');
define('EXEC_LIMIT',  85);
define('API_TIMEOUT', 25);
define('MFAPI_BASE',  'https://api.mfapi.in/mf/');

$PARALLEL_SIZE = isset($_GET['parallel']) ? (int)$_GET['parallel'] : 8;
$PARALLEL_SIZE = max(1, min(50, $PARALLEL_SIZE));

set_time_limit(180);
header('Content-Type: text/plain; charset=utf-8');
if (ob_get_level()) ob_end_clean();

$runStart = time();

function lm(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    flush();
}

function overtime(): bool {
    global $runStart;
    return (time() - $runStart) >= EXEC_LIMIT;
}

// ── DB ──────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('[FATAL] DB: ' . $e->getMessage() . "\n");
}

lm('=== WealthDash NAV History Processor ===');
lm('Parallel: ' . $PARALLEL_SIZE);

// Get admin-set from_date
$fromDate = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_from_date'")->fetchColumn();
$fromDate = $fromDate ?: '2025-01-01';
lm("Downloading NAV history from: {$fromDate}");

// Mark running
$pdo->prepare("UPDATE app_settings SET setting_val='running', setting_val=NOW() WHERE setting_key='nav_history_last_run'")->execute();
$pdo->prepare("UPDATE app_settings SET setting_val='running' WHERE setting_key='nav_history_status'")->execute();
$pdo->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='nav_history_last_run'")->execute([date('Y-m-d H:i:s')]);

// Fetch pending schemes
$schemes = $pdo->query("
    SELECT scheme_code, fund_id, from_date
    FROM nav_download_progress
    WHERE status IN ('pending', 'error')
    ORDER BY
        CASE status WHEN 'pending' THEN 1 WHEN 'error' THEN 2 ELSE 3 END,
        scheme_code
")->fetchAll();

$total = count($schemes);
if ($total === 0) {
    lm("All funds already downloaded.");
    $pdo->prepare("UPDATE app_settings SET setting_val='idle' WHERE setting_key='nav_history_status'")->execute();
    echo "ALL_COMPLETE\n";
    exit;
}

lm("Funds to process: {$total}");
$done = 0;
$errs = 0;
$records = 0;

// ── PARALLEL FETCH ──────────────────────────────────────────────
function parallelFetch(array $batch, int $timeout, int $parallelSize): array {
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $parallelSize);
    $handles = [];

    foreach ($batch as $row) {
        $ch = curl_init(MFAPI_BASE . $row['scheme_code']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'WealthDash-NavHistory/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => 'gzip',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$row['scheme_code']] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running > 0 && $status == CURLM_OK);

    $results = [];
    foreach ($handles as $sc => $ch) {
        $body = curl_multi_getcontent($ch);
        $err  = curl_error($ch);
        $results[$sc] = ($err || !$body) ? null : $body;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

// ── PROCESS ONE FUND ────────────────────────────────────────────
function processOne(array $row, ?string $raw, PDO $pdo, string $globalFromDate): array {
    $sc       = $row['scheme_code'];
    $fundId   = $row['fund_id'];
    // Use fund's specific from_date, fallback to global
    $fromDate = $row['from_date'] ?: $globalFromDate;

    if ($raw === null) {
        return ['success' => false, 'error' => 'API timeout / no response', 'records' => 0];
    }

    $json = @json_decode($raw, true);
    if (empty($json['data'])) {
        // Fund has no data — mark completed with 0 records
        $pdo->prepare("
            UPDATE nav_download_progress
            SET status='completed', last_downloaded_date=CURDATE(), records_saved=0, error_message=NULL
            WHERE scheme_code=?
        ")->execute([$sc]);
        return ['success' => true, 'records' => 0];
    }

    // Filter by from_date and insert into nav_history
    $insStmt = $pdo->prepare("
        INSERT IGNORE INTO nav_history (fund_id, nav_date, nav)
        VALUES (?, ?, ?)
    ");

    $savedCount  = 0;
    $latestDate  = null;
    $earliestDate = null;

    // mfapi returns newest first — process all
    foreach ($json['data'] as $entry) {
        // Parse date DD-MM-YYYY → YYYY-MM-DD
        $parts = explode('-', $entry['date']);
        if (count($parts) !== 3) continue;
        $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";

        // Skip dates before from_date
        if ($isoDate < $fromDate) continue;

        $nav = (float)$entry['nav'];
        if ($nav <= 0) continue;

        if ($fundId) {
            $insStmt->execute([$fundId, $isoDate, $nav]);
            if ($pdo->lastInsertId()) $savedCount++;
        }

        if (!$latestDate  || $isoDate > $latestDate)  $latestDate  = $isoDate;
        if (!$earliestDate || $isoDate < $earliestDate) $earliestDate = $isoDate;
    }

    // Update progress
    $pdo->prepare("
        UPDATE nav_download_progress
        SET status='completed',
            last_downloaded_date=?,
            records_saved=records_saved+?,
            error_message=NULL
        WHERE scheme_code=?
    ")->execute([$latestDate ?? date('Y-m-d'), $savedCount, $sc]);

    return ['success' => true, 'records' => $savedCount, 'from' => $earliestDate, 'to' => $latestDate];
}

// ── MAIN LOOP ───────────────────────────────────────────────────
$chunks   = array_chunk($schemes, $PARALLEL_SIZE);
$updateCurrent = $pdo->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='nav_history_current_batch'");
$checkStop = $pdo->prepare("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_status'");

foreach ($chunks as $chunkIdx => $chunk) {

    // ── Check stop flag ──────────────────────────────
    $checkStop->execute();
    $currentStatus = $checkStop->fetchColumn();
    if ($currentStatus === 'stop_requested') {
        $codes = array_column($chunk, 'scheme_code');
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending' WHERE scheme_code IN ({$ph}) AND status='in_progress'")->execute($codes);
        $updateCurrent->execute(['']);
        lm("⛔ Stop requested by admin. Stopped at chunk #{$chunkIdx}. Progress saved — run again to continue.");
        $pdo->prepare("UPDATE app_settings SET setting_val='idle' WHERE setting_key='nav_history_status'")->execute();
        echo "STOPPED\n";
        exit;
    }

    if (overtime()) {
        // Revert in_progress → pending
        $codes = array_column($chunk, 'scheme_code');
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending' WHERE scheme_code IN ({$ph}) AND status='in_progress'")->execute($codes);
        lm("Time limit reached at chunk #{$chunkIdx}. Run again to continue.");
        $updateCurrent->execute(['']);
        break;
    }

    // Mark as in_progress
    $codes = array_column($chunk, 'scheme_code');
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $pdo->prepare("UPDATE nav_download_progress SET status='in_progress' WHERE scheme_code IN ({$ph})")->execute($codes);

    // Save currently processing batch info for live display
    $batchInfo = implode(', ', array_slice($codes, 0, 3)) . (count($codes) > 3 ? '...' : '');
    $updateCurrent->execute(["Batch #".($chunkIdx+1)." | {$batchInfo} | ".date('H:i:s')]);

    // Parallel fetch
    $results = parallelFetch($chunk, API_TIMEOUT, $PARALLEL_SIZE);

    $failed = [];
    foreach ($chunk as $row) {
        $raw = $results[$row['scheme_code']] ?? null;
        $res = processOne($row, $raw, $pdo, $fromDate);
        if ($res['success']) {
            $done++;
            $records += $res['records'];
        } else {
            $failed[] = $row;
        }
    }

    // Retry failed
    if (!empty($failed)) {
        usleep(500000);
        $retryResults = parallelFetch($failed, API_TIMEOUT + 15, max(1, intdiv($PARALLEL_SIZE, 2)));
        foreach ($failed as $row) {
            $raw = $retryResults[$row['scheme_code']] ?? null;
            $res = processOne($row, $raw, $pdo, $fromDate);
            if ($res['success']) {
                $done++;
                $records += $res['records'];
            } else {
                $pdo->prepare("
                    UPDATE nav_download_progress
                    SET status='error', error_message='API timeout after retry'
                    WHERE scheme_code=?
                ")->execute([$row['scheme_code']]);
                $errs++;
            }
        }
    }

    if (($chunkIdx + 1) % 5 === 0) {
        $elapsed = time() - $runStart;
        $speed   = $elapsed > 0 ? round($done / $elapsed, 1) : 0;
        lm("Progress | Done: {$done} | Records: {$records} | Errors: {$errs} | {$speed}/s");
    }
}

$elapsed   = time() - $runStart;
$remaining = $pdo->query("SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('pending','error','in_progress')")->fetchColumn();

lm('---');
lm("Done: {$done} | Records saved: {$records} | Errors: {$errs} | Time: {$elapsed}s");
lm("Remaining: {$remaining}");

$pdo->prepare("UPDATE app_settings SET setting_val='idle' WHERE setting_key='nav_history_status'")->execute();

if ((int)$remaining === 0) {
    echo "ALL_COMPLETE\n";
} else {
    echo "TIME_LIMIT\n";
}

$pdo = null;