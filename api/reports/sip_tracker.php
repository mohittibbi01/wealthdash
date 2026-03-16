<?php
/**
 * WealthDash — SIP Tracker API
 * Actions: sip_list | sip_add | sip_edit | sip_delete | sip_analysis | sip_upcoming
 */
declare(strict_types=1);

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$userId      = (int) $_SESSION['user_id'];
$isAdmin     = is_admin();
$portfolioId = (int) ($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ??
                      $_SESSION['selected_portfolio_id'] ?? 0);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── List SIPs ─────────────────────────────────────────────
    case 'sip_list':
        $siPs = DB::fetchAll(
            "SELECT s.*,
                    f.scheme_name AS fund_name,
                    f.category    AS fund_category,
                    fh.name       AS fund_house,
                    f.latest_nav
             FROM sip_schedules s
             LEFT JOIN funds f  ON f.id  = s.fund_id
             LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
             WHERE s.portfolio_id = ?
             ORDER BY s.is_active DESC, s.start_date DESC",
            [$portfolioId]
        );

        // Enrich: next SIP date + total invested so far
        foreach ($siPs as &$sip) {
            $sip['next_date']       = _next_sip_date($sip);
            $sip['total_invested']  = _sip_total_invested($sip, $portfolioId);
            $sip['months_running']  = _months_running($sip['start_date']);
            $sip['sip_amount_fmt']  = inr($sip['sip_amount']);
        }
        unset($sip);

        json_response(true, '', ['sips' => $siPs]);

    // ── SIP Analysis (all active SIPs summary) ────────────────
    case 'sip_analysis':
        $siPs = DB::fetchAll(
            "SELECT s.*, f.scheme_name, f.latest_nav, f.category,
                    mh.total_invested AS holding_invested,
                    mh.value_now,
                    mh.gain_loss,
                    mh.cagr
             FROM sip_schedules s
             LEFT JOIN funds f ON f.id = s.fund_id
             LEFT JOIN mf_holdings mh ON mh.portfolio_id = s.portfolio_id
                    AND mh.fund_id = s.fund_id AND mh.is_active = 1
             WHERE s.portfolio_id = ? AND s.is_active = 1",
            [$portfolioId]
        );

        $totalMonthly    = 0;
        $totalInvested   = 0;
        $totalCurrentVal = 0;

        foreach ($siPs as &$sip) {
            $freq = $sip['frequency'];
            // Normalize to monthly equivalent
            $monthlyEquiv = match($freq) {
                'weekly'    => $sip['sip_amount'] * 4.33,
                'quarterly' => $sip['sip_amount'] / 3,
                'yearly'    => $sip['sip_amount'] / 12,
                default     => (float) $sip['sip_amount'],
            };
            $totalMonthly    += $monthlyEquiv;
            $totalInvested   += (float) ($sip['holding_invested'] ?? 0);
            $totalCurrentVal += (float) ($sip['value_now'] ?? 0);

            $sip['monthly_equiv']   = round($monthlyEquiv, 2);
            $sip['months_running']  = _months_running($sip['start_date']);
            $sip['next_date']       = _next_sip_date($sip);
        }
        unset($sip);

        $overallGain    = $totalCurrentVal - $totalInvested;
        $overallGainPct = $totalInvested > 0
            ? round(($overallGain / $totalInvested) * 100, 2) : 0;

        json_response(true, '', [
            'sips'                => $siPs,
            'total_monthly_sip'   => round($totalMonthly, 2),
            'total_invested'      => round($totalInvested, 2),
            'total_current_value' => round($totalCurrentVal, 2),
            'overall_gain'        => round($overallGain, 2),
            'overall_gain_pct'    => $overallGainPct,
        ]);

    // ── Upcoming SIPs (next 30 days) ──────────────────────────
    case 'sip_upcoming':
        $days   = min((int) ($_GET['days'] ?? 30), 90);
        $today  = new DateTime();
        $endDay = (clone $today)->modify("+{$days} days");

        $siPs = DB::fetchAll(
            "SELECT s.*, f.scheme_name, f.category, fh.name AS fund_house
             FROM sip_schedules s
             LEFT JOIN funds f  ON f.id  = s.fund_id
             LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
             WHERE s.portfolio_id = ? AND s.is_active = 1
             ORDER BY s.sip_day",
            [$portfolioId]
        );

        $upcoming = [];
        foreach ($siPs as $sip) {
            $next = _next_sip_date($sip);
            if ($next && new DateTime($next) <= $endDay) {
                $sip['next_date']      = $next;
                $sip['days_remaining'] = (int) $today->diff(new DateTime($next))->days;
                $upcoming[] = $sip;
            }
        }
        usort($upcoming, fn($a, $b) => $a['days_remaining'] <=> $b['days_remaining']);

        json_response(true, '', ['upcoming' => $upcoming, 'days_checked' => $days]);

    // ── Monthly SIP history (months × amount chart data) ─────
    case 'sip_monthly_chart':
        $months = min((int) ($_GET['months'] ?? 12), 60);

        $rows = DB::fetchAll(
            "SELECT DATE_FORMAT(txn_date,'%Y-%m') AS ym,
                    SUM(value_at_cost) AS invested
             FROM mf_transactions
             WHERE portfolio_id = ? AND transaction_type = 'BUY'
               AND txn_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
             GROUP BY ym
             ORDER BY ym",
            [$portfolioId, $months]
        );

        json_response(true, '', ['chart' => $rows, 'months' => $months]);

    // ── Add SIP ───────────────────────────────────────────────
    case 'sip_add':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $fundId    = (int) ($_POST['fund_id']    ?? 0);
        $amount    = (float) ($_POST['sip_amount'] ?? 0);
        $frequency = in_array($_POST['frequency'] ?? '', ['daily','weekly','fortnightly','monthly','quarterly','yearly'])
                     ? $_POST['frequency'] : 'monthly';
        $sipDay    = max(1, min(28, (int) ($_POST['sip_day'] ?? 1)));
        $startDate = date_to_db(clean($_POST['start_date'] ?? ''));
        $endDate   = !empty($_POST['end_date']) ? date_to_db(clean($_POST['end_date'])) : null;
        $folio     = clean($_POST['folio_number'] ?? '');
        $platform  = clean($_POST['platform']     ?? '');
        $notes     = clean($_POST['notes']        ?? '');

        if (!$fundId || $amount <= 0 || !$startDate) {
            json_response(false, 'Fund, amount, and start date are required.');
        }

        // Verify fund exists
        $fund = DB::fetchOne('SELECT id, scheme_name, scheme_code FROM funds WHERE id = ?', [$fundId]);
        if (!$fund) json_response(false, 'Fund not found.');

        DB::run(
            'INSERT INTO sip_schedules
             (portfolio_id, asset_type, fund_id, folio_number, sip_amount, frequency,
              sip_day, start_date, end_date, platform, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$portfolioId, 'mf', $fundId, $folio ?: null, $amount, $frequency,
             $sipDay, $startDate, $endDate, $platform ?: null, $notes ?: null]
        );
        $id = (int) DB::conn()->lastInsertId();
        audit_log('sip_add', 'sip_schedules', $id);

        // ── On-demand NAV history check & trigger download ──────
        // Check if nav_history has data from start_date onwards for this fund
        $navCount = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM nav_history WHERE fund_id = ? AND nav_date >= ?",
            [$fundId, $startDate]
        );

        $navStatus = 'available'; // assume available
        $navMessage = '';

        if ($navCount === 0) {
            $schemeCode = $fund['scheme_code'];
            $existing = DB::fetchOne(
                "SELECT status, from_date FROM nav_download_progress WHERE scheme_code = ?",
                [$schemeCode]
            );

            if ($existing && $existing['status'] === 'completed'
                && $existing['from_date'] !== null
                && $existing['from_date'] <= $startDate) {
                $navStatus  = 'no_data';
                $navMessage = 'No NAV data found for this fund from the selected date.';
            } else {
                // Queue fund for download — nav_history_downloader will pick it up
                // OR JS will trigger sip_nav_fetch.php directly
                DB::run(
                    "INSERT INTO nav_download_progress (scheme_code, fund_id, status, from_date)
                     VALUES (?, ?, 'pending', ?)
                     ON DUPLICATE KEY UPDATE
                       status        = 'pending',
                       from_date     = LEAST(from_date, ?),
                       error_message = NULL",
                    [$schemeCode, $fundId, $startDate, $startDate]
                );

                $navStatus  = 'downloading';
                $navMessage = 'NAV history queued for download. XIRR will be available once ready.';
            }
        }

        json_response(true, 'SIP added successfully.', [
            'id'          => $id,
            'nav_status'  => $navStatus,
            'nav_message' => $navMessage,
            'nav_count'   => $navCount,
        ]);

    // ── Edit SIP ──────────────────────────────────────────────
    case 'sip_edit':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $sipId   = (int) ($_POST['sip_id'] ?? 0);
        $amount  = (float) ($_POST['sip_amount'] ?? 0);
        $sipDay  = max(1, min(28, (int) ($_POST['sip_day'] ?? 1)));
        $endDate = !empty($_POST['end_date']) ? date_to_db(clean($_POST['end_date'])) : null;
        $isActive= (int) ($_POST['is_active'] ?? 1);
        $notes   = clean($_POST['notes'] ?? '');

        $sip = DB::fetchOne(
            'SELECT id FROM sip_schedules WHERE id = ? AND portfolio_id = ?',
            [$sipId, $portfolioId]
        );
        if (!$sip) json_response(false, 'SIP not found.');

        DB::run(
            'UPDATE sip_schedules SET sip_amount=?, sip_day=?, end_date=?, is_active=?, notes=?
             WHERE id=?',
            [$amount, $sipDay, $endDate, $isActive, $notes ?: null, $sipId]
        );
        audit_log('sip_edit', 'sip_schedules', $sipId);
        json_response(true, 'SIP updated.');

    // ── Delete SIP ────────────────────────────────────────────
    case 'sip_delete':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $sipId = (int) ($_POST['sip_id'] ?? 0);
        $sip   = DB::fetchOne(
            'SELECT id FROM sip_schedules WHERE id = ? AND portfolio_id = ?',
            [$sipId, $portfolioId]
        );
        if (!$sip) json_response(false, 'SIP not found.');

        DB::run('DELETE FROM sip_schedules WHERE id = ?', [$sipId]);
        audit_log('sip_delete', 'sip_schedules', $sipId);
        json_response(true, 'SIP removed.');

    // ── SIP XIRR Calculation ──────────────────────────────────
    // Calculates XIRR for a specific SIP using nav_history
    case 'sip_xirr':
        $sipId = (int) ($_POST['sip_id'] ?? $_GET['sip_id'] ?? 0);
        $sip   = DB::fetchOne(
            "SELECT s.*, f.scheme_code, f.latest_nav, f.latest_nav_date
             FROM sip_schedules s
             JOIN funds f ON f.id = s.fund_id
             WHERE s.id = ? AND s.portfolio_id = ?",
            [$sipId, $portfolioId]
        );
        if (!$sip) json_response(false, 'SIP not found.');

        $startDate = $sip['start_date'];
        $endDate   = $sip['end_date'] ?: date('Y-m-d');
        $amount    = (float) $sip['sip_amount'];
        $sipDay    = (int) $sip['sip_day'];
        $fundId    = (int) $sip['fund_id'];
        $freq      = $sip['frequency'];

        // Generate all installment dates
        $installDates = _generate_installment_dates($startDate, $endDate, $sipDay, $freq);

        if (empty($installDates)) {
            json_response(true, '', ['xirr' => null, 'message' => 'No installments yet.']);
        }

        // For each installment date, get NAV from nav_history
        $cashFlows  = [];
        $totalInvested = 0;
        $totalUnits = 0;
        $missingNavs = 0;

        foreach ($installDates as $date) {
            // Get closest NAV on or after the installment date
            $navRow = DB::fetchOne(
                "SELECT nav, nav_date FROM nav_history
                 WHERE fund_id = ? AND nav_date >= ?
                 ORDER BY nav_date ASC LIMIT 1",
                [$fundId, $date]
            );

            if (!$navRow || (float)$navRow['nav'] <= 0) {
                $missingNavs++;
                // Use amount as cash flow even without NAV (for partial XIRR)
                $cashFlows[] = ['date' => $date, 'amount' => -$amount];
                continue;
            }

            $nav   = (float) $navRow['nav'];
            $units = $amount / $nav;
            $totalUnits    += $units;
            $totalInvested += $amount;
            $cashFlows[]    = ['date' => $date, 'amount' => -$amount];
        }

        // Current value = total units × latest NAV
        $currentNav = (float) $sip['latest_nav'];
        $currentValue = $totalUnits * $currentNav;

        // Add terminal cash flow (current value as inflow)
        $today = date('Y-m-d');
        $cashFlows[] = ['date' => $today, 'amount' => $currentValue];

        // Calculate XIRR
        $xirr = null;
        if ($currentValue > 0 && count($cashFlows) >= 2) {
            // Use existing xirr function from helpers
            $xirrInput = array_map(fn($cf) => [
                'date'   => $cf['date'],
                'amount' => $cf['amount'],
            ], $cashFlows);
            $xirr = xirr_from_cashflows($xirrInput);
        }

        $gain    = $currentValue - $totalInvested;
        $gainPct = $totalInvested > 0 ? round(($gain / $totalInvested) * 100, 2) : 0;

        json_response(true, '', [
            'sip_id'          => $sipId,
            'installments'    => count($installDates),
            'missing_navs'    => $missingNavs,
            'total_invested'  => round($totalInvested, 2),
            'total_units'     => round($totalUnits, 4),
            'current_nav'     => $currentNav,
            'current_value'   => round($currentValue, 2),
            'gain'            => round($gain, 2),
            'gain_pct'        => $gainPct,
            'xirr'            => $xirr,
            'nav_date'        => $sip['latest_nav_date'],
        ]);

    // ── NAV Token (for JS to trigger download directly) ───────
    case 'sip_nav_token':
        $fId  = (int) ($_POST['fund_id'] ?? 0);
        $date = clean($_POST['start_date'] ?? '');
        $token = md5($fId . $date . env('APP_KEY','wealthdash'));
        json_response(true, '', ['token' => $token]);

    // ── NAV Download Status for a fund ────────────────────────
    case 'sip_nav_status':
        $fundId    = (int) ($_POST['fund_id'] ?? $_GET['fund_id'] ?? 0);
        $startDate = clean($_POST['start_date'] ?? $_GET['start_date'] ?? '');

        if (!$fundId) json_response(false, 'fund_id required.');

        $fund = DB::fetchOne('SELECT scheme_code FROM funds WHERE id = ?', [$fundId]);
        if (!$fund) json_response(false, 'Fund not found.');

        // Count available NAVs from start_date
        $navCount = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM nav_history WHERE fund_id = ? AND nav_date >= ?",
            [$fundId, $startDate ?: '2000-01-01']
        );

        // Check download progress
        $progress = DB::fetchOne(
            "SELECT status, from_date, last_downloaded_date, records_saved
             FROM nav_download_progress WHERE scheme_code = ?",
            [$fund['scheme_code']]
        );

        $isReady = $navCount > 0;
        $status  = $progress['status'] ?? 'not_queued';

        json_response(true, '', [
            'fund_id'     => $fundId,
            'nav_count'   => $navCount,
            'is_ready'    => $isReady,
            'dl_status'   => $status,
            'dl_progress' => $progress,
        ]);

    default:
        json_response(false, 'Unknown SIP action.', [], 400);
}

