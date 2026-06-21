<?php
/**
 * WealthDash — Data Versioning & Undo Import API [t336]
 * File: api/admin/data_versioning.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
require_auth();

$actingUser  = (int)$_SESSION['user_id'];
$isAdmin     = is_admin();
$portfolioId = get_user_portfolio_id($actingUser);

// Tables that support undo (whitelist for safety)
const UNDOABLE_TABLES = [
    'mf_holdings', 'mf_transactions', 'sip_schedules',
    'stock_holdings', 'stock_transactions',
    'fd_accounts', 'savings_accounts', 'bank_accounts', 'bank_transactions',
    'nps_holdings', 'nps_transactions',
    'insurance_policies', 'loan_accounts',
    'epf_accounts', 'bonds',
    'gold_holdings', 'post_office_schemes',
];

// ── Helpers ──────────────────────────────────────────────────────────────────
function iv_get_or_fail(int $id, int $userId, bool $adminOk = true): array {
    $where  = ($adminOk && is_admin()) ? 'WHERE id=?' : 'WHERE id=? AND user_id=?';
    $params = ($adminOk && is_admin()) ? [$id] : [$id, $userId];
    $row    = DB::fetchOne("SELECT * FROM import_versions $where", $params);
    if (!$row) json_response(false, 'Import version not found.', [], 404);
    return $row;
}

/**
 * Save a version snapshot BEFORE an import runs.
 * Call this from any import API before inserting rows.
 *
 * Usage:
 *   $versionId = DataVersioning::begin($userId, $portfolioId, 'mf_csv', 'MF Import Jan 2025', 'mf_holdings');
 *   // ... do inserts ...
 *   DataVersioning::commit($versionId, $insertedIds, $addedCount);
 */
