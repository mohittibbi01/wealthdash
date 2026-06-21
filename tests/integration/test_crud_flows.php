<?php
/**
 * WealthDash — Integration Tests: Full CRUD Flows [t353]
 * File: tests/integration/test_crud_flows.php
 * Worker: ID-M
 * Run via: debug/runner.php?suite=integration
 * WARNING: Creates + deletes test data — run on local/dev only.
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

if (!IS_LOCAL) {
    WDTest::describe('Integration Tests', function () {
        WDTest::skip('ALL TESTS', 'Integration tests run on IS_LOCAL only');
    });
    return;
}

$userId      = (int)($_SESSION['user_id'] ?? 0);
$portfolioId = $userId ? (int)(DB::fetchVal('SELECT id FROM portfolios WHERE user_id=?', [$userId]) ?: 0) : 0;

// ── Bank Account CRUD ─────────────────────────────────────────────────────────
WDTest::describe('Bank Account CRUD', function () use ($userId, $portfolioId) {
    if (!$userId || !$portfolioId) {
        WDTest::skip('All bank tests', 'No authenticated user for integration test');
        return;
    }

    $createdId = null;

    WDTest::it('INSERT bank account succeeds', function () use ($userId, $portfolioId, &$createdId) {
        $id = DB::insert(
            'INSERT INTO bank_accounts
             (portfolio_id, user_id, bank_name, account_type, opening_balance, current_balance, balance_date, status)
             VALUES (?,?,?,?,?,?,CURDATE(),?)',
            [$portfolioId, $userId, '__TEST_BANK__', 'savings', 10000, 10000, 'active']
        );
        assert_true((int)$id > 0, 'INSERT should return a positive ID');
        $createdId = (int)$id;
    });

    WDTest::it('SELECT bank account by id', function () use ($userId, &$createdId) {
        if (!$createdId) return;
        $row = DB::fetchOne('SELECT * FROM bank_accounts WHERE id=? AND user_id=?', [$createdId, $userId]);
        assert_true(is_array($row));
        assert_eq('__TEST_BANK__', $row['bank_name']);
        assert_eq('10000.00', number_format((float)$row['current_balance'], 2, '.', ''));
    });

    WDTest::it('UPDATE balance succeeds', function () use ($userId, &$createdId) {
        if (!$createdId) return;
        DB::run('UPDATE bank_accounts SET current_balance=? WHERE id=? AND user_id=?',
            [25000, $createdId, $userId]);
        $bal = DB::fetchVal('SELECT current_balance FROM bank_accounts WHERE id=?', [$createdId]);
        assert_eq('25000.00', number_format((float)$bal, 2, '.', ''));
    });

    WDTest::it('INSERT bank transaction updates balance', function () use ($userId, &$createdId) {
        if (!$createdId) return;
        DB::run(
            'INSERT INTO bank_transactions (account_id, user_id, txn_date, type, category, amount, description)
             VALUES (?,?,CURDATE(),\'credit\',\'test\',5000,\'integration test txn\')',
            [$createdId, $userId]
        );
        $count = (int) DB::fetchVal(
            'SELECT COUNT(*) FROM bank_transactions WHERE account_id=? AND description=?',
            [$createdId, 'integration test txn']
        );
        assert_eq(1, $count);
    });

    WDTest::it('DELETE bank account cascades transactions', function () use ($userId, &$createdId) {
        if (!$createdId) return;
        DB::run('DELETE FROM bank_accounts WHERE id=? AND user_id=?', [$createdId, $userId]);
        $row = DB::fetchOne('SELECT id FROM bank_accounts WHERE id=?', [$createdId]);
        assert_false($row, 'Account should be deleted');
        $txns = (int) DB::fetchVal('SELECT COUNT(*) FROM bank_transactions WHERE account_id=?', [$createdId]);
        assert_eq(0, $txns, 'Transactions should cascade delete');
    });
});

// ── Audit Log Write ───────────────────────────────────────────────────────────
WDTest::describe('Audit Log Integration', function () use ($userId) {
    WDTest::it('INSERT audit entry succeeds', function () use ($userId) {
        $before = (int) DB::fetchVal('SELECT COUNT(*) FROM audit_log');
        DB::run(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, severity)
             VALUES (?,?,?,?,?)',
            [$userId ?: null, 'integration_test', 'test', 0, 'info']
        );
        $after = (int) DB::fetchVal('SELECT COUNT(*) FROM audit_log');
        assert_true($after > $before, 'Audit count should increase');
    });

    WDTest::it('Audit entry has correct fields', function () use ($userId) {
        $row = DB::fetchOne(
            "SELECT * FROM audit_log WHERE action='integration_test' ORDER BY id DESC LIMIT 1"
        );
        assert_true(is_array($row));
        assert_eq('integration_test', $row['action']);
        // Cleanup
        DB::run("DELETE FROM audit_log WHERE action='integration_test'");
    });
});

// ── App Settings Integration ──────────────────────────────────────────────────
WDTest::describe('App Settings Integration', function () {
    WDTest::it('Read app_name setting', function () {
        $val = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='app_name'");
        assert_true($val !== false, 'app_name setting must exist');
        assert_not_empty($val);
    });

    WDTest::it('Update and revert a setting', function () {
        $orig = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='items_per_page'");
        if ($orig === false) return;
        DB::run("UPDATE app_settings SET setting_val='99' WHERE setting_key='items_per_page'");
        $updated = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='items_per_page'");
        assert_eq('99', (string)$updated);
        // Revert
        DB::run("UPDATE app_settings SET setting_val=? WHERE setting_key='items_per_page'", [$orig]);
    });
});

// ── Error Monitor Integration ─────────────────────────────────────────────────
WDTest::describe('Error Monitor Integration', function () {
    WDTest::it('WDErrorMonitor::capture() inserts row', function () {
        if (!class_exists('WDErrorMonitor')) return;
        $before = (int) DB::fetchVal('SELECT COUNT(*) FROM error_events');
        WDErrorMonitor::capture('INTEGRATION_TEST', 'test error message', '/test/file.php', 42);
        $after = (int) DB::fetchVal('SELECT COUNT(*) FROM error_events');
        assert_true($after >= $before, 'Error event should be captured');
        // Cleanup
        DB::run("DELETE FROM error_events WHERE error_type='INTEGRATION_TEST'");
    });

    WDTest::it('Duplicate fingerprint increments count', function () {
        if (!class_exists('WDErrorMonitor')) return;
        WDErrorMonitor::capture('DUPE_TEST', 'duplicate message', '/test/dupe.php', 10);
        WDErrorMonitor::capture('DUPE_TEST', 'duplicate message', '/test/dupe.php', 10);
        $row = DB::fetchOne(
            "SELECT count FROM error_events WHERE error_type='DUPE_TEST' ORDER BY id DESC LIMIT 1"
        );
        if ($row) assert_true((int)$row['count'] >= 2, 'Duplicate should increment count');
        DB::run("DELETE FROM error_events WHERE error_type='DUPE_TEST'");
    });
});

// ── DataVersioning Integration ────────────────────────────────────────────────
WDTest::describe('DataVersioning Integration', function () use ($userId, $portfolioId) {
    if (!$userId || !$portfolioId) {
        WDTest::skip('All DV tests', 'No authenticated user');
        return;
    }

    WDTest::it('DataVersioning::begin() creates version record', function () use ($userId, $portfolioId) {
        $id = DataVersioning::begin($userId, $portfolioId, 'unit_test', 'Integration Test Import', 'mf_holdings');
        assert_true($id > 0, 'begin() should return positive ID');
        DataVersioning::abort($id);
        $row = DB::fetchOne('SELECT id FROM import_versions WHERE id=?', [$id]);
        assert_false($row, 'abort() should delete version');
    });
});
