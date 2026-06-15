<?php
/**
 * WealthDash — t389: GDPR-style Data Controls
 * File: api/security/data_controls.php
 * Actions: data_export_request, data_export_list, data_export_download,
 *          data_delete_request, data_delete_status, data_delete_cancel
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    // ── Request a full data export (JSON) ───────────────────────────
    case 'data_export_request': {
        csrf_verify();
        RateLimit::check('bulk_import', $userId);

        $data = [];
        $tables = [
            'mf_holdings'   => "SELECT * FROM mf_holdings WHERE user_id=?",
            'mf_transactions'=> "SELECT * FROM mf_transactions WHERE user_id=?",
            'mf_sips'       => "SELECT * FROM mf_sips WHERE user_id=?",
            'goals'         => "SELECT * FROM goals WHERE user_id=?",
            'insurance_policies' => "SELECT * FROM insurance_policies WHERE user_id=?",
            'loans'         => "SELECT * FROM loans WHERE user_id=?",
            'life_events'   => "SELECT * FROM life_events WHERE user_id=?",
            'finance_profiles' => "SELECT * FROM finance_profiles WHERE user_id=?",
            'ai_chat_history' => "SELECT role,message,created_at FROM ai_chat_history WHERE user_id=?",
        ];

        foreach ($tables as $name => $sql) {
            try {
                $data[$name] = DB::fetchAll($sql, [$userId]);
            } catch (\Throwable $e) {
                $data[$name] = []; // table may not exist in some setups
            }
        }

        // User profile (excluding sensitive auth fields)
        $user = DB::fetchRow("SELECT id,name,email,role,created_at,theme FROM users WHERE id=?", [$userId]);
        $data['profile'] = $user;
        $data['exported_at'] = date('c');
        $data['export_format_version'] = '1.0';

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Save export record + file
        $exportDir = APP_ROOT . '/storage/exports';
        if (!is_dir($exportDir)) @mkdir($exportDir, 0755, true);
        $filename = "export_user{$userId}_" . date('Ymd_His') . '.json';
        $filepath = $exportDir . '/' . $filename;
        file_put_contents($filepath, $json);

        DB::execute(
            "INSERT INTO data_export_requests(user_id,filename,file_size,status,created_at,expires_at)
             VALUES(?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 7 DAY))",
            [$userId, $filename, strlen($json), 'ready']
        );

        audit_log($userId, 'data_export', "Generated export: $filename");
        json_response(true, 'Export ready! Download link valid for 7 days.', ['filename' => $filename, 'size_kb' => round(strlen($json)/1024, 1)]);
        break;
    }

    // ── List past export requests ────────────────────────────────────
    case 'data_export_list': {
        DB::execute("DELETE FROM data_export_requests WHERE user_id=? AND expires_at < NOW()", [$userId]);
        $rows = DB::fetchAll("SELECT id,filename,file_size,status,created_at,expires_at FROM data_export_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 10", [$userId]);
        json_response(true,'ok',['exports'=>$rows]);
        break;
    }

    // ── Download an export file ──────────────────────────────────────
    case 'data_export_download': {
        $id = (int)($_GET['id'] ?? 0);
        $row = DB::fetchRow("SELECT filename, expires_at FROM data_export_requests WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$row) json_response(false, 'Export not found.');
        if (strtotime($row['expires_at']) < time()) json_response(false, 'Export expired.');

        $filepath = APP_ROOT . '/storage/exports/' . $row['filename'];
        if (!file_exists($filepath)) json_response(false, 'File not found on server.');

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $row['filename'] . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }

    // ── Request account deletion (with grace period) ──────────────────
    case 'data_delete_request': {
        csrf_verify();
        $confirm = clean($_POST['confirm_text'] ?? '');
        if ($confirm !== 'DELETE MY ACCOUNT') {
            json_response(false, 'Confirmation text mismatch. Type exactly: DELETE MY ACCOUNT');
        }

        $existing = DB::fetchVal("SELECT id FROM account_deletion_requests WHERE user_id=? AND status='pending'", [$userId]);
        if ($existing) json_response(false, 'Deletion already requested. Check status.');

        $scheduledFor = date('Y-m-d H:i:s', strtotime('+14 days'));
        DB::execute(
            "INSERT INTO account_deletion_requests(user_id,status,requested_at,scheduled_for) VALUES(?,?,NOW(),?)",
            [$userId, 'pending', $scheduledFor]
        );

        audit_log($userId, 'data_delete_request', "Account deletion requested, scheduled for $scheduledFor");
        json_response(true, 'Account deletion scheduled.', ['scheduled_for' => $scheduledFor, 'grace_days' => 14]);
        break;
    }

    // ── Check deletion request status ──────────────────────────────────
    case 'data_delete_status': {
        $row = DB::fetchRow("SELECT * FROM account_deletion_requests WHERE user_id=? ORDER BY requested_at DESC LIMIT 1", [$userId]);
        if (!$row) json_response(true,'ok',['pending'=>false]);
        $daysLeft = max(0, (int)ceil((strtotime($row['scheduled_for']) - time())/86400));
        json_response(true,'ok',[
            'pending'      => $row['status']==='pending',
            'status'       => $row['status'],
            'requested_at' => $row['requested_at'],
            'scheduled_for'=> $row['scheduled_for'],
            'days_left'    => $daysLeft,
        ]);
        break;
    }

    // ── Cancel deletion request ───────────────────────────────────────
    case 'data_delete_cancel': {
        csrf_verify();
        $row = DB::fetchVal("SELECT id FROM account_deletion_requests WHERE user_id=? AND status='pending'", [$userId]);
        if (!$row) json_response(false, 'No pending deletion request.');
        DB::execute("UPDATE account_deletion_requests SET status='cancelled', cancelled_at=NOW() WHERE id=?", [$row]);
        audit_log($userId, 'data_delete_cancel', 'Account deletion request cancelled');
        json_response(true, 'Deletion request cancelled.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
