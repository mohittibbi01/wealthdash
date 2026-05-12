<?php
/**
 * WealthDash — Admin: DB Backup & Restore API
 * Task: t211
 * Actions: admin_db_backup_create, admin_db_backup_list,
 *          admin_db_backup_delete, admin_db_backup_download,
 *          admin_db_restore_upload, admin_db_restore_run,
 *          admin_db_restore_log
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

// ── Config ────────────────────────────────────────────────────────────────
$backupDir = APP_ROOT . '/storage/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0750, true);
}

$action  = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId  = (int) $_SESSION['user_id'];

// All actions here are admin-only (router already checked, but double-guard)
if (!is_admin()) {
    json_response(false, 'Admin only.', [], 403);
}

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Get DB credentials from config (reads from env/define constants).
 */
function t211_db_creds(): array {
    return [
        'host' => defined('DB_HOST') ? DB_HOST : (env('DB_HOST', 'localhost')),
        'port' => defined('DB_PORT') ? DB_PORT : (env('DB_PORT', '3306')),
        'name' => defined('DB_NAME') ? DB_NAME : (env('DB_NAME', '')),
        'user' => defined('DB_USER') ? DB_USER : (env('DB_USER', '')),
        'pass' => defined('DB_PASS') ? DB_PASS : (env('DB_PASS', '')),
    ];
}

/**
 * Format bytes to human-readable.
 */
function t211_fmt_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * Sanitize a filename to prevent path traversal.
 */
function t211_safe_filename(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', basename($name));
}

