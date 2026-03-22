<?php
/**
 * WealthDash — Goal Planning API
 * Actions: goal_list | goal_add | goal_edit | goal_delete | goal_projection | goal_contribute
 */
declare(strict_types=1);

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$userId      = (int) $_SESSION['user_id'];
$isAdmin     = is_admin();
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── List Goals ────────────────────────────────────────────
    case 'goal_list':
        $goals = DB::fetchAll(
            "SELECT g.*,
                    COALESCE((SELECT SUM(amount) FROM goal_contributions WHERE goal_id=g.id),0)
                        AS contributions_sum
             FROM investment_goals g
             WHERE g.portfolio_id = ?
             ORDER BY g.is_achieved ASC, g.priority DESC, g.target_date ASC",
            [$portfolioId]
        );

        foreach ($goals as &$goal) {
            $goal = _enrich_goal($goal);
        }
        unset($goal);

        // Summary
        $totalTargets  = array_sum(array_column($goals, 'target_amount'));
        $totalSaved    = array_sum(array_column($goals, 'effective_saved'));
        $achieved      = count(array_filter($goals, fn($g) => $g['is_achieved']));

        json_response(true, '', [
            'goals'         => $goals,
            'total_targets' => round($totalTargets, 2),
            'total_saved'   => round($totalSaved, 2),
            'achieved_count'=> $achieved,
        ]);

    // ── Goal Projection Calculator (standalone) ───────────────
    case 'goal_projection':
        $targetAmount  = (float) ($_POST['target_amount']   ?? $_GET['target_amount']   ?? 0);
        $returnPct     = (float) ($_POST['return_pct']      ?? $_GET['return_pct']      ?? 12);
        $months        = (int)   ($_POST['months']          ?? $_GET['months']          ?? 120);
        $currentSaved  = (float) ($_POST['current_saved']   ?? $_GET['current_saved']   ?? 0);

        if ($targetAmount <= 0 || $months <= 0) {
            json_response(false, 'target_amount and months are required.');
        }

        $result = _calculate_projection($targetAmount, $returnPct, $months, $currentSaved);
        json_response(true, '', $result);

    // ── Add Goal ──────────────────────────────────────────────
    case 'goal_add':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $name        = clean($_POST['name']        ?? '');
        $desc        = clean($_POST['description'] ?? '');
        $target      = (float) ($_POST['target_amount'] ?? 0);
        $targetDate  = date_to_db(clean($_POST['target_date'] ?? ''));
        $returnPct   = (float) ($_POST['expected_return_pct'] ?? 12);
        $priority    = in_array($_POST['priority'] ?? '', ['high','medium','low'])
                       ? $_POST['priority'] : 'medium';
        $color       = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '')
                       ? $_POST['color'] : '#2563EB';
        $icon        = clean($_POST['icon'] ?? 'target');

        if (!$name || $target <= 0 || !$targetDate) {
            json_response(false, 'Name, target amount, and target date are required.');
        }

        // Auto-calculate monthly SIP needed
        $monthsLeft = _months_to_date($targetDate);
        $monthlySip = $monthsLeft > 0
            ? _sip_needed($target, $returnPct, $monthsLeft, 0)
            : null;

        $id = (int)DB::insert(
            'INSERT INTO investment_goals
             (portfolio_id, name, description, target_amount, target_date,
              expected_return_pct, priority, color, icon, monthly_sip_needed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$portfolioId, $name, $desc ?: null, $target, $targetDate,
             $returnPct, $priority, $color, $icon, $monthlySip]
        );
        audit_log('goal_add', 'investment_goals', $id);
        json_response(true, 'Goal created.', ['id' => $id]);

    // ── Edit Goal ─────────────────────────────────────────────
    case 'goal_edit':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId     = (int) ($_POST['goal_id'] ?? 0);
        $name       = clean($_POST['name']     ?? '');
        $target     = (float) ($_POST['target_amount']      ?? 0);
        $targetDate = date_to_db(clean($_POST['target_date'] ?? ''));
        $returnPct  = (float) ($_POST['expected_return_pct'] ?? 12);
        $priority   = in_array($_POST['priority'] ?? '', ['high','medium','low'])
                      ? $_POST['priority'] : 'medium';
        $color      = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '')
                      ? $_POST['color'] : '#2563EB';

        $goal = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        $monthsLeft = _months_to_date($targetDate);
        $currentSaved = (float) DB::fetchVal(
            'SELECT COALESCE(SUM(amount),0) FROM goal_contributions WHERE goal_id=?',
            [$goalId]
        );
        $monthlySip = $monthsLeft > 0
            ? _sip_needed($target, $returnPct, $monthsLeft, $currentSaved)
            : null;

        DB::run(
            'UPDATE investment_goals
             SET name=?, target_amount=?, target_date=?, expected_return_pct=?,
                 priority=?, color=?, monthly_sip_needed=?
             WHERE id=?',
            [$name, $target, $targetDate, $returnPct, $priority, $color, $monthlySip, $goalId]
        );
        audit_log('goal_edit', 'investment_goals', $goalId);
        json_response(true, 'Goal updated.');

    // ── Delete Goal ───────────────────────────────────────────
    case 'goal_delete':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $goal   = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run('DELETE FROM investment_goals WHERE id = ?', [$goalId]);
        audit_log('goal_delete', 'investment_goals', $goalId);
        json_response(true, 'Goal deleted.');

    // ── Mark Goal Achieved ────────────────────────────────────
    case 'goal_mark_achieved':
        csrf_verify();
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $goal   = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run(
            'UPDATE investment_goals SET is_achieved=1, achieved_at=CURDATE() WHERE id=?',
            [$goalId]
        );
        audit_log('goal_mark_achieved', 'investment_goals', $goalId);
        json_response(true, '🎉 Goal marked as achieved!');

    // ── Add Contribution ──────────────────────────────────────
    case 'goal_contribute':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId  = (int)   ($_POST['goal_id'] ?? 0);
        $amount  = (float) ($_POST['amount']  ?? 0);
        $date    = date_to_db(clean($_POST['date'] ?? date('d-m-Y')));
        $note    = clean($_POST['note'] ?? '');

        if (!$goalId || $amount <= 0) json_response(false, 'Goal ID and amount required.');

        $goal = DB::fetchOne(
            'SELECT id, target_amount FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run(
            'INSERT INTO goal_contributions (goal_id, amount, contribution_date, note)
             VALUES (?, ?, ?, ?)',
            [$goalId, $amount, $date, $note ?: null]
        );

        // Check if goal achieved now
        $totalSaved = (float) DB::fetchVal(
            'SELECT COALESCE(SUM(amount),0) FROM goal_contributions WHERE goal_id=?',
            [$goalId]
        );
        if ($totalSaved >= (float) $goal['target_amount']) {
            DB::run(
                'UPDATE investment_goals SET is_achieved=1, achieved_at=CURDATE() WHERE id=? AND is_achieved=0',
                [$goalId]
            );
        }

        json_response(true, 'Contribution recorded.', ['total_saved' => round($totalSaved, 2)]);

    // ── SIP / SWP Actions ─────────────────────────────────────────────────
    case 'sip_list':
        $showInactive = (int)($_POST['show_inactive'] ?? $_GET['show_inactive'] ?? 0);
        $whereActive  = $showInactive ? '' : 'AND s.is_active = 1';
        $sips = DB::fetchAll(
            "SELECT s.*,
                    f.scheme_name AS fund_name,
                    fh.short_name AS fund_house,
                    f.category AS fund_category,
                    f.scheme_code,
                    h.total_invested,
                    h.value_now,
                    (h.value_now - h.total_invested) AS gain_loss,
                    (
                        SELECT txn_date FROM mf_transactions t
                        WHERE t.portfolio_id = s.portfolio_id
                          AND t.fund_id      = s.fund_id
                          AND t.transaction_type IN ('BUY','SWP')
                        ORDER BY t.txn_date DESC LIMIT 1
                    ) AS last_txn_date,
                    -- MySQL se next_date compute karo (monthly only; PHP fallback baaki ke liye)
                    CASE
                      WHEN s.is_active = 0 THEN NULL
                      WHEN s.end_date IS NOT NULL AND s.end_date != '0000-00-00' AND s.end_date < CURDATE() THEN NULL
                      WHEN s.frequency = 'monthly' THEN
                        CASE
                          WHEN DAY(CURDATE()) <= s.sip_day
                            THEN STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d')
                          ELSE DATE_ADD(
                                 STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d'),
                                 INTERVAL 1 MONTH)
                        END
                      WHEN s.frequency = 'quarterly' THEN
                        CASE
                          WHEN DAY(CURDATE()) <= s.sip_day
                            THEN STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d')
                          ELSE DATE_ADD(
                                 STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d'),
                                 INTERVAL 3 MONTH)
                        END
                      WHEN s.frequency = 'yearly' THEN
                        CASE
                          WHEN DAY(CURDATE()) <= s.sip_day
                            THEN STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d')
                          ELSE DATE_ADD(
                                 STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d'),
                                 INTERVAL 1 YEAR)
                        END
                      WHEN s.frequency = 'weekly'      THEN DATE_ADD(CURDATE(), INTERVAL 7  DAY)
                      WHEN s.frequency = 'fortnightly' THEN DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                      WHEN s.frequency = 'daily'       THEN DATE_ADD(CURDATE(), INTERVAL 1  DAY)
                      ELSE NULL
                    END AS next_date_sql
             FROM sip_schedules s
             LEFT JOIN funds        f ON f.id = s.fund_id
             LEFT JOIN mf_holdings  h ON h.fund_id = s.fund_id AND h.portfolio_id = s.portfolio_id AND h.is_active = 1
             LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
             WHERE s.portfolio_id = ? AND s.asset_type = 'mf' $whereActive
             ORDER BY s.is_active DESC, s.created_at DESC",
            [$portfolioId]
        );
        // start_date / end_date normalize + next_date assign
        foreach ($sips as &$s) {
            // start_date normalize (DD-MM-YYYY → YYYY-MM-DD for old bad data)
            $rawStart = (string)($s['start_date'] ?? '');
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $rawStart)) {
                $parsedSd  = DateTime::createFromFormat('d-m-Y', $rawStart);
                $s['start_date'] = $parsedSd ? $parsedSd->format('Y-m-d') : $rawStart;
            }
            // Use MySQL-computed next_date (already a YYYY-MM-DD string or null)
            $s['next_date'] = $s['next_date_sql'] ?? null;
            unset($s['next_date_sql']);
        }
        unset($s);
        json_response(true, 'OK', ['sips' => $sips]);

    case 'sip_analysis':
        $sips = DB::fetchAll(
            "SELECT s.schedule_type, s.sip_amount, s.frequency,
                    h.total_invested, h.value_now
             FROM sip_schedules s
             LEFT JOIN mf_holdings h ON h.fund_id = s.fund_id AND h.portfolio_id = s.portfolio_id AND h.is_active = 1
             WHERE s.portfolio_id = ? AND s.is_active = 1 AND s.asset_type = 'mf'",
            [$portfolioId]
        );
        $activeSips   = array_filter($sips, fn($s) => $s['schedule_type'] === 'SIP');
        $activeSWPs   = array_filter($sips, fn($s) => $s['schedule_type'] === 'SWP');
        $monthlyTotal = 0;
        foreach ($activeSips as $s) {
            $monthlyTotal += _to_monthly((float)$s['sip_amount'], $s['frequency']);
        }
        $totalInvested = array_sum(array_column($sips, 'total_invested'));
        $valueNow      = array_sum(array_column($sips, 'value_now'));
        $gain          = $valueNow - $totalInvested;
        $gainPct       = $totalInvested > 0 ? round($gain / $totalInvested * 100, 2) : 0;
        json_response(true, 'OK', [
            'sips'              => array_values($activeSips),
            'swps'              => array_values($activeSWPs),
            'total_monthly_sip' => round($monthlyTotal, 2),
            'active_swp_count'  => count($activeSWPs),
            'total_invested'    => round($totalInvested, 2),
            'overall_gain'      => round($gain, 2),
            'overall_gain_pct'  => $gainPct,
        ]);

    case 'sip_upcoming':
        // Success:true ALWAYS return karo — API.post success:false pe throw karta hai
        $days = min((int)($_POST['days'] ?? 30), 90);
        $upcoming = [];
        try {
            $cutoffDate = date('Y-m-d', strtotime("+{$days} days"));
            $sips = DB::fetchAll(
                "SELECT s.id, s.schedule_type, s.sip_amount, s.frequency, s.sip_day,
                        s.start_date, s.end_date, s.platform,
                        f.scheme_name, fh.short_name AS fund_house,
                        CASE
                          WHEN s.end_date IS NOT NULL AND s.end_date != '0000-00-00' AND s.end_date < CURDATE() THEN NULL
                          WHEN s.frequency = 'monthly' THEN
                            CASE
                              WHEN DAY(CURDATE()) <= s.sip_day
                                THEN STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d')
                              ELSE DATE_ADD(
                                     STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d'),
                                     INTERVAL 1 MONTH)
                            END
                          WHEN s.frequency = 'quarterly' THEN
                            CASE
                              WHEN DAY(CURDATE()) <= s.sip_day
                                THEN STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d')
                              ELSE DATE_ADD(
                                     STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d'),
                                     INTERVAL 3 MONTH)
                            END
                          WHEN s.frequency = 'yearly' THEN
                            CASE
                              WHEN DAY(CURDATE()) <= s.sip_day
                                THEN STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d')
                              ELSE DATE_ADD(
                                     STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(),'%Y-%m-'), LPAD(s.sip_day,2,'0')), '%Y-%m-%d'),
                                     INTERVAL 1 YEAR)
                            END
                          WHEN s.frequency = 'weekly'      THEN DATE_ADD(CURDATE(), INTERVAL 7  DAY)
                          WHEN s.frequency = 'fortnightly' THEN DATE_ADD(CURDATE(), INTERVAL 14 DAY)
                          WHEN s.frequency = 'daily'       THEN DATE_ADD(CURDATE(), INTERVAL 1  DAY)
                          ELSE NULL
                        END AS next_date
                 FROM sip_schedules s
                 LEFT JOIN funds f  ON f.id  = s.fund_id
                 LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
                 WHERE s.portfolio_id = ? AND s.is_active = 1
                   AND (
                     -- Only fetch SIPs whose next_date falls within our window
                     -- (pre-filter in SQL for performance)
                     s.end_date IS NULL OR s.end_date = '0000-00-00' OR s.end_date >= CURDATE()
                   )",
                [$portfolioId]
            );
            $today = date('Y-m-d');
            foreach ($sips as $s) {
                $next = $s['next_date'] ?? null;
                if (!$next || $next < $today || $next > $cutoffDate) continue;
                $daysRemaining = (int)((strtotime($next) - strtotime($today)) / 86400);
                // start_date normalize for display
                $rawStart = (string)($s['start_date'] ?? '');
                if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $rawStart)) {
                    $parsedSd = DateTime::createFromFormat('d-m-Y', $rawStart);
                    $s['start_date'] = $parsedSd ? $parsedSd->format('Y-m-d') : $rawStart;
                }
                $s['days_remaining'] = $daysRemaining;
                $upcoming[] = $s;
            }
            usort($upcoming, fn($a, $b) => strcmp((string)($a['next_date']??''), (string)($b['next_date']??'')));
        } catch (\Throwable $e) {
            json_response(true, 'OK', ['upcoming' => [], '_debug_error' => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()]);
        }
        json_response(true, 'OK', ['upcoming' => $upcoming, '_count' => count($upcoming)]);

    case 'sip_monthly_chart':
        $months = min((int)($_POST['months'] ?? 12), 60);
        $chart  = DB::fetchAll(
            "SELECT DATE_FORMAT(t.txn_date,'%Y-%m') AS ym,
                    SUM(t.value_at_cost) AS invested
             FROM mf_transactions t
             JOIN sip_schedules   s ON s.fund_id = t.fund_id AND s.portfolio_id = t.portfolio_id
             WHERE t.portfolio_id = ? AND t.transaction_type IN ('BUY','SWP')
               AND t.txn_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
             GROUP BY ym ORDER BY ym",
            [$portfolioId, $months]
        );
        json_response(true, 'OK', ['chart' => $chart]);

    // ── t10: Add SIP/SWP ──────────────────────────────────────────────────
    case 'sip_add':
    case 'sip_edit':
        $sipId       = (int)($_POST['sip_id'] ?? 0);
        $fundId      = (int)($_POST['fund_id'] ?? 0);
        $type        = strtoupper(trim($_POST['schedule_type'] ?? 'SIP'));
        $amount      = (float)($_POST['sip_amount'] ?? 0);
        $freq        = trim($_POST['frequency']     ?? 'monthly');
        $day         = max(1, min(28, (int)($_POST['sip_day'] ?? 1)));
        $startDate   = date_to_db(trim($_POST['start_date'] ?? date('d-m-Y')));
        $endDate     = (trim($_POST['end_date'] ?? '') !== '') ? date_to_db(trim($_POST['end_date'])) : null;
        $platform    = trim($_POST['platform']       ?? '') ?: null;
        $notes       = trim($_POST['notes']          ?? '') ?: null;
        $folio       = trim($_POST['folio_number']   ?? '') ?: null;

        if (!$fundId || !$amount || !in_array($type, ['SIP','SWP'])) {
            json_response(false, 'Fund, amount, type required.');
        }

        if ($action === 'sip_edit' && $sipId) {
            DB::run(
                "UPDATE sip_schedules SET schedule_type=?, sip_amount=?, frequency=?, sip_day=?,
                 start_date=?, end_date=?, platform=?, notes=?, folio_number=?, updated_at=NOW()
                 WHERE id=? AND portfolio_id=?",
                [$type, $amount, $freq, $day, $startDate, $endDate, $platform, $notes, $folio, $sipId, $portfolioId]
            );
            json_response(true, $type . ' updated.', ['sip_id' => $sipId]);
        }

        // INSERT new SIP
        $newId = (int)DB::insert(
            "INSERT INTO sip_schedules
             (portfolio_id, asset_type, schedule_type, fund_id, sip_amount, frequency, sip_day,
              start_date, end_date, platform, notes, folio_number, is_active)
             VALUES (?,     'mf',       ?,             ?,       ?,          ?,         ?,
                     ?,          ?,        ?,        ?,     ?,            1)",
            [$portfolioId, $type, $fundId, $amount, $freq, $day,
             $startDate, $endDate, $platform, $notes, $folio]
        );

        // ── t11: Past NAV auto-download ──────────────────────────────────────
        // Agar start_date purani hai, check karo nav_history mein data hai ya nahi
        $schemeCode = DB::fetchVal("SELECT scheme_code FROM funds WHERE id=?", [$fundId]);
        $navExists  = false;
        if ($schemeCode && $startDate < date('Y-m-d')) {
            $count = (int)DB::fetchVal(
                "SELECT COUNT(*) FROM nav_history WHERE fund_id=? AND nav_date >= ? LIMIT 1",
                [$fundId, $startDate]
            );
            $navExists = $count > 0;
        }

        $navFetchUrl = null;
        if ($schemeCode && !$navExists && $startDate < date('Y-m-d')) {
            // Token generate karo — sip_nav_fetch.php same token expect karta hai
            $token = md5($fundId . $startDate . env('APP_KEY', 'wealthdash'));
            $navFetchUrl = APP_URL . "/api/sip/sip_nav_fetch.php"
                . "?fund_id={$fundId}"
                . "&from_date=" . urlencode($startDate)
                . "&scheme_code=" . urlencode($schemeCode)
                . "&token={$token}"
                . "&sip_id={$newId}"
                . "&portfolio_id={$portfolioId}";
        }

        json_response(true, $type . ' added successfully.', [
            'sip_id'        => $newId,
            'nav_fetch_url' => $navFetchUrl,  // JS isse async fire karega
            'nav_missing'   => !$navExists && $schemeCode && $startDate < date('Y-m-d'),
        ]);

    // ── t10: Stop SIP/SWP ──────────────────────────────────────────────────
    case 'sip_stop':
        $sipId   = (int)($_POST['sip_id']   ?? 0);
        $endDate = trim($_POST['end_date']   ?? date('Y-m-d'));
        if (!$sipId) json_response(false, 'sip_id required.');
        // Verify ownership
        $owned = (int)DB::fetchVal(
            "SELECT COUNT(*) FROM sip_schedules WHERE id=? AND portfolio_id=?",
            [$sipId, $portfolioId]
        );
        if (!$owned) json_response(false, 'SIP not found.', [], 403);
        DB::run(
            "UPDATE sip_schedules SET is_active=0, end_date=?, updated_at=NOW() WHERE id=? AND portfolio_id=?",
            [$endDate, $sipId, $portfolioId]
        );
        json_response(true, 'SIP/SWP stopped.');

    case 'sip_delete':
        $sipId = (int)($_POST['sip_id'] ?? 0);
        if (!$sipId) json_response(false, 'sip_id required.');
        $owned = (int)DB::fetchVal(
            "SELECT COUNT(*) FROM sip_schedules WHERE id=? AND portfolio_id=?",
            [$sipId, $portfolioId]
        );
        if (!$owned) json_response(false, 'SIP not found.', [], 403);
        DB::run("DELETE FROM sip_schedules WHERE id=? AND portfolio_id=?", [$sipId, $portfolioId]);
        json_response(true, 'SIP deleted.');

    case 'sip_xirr':
        $sipId = (int)($_POST['sip_id'] ?? 0);
        if (!$sipId) json_response(false, 'sip_id required.');
        $sip = DB::fetchOne(
            "SELECT s.fund_id, s.start_date, f.scheme_name
             FROM sip_schedules s
             JOIN funds f ON f.id=s.fund_id
             WHERE s.id=? AND s.portfolio_id=?",
            [$sipId, $portfolioId]
        );
        if (!$sip) json_response(false, 'SIP not found.');
        // Transactions for this SIP fund
        $txns = DB::fetchAll(
            "SELECT txn_date, transaction_type, value_at_cost AS amount, units
             FROM mf_transactions
             WHERE fund_id=? AND portfolio_id=? AND txn_date >= ?
             ORDER BY txn_date",
            [$sip['fund_id'], $portfolioId, $sip['start_date']]
        );
        // Current value
        $holding = DB::fetchOne(
            "SELECT total_units, value_now FROM mf_holdings WHERE fund_id=? AND portfolio_id=? AND is_active=1",
            [$sip['fund_id'], $portfolioId]
        );
        $xirr = null;
        if ($txns && $holding) {
            $flows = [];
            foreach ($txns as $t) {
                $amt    = (float)$t['value_at_cost'];
                $flows[] = ['date' => $t['txn_date'], 'amount' => in_array($t['transaction_type'], ['SELL','SWP','SWITCH_OUT','STP_OUT']) ? $amt : -$amt];
            }
            $flows[] = ['date' => date('Y-m-d'), 'amount' => (float)$holding['value_now']];
            $xirr = _calc_xirr($flows);
        }
        json_response(true, 'OK', ['xirr' => $xirr, 'fund' => $sip['scheme_name']]);

    case 'sip_nav_token':
        // Frontend requests a token to trigger sip_nav_fetch.php directly
        $fundId  = (int)($_POST['fund_id']  ?? 0);
        $fromDate= trim($_POST['from_date'] ?? '');
        $sipId_t = (int)($_POST['sip_id']   ?? 0);
        if (!$fundId || !$fromDate) json_response(false, 'fund_id and from_date required.');
        $sc    = DB::fetchVal("SELECT scheme_code FROM funds WHERE id=?", [$fundId]);
        $token = md5($fundId . $fromDate . env('APP_KEY', 'wealthdash'));
        $url   = APP_URL . "/api/sip/sip_nav_fetch.php"
               . "?fund_id={$fundId}&from_date=" . urlencode($fromDate)
               . "&scheme_code=" . urlencode($sc ?: '')
               . "&token={$token}&sip_id={$sipId_t}&portfolio_id={$portfolioId}";
        json_response(true, 'OK', ['token' => $token, 'url' => $url, 'scheme_code' => $sc]);

    case 'sip_nav_status':
        $fundId = (int)($_POST['fund_id'] ?? 0);
        if (!$fundId) { json_response(true, 'OK', ['status' => 'unknown']); }
        $sc  = DB::fetchVal("SELECT scheme_code FROM funds WHERE id=?", [$fundId]);
        $row = $sc ? DB::fetchOne(
            "SELECT status, records_saved, last_downloaded_date, error_message FROM nav_download_progress WHERE scheme_code=?",
            [$sc]
        ) : null;
        json_response(true, 'OK', ['status' => $row['status'] ?? 'not_started', 'progress' => $row]);

    case 'sip_sync_txns':
        // Triggers sip_nav_fetch which auto-generates transactions
        $sipId  = (int)($_POST['sip_id']  ?? 0);
        if (!$sipId) json_response(false, 'sip_id required.');
        $sip = DB::fetchOne(
            "SELECT s.fund_id, s.start_date, f.scheme_code FROM sip_schedules s JOIN funds f ON f.id=s.fund_id WHERE s.id=? AND s.portfolio_id=?",
            [$sipId, $portfolioId]
        );
        if (!$sip) json_response(false, 'SIP not found.');
        $token = md5($sip['fund_id'] . $sip['start_date'] . env('APP_KEY', 'wealthdash'));
        $url   = APP_URL . "/api/sip/sip_nav_fetch.php"
               . "?fund_id={$sip['fund_id']}&from_date=" . urlencode($sip['start_date'])
               . "&scheme_code=" . urlencode($sip['scheme_code'])
               . "&token={$token}&sip_id={$sipId}&portfolio_id={$portfolioId}";
        json_response(true, 'Sync triggered.', ['fetch_url' => $url]);

    default:
        json_response(false, 'Unknown action.', [], 400);
}


