<?php
/**
 * WealthDash — Full NAV History Processor
 * Path: wealthdash/nav_download/processor.php
 *
 * MFAPI.in se saare funds ki complete NAV history fetch karta hai
 * since inception tak — incremental, parallel, resumable
 */

define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'wealthdash');
define('EXEC_LIMIT',  110);   // 110s — PHP hard limit se pehle stop
define('API_TIMEOUT', 25);
define('MFAPI_BASE',  'https://api.mfapi.in/mf/');

$PARALLEL = isset($_GET['parallel']) ? max(1, min(50, (int)$_GET['parallel'])) : 8;

set_time_limit(240);
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

// ── DB ──────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('[FATAL] DB: ' . $e->getMessage() . "\n");
}

lm('=== WealthDash Full NAV History Processor ===');
lm("Parallel: {$PARALLEL}");

// ── Auto-seed agar progress table empty hai ─────────────────────────────────
$totalSeeded = (int)$pdo->query("SELECT COUNT(*) FROM nav_download_progress")->fetchColumn();
if ($totalSeeded === 0) {
    lm("nav_download_progress empty — seeding from funds table...");
    $inserted = $pdo->exec("
        INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status)
        SELECT scheme_code, id, 'pending' FROM funds
    ");
    lm("Seeded {$inserted} funds.");
}

// ── Clear stop flag ──────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('nav_dl_stop','0') ON DUPLICATE KEY UPDATE setting_val='0'");

$today = date('Y-m-d');