// ── Helpers ──────────────────────────────────────────────────
function _next_sip_date(array $sip): ?string {
    if (!$sip['is_active']) return null;
    $today     = new DateTime();
    $year      = (int) $today->format('Y');
    $month     = (int) $today->format('n');
    $day       = (int) $sip['sip_day'];
    $freq      = $sip['frequency'];

    // For daily/weekly/fortnightly — next occurrence from today
    if ($freq === 'daily') {
        $next = (clone $today)->modify('+1 day');
        if ($sip['end_date'] && $next->format('Y-m-d') > $sip['end_date']) return null;
        return $next->format('Y-m-d');
    }
    if ($freq === 'weekly') {
        $next = (clone $today)->modify('+7 days');
        if ($sip['end_date'] && $next->format('Y-m-d') > $sip['end_date']) return null;
        return $next->format('Y-m-d');
    }
    if ($freq === 'fortnightly') {
        $next = (clone $today)->modify('+15 days');
        if ($sip['end_date'] && $next->format('Y-m-d') > $sip['end_date']) return null;
        return $next->format('Y-m-d');
    }

    $candidate = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, min($day, 28)));
    if ($candidate <= $today) {
        $candidate->modify(match($freq) {
            'quarterly' => '+3 months',
            'yearly'    => '+1 year',
            default     => '+1 month',
        });
    }

    if ($sip['end_date'] && $candidate->format('Y-m-d') > $sip['end_date']) {
        return null;
    }
    return $candidate->format('Y-m-d');
}

