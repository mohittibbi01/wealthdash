<?php
/**
 * WealthDash — Mark FD as Matured
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$id = (int)($_POST['id'] ?? 0);
if (!$id) json_response(false, 'Invalid ID.');

$fd = DB::fetchOne("SELECT fa.*, p.user_id FROM fd_accounts fa JOIN portfolios p ON p.id=fa.portfolio_id WHERE fa.id=?", [$id]);
if (!$fd)                                          json_response(false, 'FD not found.');
if (!$isAdmin && (int)$fd['user_id'] !== $userId)  json_response(false, 'Access denied.');

DB::query("UPDATE fd_accounts SET status='matured' WHERE id=?", [$id]);
audit_log('fd_mature', 'fd_accounts', $id);

json_response(true, 'FD marked as matured.', ['maturity_amount' => $fd['maturity_amount']]);

