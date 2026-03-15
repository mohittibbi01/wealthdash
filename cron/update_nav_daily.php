#!/usr/bin/env php
<?php
/**
 * WealthDash — Daily NAV Update Cron
 * Schedule: Daily at 10 PM IST
 * Windows Task Scheduler: php C:\xampp\htdocs\wealthdash\cron\update_nav_daily.php
 * Linux cron: 0 22 * * * /usr/bin/php /var/www/html/wealthdash/cron/update_nav_daily.php
 */
define('WEALTHDASH', true);
define('RUNNING_AS_CRON', true);
require_once dirname(__FILE__) . '/../config/config.php';
require_once APP_ROOT . '/includes/helpers.php';

$start = microtime(true);
$log   = [];
$date  = date('Y-m-d H:i:s');

$log[] = "[$date] WealthDash NAV Update Cron started";

try {
    $db = DB::conn();
    $lastRun = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='last_nav_update' LIMIT 1")->fetchColumn();
    $today   = date('Y-m-d');

    if ($lastRun && substr($lastRun, 0, 10) === $today && !in_array('--force', $argv ?? [])) {
        $log[] = "[$date] NAV already updated today ($lastRun). Use --force to override.";
        echo implode("\n", $log) . "\n"; exit(0);
    }
} catch (Exception $e) { $log[] = "[WARN] " . $e->getMessage(); }

// Fetch AMFI NAV
$amfiUrl = defined('AMFI_NAV_URL') ? AMFI_NAV_URL : 'https://www.amfiindia.com/spages/NAVAll.txt';
$ctx = stream_context_create(['http'=>['timeout'=>60,'user_agent'=>'WealthDash/1.0']]);
$raw = @file_get_contents($amfiUrl, false, $ctx);
if (!$raw) { $log[] = "[$date] [ERROR] Failed to fetch AMFI data"; echo implode("\n",$log)."\n"; exit(1); }

$lines   = explode("\n", $raw);
$updated = 0; $skipped = 0;

try {
    $db = DB::conn();
    $db->beginTransaction();

    $findFund   = $db->prepare("SELECT id FROM funds WHERE scheme_code=? LIMIT 1");
    $updateNav  = $db->prepare("UPDATE funds SET
                    prev_nav      = IF(latest_nav_date IS NOT NULL AND latest_nav_date < ?, latest_nav, prev_nav),
                    prev_nav_date = IF(latest_nav_date IS NOT NULL AND latest_nav_date < ?, latest_nav_date, prev_nav_date),
                    latest_nav=?, latest_nav_date=?,
                    highest_nav=GREATEST(COALESCE(highest_nav,0),?),
                    updated_at=NOW()
                  WHERE scheme_code=?");
    $insHistory = $db->prepare("INSERT IGNORE INTO nav_history (fund_id,nav_date,nav) VALUES(?,?,?)");
    $batch = 0;

    foreach ($lines as $line) {
        $parts = explode(';', trim($line));
        if (count($parts) < 5) continue;
        $code = trim($parts[0]);
        if (!is_numeric($code)) continue;

        $navVal  = ''; $navDate = '';
        if (count($parts) >= 6 && is_numeric(str_replace([',','.'],'',trim($parts[4])))) {
            $navVal = trim($parts[4]); $navDate = trim($parts[5]);
        } else {
            $navVal = trim($parts[3]); $navDate = trim($parts[4] ?? '');
        }
        if (!is_numeric($navVal) || (float)$navVal <= 0) { $skipped++; continue; }

        // Parse date
        $pd = null;
        foreach (['d-M-Y','d/m/Y','Y-m-d'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $navDate);
            if ($dt) { $pd = $dt->format('Y-m-d'); break; }
        }
        if (!$pd) { $skipped++; continue; }

        $findFund->execute([$code]);
        $fund = $findFund->fetch();
        if (!$fund) { $skipped++; continue; }

        $nav = (float)$navVal;
        $updateNav->execute([$pd, $pd, $nav, $pd, $nav, $code]);
        $insHistory->execute([$fund['id'], $pd, $nav]);
        $updated++;
        if (++$batch % 500 === 0) { $db->commit(); $db->beginTransaction(); }
    }

    $db->commit();

    // Update all holding values in bulk
    $db->exec("
        UPDATE mf_holdings h
        JOIN funds f ON f.id = h.fund_id
        SET h.value_now  = ROUND(h.total_units * f.latest_nav, 2),
            h.gain_loss  = ROUND((h.total_units * f.latest_nav) - h.total_invested, 2),
            h.gain_pct   = CASE WHEN h.total_invested > 0
                           THEN ROUND(((h.total_units*f.latest_nav - h.total_invested)/h.total_invested)*100,2)
                           ELSE 0 END,
            h.gain_type  = CASE WHEN DATEDIFF(CURDATE(), h.first_purchase_date) >= f.min_ltcg_days
                           THEN 'LTCG' ELSE 'STCG' END,
            h.updated_at = NOW()
        WHERE h.is_active = 1 AND f.latest_nav > 0
    ");

    $db->exec("INSERT INTO app_settings (setting_key,setting_value) VALUES ('last_nav_update',NOW())
               ON DUPLICATE KEY UPDATE setting_value=NOW()");

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $log[] = "[$date] [ERROR] " . $e->getMessage();
}

$elapsed = round(microtime(true) - $start, 2);
$log[] = "[$date] Updated: $updated NAVs, Skipped: $skipped. Time: {$elapsed}s";
$log[] = "[$date] Done.";

$out = implode("\n", $log);
echo $out . "\n";
@file_put_contents(APP_ROOT . '/logs/nav_update_' . date('Y-m') . '.log', $out . "\n", FILE_APPEND | LOCK_EX);