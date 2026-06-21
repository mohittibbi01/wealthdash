<?php
/**
 * WealthDash — Unit Tests: Helpers + Cache + Auth Logic [t352]
 * File: tests/unit/test_helpers.php
 * Worker: ID-M
 * Run via: debug/runner.php?suite=unit
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

// ── clean() ──────────────────────────────────────────────────────────────────
WDTest::describe('clean()', function () {
    WDTest::it('strips HTML tags', function () {
        assert_false(str_contains(clean('<b>bold</b>'), '<b>'));
    });
    WDTest::it('trims whitespace', function () {
        assert_eq('hello', clean('  hello  '));
    });
    WDTest::it('handles empty string', function () {
        assert_eq('', clean(''));
    });
    WDTest::it('handles special chars safely', function () {
        $r = clean("O'Reilly & \"Co\"");
        assert_true(strlen($r) > 0);
    });
    WDTest::it('strips script tag', function () {
        $r = clean('<script>alert(1)</script>text');
        assert_false(str_contains($r, '<script>'));
        assert_contains('text', $r);
    });
});

// ── env() ────────────────────────────────────────────────────────────────────
WDTest::describe('env()', function () {
    WDTest::it('returns default for missing key', function () {
        assert_eq('fallback', env('__WD_MISSING_KEY__', 'fallback'));
    });
    WDTest::it('casts true string to bool', function () {
        $_ENV['__WD_BOOL_T'] = 'true';
        assert_true(env('__WD_BOOL_T') === true);
        unset($_ENV['__WD_BOOL_T']);
    });
    WDTest::it('casts false string to bool', function () {
        $_ENV['__WD_BOOL_F'] = 'false';
        assert_true(env('__WD_BOOL_F') === false);
        unset($_ENV['__WD_BOOL_F']);
    });
    WDTest::it('casts yes/no', function () {
        $_ENV['__WD_YES'] = 'yes'; $_ENV['__WD_NO'] = 'no';
        assert_true(env('__WD_YES') === true);
        assert_true(env('__WD_NO') === false);
        unset($_ENV['__WD_YES'], $_ENV['__WD_NO']);
    });
    WDTest::it('returns string value unchanged', function () {
        $_ENV['__WD_STR'] = 'hello_world';
        assert_eq('hello_world', env('__WD_STR'));
        unset($_ENV['__WD_STR']);
    });
});

// ── Constants ─────────────────────────────────────────────────────────────────
WDTest::describe('Constants', function () {
    $required = [
        'WEALTHDASH','APP_ROOT','APP_URL','APP_ENV','APP_NAME',
        'ROLE_ADMIN','ROLE_MEMBER','CURRENCY','CURRENCY_CODE',
        'PAGE_SIZE','DATE_DISPLAY','DATE_DB',
        'EQUITY_LTCG_RATE','EQUITY_STCG_RATE','EQUITY_LTCG_DAYS',
        'EQUITY_LTCG_EXEMPTION','FD_TDS_RATE','FD_TDS_THRESHOLD',
        'ELSS_LOCKIN_DAYS','DEBT_LTCG_DAYS','FY_START_MONTH',
        'AMFI_NAV_URL','AMFI_NAV_HISTORY_URL',
    ];
    foreach ($required as $c) {
        WDTest::it("defined('{$c}')", function () use ($c) {
            assert_true(defined($c), "Constant {$c} not defined");
        });
    }
    WDTest::it('ROLE_ADMIN is "admin"', function () { assert_eq('admin', ROLE_ADMIN); });
    WDTest::it('CURRENCY is ₹', function () { assert_eq('₹', CURRENCY); });
    WDTest::it('PAGE_SIZE is numeric', function () { assert_true(is_int(PAGE_SIZE) && PAGE_SIZE > 0); });
    WDTest::it('FY_START_MONTH is 4 (April)', function () { assert_eq(4, FY_START_MONTH); });
});

// ── DB Class ──────────────────────────────────────────────────────────────────
WDTest::describe('DB Class Methods', function () {
    WDTest::it('DB::fetchVal returns scalar', function () {
        assert_eq('1', (string) DB::fetchVal('SELECT 1'));
    });
    WDTest::it('DB::fetchOne returns assoc array', function () {
        $r = DB::fetchOne('SELECT 1 as n, 2 as m');
        assert_keys(['n','m'], $r);
    });
    WDTest::it('DB::fetchAll returns indexed array', function () {
        $r = DB::fetchAll('SELECT 1 as n UNION SELECT 2 as n ORDER BY n');
        assert_count(2, $r);
        assert_eq('1', (string)$r[0]['n']);
        assert_eq('2', (string)$r[1]['n']);
    });
    WDTest::it('DB::run returns PDOStatement', function () {
        $stmt = DB::run('SELECT 1');
        assert_instanceof('PDOStatement', $stmt);
    });
    WDTest::it('DB::rollback on uncommitted txn is safe', function () {
        DB::beginTransaction();
        DB::rollback(); // should not throw
        assert_true(true);
    });
    WDTest::it('DB prepared statement with params', function () {
        $r = DB::fetchVal('SELECT ? + ?', [3, 7]);
        assert_eq('10', (string)$r);
    });
});

// ── WdCache ───────────────────────────────────────────────────────────────────
WDTest::describe('WdCache', function () {
    WDTest::it('class exists', function () {
        assert_true(class_exists('WdCache'));
    });
    WDTest::it('remember() returns value', function () {
        if (!class_exists('WdCache')) return;
        $v = WdCache::remember('wd_test_unit_' . time(), fn() => 42, 60);
        assert_eq(42, $v);
    });
    WDTest::it('remember() returns cached value on second call', function () {
        if (!class_exists('WdCache')) return;
        $key = 'wd_test_cache_' . mt_rand(1000,9999);
        WdCache::remember($key, fn() => 'first', 60);
        $v = WdCache::remember($key, fn() => 'second', 60);
        assert_eq('first', $v, 'Cache should return stored value');
    });
    WDTest::it('invalidate() clears cache', function () {
        if (!class_exists('WdCache')) return;
        $key = 'wd_inv_' . mt_rand(1000,9999);
        WdCache::remember($key, fn() => 'cached', 60, ['tag_test']);
        WdCache::invalidate('tag_test');
        $v = WdCache::remember($key, fn() => 'fresh', 60);
        // After invalidation fresh value should be returned
        assert_eq('fresh', $v);
    });
});

// ── Auth Functions ────────────────────────────────────────────────────────────
WDTest::describe('Auth Functions', function () {
    WDTest::it('is_logged_in() returns bool', function () {
        assert_true(is_bool(is_logged_in()));
    });
    WDTest::it('is_admin() returns bool', function () {
        assert_true(is_bool(is_admin()));
    });
    WDTest::it('get_user_portfolio_id() returns int', function () {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (!$userId) return;
        $id = get_user_portfolio_id($userId);
        assert_true(is_int($id));
    });
    WDTest::it('can_access_portfolio() returns bool', function () {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (!$userId) return;
        $pid = get_user_portfolio_id($userId);
        if (!$pid) return;
        assert_true(is_bool(can_access_portfolio($pid, $userId)));
    });
});

// ── CSRF ──────────────────────────────────────────────────────────────────────
WDTest::describe('CSRF Token', function () {
    WDTest::it('csrf_token() returns non-empty string', function () {
        if (!function_exists('csrf_token')) return;
        $tok = csrf_token();
        assert_true(is_string($tok) && strlen($tok) >= 32);
    });
    WDTest::it('csrf_token() returns same token on repeat call', function () {
        if (!function_exists('csrf_token')) return;
        assert_eq(csrf_token(), csrf_token());
    });
});
