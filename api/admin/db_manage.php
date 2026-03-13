<?php
/**
 * WealthDash — DB Table Management API
 * Called via router.php with actions:
 *   admin_db_list         → list all tables with row counts
 *   admin_db_truncate_one → truncate a single table
 *   admin_db_truncate_all → truncate all non-protected tables
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$db = DB::conn();

// Tables that should NEVER be truncated
$PROTECTED = [
    'funds',
    'fund_houses',
    'sessions',
    'users',
    'app_settings',
];

// ─── LIST ────────────────────────────────────────────────────
if ($action === 'admin_db_list') {
    // Get list of tables
    $tableNames = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $tables = [];
    foreach ($tableNames as $tName) {
        // Get row count individually — more reliable than SHOW TABLE STATUS
        try {
            $count = (int)$db->query("SELECT COUNT(*) FROM `$tName`")->fetchColumn();
        } catch (Exception $e) {
            $count = 0;
        }
        $tables[] = [
            'name'      => $tName,
            'rows'      => $count,
            'protected' => in_array($tName, $PROTECTED),
        ];
    }
    json_response(true, '', $tables);
}

// ─── TRUNCATE ONE ────────────────────────────────────────────
if ($action === 'admin_db_truncate_one') {
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $table = clean($body['table'] ?? $_POST['table'] ?? '');

    if (!$table || in_array($table, $PROTECTED)) {
        json_response(false, 'Table is protected or invalid.');
    }
    $exists = $db->query("SHOW TABLES LIKE " . $db->quote($table))->fetchColumn();
    if (!$exists) json_response(false, 'Table not found.');

    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    $db->exec("TRUNCATE TABLE `$table`");
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
    json_response(true, "Table cleared.", ['truncated' => $table]);
}

// ─── TRUNCATE ALL ────────────────────────────────────────────
if ($action === 'admin_db_truncate_all') {
    $allTables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    $truncated = [];
    foreach ($allTables as $t) {
        if (!in_array($t, $PROTECTED)) {
            $db->exec("TRUNCATE TABLE `$t`");
            $truncated[] = $t;
        }
    }
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
    json_response(true, count($truncated) . ' tables cleared.', [
        'truncated' => $truncated,
        'count'     => count($truncated),
    ]);
}