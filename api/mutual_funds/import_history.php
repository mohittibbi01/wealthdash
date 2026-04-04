<?php
/**
 * WealthDash — t190: Import History API
 * Returns paginated import log for current user
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$user = require_auth();
$userId = (int)$user['id'];

header('Content-Type: application/json');

try {
    // Check if import_logs table exists
    DB::conn()->query("SELECT 1 FROM import_logs LIMIT 1");
} catch (Exception $e) {
    json_response(true, '', ['logs' => [], 'total' => 0]);
    exit;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(50, max(5, (int)($_GET['per_page'] ?? 20)));
$offset  = ($page - 1) * $perPage;

try {
    $total = (int)DB::fetchOne(
        "SELECT COUNT(*) AS cnt FROM import_logs WHERE user_id = ?",
        [$userId]
    )['cnt'];

    $rows = DB::fetchAll(
        "SELECT id, filename, format, imported_count, failed_count, skipped_count,
                status, error_log, imported_at
         FROM import_logs
         WHERE user_id = ?
         ORDER BY imported_at DESC
         LIMIT ? OFFSET ?",
        [$userId, $perPage, $offset]
    );

    json_response(true, '', [
        'logs'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
} catch (Exception $e) {
    json_response(false, 'Failed to fetch import history: ' . $e->getMessage());
}
