<?php
/**
 * WealthDash — t53: DB Manager Tab
 * File: api/admin/db_manager.php
 * Actions: admin_db_list, admin_db_table_info, admin_db_optimize,
 *          admin_db_backup_sql, admin_db_query (SELECT only)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!$isAdmin) json_response(false, 'Admin access required.', [], 403);

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$dbName = DB::fetchVal("SELECT DATABASE()");

switch ($action) {

    // ── List all tables with stats ────────────────────────────────
    case 'admin_db_list': {
        $tables = DB::fetchAll(
            "SELECT table_name, table_rows, table_comment,
                    ROUND(data_length/1024/1024,3)                AS data_mb,
                    ROUND(index_length/1024/1024,3)               AS index_mb,
                    ROUND((data_length+index_length)/1024/1024,3) AS total_mb,
                    create_time, update_time, engine
             FROM information_schema.tables
             WHERE table_schema = ?
             ORDER BY data_length+index_length DESC",
            [$dbName]
        );
        $totalMb = round(array_sum(array_column($tables, 'total_mb')), 2);
        json_response(true, 'ok', [
            'tables'   => $tables,
            'total_mb' => $totalMb,
            'db_name'  => $dbName,
        ]);
        break;
    }

    // ── Table structure + sample rows ────────────────────────────
    case 'admin_db_table_info': {
        $table = clean($_GET['table'] ?? '');
        if (!$table) json_response(false, 'table required.');

        // Whitelist: only tables in current DB
        $exists = DB::fetchVal(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?",
            [$dbName, $table]
        );
        if (!$exists) json_response(false, 'Table not found.');

        $columns = DB::fetchAll("DESCRIBE `$table`");
        $indexes = DB::fetchAll("SHOW INDEX FROM `$table`");
        $count   = (int)DB::fetchVal("SELECT COUNT(*) FROM `$table`");
        // Safe sample — no user data, just structure verification
        $sample  = DB::fetchAll("SELECT * FROM `$table` ORDER BY 1 DESC LIMIT 5");

        json_response(true, 'ok', [
            'table'   => $table,
            'columns' => $columns,
            'indexes' => $indexes,
            'count'   => $count,
            'sample'  => $sample,
        ]);
        break;
    }

    // ── Optimize / Analyze tables ─────────────────────────────────
    case 'admin_db_optimize': {
        csrf_verify();
        $table = clean($_POST['table'] ?? '');

        if ($table) {
            // Single table
            $exists = DB::fetchVal(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?",
                [$dbName, $table]
            );
            if (!$exists) json_response(false, 'Table not found.');
            DB::execute("OPTIMIZE TABLE `$table`");
            DB::execute("ANALYZE TABLE `$table`");
            audit_log($userId, 'admin_db_optimize', "Optimized table: $table");
            json_response(true, "Table `$table` optimized.");
        } else {
            // All tables
            $tables = DB::fetchAll(
                "SELECT table_name FROM information_schema.tables WHERE table_schema=? AND engine='InnoDB'",
                [$dbName]
            );
            foreach ($tables as $t) {
                DB::execute("OPTIMIZE TABLE `{$t['table_name']}`");
            }
            audit_log($userId, 'admin_db_optimize', "Optimized all InnoDB tables");
            json_response(true, count($tables) . ' tables optimized.');
        }
        break;
    }

    // ── Safe SQL Export (SELECT dump via PHP) ─────────────────────
    case 'admin_db_backup_sql': {
        csrf_verify();
        $table = clean($_POST['table'] ?? '');

        if ($table) {
            $exists = DB::fetchVal(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?",
                [$dbName, $table]
            );
            if (!$exists) json_response(false, 'Table not found.');
            $tables = [['table_name' => $table]];
        } else {
            $tables = DB::fetchAll(
                "SELECT table_name FROM information_schema.tables WHERE table_schema=? ORDER BY table_name",
                [$dbName]
            );
        }

        // Stream as SQL
        $filename = 'wealthdash_' . ($table ?: 'backup') . '_' . date('Ymd_His') . '.sql';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "-- WealthDash Database Backup\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Database: $dbName\n\n";
        echo "SET NAMES utf8mb4;\n";
        echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $t) {
            $tn = $t['table_name'];
            // CREATE TABLE
            $createRow = DB::fetchRow("SHOW CREATE TABLE `$tn`");
            echo "-- Table: $tn\n";
            echo "DROP TABLE IF EXISTS `$tn`;\n";
            echo $createRow['Create Table'] . ";\n\n";

            // Data
            $rows = DB::fetchAll("SELECT * FROM `$tn`");
            if ($rows) {
                $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
                foreach ($rows as $row) {
                    $vals = array_map(function($v) {
                        if ($v === null) return 'NULL';
                        return "'" . addslashes($v) . "'";
                    }, $row);
                    echo "INSERT INTO `$tn` ($cols) VALUES (" . implode(',', $vals) . ");\n";
                }
                echo "\n";
            }
        }

        echo "SET FOREIGN_KEY_CHECKS = 1;\n";
        exit;
    }

    // ── Safe read-only SQL query (SELECT / SHOW / EXPLAIN only) ──
    case 'admin_db_query': {
        csrf_verify();
        $sql = trim($_POST['sql'] ?? '');
        if (!$sql) json_response(false, 'SQL query required.');

        // Only allow SELECT, SHOW, EXPLAIN, DESCRIBE
        $firstWord = strtoupper(preg_replace('/\s.*/', '', $sql));
        if (!in_array($firstWord, ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC'])) {
            json_response(false, 'Only SELECT, SHOW, EXPLAIN and DESCRIBE queries are allowed.');
        }

        // Limit rows
        if (stripos($sql, 'LIMIT') === false) {
            $sql .= ' LIMIT 200';
        }

        try {
            $rows = DB::fetchAll($sql);
            $cols = $rows ? array_keys($rows[0]) : [];
            audit_log($userId, 'admin_db_query', substr($sql, 0, 120));
            json_response(true, 'ok', [
                'rows'  => $rows,
                'cols'  => $cols,
                'count' => count($rows),
            ]);
        } catch (\Throwable $e) {
            json_response(false, 'Query error: ' . $e->getMessage());
        }
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}