// ── SIP Helpers ───────────────────────────────────────────────────────────────

/**
 * Agle SIP date calculate karo based on frequency + sip_day
 */
function _next_sip_date(int $day, string $freq, string $startDate, ?string $endDate): ?string {
    try {
        $today  = new DateTime('today'); // midnight — time component mat lo, warna aaj ka SIP kal push ho jaata hai

        // ── start_date format normalize karo (DB mein DD-MM-YYYY ya YYYY-MM-DD dono ho sakte hain) ──
        if ($startDate && $startDate !== '0000-00-00') {
            // DD-MM-YYYY format detect karo
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $startDate)) {
                $parsedStart = DateTime::createFromFormat('d-m-Y', $startDate);
                $start = $parsedStart ?: new DateTime('2000-01-01');
            } else {
                try { $start = new DateTime($startDate); } catch (\Throwable $e) { $start = new DateTime('2000-01-01'); }
            }
        } else {
            $start = new DateTime('2000-01-01');
        }

        // ── end_date normalize karo ──
        $endDate = ($endDate !== null && $endDate !== '') ? $endDate : null;
        if ($endDate) {
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $endDate)) {
                $parsedEnd = DateTime::createFromFormat('d-m-Y', $endDate);
                $endDt = $parsedEnd ?: null;
            } else {
                try { $endDt = new DateTime($endDate); } catch (\Throwable $e) { $endDt = null; }
            }
            if ($endDt && $endDt < $today) return null;
        } else {
            $endDt = null;
        }

        $day    = max(1, min(28, $day));
        $ym     = $today->format('Y-m-'); // e.g. "2026-03-"
        $dayStr = str_pad((string)$day, 2, '0', STR_PAD_LEFT);

        switch ($freq) {
            case 'monthly':
                $candidate = new DateTime($ym . $dayStr);
                if ($candidate < $today) $candidate->modify('+1 month');
                break;
            case 'quarterly':
                $candidate = new DateTime($ym . $dayStr);
                while ($candidate < $today) $candidate->modify('+3 months');
                break;
            case 'weekly':
                $candidate = clone $today;
                $candidate->modify('+7 days');
                break;
            case 'fortnightly':
                $candidate = clone $today;
                $candidate->modify('+14 days');
                break;
            case 'yearly':
                $candidate = new DateTime($ym . $dayStr);
                if ($candidate < $today) $candidate->modify('+1 year');
                break;
            default:
                $candidate = new DateTime($ym . $dayStr);
                if ($candidate < $today) $candidate->modify('+1 month');
        }

        if ($candidate < $start) return null;
        if ($endDt && $candidate > $endDt) return null;
        return $candidate->format('Y-m-d');

    } catch (\Throwable $e) {
        return null; // Invalid date silently skip
    }
}