class DataVersioning {
    public static function begin(
        int    $userId,
        int    $portfolioId,
        string $importType,
        string $label,
        string $table,
        array  $existingIds = [],  // IDs of rows about to be modified
        string $fileName    = '',
        string $fileHash    = ''
    ): int {
        // Snapshot existing rows that will be modified
        $snapshot = [];
        if (!empty($existingIds) && in_array($table, UNDOABLE_TABLES)) {
            $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
            $snapshot = DB::fetchAll(
                "SELECT * FROM `{$table}` WHERE id IN ({$placeholders})",
                $existingIds
            );
        }

        $id = (int) DB::insert(
            'INSERT INTO import_versions
             (user_id, portfolio_id, import_type, label, snapshot_data, affected_table,
              affected_ids, file_name, file_hash)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $userId, $portfolioId, $importType, $label,
                json_encode($snapshot),
                $table,
                json_encode([]),
                $fileName ?: null,
                $fileHash ?: null,
            ]
        );
        return $id;
    }

    public static function recordInsert(int $versionId, string $table, int $rowId, array $data): void {
        DB::run(
            'INSERT INTO import_row_changes (version_id, table_name, row_id, change_type, old_data, new_data)
             VALUES (?,?,?,\'insert\',NULL,?)',
            [$versionId, $table, $rowId, json_encode($data)]
        );
        // Update affected_ids JSON
        $cur = DB::fetchVal('SELECT affected_ids FROM import_versions WHERE id=?', [$versionId]);
        $ids = json_decode($cur, true) ?? [];
        $ids[] = $rowId;
        DB::run('UPDATE import_versions SET affected_ids=?, rows_added=rows_added+1 WHERE id=?',
            [json_encode(array_unique($ids)), $versionId]);
    }

    public static function recordUpdate(int $versionId, string $table, int $rowId, array $old, array $new): void {
        DB::run(
            'INSERT INTO import_row_changes (version_id, table_name, row_id, change_type, old_data, new_data)
             VALUES (?,?,?,\'update\',?,?)',
            [$versionId, $table, $rowId, json_encode($old), json_encode($new)]
        );
        DB::run('UPDATE import_versions SET rows_modified=rows_modified+1 WHERE id=?', [$versionId]);
    }

    public static function commit(int $versionId): void {
        DB::run("UPDATE import_versions SET status='active' WHERE id=?", [$versionId]);
    }

    public static function abort(int $versionId): void {
        DB::run('DELETE FROM import_versions WHERE id=?', [$versionId]);
    }
}

// ── Routing ──────────────────────────────────────────────────────────────────
switch ($action) {

    // ── LIST VERSIONS ─────────────────────────────────────────────────────────
    case 'dv_list': {
        $where  = $isAdmin && isset($_GET['all']) ? 'WHERE 1=1' : 'WHERE iv.user_id=?';
        $params = $isAdmin && isset($_GET['all']) ? [] : [$actingUser];
        $type   = clean($_GET['type'] ?? '');
        $status = clean($_GET['status'] ?? '');

        if ($type)   { $where .= ' AND iv.import_type=?';  $params[] = $type; }
        if ($status && in_array($status, ['active','undone','partial'])) {
                       $where .= ' AND iv.status=?';        $params[] = $status; }

        $rows = DB::fetchAll(
            "SELECT iv.*,
                    u.name AS user_name,
                    ub.name AS undone_by_name
             FROM import_versions iv
             LEFT JOIN users u  ON u.id  = iv.user_id
             LEFT JOIN users ub ON ub.id = iv.undone_by
             $where
             ORDER BY iv.created_at DESC
             LIMIT 100",
            $params
        );

        // Don't send snapshot_data in list — too large
        foreach ($rows as &$r) {
            $r['affected_ids'] = json_decode($r['affected_ids'], true) ?? [];
            unset($r['snapshot_data']);
        }
        unset($r);

        // Summary counts
        $counts = DB::fetchOne(
            "SELECT SUM(status='active') as active, SUM(status='undone') as undone
             FROM import_versions WHERE user_id=?",
            [$actingUser]
        );

        json_response(true, '', ['versions' => $rows, 'counts' => $counts]);
    }

    // ── GET SINGLE WITH DIFF ──────────────────────────────────────────────────
    case 'dv_get': {
        $id  = (int)($_GET['id'] ?? 0);
        $row = iv_get_or_fail($id, $actingUser);

        // Row changes
        $changes = DB::fetchAll(
            'SELECT id, table_name, row_id, change_type,
                    old_data, new_data
             FROM import_row_changes WHERE version_id=?
             ORDER BY id ASC LIMIT 500',
            [$id]
        );

        foreach ($changes as &$c) {
            $c['old_data'] = $c['old_data'] ? json_decode($c['old_data'], true) : null;
            $c['new_data'] = $c['new_data'] ? json_decode($c['new_data'], true) : null;
        }
        unset($c);

        $row['affected_ids']   = json_decode($row['affected_ids'], true) ?? [];
        $row['snapshot_data']  = null; // don't expose raw snapshot
        $row['changes']        = $changes;

        json_response(true, '', ['version' => $row]);
    }

    // ── UNDO IMPORT ───────────────────────────────────────────────────────────
    case 'dv_undo': {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        $row = iv_get_or_fail($id, $actingUser);

        if ($row['status'] === 'undone') {
            json_response(false, 'This import has already been undone.');
        }
        if (!in_array($row['affected_table'], UNDOABLE_TABLES)) {
            json_response(false, 'This table does not support undo.');
        }

        $changes = DB::fetchAll(
            'SELECT * FROM import_row_changes WHERE version_id=? ORDER BY id DESC',
            [$id]
        );

        if (empty($changes)) {
            // Fallback: use snapshot_data to restore
            $snapshot = json_decode($row['snapshot_data'], true) ?? [];
            $affected = json_decode($row['affected_ids'], true) ?? [];

            DB::beginTransaction();
            try {
                $table = $row['affected_table'];
                // Delete inserted rows
                if (!empty($affected)) {
                    $placeholders = implode(',', array_fill(0, count($affected), '?'));
                    DB::run("DELETE FROM `{$table}` WHERE id IN ({$placeholders})", $affected);
                }
                // Restore modified rows from snapshot
                foreach ($snapshot as $snap) {
                    $cols     = array_keys($snap);
                    $setClause= implode(', ', array_map(fn($c) => "`{$c}`=?", array_filter($cols, fn($c) => $c !== 'id')));
                    $vals     = array_values(array_filter($snap, fn($k) => $k !== 'id', ARRAY_FILTER_USE_KEY));
                    $vals[]   = $snap['id'];
                    DB::run("UPDATE `{$table}` SET {$setClause} WHERE id=?", $vals);
                }
                DB::commit();
            } catch (Exception $e) {
                DB::rollback();
                json_response(false, 'Undo failed: ' . $e->getMessage());
            }
        } else {
            // Granular undo using row-level change log
            DB::beginTransaction();
            try {
                foreach ($changes as $c) {
                    $table  = $c['table_name'];
                    if (!in_array($table, UNDOABLE_TABLES)) continue;

                    if ($c['change_type'] === 'insert') {
                        // Undo insert → delete the row
                        DB::run("DELETE FROM `{$table}` WHERE id=?", [$c['row_id']]);

                    } elseif ($c['change_type'] === 'update') {
                        // Undo update → restore old_data
                        $old = json_decode($c['old_data'], true);
                        if (!$old) continue;
                        $cols      = array_filter(array_keys($old), fn($k) => $k !== 'id');
                        $setClause = implode(', ', array_map(fn($col) => "`{$col}`=?", $cols));
                        $vals      = array_values(array_intersect_key($old, array_flip($cols)));
                        $vals[]    = $c['row_id'];
                        DB::run("UPDATE `{$table}` SET {$setClause} WHERE id=?", $vals);

                    } elseif ($c['change_type'] === 'delete') {
                        // Undo delete → re-insert
                        $new  = json_decode($c['new_data'], true);
                        if (!$new) continue;
                        $cols = array_keys($new);
                        $placeholders = implode(',', array_fill(0, count($cols), '?'));
                        $colList = implode(',', array_map(fn($c) => "`{$c}`", $cols));
                        DB::run(
                            "INSERT IGNORE INTO `{$table}` ({$colList}) VALUES ({$placeholders})",
                            array_values($new)
                        );
                    }
                }
                DB::commit();
            } catch (Exception $e) {
                DB::rollback();
                json_response(false, 'Undo failed: ' . $e->getMessage());
            }
        }

        // Mark undone
        DB::run(
            "UPDATE import_versions SET status='undone', undone_at=NOW(), undone_by=? WHERE id=?",
            [$actingUser, $id]
        );

        // Invalidate cache
        DB::invalidateCache("user:{$actingUser}", "portfolio:{$row['portfolio_id']}");

        $undoneRows = count($changes) ?: count(json_decode($row['affected_ids'], true) ?? []);
        json_response(true, "Import undone successfully. {$undoneRows} change(s) reverted.", [
            'version_id'  => $id,
            'rows_undone' => $undoneRows,
        ]);
    }

    // ── DELETE VERSION RECORD ─────────────────────────────────────────────────
    case 'dv_delete': {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        $row = iv_get_or_fail($id, $actingUser);
        if ($row['status'] === 'active') {
            json_response(false, 'Cannot delete an active version. Undo it first, or it will be cleaned up automatically after 90 days.');
        }
        DB::run('DELETE FROM import_versions WHERE id=?', [$id]);
        json_response(true, 'Version record deleted.');
    }

    // ── PURGE OLD VERSIONS (admin) ────────────────────────────────────────────
    case 'dv_purge': {
        csrf_verify();
        require_auth(ROLE_ADMIN);
        $days = max(30, (int)($_POST['days'] ?? 90));
        $n    = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM import_versions WHERE status='undone' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        DB::run(
            "DELETE FROM import_versions WHERE status='undone' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        json_response(true, "Purged {$n} old version records.", ['count' => $n]);
    }

    // ── STATS ─────────────────────────────────────────────────────────────────
    case 'dv_stats': {
        $stats = DB::fetchOne(
            "SELECT COUNT(*) as total,
                    SUM(status='active') as active,
                    SUM(status='undone') as undone,
                    SUM(rows_added) as total_rows_added,
                    SUM(rows_modified) as total_rows_modified
             FROM import_versions WHERE user_id=?",
            [$actingUser]
        );

        $byType = DB::fetchAll(
            'SELECT import_type, COUNT(*) as cnt, SUM(rows_added) as rows_added
             FROM import_versions WHERE user_id=?
             GROUP BY import_type ORDER BY cnt DESC',
            [$actingUser]
        );

        $recent = DB::fetchAll(
            "SELECT id, label, import_type, rows_added, status, created_at
             FROM import_versions WHERE user_id=?
             ORDER BY created_at DESC LIMIT 10",
            [$actingUser]
        );

        json_response(true, '', [
            'stats'   => $stats,
            'by_type' => $byType,
            'recent'  => $recent,
        ]);
    }

    default:
        json_response(false, "Unknown action: {$action}", [], 400);
}
