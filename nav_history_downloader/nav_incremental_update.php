#!/usr/bin/env php
<?php
/**
 * WealthDash — NAV Incremental Update (Daily Cron)
 * 
 * Kaam: Jo funds already download ho chuke hain, unke latest NAV fetch karo
 * from: last_downloaded_date ke baad se aaj tak
 *
 * Usage:
 *   php nav_history_downloader/nav_incremental_update.php
 *   php nav_history_downloader/nav_incremental_update.php --days=10  (force last 10 days)
 *   php nav_history_downloader/nav_incremental_update.php --all      (all funds, not just completed)
 *
 * Windows Task Scheduler:
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\wealthdash\nav_history_downloader\nav_incremental_update.php
 *   Schedule: Daily at 8:00 PM (after market close)
 */

set_time_limit(0);
ini_set('memory_limit', '256M');

define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'wealthdash');
define('MFAPI_BASE', 'https://api.mfapi.in/mf/');
define('PARALLEL',   16);

$argv    = $argv ?? [];
$forceDays = 0;
$allFunds  = false;
foreach ($argv as $arg) {
    if (preg_match('/--days=(\d+)/', $arg, $m)) $forceDays = (int)$m[1];
    if ($arg === '--all') $allFunds = true;
}

// ── DB ─────────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) { die('[FATAL] ' . $e->getMessage() . "\n"); }

$logFile = dirname(__DIR__) . '/logs/nav_incremental_' . date('Y-m') . '.log';
@mkdir(dirname($logFile), 0755, true);

function lg(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

$today = date('Y-m-d');
lg("=== NAV Incremental Update ===");
lg("Today: {$today} | Force days: {$forceDays} | All funds: " . ($allFunds ? 'YES' : 'NO'));

// ── Find funds that need updating ──────────────────────────────────────────
// A fund needs update if:
// 1. last_downloaded_date < today (market day)
// 2. OR last_downloaded_date IS NULL (never downloaded)
if ($allFunds) {
    $where = "WHERE p.fund_id IS NOT NULL";
} else {
    $where = "WHERE p.status = 'completed' AND p.last_downloaded_date < ?";
}

$sql = "
    SELECT p.scheme_code, p.fund_id, p.last_downloaded_date,
           DATEDIFF(?, COALESCE(p.last_downloaded_date, '2000-01-01')) AS days_behind
    FROM nav_download_progress p
    {$where}
    ORDER BY days_behind DESC
";

if ($allFunds) {
    $funds = $pdo->query(str_replace('?', "'$today'", $sql))->fetchAll();
} else {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$today, $today]);
    $funds = $stmt->fetchAll();
}

// Filter: only funds that are actually behind
$fundsToUpdate = [];
foreach ($funds as $f) {
    $daysBehind = (int)$f['days_behind'];
    if ($forceDays > 0) $daysBehind = max($daysBehind, $forceDays);
    if ($daysBehind > 0) {
        $f['days_behind'] = $daysBehind;
        $fundsToUpdate[] = $f;
    }
}

$total = count($fundsToUpdate);
lg("Funds needing update: {$total}");

if ($total === 0) {
    lg("✅ All funds up to date. Nothing to do.");
    exit(0);
}

// ── Process in parallel batches ─────────────────────────────────────────────
$insNav  = $pdo->prepare("INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?,?,?)");
$updFund = $pdo->prepare("UPDATE funds SET latest_nav=?, latest_nav_date=?, highest_nav=GREATEST(COALESCE(highest_nav,0),?), updated_at=NOW() WHERE id=?");
$markUpd = $pdo->prepare("UPDATE nav_download_progress SET last_downloaded_date=?, records_saved=records_saved+? WHERE scheme_code=?");

$chunks  = array_chunk($fundsToUpdate, PARALLEL);
$done    = 0; $saved = 0; $errors = 0;
$start   = microtime(true);