function _to_monthly(float $amount, string $freq): float {
    return match($freq) {
        'daily'       => $amount * 30,
        'weekly'      => $amount * 4.33,
        'fortnightly' => $amount * 2.17,
        'monthly'     => $amount,
        'quarterly'   => $amount / 3,
        'yearly'      => $amount / 12,
        default        => $amount,
    };
}

function _calc_xirr(array $flows): ?float {
    if (count($flows) < 2) return null;
    $t0   = strtotime($flows[0]['date']);
    $days = array_map(fn($f) => (strtotime($f['date']) - $t0) / 86400, $flows);
    $amts = array_column($flows, 'amount');
    $rate = 0.1;
    for ($i = 0; $i < 100; $i++) {
        $f = $df = 0.0;
        foreach ($amts as $k => $a) {
            $e = $days[$k] / 365;
            $f  += $a / pow(1 + $rate, $e);
            $df -= $e * $a / pow(1 + $rate, $e + 1);
        }
        if (abs($df) < 1e-10) break;
        $nr = $rate - $f / $df;
        if (abs($nr - $rate) < 1e-6) { $rate = $nr; break; }
        $rate = $nr < -0.999 ? -0.5 : $nr;
    }
    return abs($rate) > 10 ? null : round($rate * 100, 2);
}

