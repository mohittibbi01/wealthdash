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

// DEBUG: log what we're getting

// If no portfolioId given, use user's first portfolio
if (!$portfolioId) {
    $firstPortfolio = DB::fetchOne(
        'SELECT id FROM portfolios WHERE user_id = ? ORDER BY id ASC LIMIT 1',
        [$userId]
    );
    if ($firstPortfolio) {
        $portfolioId = (int) $firstPortfolio['id'];
    }
}

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── List SIPs ─────────────────────────────────────────────
    case 'sip_list':
        try {
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
        } catch (Exception $e) {
            json_response(false, 'sip_list error: ' . $e->getMessage());
        }

    // ── SIP Analysis (all active SIPs summary) ────────────────
    case 'sip_analysis':
        try {
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
        } catch (Exception $e) {
            json_response(false, 'sip_analysis error: ' . $e->getMessage());
        }

    // ── Upcoming SIPs (next 30 days) ──────────────────────────
    case 'sip_upcoming':
        try {
        $days   = min((int) ($_GET['days'] ?? $_POST['days'] ?? 30), 90);
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
        } catch (Exception $e) {
            json_response(false, 'sip_upcoming error: ' . $e->getMessage());
        }

    // ── Monthly SIP history (months × amount chart data) ─────
    case 'sip_monthly_chart':
        $months = min((int) ($_POST['months'] ?? $_GET['months'] ?? 12), 60);

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

        json_response(true, '', ['chart' => $rows ?: [], 'months' => $months]);

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

        // Determine type: SWP if notes='SWP', otherwise SIP
        $scheduleType = (strtoupper($notes) === 'SWP') ? 'SWP' : 'SIP';

        DB::run(
            'INSERT INTO sip_schedules
             (portfolio_id, asset_type, fund_id, folio_number, sip_amount, frequency,
              sip_day, start_date, end_date, platform, notes, schedule_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$portfolioId, 'mf', $fundId, $folio ?: null, $amount, $frequency,
             $sipDay, $startDate, $endDate, $platform ?: null, $notes ?: null, $scheduleType]
        );
        $id = (int) DB::conn()->lastInsertId();
        audit_log('sip_add', 'sip_schedules', $id);

        // ── Step 1: Ensure NAV history exists (download if needed) ────
        set_time_limit(120); // allow up to 2 min for download + processing
        $schemeCode = $fund['scheme_code'];

        $navCount = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM nav_history WHERE fund_id = ? AND nav_date >= ?",
            [$fundId, $startDate]
        );

        if ($navCount === 0) {
            // Download full NAV history from mfapi.in synchronously
            $ctx = stream_context_create([
                'http' => ['timeout' => 30, 'user_agent' => 'WealthDash/1.0'],
                'ssl'  => ['verify_peer' => false],
            ]);
            $raw  = @file_get_contents("https://api.mfapi.in/mf/{$schemeCode}", false, $ctx);
            $json = $raw ? @json_decode($raw, true) : null;

            if (!empty($json['data'])) {
                $insNav = DB::conn()->prepare(
                    "INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?, ?, ?)"
                );
                $latestNDate = null; $latestNNav = null;
                foreach ($json['data'] as $entry) {
                    $parts = explode('-', $entry['date'] ?? '');
                    if (count($parts) !== 3) continue;
                    $isoDate = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                    $nav = (float)($entry['nav'] ?? 0);
                    if ($nav <= 0) continue;
                    $insNav->execute([$fundId, $isoDate, $nav]);
                    if (!$latestNDate || $isoDate > $latestNDate) { $latestNDate = $isoDate; $latestNNav = $nav; }
                }
                // Update funds table with latest NAV
                if ($latestNDate) {
                    DB::run("UPDATE funds SET latest_nav=?, latest_nav_date=?, updated_at=NOW() WHERE id=? AND (latest_nav_date IS NULL OR latest_nav_date < ?)",
                        [$latestNNav, $latestNDate, $fundId, $latestNDate]);
                }
                $navCount = (int) DB::fetchVal(
                    "SELECT COUNT(*) FROM nav_history WHERE fund_id = ? AND nav_date >= ?",
                    [$fundId, $startDate]
                );
            }
        }

        // ── Step 2: Generate past SIP/SWP transactions inline ────────
        $txnsGenerated = 0;
        $txnErrors     = 0;
        $isSWP         = ($scheduleType === 'SWP');
        $txnType       = $isSWP ? 'SWP' : 'BUY';
        $sipEndForTxn  = $endDate ?: date('Y-m-d');
        $sipEndCap     = min($sipEndForTxn, date('Y-m-d'));

        $installDates = _sip_generate_dates_inline($startDate, $sipEndCap, $sipDay, $frequency);

        if (!empty($installDates) && $navCount > 0) {
            $db = DB::conn();
            $navStmt = $db->prepare(
                "SELECT nav, nav_date FROM nav_history
                 WHERE fund_id = ? AND nav_date >= ?
                 ORDER BY nav_date ASC LIMIT 1"
            );
            $dupStmt = $db->prepare(
                "SELECT id FROM mf_transactions
                 WHERE portfolio_id=? AND fund_id=? AND transaction_type=? AND txn_date=? LIMIT 1"
            );
            $insStmt = $db->prepare(
                "INSERT INTO mf_transactions
                 (portfolio_id, fund_id, folio_number, transaction_type, platform,
                  txn_date, units, nav, value_at_cost, stamp_duty, notes, import_source, investment_fy)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?)"
            );

            foreach ($installDates as $date) {
                // Skip if already exists
                $dupStmt->execute([$portfolioId, $fundId, $txnType, $date]);
                if ($dupStmt->fetch()) continue;

                $navStmt->execute([$fundId, $date]);
                $navRow = $navStmt->fetch(PDO::FETCH_ASSOC);
                if (!$navRow || (float)$navRow['nav'] <= 0) { $txnErrors++; continue; }

                $navVal     = (float)$navRow['nav'];
                $actualDate = $navRow['nav_date'];
                $units      = round($amount / $navVal, 4);
                $yr         = (int)date('Y', strtotime($actualDate));
                $mo         = (int)date('n', strtotime($actualDate));
                $fy         = $mo >= 4 ? "{$yr}-" . substr((string)($yr+1),2) : ($yr-1) . '-' . substr((string)$yr,2);

                try {
                    $insStmt->execute([
                        $portfolioId, $fundId, $folio ?: null, $txnType, $platform ?: null,
                        $actualDate, $units, $navVal, $amount,
                        'Auto-generated SIP #' . $id, 'manual', $fy
                    ]);
                    $txnsGenerated++;
                } catch (Exception $e) { $txnErrors++; }
            }

            // Recalculate holdings
            if ($txnsGenerated > 0) {
                try {
                    require_once APP_ROOT . '/includes/holding_calculator.php';
                    HoldingCalculator::recalculate_mf_holding($portfolioId, $fundId, $folio ?: null);
                } catch (Exception $ignored) {}
            }
        }

        json_response(true, 'SIP added successfully.', [
            'id'             => $id,
            'nav_status'     => $navCount > 0 ? 'available' : 'no_data',
            'nav_count'      => $navCount,
            'txns_generated' => $txnsGenerated,
            'txn_errors'     => $txnErrors,
            'nav_message'    => $txnsGenerated > 0
                ? "SIP saved. {$txnsGenerated} past transactions generated automatically."
                : ($navCount === 0 ? 'NAV data not available. Transactions could not be generated.' : 'SIP saved.'),
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

    // ── Stop SIP/SWP ──────────────────────────────────────────
    case 'sip_stop':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $sipId  = (int) ($_POST['sip_id'] ?? 0);
        $endDt  = !empty($_POST['end_date']) ? date_to_db(clean($_POST['end_date'])) : date('Y-m-d');

        $sip = DB::fetchOne(
            'SELECT id, schedule_type FROM sip_schedules WHERE id = ? AND portfolio_id = ?',
            [$sipId, $portfolioId]
        );
        if (!$sip) json_response(false, 'SIP not found.');

        DB::run(
            'UPDATE sip_schedules SET is_active = 0, end_date = ? WHERE id = ?',
            [$endDt, $sipId]
        );
        audit_log('sip_stop', 'sip_schedules', $sipId);
        $type = $sip['schedule_type'] ?? 'SIP';
        json_response(true, "$type stopped successfully.", ['end_date' => $endDt]);

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
    // ── Sync / Regenerate past transactions for existing SIP ────
    case 'sip_sync_txns':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $sipId = (int) ($_POST['sip_id'] ?? 0);
        if (!$sipId) json_response(false, 'sip_id required.');

        $sipRow = DB::fetchOne(
            'SELECT s.*, f.scheme_code, f.scheme_name FROM sip_schedules s
             JOIN funds f ON f.id = s.fund_id
             WHERE s.id = ? AND s.portfolio_id = ?',
            [$sipId, $portfolioId]
        );
        if (!$sipRow) json_response(false, 'SIP not found.');

        $fundId    = (int)$sipRow['fund_id'];
        $schCode   = $sipRow['scheme_code'];
        $startDate = $sipRow['start_date'];
        $sipEnd    = $sipRow['end_date'] ?: date('Y-m-d');
        $sipEndCap = min($sipEnd, date('Y-m-d'));
        $amount    = (float)$sipRow['sip_amount'];
        $frequency = $sipRow['frequency'];
        $sipDay    = (int)$sipRow['sip_day'];
        $folio     = $sipRow['folio_number'] ?? null;
        $platform  = $sipRow['platform'] ?? null;
        $isSWP2    = ($sipRow['schedule_type'] === 'SWP');
        $txnType2  = $isSWP2 ? 'SWP' : 'BUY';

        set_time_limit(120);

        // Download NAV history if missing
        $navCount2 = (int) DB::fetchVal(
            'SELECT COUNT(*) FROM nav_history WHERE fund_id = ? AND nav_date >= ?',
            [$fundId, $startDate]
        );
        if ($navCount2 === 0) {
            $ctx2 = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'WealthDash/1.0'], 'ssl' => ['verify_peer' => false]]);
            $raw2  = @file_get_contents("https://api.mfapi.in/mf/{$schCode}", false, $ctx2);
            $json2 = $raw2 ? @json_decode($raw2, true) : null;
            if (!empty($json2['data'])) {
                $ins2 = DB::conn()->prepare('INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?,?,?)');
                $lDate = null; $lNav = null;
                foreach ($json2['data'] as $e2) {
                    $p2 = explode('-', $e2['date'] ?? '');
                    if (count($p2) !== 3) continue;
                    $d2 = "{$p2[2]}-{$p2[1]}-{$p2[0]}";
                    $n2 = (float)($e2['nav'] ?? 0);
                    if ($n2 <= 0) continue;
                    $ins2->execute([$fundId, $d2, $n2]);
                    if (!$lDate || $d2 > $lDate) { $lDate = $d2; $lNav = $n2; }
                }
                if ($lDate) DB::run('UPDATE funds SET latest_nav=?,latest_nav_date=?,updated_at=NOW() WHERE id=? AND (latest_nav_date IS NULL OR latest_nav_date < ?)', [$lNav,$lDate,$fundId,$lDate]);
            }
        }

        // Generate transactions
        $dates2 = _sip_generate_dates_inline($startDate, $sipEndCap, $sipDay, $frequency);
        $gen2 = 0; $err2 = 0;
        if (!empty($dates2)) {
            $db2    = DB::conn();
            $navSt  = $db2->prepare('SELECT nav, nav_date FROM nav_history WHERE fund_id=? AND nav_date>=? ORDER BY nav_date ASC LIMIT 1');
            $dupSt  = $db2->prepare('SELECT id FROM mf_transactions WHERE portfolio_id=? AND fund_id=? AND transaction_type=? AND txn_date=? LIMIT 1');
            $insSt  = $db2->prepare('INSERT INTO mf_transactions (portfolio_id,fund_id,folio_number,transaction_type,platform,txn_date,units,nav,value_at_cost,stamp_duty,notes,import_source,investment_fy) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?)');
            foreach ($dates2 as $d3) {
                $dupSt->execute([$portfolioId, $fundId, $txnType2, $d3]);
                if ($dupSt->fetch()) continue;
                $navSt->execute([$fundId, $d3]);
                $nr = $navSt->fetch(PDO::FETCH_ASSOC);
                if (!$nr || (float)$nr['nav'] <= 0) { $err2++; continue; }
                $nv3 = (float)$nr['nav']; $ad3 = $nr['nav_date'];
                $un3 = round($amount / $nv3, 4);
                $yr3 = (int)date('Y',strtotime($ad3)); $mo3 = (int)date('n',strtotime($ad3));
                $fy3 = $mo3>=4 ? "{$yr3}-".substr((string)($yr3+1),2) : ($yr3-1).'-'.substr((string)$yr3,2);
                try { $insSt->execute([$portfolioId,$fundId,$folio,$txnType2,$platform,$ad3,$un3,$nv3,$amount,'Auto SIP #'.$sipId,'manual',$fy3]); $gen2++; }
                catch(Exception $e3) { $err2++; }
            }
            if ($gen2 > 0) {
                try {
                    require_once APP_ROOT . '/includes/holding_calculator.php';
                    HoldingCalculator::recalculate_mf_holding($portfolioId, $fundId, $folio);
                } catch(Exception $ignored2) {}
            }
        }
        json_response(true, 'Sync complete.', [
            'txns_generated' => $gen2,
            'txn_errors'     => $err2,
            'message'        => "{$gen2} transactions generated, {$err2} skipped (no NAV).",
        ]);

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

