<?php
/**
 * WealthDash — Admin: DB Backup & Restore
 * Task: t211
 * Worker: ID-M
 */
defined('WEALTHDASH') or die();

if (!$isAdmin) {
    json_response(false, 'Admin only.', [], 403);
}

$backupDir = APP_ROOT . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
    file_put_contents($backupDir . '/.htaccess', "Require all denied\n");
}

switch ($action) {

    // ── List all backups ─────────────────────────────────────────
    case 'admin_db_backup_list':
        $backups = DB::fetchAll(
            "SELECT b.*, u.name AS created_by_name
             FROM db_backups b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.created_at DESC
             LIMIT 50"
        );
        foreach ($backups as &$b) {
            $b['file_size_human'] = _wd_format_bytes((int)$b['file_size']);
            $b['file_exists']     = file_exists(APP_ROOT . '/backups/' . $b['filename']);
        }
        json_response(true, '', ['backups' => $backups]);

    // ── Backup stats ─────────────────────────────────────────────
    case 'admin_db_backup_stats':
        $total      = DB::fetchVal("SELECT COUNT(*) FROM db_backups WHERE status='completed'");
        $lastBackup = DB::fetchOne("SELECT created_at FROM db_backups WHERE status='completed' ORDER BY created_at DESC LIMIT 1");
        $diskUsed   = 0;
        if (is_dir($backupDir)) {
            foreach (glob($backupDir . '/*.sql*') ?: [] as $f) {
                $diskUsed += filesize($f);
            }
        }
        json_response(true, '', [
            'total_backups'   => (int)$total,
            'last_backup_at'  => $lastBackup['created_at'] ?? null,
            'disk_used'       => $diskUsed,
            'disk_used_human' => _wd_format_bytes($diskUsed),
        ]);

    // ── Create new backup ────────────────────────────────────────
    case 'admin_db_backup_create':
        $notes    = clean($_POST['notes'] ?? '');
        $compress = (bool)($_POST['compress'] ?? true);

        // Register row immediately
        $ts       = date('Y-m-d_H-i-s');
        $ext      = $compress ? '.sql.gz' : '.sql';
        $filename = 'wealthdash_backup_' . $ts . $ext;
        $filepath = $backupDir . '/' . $filename;

        DB::run(
            "INSERT INTO db_backups (filename, status, notes, created_by) VALUES (?,?,?,?)",
            [$filename, 'in_progress', $notes, $userId]
        );
        $backupId = (int)DB::conn()->lastInsertId();

        try {
            $host   = env('DB_HOST', 'localhost');
            $port   = env('DB_PORT', '3306');
            $dbname = env('DB_NAME', 'wealthdash');
            $user   = env('DB_USER', 'root');
            $pass   = env('DB_PASS', '');

            // Check mysqldump availability
            $mysqldump = _wd_find_mysqldump();
            if (!$mysqldump) {
                throw new RuntimeException('mysqldump not found on server. Contact your hosting provider.');
            }

            $passPart = $pass ? '-p' . escapeshellarg($pass) : '';
            $cmd = sprintf(
                '%s -h %s -P %s -u %s %s --single-transaction --routines --triggers %s',
                $mysqldump,
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                $passPart,
                escapeshellarg($dbname)
            );

            if ($compress) {
                $cmd .= ' | gzip > ' . escapeshellarg($filepath);
            } else {
                $cmd .= ' > ' . escapeshellarg($filepath);
            }

            exec($cmd . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('mysqldump failed: ' . implode(' ', $output));
            }

            $fileSize   = file_exists($filepath) ? filesize($filepath) : 0;
            $tableCount = (int)DB::fetchVal("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");

            DB::run(
                "UPDATE db_backups SET status='completed', file_size=?, tables_count=?, completed_at=NOW() WHERE id=?",
                [$fileSize, $tableCount, $backupId]
            );

            audit_log('db_backup_create', 'db_backup', $backupId, [], ['filename' => $filename]);
            json_response(true, 'Backup created successfully.', [
                'backup_id'       => $backupId,
                'filename'        => $filename,
                'file_size'       => $fileSize,
                'file_size_human' => _wd_format_bytes($fileSize),
                'tables_count'    => $tableCount,
            ]);

        } catch (Exception $e) {
            DB::run("UPDATE db_backups SET status='failed', error_msg=? WHERE id=?", [$e->getMessage(), $backupId]);
            json_response(false, $e->getMessage());
        }

    // ── Delete a backup file ─────────────────────────────────────
    case 'admin_db_backup_delete':
        $backupId = (int)($_POST['backup_id'] ?? 0);
        if (!$backupId) json_response(false, 'backup_id required.');

        $backup = DB::fetchOne("SELECT * FROM db_backups WHERE id=?", [$backupId]);
        if (!$backup) json_response(false, 'Backup not found.');

        $filePath = APP_ROOT . '/backups/' . $backup['filename'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        DB::run("DELETE FROM db_backups WHERE id=?", [$backupId]);
        audit_log('db_backup_delete', 'db_backup', $backupId, $backup, []);
        json_response(true, 'Backup deleted.');

    // ── Download a backup ─────────────────────────────────────────
    case 'admin_db_backup_download':
        $backupId = (int)($_GET['backup_id'] ?? $_POST['backup_id'] ?? 0);
        if (!$backupId) json_response(false, 'backup_id required.');

        $backup = DB::fetchOne("SELECT * FROM db_backups WHERE id=? AND status='completed'", [$backupId]);
        if (!$backup) json_response(false, 'Backup not found.');

        $filePath = APP_ROOT . '/backups/' . $backup['filename'];
        if (!file_exists($filePath)) json_response(false, 'Backup file missing from disk.');

        // Flush JSON output buffer before sending file
        ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-store');
        readfile($filePath);
        exit;

    // ── Upload & restore a backup ─────────────────────────────────
    case 'admin_db_restore_upload':
        if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            json_response(false, 'No file uploaded or upload error.');
        }
        $tmpPath  = $_FILES['backup_file']['tmp_name'];
        $origName = basename($_FILES['backup_file']['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['sql', 'gz'])) {
            json_response(false, 'Only .sql or .sql.gz files allowed.');
        }
        $dest = $backupDir . '/restore_' . date('YmdHis') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $origName);
        if (!move_uploaded_file($tmpPath, $dest)) {
            json_response(false, 'Could not save uploaded file.');
        }
        json_response(true, 'File uploaded. Ready to restore.', ['temp_file' => basename($dest)]);

    // ── Run restore ───────────────────────────────────────────────
    case 'admin_db_restore_run':
        $filename  = clean($_POST['filename'] ?? '');
        $backupId  = (int)($_POST['backup_id'] ?? 0);
        $confirmed = (bool)($_POST['confirmed'] ?? false);

        if (!$confirmed) {
            json_response(false, 'Please confirm the restore operation. This will OVERWRITE existing data.');
        }

        // Resolve filepath
        if ($backupId) {
            $backup   = DB::fetchOne("SELECT * FROM db_backups WHERE id=? AND status='completed'", [$backupId]);
            if (!$backup) json_response(false, 'Backup record not found.');
            $filePath = APP_ROOT . '/backups/' . $backup['filename'];
            $fname    = $backup['filename'];
        } elseif ($filename) {
            $fname    = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
            $filePath = $backupDir . '/' . $fname;
        } else {
            json_response(false, 'Specify backup_id or filename.');
        }

        if (!file_exists($filePath)) json_response(false, 'Backup file not found on disk.');

        DB::run(
            "INSERT INTO db_restore_log (backup_id, filename, status, restored_by) VALUES (?,?,?,?)",
            [$backupId ?: null, $fname, 'in_progress', $userId]
        );
        $restoreId = (int)DB::conn()->lastInsertId();

        try {
            $host   = env('DB_HOST', 'localhost');
            $port   = env('DB_PORT', '3306');
            $dbname = env('DB_NAME', 'wealthdash');
            $user   = env('DB_USER', 'root');
            $pass   = env('DB_PASS', '');

            $mysql = _wd_find_mysql();
            if (!$mysql) throw new RuntimeException('mysql client not found on server.');

            $passPart = $pass ? '-p' . escapeshellarg($pass) : '';
            $isGzip   = str_ends_with($filePath, '.gz');

            if ($isGzip) {
                $cmd = sprintf(
                    'zcat %s | %s -h %s -P %s -u %s %s %s 2>&1',
                    escapeshellarg($filePath),
                    $mysql,
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($user),
                    $passPart,
                    escapeshellarg($dbname)
                );
            } else {
                $cmd = sprintf(
                    '%s -h %s -P %s -u %s %s %s < %s 2>&1',
                    $mysql,
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($user),
                    $passPart,
                    escapeshellarg($dbname),
                    escapeshellarg($filePath)
                );
            }

            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('mysql restore failed: ' . implode(' ', $output));
            }

            $tableCount = (int)DB::fetchVal("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
            DB::run(
                "UPDATE db_restore_log SET status='completed', tables_restored=?, completed_at=NOW() WHERE id=?",
                [$tableCount, $restoreId]
            );
            audit_log('db_restore_run', 'db_restore_log', $restoreId, [], ['filename' => $fname]);
            json_response(true, 'Database restored successfully.', ['tables_restored' => $tableCount]);

        } catch (Exception $e) {
            DB::run("UPDATE db_restore_log SET status='failed', error_msg=? WHERE id=?", [$e->getMessage(), $restoreId]);
            json_response(false, $e->getMessage());
        }

    // ── Restore log ───────────────────────────────────────────────
    case 'admin_db_restore_log':
        $rows = DB::fetchAll(
            "SELECT r.*, u.name AS restored_by_name
             FROM db_restore_log r
             LEFT JOIN users u ON u.id = r.restored_by
             ORDER BY r.started_at DESC
             LIMIT 30"
        );
        json_response(true, '', ['log' => $rows]);

    default:
        json_response(false, 'Unknown action.', [], 400);
}

// ── Helpers ──────────────────────────────────────────────────────

function _wd_format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function _wd_find_mysqldump(): string|false {
    $locations = [
        '/usr/bin/mysqldump', '/usr/local/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump', '/usr/local/mysql/bin/mysqldump',
    ];
    foreach ($locations as $loc) {
        if (is_executable($loc)) return $loc;
    }
    $out = shell_exec('which mysqldump 2>/dev/null');
    $out = trim((string)$out);
    return ($out && is_executable($out)) ? $out : false;
}

function _wd_find_mysql(): string|false {
    $locations = [
        '/usr/bin/mysql', '/usr/local/bin/mysql',
        '/opt/homebrew/bin/mysql', '/usr/local/mysql/bin/mysql',
    ];
    foreach ($locations as $loc) {
        if (is_executable($loc)) return $loc;
    }
    $out = shell_exec('which mysql 2>/dev/null');
    $out = trim((string)$out);
    return ($out && is_executable($out)) ? $out : false;
}
