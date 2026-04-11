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

// ── t79: SIP Due Reminder — 3 days pehle notify karo ──────────────────────
try {
    require_once APP_ROOT . '/includes/notification.php';
    $targetDate = date('Y-m-d', strtotime('+3 days'));
    $sipsDue = DB::fetchAll("
        SELECT ss.id, ss.user_id, ss.amount, ss.next_date, f.scheme_name, f.id AS fund_id
        FROM sip_schedules ss
        JOIN funds f ON f.id = ss.fund_id
        WHERE ss.status = 'active'
          AND ss.next_date = ?
    ", [$targetDate]);
    foreach ($sipsDue as $sip) {
        // Check if already notified for this SIP+date
        $already = DB::fetchOne("
            SELECT id FROM notifications
            WHERE user_id=? AND type='sip_reminder'
              AND body LIKE ? AND triggered_at >= CURDATE()
        ", [(int)$sip['user_id'], '%' . $sip['id'] . '%']);
        if ($already) continue;
        $amt = '₹' . number_format((float)$sip['amount'], 0);
        Notification::create(
            (int)$sip['user_id'],
            'sip_reminder',
            "🔄 SIP Due in 3 Days",
            "SIP #{$sip['id']} — {$sip['scheme_name']} — {$amt} due on {$sip['next_date']}",
            APP_URL . '/templates/pages/report_sip.php'
        );
    }
    if (count($sipsDue)) $log[] = "[" . date('Y-m-d H:i:s') . "] SIP reminders created: " . count($sipsDue);
} catch (Exception $e) {
    $log[] = "[WARN] SIP reminder failed: " . $e->getMessage();
}

// ── t78: Drawdown Alert — ATH se 10%+ gire to notify ─────────────────────
try {
    $drawdownFunds = DB::fetchAll("
        SELECT DISTINCT h.user_id, f.id AS fund_id, f.scheme_name,
               f.latest_nav, f.highest_nav,
               ROUND((f.highest_nav - f.latest_nav) / f.highest_nav * 100, 1) AS drawdown_pct
        FROM mf_holdings h
        JOIN funds f ON f.id = h.fund_id
        WHERE h.is_active = 1
          AND f.highest_nav > 0
          AND f.latest_nav > 0
          AND ((f.highest_nav - f.latest_nav) / f.highest_nav * 100) >= 10
    ");
    foreach ($drawdownFunds as $dd) {
        // Only notify once per fund per week
        $already = DB::fetchOne("
            SELECT id FROM notifications
            WHERE user_id=? AND type='drawdown'
              AND body LIKE ? AND triggered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ", [(int)$dd['user_id'], '%fund_id=' . $dd['fund_id'] . '%']);
        if ($already) continue;
        $ddPct = $dd['drawdown_pct'];
        Notification::create(
            (int)$dd['user_id'],
            'drawdown',
            "📉 Drawdown Alert: {$dd['scheme_name']}",
            "{$dd['scheme_name']} is {$ddPct}% below its all-time high. NAV: ₹" . number_format($dd['latest_nav'], 2) . " | ATH: ₹" . number_format($dd['highest_nav'], 2) . " | fund_id={$dd['fund_id']}",
            APP_URL . '/templates/pages/mf_holdings.php'
        );
    }
    if (count($drawdownFunds)) $log[] = "[" . date('Y-m-d H:i:s') . "] Drawdown alerts created: " . count($drawdownFunds);
} catch (Exception $e) {
    $log[] = "[WARN] Drawdown alert failed: " . $e->getMessage();
}

// ── t77: DB Price Alerts — target NAV crossed check ──────────────────────
try {
    $alerts = DB::fetchAll("
        SELECT pa.id, pa.user_id, pa.fund_id, pa.type, pa.target_nav, pa.note,
               f.scheme_name, f.latest_nav
        FROM price_alerts pa
        JOIN funds f ON f.id = pa.fund_id
        WHERE pa.is_active = 1 AND pa.triggered_at IS NULL
    ");
    foreach ($alerts as $al) {
        $hit = ($al['type'] === 'above' && $al['latest_nav'] >= $al['target_nav'])
            || ($al['type'] === 'below' && $al['latest_nav'] <= $al['target_nav']);
        if (!$hit) continue;
        $dir = $al['type'] === 'above' ? 'crossed above' : 'dropped below';
        Notification::create(
            (int)$al['user_id'],
            'nav_alert',
            "🔔 Price Alert: {$al['scheme_name']}",
            "NAV has {$dir} your target of ₹" . number_format($al['target_nav'], 2) . ". Current: ₹" . number_format($al['latest_nav'], 2),
            APP_URL . '/templates/pages/mf_screener.php'
        );
        DB::execute("UPDATE price_alerts SET is_active=0, triggered_at=NOW() WHERE id=?", [$al['id']]);
    }
} catch (Exception $e) {
    $log[] = "[WARN] Price alert check failed: " . $e->getMessage();
}

$out = implode("\n", $log);
echo $out . "\n";
@file_put_contents(APP_ROOT . '/logs/nav_update_' . date('Y-m') . '.log', $out . "\n", FILE_APPEND | LOCK_EX);
// t162: Trigger returns recalculation for funds updated today
// Only recalculate 1Y returns (fast) — full calc runs weekly
try {
    $db->exec("
        UPDATE funds f
        INNER JOIN (
            SELECT nh1.fund_id,
                   nh1.nav AS nav_today,
                   nh_1y.nav AS nav_1y_ago
            FROM nav_history nh1
            INNER JOIN nav_history nh_1y
                ON nh_1y.fund_id = nh1.fund_id
                AND nh_1y.nav_date BETWEEN DATE_SUB(nh1.nav_date, INTERVAL 370 DAY)
                                      AND DATE_SUB(nh1.nav_date, INTERVAL 360 DAY)
            WHERE nh1.nav_date = CURDATE()
            GROUP BY nh1.fund_id, nh1.nav, nh_1y.nav
        ) calc ON calc.fund_id = f.id
        SET f.returns_1y = ROUND((calc.nav_today / calc.nav_1y_ago - 1) * 100, 4),
            f.returns_updated_at = NOW()
        WHERE calc.nav_1y_ago > 0
    ");
    $log[] = "[" . date('Y-m-d H:i:s') . "] 1Y returns recalculated.";
} catch (Exception $e) {
    $log[] = "[WARN] Returns calc failed: " . $e->getMessage();
}
