<?php
/**
 * WealthDash — AMFI Fund Seeder
 * Run once from browser: http://localhost/wealthdash/fetch_amfi_funds.php
 * OR via CLI: php fetch_amfi_funds.php
 *
 * Fetches all MF schemes from AMFI India, populates `funds` table.
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/wealthdash/config/config.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    // Basic security - only admin can run from browser
    require_once APP_ROOT . '/includes/auth_check.php';
    require_auth();
    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
        die('Admin only.');
    }
}

set_time_limit(300);
ini_set('memory_limit', '256M');

// ── AMFI fund house name → our fund_house short_name mapping ──────────────
$amfiToOurHouse = [
    'Aditya Birla Sun Life'   => 'ABSL MF',
    'Axis'                    => 'Axis MF',
    'Bandhan'                 => 'Bandhan MF',
    'Baroda BNP Paribas'      => 'Baroda BNP MF',
    'Canara Robeco'           => 'Canara Robeco MF',
    'DSP'                     => 'DSP MF',
    'Edelweiss'               => 'Edelweiss MF',
    'Franklin Templeton'      => 'Franklin MF',
    'HDFC'                    => 'HDFC MF',
    'HSBC'                    => 'HSBC MF',
    'ICICI Prudential'        => 'ICICI Pru MF',
    'IDFC'                    => 'IDFC MF',
    'Invesco'                 => 'Invesco MF',
    'Kotak'                   => 'Kotak MF',
    'LIC'                     => 'LIC MF',
    'Mahindra Manulife'       => 'Mahindra MF',
    'Mirae Asset'             => 'Mirae MF',
    'Motilal Oswal'           => 'Motilal MF',
    'Nippon India'            => 'Nippon MF',
    'PGIM India'              => 'PGIM MF',
    'SBI'                     => 'SBI MF',
    'Sundaram'                => 'Sundaram MF',
    'Tata'                    => 'Tata MF',
    'Templeton'               => 'Franklin MF',
    'Union'                   => 'Union MF',
    'UTI'                     => 'UTI MF',
    'Navi'                    => 'Navi MF',
    'Quant'                   => 'Quant MF',
    'WhiteOak'                => 'WhiteOak MF',
    'Groww'                   => 'Groww MF',
    'ITI'                     => 'ITI MF',
    'JM Financial'            => 'JM MF',
    'Samco'                   => 'Samco MF',
    'Trust'                   => 'Trust MF',
    'NJ'                      => 'NJ MF',
    'Helios'                  => 'Helios MF',
    'Old Bridge'              => 'Old Bridge MF',
    'Shriram'                 => 'Shriram MF',
    'Zerodha'                 => 'Zerodha MF',
    'JioBlackRock'            => 'JioBlackRock MF',
];

function log_msg($msg, $isCli) {
    if ($isCli) echo $msg . "\n";
    else echo $msg . "<br>\n";
    flush();
}

// ── Fetch AMFI data ────────────────────────────────────────────────────────
log_msg("📥 Fetching AMFI NAVAll.txt ...", $isCli);
$url  = 'https://www.amfiindia.com/spages/NAVAll.txt';
$data = @file_get_contents($url, false, stream_context_create([
    'http' => ['timeout' => 60, 'user_agent' => 'WealthDash/1.0']
]));

if (!$data) {
    die("❌ Could not fetch AMFI data. Check internet connection.\n");
}
log_msg("✅ Fetched " . number_format(strlen($data)) . " bytes", $isCli);

// ── Parse AMFI NAVAll.txt format ───────────────────────────────────────────
// Format:
// Scheme Code;ISIN Div Payout/IDCW;ISIN Div Reinvestment;Scheme Name;Net Asset Value;Date
// Headers repeat with "Open Ended Schemes(XXX Category)" etc.

$db = DB::conn();

// Get fund_house lookup
$fhStmt = $db->query("SELECT id, short_name, name FROM fund_houses");
$fundHouses = [];
foreach ($fhStmt->fetchAll() as $fh) {
    $fundHouses[$fh['short_name']] = $fh['id'];
    $fundHouses[$fh['name']]       = $fh['id'];
}

// Ensure some default AMCs exist
function ensure_fund_house(PDO $db, string $name, array &$fundHouses): int {
    if (isset($fundHouses[$name])) return $fundHouses[$name];
    $short = preg_replace('/\s+Mutual Fund.*$/i', ' MF', $name);
    $short = trim($short);
    $s = $db->prepare("INSERT IGNORE INTO fund_houses (name, short_name) VALUES (?,?)");
    $s->execute([$name, $short]);
    $id = $db->lastInsertId() ?: $db->query("SELECT id FROM fund_houses WHERE name=".  $db->quote($name))->fetchColumn();
    $fundHouses[$name]  = $id;
    $fundHouses[$short] = $id;
    return (int)$id;
}

$lines = explode("\n", $data);
$currentCategory = '';
$currentFundHouseName = '';
$inserted = 0; $updated = 0; $skipped = 0;

$insStmt = $db->prepare("
    INSERT INTO funds (fund_house_id, scheme_code, scheme_name, isin_growth, isin_div,
                       category, option_type, latest_nav, latest_nav_date, is_active)
    VALUES (?,?,?,?,?,?,?,?,?,1)
    ON DUPLICATE KEY UPDATE
        scheme_name    = VALUES(scheme_name),
        isin_growth    = VALUES(isin_growth),
        isin_div       = VALUES(isin_div),
        category       = VALUES(category),
        option_type    = VALUES(option_type),
        latest_nav     = VALUES(latest_nav),
        latest_nav_date= VALUES(latest_nav_date),
        is_active      = 1
");

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    // Category header line e.g. "Open Ended Schemes(Debt Scheme - Banking and PSU Fund)"
    if (stripos($line, 'Open Ended') !== false || stripos($line, 'Close Ended') !== false || stripos($line, 'Interval') !== false) {
        $currentCategory = $line;
        continue;
    }

    // Fund house header line (no semicolons, not a scheme code)
    if (strpos($line, ';') === false) {
        $currentFundHouseName = trim($line);
        continue;
    }

    // Data line: SchemeCode;ISIN1;ISIN2;SchemeName;NAV;Date
    $parts = explode(';', $line);
    if (count($parts) < 6) continue;

    $scheme_code  = trim($parts[0]);
    $isin_div     = trim($parts[1]) ?: null;
    $isin_growth  = trim($parts[2]) ?: null;
    $scheme_name  = trim($parts[3]);
    $nav          = is_numeric(trim($parts[4])) ? (float)trim($parts[4]) : null;
    $nav_date     = null;
    if (!empty(trim($parts[5]))) {
        $d = DateTime::createFromFormat('d-M-Y', trim($parts[5]));
        if (!$d) $d = DateTime::createFromFormat('d/m/Y', trim($parts[5]));
        if ($d)  $nav_date = $d->format('Y-m-d');
    }

    if (!is_numeric($scheme_code) || empty($scheme_name)) continue;

    // Detect option type from scheme name
    $nameLower = strtolower($scheme_name);
    $option_type = 'growth';
    if (strpos($nameLower, 'idcw') !== false || strpos($nameLower, 'dividend') !== false) {
        $option_type = 'idcw';
    } elseif (strpos($nameLower, 'bonus') !== false) {
        $option_type = 'bonus';
    }

    // Find fund house
    $fhId = null;
    foreach ($amfiToOurHouse as $amfiPrefix => $ourShort) {
        if (stripos($currentFundHouseName, $amfiPrefix) !== false) {
            // Ensure exists
            $fhId = ensure_fund_house($db, $currentFundHouseName, $fundHouses);
            if (!isset($fundHouses[$ourShort])) $fundHouses[$ourShort] = $fhId;
            break;
        }
    }
    if (!$fhId) {
        $fhId = ensure_fund_house($db, $currentFundHouseName ?: 'Other', $fundHouses);
    }

    try {
        $insStmt->execute([$fhId, $scheme_code, $scheme_name, $isin_growth, $isin_div,
                           $currentCategory, $option_type, $nav, $nav_date]);
        if ($insStmt->rowCount() > 0) $inserted++;
        else $skipped++;
    } catch (Exception $e) {
        $skipped++;
    }
}

log_msg("", $isCli);
log_msg("✅ Done! Inserted/Updated: $inserted | Skipped: $skipped", $isCli);
log_msg("", $isCli);

// ── Now check CSV funds match ─────────────────────────────────────────────
log_msg("🔍 Checking fund matches for your CSV funds...", $isCli);
$csvFunds = [
    'Aditya Birla Sun Life Banking and Financial Services Fund - Direct Plan - Growth',
    'Axis Large & Mid Cap Fund - Direct Plan - Growth',
    'HDFC Large and Mid Cap Fund - Growth Option - Direct Plan',
    'ICICI Prudential Multi-Asset Fund - Direct Plan - Growth',
    'JioBlackRock Nifty 50 Index Fund - Direct Plan - Growth Option',
    'SBI Large Cap FUND-DIRECT PLAN -GROWTH',
    'Nippon India Banking & Financial Services Fund - Direct Plan Growth Plan - Growth Option',
    'UTI Nifty 50 Index Fund - Growth Option- Direct',
    'Mirae Asset Great Consumer Fund - Direct Plan - Growth',
    'Tata Digital India Fund-Direct Plan-Growth',
];
foreach ($csvFunds as $name) {
    $s = $db->prepare("SELECT scheme_code, scheme_name FROM funds WHERE LOWER(scheme_name) LIKE LOWER(?) LIMIT 1");
    $s->execute(['%' . substr($name, 0, 30) . '%']);
    $f = $s->fetch();
    if ($f) log_msg("  ✅ MATCHED: $name → [{$f['scheme_code']}] {$f['scheme_name']}", $isCli);
    else    log_msg("  ❌ NO MATCH: $name", $isCli);
}

log_msg("", $isCli);
log_msg("🎉 Funds table populated! Now re-import your CSV.", $isCli);
