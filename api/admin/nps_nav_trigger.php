<?php
/**
 * WealthDash — Admin: NPS NAV Trigger
 * t99: Admin panel se NAV update / backfill / returns trigger
 *
 * POST /api/?action=admin_nps_nav_trigger
 * Body: { mode: 'daily'|'backfill'|'returns', scheme_id?: int, manual_nav?: float, nav_date?: string }
 */
defined('WEALTHDASH') or die('Direct access not permitted.');
if (!$isAdmin) json_response(false, 'Admin only.', [], 403);

require_once APP_ROOT . '/includes/holding_calculator.php';

$body      = json_decode(file_get_contents('php://input'), true) ?: [];
$mode      = clean($body['mode'] ?? $_POST['mode'] ?? 'status');
$schemeId  = (int)($body['scheme_id'] ?? $_POST['scheme_id'] ?? 0);
$manualNav = (float)($body['manual_nav'] ?? $_POST['manual_nav'] ?? 0);
$navDate   = clean($body['nav_date'] ?? $_POST['nav_date'] ?? date('Y-m-d'));

// ── STATUS ────────────────────────────────────────────────────────────────────
if ($mode === 'status') {
    $lastRun    = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='nps_nav_last_run'");
    $lastStatus = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='nps_nav_last_status'");
    $autoUpdate = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='nps_nav_auto_update'");

    $histCount  = (int)(DB::fetchVal("SELECT COUNT(*) FROM nps_nav_history") ?: 0);
    $schemeCount= (int)(DB::fetchVal("SELECT COUNT(*) FROM nps_schemes WHERE is_active=1") ?: 0);

    // Schemes missing today's NAV
    $today = date('Y-m-d');
    $missingToday = (int)(DB::fetchVal(
        "SELECT COUNT(*) FROM nps_schemes WHERE is_active=1 AND (latest_nav_date != ? OR latest_nav IS NULL)",
        [$today]
    ) ?: 0);

    json_response(true, '', [
        'last_run'       => $lastRun,
        'last_status'    => $lastStatus,
        'auto_update'    => (bool)$autoUpdate,
        'history_count'  => $histCount,
        'scheme_count'   => $schemeCount,
        'missing_today'  => $missingToday,
        'nav_up_to_date' => $missingToday === 0,
    ]);
}

// ── MANUAL NAV UPDATE (single scheme) ────────────────────────────────────────
if ($mode === 'manual' && $schemeId && $manualNav > 0) {
    // Save to nav_history
    DB::run(
        "INSERT INTO nps_nav_history (scheme_id, nav_date, nav)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE nav=VALUES(nav)",
        [$schemeId, $navDate, $manualNav]
    );
    // Update scheme latest
    DB::run(
        "UPDATE nps_schemes SET latest_nav=?, latest_nav_date=?, updated_at=NOW() WHERE id=?",
        [$manualNav, $navDate, $schemeId]
    );
    // Recalculate holdings
    $holdings = DB::fetchAll(
        "SELECT portfolio_id, tier FROM nps_holdings WHERE scheme_id=?", [$schemeId]
    );
    foreach ($holdings as $h) {
        HoldingCalculator::recalculate_nps_holding((int)$h['portfolio_id'], $schemeId, $h['tier']);
    }
    audit_log('nps_nav_manual', 'nps_schemes', $schemeId, ['nav' => $manualNav, 'date' => $navDate]);
    json_response(true, "NAV ₹{$manualNav} saved for " . date('d-m-Y', strtotime($navDate)));
}

// ── TOGGLE AUTO-UPDATE ────────────────────────────────────────────────────────
if ($mode === 'toggle_auto') {
    $current = (int)(DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='nps_nav_auto_update'") ?: 0);
    $new     = $current ? 0 : 1;
    DB::run("UPDATE app_settings SET setting_val=? WHERE setting_key='nps_nav_auto_update'", [$new]);
    json_response(true, "Auto-update " . ($new ? 'enabled' : 'disabled'), ['auto_update' => (bool)$new]);
}

// ── RUN CRON (background) ────────────────────────────────────────────────────
if (in_array($mode, ['daily', 'backfill', 'returns'])) {
    $phpBin    = PHP_BINARY;
    $scraperPath = APP_ROOT . '/cron/nps_nav_scraper.php';

    if (!file_exists($scraperPath)) {
        json_response(false, "Scraper file not found: {$scraperPath}");
    }

    // Non-blocking background execution
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen("start /B {$phpBin} {$scraperPath} {$mode} > NUL 2>&1", 'r'));
    } else {
        exec("{$phpBin} {$scraperPath} {$mode} > /dev/null 2>&1 &");
    }

    // Mark as triggered
    DB::run("UPDATE app_settings SET setting_val='triggered' WHERE setting_key='nps_nav_last_status'");
    DB::run("UPDATE app_settings SET setting_val=NOW() WHERE setting_key='nps_nav_last_run'");

    $labels = [
        'daily'    => 'Daily NAV update started in background',
        'backfill' => "Historical backfill started (last 5 years). This may take a few minutes.",
        'returns'  => '1Y/3Y/5Y return recalculation started',
    ];
    json_response(true, $labels[$mode], ['mode' => $mode, 'pid' => 'background']);
}

json_response(false, 'Invalid mode. Use: status|manual|daily|backfill|returns|toggle_auto');