function _sip_generate_dates_inline(string $start, string $end, int $sipDay, string $freq): array {
    $dates   = [];
    $current = new DateTime($start);
    $endDt   = new DateTime($end);
    if (!in_array($freq, ['daily', 'weekly', 'fortnightly'])) {
        $current->setDate((int)$current->format('Y'), (int)$current->format('n'), min($sipDay, 28));
        if ($current->format('Y-m-d') < $start) {
            $current->modify(match($freq) {
                'quarterly' => '+3 months', 'yearly' => '+1 year', default => '+1 month',
            });
        }
    }
    $max = match($freq) { 'daily' => 3650, 'weekly' => 1500, 'fortnightly' => 800, default => 600 };
    $i = 0;
    while ($current <= $endDt && $i++ < $max) {
        $dates[] = $current->format('Y-m-d');
        $current->modify(match($freq) {
            'daily'       => '+1 day',
            'weekly'      => '+7 days',
            'fortnightly' => '+15 days',
            'quarterly'   => '+3 months',
            'yearly'      => '+1 year',
            default       => '+1 month',
        });
        if (!in_array($freq, ['daily','weekly','fortnightly'])) {
            $current->setDate((int)$current->format('Y'), (int)$current->format('n'),
                min($sipDay, (int)$current->format('t')));
        }
    }
    return $dates;
}

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