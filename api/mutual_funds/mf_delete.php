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

// ── FUND-LEVEL DELETE MODE ─────────────────────────────────────
// Called with { fund_ids: [1,2,...], portfolio_id: X, csrf_token }
// Deletes ALL transactions + holdings rows for given fund(s)
$fundIdsRaw = $input['fund_ids'] ?? null;
if ($fundIdsRaw !== null) {
    $fundIds     = array_filter(array_map('intval', (array)$fundIdsRaw));
    $portfolioId = (int)($input['portfolio_id'] ?? 0);

    if (empty($fundIds)) {
        delete_json_die(false, 'No fund IDs provided', 400);
    }

    try {
        $db = DB::conn();

        // Verify each fund belongs to this user
        $placeholders = implode(',', array_fill(0, count($fundIds), '?'));
        $chkStmt = $db->prepare("
            SELECT DISTINCT t.fund_id
            FROM mf_transactions t
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.fund_id IN ($placeholders)
              AND p.user_id = ?
              " . ($portfolioId > 0 ? "AND t.portfolio_id = ?" : "") . "
        ");
        $params = array_merge($fundIds, [$currentUser['id']]);
        if ($portfolioId > 0) $params[] = $portfolioId;
        $chkStmt->execute($params);
        $allowed = array_column($chkStmt->fetchAll(), 'fund_id');

        // Only delete funds that user owns
        $toDelete = array_intersect($fundIds, $allowed);
        if (empty($toDelete)) {
            delete_json_die(false, 'No matching funds found or access denied', 403);
        }

        $db->beginTransaction();
        $ph2 = implode(',', array_fill(0, count($toDelete), '?'));
        $baseParams = $portfolioId > 0
            ? array_merge($toDelete, [$portfolioId])
            : $toDelete;
        $portWhere = $portfolioId > 0 ? " AND portfolio_id = ?" : "";

        // Delete transactions
        $db->prepare("DELETE FROM mf_transactions WHERE fund_id IN ($ph2)$portWhere")
           ->execute($baseParams);

        // Delete holdings rows
        $db->prepare("DELETE FROM mf_holdings WHERE fund_id IN ($ph2)$portWhere")
           ->execute($baseParams);

        foreach ($toDelete as $fid) {
            audit_log_pdo($db, $currentUser['id'], 'mf_fund_delete', "fund:$fid portfolio:$portfolioId", '');
        }

        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => count($toDelete) . ' fund(s) deleted successfully.',
            'deleted_fund_ids' => $toDelete,
        ]);
        exit;

    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log('[WealthDash] fund_delete error: ' . $e->getMessage());
        delete_json_die(false, 'Server error: ' . $e->getMessage(), 500);
    }
}
// ── END FUND-LEVEL DELETE ──────────────────────────────────────

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