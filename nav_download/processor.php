<?php
/**
 * WealthDash — NAV Download Processor (Background)
 * Tasks: t192 — Parallel NAV history downloader (like peak_nav/processor.php)
 * Run: php nav_download/processor.php [--workers=4] [--batch=25]
 */

define('WEALTHDASH', true);
require_once __DIR__ . '/../config/database.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$workers = 4;
$batch   = 25;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--workers=')) $workers = (int)substr($arg, 10);
    if (str_starts_with($arg, '--batch='))   $batch   = (int)substr($arg, 8);
}

$db  = DB::conn();
$log = fn(string $msg) => print('[' . date('H:i:s') . '] ' . $msg . PHP_EOL);

// Ensure progress table
$db->exec("
    CREATE TABLE IF NOT EXISTS nav_download_progress (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fund_id      INT UNSIGNED NOT NULL,
        scheme_code  VARCHAR(20)  NOT NULL,
        status       ENUM('pending','in_progress','done','failed') NOT NULL DEFAULT 'pending',
        nav_count    INT UNSIGNED DEFAULT 0,
        last_attempt DATETIME DEFAULT NULL,
        error_msg    VARCHAR(200) DEFAULT NULL,
        UNIQUE KEY uk_fund (fund_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed progress table from funds table
$db->exec("
    INSERT IGNORE INTO nav_download_progress (fund_id, scheme_code, status)
    SELECT id, scheme_code, 'pending' FROM funds WHERE is_active = 1
");

$total   = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress WHERE status='pending'")->fetchColumn();
$log("Total pending: $total | Workers: $workers | Batch: $batch");

if ($total === 0) { $log("Nothing to do."); exit(0); }

// Process in chunks
$processed = 0;
while (true) {
    // Claim a batch
    $db->exec("UPDATE nav_download_progress SET status='in_progress', last_attempt=NOW()
               WHERE status='pending' LIMIT $batch");

    $pending = $db->query("SELECT fund_id, scheme_code FROM nav_download_progress
                           WHERE status='in_progress' ORDER BY fund_id LIMIT $batch")
                  ->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pending)) break;

    foreach ($pending as $fund) {
        $fid  = (int)$fund['fund_id'];
        $code = $fund['scheme_code'];

        $url = "https://api.mfapi.in/mf/{$code}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 200 || !$resp) {
            $db->prepare("UPDATE nav_download_progress SET status='failed', error_msg='HTTP $http' WHERE fund_id=?")
               ->execute([$fid]);
            continue;
        }

        $data    = json_decode($resp, true);
        $navData = $data['data'] ?? [];
        $count   = 0;

        $ins = $db->prepare("INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?,?,?)");
        foreach ($navData as $row) {
            if (isset($row['date'], $row['nav'])) {
                $parts = explode('-', $row['date']);
                if (count($parts) === 3) {
                    $ins->execute([$fid, "{$parts[2]}-{$parts[1]}-{$parts[0]}", (float)$row['nav']]);
                    $count++;
                }
            }
        }

        $db->prepare("UPDATE nav_download_progress SET status='done', nav_count=? WHERE fund_id=?")
           ->execute([$count, $fid]);
        $processed++;
        usleep(80000); // 80ms
    }

    $remaining = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress WHERE status='pending'")->fetchColumn();
    $log("Processed: $processed | Remaining: $remaining");
    if ($remaining === 0) break;
}

$done   = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress WHERE status='done'")->fetchColumn();
$failed = (int)$db->query("SELECT COUNT(*) FROM nav_download_progress WHERE status='failed'")->fetchColumn();
$log("COMPLETE. Done=$done Failed=$failed");
