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

    // ── If deleting a BUY-side txn, check no SELL becomes orphaned ──
    $txnDetail = $db->prepare("
        SELECT transaction_type, units, fund_id, folio_number, portfolio_id, txn_date
        FROM mf_transactions WHERE id = ?
    ");
    $txnDetail->execute([$txn_id]);
    $txnRow = $txnDetail->fetch();

    if ($txnRow && in_array($txnRow['transaction_type'], ['BUY', 'SWITCH_IN', 'DIV_REINVEST'])) {
        $pid      = $txnRow['portfolio_id'];
        $fid      = $txnRow['fund_id'];
        $folio    = $txnRow['folio_number'];
        $buyDate  = $txnRow['txn_date'];

        // Find the earliest SELL that would be affected:
        // Any SELL on a date AFTER this BUY where removing this BUY
        // causes units_bought_before_sell_date < units_sold_by_sell_date
        $sellsQ = $db->prepare("
            SELECT id, txn_date, units FROM mf_transactions
            WHERE portfolio_id  = ?
              AND fund_id       = ?
              AND folio_number <=> ?
              AND transaction_type IN ('SELL','SWITCH_OUT')
              AND txn_date > ?
            ORDER BY txn_date ASC
        ");
        $sellsQ->execute([$pid, $fid, $folio, $buyDate]);
        $sells = $sellsQ->fetchAll();

        foreach ($sells as $sell) {
            // BUY units strictly before this sell date, excluding the txn being deleted
            $bQ = $db->prepare("
                SELECT COALESCE(SUM(units), 0) FROM mf_transactions
                WHERE portfolio_id  = ?
                  AND fund_id       = ?
                  AND folio_number <=> ?
                  AND transaction_type IN ('BUY','SWITCH_IN','DIV_REINVEST')
                  AND txn_date < ?
                  AND id != ?
            ");
            $bQ->execute([$pid, $fid, $folio, $sell['txn_date'], $txn_id]);
            $boughtBefore = (float)$bQ->fetchColumn();

            $sQ = $db->prepare("
                SELECT COALESCE(SUM(units), 0) FROM mf_transactions
                WHERE portfolio_id  = ?
                  AND fund_id       = ?
                  AND folio_number <=> ?
                  AND transaction_type IN ('SELL','SWITCH_OUT')
                  AND txn_date <= ?
            ");
            $sQ->execute([$pid, $fid, $folio, $sell['txn_date']]);
            $soldBy = (float)$sQ->fetchColumn();

            if ($soldBy > $boughtBefore) {
                $db->rollBack();
                $fName = $db->prepare("SELECT scheme_name FROM funds WHERE id=?");
                $fName->execute([$fid]);
                $name = $fName->fetchColumn() ?: 'this fund';
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete: A SELL of {$sell['units']} units on {$sell['txn_date']} depends on this purchase. Delete the SELL transaction first, then delete this BUY."
                ]);
                exit;
            }
        }
    }
    // ────────────────────────────────────────────────────────────
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