#!/usr/bin/env php
<?php
/**
 * WealthDash — NAV History Background Runner (CLI only)
 * 
 * Ye script INFINITE loop mein chalta hai jab tak sab funds complete na ho jaaye.
 * Browser ki zaroorat nahi — pure background PHP CLI process.
 *
 * Usage (Command Prompt):
 *   php nav_history_downloader/nav_cron_runner.php
 *   php nav_history_downloader/nav_cron_runner.php --parallel=16
 *   php nav_history_downloader/nav_cron_runner.php --parallel=8 --delay=100
 *
 * Windows Task Scheduler ke liye:
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\wealthdash\nav_history_downloader\nav_cron_runner.php
 *   Run whether user logged on or not: YES
 *   Run with highest privileges: YES
 */

set_time_limit(0);       // NO timeout — run forever
ini_set('memory_limit', '512M');

// ── Args ───────────────────────────────────────────────────────────────────
$argv      = $argv ?? [];
$parallel  = 8;   // default parallel requests
$delay     = 150; // ms between batches
$logToFile = true;

foreach ($argv as $arg) {
    if (preg_match('/--parallel=(\d+)/', $arg, $m)) $parallel = (int)$m[1];
    if (preg_match('/--delay=(\d+)/',    $arg, $m)) $delay    = (int)$m[1];
    if ($arg === '--no-log') $logToFile = false;
}
$parallel = max(1, min(50, $parallel));

// ── DB ─────────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'wealthdash');
define('MFAPI_BASE', 'https://api.mfapi.in/mf/');
define('API_TIMEOUT', 30);

try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_PERSISTENT => false]
    );
} catch (PDOException $e) {
    die('[FATAL] DB: ' . $e->getMessage() . "\n");
}

// ── Logger ─────────────────────────────────────────────────────────────────
$logFile = dirname(__DIR__) . '/logs/nav_cron_' . date('Y-m') . '.log';
@mkdir(dirname($logFile), 0755, true);