// ── Fetch pending/error funds ─────────────────────────────────────────────────
$schemes = $pdo->query("
    SELECT
        p.scheme_code,
        p.fund_id,
        p.last_downloaded_date,
        p.records_saved
    FROM nav_download_progress p
    WHERE p.status IN ('pending', 'error')
       OR (p.status = 'completed' AND p.last_downloaded_date < CURDATE())
    ORDER BY
        CASE p.status
            WHEN 'pending'   THEN 1
            WHEN 'error'     THEN 2
            ELSE 3
        END,
        p.scheme_code
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($schemes);

if ($total === 0) {
    lm("Sab funds already up-to-date. Kuch kaam nahi.");
    echo "ALL_COMPLETE\n";
    exit;
}

lm("Funds to process: {$total}");

$done = 0;
$errs = 0;

// ── Prepared statements ───────────────────────────────────────────────────────
$stmtNavInsert = $pdo->prepare("
    INSERT IGNORE INTO nav_history (fund_id, nav_date, nav)
    VALUES (?, ?, ?)
");
$stmtProgress = $pdo->prepare("
    UPDATE nav_download_progress
    SET status='completed', last_downloaded_date=?, records_saved=records_saved+?, error_message=NULL, updated_at=NOW()
    WHERE scheme_code=?
");
$stmtError = $pdo->prepare("
    UPDATE nav_download_progress
    SET status='error', error_message=?, updated_at=NOW()
    WHERE scheme_code=?
");
$stmtInProgress = $pdo->prepare("
    UPDATE nav_download_progress SET status='in_progress', updated_at=NOW()
    WHERE scheme_code=?
");
$stmtCheckStop = $pdo->prepare("
    SELECT setting_val FROM app_settings WHERE setting_key='nav_dl_stop'
");

// ── Parallel fetch ────────────────────────────────────────────────────────────
function parallelFetch(array $batch, int $timeout, int $parallel): array {
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $parallel);
    $handles = [];

    foreach ($batch as $row) {
        $ch = curl_init(MFAPI_BASE . $row['scheme_code']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'WealthDash/2.0',
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

// ── Process one fund result ───────────────────────────────────────────────────
function processOne(array $row, ?string $raw, PDO $pdo, string $today,
    $stmtNavInsert, $stmtProgress, $stmtError): bool {

    $sc     = $row['scheme_code'];
    $fundId = (int)$row['fund_id'];

    if ($raw === null) return false;

    $json = json_decode($raw, true);
    if (!isset($json['data']) || !is_array($json['data'])) {
        // Fund exists in AMFI but MFAPI has no data — mark completed
        $stmtProgress->execute([$today, 0, $sc]);
        return true;
    }

    $lastProcessed = $row['last_downloaded_date']; // only fetch newer than this
    $inserted      = 0;

    $pdo->beginTransaction();
    try {
        foreach ($json['data'] as $entry) {
            // Parse DD-MM-YYYY → YYYY-MM-DD
            $parts = explode('-', $entry['date'] ?? '');
            if (count($parts) !== 3) continue;
            $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";

            // Incremental: skip already downloaded dates
            if ($lastProcessed && $isoDate <= $lastProcessed) continue;

            $nav = (float)($entry['nav'] ?? 0);
            if ($nav <= 0) continue;

            $stmtNavInsert->execute([$fundId, $isoDate, $nav]);
            if ($stmtNavInsert->rowCount() > 0) $inserted++;
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $stmtError->execute(['DB error: ' . $e->getMessage(), $sc]);
        return false;
    }

    $stmtProgress->execute([$today, $inserted, $sc]);
    return true;
}

// ── Main loop ─────────────────────────────────────────────────────────────────
$chunks = array_chunk($schemes, $PARALLEL);

foreach ($chunks as $chunkIdx => $chunk) {

    // Check stop flag
    $stmtCheckStop->execute();
    $stopVal = $stmtCheckStop->fetchColumn();
    if ($stopVal === '1') {
        // Revert in_progress → pending
        $codes = array_column($chunk, 'scheme_code');
        $ph    = implode(',', array_fill(0, count($codes), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending', updated_at=NOW() WHERE scheme_code IN ({$ph}) AND status='in_progress'")->execute($codes);
        $pdo->exec("UPDATE app_settings SET setting_val='0' WHERE setting_key='nav_dl_stop'");
        lm("⛔ Stop requested at chunk #{$chunkIdx}. Resume karega jahaan se ruka tha.");
        echo "STOPPED\n";
        exit;
    }

    if (overtime()) {
        $codes = array_column($chunk, 'scheme_code');
        $ph    = implode(',', array_fill(0, count($codes), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending', updated_at=NOW() WHERE scheme_code IN ({$ph}) AND status='in_progress'")->execute($codes);
        lm("⏱ Time limit reached at chunk #{$chunkIdx}. Dobara run karo — wahi se continue hoga.");
        break;
    }

    // Mark as in_progress
    foreach ($chunk as $row) {
        $stmtInProgress->execute([$row['scheme_code']]);
    }

    // First attempt
    $results = parallelFetch($chunk, API_TIMEOUT, $PARALLEL);
    $failed  = [];

    foreach ($chunk as $row) {
        $raw = $results[$row['scheme_code']] ?? null;
        if (processOne($row, $raw, $pdo, $today, $stmtNavInsert, $stmtProgress, $stmtError)) {
            $done++;
        } else {
            $failed[] = $row;
        }
    }

    // Retry failed
    if (!empty($failed)) {
        usleep(500000); // 0.5s pause
        $retry = parallelFetch($failed, API_TIMEOUT + 10, max(1, intdiv($PARALLEL, 2)));
        foreach ($failed as $row) {
            $raw = $retry[$row['scheme_code']] ?? null;
            if (processOne($row, $raw, $pdo, $today, $stmtNavInsert, $stmtProgress, $stmtError)) {
                $done++;
            } else {
                $stmtError->execute(['API timeout after retry', $row['scheme_code']]);
                $errs++;
            }
        }
    }

    // Progress log every 10 chunks
    if (($chunkIdx + 1) % 10 === 0) {
        $elapsed = time() - $runStart;
        $rate    = round($done / max(1, $elapsed), 1);
        lm("Progress | Done: {$done} | Errors: {$errs} | {$rate} funds/s");
    }
}

$elapsed   = time() - $runStart;
$remaining = (int)$pdo->query("SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('pending','error','in_progress')")->fetchColumn();
$totalRecs = (int)$pdo->query("SELECT SUM(records_saved) FROM nav_download_progress")->fetchColumn();

lm('---');
lm("Done: {$done} | Errors: {$errs} | Time: {$elapsed}s");
lm("Total NAV records saved (all time): " . number_format($totalRecs));
lm("Remaining: {$remaining}");

if ($remaining === 0) {
    echo "ALL_COMPLETE\n";
} else {
    echo "TIME_LIMIT\n";
}

$pdo = null;
