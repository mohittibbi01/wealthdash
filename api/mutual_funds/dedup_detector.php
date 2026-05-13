<?php
/**
 * WealthDash — Duplicate Transaction Detector
 * Task: t479
 * Worker: ID-M
 *
 * Actions:
 *   dedup_scan        — Scan portfolio for duplicates (MF + Stocks)
 *   dedup_list        — List detected duplicate groups
 *   dedup_merge       — Mark one txn as duplicate, keep the other
 *   dedup_dismiss     — Dismiss a false-positive duplicate flag
 *   dedup_check_new   — Pre-insert check: is this txn a duplicate? (called before mf_add/stocks_add)
 *   dedup_stats       — Summary count of duplicates per asset type
 */
defined('WEALTHDASH') or die();

$portfolioId = (int)($_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);
if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid portfolio.');
}

switch ($action) {

    // ── Scan portfolio & flag duplicates ─────────────────────────
    case 'dedup_scan':
        $result = [
            'mf'     => _dedup_scan_mf($portfolioId),
            'stocks' => _dedup_scan_stocks($portfolioId),
        ];
        json_response(true, 'Scan complete.', $result);

    // ── List duplicate groups ─────────────────────────────────────
    case 'dedup_list':
        $assetType = clean($_POST['asset_type'] ?? 'mf');

        if ($assetType === 'mf') {
            $rows = DB::fetchAll(
                "SELECT t.*, f.scheme_name AS fund_name
                 FROM mf_transactions t
                 JOIN funds f ON f.id = t.fund_id
                 WHERE t.portfolio_id = ? AND t.is_duplicate = 1
                 ORDER BY t.dedup_hash, t.txn_date DESC",
                [$portfolioId]
            );
        } elseif ($assetType === 'stocks') {
            $rows = DB::fetchAll(
                "SELECT t.*, s.symbol, s.company_name
                 FROM stock_transactions t
                 JOIN stock_master s ON s.id = t.stock_id
                 WHERE t.portfolio_id = ? AND t.is_duplicate = 1
                 ORDER BY t.dedup_hash, t.txn_date DESC",
                [$portfolioId]
            );
        } else {
            json_response(false, 'Invalid asset_type. Use: mf, stocks');
        }

        // Group by dedup_hash
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['dedup_hash']][] = $row;
        }
        json_response(true, '', [
            'asset_type'  => $assetType,
            'group_count' => count($groups),
            'groups'      => array_values($groups),
        ]);

    // ── Merge: mark txn as duplicate of another ───────────────────
    case 'dedup_merge':
        $assetType   = clean($_POST['asset_type'] ?? 'mf');
        $dupTxnId    = (int)($_POST['dup_txn_id'] ?? 0);
        $keepTxnId   = (int)($_POST['keep_txn_id'] ?? 0);

        if (!$dupTxnId || !$keepTxnId || $dupTxnId === $keepTxnId) {
            json_response(false, 'dup_txn_id and keep_txn_id required and must differ.');
        }

        $table = $assetType === 'stocks' ? 'stock_transactions' : 'mf_transactions';
        // Verify ownership
        $dup  = DB::fetchOne("SELECT * FROM `$table` WHERE id=? AND portfolio_id=?", [$dupTxnId, $portfolioId]);
        $keep = DB::fetchOne("SELECT * FROM `$table` WHERE id=? AND portfolio_id=?", [$keepTxnId, $portfolioId]);
        if (!$dup || !$keep) json_response(false, 'Transaction not found or access denied.');

        DB::run("UPDATE `$table` SET is_duplicate=1, duplicate_of=? WHERE id=?", [$keepTxnId, $dupTxnId]);
        DB::run(
            "INSERT INTO dedup_review_log (asset_type, txn_id, action, reviewed_by) VALUES (?,?,?,?)",
            [$assetType, $dupTxnId, 'merged', $userId]
        );
        audit_log('dedup_merge', $table, $dupTxnId, $dup, ['duplicate_of' => $keepTxnId]);
        json_response(true, 'Transaction marked as duplicate.');

    // ── Dismiss false positive ────────────────────────────────────
    case 'dedup_dismiss':
        $assetType = clean($_POST['asset_type'] ?? 'mf');
        $txnId     = (int)($_POST['txn_id'] ?? 0);
        if (!$txnId) json_response(false, 'txn_id required.');

        $table = $assetType === 'stocks' ? 'stock_transactions' : 'mf_transactions';
        $txn   = DB::fetchOne("SELECT * FROM `$table` WHERE id=? AND portfolio_id=?", [$txnId, $portfolioId]);
        if (!$txn) json_response(false, 'Transaction not found or access denied.');

        DB::run("UPDATE `$table` SET is_duplicate=0, duplicate_of=NULL WHERE id=?", [$txnId]);
        DB::run(
            "INSERT INTO dedup_review_log (asset_type, txn_id, action, reviewed_by) VALUES (?,?,?,?)",
            [$assetType, $txnId, 'dismissed', $userId]
        );
        json_response(true, 'Duplicate flag dismissed.');

    // ── Pre-insert duplicate check ────────────────────────────────
    case 'dedup_check_new':
        $assetType = clean($_POST['asset_type'] ?? 'mf');
        $isDuplicate = false;
        $matches     = [];

        if ($assetType === 'mf') {
            $fundId  = (int)($_POST['fund_id'] ?? 0);
            $txnDate = clean($_POST['txn_date'] ?? '');
            $txnType = clean($_POST['txn_type'] ?? '');
            $units   = (float)($_POST['units'] ?? 0);
            $amount  = (float)($_POST['amount'] ?? 0);

            if ($fundId && $txnDate && $txnType) {
                $hash    = _dedup_hash_mf($portfolioId, $fundId, $txnDate, $txnType, $units, $amount);
                $matches = DB::fetchAll(
                    "SELECT t.*, f.scheme_name AS fund_name FROM mf_transactions t
                     JOIN funds f ON f.id = t.fund_id
                     WHERE t.portfolio_id=? AND t.fund_id=? AND t.txn_date=?
                       AND t.txn_type=? AND ABS(t.units - ?) < 0.01 AND ABS(t.amount - ?) < 1",
                    [$portfolioId, $fundId, $txnDate, $txnType, $units, $amount]
                );
                $isDuplicate = count($matches) > 0;
            }
        } elseif ($assetType === 'stocks') {
            $stockId  = (int)($_POST['stock_id'] ?? 0);
            $txnDate  = clean($_POST['txn_date'] ?? '');
            $txnType  = clean($_POST['txn_type'] ?? '');
            $quantity = (float)($_POST['quantity'] ?? 0);
            $price    = (float)($_POST['price'] ?? 0);

            if ($stockId && $txnDate && $txnType) {
                $matches = DB::fetchAll(
                    "SELECT t.*, s.symbol FROM stock_transactions t
                     JOIN stock_master s ON s.id = t.stock_id
                     WHERE t.portfolio_id=? AND t.stock_id=? AND t.txn_date=?
                       AND t.txn_type=? AND ABS(t.quantity - ?) < 0.001 AND ABS(t.price - ?) < 0.01",
                    [$portfolioId, $stockId, $txnDate, $txnType, $quantity, $price]
                );
                $isDuplicate = count($matches) > 0;
            }
        }

        json_response(true, '', [
            'is_duplicate' => $isDuplicate,
            'matches'      => $matches,
        ]);

    // ── Stats ──────────────────────────────────────────────────────
    case 'dedup_stats':
        $mfCount     = (int)DB::fetchVal("SELECT COUNT(*) FROM mf_transactions WHERE portfolio_id=? AND is_duplicate=1", [$portfolioId]);
        $stocksCount = (int)DB::fetchVal("SELECT COUNT(*) FROM stock_transactions WHERE portfolio_id=? AND is_duplicate=1", [$portfolioId]);
        json_response(true, '', [
            'mf'     => $mfCount,
            'stocks' => $stocksCount,
            'total'  => $mfCount + $stocksCount,
        ]);

    default:
        json_response(false, 'Unknown dedup action.', [], 400);
}

