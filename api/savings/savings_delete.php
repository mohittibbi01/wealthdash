<?php
/**
 * WealthDash — Delete Savings Account or Interest Entry
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$action = clean($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
if (!$id) json_response(false, 'Invalid ID.');

if ($action === 'savings_delete') {
    $acct = DB::fetchOne("SELECT sa.*, p.user_id FROM savings_accounts sa JOIN portfolios p ON p.id=sa.portfolio_id WHERE sa.id=?", [$id]);
    if (!$acct || (!$isAdmin && (int)$acct['user_id'] !== $userId)) json_response(false, 'Access denied.');
    DB::query("DELETE FROM savings_interest WHERE account_id=?", [$id]);
    DB::query("DELETE FROM savings_accounts WHERE id=?", [$id]);
    audit_log('savings_delete', 'savings_accounts', $id);
    json_response(true, 'Account deleted.');
}

if ($action === 'savings_delete_interest') {
    $entry = DB::fetchOne("SELECT si.*, p.user_id FROM savings_interest si JOIN savings_accounts sa ON sa.id=si.account_id JOIN portfolios p ON p.id=sa.portfolio_id WHERE si.id=?", [$id]);
    if (!$entry || (!$isAdmin && (int)$entry['user_id'] !== $userId)) json_response(false, 'Access denied.');
    DB::query("DELETE FROM savings_interest WHERE id=?", [$id]);
    // Recalculate annual interest
    $currentFy = calculate_fy(date('Y-m-d'));
    $fyTotal   = DB::fetchVal("SELECT SUM(interest_amount) FROM savings_interest WHERE account_id=? AND interest_fy=?", [$entry['account_id'], $currentFy]) ?? 0;
    DB::query("UPDATE savings_accounts SET annual_interest_earned=? WHERE id=?", [$fyTotal, $entry['account_id']]);
    json_response(true, 'Interest entry deleted.');
}

json_response(false, 'Unknown action.');

