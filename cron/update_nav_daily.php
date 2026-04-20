<?php
/**
 * WealthDash — Daily NAV Updater
 * Task: t162 — Update current NAV from AMFI + maintain nav_history
 * Run: php cron/update_nav_daily.php
 * Schedule: Daily 7 PM (after AMFI publishes NAVs)
 */

define('WEALTHDASH', true);
require_once __DIR__ . '/../config/database.php';

$log = fn(string $msg) => print('[' . date('H:i:s') . '] ' . $msg . PHP_EOL);
$log("Daily NAV update starting...");

$db = DB::conn();

// ── Fetch AMFI NAVAll.txt ────────────────────────────────────────────────
function fetchAmfiNavAll(): array {
    $url  = 'https://www.amfiindia.com/spages/NAVAll.txt';
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: text/plain'],
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$data) return [];

    $navMap = [];
    foreach (explode("\n", $data) as $line) {
        $parts = explode(';', trim($line));
        if (count($parts) >= 5) {
            $code = trim($parts[0]);
            $nav  = (float)trim($parts[4]);
            $date = trim($parts[7] ?? $parts[5] ?? '');
            if (is_numeric($code) && $nav > 0) {
                $navMap[$code] = ['nav' => $nav, 'date' => $date];
            }
        }
    }
    return $navMap;
}

$navAll = fetchAmfiNavAll();
$log("AMFI NAVAll.txt: " . count($navAll) . " entries fetched.");

if (empty($navAll)) {
    $log("ERROR: Could not fetch AMFI data. Exiting.");
    exit(1);
}

// ── Update funds table + nav_history ────────────────────────────────────
$today   = date('Y-m-d');
$updated = $skipped = $notFound = 0;

$funds = $db->query("SELECT id, scheme_code FROM funds WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

$stmtFund = $db->prepare("
    UPDATE funds SET current_nav = ?, nav_date = NOW() WHERE id = ?
");
$stmtHist = $db->prepare("
    INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?, ?, ?)
");

$db->beginTransaction();
try {
    foreach ($funds as $fund) {
        $code = $fund['scheme_code'];
        $fid  = (int)$fund['id'];

        if (!isset($navAll[$code])) { $notFound++; continue; }

        $nav     = $navAll[$code]['nav'];
        $navDate = $navAll[$code]['date'];

        // Parse date (AMFI format: DD-MMM-YYYY e.g. 18-Apr-2026)
        if ($navDate) {
            $ts = strtotime($navDate);
            if ($ts) $navDate = date('Y-m-d', $ts);
            else     $navDate = $today;
        } else {
            $navDate = $today;
        }

        $stmtFund->execute([$nav, $fid]);
        $stmtHist->execute([$fid, $navDate, $nav]);
        $updated++;
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    $log("DB ERROR: " . $e->getMessage());
    exit(1);
}

$log("Updated=$updated, NotFound=$notFound, Skipped=$skipped");

// ── Update mf_holdings current_value ────────────────────────────────────
$log("Updating mf_holdings current values...");
$db->exec("
    UPDATE mf_holdings mh
    JOIN funds f ON f.id = mh.fund_id
    SET mh.current_nav    = f.current_nav,
        mh.current_value  = mh.units * f.current_nav,
        mh.abs_return_pct = CASE WHEN mh.invested_amount > 0
                                 THEN (mh.units * f.current_nav - mh.invested_amount) / mh.invested_amount * 100
                                 ELSE 0 END,
        mh.updated_at     = NOW()
    WHERE mh.is_active = 1 AND f.current_nav > 0
");

// ── Trigger returns calculation for recently updated funds ───────────────
$log("Queueing returns recalculation...");
$db->exec("UPDATE funds SET returns_updated_at = NULL WHERE nav_date >= CURDATE() - INTERVAL 1 DAY");

$log("Daily NAV update complete. Triggering calculate_returns.php...");
// Spawn calculate_returns in background
@exec('php ' . __DIR__ . '/calculate_returns.php --limit=2000 > /dev/null 2>&1 &');

$log("Done.");
