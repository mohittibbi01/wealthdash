#!/usr/bin/env php
<?php
/**
 * WealthDash — t160: Populate NAV History (One-time bulk script)
 * MFAPI.in se sab active funds ka full history fetch karo
 *
 * Usage:
 *   php cron/populate_nav_history.php            → all funds
 *   php cron/populate_nav_history.php --limit=50 → first 50 funds only (test)
 *   php cron/populate_nav_history.php --from=HDFC → specific AMC only
 *   php cron/populate_nav_history.php --fund=119597 → single scheme code
 *
 * Estimated time: ~2-4 hours for full run (5000+ funds)
 * Run from XAMPP: php C:\xampp\htdocs\wealthdash\cron\populate_nav_history.php
 */
define('WEALTHDASH', true);
define('RUNNING_AS_CRON', true);
require_once dirname(__FILE__) . '/../config/config.php';
require_once APP_ROOT . '/includes/helpers.php';

$start   = microtime(true);
$argv    = $argv ?? [];
$limit   = 0;
$fromAmc = '';
$onlyFund= '';

foreach ($argv as $arg) {
    if (preg_match('/--limit=(\d+)/', $arg, $m))  $limit    = (int)$m[1];
    if (preg_match('/--from=(.+)/',   $arg, $m))  $fromAmc  = $m[1];
    if (preg_match('/--fund=(.+)/',   $arg, $m))  $onlyFund = $m[1];
}

$db = DB::conn();

// ── Fetch funds to process ──────────────────────────────────────────────────
$sql = "SELECT f.id, f.scheme_code, f.scheme_name,
               COALESCE(fh.short_name, fh.name) AS amc,
               (SELECT COUNT(*) FROM nav_history nh WHERE nh.fund_id = f.id) AS history_count,
               f.latest_nav_date
        FROM funds f
        LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
        WHERE f.is_active = 1";
if ($onlyFund) $sql .= " AND f.scheme_code = " . $db->quote($onlyFund);
if ($fromAmc)  $sql .= " AND (fh.short_name LIKE " . $db->quote('%'.$fromAmc.'%') . " OR fh.name LIKE " . $db->quote('%'.$fromAmc.'%') . ")";
$sql .= " ORDER BY f.id ASC";
if ($limit) $sql .= " LIMIT $limit";

$funds = DB::fetchAll($sql);
$total = count($funds);

echo "=== WealthDash NAV History Populator ===\n";
echo "Funds to process: $total\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

if (!$total) { echo "No funds found.\n"; exit(0); }

// ── Prepare statements ──────────────────────────────────────────────────────
$insNav = $db->prepare(
    "INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?, ?, ?)"
);
$updFund = $db->prepare(
    "UPDATE funds SET latest_nav=?, latest_nav_date=?,
     highest_nav=GREATEST(COALESCE(highest_nav,0),?), updated_at=NOW()
     WHERE id=?"
);

$done = 0; $failed = 0; $totalInserted = 0;

foreach ($funds as $fund) {
    $done++;
    $schemeCode  = $fund['scheme_code'];
    $fundId      = $fund['id'];
    $existingRows= (int)$fund['history_count'];

    $pct = round($done/$total*100);
    echo "[{$pct}%] {$done}/{$total} | {$schemeCode} | {$fund['scheme_name']} (existing: {$existingRows} rows)";

    // Skip if already has lots of history (>500 rows = already populated)
    if ($existingRows > 500 && !$onlyFund) {
        echo " — SKIP (sufficient history)\n";
        continue;
    }

    // ── Fetch from MFAPI ──────────────────────────────────────────────────
    $url = "https://api.mfapi.in/mf/{$schemeCode}";
    $ctx = stream_context_create(['http' => [
        'timeout'    => 30,
        'user_agent' => 'WealthDash/1.0',
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        echo " — FAILED (fetch error)\n";
        $failed++;
        usleep(500000); // 0.5s delay on failure
        continue;
    }

    $json = json_decode($raw, true);
    if (!isset($json['data']) || !is_array($json['data'])) {
        echo " — FAILED (bad JSON)\n";
        $failed++;
        continue;
    }

    $navData = $json['data']; // [{date:'DD-MM-YYYY', nav:'123.456'}]
    if (!count($navData)) {
        echo " — SKIP (no data)\n";
        continue;
    }

    // ── Insert into nav_history ───────────────────────────────────────────
    $inserted  = 0;
    $latestNav = 0;
    $latestDate= '';
    $highestNav= 0;

    $db->beginTransaction();
    try {
        foreach ($navData as $row) {
            $navVal = (float)($row['nav'] ?? 0);
            if ($navVal <= 0) continue;

            // Parse date DD-MM-YYYY → YYYY-MM-DD
            $parts = explode('-', $row['date'] ?? '');
            if (count($parts) !== 3) continue;
            $navDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            if (!checkdate((int)$parts[1], (int)$parts[0], (int)$parts[2])) continue;

            $insNav->execute([$fundId, $navDate, $navVal]);
            $inserted++;

            if ($navDate > $latestDate) {
                $latestDate = $navDate;
                $latestNav  = $navVal;
            }
            if ($navVal > $highestNav) $highestNav = $navVal;
        }

        // Update fund's latest NAV if newer than current
        if ($latestNav > 0) {
            $updFund->execute([$latestNav, $latestDate, $highestNav, $fundId]);
        }

        $db->commit();
        $totalInserted += $inserted;
        echo " — ✓ {$inserted} rows inserted\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo " — FAILED ({$e->getMessage()})\n";
        $failed++;
    }

    // Rate limiting: 200ms delay between requests
    usleep(200000);

    // Progress checkpoint every 100 funds
    if ($done % 100 === 0) {
        $elapsed = round(microtime(true) - $start);
        $remaining = $total - $done;
        $eta = $done > 0 ? round($elapsed/$done * $remaining) : 0;
        echo "\n--- CHECKPOINT: {$done}/{$total} done | {$totalInserted} rows total | Elapsed: {$elapsed}s | ETA: {$eta}s ---\n\n";
    }
}

$elapsed = round(microtime(true) - $start, 1);
echo "\n=== DONE ===\n";
echo "Funds processed : {$done}\n";
echo "Failed          : {$failed}\n";
echo "Rows inserted   : {$totalInserted}\n";
echo "Time taken      : {$elapsed}s\n";
echo "Finished        : " . date('Y-m-d H:i:s') . "\n";

// Log to file
$logMsg = date('Y-m-d H:i:s') . " | populate_nav_history | Processed:{$done} Failed:{$failed} Inserted:{$totalInserted} Time:{$elapsed}s\n";
@file_put_contents(APP_ROOT . '/logs/nav_populate_' . date('Y-m') . '.log', $logMsg, FILE_APPEND | LOCK_EX);
