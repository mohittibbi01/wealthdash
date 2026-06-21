<?php
/**
 * WealthDash — Audit Log API [t307]
 * File: api/admin/audit_log.php
 * Worker: ID-M
 * OVERWRITE: YES
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
require_auth(ROLE_ADMIN);

$actingUserId = (int) $_SESSION['user_id'];

// ── Helpers ──────────────────────────────────────────────────────────────────
function write_audit(
    string  $action,
    string  $entityType  = '',
    int     $entityId    = 0,
    array   $old         = [],
    array   $new         = [],
    string  $severity    = 'info'
): void {
    DB::run(
        'INSERT INTO audit_log
         (user_id, action, entity_type, entity_id, old_values, new_values,
          ip_address, user_agent, session_id, severity, request_method, request_uri)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            (int)($_SESSION['user_id'] ?? 0),
            $action,
            $entityType ?: null,
            $entityId   ?: null,
            $old ? json_encode($old) : null,
            $new  ? json_encode($new)  : null,
            $_SERVER['REMOTE_ADDR']     ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
            session_id() ?: null,
            $severity,
            $_SERVER['REQUEST_METHOD']  ?? null,
            substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
        ]
    );
}

switch ($action) {

    // ── LIST with filters + pagination ────────────────────────────────────────
    case 'al_list': {
        $page       = max(1, (int)($_GET['page'] ?? 1));
        $perPage    = min((int)($_GET['per_page'] ?? 50), 200);
        $offset     = ($page - 1) * $perPage;
        $userId     = (int)($_GET['user_id'] ?? 0);
        $entity     = clean($_GET['entity_type'] ?? '');
        $actionQ    = clean($_GET['action'] ?? '');
        $severity   = clean($_GET['severity'] ?? '');
        $dateFrom   = clean($_GET['date_from'] ?? '');
        $dateTo     = clean($_GET['date_to'] ?? '');
        $search     = clean($_GET['search'] ?? '');

        $where  = 'WHERE 1=1';
        $params = [];

        if ($userId)   { $where .= ' AND al.user_id = ?';       $params[] = $userId; }
        if ($entity)   { $where .= ' AND al.entity_type = ?';   $params[] = $entity; }
        if ($actionQ)  { $where .= ' AND al.action LIKE ?';     $params[] = "%{$actionQ}%"; }
        if ($severity && in_array($severity, ['info','warning','critical'])) {
                         $where .= ' AND al.severity = ?';       $params[] = $severity; }
        if ($dateFrom) { $where .= ' AND al.created_at >= ?';   $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo)   { $where .= ' AND al.created_at <= ?';   $params[] = $dateTo   . ' 23:59:59'; }
        if ($search)   {
            $where .= ' AND (al.action LIKE ? OR al.entity_type LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%"; $params[] = "%{$search}%";
            $params[] = "%{$search}%"; $params[] = "%{$search}%";
        }

        $total = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id $where",
            $params
        );

        $rows = DB::fetchAll(
            "SELECT al.*,
                    u.name  AS user_name,
                    u.email AS user_email,
                    u.role  AS user_role
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             $where
             ORDER BY al.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        // Parse JSON columns for display
        foreach ($rows as &$r) {
            $r['old_values'] = $r['old_values'] ? json_decode($r['old_values'], true) : null;
            $r['new_values'] = $r['new_values'] ? json_decode($r['new_values'], true) : null;
        }
        unset($r);

        json_response(true, '', [
            'rows'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    // ── STATS ─────────────────────────────────────────────────────────────────
    case 'al_stats': {
        $days = max(1, min((int)($_GET['days'] ?? 30), 365));

        $summary = DB::fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(severity='critical') as critical,
                SUM(severity='warning')  as warnings,
                SUM(severity='info')     as info,
                COUNT(DISTINCT user_id)  as unique_users,
                COUNT(DISTINCT action)   as unique_actions
             FROM audit_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );

        // Daily trend
        $trend = DB::fetchAll(
            "SELECT DATE(created_at) as date,
                    COUNT(*) as total,
                    SUM(severity='critical') as critical,
                    SUM(severity='warning') as warnings
             FROM audit_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        );

        // Top actions
        $topActions = DB::fetchAll(
            "SELECT action, COUNT(*) as cnt
             FROM audit_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY action ORDER BY cnt DESC LIMIT 15",
            [$days]
        );

        // Top users
        $topUsers = DB::fetchAll(
            "SELECT al.user_id, u.name, u.email, COUNT(*) as cnt
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY al.user_id ORDER BY cnt DESC LIMIT 10",
            [$days]
        );

        // Entity breakdown
        $byEntity = DB::fetchAll(
            "SELECT entity_type, COUNT(*) as cnt
             FROM audit_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND entity_type IS NOT NULL
             GROUP BY entity_type ORDER BY cnt DESC LIMIT 10",
            [$days]
        );

        // Critical events last 7 days
        $criticalRecent = DB::fetchAll(
            "SELECT al.*, u.name, u.email
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.severity = 'critical'
               AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY al.id DESC LIMIT 20"
        );

        json_response(true, '', [
            'summary'         => $summary,
            'trend'           => $trend,
            'top_actions'     => $topActions,
            'top_users'       => $topUsers,
            'by_entity'       => $byEntity,
            'critical_recent' => $criticalRecent,
            'days'            => $days,
        ]);
    }

    // ── SINGLE ENTRY ──────────────────────────────────────────────────────────
    case 'al_get': {
        $id  = (int)($_GET['id'] ?? 0);
        $row = DB::fetchOne(
            'SELECT al.*, u.name, u.email, u.role FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id WHERE al.id = ?',
            [$id]
        );
        if (!$row) json_response(false, 'Entry not found.', [], 404);
        $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
        $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
        json_response(true, '', ['entry' => $row]);
    }

    // ── EXPORT CSV ────────────────────────────────────────────────────────────
    case 'al_export': {
        $days  = max(1, min((int)($_GET['days'] ?? 30), 365));
        $rows  = DB::fetchAll(
            'SELECT al.id, al.created_at, u.name, u.email, al.action, al.entity_type,
                    al.entity_id, al.severity, al.ip_address, al.request_method, al.request_uri
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY al.id DESC',
            [$days]
        );

        $csvLines = ['id,created_at,user_name,user_email,action,entity_type,entity_id,severity,ip_address,method,uri'];
        foreach ($rows as $r) {
            $csvLines[] = implode(',', array_map(function($v) {
                $v = (string)($v ?? '');
                return str_contains($v, ',') || str_contains($v, '"')
                    ? '"' . str_replace('"', '""', $v) . '"' : $v;
            }, array_values($r)));
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
        echo implode("\n", $csvLines);
        exit;
    }

    // ── PURGE OLD ENTRIES ─────────────────────────────────────────────────────
    case 'al_purge': {
        csrf_verify();
        $days = max(30, (int)($_POST['days'] ?? 365));
        $count = (int) DB::fetchVal(
            'SELECT COUNT(*) FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
        DB::run('DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
        DB::run(
            'UPDATE audit_retention_config SET last_purge_at=NOW(), rows_purged=rows_purged+? WHERE id=1',
            [$count]
        );
        write_audit('audit_purge', 'audit_log', 0, ['days' => $days], ['rows_deleted' => $count], 'warning');
        json_response(true, "Purged {$count} entries older than {$days} days.", ['count' => $count]);
    }

    // ── UPDATE RETENTION CONFIG ───────────────────────────────────────────────
    case 'al_retention_save': {
        csrf_verify();
        $days      = max(30, (int)($_POST['retention_days'] ?? 365));
        $autoPurge = (int)(bool)($_POST['auto_purge'] ?? 0);
        DB::run(
            'INSERT INTO audit_retention_config (id, retention_days, auto_purge) VALUES (1,?,?)
             ON DUPLICATE KEY UPDATE retention_days=VALUES(retention_days), auto_purge=VALUES(auto_purge)',
            [$days, $autoPurge]
        );
        json_response(true, 'Retention settings saved.');
    }

    // ── GET RETENTION CONFIG ──────────────────────────────────────────────────
    case 'al_retention_get': {
        $row = DB::fetchOne('SELECT * FROM audit_retention_config WHERE id=1');
        json_response(true, '', ['config' => $row]);
    }

    // ── DISTINCT FILTER VALUES ────────────────────────────────────────────────
    case 'al_filters': {
        $actions  = DB::fetchAll("SELECT DISTINCT action FROM audit_log ORDER BY action LIMIT 100");
        $entities = DB::fetchAll("SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type");
        json_response(true, '', [
            'actions'  => array_column($actions, 'action'),
            'entities' => array_column($entities, 'entity_type'),
        ]);
    }

    default:
        json_response(false, "Unknown action: {$action}", [], 400);
}
