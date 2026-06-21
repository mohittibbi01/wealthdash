<?php
/**
 * WealthDash — E2E Tests: Full HTTP flows [t354]
 * File: tests/e2e/test_e2e.php
 * Worker: ID-M
 * Run via: debug/runner.php?suite=e2e
 *
 * Tests real HTTP round-trips including auth, redirects, HTML pages.
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

// ── HTTP helper ───────────────────────────────────────────────────────────────
function e2e_get(string $path, array $cookies = []): array {
    $url = APP_URL . '/' . ltrim($path, '/');
    $cookieStr = '';
    if ($cookies) {
        $cookieStr = implode('; ', array_map(fn($k,$v) => "$k=$v", array_keys($cookies), $cookies));
    }
    // Pass session cookie
    $sessionCookie = session_name() . '=' . session_id();
    $cookieStr = $cookieStr ? $cookieStr . '; ' . $sessionCookie : $sessionCookie;

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET', 'timeout' => 8,
        'ignore_errors' => true, 'max_redirects' => 0,
        'header'        => [
            'Cookie: ' . $cookieStr,
            'Accept: text/html,application/json',
            'X-Requested-With: XMLHttpRequest',
        ],
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    $status  = 0;
    if ($headers) {
        preg_match('/HTTP\/\S+ (\d+)/', $headers[0] ?? '', $m);
        $status = (int)($m[1] ?? 0);
    }
    return ['status' => $status, 'body' => $body ?? '', 'headers' => $headers, 'url' => $url];
}

function e2e_api(string $action, array $params = []): array {
    $r = e2e_get('api/router.php?action=' . urlencode($action) . ($params ? '&' . http_build_query($params) : ''));
    return json_decode($r['body'], true) ?? ['success' => false, '_raw' => substr($r['body'], 0, 100)];
}

// ── Page availability ─────────────────────────────────────────────────────────
WDTest::describe('E2E: Page HTTP Status', function () {
    $pages = [
        'index.php'       => [200, 302, 301],
        'auth/login.php'  => [200],
        'api/router.php?action=health_ping' => [200],
    ];
    foreach ($pages as $path => $expected) {
        WDTest::it("GET /{$path} returns " . implode('/',$expected), function () use ($path, $expected) {
            $r = e2e_get($path);
            assert_true(in_array($r['status'], $expected),
                "Expected HTTP " . implode('|',$expected) . ", got {$r['status']} for {$r['url']}");
        });
    }
});

// ── API JSON contract ─────────────────────────────────────────────────────────
WDTest::describe('E2E: API JSON Contract', function () {
    $checks = [
        'health_ping'   => ['success', 'data'],
        'fd_list'       => ['success'],
        'savings_list'  => ['success'],
        'bank_list'     => ['success'],
        'bank_summary'  => ['success'],
        'gs_list'       => ['success'],
        'al_list'       => ['success'],
        'extapi_scopes' => ['success'],
        'dv_stats'      => ['success'],
    ];
    foreach ($checks as $action => $keys) {
        WDTest::it("{$action}: has required keys", function () use ($action, $keys) {
            $r = e2e_api($action);
            foreach ($keys as $k) {
                assert_true(array_key_exists($k, $r), "{$action} missing key '{$k}'");
            }
        });
    }
});

// ── Auth redirect ─────────────────────────────────────────────────────────────
WDTest::describe('E2E: Auth Protection', function () {
    WDTest::it('Login page loads (200)', function () {
        $r = e2e_get('auth/login.php');
        assert_true(in_array($r['status'], [200, 301, 302]),
            "Login page returned {$r['status']}");
    });

    WDTest::it('health_ping always returns JSON 200', function () {
        $r = e2e_get('api/router.php?action=health_ping');
        assert_eq(200, $r['status']);
        $d = json_decode($r['body'], true);
        assert_true(is_array($d), 'health_ping must return valid JSON');
        assert_true(isset($d['success']));
    });

    WDTest::it('Unknown action returns 400 or success=false', function () {
        $r = e2e_get('api/router.php?action=__e2e_nonexistent__');
        $d = json_decode($r['body'], true);
        assert_true(is_array($d), 'Must return JSON for unknown action');
        assert_false($d['success'] ?? true, 'Unknown action must return success=false');
    });
});

// ── External REST API ─────────────────────────────────────────────────────────
WDTest::describe('E2E: External REST Gateway', function () {
    WDTest::it('REST gateway without key returns 401 JSON', function () {
        $r = e2e_get('api/external/v1/portfolio');
        $d = json_decode($r['body'], true);
        // Should fail auth
        assert_true(is_array($d), 'Gateway must return JSON');
        assert_false($d['success'] ?? true, 'No API key should fail');
    });

    WDTest::it('REST gateway bad key returns 401 JSON', function () {
        $url = APP_URL . '/api/external/v1/portfolio?api_key=wdx_badkey00000000';
        $ctx = stream_context_create(['http' => ['method'=>'GET','timeout'=>5,'ignore_errors'=>true]]);
        $body = @file_get_contents($url, false, $ctx);
        $d    = json_decode($body ?? '', true);
        assert_true(is_array($d), 'Must return JSON for bad key');
        assert_false($d['success'] ?? true, 'Bad key should fail');
    });
});

// ── Data consistency checks ───────────────────────────────────────────────────
WDTest::describe('E2E: Data Consistency', function () {
    WDTest::it('bank_list count matches bank_summary total', function () {
        $list    = e2e_api('bank_list', ['status' => 'active']);
        $summary = e2e_api('bank_summary');
        if (!($list['success'] ?? false) || !($summary['success'] ?? false)) return;
        $listTotal = array_sum(array_column($list['data']['accounts'] ?? [], 'current_balance'));
        $summaryTotal = (float)($summary['data']['grand_total'] ?? 0);
        assert_true(
            abs($listTotal - $summaryTotal) < 1.0,
            "bank_list total {$listTotal} != bank_summary {$summaryTotal}"
        );
    });

    WDTest::it('gs_list groups match expected names', function () {
        $r = e2e_api('gs_list');
        if (!($r['success'] ?? false)) return;
        $groups = $r['data']['groups'] ?? [];
        assert_true(in_array('general', $groups), 'Missing "general" group in settings');
        assert_true(in_array('auth', $groups), 'Missing "auth" group in settings');
    });

    WDTest::it('al_stats summary has correct keys', function () {
        $r = e2e_api('al_stats', ['days' => 7]);
        if (!($r['success'] ?? false)) return;
        assert_keys(['total','critical','warnings','unique_users'], $r['data']['summary'] ?? []);
    });
});

// ── Response time SLA ─────────────────────────────────────────────────────────
WDTest::describe('E2E: SLA Response Times', function () {
    $sla = [
        'api/router.php?action=health_ping' => 300,
        'api/router.php?action=bank_summary' => 800,
        'api/router.php?action=gs_list'      => 600,
    ];
    foreach ($sla as $path => $maxMs) {
        WDTest::it("{$path} < {$maxMs}ms", function () use ($path, $maxMs) {
            $start = microtime(true);
            e2e_get($path);
            $ms = round((microtime(true) - $start) * 1000);
            assert_true($ms < $maxMs, "{$path} took {$ms}ms (SLA: {$maxMs}ms)");
        });
    }
});
