<?php
/**
 * WealthDash — t424: FD Interest Payout Tracker
 * Action: fd_interest_tracker
 * Returns: monthly interest calendar, FY totals, TDS, savings 80TTA
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];

$portfolioId = (int)($_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin ?? false)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

$fyStart = (int)($_POST['fy_start'] ?? date('Y'));
// Indian FY: Apr fyStart → Mar fyStart+1
$fyFrom = $fyStart . '-04-01';
$fyTo   = ($fyStart + 1) . '-03-31';
$today  = date('Y-m-d');

/* ─── 1. Get all active FDs with interest computation ──────── */
$fds = DB::fetchAll(
    "SELECT id, bank_name, fd_type, principal_amount, interest_rate,
            compounding, start_date, maturity_date, maturity_amount,
            interest_earned, status, tds_deducted
     FROM fd_accounts
     WHERE portfolio_id = ? AND status IN ('active','matured')
     ORDER BY bank_name, start_date",
    [$portfolioId]
);

/* ─── 2. Compute FY interest for each FD (accrual basis) ───── */
function fdFyInterest(array $fd, string $fyFrom, string $fyTo, string $today): float {
    $start    = max($fd['start_date'], $fyFrom);
    $end      = min($fd['maturity_date'] ?? $today, $fyTo, $today);
    if ($start > $end) return 0.0;

    $daysInFy = (strtotime($end) - strtotime($start)) / 86400;
    if ($daysInFy <= 0) return 0.0;

    $rate = (float)$fd['interest_rate'] / 100;
    $p    = (float)$fd['principal_amount'];

    // Simple interest for non-cumulative; compound for others
    // Using 365-day simple accrual for annual display clarity
    $interest = $p * $rate * ($daysInFy / 365);
    return round($interest, 2);
}

/* ─── 3. Monthly breakdown — 12 months of FY ───────────────── */
$months = [];
for ($m = 1; $m <= 12; $m++) {
    // FY month 1 = April
    $calMonth = (($m + 2) % 12) + 1; // Apr=1→month 4, May=1→month 5 ...
    $calMonth = (($m - 1 + 3) % 12) + 1;
    // Simpler: April=4, May=5 ... Dec=12, Jan=1 ... Mar=3
    $monthNum  = $m <= 9 ? ($m + 3) : ($m - 9);
    $yearNum   = $m <= 9 ? $fyStart : ($fyStart + 1);
    $monthFrom = sprintf('%04d-%02d-01', $yearNum, $monthNum);
    $monthTo   = date('Y-m-t', strtotime($monthFrom)); // last day of month
    $isFuture  = $monthFrom > $today;
    $isCurrentMonth = (date('Y-m') === substr($monthFrom, 0, 7));

    $monthInterest = 0.0;
    foreach ($fds as $fd) {
        $start = max($fd['start_date'], $monthFrom);
        $end   = min($fd['maturity_date'] ?? $today, $monthTo);
        if ($isFuture) $end = min($fd['maturity_date'] ?? $monthTo, $monthTo);
        if ($start > $end) continue;
        $days = (strtotime($end) - strtotime($start)) / 86400;
        if ($days <= 0) continue;
        $monthInterest += (float)$fd['principal_amount'] * ((float)$fd['interest_rate']/100) * ($days / 365);
    }

    $months[] = [
        'month_num'    => $monthNum,
        'year_num'     => $yearNum,
        'month_label'  => date('M \'y', strtotime($monthFrom)),
        'total_interest'=> round($monthInterest, 2),
        'is_future'    => $isFuture,
        'is_current'   => $isCurrentMonth,
    ];
}

/* ─── 4. FD-wise FY interest ────────────────────────────────── */
$fdRows = [];
foreach ($fds as $fd) {
    $fyInt = fdFyInterest($fd, $fyFrom, $fyTo, $today);
    $fdRows[] = [
        'bank_name'          => $fd['bank_name'],
        'principal_amount'   => $fd['principal_amount'],
        'interest_rate'      => $fd['interest_rate'],
        'start_date'         => $fd['start_date'],
        'maturity_date'      => $fd['maturity_date'],
        'interest_accrued_fy'=> $fyInt,
        'status'             => $fd['status'],
        'tds_deducted'       => $fd['tds_deducted'] ?? 0,
    ];
}

/* ─── 5. FY totals ──────────────────────────────────────────── */
$fdInterestFy = array_sum(array_column($fdRows, 'interest_accrued_fy'));

// TDS: 10% if FD interest > ₹40,000 from a single bank
// Simplified: apply 10% on total if sum > 40000
$tdsFy = $fdInterestFy > 40000 ? round($fdInterestFy * 0.10, 2) : 0.0;

// Savings interest this FY from savings_interest log, or estimate from savings_accounts
$savingsIntFy = DB::fetchRow(
    "SELECT COALESCE(SUM(interest_amount), 0) AS total
     FROM savings_interest si
     JOIN savings_accounts sa ON sa.id = si.account_id
     WHERE sa.portfolio_id = ? AND si.interest_fy = ?",
    [$portfolioId, $fyStart . '-' . substr($fyStart + 1, 2)]
);
$savInt = (float)($savingsIntFy['total'] ?? 0);

// If no savings_interest records, estimate from savings_accounts
if ($savInt == 0) {
    $savRow = DB::fetchRow(
        "SELECT COALESCE(SUM(annual_interest_earned), 0) AS total
         FROM savings_accounts WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );
    $savInt = (float)($savRow['total'] ?? 0);
}

$totalInterestFy = $fdInterestFy + $savInt;

json_response(true, 'Interest tracker loaded.', [
    'summary' => [
        'fd_interest_fy'    => round($fdInterestFy, 2),
        'sav_interest_fy'   => round($savInt, 2),
        'tds_fy'            => $tdsFy,
        'total_interest_fy' => round($totalInterestFy, 2),
        'fy_label'          => 'FY ' . $fyStart . '-' . substr($fyStart + 1, 2),
        'tds_threshold'     => 40000,
    ],
    'monthly' => $months,
    'fds'     => $fdRows,
]);
