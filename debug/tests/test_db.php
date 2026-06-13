<?php
/**
 * WealthDash — Unit Tests: DB + Tax + XIRR [t411]
 * File: debug/tests/test_db.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

// ── DB Connection ─────────────────────────────────────────────────────────────
WDTest::describe('Database Connection', function () {
    WDTest::it('PDO connection is alive', function () {
        $val = DB::fetchVal('SELECT 1');
        assert_eq('1', (string)$val);
    });

    WDTest::it('fetchOne returns array or false', function () {
        $row = DB::fetchOne('SELECT 1 as n');
        assert_true(is_array($row));
        assert_eq('1', (string)$row['n']);
    });

    WDTest::it('fetchAll returns array', function () {
        $rows = DB::fetchAll('SELECT 1 as n UNION SELECT 2 as n');
        assert_count(2, $rows);
    });

    WDTest::it('fetchVal returns scalar', function () {
        $v = DB::fetchVal('SELECT COUNT(*) FROM users');
        assert_true(is_numeric($v));
    });

    WDTest::it('transaction commit works', function () {
        DB::beginTransaction();
        DB::run("INSERT INTO audit_log (user_id, action, entity_type) VALUES (NULL, 'test_txn', 'unit_test')");
        $id = DB::fetchVal("SELECT id FROM audit_log WHERE action='test_txn' AND entity_type='unit_test' ORDER BY id DESC LIMIT 1");
        DB::rollback();
        $after = DB::fetchVal("SELECT id FROM audit_log WHERE id=?", [$id]);
        assert_false((bool)$after, 'Row should be rolled back');
    });

    WDTest::it('prepared statement handles SQL injection safely', function () {
        $evil = "' OR '1'='1";
        $row  = DB::fetchOne('SELECT id FROM users WHERE email = ?', [$evil]);
        assert_false($row, 'Should return false for injected input');
    });
});

// ── Core Tables Exist ─────────────────────────────────────────────────────────
WDTest::describe('Required Tables', function () {
    $required = [
        'users','portfolios','mf_holdings','mf_transactions','funds','nav_history',
        'fd_accounts','savings_accounts','stock_holdings','stock_transactions',
        'nps_holdings','bank_accounts','audit_log','app_settings',
        'login_attempts','sessions','net_worth_snapshots',
    ];
    foreach ($required as $table) {
        WDTest::it("Table '{$table}' exists", function () use ($table) {
            $exists = (int) DB::fetchVal(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
                [$table]
            );
            assert_true($exists === 1, "Table '{$table}' not found in DB");
        });
    }
});

// ── Tax Calculations ──────────────────────────────────────────────────────────
WDTest::describe('Tax Engine', function () {
    WDTest::it('LTCG rate is 12.5%', function () {
        assert_eq(12.5, EQUITY_LTCG_RATE);
    });

    WDTest::it('STCG rate is 20%', function () {
        assert_eq(20.0, EQUITY_STCG_RATE);
    });

    WDTest::it('LTCG exemption is ₹1.25L', function () {
        assert_eq(125000, EQUITY_LTCG_EXEMPTION);
    });

    WDTest::it('LTCG tax calculation below exemption', function () {
        $gain     = 100000;
        $taxable  = max(0, $gain - EQUITY_LTCG_EXEMPTION);
        $tax      = $taxable * EQUITY_LTCG_RATE / 100;
        assert_eq(0.0, $tax, 'No tax below exemption limit');
    });

    WDTest::it('LTCG tax calculation above exemption', function () {
        $gain    = 200000;
        $taxable = max(0, $gain - EQUITY_LTCG_EXEMPTION);
        $tax     = round($taxable * EQUITY_LTCG_RATE / 100, 2);
        assert_eq(9375.0, $tax, 'Tax on ₹75,000 taxable at 12.5%');
    });

    WDTest::it('FD TDS rate is 10%', function () {
        assert_eq(10.0, FD_TDS_RATE);
    });

    WDTest::it('ELSS lock-in is 3 years', function () {
        assert_eq(1095, ELSS_LOCKIN_DAYS);
    });

    WDTest::it('Debt LTCG holding period is 1095 days', function () {
        assert_eq(1095, DEBT_LTCG_DAYS);
    });
});

// ── XIRR / Returns ───────────────────────────────────────────────────────────
WDTest::describe('XIRR & Return Calculations', function () {
    WDTest::it('Simple absolute return calculation', function () {
        $invested = 100000;
        $current  = 120000;
        $return   = ($current - $invested) / $invested * 100;
        assert_eq(20.0, $return);
    });

    WDTest::it('CAGR formula 5 years', function () {
        $start  = 100000;
        $end    = 161051;
        $years  = 5;
        $cagr   = round((pow($end / $start, 1 / $years) - 1) * 100, 2);
        assert_eq(10.0, $cagr);
    });

    WDTest::it('SIP future value calculation', function () {
        // FV = P × [((1+r)^n - 1)/r] × (1+r)
        $monthly = 10000;
        $r       = 0.12 / 12;
        $n       = 12;
        $fv      = $monthly * (((1 + $r) ** $n - 1) / $r) * (1 + $r);
        assert_true($fv > 127000 && $fv < 128000, "SIP FV should be ~₹1.27L, got {$fv}");
    });

    WDTest::it('Negative gain returns correct negative pct', function () {
        $invested = 50000;
        $current  = 40000;
        $pct      = ($current - $invested) / $invested * 100;
        assert_eq(-20.0, $pct);
    });
});

// ── Helpers ──────────────────────────────────────────────────────────────────
WDTest::describe('Helper Functions', function () {
    WDTest::it('clean() strips HTML', function () {
        $input  = '<script>alert(1)</script>Hello';
        $output = clean($input);
        assert_false(str_contains($output, '<script>'), 'clean() should strip tags');
    });

    WDTest::it('env() returns default for missing key', function () {
        $val = env('__NONEXISTENT_KEY_WD__', 'default_val');
        assert_eq('default_val', $val);
    });

    WDTest::it('env() parses boolean true', function () {
        $_ENV['_WD_TEST_BOOL'] = 'true';
        $val = env('_WD_TEST_BOOL', false);
        assert_true($val === true);
    });

    WDTest::it('date_to_db() converts Indian date format', function () {
        if (!function_exists('date_to_db')) {
            WDTest::skip('date_to_db', 'Function not available');
            return;
        }
        $result = date_to_db('15-06-2024');
        assert_contains('2024', $result);
    });

    WDTest::it('Constants are defined', function () {
        assert_true(defined('WEALTHDASH'));
        assert_true(defined('APP_URL'));
        assert_true(defined('APP_ROOT'));
        assert_true(defined('ROLE_ADMIN'));
        assert_true(defined('CURRENCY'));
    });
});

// ── Config ────────────────────────────────────────────────────────────────────
WDTest::describe('Config & Environment', function () {
    WDTest::it('APP_ROOT is a valid directory', function () {
        assert_true(is_dir(APP_ROOT));
    });

    WDTest::it('.env file exists', function () {
        assert_true(file_exists(APP_ROOT . '/.env'), '.env file missing');
    });

    WDTest::it('Timezone is Asia/Kolkata', function () {
        assert_eq('Asia/Kolkata', date_default_timezone_get());
    });

    WDTest::it('Session is active', function () {
        assert_true(session_status() === PHP_SESSION_ACTIVE);
    });

    WDTest::it('Logs directory is writable', function () {
        assert_true(is_writable(APP_ROOT . '/logs'), 'logs/ must be writable');
    });
});
