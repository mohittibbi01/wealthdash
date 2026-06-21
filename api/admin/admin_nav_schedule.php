<?php
/**
 * WealthDash — Admin: NAV Auto-Update Schedule Settings
 * t05: Lets admin configure auto-NAV update time + enable/disable.
 * Routed via: ?action=admin_nav_schedule_get | admin_nav_schedule_save
 */
defined('WEALTHDASH') or die();

if (!is_admin()) {
    json_response(false, 'Admin only.', [], 403);
}

$action = $_GET['action'] ?? '';

// ── GET schedule settings ─────────────────────────────────────
if ($action === 'admin_nav_schedule_get') {
    $settings = [
        'nav_auto_enabled'  => (bool)(db_get_setting('nav_auto_enabled')  ?? true),
        'nav_auto_time'     => db_get_setting('nav_auto_time')             ?? '22:00',
        'nav_last_run_date' => db_get_setting('nav_auto_update_last_run')  ?? null,
        'nav_last_run_status' => db_get_setting('nav_auto_update_last_status') ?? null,
    ];
    json_response(true, 'OK', $settings);
    exit;
}

// ── SAVE schedule settings ────────────────────────────────────
if ($action === 'admin_nav_schedule_save') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $enabled = isset($body['nav_auto_enabled']) ? (bool)$body['nav_auto_enabled'] : true;
    $time    = preg_match('/^\d{2}:\d{2}$/', $body['nav_auto_time'] ?? '') ? $body['nav_auto_time'] : '22:00';

    db_set_setting('nav_auto_enabled', $enabled ? '1' : '0');
    db_set_setting('nav_auto_time',    $time);

    // Write/update the system crontab entry for www-data / deploy user
    _t05_write_crontab($enabled, $time);

    json_response(true, 'Schedule saved.', [
        'nav_auto_enabled' => $enabled,
        'nav_auto_time'    => $time,
    ]);
    exit;
}

// ── MANUAL TRIGGER (admin override, still available) ─────────
if ($action === 'admin_nav_update') {
    require APP_ROOT . '/api/nav/update_amfi.php';
    exit;
}

json_response(false, 'Unknown action.', [], 400);

// ─────────────────────────────────────────────────────────────
// Helper: update system crontab for the nav auto-update script
// ─────────────────────────────────────────────────────────────
function _t05_write_crontab(bool $enabled, string $time): void {
    // Parse HH:MM
    [$hh, $mm] = explode(':', $time);
    $hh = (int)$hh;
    $mm = (int)$mm;

    $cronScript  = escapeshellarg(APP_ROOT . '/cron/nav_auto_update.php');
    $cronComment = '# WealthDash t05 nav_auto_update';
    $cronLine    = sprintf('%d %d * * * php %s >> /var/log/wealthdash_nav.log 2>&1 %s', $mm, $hh, $cronScript, $cronComment);

    // Read current crontab
    exec('crontab -l 2>/dev/null', $lines, $rc);
    $lines = array_filter($lines, fn($l) => strpos($l, 'nav_auto_update') === false && strpos($l, $cronComment) === false);
    $lines = array_values($lines);

    if ($enabled) {
        $lines[] = $cronLine;
    }

    // Write back
    $tmp = tempnam(sys_get_temp_dir(), 'wd_cron_');
    file_put_contents($tmp, implode("\n", $lines) . "\n");
    exec('crontab ' . escapeshellarg($tmp));
    unlink($tmp);
}
