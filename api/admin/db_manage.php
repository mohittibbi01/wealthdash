<?php
/**
 * WealthDash — DB Table Management API
 * Actions: admin_db_list | admin_db_truncate_one | admin_db_truncate_all
 *          admin_db_protect | admin_db_unprotect
 *          admin_db_backup  | admin_db_backup_list | admin_db_backup_download (t211)
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$db = DB::conn();

// Tables that can NEVER be unprotected (hardcoded, permanent)
$PERMANENT_PROTECTED = [
    'funds', 'fund_houses', 'sessions', 'users', 'app_settings',
];

// Helper: get user-defined protected tables from app_settings
function getUserProtected($db) {
    try {
        $val = $db->query("SELECT setting_val FROM app_settings WHERE setting_key='db_user_protected_tables'")->fetchColumn();
        return $val ? json_decode($val, true) : [];
    } catch (Exception $e) { return []; }
}

function saveUserProtected($db, array $list) {
    $json = json_encode(array_values(array_unique($list)));
    $exists = $db->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='db_user_protected_tables'")->fetchColumn();
    if ($exists) {
        $db->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='db_user_protected_tables'")->execute([$json]);
    } else {
        $db->prepare("INSERT INTO app_settings (setting_key,setting_val) VALUES ('db_user_protected_tables',?)")->execute([$json]);
    }
}

// ─── LIST ─────────────────────────────────────────────────────
if ($action === 'admin_db_list') {
    $userProtected = getUserProtected($db);

    // Get size info from information_schema
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    $sizeRows = $db->query("
        SELECT TABLE_NAME,
               COALESCE(DATA_LENGTH,0) + COALESCE(INDEX_LENGTH,0) AS size_bytes
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = " . $db->quote($dbName) . "
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $tableNames = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $tables = [];
    foreach ($tableNames as $tName) {
        try { $count = (int)$db->query("SELECT COUNT(*) FROM `$tName`")->fetchColumn(); }
        catch (Exception $e) { $count = 0; }

        $isPermanent = in_array($tName, $PERMANENT_PROTECTED);
        $isUser      = in_array($tName, $userProtected);

        $tables[] = [
            'name'               => $tName,
            'rows'               => $count,
            'size_bytes'         => (int)($sizeRows[$tName] ?? 0),
            'protected'          => $isPermanent || $isUser,
            'permanent_protected'=> $isPermanent,
            'user_protected'     => $isUser,
        ];
    }
    json_response(true, '', $tables);
}

// ─── PROTECT (user-defined) ────────────────────────────────────
if ($action === 'admin_db_protect') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $table = clean($body['table'] ?? '');
    if (!$table) json_response(false, 'Invalid table.');
    $list = getUserProtected($db);
    if (!in_array($table, $list)) $list[] = $table;
    saveUserProtected($db, $list);
    json_response(true, "\"$table\" is now protected.");
}

// ─── UNPROTECT (user-defined only) ────────────────────────────
if ($action === 'admin_db_unprotect') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $table = clean($body['table'] ?? '');
    if (in_array($table, $PERMANENT_PROTECTED))
        json_response(false, 'This table is permanently protected and cannot be unprotected.');
    $list = getUserProtected($db);
    $list = array_filter($list, fn($t) => $t !== $table);
    saveUserProtected($db, array_values($list));
    json_response(true, "\"$table\" protection removed.");
}

// ─── TRUNCATE ONE ──────────────────────────────────────────────
if ($action === 'admin_db_truncate_one') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $table = clean($body['table'] ?? $_POST['table'] ?? '');
    $userProtected = getUserProtected($db);
    $allProtected  = array_merge($PERMANENT_PROTECTED, $userProtected);

    if (!$table || in_array($table, $allProtected))
        json_response(false, 'Table is protected or invalid.');
    $exists = $db->query("SHOW TABLES LIKE " . $db->quote($table))->fetchColumn();
    if (!$exists) json_response(false, 'Table not found.');

    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    $db->exec("TRUNCATE TABLE `$table`");
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
    json_response(true, "Table cleared.", ['truncated' => $table]);
}

// ─── TRUNCATE ALL ──────────────────────────────────────────────
if ($action === 'admin_db_truncate_all') {
    $userProtected = getUserProtected($db);
    $allProtected  = array_merge($PERMANENT_PROTECTED, $userProtected);
    $allTables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    $truncated = [];
    foreach ($allTables as $t) {
        if (!in_array($t, $allProtected)) {
            $db->exec("TRUNCATE TABLE `$t`");
            $truncated[] = $t;
        }
    }
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
    json_response(true, count($truncated) . ' tables cleared.', [
        'truncated' => $truncated, 'count' => count($truncated),
    ]);
}
// ─── t211: DB BACKUP ───────────────────────────────────────────
if ($action === 'admin_db_backup') {
    // Verify mysqldump available
    $mysqldump = trim(shell_exec('which mysqldump 2>/dev/null') ?: '');
    if (!$mysqldump) $mysqldump = 'mysqldump'; // fallback, hope it's in PATH

    // Read DB credentials from config
    $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
    $dbName = defined('DB_NAME') ? DB_NAME : 'wealthdash';
    $dbUser = defined('DB_USER') ? DB_USER : 'root';
    $dbPass = defined('DB_PASS') ? DB_PASS : '';

    $backupDir = APP_ROOT . '/database/backups';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

    $filename   = 'wealthdash_backup_' . date('Y-m-d_His') . '.sql';
    $filePath   = $backupDir . '/' . $filename;
    $gzPath     = $filePath . '.gz';

    // Build mysqldump command — password via env to avoid shell history exposure
    $passArg = $dbPass !== '' ? '-p' . escapeshellarg($dbPass) : '';
    $cmd = sprintf(
        'mysqldump -h %s -u %s %s --single-transaction --routines --triggers %s > %s 2>&1',
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        $passArg,
        escapeshellarg($dbName),
        escapeshellarg($filePath)
    );

    $output = null;
    $retval = null;
    exec($cmd, $output, $retval);

    if ($retval !== 0 || !file_exists($filePath) || filesize($filePath) < 100) {
        @unlink($filePath);
        json_response(false, 'mysqldump failed. Output: ' . implode(' ', (array)$output));
    }

    // Compress with gzip
    $gz = gzopen($gzPath, 'wb9');
    $fh = fopen($filePath, 'rb');
    while (!feof($fh)) gzwrite($gz, fread($fh, 65536));
    fclose($fh); gzclose($gz);
    @unlink($filePath); // remove uncompressed

    $size = file_exists($gzPath) ? filesize($gzPath) : 0;

    // Save backup record in app_settings (json list)
    $listJson = $db->query("SELECT setting_val FROM app_settings WHERE setting_key='db_backup_list'")->fetchColumn();
    $list = $listJson ? json_decode($listJson, true) : [];
    array_unshift($list, [
        'file'    => $filename . '.gz',
        'size'    => $size,
        'created' => date('Y-m-d H:i:s'),
    ]);
    $list = array_slice($list, 0, 20); // keep last 20
    $newJson = json_encode($list);
    $exists = $db->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='db_backup_list'")->fetchColumn();
    if ($exists) {
        $db->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='db_backup_list'")->execute([$newJson]);
    } else {
        $db->prepare("INSERT INTO app_settings (setting_key,setting_val) VALUES ('db_backup_list',?)")->execute([$newJson]);
    }

    json_response(true, 'Backup created successfully.', [
        'file'    => $filename . '.gz',
        'size'    => $size,
        'size_fmt'=> $size > 1048576 ? round($size/1048576,2).'MB' : round($size/1024,1).'KB',
        'created' => date('Y-m-d H:i:s'),
    ]);
}

// ─── t211: BACKUP LIST ─────────────────────────────────────────
if ($action === 'admin_db_backup_list') {
    $listJson = $db->query("SELECT setting_val FROM app_settings WHERE setting_key='db_backup_list'")->fetchColumn();
    $list = $listJson ? json_decode($listJson, true) : [];

    // Verify files still exist on disk
    $backupDir = APP_ROOT . '/database/backups';
    foreach ($list as &$item) {
        $item['exists'] = file_exists($backupDir . '/' . $item['file']);
        $item['size_fmt'] = isset($item['size'])
            ? ($item['size'] > 1048576 ? round($item['size']/1048576,2).'MB' : round($item['size']/1024,1).'KB')
            : '—';
    }
    unset($item);

    json_response(true, '', $list);
}

// ─── t211: BACKUP DOWNLOAD ─────────────────────────────────────
if ($action === 'admin_db_backup_download') {
    $file = basename(clean($_GET['file'] ?? $_POST['file'] ?? ''));
    if (!$file || !preg_match('/^wealthdash_backup_[\d_-]+\.sql\.gz$/', $file))
        json_response(false, 'Invalid filename.');

    $path = APP_ROOT . '/database/backups/' . $file;
    if (!file_exists($path)) json_response(false, 'Backup file not found.');

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ─── t211: BACKUP DELETE ───────────────────────────────────────
if ($action === 'admin_db_backup_delete') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $file = basename(clean($body['file'] ?? ''));
    if (!$file || !preg_match('/^wealthdash_backup_[\d_-]+\.sql\.gz$/', $file))
        json_response(false, 'Invalid filename.');

    $path = APP_ROOT . '/database/backups/' . $file;
    @unlink($path);

    // Remove from list
    $listJson = $db->query("SELECT setting_val FROM app_settings WHERE setting_key='db_backup_list'")->fetchColumn();
    $list = $listJson ? json_decode($listJson, true) : [];
    $list = array_values(array_filter($list, fn($i) => $i['file'] !== $file));
    $db->prepare("UPDATE app_settings SET setting_val=? WHERE setting_key='db_backup_list'")->execute([json_encode($list)]);

    json_response(true, 'Backup deleted.');
}
