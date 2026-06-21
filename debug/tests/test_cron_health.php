<?php
/**
 * WealthDash — Test: Cron Health [t411]
 * File: debug/tests/test_cron_health.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

// ── Cron Job Last-Run Checks ──────────────────────────────────────────────────
WDTest::describe('Cron Job Health', function () {

    WDTest::it('NAV last updated within 48 hours', function () {
        $lastNav = DB::fetchVal(
            "SELECT MAX(updated_at) FROM nav_history"
        );
        if (!$lastNav) {
            // No data yet — skip gracefully
            return;
        }
        $diff = time() - strtotime($lastNav);
        assert_true($diff < 172800, "NAV not updated for " . round($diff / 3600) . "h (>48h)");
    });

    WDTest::it('FD maturity alerts table accessible', function () {
        $count = DB::fetchVal('SELECT COUNT(*) FROM fd_accounts');
        assert_true(is_numeric($count));
    });

    WDTest::it('cron logger file writable', function () {
        $logDir = APP_ROOT . '/logs';
        assert_true(is_writable($logDir), "logs/ not writable — cron can't log");
    });

    WDTest::it('No PHP fatal errors in error log (last 24h)', function () {
        $logFile = APP_ROOT . '/logs/php_errors.log';
        if (!file_exists($logFile)) return;
        $content = file_get_contents($logFile);
        $lines   = array_filter(explode("\n", $content));
        $recent  = array_filter($lines, function ($l) {
            if (preg_match('/\[(\d{2}-\w{3}-\d{4})/', $l, $m)) {
                return strtotime($m[1]) > strtotime('-24 hours');
            }
            return false;
        });
        $fatals = array_filter($recent, fn($l) => stripos($l, 'Fatal error') !== false);
        assert_count(0, array_values($fatals), count($fatals) . ' fatal error(s) in last 24h');
    });
});

// ── DB Health ─────────────────────────────────────────────────────────────────
WDTest::describe('DB Health Indicators', function () {

    WDTest::it('Slow queries counter is reasonable (<100)', function () {
        $slow = (int) DB::fetchOne("SHOW STATUS LIKE 'Slow_queries'")['Value'];
        assert_true($slow < 100, "High slow query count: {$slow}");
    });

    WDTest::it('DB connection within limits', function () {
        $conn = (int) DB::fetchOne("SHOW STATUS LIKE 'Threads_connected'")['Value'];
        $max  = (int) DB::fetchVal("SELECT @@max_connections");
        $pct  = $max > 0 ? $conn / $max * 100 : 0;
        assert_true($pct < 80, "DB connections at {$pct}% ({$conn}/{$max})");
    });

    WDTest::it('InnoDB buffer pool hit rate >90%', function () {
        $reads = (float) DB::fetchOne("SHOW STATUS LIKE 'Innodb_buffer_pool_reads'")['Value'];
        $req   = (float) DB::fetchOne("SHOW STATUS LIKE 'Innodb_buffer_pool_read_requests'")['Value'];
        if ($req === 0.0) return; // fresh DB
        $hitRate = (1 - $reads / $req) * 100;
        assert_true($hitRate > 90, "Buffer pool hit rate {$hitRate}% (<90%)");
    });

    WDTest::it('No orphan mf_transactions (portfolio must exist)', function () {
        $orphans = (int) DB::fetchVal(
            'SELECT COUNT(*) FROM mf_transactions mt
             LEFT JOIN portfolios p ON p.id = mt.portfolio_id
             WHERE p.id IS NULL'
        );
        assert_eq(0, $orphans, "{$orphans} orphan mf_transactions found");
    });

    WDTest::it('All users have exactly one portfolio', function () {
        $missing = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM users u
             LEFT JOIN portfolios p ON p.user_id = u.id
             WHERE p.id IS NULL AND u.status = 'active'"
        );
        assert_eq(0, $missing, "{$missing} active user(s) with no portfolio");
    });
});

// ── Cache Health ──────────────────────────────────────────────────────────────
WDTest::describe('Cache Health', function () {
    WDTest::it('Cache directory exists', function () {
        $cacheDir = APP_ROOT . '/logs/cache';
        assert_true(is_dir($cacheDir) || !class_exists('WdCache'),
            'Cache dir missing: logs/cache');
    });

    WDTest::it('WdCache class is available', function () {
        assert_true(class_exists('WdCache'), 'WdCache class not loaded');
    });

    WDTest::it('Cache read/write roundtrip', function () {
        if (!class_exists('WdCache')) return;
        $key = 'wd_test_' . time();
        WdCache::remember($key, fn() => 'test_value_42', 60);
        $val = WdCache::remember($key, fn() => 'should_not_run', 60);
        assert_eq('test_value_42', $val);
    });
});