// ── Goal Helpers ────────────────────────────────────────────

function _enrich_goal(array $goal): array {
    $effectiveSaved = max(
        (float) $goal['current_saved'],
        (float) $goal['contributions_sum']
    );
    $goal['effective_saved'] = $effectiveSaved;

    $target    = (float) $goal['target_amount'];
    $pct       = $target > 0 ? min(round(($effectiveSaved / $target) * 100, 1), 100) : 0;
    $remaining = max($target - $effectiveSaved, 0);

    $monthsLeft = _months_to_date($goal['target_date']);
    $projection = _calculate_projection($target, (float)$goal['expected_return_pct'], $monthsLeft, $effectiveSaved);

    $goal['progress_pct']    = $pct;
    $goal['amount_remaining']= round($remaining, 2);
    $goal['months_left']     = $monthsLeft;
    $goal['monthly_sip_needed'] = $projection['sip_needed'];
    $goal['on_track']        = $projection['on_track'];
    $goal['projected_value'] = $projection['projected_value'];
    return $goal;
}

function _months_to_date(string $targetDate): int {
    $today  = new DateTime();
    $target = new DateTime($targetDate);
    if ($target <= $today) return 0;
    $diff = $today->diff($target);
    return $diff->y * 12 + $diff->m;
}

/**
 * Calculate SIP needed to reach targetAmount in N months
 * given current savings and expected annual return.
 */
