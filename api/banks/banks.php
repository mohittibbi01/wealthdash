<?php
/**
 * WealthDash — Bank Accounts Tracker API [t43]
 * Worker: ID-M
 * File: api/banks/banks.php
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$user        = require_auth();
$userId      = (int) $user['id'];
$portfolioId = get_user_portfolio_id($userId);

if (!$portfolioId) {
    json_response(false, 'Portfolio not found.', [], 404);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function validate_account_id(int $id, int $userId): array {
    $row = DB::fetchOne(
        'SELECT * FROM bank_accounts WHERE id = ? AND user_id = ?',
        [$id, $userId]
    );
    if (!$row) json_response(false, 'Bank account not found.', [], 404);
    return $row;
}

function recalc_balance(int $accountId): void {
    // Recalculate current_balance from opening_balance + transactions
    $opening = (float) DB::fetchVal(
        'SELECT opening_balance FROM bank_accounts WHERE id = ?', [$accountId]
    );
    $credits = (float) DB::fetchVal(
        "SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE account_id = ? AND type='credit'",
        [$accountId]
    );
    $debits  = (float) DB::fetchVal(
        "SELECT COALESCE(SUM(amount),0) FROM bank_transactions WHERE account_id = ? AND type='debit'",
        [$accountId]
    );
    $balance = $opening + $credits - $debits;
    DB::run(
        'UPDATE bank_accounts SET current_balance = ?, balance_date = CURDATE() WHERE id = ?',
        [$balance, $accountId]
    );
}

function snap_balance(int $accountId, float $balance): void {
    DB::run(
        'INSERT INTO bank_balance_history (account_id, snap_date, balance)
         VALUES (?, CURDATE(), ?)
         ON DUPLICATE KEY UPDATE balance = VALUES(balance)',
        [$accountId, $balance]
    );
}

// ── Routing ──────────────────────────────────────────────────────────────────

switch ($action) {

    // ── LIST ─────────────────────────────────────────────────────────────────
    case 'bank_list': {
        $status = clean($_GET['status'] ?? 'active');
        $where  = "WHERE ba.user_id = ?";
        $params = [$userId];
        if (in_array($status, ['active','closed','dormant'])) {
            $where  .= ' AND ba.status = ?';
            $params[] = $status;
        }
        $rows = DB::fetchAll(
            "SELECT ba.*,
                    (SELECT SUM(amount) FROM bank_transactions bt
                     WHERE bt.account_id = ba.id AND bt.type='credit') AS total_credits,
                    (SELECT SUM(amount) FROM bank_transactions bt
                     WHERE bt.account_id = ba.id AND bt.type='debit')  AS total_debits,
                    (SELECT COUNT(*) FROM bank_transactions bt
                     WHERE bt.account_id = ba.id)                       AS txn_count
             FROM bank_accounts ba
             $where
             ORDER BY ba.is_primary DESC, ba.bank_name ASC",
            $params
        );

        // Summary
        $totalBalance = array_sum(array_column(
            array_filter($rows, fn($r) => $r['status'] === 'active'),
            'current_balance'
        ));

        json_response(true, '', [
            'accounts'      => $rows,
            'total_balance' => $totalBalance,
            'count'         => count($rows),
        ]);
    }

    // ── SINGLE GET ────────────────────────────────────────────────────────────
    case 'bank_get': {
        $id  = (int)($_GET['id'] ?? 0);
        $row = validate_account_id($id, $userId);

        // Recent transactions
        $txns = DB::fetchAll(
            'SELECT * FROM bank_transactions WHERE account_id = ? ORDER BY txn_date DESC, id DESC LIMIT 50',
            [$id]
        );
        // Balance history (last 12 months)
        $history = DB::fetchAll(
            'SELECT snap_date, balance FROM bank_balance_history
             WHERE account_id = ? ORDER BY snap_date ASC LIMIT 366',
            [$id]
        );

        json_response(true, '', ['account' => $row, 'transactions' => $txns, 'history' => $history]);
    }

    // ── ADD ───────────────────────────────────────────────────────────────────
    case 'bank_add': {
        csrf_verify();

        $bankName    = clean($_POST['bank_name'] ?? '');
        $accountType = clean($_POST['account_type'] ?? 'savings');
        if (!$bankName) json_response(false, 'Bank name is required.');

        $allowedTypes = ['savings','current','salary','nre','nro','fcnr','rd','cc'];
        if (!in_array($accountType, $allowedTypes)) $accountType = 'savings';

        $openingBalance = (float)($_POST['opening_balance'] ?? 0);

        $id = DB::insert(
            'INSERT INTO bank_accounts
             (portfolio_id, user_id, bank_name, branch, account_type, account_number,
              ifsc_code, nickname, currency, opening_balance, current_balance, balance_date,
              interest_rate, rd_amount, rd_tenure_months, rd_start_date, rd_maturity_date,
              is_joint, joint_holder, nominee, linked_to_demat, is_primary, notes, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $portfolioId, $userId,
                $bankName,
                clean($_POST['branch'] ?? ''),
                $accountType,
                clean($_POST['account_number'] ?? ''),
                strtoupper(clean($_POST['ifsc_code'] ?? '')),
                clean($_POST['nickname'] ?? ''),
                clean($_POST['currency'] ?? 'INR'),
                $openingBalance,
                $openingBalance,  // current_balance = opening_balance on add
                date('Y-m-d'),
                ($_POST['interest_rate'] ?? '') !== '' ? (float)$_POST['interest_rate'] : null,
                ($_POST['rd_amount'] ?? '') !== '' ? (float)$_POST['rd_amount'] : null,
                ($_POST['rd_tenure_months'] ?? '') !== '' ? (int)$_POST['rd_tenure_months'] : null,
                ($_POST['rd_start_date'] ?? '') !== '' ? date_to_db($_POST['rd_start_date']) : null,
                ($_POST['rd_maturity_date'] ?? '') !== '' ? date_to_db($_POST['rd_maturity_date']) : null,
                (int)($_POST['is_joint'] ?? 0),
                clean($_POST['joint_holder'] ?? ''),
                clean($_POST['nominee'] ?? ''),
                (int)($_POST['linked_to_demat'] ?? 0),
                (int)($_POST['is_primary'] ?? 0),
                clean($_POST['notes'] ?? ''),
                'active',
            ]
        );

        // If marked primary, unset others
        if ((int)($_POST['is_primary'] ?? 0)) {
            DB::run(
                'UPDATE bank_accounts SET is_primary = 0 WHERE user_id = ? AND id != ?',
                [$userId, $id]
            );
        }

        snap_balance((int)$id, $openingBalance);
        DB::invalidateCache("user:{$userId}", "banks");

        json_response(true, 'Bank account added.', ['id' => (int)$id]);
    }

    // ── EDIT ──────────────────────────────────────────────────────────────────
    case 'bank_edit': {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        $row = validate_account_id($id, $userId);

        $bankName    = clean($_POST['bank_name'] ?? $row['bank_name']);
        $accountType = clean($_POST['account_type'] ?? $row['account_type']);
        $allowedTypes = ['savings','current','salary','nre','nro','fcnr','rd','cc'];
        if (!in_array($accountType, $allowedTypes)) $accountType = $row['account_type'];

        DB::run(
            'UPDATE bank_accounts SET
                bank_name=?, branch=?, account_type=?, account_number=?, ifsc_code=?,
                nickname=?, interest_rate=?, rd_amount=?, rd_tenure_months=?,
                rd_start_date=?, rd_maturity_date=?, is_joint=?, joint_holder=?,
                nominee=?, linked_to_demat=?, is_primary=?, notes=?, status=?
             WHERE id = ? AND user_id = ?',
            [
                $bankName,
                clean($_POST['branch'] ?? $row['branch']),
                $accountType,
                clean($_POST['account_number'] ?? $row['account_number']),
                strtoupper(clean($_POST['ifsc_code'] ?? $row['ifsc_code'])),
                clean($_POST['nickname'] ?? $row['nickname']),
                ($_POST['interest_rate'] ?? '') !== '' ? (float)$_POST['interest_rate'] : $row['interest_rate'],
                ($_POST['rd_amount'] ?? '') !== '' ? (float)$_POST['rd_amount'] : $row['rd_amount'],
                ($_POST['rd_tenure_months'] ?? '') !== '' ? (int)$_POST['rd_tenure_months'] : $row['rd_tenure_months'],
                ($_POST['rd_start_date'] ?? '') !== '' ? date_to_db($_POST['rd_start_date']) : $row['rd_start_date'],
                ($_POST['rd_maturity_date'] ?? '') !== '' ? date_to_db($_POST['rd_maturity_date']) : $row['rd_maturity_date'],
                (int)($_POST['is_joint'] ?? $row['is_joint']),
                clean($_POST['joint_holder'] ?? $row['joint_holder']),
                clean($_POST['nominee'] ?? $row['nominee']),
                (int)($_POST['linked_to_demat'] ?? $row['linked_to_demat']),
                (int)($_POST['is_primary'] ?? $row['is_primary']),
                clean($_POST['notes'] ?? $row['notes']),
                clean($_POST['status'] ?? $row['status']),
                $id, $userId,
            ]
        );

        if ((int)($_POST['is_primary'] ?? 0)) {
            DB::run('UPDATE bank_accounts SET is_primary = 0 WHERE user_id = ? AND id != ?', [$userId, $id]);
        }

        DB::invalidateCache("user:{$userId}", "banks");
        json_response(true, 'Bank account updated.');
    }

    // ── UPDATE BALANCE (manual) ───────────────────────────────────────────────
    case 'bank_update_balance': {
        csrf_verify();
        $id      = (int)($_POST['id'] ?? 0);
        validate_account_id($id, $userId);
        $balance = (float)($_POST['balance'] ?? 0);
        $date    = ($_POST['date'] ?? '') !== '' ? date_to_db($_POST['date']) : date('Y-m-d');

        DB::run(
            'UPDATE bank_accounts SET current_balance = ?, balance_date = ? WHERE id = ? AND user_id = ?',
            [$balance, $date, $id, $userId]
        );
        snap_balance($id, $balance);
        DB::invalidateCache("user:{$userId}", "banks");
        json_response(true, 'Balance updated.', ['balance' => $balance]);
    }

    // ── DELETE ────────────────────────────────────────────────────────────────
    case 'bank_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        validate_account_id($id, $userId);
        DB::run('DELETE FROM bank_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);
        DB::invalidateCache("user:{$userId}", "banks");
        json_response(true, 'Bank account deleted.');
    }

    // ── TRANSACTION LIST ──────────────────────────────────────────────────────
    case 'bank_txn_list': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        validate_account_id($accountId, $userId);

        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $rows = DB::fetchAll(
            'SELECT * FROM bank_transactions
             WHERE account_id = ?
             ORDER BY txn_date DESC, id DESC
             LIMIT ? OFFSET ?',
            [$accountId, $limit, $offset]
        );
        $total = (int) DB::fetchVal(
            'SELECT COUNT(*) FROM bank_transactions WHERE account_id = ?', [$accountId]
        );

        json_response(true, '', ['transactions' => $rows, 'total' => $total]);
    }

    // ── TRANSACTION ADD ───────────────────────────────────────────────────────
    case 'bank_txn_add': {
        csrf_verify();
        $accountId = (int)($_POST['account_id'] ?? 0);
        validate_account_id($accountId, $userId);

        $type   = clean($_POST['type'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        if (!in_array($type, ['credit','debit'])) json_response(false, 'Invalid transaction type.');
        if ($amount <= 0) json_response(false, 'Amount must be positive.');

        $date = ($_POST['txn_date'] ?? '') !== '' ? date_to_db($_POST['txn_date']) : date('Y-m-d');

        DB::beginTransaction();
        try {
            // Get current balance for balance_after
            $curBal = (float) DB::fetchVal('SELECT current_balance FROM bank_accounts WHERE id = ?', [$accountId]);
            $balAfter = $type === 'credit' ? $curBal + $amount : $curBal - $amount;

            DB::run(
                'INSERT INTO bank_transactions
                 (account_id, user_id, txn_date, value_date, type, category, amount, balance_after, description, ref_number)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $accountId, $userId,
                    $date,
                    ($_POST['value_date'] ?? '') !== '' ? date_to_db($_POST['value_date']) : null,
                    $type,
                    clean($_POST['category'] ?? 'other'),
                    $amount,
                    $balAfter,
                    clean($_POST['description'] ?? ''),
                    clean($_POST['ref_number'] ?? ''),
                ]
            );

            DB::run(
                'UPDATE bank_accounts SET current_balance = ?, balance_date = ? WHERE id = ?',
                [$balAfter, $date, $accountId]
            );
            snap_balance($accountId, $balAfter);
            DB::commit();
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }

        DB::invalidateCache("user:{$userId}", "banks");
        json_response(true, 'Transaction added.', ['balance_after' => $balAfter]);
    }

    // ── TRANSACTION DELETE ────────────────────────────────────────────────────
    case 'bank_txn_delete': {
        csrf_verify();
        $txnId = (int)($_POST['txn_id'] ?? 0);
        $txn   = DB::fetchOne('SELECT * FROM bank_transactions WHERE id = ? AND user_id = ?', [$txnId, $userId]);
        if (!$txn) json_response(false, 'Transaction not found.', [], 404);

        DB::run('DELETE FROM bank_transactions WHERE id = ?', [$txnId]);
        recalc_balance((int)$txn['account_id']);

        $newBal = (float) DB::fetchVal('SELECT current_balance FROM bank_accounts WHERE id = ?', [$txn['account_id']]);
        snap_balance((int)$txn['account_id'], $newBal);

        DB::invalidateCache("user:{$userId}", "banks");
        json_response(true, 'Transaction deleted.');
    }

    // ── SUMMARY (for dashboard / networth) ───────────────────────────────────
    case 'bank_summary': {
        $rows = DB::fetchAll(
            "SELECT account_type, currency, SUM(current_balance) as total, COUNT(*) as cnt
             FROM bank_accounts
             WHERE user_id = ? AND status = 'active'
             GROUP BY account_type, currency",
            [$userId]
        );

        $grandTotal = (float) DB::fetchVal(
            "SELECT COALESCE(SUM(current_balance),0) FROM bank_accounts WHERE user_id = ? AND status='active'",
            [$userId]
        );

        $monthlyInflow  = (float) DB::fetchVal(
            "SELECT COALESCE(SUM(bt.amount),0)
             FROM bank_transactions bt
             JOIN bank_accounts ba ON ba.id = bt.account_id
             WHERE ba.user_id = ? AND bt.type='credit'
               AND bt.txn_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",
            [$userId]
        );
        $monthlyOutflow = (float) DB::fetchVal(
            "SELECT COALESCE(SUM(bt.amount),0)
             FROM bank_transactions bt
             JOIN bank_accounts ba ON ba.id = bt.account_id
             WHERE ba.user_id = ? AND bt.type='debit'
               AND bt.txn_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",
            [$userId]
        );

        json_response(true, '', [
            'by_type'        => $rows,
            'grand_total'    => $grandTotal,
            'monthly_inflow' => $monthlyInflow,
            'monthly_outflow'=> $monthlyOutflow,
            'net_cashflow'   => $monthlyInflow - $monthlyOutflow,
        ]);
    }

    // ── BALANCE HISTORY CHART ─────────────────────────────────────────────────
    case 'bank_balance_history': {
        $accountId = (int)($_GET['account_id'] ?? 0);
        if ($accountId) {
            validate_account_id($accountId, $userId);
            $rows = DB::fetchAll(
                'SELECT snap_date, balance FROM bank_balance_history
                 WHERE account_id = ? ORDER BY snap_date ASC',
                [$accountId]
            );
        } else {
            // All accounts combined by date
            $rows = DB::fetchAll(
                'SELECT bbh.snap_date, SUM(bbh.balance) as balance
                 FROM bank_balance_history bbh
                 JOIN bank_accounts ba ON ba.id = bbh.account_id
                 WHERE ba.user_id = ? AND ba.status = \'active\'
                 GROUP BY bbh.snap_date
                 ORDER BY bbh.snap_date ASC',
                [$userId]
            );
        }
        json_response(true, '', ['history' => $rows]);
    }

    // ── BANKS LIST (search helper for add form) ───────────────────────────────
    case 'banks_list': {
        $rows = DB::fetchAll(
            'SELECT * FROM bank_master ORDER BY bank_name ASC',
            []
        );
        // If table doesn't exist, return common banks
        if (empty($rows)) {
            $rows = [
                ['code'=>'SBI',  'bank_name'=>'State Bank of India'],
                ['code'=>'HDFC', 'bank_name'=>'HDFC Bank'],
                ['code'=>'ICICI','bank_name'=>'ICICI Bank'],
                ['code'=>'AXIS', 'bank_name'=>'Axis Bank'],
                ['code'=>'KOTAK','bank_name'=>'Kotak Mahindra Bank'],
                ['code'=>'PNB',  'bank_name'=>'Punjab National Bank'],
                ['code'=>'BOB',  'bank_name'=>'Bank of Baroda'],
                ['code'=>'CANARA','bank_name'=>'Canara Bank'],
                ['code'=>'IDFC', 'bank_name'=>'IDFC First Bank'],
                ['code'=>'YES',  'bank_name'=>'Yes Bank'],
                ['code'=>'INDUS','bank_name'=>'IndusInd Bank'],
                ['code'=>'FEDERAL','bank_name'=>'Federal Bank'],
                ['code'=>'RBL', 'bank_name'=>'RBL Bank'],
                ['code'=>'UCO',  'bank_name'=>'UCO Bank'],
                ['code'=>'UNION','bank_name'=>'Union Bank of India'],
            ];
        }
        json_response(true, '', ['banks' => $rows]);
    }

    default:
        json_response(false, "Unknown bank action: {$action}", [], 400);
}
