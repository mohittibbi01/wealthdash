<?php
/**
 * WealthDash — DB Table Management API
 * Actions: admin_db_list | admin_db_truncate_one | admin_db_truncate_all
 *          admin_db_protect | admin_db_unprotect
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