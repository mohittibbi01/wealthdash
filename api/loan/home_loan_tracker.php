<?php
/**
 * WealthDash — t464: Home Loan EMI Tracker (Extended API)
 * Rate history, prepayment log, tax claims, EMI calendar
 * Actions: hl_detail, hl_update_details,
 *          hl_rate_history, hl_rate_add, hl_rate_delete,
 *          hl_prepayments, hl_prepayment_add, hl_prepayment_delete,
 *          hl_tax_claims, hl_tax_claim_save,
 *          hl_emi_calendar, hl_edit_loan
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

// ── Verify loan ownership helper ──────────────────────────────────────────
function hl_own(int $loanId, int $userId): bool {
    $row = DB::fetchOne(
        "SELECT la.id FROM loan_accounts la
         JOIN portfolios p ON p.id = la.portfolio_id
         WHERE la.id = ? AND p.user_id = ?",
        [$loanId, $userId]
    );
    return (bool)$row;
}

// ── HL DETAIL (single loan full info) ────────────────────────────────────
if ($action === 'hl_detail') {
    $id = (int)($_GET['id'] ?? 0);
    if (!hl_own($id, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    $loan = DB::fetchOne(
        "SELECT la.*, p.name AS portfolio_name,
                ROUND(la.outstanding_balance * la.interest_rate / 100 / 12, 2) AS monthly_interest,
                TIMESTAMPDIFF(MONTH, la.disbursement_date, CURDATE()) AS months_elapsed,
                DATEDIFF(la.end_date, CURDATE()) AS days_remaining
         FROM loan_accounts la
         JOIN portfolios p ON p.id = la.portfolio_id
         WHERE la.id = ?",
        [$id]
    );

    // Cumulative interest + principal paid (approximation from amortization)
    $P   = (float)$loan['principal_amount'];
    $r   = (float)$loan['interest_rate'] / 100 / 12;
    $emi = (float)$loan['emi_amount'];
    $bal = $P;
    $totalIntPaid = 0;
    $totalPrinPaid = 0;
    $tenureElapsed = max(0, (int)$loan['months_elapsed'] - (int)$loan['moratorium_months']);
    for ($m = 1; $m <= $tenureElapsed && $bal > 0; $m++) {
        $intPart  = $bal * $r;
        $prinPart = $emi - $intPart;
        $totalIntPaid  += $intPart;
        $totalPrinPaid += max(0, $prinPart);
        $bal -= max(0, $prinPart);
    }
    $loan['total_interest_paid']    = round($totalIntPaid, 2);
    $loan['total_principal_paid']   = round($totalPrinPaid, 2);
    $loan['computed_outstanding']   = round(max(0, $bal), 2);
    $remainingMonths = $r > 0 && $emi > 0 && $loan['outstanding_balance'] > 0
        ? (int)ceil(log($emi / ($emi - $loan['outstanding_balance'] * $r)) / log(1 + $r))
        : 0;
    $loan['remaining_months']       = max(0, $remainingMonths);
    $totalFutureInt = max(0, ($emi * $remainingMonths) - (float)$loan['outstanding_balance']);
    $loan['total_future_interest']  = round($totalFutureInt, 2);

    json_response(true, '', $loan);
    exit;
}

// ── HL UPDATE DETAILS ────────────────────────────────────────────────────
if ($action === 'hl_update_details') {
    $id = (int)($_POST['id'] ?? 0);
    if (!hl_own($id, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    DB::run(
        "UPDATE loan_accounts SET
           property_name = ?, property_address = ?, co_borrower = ?,
           loan_sanction_date = ?, moratorium_months = ?,
           base_rate_type = ?, spread_pct = ?, reset_date = ?
         WHERE id = ?",
        [
            clean($_POST['property_name']    ?? '') ?: null,
            clean($_POST['property_address'] ?? '') ?: null,
            clean($_POST['co_borrower']      ?? '') ?: null,
            clean($_POST['loan_sanction_date']?? '') ?: null,
            (int)($_POST['moratorium_months']  ?? 0),
            clean($_POST['base_rate_type']   ?? '') ?: null,
            strlen($_POST['spread_pct'] ?? '') ? (float)$_POST['spread_pct'] : null,
            clean($_POST['reset_date']       ?? '') ?: null,
            $id,
        ]
    );
    json_response(true, 'Home loan details updated.');
    exit;
}

// ── RATE HISTORY ─────────────────────────────────────────────────────────
if ($action === 'hl_rate_history') {
    $id = (int)($_GET['loan_id'] ?? 0);
    if (!hl_own($id, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    $rows = DB::fetchAll(
        "SELECT * FROM loan_rate_history WHERE loan_id = ? ORDER BY effective_date DESC",
        [$id]
    );
    json_response(true, '', $rows);
    exit;
}

if ($action === 'hl_rate_add') {
    $loanId  = (int)($_POST['loan_id'] ?? 0);
    if (!hl_own($loanId, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    $effDate = clean($_POST['effective_date'] ?? date('Y-m-d'));
    $oldRate = (float)($_POST['old_rate']     ?? 0);
    $newRate = (float)($_POST['new_rate']     ?? 0);
    $newEmi  = strlen($_POST['new_emi']       ?? '') ? (float)$_POST['new_emi']    : null;
    $newTen  = strlen($_POST['new_tenure']    ?? '') ? (int)$_POST['new_tenure']   : null;
    $baseRate= strlen($_POST['base_rate']     ?? '') ? (float)$_POST['base_rate']  : null;
    $reason  = clean($_POST['reason']         ?? '') ?: null;
    $notes   = clean($_POST['notes']          ?? '') ?: null;

    if (!$newRate) { json_response(false, 'New rate required.', [], 422); exit; }

    DB::run(
        "INSERT INTO loan_rate_history (loan_id, effective_date, old_rate, new_rate, new_emi, new_tenure, base_rate, reason, notes)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [$loanId, $effDate, $oldRate, $newRate, $newEmi, $newTen, $baseRate, $reason, $notes]
    );

    // Also update the current rate on loan_accounts
    DB::run(
        "UPDATE loan_accounts SET interest_rate = ?  WHERE id = ?",
        [$newRate, $loanId]
    );
    if ($newEmi) {
        DB::run("UPDATE loan_accounts SET emi_amount = ? WHERE id = ?", [$newEmi, $loanId]);
    }

    json_response(true, 'Rate change recorded.');
    exit;
}

if ($action === 'hl_rate_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run(
        "DELETE lrh FROM loan_rate_history lrh
         INNER JOIN loan_accounts la ON la.id = lrh.loan_id
         INNER JOIN portfolios p ON p.id = la.portfolio_id
         WHERE lrh.id = ? AND p.user_id = ?",
        [$id, $userId]
    );
    json_response(true, 'Rate entry deleted.');
    exit;
}

// ── PREPAYMENTS ──────────────────────────────────────────────────────────
if ($action === 'hl_prepayments') {
    $id = (int)($_GET['loan_id'] ?? 0);
    if (!hl_own($id, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    $rows = DB::fetchAll(
        "SELECT * FROM loan_prepayments WHERE loan_id = ? ORDER BY payment_date DESC",
        [$id]
    );
    // Summary
    $total = DB::fetchOne("SELECT COALESCE(SUM(amount),0) AS tot FROM loan_prepayments WHERE loan_id=?", [$id]);
    $intSaved = DB::fetchOne("SELECT COALESCE(SUM(interest_saved),0) AS tot FROM loan_prepayments WHERE loan_id=?", [$id]);

    json_response(true, '', [
        'rows'            => $rows,
        'total_prepaid'   => (float)($total['tot'] ?? 0),
        'interest_saved'  => (float)($intSaved['tot'] ?? 0),
    ]);
    exit;
}

if ($action === 'hl_prepayment_add') {
    $loanId  = (int)($_POST['loan_id'] ?? 0);
    if (!hl_own($loanId, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    $amount    = (float)($_POST['amount']         ?? 0);
    $date      = clean($_POST['payment_date']     ?? date('Y-m-d'));
    $mode      = clean($_POST['mode']             ?? 'partial_prepayment');
    $impact    = clean($_POST['impact']           ?? 'reduce_tenure');
    $penalty   = (float)($_POST['penalty_charged']?? 0);
    $source    = clean($_POST['source']           ?? '') ?: null;
    $notes     = clean($_POST['notes']            ?? '') ?: null;

    if ($amount <= 0) { json_response(false, 'Amount required.', [], 422); exit; }

    // Compute savings based on current loan state
    $loan = DB::fetchOne(
        "SELECT outstanding_balance, interest_rate, emi_amount, tenure_months FROM loan_accounts WHERE id = ?",
        [$loanId]
    );
    $P   = (float)$loan['outstanding_balance'];
    $r   = (float)$loan['interest_rate'] / 100 / 12;
    $emi = (float)$loan['emi_amount'];

    // Months remaining before prepayment
    $nBefore = ($r > 0 && $emi > 0 && $P > 0)
        ? (int)ceil(log($emi / ($emi - $P * $r)) / log(1 + $r))
        : (int)$loan['tenure_months'];

    $newP = max(0, $P - $amount);

    // Months remaining after prepayment
    $nAfter = ($impact === 'reduce_tenure' && $r > 0 && $emi > 0 && $newP > 0)
        ? (int)ceil(log($emi / ($emi - $newP * $r)) / log(1 + $r))
        : $nBefore; // reduce_emi: tenure same, emi changes

    $emisSaved   = max(0, $nBefore - $nAfter);
    $intBefore   = max(0, $emi * $nBefore - $P);
    $intAfter    = max(0, $emi * $nAfter  - $newP);
    $intSaved    = round(max(0, $intBefore - $intAfter), 2);

    $newEmi = null;
    $newTen = null;
    if ($impact === 'reduce_emi' && $r > 0 && $nBefore > 0 && $newP > 0) {
        $newEmi = round($newP * $r * pow(1+$r,$nBefore) / (pow(1+$r,$nBefore)-1), 2);
    } elseif ($impact === 'reduce_tenure') {
        $newTen = $nAfter;
    }

    DB::run(
        "INSERT INTO loan_prepayments
           (loan_id, payment_date, amount, mode, impact, emis_saved, interest_saved,
            new_outstanding, new_tenure, new_emi, penalty_charged, source, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$loanId, $date, $amount, $mode, $impact, $emisSaved, $intSaved,
         $newP, $newTen, $newEmi, $penalty, $source, $notes]
    );

    // Update outstanding + emi if applicable
    DB::run("UPDATE loan_accounts SET outstanding_balance = ?, total_prepaid = total_prepaid + ? WHERE id = ?",
        [$newP, $amount, $loanId]);
    if ($newEmi) {
        DB::run("UPDATE loan_accounts SET emi_amount = ? WHERE id = ?", [$newEmi, $loanId]);
    }

    json_response(true, 'Prepayment logged.', [
        'emis_saved'     => $emisSaved,
        'interest_saved' => $intSaved,
        'new_outstanding'=> $newP,
        'new_emi'        => $newEmi,
        'new_tenure'     => $newTen,
    ]);
    exit;
}

if ($action === 'hl_prepayment_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run(
        "DELETE lp FROM loan_prepayments lp
         INNER JOIN loan_accounts la ON la.id = lp.loan_id
         INNER JOIN portfolios p ON p.id = la.portfolio_id
         WHERE lp.id = ? AND p.user_id = ?",
        [$id, $userId]
    );
    json_response(true, 'Prepayment deleted.');
    exit;
}

// ── TAX CLAIMS ──────────────────────────────────────────────────────────
if ($action === 'hl_tax_claims') {
    $id = (int)($_GET['loan_id'] ?? 0);
    if (!hl_own($id, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    $rows = DB::fetchAll(
        "SELECT * FROM loan_tax_claims WHERE loan_id = ? ORDER BY fy DESC",
        [$id]
    );
    json_response(true, '', $rows);
    exit;
}

if ($action === 'hl_tax_claim_save') {
    $loanId   = (int)($_POST['loan_id']      ?? 0);
    $fy       = clean($_POST['fy']           ?? '');
    $intPaid  = (float)($_POST['interest_paid']    ?? 0);
    $prinPaid = (float)($_POST['principal_paid']   ?? 0);
    $s24b     = (float)($_POST['sec_24b_claimed']  ?? 0);
    $s80c     = (float)($_POST['sec_80c_claimed']  ?? 0);
    $uc       = (int)(($_POST['under_construction']?? '0') === '1');
    $notes    = clean($_POST['notes']        ?? '') ?: null;

    if (!hl_own($loanId, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }
    if (!$fy) { json_response(false, 'FY required.', [], 422); exit; }

    DB::run(
        "INSERT INTO loan_tax_claims (loan_id, fy, interest_paid, principal_paid, sec_24b_claimed, sec_80c_claimed, under_construction, notes)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           interest_paid=VALUES(interest_paid), principal_paid=VALUES(principal_paid),
           sec_24b_claimed=VALUES(sec_24b_claimed), sec_80c_claimed=VALUES(sec_80c_claimed),
           under_construction=VALUES(under_construction), notes=VALUES(notes)",
        [$loanId, $fy, $intPaid, $prinPaid, $s24b, $s80c, $uc, $notes]
    );
    json_response(true, 'Tax claim saved.');
    exit;
}

// ── EMI CALENDAR (month-wise upcoming EMIs) ──────────────────────────────
if ($action === 'hl_emi_calendar') {
    $id     = (int)($_GET['loan_id'] ?? 0);
    $months = min(60, max(1, (int)($_GET['months'] ?? 24))); // 1-60 months ahead

    if (!hl_own($id, $userId)) { json_response(false, 'Access denied.', [], 403); exit; }

    $loan = DB::fetchOne(
        "SELECT outstanding_balance, interest_rate, emi_amount,
                tenure_months, first_emi_date, disbursement_date, lender_name, loan_type
         FROM loan_accounts WHERE id = ?",
        [$id]
    );

    $P      = (float)$loan['outstanding_balance'];
    $r      = (float)$loan['interest_rate'] / 100 / 12;
    $emi    = (float)$loan['emi_amount'];
    $bal    = $P;
    $calendar = [];

    // Determine first upcoming EMI date
    $emiDom = null; // day of month
    if ($loan['first_emi_date']) {
        $d = new DateTime($loan['first_emi_date']);
        $emiDom = (int)$d->format('j');
    }

    $today = new DateTime();
    $curDate = new DateTime($today->format('Y-m-01'));

    for ($m = 0; $m < $months && $bal > 1; $m++) {
        $intPart  = round($bal * $r, 2);
        $prinPart = round(min($emi - $intPart, $bal), 2);
        $bal      = round(max(0, $bal - $prinPart), 2);

        $dueDate = clone $curDate;
        if ($emiDom) {
            $maxDay = (int)$dueDate->format('t');
            $dueDate->setDate((int)$dueDate->format('Y'), (int)$dueDate->format('m'), min($emiDom, $maxDay));
        }

        $calendar[] = [
            'month'        => $dueDate->format('M Y'),
            'due_date'     => $dueDate->format('Y-m-d'),
            'emi'          => round($emi, 2),
            'principal'    => $prinPart,
            'interest'     => $intPart,
            'balance'      => $bal,
            'month_num'    => $m + 1,
            'is_overdue'   => $dueDate < $today,
        ];

        $curDate->modify('+1 month');
    }

    $totalEmi      = round(array_sum(array_column($calendar, 'emi')), 2);
    $totalInterest = round(array_sum(array_column($calendar, 'interest')), 2);
    $totalPrincipal= round(array_sum(array_column($calendar, 'principal')), 2);

    json_response(true, '', [
        'calendar'        => $calendar,
        'total_emi'       => $totalEmi,
        'total_interest'  => $totalInterest,
        'total_principal' => $totalPrincipal,
        'months_shown'    => count($calendar),
    ]);
    exit;
}

json_response(false, 'Unknown home loan action.', [], 400);