// ── Action Router ─────────────────────────────────────────────────────────
switch ($action) {

    // ── LIST backups ──────────────────────────────────────────────────────
    case 'admin_db_backup_list':
        $rows = DB::fetchAll(
            'SELECT b.*, u.name as created_by_name
             FROM db_backups b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.created_at DESC
             LIMIT 50'
        );

        // Verify files still exist on disk
        foreach ($rows as &$row) {
            $path = $backupDir . '/' . $row['filename'];
            $row['file_exists'] = file_exists($path);
            $row['file_size_human'] = t211_fmt_bytes((int)$row['file_size']);
        }
        unset($row);

        json_response(true, '', ['backups' => $rows]);

    // ── CREATE backup ─────────────────────────────────────────────────────
    case 'admin_db_backup_create':
        $type = clean($_POST['backup_type'] ?? 'full');
        if (!in_array($type, ['full', 'schema_only', 'data_only'])) {
            $type = 'full';
        }

        $creds    = t211_db_creds();
        $dbName   = $creds['name'];
        $ts       = date('Ymd_His');
        $filename = "wealthdash_{$dbName}_{$type}_{$ts}.sql";
        $filepath = $backupDir . '/' . $filename;

        if (empty($dbName)) {
            json_response(false, 'DB_NAME not configured.');
        }

        // Insert log row (in_progress)
        DB::run(
            'INSERT INTO db_backups (filename, backup_type, status, created_by) VALUES (?, ?, ?, ?)',
            [$filename, $type, 'in_progress', $userId]
        );
        $backupId = (int) DB::lastInsertId();

        // Build mysqldump command
        $passEnv   = '';
        $passArg   = '';
        if (!empty($creds['pass'])) {
            // Safer: use MYSQL_PWD env variable instead of -p on cmdline
            $passEnv = 'MYSQL_PWD=' . escapeshellarg($creds['pass']) . ' ';
        }

        $typeFlags = match ($type) {
            'schema_only' => '--no-data',
            'data_only'   => '--no-create-info',
            default       => '',
        };

        $cmd = sprintf(
            '%smysqldump --single-transaction --quick --lock-tables=false'
            . ' -h %s -P %s -u %s %s %s > %s 2>&1',
            $passEnv,
            escapeshellarg($creds['host']),
            escapeshellarg($creds['port']),
            escapeshellarg($creds['user']),
            $typeFlags,
            escapeshellarg($dbName),
            escapeshellarg($filepath)
        );

        exec($cmd, $cmdOut, $exitCode);

        if ($exitCode !== 0 || !file_exists($filepath)) {
            $errMsg = implode("\n", $cmdOut) ?: 'mysqldump failed.';
            DB::run(
                'UPDATE db_backups SET status = ?, error_msg = ?, completed_at = NOW() WHERE id = ?',
                ['failed', $errMsg, $backupId]
            );
            json_response(false, 'Backup failed: ' . $errMsg);
        }

        // Count tables & rows from dump
        $tablesCount = (int) shell_exec("grep -c '^CREATE TABLE' " . escapeshellarg($filepath) . " 2>/dev/null") ?: 0;
        $rowsCount   = (int) shell_exec("grep -c '^INSERT INTO'  " . escapeshellarg($filepath) . " 2>/dev/null") ?: 0;
        $fileSize    = filesize($filepath);

        DB::run(
            'UPDATE db_backups SET status = ?, file_size = ?, tables_count = ?, rows_count = ?, completed_at = NOW() WHERE id = ?',
            ['completed', $fileSize, $tablesCount, $rowsCount, $backupId]
        );

        json_response(true, 'Backup created successfully.', [
            'backup_id'       => $backupId,
            'filename'        => $filename,
            'file_size'       => $fileSize,
            'file_size_human' => t211_fmt_bytes($fileSize),
            'tables_count'    => $tablesCount,
        ]);

    // ── DOWNLOAD a backup ─────────────────────────────────────────────────
    case 'admin_db_backup_download':
        $backupId = (int) ($_GET['id'] ?? 0);
        if (!$backupId) json_response(false, 'Invalid backup ID.');

        $row = DB::fetchOne('SELECT * FROM db_backups WHERE id = ?', [$backupId]);
        if (!$row) json_response(false, 'Backup not found.');

        $filename = t211_safe_filename($row['filename']);
        $filepath = $backupDir . '/' . $filename;

        if (!file_exists($filepath)) {
            json_response(false, 'Backup file not found on disk.');
        }

        // Serve file
        ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($filepath);
        exit;

    // ── DELETE a backup ───────────────────────────────────────────────────
    case 'admin_db_backup_delete':
        $backupId = (int) ($_POST['id'] ?? 0);
        if (!$backupId) json_response(false, 'Invalid backup ID.');

        $row = DB::fetchOne('SELECT * FROM db_backups WHERE id = ?', [$backupId]);
        if (!$row) json_response(false, 'Backup not found.');

        $filename = t211_safe_filename($row['filename']);
        $filepath = $backupDir . '/' . $filename;

        if (file_exists($filepath)) {
            unlink($filepath);
        }

        DB::run('DELETE FROM db_backups WHERE id = ?', [$backupId]);
        json_response(true, 'Backup deleted.');

    // ── RESTORE — upload SQL file ─────────────────────────────────────────
    case 'admin_db_restore_upload':
        if (empty($_FILES['sql_file'])) {
            json_response(false, 'No file uploaded.');
        }

        $file    = $_FILES['sql_file'];
        $tmpPath = $file['tmp_name'];
        $origName= $file['name'];

        // Validate
        if ($file['error'] !== UPLOAD_ERR_OK) {
            json_response(false, 'Upload error: ' . $file['error']);
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['sql', 'gz'])) {
            json_response(false, 'Only .sql or .sql.gz files allowed.');
        }

        // Max 200 MB
        if ($file['size'] > 209715200) {
            json_response(false, 'File too large (max 200 MB).');
        }

        $safeFilename = 'restore_upload_' . date('Ymd_His') . '_' . t211_safe_filename($origName);
        $destPath     = $backupDir . '/' . $safeFilename;
        move_uploaded_file($tmpPath, $destPath);

        json_response(true, 'File uploaded. Ready to restore.', [
            'temp_filename' => $safeFilename,
            'orig_name'     => $origName,
            'file_size'     => $file['size'],
            'file_size_human' => t211_fmt_bytes($file['size']),
        ]);

    // ── RESTORE — run ─────────────────────────────────────────────────────
    case 'admin_db_restore_run':
        // source: 'backup' (from backup list) or 'upload' (temp file)
        $source   = clean($_POST['source'] ?? 'backup');
        $backupId = (int) ($_POST['backup_id'] ?? 0);
        $tempFile = clean($_POST['temp_filename'] ?? '');
        $confirm  = clean($_POST['confirm'] ?? '');

        // Require explicit confirmation string
        if ($confirm !== 'RESTORE') {
            json_response(false, 'Type RESTORE to confirm.');
        }

        // Resolve filepath
        if ($source === 'backup') {
            if (!$backupId) json_response(false, 'Invalid backup ID.');
            $row      = DB::fetchOne('SELECT * FROM db_backups WHERE id = ?', [$backupId]);
            if (!$row) json_response(false, 'Backup not found.');
            $filename = t211_safe_filename($row['filename']);
            $filepath = $backupDir . '/' . $filename;
            $origName = $filename;
        } else {
            if (empty($tempFile)) json_response(false, 'No temp file specified.');
            $filename = t211_safe_filename($tempFile);
            $filepath = $backupDir . '/' . $filename;
            $origName = $filename;
        }

        if (!file_exists($filepath)) {
            json_response(false, 'SQL file not found on disk.');
        }

        // Insert restore log
        DB::run(
            'INSERT INTO db_restore_log (backup_id, filename, status, restored_by) VALUES (?, ?, ?, ?)',
            [$source === 'backup' ? $backupId : null, $origName, 'in_progress', $userId]
        );
        $restoreId = (int) DB::lastInsertId();

        $creds   = t211_db_creds();
        $dbName  = $creds['name'];
        $passEnv = '';
        if (!empty($creds['pass'])) {
            $passEnv = 'MYSQL_PWD=' . escapeshellarg($creds['pass']) . ' ';
        }

        // Handle .gz files
        $inputCmd = escapeshellarg($filepath);
        if (str_ends_with($filename, '.gz')) {
            $inputCmd = 'gunzip -c ' . escapeshellarg($filepath) . ' |';
            $mysqlCmd = sprintf(
                '%s %smysql -h %s -P %s -u %s %s 2>&1',
                $inputCmd,
                $passEnv,
                escapeshellarg($creds['host']),
                escapeshellarg($creds['port']),
                escapeshellarg($creds['user']),
                escapeshellarg($dbName)
            );
        } else {
            $mysqlCmd = sprintf(
                '%smysql -h %s -P %s -u %s %s < %s 2>&1',
                $passEnv,
                escapeshellarg($creds['host']),
                escapeshellarg($creds['port']),
                escapeshellarg($creds['user']),
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );
        }

        exec($mysqlCmd, $cmdOut, $exitCode);

        if ($exitCode !== 0) {
            $errMsg = implode("\n", $cmdOut) ?: 'mysql restore failed.';
            DB::run(
                'UPDATE db_restore_log SET status = ?, error_msg = ?, completed_at = NOW() WHERE id = ?',
                ['failed', $errMsg, $restoreId]
            );
            json_response(false, 'Restore failed: ' . $errMsg);
        }

        // Count restored tables (best-effort)
        $tablesRestored = (int) shell_exec("grep -c '^CREATE TABLE' " . escapeshellarg($filepath) . " 2>/dev/null") ?: 0;

        DB::run(
            'UPDATE db_restore_log SET status = ?, tables_restored = ?, completed_at = NOW() WHERE id = ?',
            ['completed', $tablesRestored, $restoreId]
        );

        // Clean up temp upload file (not backup library files)
        if ($source === 'upload' && file_exists($filepath)) {
            unlink($filepath);
        }

        json_response(true, 'Database restored successfully.', [
            'restore_id'      => $restoreId,
            'tables_restored' => $tablesRestored,
        ]);

    // ── RESTORE LOG ───────────────────────────────────────────────────────
    case 'admin_db_restore_log':
        $rows = DB::fetchAll(
            'SELECT r.*, u.name as restored_by_name
             FROM db_restore_log r
             LEFT JOIN users u ON u.id = r.restored_by
             ORDER BY r.created_at DESC
             LIMIT 30'
        );
        json_response(true, '', ['logs' => $rows]);

    // ── STORAGE STATS ─────────────────────────────────────────────────────
    case 'admin_db_backup_stats':
        $totalFiles = 0;
        $totalSize  = 0;
        if (is_dir($backupDir)) {
            foreach (glob($backupDir . '/*.sql') as $f) {
                $totalFiles++;
                $totalSize += filesize($f);
            }
            foreach (glob($backupDir . '/*.gz') as $f) {
                $totalFiles++;
                $totalSize += filesize($f);
            }
        }

        $dbStats = DB::fetchOne(
            "SELECT
                COUNT(*)         AS backup_count,
                SUM(file_size)   AS total_size,
                MAX(created_at)  AS last_backup,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed_count
             FROM db_backups"
        );

        // Disk free space
        $diskFree = disk_free_space($backupDir);

        json_response(true, '', [
            'backup_dir'        => $backupDir,
            'total_files_disk'  => $totalFiles,
            'total_size_disk'   => $totalSize,
            'total_size_human'  => t211_fmt_bytes($totalSize),
            'disk_free'         => $diskFree,
            'disk_free_human'   => t211_fmt_bytes((int)$diskFree),
            'db_backup_count'   => (int)($dbStats['backup_count'] ?? 0),
            'last_backup'       => $dbStats['last_backup'] ?? null,
            'failed_count'      => (int)($dbStats['failed_count'] ?? 0),
        ]);

    default:
        json_response(false, 'Unknown action.', [], 400);
}
