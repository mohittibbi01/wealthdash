<?php
/**
 * WealthDash — MF Delete Transaction
 * POST /api/mutual_funds/mf_delete.php
 * Body: { txn_id, csrf_token }
 */
ob_start();

define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/holding_calculator.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

function delete_json_die(bool $success, string $msg, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => $success, 'message' => $msg]);
    exit;
}

$currentUser = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    delete_json_die(false, 'POST only', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!verify_csrf($input['csrf_token'] ?? '')) {
    delete_json_die(false, 'Invalid CSRF token', 403);
}

$txn_id = (int)($input['txn_id'] ?? 0);
if ($txn_id <= 0) {
    delete_json_die(false, 'Invalid transaction ID', 400);
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
        delete_json_die(false, 'Transaction not found', 404);
    }

    if ($txn['user_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin') {
        delete_json_die(false, 'Access denied', 403);
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
                delete_json_die(false, "Cannot delete: A SELL of {$sell['units']} units on {$sell['txn_date']} depends on this purchase. Delete the SELL transaction first, then delete this BUY.", 422);
            }
        }
    }
    // ────────────────────────────────────────────────────────────

    $del = $db->prepare("DELETE FROM mf_transactions WHERE id = ?");
    $del->execute([$txn_id]);

    audit_log_pdo($db, $currentUser['id'], 'mf_txn_delete', "mf_transactions:$txn_id", '');

    // Recalculate holdings
    recalculate_mf_holdings($db, $txn['portfolio_id'], $txn['fund_id'], $txn['folio_number']);

    $db->commit();

    echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[WealthDash] mf_delete PDO error: ' . $e->getMessage());
    delete_json_die(false, 'Database error. Please try again.', 500);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[WealthDash] mf_delete error: ' . $e->getMessage());
    delete_json_die(false, 'Server error: ' . $e->getMessage(), 500);
}