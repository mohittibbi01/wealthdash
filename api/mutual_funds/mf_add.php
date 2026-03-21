<?php
/**
 * WealthDash — MF Add/Edit Transaction
 * POST /api/mutual_funds/mf_add.php
 */

// Buffer ALL output so stray PHP warnings never corrupt JSON
ob_start();

define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/holding_calculator.php';

// Discard any accidental output so far, then set JSON header
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// ── Helper: die with JSON ────────────────────────────────────
function json_die(bool $success, string $msg, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => $success, 'message' => $msg]);
    exit;
}

// ── Method check ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_die(false, 'POST only', 405);
}

// ── Auth ─────────────────────────────────────────────────────
$currentUser = require_auth();

// ── Parse input (JSON body OR form POST) ─────────────────────
$raw   = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : null;
if (!$input || !is_array($input)) {
    $input = $_POST;
}
if (empty($input)) {
    json_die(false, 'No input received', 400);
}

// ── CSRF ──────────────────────────────────────────────────────
if (!verify_csrf($input['csrf_token'] ?? '')) {
    json_die(false, 'Invalid or expired CSRF token. Please refresh the page and try again.', 403);
}

// ── Extract & sanitize fields ─────────────────────────────────
$edit_id      = isset($input['edit_id']) && $input['edit_id'] !== '' ? (int)$input['edit_id'] : null;
$portfolio_id = (int)($input['portfolio_id'] ?? 0);
$fund_id      = (int)($input['fund_id'] ?? 0);
$folio        = trim($input['folio_number'] ?? '') ?: null;  // store NULL not '' so FIFO queries match correctly
$txn_type     = strtoupper(trim($input['transaction_type'] ?? ''));
$platform     = trim($input['platform'] ?? '');
$txn_date     = trim($input['txn_date'] ?? '');
$units        = (float)($input['units'] ?? 0);
$nav          = (float)($input['nav'] ?? 0);
$stamp_duty   = (float)($input['stamp_duty'] ?? 0);
$notes        = trim($input['notes'] ?? '');
$investment_fy = trim($input['investment_fy'] ?? '');

// ── Validations ───────────────────────────────────────────────
$errors = [];
if ($portfolio_id <= 0) $errors[] = 'Portfolio is required';
if ($fund_id <= 0)      $errors[] = 'Fund is required';
if (empty($txn_date))   $errors[] = 'Transaction date is required';
if ($units <= 0)        $errors[] = 'Units must be greater than 0';
if ($nav   <= 0)        $errors[] = 'NAV must be greater than 0';

$allowed_types = ['BUY','SELL','DIV_REINVEST','SWITCH_IN','SWITCH_OUT'];
if (!in_array($txn_type, $allowed_types)) {
    $errors[] = 'Invalid transaction type: ' . $txn_type;
}

// Normalize date → Y-m-d
if (!empty($txn_date)) {
    $dt = DateTime::createFromFormat('Y-m-d', $txn_date);
    if (!$dt || $dt->format('Y-m-d') !== $txn_date) {
        $dt2 = DateTime::createFromFormat('d-m-Y', $txn_date);
        if ($dt2) {
            $txn_date = $dt2->format('Y-m-d');
        } else {
            $errors[] = 'Invalid date format. Use YYYY-MM-DD';
        }
    }
}

// ── Date/Market validations (only for BUY/SELL/SWITCH) ────────
$tradingTypes = ['BUY', 'SELL', 'SWITCH_IN', 'SWITCH_OUT'];
if (!empty($txn_date) && in_array($txn_type, $tradingTypes)) {

    // IST timezone
    $tz      = new DateTimeZone('Asia/Kolkata');
    $nowIST  = new DateTime('now', $tz);
    $todayStr = $nowIST->format('Y-m-d');

    // RULE: No future dates
    if ($txn_date > $todayStr) {
        $errors[] = "Transaction date ({$txn_date}) cannot be in the future. Today is {$todayStr}.";
    }

    // RULE: Same-day → check market hours (NSE/BSE: 09:15–15:30 IST, Mon–Fri)
    if ($txn_date === $todayStr && empty($errors)) {
        $dayOfWeek = (int)$nowIST->format('N'); // 1=Mon … 7=Sun
        $timeNow   = (int)$nowIST->format('Hi'); // e.g. 0914, 1530

        if ($dayOfWeek >= 6) {
            $errors[] = "Cannot record a transaction today — Indian stock market is closed on weekends.";
        } elseif ($timeNow < 915) {
            $errors[] = "Market has not opened yet today. NSE/BSE opens at 09:15 AM IST. Current time: " . $nowIST->format('h:i A') . " IST.";
        } elseif ($timeNow > 1530) {
            $errors[] = "Market is closed for today. NSE/BSE closes at 03:30 PM IST. Use tomorrow's date or a past date.";
        }
    }
}
// ─────────────────────────────────────────────────────────────

if (!empty($errors)) {
    json_die(false, implode('. ', $errors), 422);
}

// ── Compute value ─────────────────────────────────────────────
$value_at_cost = round($units * $nav + $stamp_duty, 4);
if (empty($investment_fy)) {
    $investment_fy = get_investment_fy($txn_date);
}

