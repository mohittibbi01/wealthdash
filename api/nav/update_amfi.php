<?php
/**
 * WealthDash — AMFI Fund List Updater + NAV Proxy
 * Tasks: t05 (AMFI Update), t163 (NAV Proxy local cache)
 * Actions: update_amfi | nav_proxy | fund_search_amfi
 * Run: php api/nav/update_amfi.php (to update fund master list)
 */

define('WEALTHDASH', true);
require_once __DIR__ . '/../../config/database.php';

$isCli    = (php_sapi_name() === 'cli');
$isAction = !$isCli;

if ($isAction) {
    if (!defined('WEALTHDASH')) die('Direct access not allowed.');
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? 'nav_proxy';

    switch ($action) {

    // ── NAV Proxy ─────────────────────────────────────────────────────────
    case 'nav_proxy':
        $code = preg_replace('/[^0-9]/', '', $_GET['scheme_code'] ?? '');
        if (!$code) { echo json_encode(['error' => 'scheme_code required']); exit; }

        $db   = DB::conn();
        $fund = $db->prepare("SELECT id, current_nav, nav_date FROM funds WHERE scheme_code=? LIMIT 1");
        $fund->execute([$code]);
        $f    = $fund->fetch(PDO::FETCH_ASSOC);

        if (!$f) {
            // Passthrough to MFAPI
            $resp = @file_get_contents("https://api.mfapi.in/mf/{$code}");
            echo $resp ?: json_encode(['error' => 'Fund not found']);
            exit;
        }

        // Return cached NAV history
        $rows = $db->prepare("SELECT nav_date AS date, nav FROM nav_history WHERE fund_id=? ORDER BY nav_date DESC LIMIT 1826");
        $rows->execute([$f['id']]);
        $data = $rows->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'meta' => ['scheme_code' => $code, 'scheme_name' => '', 'fund_house' => ''],
            'data' => array_map(fn($r) => [
                'date' => date('d-m-Y', strtotime($r['date'])),
                'nav'  => (string)$r['nav'],
            ], $data),
            'source' => 'wealthdash_cache',
        ]);
        exit;

    // ── Search AMFI by name ───────────────────────────────────────────────
    case 'fund_search_amfi':
        $q  = trim($_GET['q'] ?? '');
        $db = DB::conn();
        if (!$q) { echo json_encode(['results' => []]); exit; }
        $stmt = $db->prepare("
            SELECT id, scheme_code, fund_name, fund_house, category, plan_type, current_nav
            FROM funds WHERE is_active=1 AND fund_name LIKE ? ORDER BY fund_name LIMIT 20
        ");
        $stmt->execute(['%' . $q . '%']);
        echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    exit;
}

// ── CLI: Update AMFI Fund Master ───────────────────────────────────────────
$log  = fn(string $msg) => print('[' . date('H:i:s') . '] ' . $msg . PHP_EOL);
$db   = DB::conn();

$log("Fetching AMFI NAVAll.txt...");

// Ensure funds table has required columns
try {
    $db->exec("
        ALTER TABLE funds
          ADD COLUMN IF NOT EXISTS isin          VARCHAR(12)  DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS isin_growth   VARCHAR(12)  DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS plan_type     ENUM('Direct','Regular') DEFAULT 'Direct',
          ADD COLUMN IF NOT EXISTS fund_house    VARCHAR(100) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS category      VARCHAR(80)  DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS sub_category  VARCHAR(80)  DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS risk_level    VARCHAR(30)  DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS aum           DECIMAL(18,2) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS expense_ratio DECIMAL(5,3) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS exit_load_percent DECIMAL(5,2) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS lock_in_months INT UNSIGNED DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS is_active     TINYINT(1) NOT NULL DEFAULT 1,
          ADD COLUMN IF NOT EXISTS current_nav   DECIMAL(12,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS nav_date      DATETIME DEFAULT NULL
    ");
} catch (Exception $e) { $log("Column add: " . $e->getMessage()); }

$url  = 'https://www.amfiindia.com/spages/NAVAll.txt';
$ch   = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
$data = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$data) { $log("FAILED to fetch AMFI. HTTP $code"); exit(1); }

$lines    = explode("\n", $data);
$inserted = $updated = $skipped = 0;
$currentHouse = '';

$upsert = $db->prepare("
    INSERT INTO funds (scheme_code, fund_name, fund_house, plan_type, current_nav, nav_date, is_active)
    VALUES (?,?,?,?,?,NOW(),1)
    ON DUPLICATE KEY UPDATE
      fund_name=VALUES(fund_name), fund_house=VALUES(fund_house),
      current_nav=VALUES(current_nav), nav_date=NOW(), is_active=1
");

$db->beginTransaction();
$count = 0;
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    // Fund house header line (no semicolons)
    if (!str_contains($line, ';')) {
        $currentHouse = $line;
        continue;
    }

    $parts = explode(';', $line);
    if (count($parts) < 5) continue;

    [$code, $isin1, $isin2, $name, $nav] = array_map('trim', $parts);
    if (!is_numeric($code) || !is_numeric($nav) || (float)$nav <= 0) continue;

    $planType = (str_contains(strtolower($name), 'direct') || str_contains(strtolower($name), ' - dp ')) ? 'Direct' : 'Regular';

    $upsert->execute([$code, $name, $currentHouse, $planType, (float)$nav]);
    $count++;

    if ($count % 1000 === 0) {
        $db->commit();
        $db->beginTransaction();
        $log("Processed $count funds...");
    }
}
$db->commit();

$log("AMFI update complete. $count fund entries processed.");
