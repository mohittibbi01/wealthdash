<?php
/**
 * WealthDash — Test: API Files Existence [t411]
 * File: debug/tests/test_api_files.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

// ── Core Files ────────────────────────────────────────────────────────────────
WDTest::describe('Core Files', function () {
    $files = [
        'config/config.php', 'config/constants.php', 'config/database.php',
        'includes/auth_check.php', 'includes/helpers.php', 'includes/cache.php',
        'api/router.php', 'index.php', '.env', '.htaccess',
        'public/css/app.css', 'public/js/app.js',
    ];
    foreach ($files as $f) {
        WDTest::it("Exists: {$f}", function () use ($f) {
            assert_true(file_exists(APP_ROOT . '/' . $f), "Missing: {$f}");
        });
    }
});

// ── Admin API ─────────────────────────────────────────────────────────────────
WDTest::describe('Admin API Files', function () {
    $files = [
        'api/admin/audit_log.php', 'api/admin/system_health.php',
        'api/admin/global_settings.php', 'api/admin/db_manager.php',
        'api/admin/multi_user.php', 'api/admin/perf_monitor.php',
        'api/admin/data_versioning.php',
    ];
    foreach ($files as $f) {
        WDTest::it("Exists: {$f}", function () use ($f) {
            assert_true(file_exists(APP_ROOT . '/' . $f), "Missing: {$f}");
        });
    }
});

// ── External API ──────────────────────────────────────────────────────────────
WDTest::describe('External API Files', function () {
    $files = [
        'api/external/api_key_manager.php',
        'api/external/rest_gateway.php',
    ];
    foreach ($files as $f) {
        WDTest::it("Exists: {$f}", function () use ($f) {
            assert_true(file_exists(APP_ROOT . '/' . $f), "Missing: {$f}");
        });
    }
});

// ── MF API Files ──────────────────────────────────────────────────────────────
WDTest::describe('MF API Files', function () {
    $files = [
        'api/mutual_funds/mf_list.php', 'api/mutual_funds/mf_add.php',
        'api/mutual_funds/mf_import_csv.php', 'api/mutual_funds/live_nav.php',
        'api/nav/update_amfi.php',
    ];
    foreach ($files as $f) {
        WDTest::it("Exists: {$f}", function () use ($f) {
            assert_true(file_exists(APP_ROOT . '/' . $f), "Missing: {$f}");
        });
    }
});

// ── Asset Module Files ────────────────────────────────────────────────────────
WDTest::describe('Asset Module Files', function () {
    $files = [
        'api/banks/banks.php', 'api/fd/fd_list.php',
        'api/savings/savings_list.php', 'api/nps/nps_list.php',
        'api/stocks/stocks_list.php',
    ];
    foreach ($files as $f) {
        WDTest::it("Exists: {$f}", function () use ($f) {
            assert_true(file_exists(APP_ROOT . '/' . $f), "Missing: {$f}");
        });
    }
});

// ── Template Pages ────────────────────────────────────────────────────────────
WDTest::describe('Template Pages', function () {
    $pages = [
        'templates/pages/dashboard.php', 'templates/pages/mf_holdings.php',
        'templates/pages/fd.php', 'templates/pages/savings.php',
        'templates/pages/banks.php', 'templates/pages/stocks.php',
        'templates/pages/admin_db.php', 'templates/pages/admin_health.php',
        'templates/pages/admin_settings.php', 'templates/pages/admin_users.php',
    ];
    foreach ($pages as $f) {
        WDTest::it("Exists: {$f}", function () use ($f) {
            assert_true(file_exists(APP_ROOT . '/' . $f), "Missing: {$f}");
        });
    }
});

// ── Cron Files ────────────────────────────────────────────────────────────────
WDTest::describe('Cron Files', function () {
    $files = [
        'cron/update_nav_daily.php', 'cron/nav_auto_update.php',
        'cron/fd_maturity_alert.php', 'cron/calculate_returns.php',
    ];
    foreach ($files as $f) {
        WDTest::it("Exists: {$f}", function () use ($f) {
            assert_true(file_exists(APP_ROOT . '/' . $f), "Missing: {$f}");
        });
    }
});

// ── PHP Guard Check ───────────────────────────────────────────────────────────
WDTest::describe('PHP Guard (WEALTHDASH check)', function () {
    $checkFiles = [
        'api/admin/audit_log.php', 'api/admin/system_health.php',
        'api/admin/multi_user.php', 'api/banks/banks.php',
        'includes/auth_check.php', 'includes/helpers.php',
    ];
    foreach ($checkFiles as $f) {
        WDTest::it("Guard present: {$f}", function () use ($f) {
            $full = APP_ROOT . '/' . $f;
            if (!file_exists($full)) { return; } // file test above will catch this
            $content = file_get_contents($full);
            assert_contains("defined('WEALTHDASH')", $content,
                "Missing WEALTHDASH guard in {$f}");
        });
    }
});
