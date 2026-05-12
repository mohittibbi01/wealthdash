<?php
/**
 * WealthDash — EPFO Passbook Sync API
 * t151: Automated passbook import
 * Actions: epfo_accounts_list | epfo_account_add | epfo_account_delete
 *          epfo_import_pdf | epfo_passbook | epfo_summary | epfo_sync_log
 * Included by api/router.php
 */
defined('WEALTHDASH') or die();

require_once APP_ROOT . '/includes/epfo_parser.php';

$sub = $action;

// ── Helpers ───────────────────────────────────────────────────────────────────

function t151_ownAccount(int $accountId, int $userId): array
{
    $row = DB::fetchRow('SELECT * FROM epfo_accounts WHERE id = ? AND user_id = ?', [$accountId, $userId]);
    if (!$row) json_response(false, 'Account not found.', [], 404);
    return $row;
}

// ── Routes ────────────────────────────────────────────────────────────────────

switch ($sub) {

    // ── List all EPFO accounts for user ──────────────────────────────────────
    case 'epfo_accounts_list':
        $rows = DB::fetchAll(
            'SELECT id, uan, member_id, establishment, office_code, last_sync_at, sync_status, is_active, created_at
             FROM epfo_accounts WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );
        // Attach latest balance for each account
        foreach ($rows as &$r) {
            $r['balance'] = (float) DB::fetchVal(
                'SELECT balance FROM epfo_passbook_entries WHERE account_id = ? ORDER BY wage_month DESC, id DESC LIMIT 1',
                [$r['id']]
            ) ?? 0;
            $r['entry_count'] = (int) DB::fetchVal(
                'SELECT COUNT(*) FROM epfo_passbook_entries WHERE account_id = ?',
                [$r['id']]
            );
        }
        unset($r);
        json_response(true, '', ['accounts' => $rows]);

    // ── Add new EPFO account ─────────────────────────────────────────────────
    case 'epfo_account_add':
        $uan   = preg_replace('/\D/', '', clean($_POST['uan'] ?? ''));
        $memId = clean($_POST['member_id'] ?? '');
        $estab = clean($_POST['establishment'] ?? '');
        if (strlen($uan) !== 12) json_response(false, 'UAN must be 12 digits.');
        // Duplicate check
        $exists = DB::fetchVal('SELECT id FROM epfo_accounts WHERE user_id = ? AND uan = ?', [$userId, $uan]);
        if ($exists) json_response(false, 'This UAN is already added.');
        DB::run(
            'INSERT INTO epfo_accounts (user_id, uan, member_id, establishment) VALUES (?,?,?,?)',
            [$userId, $uan, $memId ?: null, $estab ?: null]
        );
        $newId = DB::lastId();
        json_response(true, 'Account added.', ['id' => $newId]);

    // ── Delete EPFO account ──────────────────────────────────────────────────
    case 'epfo_account_delete':
        $accountId = (int) ($_POST['account_id'] ?? 0);
        t151_ownAccount($accountId, $userId);
        DB::run('DELETE FROM epfo_passbook_entries WHERE account_id = ?', [$accountId]);
        DB::run('DELETE FROM epfo_sync_log       WHERE account_id = ?', [$accountId]);
        DB::run('DELETE FROM epfo_accounts       WHERE id = ?',         [$accountId]);
        json_response(true, 'Account and all passbook data deleted.');

    // ── Import PDF passbook ───────────────────────────────────────────────────
    case 'epfo_import_pdf':
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $account   = t151_ownAccount($accountId, $userId);

        // Validate upload
        if (empty($_FILES['passbook_pdf']) || $_FILES['passbook_pdf']['error'] !== UPLOAD_ERR_OK) {
            json_response(false, 'No PDF uploaded or upload error.');
        }
        $file = $_FILES['passbook_pdf'];
        if ($file['size'] > 10 * 1024 * 1024) {
            json_response(false, 'File too large (max 10 MB).');
        }
        $mimeOk = in_array($file['type'], ['application/pdf', 'application/octet-stream'], true)
                  || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf';
        if (!$mimeOk) {
            json_response(false, 'Only PDF files are accepted.');
        }

        // Extract text from PDF using pdftotext (Poppler) if available, else read raw
        $tmpPath = $file['tmp_name'];
        $rawText = '';

        if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
            $escaped  = escapeshellarg($tmpPath);
            $rawText  = shell_exec("pdftotext -layout $escaped -") ?? '';
        }

        // Fallback: try to extract text directly (very basic for text-based PDFs)
        if (empty(trim($rawText))) {
            $content = file_get_contents($tmpPath);
            // Extract text objects from PDF stream (basic heuristic)
            preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $blocks);
            foreach ($blocks[1] as $block) {
                preg_match_all('/\((.*?)\)\s*Tj/', $block, $texts);
                $rawText .= implode(' ', $texts[1]) . "\n";
            }
        }

        if (empty(trim($rawText))) {
            json_response(false, 'Could not extract text from PDF. Ensure it is a text-based (not scanned) PDF.');
        }

        // Parse
        $result  = EPFOPassbookParser::parse($rawText);
        $entries = $result['entries'];

        if (empty($entries)) {
            json_response(false, 'No passbook entries found in PDF. Verify it is an EPFO passbook.', [
                'parse_errors' => $result['errors'],
            ]);
        }

        // Update account UAN/member_id if parsed from PDF and not set
        $meta = $result['meta'];
        if ($meta['uan'] && !$account['uan']) {
            DB::run('UPDATE epfo_accounts SET uan = ? WHERE id = ?', [$meta['uan'], $accountId]);
        }
        if ($meta['member_id'] && !$account['member_id']) {
            DB::run('UPDATE epfo_accounts SET member_id = ? WHERE id = ?', [$meta['member_id'], $accountId]);
        }
        if ($meta['establishment'] && !$account['establishment']) {
            DB::run('UPDATE epfo_accounts SET establishment = ? WHERE id = ?', [$meta['establishment'], $accountId]);
        }

        // Upsert entries
        $inserted = 0;
        $skipped  = 0;
        foreach ($entries as $e) {
            $ref = $e['raw_ref'] ?? ($e['wage_month'] . '_' . $e['entry_type']);
            try {
                DB::run(
                    'INSERT IGNORE INTO epfo_passbook_entries
                     (account_id, user_id, wage_month, transaction_date, description,
                      epf_employee, epf_employer, eps_employer, interest, balance,
                      entry_type, raw_ref, source)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $accountId,
                        $userId,
                        $e['wage_month'],
                        $e['transaction_date'],
                        $e['description'],
                        $e['epf_employee'],
                        $e['epf_employer'],
                        $e['eps_employer'],
                        $e['interest'],
                        $e['balance'],
                        $e['entry_type'],
                        $ref,
                        'pdf',
                    ]
                );
                if (DB::affectedRows() > 0) $inserted++;
                else                        $skipped++;
            } catch (\Throwable $ex) {
                $skipped++;
            }
        }

        // Update account sync status
        DB::run(
            'UPDATE epfo_accounts SET last_sync_at = NOW(), sync_status = ?, sync_error = NULL WHERE id = ?',
            ['done', $accountId]
        );

        // Log sync
        DB::run(
            'INSERT INTO epfo_sync_log (account_id, user_id, source, rows_inserted, rows_skipped, status)
             VALUES (?,?,?,?,?,?)',
            [$accountId, $userId, 'pdf', $inserted, $skipped, 'ok']
        );

        json_response(true, "Import complete: $inserted new entries added, $skipped skipped.", [
            'inserted'     => $inserted,
            'skipped'      => $skipped,
            'parse_errors' => $result['errors'],
            'meta'         => $meta,
        ]);

    // ── Get passbook entries ─────────────────────────────────────────────────
    case 'epfo_passbook':
        $accountId = (int) ($_GET['account_id'] ?? $_POST['account_id'] ?? 0);
        t151_ownAccount($accountId, $userId);
        $year = (int) ($_GET['year'] ?? $_POST['year'] ?? 0);

        $sql    = 'SELECT * FROM epfo_passbook_entries WHERE account_id = ?';
        $params = [$accountId];
        if ($year > 0) {
            $sql    .= ' AND YEAR(wage_month) = ?';
            $params[] = $year;
        }
        $sql .= ' ORDER BY wage_month DESC, id DESC';
        $rows = DB::fetchAll($sql, $params);

        // Summary totals
        $totals = DB::fetchRow(
            'SELECT SUM(epf_employee) AS emp, SUM(epf_employer) AS er, SUM(eps_employer) AS eps,
                    SUM(interest) AS interest, MAX(balance) AS latest_balance
             FROM epfo_passbook_entries WHERE account_id = ?',
            [$accountId]
        );
        json_response(true, '', ['entries' => $rows, 'totals' => $totals]);

    // ── Account-level summary ─────────────────────────────────────────────────
    case 'epfo_summary':
        // Aggregate across ALL accounts for this user
        $summary = DB::fetchRow(
            'SELECT
                COUNT(DISTINCT a.id)           AS account_count,
                COALESCE(SUM(e.epf_employee),0) AS total_employee,
                COALESCE(SUM(e.epf_employer),0) AS total_employer,
                COALESCE(SUM(e.eps_employer),0) AS total_eps,
                COALESCE(SUM(e.interest),0)     AS total_interest
             FROM epfo_accounts a
             LEFT JOIN epfo_passbook_entries e ON e.account_id = a.id
             WHERE a.user_id = ? AND a.is_active = 1',
            [$userId]
        );

        // Latest balance per account
        $balances = DB::fetchAll(
            'SELECT a.id, a.uan, a.establishment,
                    (SELECT balance FROM epfo_passbook_entries
                     WHERE account_id = a.id ORDER BY wage_month DESC, id DESC LIMIT 1) AS balance
             FROM epfo_accounts a WHERE a.user_id = ? AND a.is_active = 1',
            [$userId]
        );
        $totalBalance = array_sum(array_column($balances, 'balance'));

        json_response(true, '', [
            'summary'       => $summary,
            'total_balance' => $totalBalance,
            'accounts'      => $balances,
        ]);

    // ── Sync log ─────────────────────────────────────────────────────────────
    case 'epfo_sync_log':
        $accountId = (int) ($_GET['account_id'] ?? $_POST['account_id'] ?? 0);
        if ($accountId) {
            t151_ownAccount($accountId, $userId);
            $rows = DB::fetchAll(
                'SELECT * FROM epfo_sync_log WHERE account_id = ? ORDER BY synced_at DESC LIMIT 50',
                [$accountId]
            );
        } else {
            $rows = DB::fetchAll(
                'SELECT l.* FROM epfo_sync_log l
                 JOIN epfo_accounts a ON a.id = l.account_id
                 WHERE l.user_id = ? ORDER BY l.synced_at DESC LIMIT 100',
                [$userId]
            );
        }
        json_response(true, '', ['log' => $rows]);

    default:
        json_response(false, 'Unknown EPFO action.');
}