// ── DB operations ─────────────────────────────────────────────
try {
    $db = DB::conn();

    // Verify portfolio access
    $pStmt = $db->prepare("SELECT id, user_id FROM portfolios WHERE id = ?");
    $pStmt->execute([$portfolio_id]);
    $portfolio = $pStmt->fetch();

    if (!$portfolio) {
        json_die(false, 'Portfolio not found', 404);
    }
    if ($portfolio['user_id'] != $currentUser['id'] && $currentUser['role'] !== 'admin') {
        json_die(false, 'You do not have edit access to this portfolio', 403);
    }

    // Verify fund exists
    $fStmt = $db->prepare("SELECT id, scheme_name FROM funds WHERE id = ?");
    $fStmt->execute([$fund_id]);
    $fund = $fStmt->fetch();
    if (!$fund) {
        json_die(false, 'Fund not found. Please search and select a fund from the dropdown.', 404);
    }

    $db->beginTransaction();

    // ── SELL/SWITCH_OUT: date-aware units validation ─────────────
    if (in_array($txn_type, ['SELL', 'SWITCH_OUT'])) {

        // Helper: get fund name once
        $fNameStmt = $db->prepare("SELECT scheme_name FROM funds WHERE id = ?");
        $fNameStmt->execute([$fund_id]);
        $fundName = $fNameStmt->fetchColumn() ?: 'this fund';

        // BUY units ON OR BEFORE sell date — no folio filter (user may leave folio blank)
        $buyQ = $db->prepare("
            SELECT COALESCE(SUM(units), 0)
            FROM mf_transactions
            WHERE portfolio_id = ?
              AND fund_id      = ?
              AND transaction_type IN ('BUY','SWITCH_IN','DIV_REINVEST')
              AND txn_date <= ?
        ");
        $buyQ->execute([$portfolio_id, $fund_id, $txn_date]);
        $boughtByDate = (float)$buyQ->fetchColumn();

        // SELLs BEFORE this date (same-day sell allowed — tax harvesting)
        $sellQ = $db->prepare("
            SELECT COALESCE(SUM(units), 0)
            FROM mf_transactions
            WHERE portfolio_id = ?
              AND fund_id      = ?
              AND transaction_type IN ('SELL','SWITCH_OUT')
              AND txn_date < ?
              " . ($edit_id ? "AND id != $edit_id" : "") . "
        ");
        $sellQ->execute([$portfolio_id, $fund_id, $txn_date]);
        $soldBeforeDate = (float)$sellQ->fetchColumn();

        $availableOnDate = round($boughtByDate - $soldBeforeDate, 6);

        if ($availableOnDate <= 0) {
            $db->rollBack();
            json_die(false, "Cannot sell on {$txn_date}: no units available in \"{$fundName}\". Ensure BUY transactions exist on or before this date.", 422);
        }

        if ($units > $availableOnDate + 0.001) {
            $db->rollBack();
            json_die(false, "Cannot sell {$units} units on {$txn_date}. Only " . number_format($availableOnDate, 4) . " units available in \"{$fundName}\".", 422);
        }
    }
    // ────────────────────────────────────────────────────────────

    if ($edit_id) {
        // UPDATE existing transaction
        $stmt = $db->prepare("
            UPDATE mf_transactions SET
                portfolio_id=?, fund_id=?, folio_number=?,
                transaction_type=?, platform=?, txn_date=?,
                units=?, nav=?, value_at_cost=?,
                investment_fy=?, stamp_duty=?, notes=?,
                updated_at=NOW()
            WHERE id=? AND portfolio_id IN (
                SELECT id FROM portfolios WHERE user_id=?
            )
        ");
        $stmt->execute([
            $portfolio_id, $fund_id, $folio, $txn_type,
            $platform, $txn_date, $units, $nav, $value_at_cost,
            $investment_fy, $stamp_duty, $notes,
            $edit_id, $currentUser['id']
        ]);
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            json_die(false, 'Transaction not found or access denied', 404);
        }
        $txn_id = $edit_id;
        audit_log_pdo($db, $currentUser['id'], 'mf_txn_edit', "mf_transactions:$edit_id", json_encode(['fund_id'=>$fund_id,'units'=>$units,'nav'=>$nav]));
    } else {
        // INSERT new transaction
        $stmt = $db->prepare("
            INSERT INTO mf_transactions
                (portfolio_id, fund_id, folio_number, transaction_type, platform,
                 txn_date, units, nav, value_at_cost, investment_fy, stamp_duty, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $portfolio_id, $fund_id, $folio, $txn_type,
            $platform, $txn_date, $units, $nav, $value_at_cost,
            $investment_fy, $stamp_duty, $notes
        ]);
        $txn_id = (int)$db->lastInsertId();
        audit_log_pdo($db, $currentUser['id'], 'mf_txn_add', "mf_transactions:$txn_id", json_encode(['fund_id'=>$fund_id,'units'=>$units,'nav'=>$nav]));
    }

    // Recalculate holdings for this fund+folio+portfolio
    recalculate_mf_holdings($db, $portfolio_id, $fund_id, $folio);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $edit_id ? 'Transaction updated successfully' : 'Transaction added successfully',
        'txn_id'  => $txn_id,
        'fund'    => $fund['scheme_name']
    ]);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    // Don't expose raw DB errors to client — log and return clean message
    error_log('[WealthDash] mf_add PDO error: ' . $e->getMessage());
    json_die(false, 'Database error. Please try again. (Code: DB01)', 500);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('[WealthDash] mf_add error: ' . $e->getMessage());
    json_die(false, 'Server error: ' . $e->getMessage(), 500);
}