foreach ($chunks as $chunkIdx => $chunk) {
    // Parallel fetch
    $mh      = curl_multi_init();
    $handles = [];
    foreach ($chunk as $row) {
        $ch = curl_init(MFAPI_BASE . $row['scheme_code']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,    CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'WealthDash-IncrementalUpdate/1.0',
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_ENCODING => 'gzip',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$row['scheme_code']] = [$ch, $row];
    }
    $running = null;
    do { $s = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running > 0 && $s == CURLM_OK);

    foreach ($handles as $sc => [$ch, $row]) {
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if (!$body) { $errors++; continue; }

        $json = @json_decode($body, true);
        if (empty($json['data'])) { $done++; continue; }

        // Only process records AFTER last_downloaded_date
        $fromDate = $row['last_downloaded_date'] ?: '2000-01-01';
        $fundId   = $row['fund_id'];
        $batchSaved = 0; $latestNav = 0; $latestDate = '';

        $pdo->beginTransaction();
        try {
            foreach ($json['data'] as $entry) {
                $parts = explode('-', $entry['date']);
                if (count($parts) !== 3) continue;
                $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                // Only NEW records (after last download)
                if ($isoDate <= $fromDate) break; // mfapi newest first, so break when we hit old data
                $nav = (float)$entry['nav'];
                if ($nav <= 0) continue;
                if ($fundId) {
                    $insNav->execute([$fundId, $isoDate, $nav]);
                    if ($pdo->lastInsertId()) $batchSaved++;
                }
                if (!$latestDate || $isoDate > $latestDate) { $latestDate = $isoDate; $latestNav = $nav; }
            }
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); $errors++; continue; }

        if ($latestNav > 0 && $fundId) {
            $updFund->execute([$latestNav, $latestDate, $latestNav, $fundId]);
        }
        if ($batchSaved > 0) {
            $markUpd->execute([$latestDate ?: $today, $batchSaved, $sc]);
        }

        $done++; $saved += $batchSaved;
    }
    curl_multi_close($mh);

    if ($chunkIdx % 50 === 0 && $chunkIdx > 0) {
        $elapsed = round(microtime(true) - $start);
        lg("Progress: {$done}/{$total} | Saved:{$saved} | Errors:{$errors} | Time:{$elapsed}s");
    }
}

// ── Recalculate holding values ─────────────────────────────────────────────
lg("Recalculating MF holdings values...");
$pdo->exec("
    UPDATE mf_holdings h
    JOIN funds f ON f.id = h.fund_id
    SET h.value_now  = ROUND(h.total_units * f.latest_nav, 2),
        h.gain_loss  = ROUND((h.total_units * f.latest_nav) - h.total_invested, 2),
        h.gain_pct   = CASE WHEN h.total_invested > 0
                       THEN ROUND(((h.total_units*f.latest_nav - h.total_invested)/h.total_invested)*100,2)
                       ELSE 0 END,
        h.updated_at = NOW()
    WHERE h.is_active = 1 AND f.latest_nav > 0
");

// ── Returns update (1Y only — fast) ────────────────────────────────────────
lg("Updating 1Y returns...");
try {
    $pdo->exec("
        UPDATE funds f
        INNER JOIN (
            SELECT nh1.fund_id, nh1.nav AS nav_today, nh_1y.nav AS nav_1y_ago
            FROM nav_history nh1
            INNER JOIN nav_history nh_1y ON nh_1y.fund_id = nh1.fund_id
                AND nh_1y.nav_date BETWEEN DATE_SUB(nh1.nav_date, INTERVAL 370 DAY)
                                      AND DATE_SUB(nh1.nav_date, INTERVAL 360 DAY)
            WHERE nh1.nav_date = CURDATE()
            GROUP BY nh1.fund_id, nh1.nav, nh_1y.nav
        ) calc ON calc.fund_id = f.id
        SET f.returns_1y = ROUND((calc.nav_today / calc.nav_1y_ago - 1) * 100, 4),
            f.returns_updated_at = NOW()
        WHERE calc.nav_1y_ago > 0
    ");
} catch (Exception $e) { lg("Returns update skipped: " . $e->getMessage()); }

$elapsed = round(microtime(true) - $start);
lg("=== DONE === | Updated:{$done} | New records:{$saved} | Errors:{$errors} | Time:{$elapsed}s");
