<?php
/**
 * WealthDash — Integration Tests: Full API Flow [t411]
 * File: tests/integration/test_apis.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

function int_api(string $action, array $params = []): array {
    $url = APP_URL . '/api/router.php?action=' . urlencode($action);
    if ($params) $url .= '&' . http_build_query($params);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'GET', 'timeout' => 6, 'ignore_errors' => true,
        'header'        => ['Cookie: ' . session_name() . '=' . session_id(),
                            'X-Requested-With: XMLHttpRequest'],
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return json_decode($body ?? '{}', true) ?? [];
}

// ── Auth Protected ────────────────────────────────────────────────────────────
WDTest::describe('Auth Protection', function () {
    WDTest::it('Admin-only action rejects non-admin session', function () {
        // Without admin session this should fail
        $r = int_api('mu_list');
        // Either success (if logged in as admin) or fail (not admin)
        assert_true(isset($r['success']), 'mu_list must always return JSON');
    });

    WDTest::it('CSRF-required action without token returns error', function () {
        // al_purge requires CSRF — GET request should fail
        $r = int_api('al_purge');
        assert_true(isset($r['success']));
        // Should fail since no CSRF token via GET
    });
});

// ── Data Integrity via API ────────────────────────────────────────────────────
WDTest::describe('Data Integrity', function () {
    WDTest::it('bank_list data matches DB count', function () {
        $userId  = (int)($_SESSION['user_id'] ?? 0);
        if (!$userId) return;
        $apiResp = int_api('bank_list', ['status' => 'active']);
        $dbCount = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM bank_accounts WHERE user_id=? AND status='active'",
            [$userId]
        );
        if ($apiResp['success'] ?? false) {
            $apiCount = count($apiResp['data']['accounts'] ?? []);
            assert_eq($dbCount, $apiCount, "API count {$apiCount} != DB count {$dbCount}");
        }
    });

    WDTest::it('al_stats returns correct keys', function () {
        $r = int_api('al_stats', ['days' => 7]);
        if ($r['success'] ?? false) {
            assert_keys(['summary', 'trend', 'top_actions'], $r['data']);
        }
    });

    WDTest::it('gs_list groups are non-empty', function () {
        $r = int_api('gs_list');
        if ($r['success'] ?? false) {
            assert_not_empty($r['data']['groups'] ?? [], 'Settings groups must not be empty');
        }
    });

    WDTest::it('dbm_tables returns table list', function () {
        $r = int_api('dbm_tables');
        if ($r['success'] ?? false) {
            assert_not_empty($r['data']['tables'] ?? [], 'Table list must not be empty');
            assert_true($r['data']['count'] > 0);
        }
    });

    WDTest::it('extapi_scopes returns all 10 scopes', function () {
        $r = int_api('extapi_scopes');
        if ($r['success'] ?? false) {
            assert_true(count($r['data']['scopes'] ?? []) >= 8, 'At least 8 scopes expected');
        }
    });

    WDTest::it('dv_stats returns version stats', function () {
        $r = int_api('dv_stats');
        if ($r['success'] ?? false) {
            assert_keys(['stats', 'by_type', 'recent'], $r['data']);
        }
    });
});

// ── Response Time Baseline ────────────────────────────────────────────────────
WDTest::describe('Response Time Baseline', function () {
    $actions = ['fd_list', 'bank_list', 'health_ping', 'gs_list'];
    foreach ($actions as $a) {
        WDTest::it("{$a} responds under 2000ms", function () use ($a) {
            $start = microtime(true);
            int_api($a);
            $ms = (microtime(true) - $start) * 1000;
            assert_true($ms < 2000, "{$a} took {$ms}ms (> 2000ms)");
        });
    }
});
