<?php
/**
 * WealthDash — t151: EPFO Passbook Sync
 * File: api/epfo/passbook.php
 * Actions: epfo_accounts_list, epfo_account_add, epfo_account_delete,
 *          epfo_passbook_import, epfo_passbook_entries, epfo_summary
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$db     = DB::getInstance();

switch ($action) {

    // ── LIST saved EPFO accounts ────────────────────────────────────────
    case 'epfo_accounts_list': {
        $rows = DB::fetchAll(
            "SELECT id, uan, member_id, establishment_name, member_name,
                    last_sync_at, balance_employee, balance_employer, balance_total,
                    created_at
             FROM epfo_accounts
             WHERE user_id = ?
             ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($rows as &$r) {
            $r['balance_total'] = (float)$r['balance_total'];
            $r['balance_employee'] = (float)$r['balance_employee'];
            $r['balance_employer'] = (float)$r['balance_employer'];
        }
        json_response(true, 'ok', ['accounts' => $rows]);
        break;
    }

    // ── ADD / LINK a new EPFO UAN ────────────────────────────────────────
    case 'epfo_account_add': {
        csrf_verify();
        $uan  = clean($_POST['uan'] ?? '');
        $name = clean($_POST['member_name'] ?? '');
        if (!preg_match('/^\d{12}$/', $uan)) {
            json_response(false, 'Invalid UAN. Must be 12 digits.');
        }

        // Check duplicate
        $exists = DB::fetchVal(
            "SELECT id FROM epfo_accounts WHERE user_id=? AND uan=?",
            [$userId, $uan]
        );
        if ($exists) json_response(false, 'This UAN is already linked.');

        DB::execute(
            "INSERT INTO epfo_accounts (user_id, uan, member_name, created_at)
             VALUES (?, ?, ?, NOW())",
            [$userId, $uan, $name]
        );
        $newId = DB::lastInsertId();
        json_response(true, 'EPFO account linked.', ['id' => $newId]);
        break;
    }

    // ── DELETE account + all entries ────────────────────────────────────
    case 'epfo_account_delete': {
        csrf_verify();
        $accountId = (int)($_POST['account_id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM epfo_accounts WHERE id=? AND user_id=?", [$accountId, $userId]);
        if (!$own) json_response(false, 'Account not found.');
        DB::execute("DELETE FROM epfo_passbook WHERE account_id=?", [$accountId]);
        DB::execute("DELETE FROM epfo_accounts WHERE id=?",         [$accountId]);
        json_response(true, 'Account removed.');
        break;
    }

    // ── IMPORT passbook via JSON upload (parsed from PDF/HTML by frontend) ──
    case 'epfo_passbook_import': {
        csrf_verify();
        $accountId = (int)($_POST['account_id'] ?? 0);
        $entries   = $_POST['entries'] ?? null; // JSON string

        if (!$accountId || !$entries) {
            json_response(false, 'account_id and entries required.');
        }

        $own = DB::fetchVal("SELECT id FROM epfo_accounts WHERE id=? AND user_id=?", [$accountId, $userId]);
        if (!$own) json_response(false, 'Account not found.');

        $rows = json_decode($entries, true);
        if (!is_array($rows) || empty($rows)) {
            json_response(false, 'Invalid entries JSON.');
        }

        $inserted = 0; $skipped = 0;
        foreach ($rows as $row) {
            $txnDate = clean($row['txn_date'] ?? '');
            $type    = clean($row['type'] ?? '');          // 'employee','employer','pension','withdrawal'
            $amount  = (float)($row['amount']  ?? 0);
            $remarks = clean($row['remarks']   ?? '');
            $wageMonth = clean($row['wage_month'] ?? ''); // YYYY-MM

            if (!$txnDate || !$type || $amount <= 0) { $skipped++; continue; }

            // Deduplicate by account_id + txn_date + type + amount
            $dup = DB::fetchVal(
                "SELECT id FROM epfo_passbook
                 WHERE account_id=? AND txn_date=? AND type=? AND amount=?",
                [$accountId, $txnDate, $type, $amount]
            );
            if ($dup) { $skipped++; continue; }

            DB::execute(
                "INSERT INTO epfo_passbook
                    (account_id, txn_date, wage_month, type, amount, remarks, created_at)
                 VALUES (?,?,?,?,?,?,NOW())",
                [$accountId, $txnDate, $wageMonth, $type, $amount, $remarks]
            );
            $inserted++;
        }

        // Recalculate balances
        _epfo_refresh_balance($accountId);

        json_response(true, "Imported $inserted entries. ($skipped skipped)", [
            'inserted' => $inserted,
            'skipped'  => $skipped,
        ]);
        break;
    }

    // ── ENTRIES list with filters ────────────────────────────────────────
    case 'epfo_passbook_entries': {
        $accountId = (int)($_POST['account_id'] ?? $_GET['account_id'] ?? 0);
        $fy        = clean($_GET['fy'] ?? '');  // e.g. 2024-25
        $type      = clean($_GET['type'] ?? '');

        $own = DB::fetchVal("SELECT id FROM epfo_accounts WHERE id=? AND user_id=?", [$accountId, $userId]);
        if (!$own) json_response(false, 'Account not found.');

        $where = ['p.account_id = ?'];
        $params = [$accountId];

        if ($fy && preg_match('/^(\d{4})-(\d{2})$/', $fy, $m)) {
            $fyStart = $m[1] . '-04-01';
            $fyEnd   = ('20' . $m[2]) . '-03-31';
            $where[] = 'p.txn_date BETWEEN ? AND ?';
            $params[] = $fyStart; $params[] = $fyEnd;
        }
        if ($type) { $where[] = 'p.type = ?'; $params[] = $type; }

        $sql = "SELECT p.*, a.establishment_name
                FROM epfo_passbook p
                JOIN epfo_accounts a ON a.id = p.account_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.txn_date DESC LIMIT 500";

        $entries = DB::fetchAll($sql, $params);

        // Running total
        $total = 0;
        foreach ($entries as &$e) {
            $total += (float)$e['amount'];
            $e['amount'] = (float)$e['amount'];
        }

        json_response(true, 'ok', ['entries' => $entries, 'total' => $total]);
        break;
    }

    // ── SUMMARY: totals per account ──────────────────────────────────────
    case 'epfo_summary': {
        $rows = DB::fetchAll(
            "SELECT a.id, a.uan, a.member_name, a.establishment_name,
                    a.balance_employee, a.balance_employer, a.balance_total,
                    a.last_sync_at,
                    COUNT(p.id) as entry_count
             FROM epfo_accounts a
             LEFT JOIN epfo_passbook p ON p.account_id = a.id
             WHERE a.user_id = ?
             GROUP BY a.id",
            [$userId]
        );
        $grandTotal = array_sum(array_column($rows, 'balance_total'));
        json_response(true, 'ok', [
            'accounts'    => $rows,
            'grand_total' => (float)$grandTotal,
        ]);
        break;
    }

    default:
        json_response(false, 'Unknown EPFO action.', [], 400);
}

// ── Recalculate & update balance totals ──────────────────────────────────
function _epfo_refresh_balance(int $accountId): void {
    $totals = DB::fetchRow(
        "SELECT
            SUM(CASE WHEN type='employee' THEN amount ELSE 0 END) AS emp,
            SUM(CASE WHEN type='employer' THEN amount ELSE 0 END) AS er,
            SUM(amount) AS total
         FROM epfo_passbook WHERE account_id=?",
        [$accountId]
    );
    DB::execute(
        "UPDATE epfo_accounts SET
            balance_employee = ?,
            balance_employer = ?,
            balance_total    = ?,
            last_sync_at     = NOW()
         WHERE id = ?",
        [
            (float)($totals['emp']   ?? 0),
            (float)($totals['er']    ?? 0),
            (float)($totals['total'] ?? 0),
            $accountId,
        ]
    );
}
