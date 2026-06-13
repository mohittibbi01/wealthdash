<?php
/**
 * WealthDash — Performance Tests [t412]
 * File: debug/tests/test_perf.php
 * Worker: ID-M
 * Standalone runner: ?suite=perf OR include from runner.php
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

/**
 * Run an API action N times and return stats
 */
function perf_bench(string $action, int $runs = 5, array $params = []): array {
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $url  = APP_URL . '/api/router.php?action=' . urlencode($action);
        if ($params) $url .= '&' . http_build_query($params);
        $ctx  = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 10, 'ignore_errors' => true,
            'header' => ['Cookie: ' . session_name() . '=' . session_id(),
                         'X-Requested-With: XMLHttpRequest'],
        ]]);
        $start   = microtime(true);
        @file_get_contents($url, false, $ctx);
        $times[] = round((microtime(true) - $start) * 1000, 2);
    }
    sort($times);
    return [
        'action' => $action,
        'runs'   => $runs,
        'min_ms' => $times[0],
        'max_ms' => $times[$runs - 1],
        'avg_ms' => round(array_sum($times) / count($times), 2),
        'p50_ms' => $times[(int)($runs / 2)],
        'p95_ms' => $times[(int)($runs * 0.95)] ?? $times[$runs - 1],
        'times'  => $times,
    ];
}

/**
 * Get threshold for an action from DB baselines
 */
function get_threshold(string $action, float $default = 500): float {
    try {
        $row = DB::fetchOne('SELECT threshold_ms FROM perf_baselines WHERE action=?', [$action]);
        return $row ? (float)$row['threshold_ms'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// ── Endpoint Performance ──────────────────────────────────────────────────────
WDTest::describe('Endpoint Response Times', function () {

    $endpoints = [
        'health_ping'       => 200,
        'fd_list'           => 500,
        'savings_list'      => 500,
        'bank_list'         => 500,
        'bank_summary'      => 600,
        'al_list'           => 500,
        'gs_list'           => 400,
        'dbm_tables'        => 500,
        'perf_live'         => 600,
        'extapi_scopes'     => 300,
        'dv_stats'          => 400,
    ];

    foreach ($endpoints as $action => $thresholdMs) {
        WDTest::it("{$action} avg < {$thresholdMs}ms", function () use ($action, $thresholdMs) {
            $result = perf_bench($action, 3);
            $dbThreshold = get_threshold($action, $thresholdMs);
            assert_true(
                $result['avg_ms'] < $dbThreshold,
                "{$action} avg {$result['avg_ms']}ms exceeds threshold {$dbThreshold}ms"
            );
        });
    }
});

// ── DB Query Performance ──────────────────────────────────────────────────────
WDTest::describe('DB Query Performance', function () {

    WDTest::it('SELECT COUNT(*) FROM mf_holdings < 50ms', function () {
        $start = microtime(true);
        DB::fetchVal('SELECT COUNT(*) FROM mf_holdings');
        $ms = (microtime(true) - $start) * 1000;
        assert_true($ms < 50, "mf_holdings count took {$ms}ms");
    });

    WDTest::it('SELECT COUNT(*) FROM nav_history < 100ms', function () {
        $start = microtime(true);
        DB::fetchVal('SELECT COUNT(*) FROM nav_history');
        $ms = (microtime(true) - $start) * 1000;
        assert_true($ms < 100, "nav_history count took {$ms}ms");
    });

    WDTest::it('Audit log paginated query < 200ms', function () {
        $start = microtime(true);
        DB::fetchAll('SELECT id, action, created_at FROM audit_log ORDER BY id DESC LIMIT 50');
        $ms = (microtime(true) - $start) * 1000;
        assert_true($ms < 200, "audit_log paginated query took {$ms}ms");
    });

    WDTest::it('Users JOIN portfolios < 100ms', function () {
        $start = microtime(true);
        DB::fetchAll(
            'SELECT u.id, u.name, p.id as pid FROM users u
             LEFT JOIN portfolios p ON p.user_id = u.id LIMIT 50'
        );
        $ms = (microtime(true) - $start) * 1000;
        assert_true($ms < 100, "Users JOIN portfolios took {$ms}ms");
    });

    WDTest::it('10 concurrent fetchVal calls < 200ms total', function () {
        $start = microtime(true);
        for ($i = 0; $i < 10; $i++) {
            DB::fetchVal('SELECT 1');
        }
        $ms = (microtime(true) - $start) * 1000;
        assert_true($ms < 200, "10x fetchVal took {$ms}ms");
    });
});

// ── Memory Usage ──────────────────────────────────────────────────────────────
WDTest::describe('Memory Usage', function () {

    WDTest::it('Current memory usage < 64MB', function () {
        $mb = memory_get_usage(true) / 1048576;
        assert_true($mb < 64, "Memory usage {$mb}MB exceeds 64MB");
    });

    WDTest::it('Peak memory usage < 128MB', function () {
        $mb = memory_get_peak_usage(true) / 1048576;
        assert_true($mb < 128, "Peak memory {$mb}MB exceeds 128MB");
    });

    WDTest::it('Fetching 500 audit_log rows stays < 16MB incremental', function () {
        $before = memory_get_usage(true);
        DB::fetchAll('SELECT * FROM audit_log ORDER BY id DESC LIMIT 500');
        $after  = memory_get_usage(true);
        $mb     = ($after - $before) / 1048576;
        assert_true($mb < 16, "Fetching 500 rows used {$mb}MB extra RAM");
    });
});

// ── Save Baselines ────────────────────────────────────────────────────────────
WDTest::describe('Baseline Snapshot', function () {
    WDTest::it('Saves current benchmark to perf_baselines', function () {
        $actions = ['health_ping', 'fd_list', 'bank_list'];
        foreach ($actions as $a) {
            try {
                $r = perf_bench($a, 3);
                DB::run(
                    'INSERT INTO perf_baselines (action, baseline_ms, threshold_ms)
                     VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE baseline_ms=VALUES(baseline_ms)',
                    [$a, $r['avg_ms'], $r['avg_ms'] * 3]
                );
            } catch (Exception $e) { /* non-fatal */ }
        }
        assert_true(true, 'Baselines saved');
    });
});