// ────────────────────────────────────────────────────────────────
// INTERNAL SCAN FUNCTIONS
// ────────────────────────────────────────────────────────────────

function _dedup_hash_mf(int $pid, int $fid, string $date, string $type, float $units, float $amount): string {
    return hash('sha256', "{$pid}|{$fid}|{$date}|{$type}|" . round($units, 4) . '|' . round($amount, 2));
}

function _dedup_hash_stocks(int $pid, int $sid, string $date, string $type, float $qty, float $price): string {
    return hash('sha256', "{$pid}|{$sid}|{$date}|{$type}|" . round($qty, 4) . '|' . round($price, 4));
}

function _dedup_scan_mf(int $portfolioId): array {
    // Get all MF transactions for portfolio
    $txns = DB::fetchAll(
        "SELECT id, fund_id, txn_date, txn_type, units, amount FROM mf_transactions
         WHERE portfolio_id=? AND is_duplicate=0 ORDER BY txn_date, fund_id",
        [$portfolioId]
    );

    $seen       = [];
    $duplicates = [];

    foreach ($txns as $txn) {
        $hash = _dedup_hash_mf(
            $portfolioId, (int)$txn['fund_id'],
            $txn['txn_date'], $txn['txn_type'],
            (float)$txn['units'], (float)$txn['amount']
        );
        if (isset($seen[$hash])) {
            $duplicates[] = $txn['id'];
            // Update hash & flag in DB
            DB::run(
                "UPDATE mf_transactions SET dedup_hash=?, is_duplicate=1, duplicate_of=? WHERE id=?",
                [$hash, $seen[$hash], $txn['id']]
            );
        } else {
            $seen[$hash] = $txn['id'];
            DB::run("UPDATE mf_transactions SET dedup_hash=? WHERE id=?", [$hash, $txn['id']]);
        }
    }

    return ['scanned' => count($txns), 'duplicates_found' => count($duplicates)];
}

function _dedup_scan_stocks(int $portfolioId): array {
    $txns = DB::fetchAll(
        "SELECT id, stock_id, txn_date, txn_type, quantity, price FROM stock_transactions
         WHERE portfolio_id=? AND is_duplicate=0 ORDER BY txn_date, stock_id",
        [$portfolioId]
    );

    $seen       = [];
    $duplicates = [];

    foreach ($txns as $txn) {
        $hash = _dedup_hash_stocks(
            $portfolioId, (int)$txn['stock_id'],
            $txn['txn_date'], $txn['txn_type'],
            (float)$txn['quantity'], (float)$txn['price']
        );
        if (isset($seen[$hash])) {
            $duplicates[] = $txn['id'];
            DB::run(
                "UPDATE stock_transactions SET dedup_hash=?, is_duplicate=1, duplicate_of=? WHERE id=?",
                [$hash, $seen[$hash], $txn['id']]
            );
        } else {
            $seen[$hash] = $txn['id'];
            DB::run("UPDATE stock_transactions SET dedup_hash=? WHERE id=?", [$hash, $txn['id']]);
        }
    }

    return ['scanned' => count($txns), 'duplicates_found' => count($duplicates)];
}
