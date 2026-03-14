<?php
/**
 * WealthDash — AMFI NAV Bulk Update
 * Run daily via cron: php update_amfi.php
 * Or manually by admin via browser
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$isCron = php_sapi_name() === 'cli';

if (!$isCron) {
    require_auth(ROLE_ADMIN);
    header('Content-Type: application/json');
}

$amfiUrl = AMFI_NAV_URL;
$today   = date('Y-m-d');

// Check if already updated today
$lastUpdated = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key = 'nav_last_updated'");
$manual = isset($_GET['manual']) && $_GET['manual'] === '1'
       || ($isCron && isset($argv[1]) && $argv[1] === '--force');

if ($lastUpdated === $today && !$manual) {
    $msg = "NAV already updated today ({$today}). Use ?manual=1 to force update.";
    if ($isCron) { echo $msg . PHP_EOL; exit(0); }
    json_response(true, $msg);
}

// Fetch AMFI data
$context = stream_context_create([
    'http' => ['timeout' => 30, 'user_agent' => 'WealthDash/1.0'],
    'ssl'  => ['verify_peer' => false],
]);

$data = @file_get_contents($amfiUrl, false, $context);

if (!$data) {
    $msg = 'Failed to fetch AMFI NAV data. Check internet connection.';
    if ($isCron) { echo "ERROR: $msg" . PHP_EOL; exit(1); }
    json_response(false, $msg);
}

$lines       = explode("\n", $data);
$updated     = 0;
$inserted    = 0;
$currentAmc  = '';
$currentAmcId = null;

DB::beginTransaction();
try {
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // AMC header line (no semicolons, not a data line)
        if (!str_contains($line, ';')) {
            $currentAmc = $line;
            // Upsert fund house
            $existing = DB::fetchOne('SELECT id FROM fund_houses WHERE name = ?', [$currentAmc]);
            if ($existing) {
                $currentAmcId = $currentAmcId = (int) $existing['id'];
            } else {
                $currentAmcId = (int) DB::insert(
                    'INSERT INTO fund_houses (name) VALUES (?)',
                    [$currentAmc]
                );
            }
            continue;
        }

        // Data line: Scheme Code;ISIN Div Payout/IDCW;ISIN Div Reinvestment;Scheme Name;Net Asset Value;Date
        $parts = explode(';', $line);
        if (count($parts) < 6) continue;

        $schemeCode  = trim($parts[0]);
        $isinDiv     = trim($parts[1]);
        $isinGrowth  = trim($parts[2]);
        $schemeName  = trim($parts[3]);
        $navStr      = trim($parts[4]);
        $navDateStr  = trim($parts[5]);

        if (!is_numeric($navStr) || empty($schemeCode) || empty($schemeName)) continue;

        $nav     = (float) $navStr;
        $navDate = null;

        // Parse date: DD-Mon-YYYY
        if ($navDateStr) {
            $d = DateTime::createFromFormat('d-M-Y', $navDateStr);
            if ($d) $navDate = $d->format('Y-m-d');
        }
        if (!$navDate) $navDate = $today;

        // Upsert fund
        $fund = DB::fetchOne('SELECT id FROM funds WHERE scheme_code = ?', [$schemeCode]);

        if ($fund) {
            // Update NAV
            DB::run(
                'UPDATE funds SET latest_nav = ?, latest_nav_date = ?,
                    highest_nav = GREATEST(COALESCE(highest_nav, 0), ?),
                    highest_nav_date = IF(? > COALESCE(highest_nav, 0), ?, highest_nav_date),
                    updated_at = NOW()
                 WHERE scheme_code = ?',
                [$nav, $navDate, $nav, $nav, $navDate, $schemeCode]
            );
            $fundId = (int) $fund['id'];
            $updated++;
        } else {
            // Determine category/type from name
            $optionType = 'growth';
            if (preg_match('/\b(dividend|idcw|div)\b/i', $schemeName)) $optionType = 'idcw';
            if (preg_match('/\bbonus\b/i', $schemeName)) $optionType = 'bonus';

            $isElss   = (bool) preg_match('/\belss\b/i', $schemeName);
            $lockIn   = $isElss ? ELSS_LOCKIN_DAYS : 0;
            $ltcgDays = EQUITY_LTCG_DAYS;

            // Detect category from scheme name
            if ($isElss) {
                $category = 'ELSS';
            } elseif (preg_match('/\b(liquid|overnight|ultra short|low dur|short dur|money market)\b/i', $schemeName)) {
                $category = 'Liquid';
                $ltcgDays = DEBT_LTCG_DAYS;
            } elseif (preg_match('/\b(debt|gilt|floater|banking and psu|credit risk|medium|long dur|dynamic bond|corporate bond)\b/i', $schemeName)) {
                $category = 'Debt';
                $ltcgDays = DEBT_LTCG_DAYS;
            } elseif (preg_match('/\b(hybrid|balanced|aggressive|conservative|multi asset|arbitrage|equity savings)\b/i', $schemeName)) {
                $category = 'Hybrid';
            } elseif (preg_match('/\b(index|nifty|sensex|bse|nse|etf|exchange traded)\b/i', $schemeName)) {
                $category = 'Index';
            } else {
                $category = 'Equity';
            }

            $fundId = (int) DB::insert(
                'INSERT INTO funds (fund_house_id, scheme_code, scheme_name, isin_growth, isin_div,
                    category, option_type, min_ltcg_days, lock_in_days, latest_nav, latest_nav_date,
                    highest_nav, highest_nav_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $currentAmcId ?: 1,
                    $schemeCode, $schemeName,
                    $isinGrowth ?: null, $isinDiv ?: null,
                    $category, $optionType, $ltcgDays, $lockIn,
                    $nav, $navDate, $nav, $navDate,
                ]
            );
            $inserted++;
        }

        // Store in nav_history (ignore duplicates)
        if ($fundId && $navDate) {
            try {
                DB::run(
                    'INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?, ?, ?)',
                    [$fundId, $navDate, $nav]
                );
            } catch (Exception) {}
        }
    }

    // Update last-updated setting
    DB::run(
        "INSERT INTO app_settings (setting_key, setting_val) VALUES ('nav_last_updated', ?)
         ON DUPLICATE KEY UPDATE setting_val = ?",
        [$today, $today]
    );

    DB::commit();

    $msg = "NAV update complete. Updated: {$updated}, New funds: {$inserted}. Date: {$today}";
    if ($isCron) { echo $msg . PHP_EOL; exit(0); }
    json_response(true, $msg, ['updated' => $updated, 'inserted' => $inserted]);

} catch (Exception $e) {
    DB::rollback();
    error_log('AMFI update error: ' . $e->getMessage());
    $msg = 'NAV update failed: ' . $e->getMessage();
    if ($isCron) { echo "ERROR: $msg" . PHP_EOL; exit(1); }
    json_response(false, $msg);
}