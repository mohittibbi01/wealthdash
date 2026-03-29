<?php
/**
 * WealthDash — Full NAV History Processor
 * Path: wealthdash/nav_download/processor.php
 *
 * FIX: fund_id NULL issue — scheme_code se fund_id live lookup
 * FIX: records_saved properly track hota hai
 */

define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'wealthdash');
define('EXEC_LIMIT',  110);
define('API_TIMEOUT', 25);
define('MFAPI_BASE',  'https://api.mfapi.in/mf/');

// CLI (background) se bhi chale, browser se bhi
$isCLI = php_sapi_name() === 'cli';
if ($isCLI) {
    $PARALLEL = isset($argv[1]) ? max(1, min(50, (int)$argv[1])) : 8;
} else {
    $PARALLEL = isset($_GET['parallel']) ? max(1, min(50, (int)$_GET['parallel'])) : 8;
}

set_time_limit(0); // CLI mein unlimited time
if (!$isCLI) {
    header('Content-Type: text/plain; charset=utf-8');
    if (ob_get_level()) ob_end_clean();
}

$runStart = time();

function lm(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    flush();
}
function overtime(): bool {
    global $runStart;
    return (time() - $runStart) >= EXEC_LIMIT;
}

// DB
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

// Step 1: Insert missing + FIX fund_id NULL
$pdo->exec("
    INSERT IGNORE INTO nav_download_progress (scheme_code, fund_id, status)
    SELECT scheme_code, id, 'pending' FROM funds
");
$fixed = $pdo->exec("
    UPDATE nav_download_progress p
    JOIN funds f ON f.scheme_code = p.scheme_code
    SET p.fund_id = f.id
    WHERE p.fund_id IS NULL OR p.fund_id = 0
");
if ($fixed > 0) lm("Fixed fund_id for {$fixed} rows.");

// Step 2: Clear stop flag
$pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('nav_dl_stop','0') ON DUPLICATE KEY UPDATE setting_val='0'");

$today = date('Y-m-d');

// Step 3: Fetch pending funds — JOIN funds to get confirmed fund_id
$schemes = $pdo->query("
    SELECT
        p.scheme_code,
        f.id        AS fund_id,
        p.last_downloaded_date,
        p.records_saved
    FROM nav_download_progress p
    INNER JOIN funds f ON f.scheme_code = p.scheme_code
    WHERE p.status IN ('pending', 'error')
       OR (p.status = 'completed' AND p.last_downloaded_date < CURDATE())
    ORDER BY
        CASE p.status WHEN 'pending' THEN 1 WHEN 'error' THEN 2 ELSE 3 END,
        p.scheme_code
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($schemes);
if ($total === 0) {
    lm("Sab funds already up-to-date.");
    echo "ALL_COMPLETE\n"; exit;
}
lm("Funds to process: {$total}");

$done = 0;
$errs = 0;

$stmtInsert  = $pdo->prepare("INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?, ?, ?)");
$stmtDone    = $pdo->prepare("UPDATE nav_download_progress SET status='completed', last_downloaded_date=?, from_date=COALESCE(from_date,?), records_saved=records_saved+?, error_message=NULL, updated_at=NOW() WHERE scheme_code=?");
$stmtWorking = $pdo->prepare("UPDATE nav_download_progress SET status='in_progress', updated_at=NOW() WHERE scheme_code=?");
$stmtError   = $pdo->prepare("UPDATE nav_download_progress SET status='error', error_message=?, updated_at=NOW() WHERE scheme_code=?");
$stmtStop    = $pdo->prepare("SELECT setting_val FROM app_settings WHERE setting_key='nav_dl_stop'");

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
        $s = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running > 0 && $s == CURLM_OK);
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

function processOne(array $row, ?string $raw, PDO $pdo, string $today, $stmtInsert, $stmtDone, $stmtError): bool {
    $sc     = $row['scheme_code'];
    $fundId = (int)$row['fund_id'];

    if ($raw === null) return false;

    $json = json_decode($raw, true);
    if (empty($json['data'])) {
        $stmtDone->execute([$today, $today, 0, $sc]);
        return true;
    }

    $lastProcessed = $row['last_downloaded_date'];
    $inserted = 0;
    $minDate  = null; // from_date ke liye

    $pdo->beginTransaction();
    try {
        foreach ($json['data'] as $entry) {
            $p = explode('-', trim($entry['date'] ?? ''));
            if (count($p) !== 3) continue;
            $isoDate = "{$p[2]}-{$p[1]}-{$p[0]}";
            if ($lastProcessed && $isoDate <= $lastProcessed) continue;
            $nav = (float)($entry['nav'] ?? 0);
            if ($nav <= 0) continue;
            $stmtInsert->execute([$fundId, $isoDate, $nav]);
            if ($stmtInsert->rowCount() > 0) {
                $inserted++;
                // Minimum date track karo (from_date ke liye)
                if ($minDate === null || $isoDate < $minDate) $minDate = $isoDate;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $stmtError->execute(['DB error: ' . $e->getMessage(), $sc]);
        return false;
    }

    // from_date = pehla available date (min date)
    $fromDate = $minDate ?? $row['last_downloaded_date'] ?? $today;
    $stmtDone->execute([$today, $fromDate, $inserted, $sc]);
    return true;
}

$chunks = array_chunk($schemes, $PARALLEL);

foreach ($chunks as $idx => $chunk) {

    $stmtStop->execute();
    if ($stmtStop->fetchColumn() === '1') {
        $codes = array_column($chunk, 'scheme_code');
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending' WHERE scheme_code IN ({$ph}) AND status='in_progress'")->execute($codes);
        $pdo->exec("UPDATE app_settings SET setting_val='0' WHERE setting_key='nav_dl_stop'");
        lm("⛔ Stopped at chunk #{$idx}.");
        echo "STOPPED\n"; exit;
    }

    if (overtime()) {
        $codes = array_column($chunk, 'scheme_code');
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $pdo->prepare("UPDATE nav_download_progress SET status='pending' WHERE scheme_code IN ({$ph}) AND status='in_progress'")->execute($codes);
        lm("⏱ Time limit at chunk #{$idx}.");
        break;
    }

    foreach ($chunk as $row) $stmtWorking->execute([$row['scheme_code']]);

    $results = parallelFetch($chunk, API_TIMEOUT, $PARALLEL);
    $failed  = [];

    foreach ($chunk as $row) {
        $raw = $results[$row['scheme_code']] ?? null;
        if (processOne($row, $raw, $pdo, $today, $stmtInsert, $stmtDone, $stmtError)) $done++;
        else $failed[] = $row;
    }

    if (!empty($failed)) {
        usleep(500000);
        $retry = parallelFetch($failed, API_TIMEOUT + 10, max(1, intdiv($PARALLEL, 2)));
        foreach ($failed as $row) {
            $raw = $retry[$row['scheme_code']] ?? null;
            if (processOne($row, $raw, $pdo, $today, $stmtInsert, $stmtDone, $stmtError)) $done++;
            else { $stmtError->execute(['Timeout after retry', $row['scheme_code']]); $errs++; }
        }
    }

    if (($idx + 1) % 10 === 0) {
        $e = time() - $runStart;
        lm("Done: {$done} | Errors: {$errs} | " . round($done/max(1,$e),1) . " funds/s");
    }
}

$remaining = (int)$pdo->query("SELECT COUNT(*) FROM nav_download_progress WHERE status IN ('pending','error','in_progress')")->fetchColumn();
$totalRecs = (int)$pdo->query("SELECT SUM(records_saved) FROM nav_download_progress")->fetchColumn();

lm("--- Done: {$done} | Errors: {$errs} | Time: ".(time()-$runStart)."s");
lm("Total NAV records: " . number_format($totalRecs));
lm("Remaining: {$remaining}");

echo $remaining === 0 ? "ALL_COMPLETE\n" : "TIME_LIMIT\n";
$pdo = null;