function _sip_total_invested(array $sip, int $portfolioId): float {
    if (!$sip['fund_id']) return 0.0;
    return (float) DB::fetchVal(
        "SELECT COALESCE(SUM(value_at_cost),0) FROM mf_transactions
         WHERE portfolio_id=? AND fund_id=? AND transaction_type='BUY'",
        [$portfolioId, $sip['fund_id']]
    );
}

function _months_running(string $startDate): int {
    $start = new DateTime($startDate);
    $now   = new DateTime();
    if ($start > $now) return 0;
    return (int) $start->diff($now)->m + ($start->diff($now)->y * 12);
}

/**
 * Generate all installment dates between start and end
 */
function _generate_installment_dates(string $startDate, string $endDate, int $sipDay, string $freq): array {
    $dates   = [];
    $today   = date('Y-m-d');
    $current = new DateTime($startDate);
    $end     = new DateTime(min($endDate, $today)); // don't go beyond today

    // Snap to SIP day
    // For daily/weekly/fortnightly — start from exact start_date
    if (in_array($freq, ['daily', 'weekly', 'fortnightly'])) {
        $current = new DateTime($startDate);
    } else {
        // For monthly+ — snap to SIP day
        $current->setDate((int)$current->format('Y'), (int)$current->format('n'), min($sipDay, 28));
        // If snapped date is before start, add one period
        if ($current->format('Y-m-d') < $startDate) {
            $current->modify(match($freq) {
                'quarterly' => '+3 months',
                'yearly'    => '+1 year',
                default     => '+1 month',
            });
        }
    }

    // Safety cap — daily could be huge
    $maxIterations = match($freq) {
        'daily'       => 3650,   // max 10 years daily
        'weekly'      => 1500,
        'fortnightly' => 800,
        default       => 600,
    };

    $i = 0;
    while ($current <= $end && $i++ < $maxIterations) {
        $dates[] = $current->format('Y-m-d');
        $current->modify(match($freq) {
            'daily'       => '+1 day',
            'weekly'      => '+7 days',
            'fortnightly' => '+15 days',
            'quarterly'   => '+3 months',
            'yearly'      => '+1 year',
            default       => '+1 month',
        });
        // Keep day consistent only for monthly+
        if (!in_array($freq, ['daily', 'weekly', 'fortnightly'])) {
            try {
                $current->setDate(
                    (int)$current->format('Y'),
                    (int)$current->format('n'),
                    min($sipDay, (int)$current->format('t'))
                );
            } catch (\Exception $e) {}
        }
    }
    return $dates;
}

/**
 * XIRR from [{date, amount}] cashflows
 * Wraps existing xirr() in helpers.php
 */
function xirr_from_cashflows(array $cashFlows): ?float {
    if (count($cashFlows) < 2) return null;

    // Convert to format xirr() expects
    $formatted = [];
    foreach ($cashFlows as $cf) {
        $formatted[] = [
            'date'   => $cf['date'],
            'amount' => (float) $cf['amount'],
        ];
    }

    // Use existing xirr function
    if (function_exists('xirr')) {
        return xirr($formatted);
    }

    // Fallback: use xirr_from_txns signature if available
    return null;
}