<?php
/**
 * WealthDash — SGB (Sovereign Gold Bonds) API
 * Task   : t113
 * Routes : sgb_list, sgb_add, sgb_update, sgb_delete, sgb_summary,
 *          sgb_live_price, sgb_refresh_nav, sgb_interest_add,
 *          sgb_interest_list, sgb_series_list
 */
defined('WEALTHDASH') or die();

$action   = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId   = (int) $_SESSION['user_id'];
$isAdmin  = is_admin();

// Helper: resolve portfolio
function sgb_portfolio(int $userId, bool $isAdmin): ?int
{
    $pid = (int) ($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, $isAdmin)) {
        return $pid;
    }
    return get_user_portfolio_id($userId) ?: null;
}

// Helper: latest gold price per gram from cache or external
function sgb_gold_price(): ?float
{
    // Try cache (max 4 h old)
    $cached = DB::fetchOne(
        "SELECT price_24k_gram FROM gold_price_cache
          WHERE date_for = CURDATE() AND source = 'ibja'
          ORDER BY id DESC LIMIT 1"
    );
    if ($cached) {
        return (float) $cached['price_24k_gram'];
    }

    // Attempt live fetch from public IBJA endpoint (best-effort)
    $url = 'https://ibja.co/api/gold-rate';
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw) {
        $json = json_decode($raw, true);
        $price = (float) ($json['rate24k'] ?? $json['price'] ?? 0);
        if ($price > 0) {
            DB::run(
                "INSERT INTO gold_price_cache (source, price_24k_gram, date_for) VALUES (?,?,CURDATE())",
                ['ibja', $price]
            );
            return $price;
        }
    }
    return null;
}

// Helper: recalculate & update current_value for an SGB row
function sgb_refresh_value(int $id): void
{
    $row = DB::fetchOne("SELECT units, current_nav FROM sgb_holdings WHERE id=?", [$id]);
    if ($row && $row['current_nav']) {
        $val = round((float)$row['units'] * (float)$row['current_nav'], 2);
        DB::run("UPDATE sgb_holdings SET current_value=?, nav_updated_at=NOW() WHERE id=?", [$val, $id]);
    }
}

// Helper: next interest date after a given date (semi-annual / annual)
function sgb_next_interest(string $issueDate, string $payout): ?string
{
    try {
        $issue = new DateTime($issueDate);
        $now   = new DateTime();
        $month = (int) $issue->format('m');
        $day   = (int) $issue->format('d');
        $step  = ($payout === 'annual') ? 12 : 6;

        // Walk forward until we find the next upcoming date
        $candidate = clone $issue;
        for ($i = 0; $i < 200; $i++) {
            $candidate->modify("+{$step} months");
            if ($candidate > $now) {
                return $candidate->format('Y-m-d');
            }
        }
    } catch (Exception $e) {}
    return null;
}

