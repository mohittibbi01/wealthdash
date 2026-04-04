<?php
/**
 * WealthDash — t207: Net Worth Timeline
 * GET  /api/?action=nw_timeline           → fetch last 24 snapshots for user
 * POST /api/?action=nw_snapshot_save      → save today's snapshot (called by cron or manual)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$subAction = clean($_REQUEST['sub'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'save' : 'fetch'));

// ── FETCH: return last 24 monthly snapshots ───────────────────────────────
if ($subAction === 'fetch') {
    try {
        $rows = DB::fetchAll(
            "SELECT snapshot_date, total_value, mf_value, stock_value,
                    fd_value, savings_value, nps_value, po_value, gold_value
             FROM net_worth_snapshots
             WHERE user_id = ?
             ORDER BY snapshot_date DESC
             LIMIT 24",
            [$userId]
        );
        // Reverse so oldest first (for chart left→right)
        $rows = array_reverse($rows);
        json_response(true, '', ['snapshots' => $rows]);
    } catch (\Throwable $e) {
        // Table might not exist yet
        json_response(true, '', ['snapshots' => [], 'note' => 'Run migration 033_networth_timeline.sql first']);
    }
    exit;
}

// ── SAVE: compute current net worth and INSERT/UPDATE snapshot ────────────
if ($subAction === 'save') {
    $portfolioId = get_user_portfolio_id($userId);
    if (!$portfolioId) {
        json_response(false, 'Portfolio not found');
        exit;
    }

    $today      = date('Y-m-d');
    $snapDate   = date('Y-m-01'); // Always 1st of month

    // MF
    $mf = DB::fetchRow(
        "SELECT COALESCE(SUM(value_now),0) AS v FROM mf_holdings WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );
    $mfVal = (float)($mf['v'] ?? 0);

    // Stocks
    $st = DB::fetchRow(
        "SELECT COALESCE(SUM(h.quantity * sm.latest_price),0) AS v
         FROM stock_holdings h
         JOIN stock_master sm ON sm.id = h.stock_id
         WHERE h.portfolio_id = ? AND h.quantity > 0",
        [$portfolioId]
    );
    $stVal = (float)($st['v'] ?? 0);

    // FD (principal + accrued interest approx)
    $fd = DB::fetchRow(
        "SELECT COALESCE(SUM(maturity_amount),0) AS mat, COALESCE(SUM(principal),0) AS pri
         FROM fd_accounts WHERE portfolio_id = ? AND status = 'active'",
        [$portfolioId]
    );
    $fdVal = (float)($fd['pri'] ?? 0); // Use principal as conservative estimate

    // Savings
    $sav = DB::fetchRow(
        "SELECT COALESCE(SUM(balance),0) AS v FROM savings_accounts WHERE portfolio_id = ?",
        [$portfolioId]
    );
    $savVal = (float)($sav['v'] ?? 0);

    // NPS
    $nps = DB::fetchRow(
        "SELECT COALESCE(SUM(current_value),0) AS v FROM nps_holdings WHERE user_id = ?",
        [$userId]
    );
    $npsVal = (float)($nps['v'] ?? 0);

    // Post Office
    $po = DB::fetchRow(
        "SELECT COALESCE(SUM(principal),0) AS v FROM po_schemes WHERE user_id = ? AND status = 'active'",
        [$userId]
    );
    $poVal = (float)($po['v'] ?? 0);

    $total = $mfVal + $stVal + $fdVal + $savVal + $npsVal + $poVal;

    try {
        DB::query(
            "INSERT INTO net_worth_snapshots
               (user_id, snapshot_date, total_value, mf_value, stock_value,
                fd_value, savings_value, nps_value, po_value, gold_value)
             VALUES (?,?,?,?,?,?,?,?,?,0)
             ON DUPLICATE KEY UPDATE
               total_value   = VALUES(total_value),
               mf_value      = VALUES(mf_value),
               stock_value   = VALUES(stock_value),
               fd_value      = VALUES(fd_value),
               savings_value = VALUES(savings_value),
               nps_value     = VALUES(nps_value),
               po_value      = VALUES(po_value),
               gold_value    = 0",
            [$userId, $snapDate, $total, $mfVal, $stVal, $fdVal, $savVal, $npsVal, $poVal]
        );
        json_response(true, 'Snapshot saved', [
            'snapshot_date' => $snapDate,
            'total_value'   => round($total, 2),
        ]);
    } catch (\Throwable $e) {
        json_response(false, 'Failed to save snapshot: ' . $e->getMessage());
    }
    exit;
}

json_response(false, 'Invalid sub-action');
