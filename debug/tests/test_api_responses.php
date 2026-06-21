<?php
/**
 * WealthDash — Integration Tests: API Responses [t411]
 * File: debug/tests/test_api_responses.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

// Helper: call internal API action (simulated — checks router file exists + action handled)
function api_check(string $action, array $get = []): array {
    $url = APP_URL . '/api/router.php?action=' . urlencode($action);
    if ($get) $url .= '&' . http_build_query($get);
    $ctx = stream_context_create([
        'http' => [
            'method'         => 'GET',
            'timeout'        => 5,
            'ignore_errors'  => true,
            'header'         => [
                'Cookie: ' . session_name() . '=' . session_id(),
                'X-Requested-With: XMLHttpRequest',
            ],
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $data = $body ? json_decode($body, true) : null;
    return [
        'ok'   => is_array($data),
        'has_success' => isset($data['success']),
        'data' => $data,
        'raw'  => substr($body ?? '', 0, 200),
    ];
}

// ── Router File ───────────────────────────────────────────────────────────────
WDTest::describe('Router', function () {
    WDTest::it('router.php exists', function () {
        assert_true(file_exists(APP_ROOT . '/api/router.php'));
    });

    WDTest::it('router.php is readable', function () {
        assert_true(is_readable(APP_ROOT . '/api/router.php'));
    });

    WDTest::it('Unknown action returns JSON with success=false', function () {
        $r = api_check('__nonexistent_action_xyz__');
        assert_true($r['ok'], 'Response must be valid JSON');
        assert_true($r['has_success']);
        assert_false($r['data']['success'] ?? true);
    });
});

// ── Read-only API Actions ─────────────────────────────────────────────────────
WDTest::describe('API: Dashboard', function () {
    WDTest::it('get_dashboard_data returns JSON', function () {
        $r = api_check('get_dashboard_data');
        assert_true($r['ok'], 'get_dashboard_data must return JSON. Got: ' . $r['raw']);
        assert_true($r['has_success']);
    });
});

WDTest::describe('API: Mutual Funds', function () {
    $actions = ['mf_list', 'sip_list'];
    foreach ($actions as $a) {
        WDTest::it("{$a} returns JSON", function () use ($a) {
            $r = api_check($a);
            assert_true($r['ok'], "{$a} did not return JSON. Got: " . $r['raw']);
            assert_true($r['has_success']);
        });
    }
});

WDTest::describe('API: FD / Savings', function () {
    foreach (['fd_list', 'savings_list'] as $a) {
        WDTest::it("{$a} returns JSON", function () use ($a) {
            $r = api_check($a);
            assert_true($r['ok'], "{$a} must return JSON");
        });
    }
});

WDTest::describe('API: Banks', function () {
    WDTest::it('bank_list returns JSON', function () {
        $r = api_check('bank_list');
        assert_true($r['ok'], 'bank_list must return JSON');
        assert_true($r['has_success']);
    });

    WDTest::it('bank_summary returns JSON with grand_total', function () {
        $r = api_check('bank_summary');
        assert_true($r['ok']);
        if ($r['data']['success'] ?? false) {
            assert_keys(['grand_total'], $r['data']['data'] ?? []);
        }
    });
});

WDTest::describe('API: Admin', function () {
    WDTest::it('health_ping returns JSON', function () {
        $r = api_check('health_ping');
        assert_true($r['ok'], 'health_ping must return JSON');
    });

    WDTest::it('al_list returns JSON', function () {
        $r = api_check('al_list');
        assert_true($r['ok'], 'al_list must return JSON');
    });

    WDTest::it('perf_live returns JSON', function () {
        $r = api_check('perf_live');
        assert_true($r['ok'], 'perf_live must return JSON');
    });

    WDTest::it('gs_list returns JSON', function () {
        $r = api_check('gs_list');
        assert_true($r['ok'], 'gs_list must return JSON');
    });

    WDTest::it('dbm_tables returns JSON', function () {
        $r = api_check('dbm_tables');
        assert_true($r['ok'], 'dbm_tables must return JSON');
    });
});

WDTest::describe('API: External API Manager', function () {
    WDTest::it('extapi_list returns JSON', function () {
        $r = api_check('extapi_list');
        assert_true($r['ok']);
    });

    WDTest::it('extapi_scopes returns scopes', function () {
        $r = api_check('extapi_scopes');
        assert_true($r['ok']);
        if ($r['data']['success'] ?? false) {
            assert_not_empty($r['data']['data']['scopes'] ?? [], 'Scopes must not be empty');
        }
    });
});

// ── JSON Structure ────────────────────────────────────────────────────────────
WDTest::describe('API Response Structure', function () {
    WDTest::it('All responses have success key', function () {
        $actions = ['fd_list', 'savings_list', 'bank_list', 'health_ping'];
        foreach ($actions as $a) {
            $r = api_check($a);
            assert_true(
                isset($r['data']['success']),
                "{$a} response missing 'success' key"
            );
        }
    });

    WDTest::it('Failed actions have message key', function () {
        $r = api_check('__bad_action__');
        if (isset($r['data']['success']) && !$r['data']['success']) {
            assert_true(isset($r['data']['message']), "Error response must have 'message'");
        }
    });
});