switch ($action) {

    // ─── LIST ────────────────────────────────────────────────────────────────
    case 'sgb_list':
        $pid = sgb_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rows = DB::fetchAll(
            "SELECT s.*,
                    DATEDIFF(s.maturity_date, CURDATE()) AS days_to_maturity,
                    CASE
                        WHEN CURDATE() >= s.maturity_date THEN 'matured'
                        WHEN DATEDIFF(s.maturity_date, CURDATE()) <= 365 THEN 'near_maturity'
                        ELSE 'active'
                    END AS maturity_status
             FROM sgb_holdings s
             WHERE s.portfolio_id = ? AND s.is_active = 1
             ORDER BY s.maturity_date ASC",
            [$pid]
        );

        // Enrich: unrealised gain, annualised return
        foreach ($rows as &$r) {
            $invested = (float) $r['total_invested'];
            $current  = (float) ($r['current_value'] ?? 0);
            $r['unrealised_gain']     = $current > 0 ? round($current - $invested, 2) : null;
            $r['unrealised_gain_pct'] = ($current > 0 && $invested > 0)
                ? round(($current - $invested) / $invested * 100, 2) : null;

            // Years held
            try {
                $days = (int) (new DateTime())->diff(new DateTime($r['issue_date']))->days;
            } catch (Exception $e) { $days = 0; }
            $years = $days / 365;

            // CAGR
            if ($current > 0 && $invested > 0 && $years > 0.1) {
                $r['cagr_pct'] = round((pow($current / $invested, 1 / $years) - 1) * 100, 2);
            } else {
                $r['cagr_pct'] = null;
            }

            // Annual interest amount
            $r['annual_interest'] = round((float)$r['units'] * (float)$r['issue_price'] * (float)$r['coupon_rate'] / 100, 2);
        }
        unset($r);

        json_response(true, '', ['data' => $rows]);


    // ─── ADD ─────────────────────────────────────────────────────────────────
    case 'sgb_add':
        $pid = sgb_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $series    = clean($_POST['series_name']    ?? '');
        $tranche   = clean($_POST['tranche_code']   ?? '');
        $isin      = clean($_POST['isin']           ?? '');
        $nse_sym   = clean($_POST['nse_symbol']     ?? '');
        $issue_dt  = clean($_POST['issue_date']     ?? '');
        $mat_dt    = clean($_POST['maturity_date']  ?? '');
        $units     = (float) ($_POST['units']       ?? 0);
        $price     = (float) ($_POST['issue_price'] ?? 0);
        $coupon    = (float) ($_POST['coupon_rate'] ?? 2.50);
        $payout    = in_array($_POST['interest_payout'] ?? '', ['semi-annual','annual'])
                     ? $_POST['interest_payout'] : 'semi-annual';
        $notes     = clean($_POST['notes']          ?? '');

        if (!$series || !$issue_dt || !$mat_dt || $units <= 0 || $price <= 0) {
            json_response(false, 'Series name, issue date, maturity date, units and issue price are required.');
        }

        $total     = round($units * $price, 2);
        $nextInt   = sgb_next_interest($issue_dt, $payout);

        DB::run(
            "INSERT INTO sgb_holdings
             (portfolio_id, series_name, tranche_code, isin, nse_symbol,
              issue_date, maturity_date, units, issue_price, total_invested,
              coupon_rate, interest_payout, next_interest_date, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$pid, $series, $tranche ?: null, $isin ?: null, $nse_sym ?: null,
             $issue_dt, $mat_dt, $units, $price, $total, $coupon, $payout, $nextInt, $notes ?: null]
        );
        $newId = (int) DB::conn()->lastInsertId();
        json_response(true, 'SGB holding added.', ['id' => $newId]);


    // ─── UPDATE ──────────────────────────────────────────────────────────────
    case 'sgb_update':
        $id  = (int) ($_POST['id'] ?? 0);
        $pid = sgb_portfolio($userId, $isAdmin);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }

        $existing = DB::fetchOne(
            "SELECT id FROM sgb_holdings WHERE id=? AND portfolio_id=?", [$id, $pid]
        );
        if (!$existing) { json_response(false, 'Not found.'); }

        $fields = ['series_name','tranche_code','isin','nse_symbol','issue_date',
                   'maturity_date','units','issue_price','coupon_rate','interest_payout',
                   'last_interest_date','notes'];
        $allowed = [];
        $params  = [];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $allowed[] = "`{$f}` = ?";
                $params[]  = clean($_POST[$f]);
            }
        }
        if (!$allowed) { json_response(false, 'Nothing to update.'); }

        // Recalculate total_invested if units or price changed
        if (isset($_POST['units']) || isset($_POST['issue_price'])) {
            $row   = DB::fetchOne("SELECT units, issue_price FROM sgb_holdings WHERE id=?", [$id]);
            $units = (float) ($_POST['units']       ?? $row['units']);
            $price = (float) ($_POST['issue_price'] ?? $row['issue_price']);
            $allowed[] = '`total_invested` = ?';
            $params[]  = round($units * $price, 2);
        }

        $params[] = $id;
        DB::run("UPDATE sgb_holdings SET " . implode(', ', $allowed) . " WHERE id=?", $params);
        sgb_refresh_value($id);
        json_response(true, 'SGB holding updated.');


    // ─── DELETE ──────────────────────────────────────────────────────────────
    case 'sgb_delete':
        $id  = (int) ($_POST['id'] ?? 0);
        $pid = sgb_portfolio($userId, $isAdmin);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }

        $existing = DB::fetchOne(
            "SELECT id FROM sgb_holdings WHERE id=? AND portfolio_id=?", [$id, $pid]
        );
        if (!$existing) { json_response(false, 'Not found.'); }

        // Soft-delete
        DB::run("UPDATE sgb_holdings SET is_active=0 WHERE id=?", [$id]);
        json_response(true, 'SGB holding removed.');


    // ─── SUMMARY ─────────────────────────────────────────────────────────────
    case 'sgb_summary':
        $pid = sgb_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $row = DB::fetchOne(
            "SELECT COUNT(*) AS total_bonds,
                    SUM(units) AS total_units,
                    SUM(total_invested) AS total_invested,
                    SUM(COALESCE(current_value, 0)) AS current_value,
                    SUM(total_interest_received) AS total_interest_received,
                    MIN(maturity_date) AS earliest_maturity,
                    MAX(maturity_date) AS latest_maturity
             FROM sgb_holdings WHERE portfolio_id=? AND is_active=1",
            [$pid]
        );

        $invested = (float) ($row['total_invested'] ?? 0);
        $current  = (float) ($row['current_value']  ?? 0);
        $gain     = $current > 0 ? round($current - $invested, 2) : 0;
        $gainPct  = ($invested > 0 && $current > 0) ? round($gain / $invested * 100, 2) : 0;

        // Maturing within 12 months
        $maturing = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM sgb_holdings
              WHERE portfolio_id=? AND is_active=1
                AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)",
            [$pid]
        );

        json_response(true, '', [
            'data' => array_merge($row, [
                'unrealised_gain'     => $gain,
                'unrealised_gain_pct' => $gainPct,
                'maturing_in_12m'     => $maturing,
                'gold_price_gram'     => sgb_gold_price(),
            ])
        ]);


    // ─── LIVE GOLD PRICE ─────────────────────────────────────────────────────
    case 'sgb_live_price':
        $price = sgb_gold_price();
        if ($price === null) {
            json_response(false, 'Could not fetch live gold price.');
        }
        json_response(true, '', ['price_per_gram' => $price, 'as_on' => date('Y-m-d')]);


    // ─── REFRESH NAV (batch update all active SGBs) ───────────────────────────
    case 'sgb_refresh_nav':
        $price = sgb_gold_price();
        if ($price === null) { json_response(false, 'Gold price unavailable.'); }

        $pid  = sgb_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $ids = DB::fetchAll(
            "SELECT id, units FROM sgb_holdings WHERE portfolio_id=? AND is_active=1", [$pid]
        );
        $updated = 0;
        foreach ($ids as $row) {
            $val = round((float)$row['units'] * $price, 2);
            DB::run(
                "UPDATE sgb_holdings SET current_nav=?, current_value=?, nav_updated_at=NOW() WHERE id=?",
                [$price, $val, $row['id']]
            );
            $updated++;
        }
        json_response(true, "NAV refreshed for {$updated} holding(s).", ['updated' => $updated, 'gold_price' => $price]);


    // ─── INTEREST ADD ────────────────────────────────────────────────────────
    case 'sgb_interest_add':
        $sgb_id  = (int) ($_POST['sgb_id']      ?? 0);
        $pid     = sgb_portfolio($userId, $isAdmin);
        if (!$sgb_id || !$pid) { json_response(false, 'Invalid request.'); }

        $sgb = DB::fetchOne(
            "SELECT id, portfolio_id, units, coupon_rate, issue_price, interest_payout
             FROM sgb_holdings WHERE id=? AND portfolio_id=? AND is_active=1",
            [$sgb_id, $pid]
        );
        if (!$sgb) { json_response(false, 'SGB not found.'); }

        $payout_dt = clean($_POST['payout_date'] ?? date('Y-m-d'));
        $period    = clean($_POST['period']       ?? '');
        $rate      = (float) ($_POST['rate_pct'] ?? $sgb['coupon_rate']);
        $notes     = clean($_POST['notes']        ?? '');

        // Auto-calc amount: units * issue_price * (rate/100) / payouts_per_year
        $perYear   = ($sgb['interest_payout'] === 'annual') ? 1 : 2;
        $amount    = (float) ($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            $amount = round((float)$sgb['units'] * (float)$sgb['issue_price'] * ($rate / 100) / $perYear, 2);
        }

        DB::run(
            "INSERT INTO sgb_interest_log
             (sgb_id, portfolio_id, payout_date, period, units, rate_pct, amount, notes)
             VALUES (?,?,?,?,?,?,?,?)",
            [$sgb_id, $pid, $payout_dt, $period ?: null,
             $sgb['units'], $rate, $amount, $notes ?: null]
        );

        // Update cumulative interest & last/next dates on parent
        $nextInt = sgb_next_interest($payout_dt, $sgb['interest_payout']);
        DB::run(
            "UPDATE sgb_holdings
             SET total_interest_received = total_interest_received + ?,
                 last_interest_date = ?,
                 next_interest_date = ?
             WHERE id=?",
            [$amount, $payout_dt, $nextInt, $sgb_id]
        );
        json_response(true, 'Interest payout recorded.', ['amount' => $amount]);


    // ─── INTEREST LIST ───────────────────────────────────────────────────────
    case 'sgb_interest_list':
        $sgb_id = (int) ($_GET['sgb_id'] ?? $_POST['sgb_id'] ?? 0);
        $pid    = sgb_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $where = $sgb_id ? "sgb_id = {$sgb_id} AND portfolio_id = {$pid}"
                         : "portfolio_id = {$pid}";

        $rows = DB::fetchAll(
            "SELECT l.*, s.series_name
             FROM sgb_interest_log l
             JOIN sgb_holdings s ON s.id = l.sgb_id
             WHERE {$where}
             ORDER BY l.payout_date DESC"
        );
        json_response(true, '', ['data' => $rows]);


    // ─── SERIES LIST (RBI master list of SGB tranches) ────────────────────────
    case 'sgb_series_list':
        // Static curated list — extend as new tranches are issued
        $series = [
            ['series' => 'SGB 2015-16 Series I',  'issue_date' => '2015-11-30', 'maturity_date' => '2023-11-30', 'issue_price' => 2684.00, 'tranche' => 'SGB-I'],
            ['series' => 'SGB 2016-17 Series I',  'issue_date' => '2016-08-05', 'maturity_date' => '2024-08-05', 'issue_price' => 3119.00, 'tranche' => 'SGB16-I'],
            ['series' => 'SGB 2017-18 Series I',  'issue_date' => '2017-05-12', 'maturity_date' => '2025-05-12', 'issue_price' => 2951.00, 'tranche' => 'SGB17-I'],
            ['series' => 'SGB 2017-18 Series II', 'issue_date' => '2017-07-28', 'maturity_date' => '2025-07-28', 'issue_price' => 2830.00, 'tranche' => 'SGB17-II'],
            ['series' => 'SGB 2018-19 Series I',  'issue_date' => '2018-04-23', 'maturity_date' => '2026-04-23', 'issue_price' => 3114.00, 'tranche' => 'SGB18-I'],
            ['series' => 'SGB 2018-19 Series II', 'issue_date' => '2018-05-22', 'maturity_date' => '2026-05-22', 'issue_price' => 3119.00, 'tranche' => 'SGB18-II'],
            ['series' => 'SGB 2018-19 Series III','issue_date' => '2018-06-25', 'maturity_date' => '2026-06-25', 'issue_price' => 3130.00, 'tranche' => 'SGB18-III'],
            ['series' => 'SGB 2018-19 Series IV', 'issue_date' => '2018-07-23', 'maturity_date' => '2026-07-23', 'issue_price' => 2916.00, 'tranche' => 'SGB18-IV'],
            ['series' => 'SGB 2019-20 Series I',  'issue_date' => '2019-06-11', 'maturity_date' => '2027-06-11', 'issue_price' => 3196.00, 'tranche' => 'SGB19-I'],
            ['series' => 'SGB 2019-20 Series II', 'issue_date' => '2019-07-16', 'maturity_date' => '2027-07-16', 'issue_price' => 3443.00, 'tranche' => 'SGB19-II'],
            ['series' => 'SGB 2019-20 Series III','issue_date' => '2019-08-14', 'maturity_date' => '2027-08-14', 'issue_price' => 3499.00, 'tranche' => 'SGB19-III'],
            ['series' => 'SGB 2019-20 Series IV', 'issue_date' => '2019-09-10', 'maturity_date' => '2027-09-10', 'issue_price' => 3890.00, 'tranche' => 'SGB19-IV'],
            ['series' => 'SGB 2019-20 Series V',  'issue_date' => '2019-10-15', 'maturity_date' => '2027-10-15', 'issue_price' => 3788.00, 'tranche' => 'SGB19-V'],
            ['series' => 'SGB 2019-20 Series VI',  'issue_date' => '2019-11-19','maturity_date' => '2027-11-19', 'issue_price' => 3835.00, 'tranche' => 'SGB19-VI'],
            ['series' => 'SGB 2019-20 Series VII', 'issue_date' => '2019-12-17','maturity_date' => '2027-12-17', 'issue_price' => 3795.00, 'tranche' => 'SGB19-VII'],
            ['series' => 'SGB 2019-20 Series VIII','issue_date' => '2020-01-28','maturity_date' => '2028-01-28', 'issue_price' => 4016.00, 'tranche' => 'SGB19-VIII'],
            ['series' => 'SGB 2019-20 Series IX',  'issue_date' => '2020-02-25','maturity_date' => '2028-02-25', 'issue_price' => 4260.00, 'tranche' => 'SGB19-IX'],
            ['series' => 'SGB 2019-20 Series X',   'issue_date' => '2020-03-11','maturity_date' => '2028-03-11', 'issue_price' => 4260.00, 'tranche' => 'SGB19-X'],
            ['series' => 'SGB 2020-21 Series I',   'issue_date' => '2020-04-28','maturity_date' => '2028-04-28', 'issue_price' => 4590.00, 'tranche' => 'SGB20-I'],
            ['series' => 'SGB 2020-21 Series II',  'issue_date' => '2020-05-19','maturity_date' => '2028-05-19', 'issue_price' => 4677.00, 'tranche' => 'SGB20-II'],
            ['series' => 'SGB 2020-21 Series III', 'issue_date' => '2020-07-14','maturity_date' => '2028-07-14', 'issue_price' => 4852.00, 'tranche' => 'SGB20-III'],
            ['series' => 'SGB 2020-21 Series IV',  'issue_date' => '2020-09-08','maturity_date' => '2028-09-08', 'issue_price' => 5117.00, 'tranche' => 'SGB20-IV'],
            ['series' => 'SGB 2020-21 Series V',   'issue_date' => '2020-10-09','maturity_date' => '2028-10-09', 'issue_price' => 5051.00, 'tranche' => 'SGB20-V'],
            ['series' => 'SGB 2020-21 Series VI',  'issue_date' => '2020-11-17','maturity_date' => '2028-11-17', 'issue_price' => 5177.00, 'tranche' => 'SGB20-VI'],
            ['series' => 'SGB 2020-21 Series VII', 'issue_date' => '2021-01-12','maturity_date' => '2029-01-12', 'issue_price' => 5104.00, 'tranche' => 'SGB20-VII'],
            ['series' => 'SGB 2020-21 Series VIII','issue_date' => '2021-02-16','maturity_date' => '2029-02-16', 'issue_price' => 4912.00, 'tranche' => 'SGB20-VIII'],
            ['series' => 'SGB 2020-21 Series IX',  'issue_date' => '2021-03-09','maturity_date' => '2029-03-09', 'issue_price' => 4662.00, 'tranche' => 'SGB20-IX'],
            ['series' => 'SGB 2020-21 Series X',   'issue_date' => '2021-03-30','maturity_date' => '2029-03-30', 'issue_price' => 4727.00, 'tranche' => 'SGB20-X'],
            ['series' => 'SGB 2021-22 Series I',   'issue_date' => '2021-05-28','maturity_date' => '2029-05-28', 'issue_price' => 4777.00, 'tranche' => 'SGB21-I'],
            ['series' => 'SGB 2021-22 Series II',  'issue_date' => '2021-07-19','maturity_date' => '2029-07-19', 'issue_price' => 4807.00, 'tranche' => 'SGB21-II'],
            ['series' => 'SGB 2021-22 Series III', 'issue_date' => '2021-08-17','maturity_date' => '2029-08-17', 'issue_price' => 4790.00, 'tranche' => 'SGB21-III'],
            ['series' => 'SGB 2021-22 Series IV',  'issue_date' => '2021-09-24','maturity_date' => '2029-09-24', 'issue_price' => 4761.00, 'tranche' => 'SGB21-IV'],
            ['series' => 'SGB 2021-22 Series V',   'issue_date' => '2021-11-29','maturity_date' => '2029-11-29', 'issue_price' => 4791.00, 'tranche' => 'SGB21-V'],
            ['series' => 'SGB 2021-22 Series VI',  'issue_date' => '2022-01-10','maturity_date' => '2030-01-10', 'issue_price' => 4786.00, 'tranche' => 'SGB21-VI'],
            ['series' => 'SGB 2021-22 Series VII', 'issue_date' => '2022-02-22','maturity_date' => '2030-02-22', 'issue_price' => 5109.00, 'tranche' => 'SGB21-VII'],
            ['series' => 'SGB 2021-22 Series VIII','issue_date' => '2022-03-22','maturity_date' => '2030-03-22', 'issue_price' => 5359.00, 'tranche' => 'SGB21-VIII'],
            ['series' => 'SGB 2022-23 Series I',   'issue_date' => '2022-06-28','maturity_date' => '2030-06-28', 'issue_price' => 5091.00, 'tranche' => 'SGB22-I'],
            ['series' => 'SGB 2022-23 Series II',  'issue_date' => '2022-08-22','maturity_date' => '2030-08-22', 'issue_price' => 5197.00, 'tranche' => 'SGB22-II'],
            ['series' => 'SGB 2022-23 Series III', 'issue_date' => '2022-12-27','maturity_date' => '2030-12-27', 'issue_price' => 5409.00, 'tranche' => 'SGB22-III'],
            ['series' => 'SGB 2022-23 Series IV',  'issue_date' => '2023-03-06','maturity_date' => '2031-03-06', 'issue_price' => 5611.00, 'tranche' => 'SGB22-IV'],
            ['series' => 'SGB 2023-24 Series I',   'issue_date' => '2023-06-19','maturity_date' => '2031-06-19', 'issue_price' => 5926.00, 'tranche' => 'SGB23-I'],
            ['series' => 'SGB 2023-24 Series II',  'issue_date' => '2023-09-11','maturity_date' => '2031-09-11', 'issue_price' => 5923.00, 'tranche' => 'SGB23-II'],
            ['series' => 'SGB 2023-24 Series III', 'issue_date' => '2023-12-18','maturity_date' => '2031-12-18', 'issue_price' => 6199.00, 'tranche' => 'SGB23-III'],
            ['series' => 'SGB 2023-24 Series IV',  'issue_date' => '2024-02-12','maturity_date' => '2032-02-12', 'issue_price' => 6263.00, 'tranche' => 'SGB23-IV'],
        ];
        json_response(true, '', ['data' => $series, 'count' => count($series)]);


    default:
        json_response(false, "Unknown SGB action: {$action}", [], 400);
}
