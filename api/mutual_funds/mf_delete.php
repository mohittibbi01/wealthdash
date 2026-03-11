<?php
/**
 * WealthDash — MF Delete Transaction
 * POST /api/mutual_funds/mf_delete.php
 * Body: { txn_id, csrf_token }
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/holding_calculator.php';

header('Content-Type: application/json');
$currentUser = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!verify_csrf($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$txn_id = (int)($input['txn_id'] ?? 0);
if ($txn_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
    exit;
}

try {
    $db = DB::conn();

    // Fetch transaction + verify ownership
    $stmt = $db->prepare("
        SELECT t.id, t.portfolio_id, t.fund_id, t.folio_number, p.user_id
        FROM mf_transactions t
        JOIN portfolios p ON p.id = t.portfolio_id
        WHERE t.id = ?
    ");
    $stmt->execute([$txn_id]);
    $txn = $stmt->fetch();

    if (!$txn) {
        echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        exit;
    }

    if ($txn['user_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $db->beginTransaction();

    $del = $db->prepare("DELETE FROM mf_transactions WHERE id = ?");
    $del->execute([$txn_id]);

    audit_log_pdo($db, $currentUser['id'], 'mf_txn_delete', "mf_transactions:$txn_id", '');

    // Recalculate holdings
    recalculate_mf_holdings($db, $txn['portfolio_id'], $txn['fund_id'], $txn['folio_number']);

    $db->commit();

    echo json_encode(['success' => true, 'message' => 'Transaction deleted']);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