function _sip_needed(float $target, float $annualReturnPct, int $months, float $currentSaved): float {
    if ($months <= 0) return 0.0;
    $r = ($annualReturnPct / 100) / 12; // monthly rate

    // Future value of current savings
    $fvCurrent = $currentSaved * pow(1 + $r, $months);
    $remaining = $target - $fvCurrent;
    if ($remaining <= 0) return 0.0;

    if ($r == 0) return round($remaining / $months, 2);

    // SIP formula: SIP = FV * r / ((1+r)^n - 1)
    $sip = $remaining * $r / (pow(1 + $r, $months) - 1);
    return round($sip, 2);
}

function _calculate_projection(float $target, float $annualReturnPct, int $months, float $currentSaved): array {
    if ($months <= 0) {
        return [
            'sip_needed'       => 0,
            'projected_value'  => round($currentSaved, 2),
            'on_track'         => $currentSaved >= $target,
            'months'           => 0,
        ];
    }

    $r   = ($annualReturnPct / 100) / 12;
    $sipNeeded = _sip_needed($target, $annualReturnPct, $months, $currentSaved);

    // Project monthly chart data (12 points)
    $chartPoints = [];
    $step = max(1, (int) ceil($months / 12));
    $running = $currentSaved;
    for ($m = 0; $m <= $months; $m += $step) {
        $fv = $currentSaved * pow(1 + $r, $m);
        if ($sipNeeded > 0 && $r > 0) {
            $fv += $sipNeeded * (pow(1 + $r, $m) - 1) / $r;
        } elseif ($sipNeeded > 0) {
            $fv += $sipNeeded * $m;
        }
        $chartPoints[] = [
            'month'     => $m,
            'projected' => round($fv, 2),
            'target'    => round($target, 2),
        ];
    }

    // Final projected value with suggested SIP
    $projectedFinal = $currentSaved * pow(1 + $r, $months);
    if ($sipNeeded > 0 && $r > 0) {
        $projectedFinal += $sipNeeded * (pow(1 + $r, $months) - 1) / $r;
    } elseif ($sipNeeded > 0) {
        $projectedFinal += $sipNeeded * $months;
    }

    return [
        'sip_needed'      => $sipNeeded,
        'projected_value' => round($projectedFinal, 2),
        'on_track'        => $projectedFinal >= $target,
        'months'          => $months,
        'chart'           => $chartPoints,
        'return_pct'      => $annualReturnPct,
    ];
}