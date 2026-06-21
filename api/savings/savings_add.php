<?php
/**
 * WealthDash — Savings: Add Account + Add Interest + Update Balance
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$action = clean($_POST['action'] ?? '');

/* ═══════════════════════════════════════════
   savings_add — Add a new savings account
═══════════════════════════════════════════ */
if ($action === 'savings_add') {
    $portfolioId  = (int)($_POST['portfolio_id'] ?? 0);
    $bankName     = clean($_POST['bank_name']     ?? '');
    $accountNum   = clean($_POST['account_number'] ?? '');
    $accountType  = clean($_POST['account_type']  ?? 'savings');
    $interestRate = (float)($_POST['interest_rate'] ?? 0);
    $balance      = (float)($_POST['current_balance'] ?? 0);
    $balanceDate  = clean($_POST['balance_date']  ?? date('Y-m-d'));

    if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) json_response(false, 'Invalid portfolio.');
    if (!$bankName) json_response(false, 'Bank name is required.');
    if ($balance < 0) json_response(false, 'Balance cannot be negative.');
    if (!validate_date($balanceDate)) json_response(false, 'Invalid balance date.');
    if (!in_array($accountType, ['savings','current','salary'])) $accountType = 'savings';

    $id = DB::insert(
        "INSERT INTO savings_accounts (portfolio_id, bank_name, account_number, account_type, interest_rate, current_balance, balance_date, annual_interest_earned, is_active)
         VALUES (?,?,?,?,?,?,?,0,1)",
        [$portfolioId, $bankName, $accountNum ?: null, $accountType, $interestRate, $balance, $balanceDate]
    );
    audit_log('savings_add', 'savings_accounts', (int)$id);
    json_response(true, 'Account added.', ['id' => $id]);
}

/* ═══════════════════════════════════════════
   savings_add_interest — Record interest credit
═══════════════════════════════════════════ */
if ($action === 'savings_add_interest') {
    $accountId    = (int)($_POST['account_id']    ?? 0);
    $intDate      = clean($_POST['interest_date'] ?? '');
    $intAmount    = (float)($_POST['interest_amount'] ?? 0);
    $balanceAfter = $_POST['balance_after'] !== '' ? (float)$_POST['balance_after'] : null;
    $notes        = clean($_POST['notes'] ?? '');

    if (!$accountId) json_response(false, 'Invalid account.');
    if (!$intDate || !validate_date($intDate)) json_response(false, 'Invalid date.');
    if ($intAmount <= 0) json_response(false, 'Interest amount must be positive.');

    // Verify ownership
    $acct = DB::fetchOne("SELECT sa.*, p.user_id FROM savings_accounts sa JOIN portfolios p ON p.id=sa.portfolio_id WHERE sa.id=?", [$accountId]);
    if (!$acct || (!$isAdmin && (int)$acct['user_id'] !== $userId)) json_response(false, 'Access denied.');

    $fy = calculate_fy($intDate);
    $id = DB::insert(
        "INSERT INTO savings_interest (account_id, interest_date, interest_amount, balance_after, interest_fy, notes)
         VALUES (?,?,?,?,?,?)",
        [$accountId, $intDate, $intAmount, $balanceAfter, $fy, $notes ?: null]
    );

    // Update account balance if provided
    if ($balanceAfter !== null) {
        DB::query("UPDATE savings_accounts SET current_balance=?, balance_date=? WHERE id=?", [$balanceAfter, $intDate, $accountId]);
    }

    // Update annual_interest_earned (current FY sum)
    $currentFy = calculate_fy(date('Y-m-d'));
    $fyTotal   = DB::fetchVal("SELECT SUM(interest_amount) FROM savings_interest WHERE account_id=? AND interest_fy=?", [$accountId, $currentFy]) ?? 0;
    DB::query("UPDATE savings_accounts SET annual_interest_earned=? WHERE id=?", [$fyTotal, $accountId]);

    audit_log('savings_interest', 'savings_interest', (int)$id);
    json_response(true, 'Interest recorded.', ['id' => $id, 'fy' => $fy]);
}

/* ═══════════════════════════════════════════
   savings_update_balance — Update current balance
═══════════════════════════════════════════ */
if ($action === 'savings_update_balance') {
    $accountId   = (int)($_POST['account_id']  ?? 0);
    $balance     = (float)($_POST['balance']   ?? 0);
    $balanceDate = clean($_POST['balance_date'] ?? date('Y-m-d'));

    if (!$accountId) json_response(false, 'Invalid account.');
    $acct = DB::fetchOne("SELECT sa.*, p.user_id FROM savings_accounts sa JOIN portfolios p ON p.id=sa.portfolio_id WHERE sa.id=?", [$accountId]);
    if (!$acct || (!$isAdmin && (int)$acct['user_id'] !== $userId)) json_response(false, 'Access denied.');

    DB::query("UPDATE savings_accounts SET current_balance=?, balance_date=? WHERE id=?", [$balance, $balanceDate, $accountId]);
    json_response(true, 'Balance updated.');
}

json_response(false, 'Unknown action.');