function lg(string $msg, bool $toFile = true): void {
    global $logFile, $logToFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    if ($toFile && $logToFile) {
        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

// ── Mark running ───────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO app_settings (setting_key, setting_val) VALUES ('nav_history_status','running')
            ON DUPLICATE KEY UPDATE setting_val='running'");
$pdo->exec("INSERT INTO app_settings (setting_key, setting_val) VALUES ('nav_history_last_run', NOW())
            ON DUPLICATE KEY UPDATE setting_val=NOW()");

lg("=== NAV History Background Runner ===");
lg("Parallel: {$parallel} | Delay: {$delay}ms | PID: " . getmypid());

// ── Get from_date ──────────────────────────────────────────────────────────
$fromDate = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_from_date'")->fetchColumn();
$fromDate = $fromDate ?: '1995-01-01';
$applyFilter = ($fromDate > '2000-01-01');
lg("From date: {$fromDate} | Filter: " . ($applyFilter ? 'YES' : 'NO (since inception)'));

// ── Prepared statements ─────────────────────────────────────────────────────
$insNav = $pdo->prepare("INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?,?,?)");
$markDone = $pdo->prepare("UPDATE nav_download_progress SET status='completed', last_downloaded_date=?, records_saved=records_saved+?, error_message=NULL WHERE scheme_code=?");
$markErr  = $pdo->prepare("UPDATE nav_download_progress SET status='error', error_message=? WHERE scheme_code=?");
$markInProg = null; // built per batch

// ── Total stats ────────────────────────────────────────────────────────────
$totalDone    = 0;
$totalRecords = 0;
$totalErrors  = 0;
$sessionStart = microtime(true);
$batchNum     = 0;

// ── MAIN INFINITE LOOP ─────────────────────────────────────────────────────
while (true) {

    // Check stop flag
    $status = $pdo->query("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_status'")->fetchColumn();
    if ($status === 'stop_requested') {
        lg("⛔ Stop requested. Exiting gracefully.");
        break;
    }

    // Fetch next batch of pending funds
    $batch = $pdo->query("
        SELECT scheme_code, fund_id, from_date
        FROM nav_download_progress
        WHERE status IN ('pending', 'error')
        ORDER BY CASE status WHEN 'pending' THEN 1 WHEN 'error' THEN 2 ELSE 3 END, scheme_code
        LIMIT {$parallel}
    ")->fetchAll();

    if (empty($batch)) {
        lg("✅ ALL FUNDS COMPLETE! No more pending funds.");
        $pdo->exec("UPDATE app_settings SET setting_val='idle' WHERE setting_key='nav_history_status'");
        break;
    }

    $batchNum++;
    $codes = array_column($batch, 'scheme_code');

    // Mark as in_progress
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $pdo->prepare("UPDATE nav_download_progress SET status='in_progress' WHERE scheme_code IN ({$ph})")->execute($codes);

    // Fetch all in parallel
    $results = fetchParallel($batch, API_TIMEOUT, $parallel);

    $failed = [];
    foreach ($batch as $row) {
        $sc     = $row['scheme_code'];
        $fundId = $row['fund_id'];
        $raw    = $results[$sc] ?? null;

        if (!$raw) { $failed[] = $row; continue; }

        $json = @json_decode($raw, true);
        if (empty($json['data'])) {
            // No data — mark completed 0 records
            $markDone->execute([date('Y-m-d'), 0, $sc]);
            $totalDone++;
            continue;
        }

        // Insert NAV records
        $saved = 0; $latest = null;
        $pdo->beginTransaction();
        try {
            foreach ($json['data'] as $entry) {
                $parts = explode('-', $entry['date']);
                if (count($parts) !== 3) continue;
                $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                if ($applyFilter && $isoDate < $fromDate) continue;
                $nav = (float)$entry['nav'];
                if ($nav <= 0) continue;
                if ($fundId) {
                    $insNav->execute([$fundId, $isoDate, $nav]);
                    if ($pdo->lastInsertId()) $saved++;
                }
                if (!$latest || $isoDate > $latest) $latest = $isoDate;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $failed[] = $row;
            continue;
        }

        $markDone->execute([$latest ?? date('Y-m-d'), $saved, $sc]);
        $totalDone++;
        $totalRecords += $saved;
    }

    // Retry failed once
    if (!empty($failed)) {
        usleep(800000); // 0.8s before retry
        $retryResults = fetchParallel($failed, API_TIMEOUT + 10, max(1, intdiv($parallel, 2)));
        foreach ($failed as $row) {
            $sc     = $row['scheme_code'];
            $fundId = $row['fund_id'];
            $raw    = $retryResults[$sc] ?? null;
            if (!$raw) {
                $markErr->execute(['API timeout after retry', $sc]);
                $totalErrors++;
                continue;
            }
            $json = @json_decode($raw, true);
            if (empty($json['data'])) { $markDone->execute([date('Y-m-d'), 0, $sc]); $totalDone++; continue; }
            $saved = 0; $latest = null;
            $pdo->beginTransaction();
            try {
                foreach ($json['data'] as $entry) {
                    $parts = explode('-', $entry['date']);
                    if (count($parts) !== 3) continue;
                    $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                    if ($applyFilter && $isoDate < $fromDate) continue;
                    $nav = (float)$entry['nav'];
                    if ($nav <= 0) continue;
                    if ($fundId) { $insNav->execute([$fundId, $isoDate, $nav]); if ($pdo->lastInsertId()) $saved++; }
                    if (!$latest || $isoDate > $latest) $latest = $isoDate;
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $markErr->execute([$e->getMessage(), $sc]);
                $totalErrors++;
                continue;
            }
            $markDone->execute([$latest ?? date('Y-m-d'), $saved, $sc]);
            $totalDone++; $totalRecords += $saved;
        }
    }

    // Update current batch info
    $pdo->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='nav_history_current_batch'")
        ->execute(["Batch #{$batchNum} | Done:{$totalDone} | Records:{$totalRecords} | Errors:{$totalErrors} | " . date('H:i:s')]);

    // Log every 100 batches
    if ($batchNum % 100 === 0) {
        $elapsed = round(microtime(true) - $sessionStart);
        $speed   = $totalDone > 0 ? round($totalDone / $elapsed, 1) : 0;
        $pending = $pdo->query("SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('pending','error')")->fetchColumn();
        $eta     = ($speed > 0 && $pending > 0) ? round($pending / $speed / 60) : '?';
        lg("Batch #{$batchNum} | Done:{$totalDone} | Records:{$totalRecords} | Errors:{$totalErrors} | Speed:{$speed}/s | ETA:{$eta}min | Pending:{$pending}");
    }

    // Small delay between batches to be polite to MFAPI
    if ($delay > 0) usleep($delay * 1000);
}

// ── Final summary ──────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $sessionStart);
lg("=== SESSION COMPLETE ===");
lg("Done:{$totalDone} | Records:{$totalRecords} | Errors:{$totalErrors} | Time:{$elapsed}s | Batches:{$batchNum}");

$pdo->exec("UPDATE app_settings SET setting_val='idle' WHERE setting_key='nav_history_status'");
$pdo->exec("UPDATE app_settings SET setting_val='' WHERE setting_key='nav_history_current_batch'");

// ── PARALLEL FETCH FUNCTION ─────────────────────────────────────────────────
function fetchParallel(array $batch, int $timeout, int $parallelSize): array {
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
