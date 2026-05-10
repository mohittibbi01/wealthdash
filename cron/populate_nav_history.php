<?php
/**
 * WealthDash — NAV History Populator
 * Task: t160 — Fetch full NAV history from MFAPI.in for all funds
 * Run: php cron/populate_nav_history.php [--batch=50] [--fund=scheme_code]
 * Note: Run once to backfill. Daily cron: update_nav_daily.php maintains it.
 */

define('WEALTHDASH', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/cron_logger.php';
$_cronLog = new CronLogger('populate_nav_history');
$_cronLog->start();


set_time_limit(0);
ini_set('memory_limit', '512M');

$batch      = 50;
$singleFund = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--batch=')) $batch      = (int)substr($arg, 8);
    if (str_starts_with($arg, '--fund='))  $singleFund = trim(substr($arg, 7));
}

$db  = DB::conn();
$log = fn(string $msg) => print('[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);

// Ensure nav_history table
$db->exec("
    CREATE TABLE IF NOT EXISTS nav_history (
        id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fund_id  INT UNSIGNED NOT NULL,
        nav_date DATE         NOT NULL,
        nav      DECIMAL(12,4) NOT NULL,
        UNIQUE KEY uk_fund_date (fund_id, nav_date),
        INDEX idx_fund (fund_id),
        INDEX idx_date (nav_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$log("WealthDash NAV History Populator starting...");

// Get funds to process
if ($singleFund) {
    $funds = $db->prepare("SELECT id, scheme_code, fund_name FROM funds WHERE scheme_code = ? LIMIT 1");
    $funds->execute([$singleFund]);
    $funds = $funds->fetchAll(PDO::FETCH_ASSOC);
} else {
    $funds = $db->query("
        SELECT f.id, f.scheme_code, f.fund_name
        FROM funds f
        LEFT JOIN (
            SELECT fund_id, MAX(nav_date) AS last_date, COUNT(*) AS nav_count
            FROM nav_history GROUP BY fund_id
        ) nh ON nh.fund_id = f.id
        WHERE f.is_active = 1
          AND (nh.nav_count IS NULL OR nh.nav_count < 30)
        ORDER BY f.id
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$total  = count($funds);
$done   = $fail = $skip = 0;
$log("Funds to process: $total");

function fetchNavFromMfapi(string $schemeCode): ?array {
    $url = "https://api.mfapi.in/mf/{$schemeCode}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: WealthDash/1.0'],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    $data = json_decode($resp, true);
    return $data['data'] ?? null;
}

// Process in batches
$chunks = array_chunk($funds, $batch);
foreach ($chunks as $ci => $chunk) {
    $log("Batch " . ($ci + 1) . "/" . count($chunks) . "...");

    foreach ($chunk as $fund) {
        $fid  = (int)$fund['id'];
        $code = $fund['scheme_code'];

        $navData = fetchNavFromMfapi($code);
        if (!$navData) {
            $log("  FAIL $code ({$fund['fund_name']})");
            $fail++;
            continue;
        }

        // Bulk insert using transaction
        $inserted = 0;
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT IGNORE INTO nav_history (fund_id, nav_date, nav)
                VALUES (?, ?, ?)
            ");
            foreach ($navData as $row) {
                // MFAPI returns date as DD-MM-YYYY
                if (isset($row['date'], $row['nav'])) {
                    $parts = explode('-', $row['date']);
                    if (count($parts) === 3) {
                        $dateMySQL = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                        $stmt->execute([$fid, $dateMySQL, (float)$row['nav']]);
                        $inserted++;
                    }
                }
            }
            $db->commit();

            // Update inception_date on funds table
            if ($inserted > 0) {
                $firstDate = $db->prepare("SELECT MIN(nav_date) FROM nav_history WHERE fund_id = ?");
                $firstDate->execute([$fid]);
                $inception = $firstDate->fetchColumn();
                if ($inception) {
                    $db->prepare("UPDATE funds SET inception_date = ? WHERE id = ? AND inception_date IS NULL")
                       ->execute([$inception, $fid]);
                }
            }

            $done++;
            if ($done % 100 === 0) $log("  Progress: $done/$total done");
        } catch (Exception $e) {
            $db->rollBack();
            $log("  DB ERROR $code: " . $e->getMessage());
            $fail++;
        }

        // Rate limiting — MFAPI free tier
        usleep(50000); // 50ms between requests
    }

    // Pause between batches
    if ($ci < count($chunks) - 1) sleep(2);
}

$log("COMPLETE. Done=$done, Failed=$fail, Skipped=$skip out of $total");
$log("Run calculate_returns.php next to compute 1Y/3Y/5Y returns.");
\$_cronLog->finish(\$fail > 0 ? 'warning' : 'success', "Done=\$done Failed=\$fail Skipped=\$skip", \$done);

