<?php
/**
 * WealthDash — Portfolio Audit Log API
 * Task t482: Who changed what — filterable timeline, undo last, export CSV
 * Actions: audit_log_list | audit_log_undo | audit_log_export | audit_log_stats
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'audit_log_list';
$db          = DB::conn();

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// audit_log_list — paginated, filterable audit trail
// ══════════════════════════════════════════════════════════════════════════
case 'audit_log_list':
    $page     = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
    $limit    = 50;
    $offset   = ($page - 1) * $limit;
    $filter   = trim($_POST['filter'] ?? $_GET['filter'] ?? '');   // action type
    $entity   = trim($_POST['entity'] ?? $_GET['entity'] ?? '');   // entity_type
    $search   = trim($_POST['search'] ?? $_GET['search'] ?? '');
    $dateFrom = trim($_POST['date_from'] ?? $_GET['date_from'] ?? '');
    $dateTo   = trim($_POST['date_to']   ?? $_GET['date_to']   ?? '');

    $where  = ['a.user_id = ?'];
    $params = [$userId];

    // Restrict to last 1 year
    $where[]  = 'a.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)';

    if ($filter)   { $where[] = 'a.action = ?';       $params[] = $filter; }
    if ($entity)   { $where[] = 'a.entity_type = ?';  $params[] = $entity; }
    if ($search)   { $where[] = '(a.action LIKE ? OR a.entity_type LIKE ? OR a.new_values LIKE ?)';
                     $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($dateFrom) { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $dateTo; }

    $whereStr = implode(' AND ', $where);

    // Total count
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM audit_log a WHERE $whereStr");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    // Rows
    $stmt = $db->prepare("
        SELECT
          a.id,
          a.action,
          a.entity_type,
          a.entity_id,
          a.old_values,
          a.new_values,
          a.ip_address,
          a.created_at,
          -- Try to get a friendly name for the entity
          CASE a.entity_type
            WHEN 'mf_holding'   THEN (SELECT f.fund_name FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id WHERE mh.id=a.entity_id LIMIT 1)
            WHEN 'mf_txn'       THEN CONCAT('Txn #', a.entity_id)
            WHEN 'fd'           THEN (SELECT CONCAT(bank_name,' FD') FROM fd_holdings WHERE id=a.entity_id LIMIT 1)
            WHEN 'nps'          THEN CONCAT('NPS #', a.entity_id)
            ELSE NULL
          END AS entity_name,
          -- Human readable action label
          CASE a.action
            WHEN 'mf_add'           THEN '➕ MF Added'
            WHEN 'mf_edit'          THEN '✏️ MF Edited'
            WHEN 'mf_fund_delete'   THEN '🗑️ MF Deleted'
            WHEN 'mf_txn_delete'    THEN '🗑️ Transaction Deleted'
            WHEN 'mf_txn_add'       THEN '➕ Transaction Added'
            WHEN 'mf_txn_edit'      THEN '✏️ Transaction Edited'
            WHEN 'fd_add'           THEN '➕ FD Added'
            WHEN 'fd_edit'          THEN '✏️ FD Edited'
            WHEN 'fd_delete'        THEN '🗑️ FD Deleted'
            WHEN 'nps_add'          THEN '➕ NPS Added'
            WHEN 'nps_delete'       THEN '🗑️ NPS Deleted'
            WHEN 'goal_add'         THEN '🎯 Goal Added'
            WHEN 'goal_edit'        THEN '✏️ Goal Edited'
            WHEN 'goal_delete'      THEN '🗑️ Goal Deleted'
            WHEN 'settings_update'  THEN '⚙️ Settings Updated'
            ELSE a.action
          END AS action_label,
          CASE
            WHEN a.action LIKE '%delete%' OR a.action LIKE '%Delete%' THEN 'delete'
            WHEN a.action LIKE '%edit%'   OR a.action LIKE '%update%' THEN 'edit'
            WHEN a.action LIKE '%add%'    OR a.action LIKE '%create%' THEN 'add'
            ELSE 'other'
          END AS action_type
        FROM audit_log a
        WHERE $whereStr
        ORDER BY a.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse JSON columns
    foreach ($rows as &$row) {
        $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
        $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
    }

    // Distinct action types for filter dropdown
    $actStmt = $db->prepare("
        SELECT DISTINCT action FROM audit_log
        WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
        ORDER BY action
    ");
    $actStmt->execute([$userId]);
    $actionTypes = $actStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'data'    => [
            'rows'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'pages'        => (int)ceil($total / $limit),
            'action_types' => $actionTypes,
        ]
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// audit_log_stats — summary counts by action type
// ══════════════════════════════════════════════════════════════════════════
case 'audit_log_stats':
    $stmt = $db->prepare("
        SELECT
          COUNT(*) AS total_changes,
          SUM(CASE WHEN action LIKE '%add%' OR action LIKE '%create%' THEN 1 ELSE 0 END)   AS total_adds,
          SUM(CASE WHEN action LIKE '%edit%' OR action LIKE '%update%' THEN 1 ELSE 0 END)  AS total_edits,
          SUM(CASE WHEN action LIKE '%delete%' THEN 1 ELSE 0 END) AS total_deletes,
          MAX(created_at) AS last_change,
          COUNT(DISTINCT DATE(created_at)) AS active_days
        FROM audit_log
        WHERE user_id = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Last 30 days activity heatmap
    $heatStmt = $db->prepare("
        SELECT DATE(created_at) AS day, COUNT(*) AS cnt
        FROM audit_log
        WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day
    ");
    $heatStmt->execute([$userId]);
    $heatmap = $heatStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true, 'data'=>['stats'=>$stats,'heatmap'=>$heatmap]]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// audit_log_export — CSV download of audit trail
// ══════════════════════════════════════════════════════════════════════════
case 'audit_log_export':
    $stmt = $db->prepare("
        SELECT
          a.created_at   AS `Date & Time`,
          a.action       AS `Action`,
          a.entity_type  AS `Entity Type`,
          a.entity_id    AS `Entity ID`,
          a.old_values   AS `Old Values`,
          a.new_values   AS `New Values`,
          a.ip_address   AS `IP Address`
        FROM audit_log a
        WHERE a.user_id=?
          AND a.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wealthdash_audit_log_' . date('Ymd') . '.csv"');
    $f = fopen('php://output', 'w');
    if (!empty($rows)) {
        fputcsv($f, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($f, $row);
    }
    fclose($f);
    exit;

default:
    echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
}
