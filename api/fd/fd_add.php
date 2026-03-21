<?php
/**
 * WealthDash — FD Add
 * POST /api/?action=fd_add
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId     = (int)($_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)$currentUser['id'] ?? 0);
$bankName        = clean($_POST['bank_name']         ?? '');
$accountNumber   = clean($_POST['account_number']    ?? '');
$principal       = (float)($_POST['principal']       ?? 0);
$interestRate    = (float)($_POST['interest_rate']   ?? 0);
$interestFreq    = clean($_POST['interest_frequency'] ?? 'cumulative');
$compoundingFreq = (int)($_POST['compounding_freq']  ?? 4); // 1=annual, 4=quarterly, 12=monthly
$startDate       = clean($_POST['start_date']        ?? '');
$maturityDate    = clean($_POST['maturity_date']     ?? '');
$tdsApplicable   = (int)($_POST['tds_applicable']    ?? 1);
$isSenior        = (int)($_POST['is_senior_citizen'] ?? 0);
$notes           = clean($_POST['notes']             ?? '');

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) json_response(false, 'Invalid portfolio.');
if (!$bankName)                      json_response(false, 'Bank name is required.');
if ($principal <= 0)                 json_response(false, 'Principal must be positive.');
if ($interestRate <= 0 || $interestRate > 25) json_response(false, 'Interest rate must be between 0.01% and 25%.');
if (!$startDate   || !validate_date($startDate))   json_response(false, 'Invalid start date.');
if (!$maturityDate || !validate_date($maturityDate)) json_response(false, 'Invalid maturity date.');
if ($maturityDate <= $startDate)     json_response(false, 'Maturity date must be after start date.');

$n     = in_array($compoundingFreq, [1, 4, 12]) ? $compoundingFreq : 4;
$days  = (int)(new DateTime($maturityDate))->diff(new DateTime($startDate))->days;
$years = $days / 365;

// Compound interest: A = P(1 + r/n)^(n*t)
$maturityAmount = round($principal * pow(1 + ($interestRate / 100 / $n), $n * $years), 2);

$id = DB::insert(
    "INSERT INTO fd_accounts (portfolio_id, bank_name, account_number, principal, interest_rate,
        interest_frequency, start_date, maturity_date, maturity_amount, tds_applicable, is_senior_citizen, status, notes)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,'active',?)",
    [$portfolioId, $bankName, $accountNumber ?: null, $principal, $interestRate,
     $interestFreq, $startDate, $maturityDate, $maturityAmount, $tdsApplicable, $isSenior, $notes ?: null]
);

audit_log('fd_add', 'fd_accounts', (int)$id);

json_response(true, 'FD added successfully.', [
    'id'              => $id,
    'maturity_amount' => $maturityAmount,
    'interest_earned' => round($maturityAmount - $principal, 2),
    'tenure_days'     => $days,
]);

