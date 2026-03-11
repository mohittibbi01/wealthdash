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
        $frequency = in_array($_POST['frequency'] ?? '', ['monthly','quarterly','weekly','yearly'])
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
        $fund = DB::fetchOne('SELECT id, scheme_name FROM funds WHERE id = ?', [$fundId]);
        if (!$fund) json_response(false, 'Fund not found.');

        DB::run(
            'INSERT INTO sip_schedules
             (portfolio_id, asset_type, fund_id, folio_number, sip_amount, frequency,
              sip_day, start_date, end_date, platform, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$portfolioId, 'mf', $fundId, $folio ?: null, $amount, $frequency,
             $sipDay, $startDate, $endDate, $platform ?: null, $notes ?: null]
        );
        $id = (int) DB::lastInsertId();
        audit_log('sip_add', 'sip_schedules', $id);
        json_response(true, 'SIP added successfully.', ['id' => $id]);

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

    default:
        json_response(false, 'Unknown SIP action.', [], 400);
}

// ── Helpers ──────────────────────────────────────────────────
function _next_sip_date(array $sip): ?string {
    if (!$sip['is_active']) return null;
    $today   = new DateTime();
    $year    = (int) $today->format('Y');
    $month   = (int) $today->format('n');
    $day     = (int) $sip['sip_day'];
    $freq    = $sip['frequency'];

    $candidate = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, min($day, 28)));
    if ($candidate <= $today) {
        $candidate->modify(match($freq) {
            'weekly'    => '+7 days',
            'monthly'   => '+1 month',
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

