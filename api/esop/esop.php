<?php
/**
 * WealthDash — ESOP / RSU Grant Tracking + Vesting API
 * Task   : t117
 * Routes : esop_list, esop_add, esop_update, esop_delete, esop_summary,
 *          esop_vesting_list, esop_vesting_add, esop_vesting_update,
 *          esop_exercise_log, esop_exercise_add, esop_fmv_update,
 *          esop_schedule_generate
 */
defined('WEALTHDASH') or die();

$action  = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId  = (int) $_SESSION['user_id'];
$isAdmin = is_admin();

// ── Helpers ──────────────────────────────────────────────────────────────────

function esop_portfolio(int $userId, bool $isAdmin): ?int
{
    $pid = (int) ($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, $isAdmin)) return $pid;
    return get_user_portfolio_id($userId) ?: null;
}

function esop_grant_access(int $grantId, int $pid): ?array
{
    return DB::fetchOne(
        "SELECT * FROM esop_grants WHERE id=? AND portfolio_id=?",
        [$grantId, $pid]
    ) ?: null;
}

/**
 * Auto-generate vesting event rows for a grant based on schedule type.
 * Existing events are deleted and regenerated (idempotent).
 */
function esop_generate_schedule(array $grant): int
{
    $id           = (int)  $grant['id'];
    $total        = (int)  $grant['total_options'];
    $startDate    = new DateTime($grant['vesting_start']);
    $cliffMonths  = (int)  $grant['vesting_cliff_months'];
    $periodMonths = (int)  $grant['vesting_period_months'];
    $type         = $grant['vesting_type'];

    // Delete auto-generated (non-exercised) events
    DB::run(
        "DELETE FROM esop_vesting_events WHERE grant_id=? AND is_exercised=0",
        [$id]
    );

    $inserted = 0;

    if ($type === 'cliff') {
        // 100% vest at cliff
        $vestDate = (clone $startDate)->modify("+{$cliffMonths} months");
        DB::run(
            "INSERT INTO esop_vesting_events (grant_id, vest_date, units_vested) VALUES (?,?,?)",
            [$id, $vestDate->format('Y-m-d'), $total]
        );
        $inserted = 1;

    } elseif ($type === 'graded') {
        // Equal tranches every frequency (determine frequency from schedule string)
        // Default: 4 tranches (1/4 per year after cliff, then quarterly)
        // We'll derive frequency: cliff = first tranche date; remainder monthly/quarterly/annual
        $freq = 12; // default annual after cliff
        if (stripos($grant['vesting_schedule'], 'quarter') !== false) $freq = 3;
        if (stripos($grant['vesting_schedule'], 'month')   !== false) $freq = 1;
        if (stripos($grant['vesting_schedule'], 'year')    !== false) $freq = 12;

        $numTranches  = (int) ceil(($periodMonths - $cliffMonths) / $freq) + 1; // +1 for cliff
        $unitsPerTranche = (int) floor($total / $numTranches);
        $remainder       = $total - ($unitsPerTranche * $numTranches);

        // Cliff tranche
        $vestDate = (clone $startDate)->modify("+{$cliffMonths} months");
        $units    = $unitsPerTranche + $remainder; // remainder on first tranche
        DB::run(
            "INSERT INTO esop_vesting_events (grant_id, vest_date, units_vested) VALUES (?,?,?)",
            [$id, $vestDate->format('Y-m-d'), $units]
        );
        $inserted++;

        // Subsequent tranches
        $current = clone $vestDate;
        $vested  = $units;
        while ($vested < $total) {
            $current->modify("+{$freq} months");
            $remaining = $total - $vested;
            $tranche   = min($unitsPerTranche, $remaining);
            if ($tranche <= 0) break;

            // Don't exceed period
            $monthsFromStart = (int) $startDate->diff($current)->m
                             + ((int) $startDate->diff($current)->y * 12);
            if ($monthsFromStart > $periodMonths) break;

            DB::run(
                "INSERT INTO esop_vesting_events (grant_id, vest_date, units_vested) VALUES (?,?,?)",
                [$id, $current->format('Y-m-d'), $tranche]
            );
            $vested  += $tranche;
            $inserted++;
        }
    }
    // 'custom' — no auto-generation; events added manually

    return $inserted;
}

/** Recalculate and sync options_vested on esop_grants from vesting events. */
function esop_sync_grant_counts(int $grantId): void
{
    $row = DB::fetchOne(
        "SELECT
            COALESCE(SUM(units_vested), 0)  AS vested,
            COALESCE(SUM(CASE WHEN is_exercised=1 THEN units_exercised ELSE 0 END), 0) AS exercised
         FROM esop_vesting_events
         WHERE grant_id=? AND vest_date <= CURDATE()",
        [$grantId]
    );
    $grant = DB::fetchOne("SELECT total_options, options_lapsed FROM esop_grants WHERE id=?", [$grantId]);
    if (!$row || !$grant) return;

    $vested    = (int) $row['vested'];
    $exercised = (int) $row['exercised'];
    $total     = (int) $grant['total_options'];
    $lapsed    = (int) $grant['options_lapsed'];

    // Derive status
    $status = 'active';
    if ($vested >= $total)                  $status = 'fully_vested';
    if ($exercised >= $total)               $status = 'exercised_full';
    if ($exercised > 0 && $exercised < $total) $status = 'exercised_partial';

    DB::run(
        "UPDATE esop_grants SET options_vested=?, options_exercised=?, status=?, updated_at=NOW() WHERE id=?",
        [$vested, $exercised, $status, $grantId]
    );
}

// ── Route switch ─────────────────────────────────────────────────────────────

switch ($action) {

    // ─── LIST ALL GRANTS ──────────────────────────────────────────────────────
    case 'esop_list':
        $pid = esop_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rows = DB::fetchAll(
            "SELECT g.*,
                /* Options still unvested */
                (g.total_options - g.options_vested - g.options_lapsed) AS options_unvested,
                /* Intrinsic value of vested unexercised options */
                CASE
                    WHEN g.current_fmv IS NOT NULL AND g.current_fmv > g.exercise_price
                    THEN (g.current_fmv - g.exercise_price) * (g.options_vested - g.options_exercised)
                    ELSE 0
                END AS intrinsic_value,
                /* Next vest date */
                (SELECT MIN(ve.vest_date) FROM esop_vesting_events ve
                  WHERE ve.grant_id = g.id AND ve.vest_date > CURDATE()) AS next_vest_date,
                /* Next vest units */
                (SELECT ve.units_vested FROM esop_vesting_events ve
                  WHERE ve.grant_id = g.id AND ve.vest_date > CURDATE()
                  ORDER BY ve.vest_date ASC LIMIT 1) AS next_vest_units,
                /* Vesting % */
                CASE WHEN g.total_options > 0
                     THEN ROUND(g.options_vested / g.total_options * 100, 1)
                     ELSE 0 END AS vesting_pct
             FROM esop_grants g
             WHERE g.portfolio_id = ? AND g.status != 'cancelled'
             ORDER BY g.grant_date DESC",
            [$pid]
        );

        foreach ($rows as &$r) {
            // Vesting progress bar data
            $total     = (int) $r['total_options'];
            $vested    = (int) $r['options_vested'];
            $exercised = (int) $r['options_exercised'];
            $lapsed    = (int) $r['options_lapsed'];
            $unvested  = max(0, $total - $vested - $lapsed);
            $unexercised = max(0, $vested - $exercised);

            $r['breakdown'] = [
                'total'      => $total,
                'vested'     => $vested,
                'exercised'  => $exercised,
                'lapsed'     => $lapsed,
                'unvested'   => $unvested,
                'unexercised'=> $unexercised,
            ];

            // Days to full vest
            try {
                $fullVest = (new DateTime($r['vesting_start']))
                    ->modify('+' . $r['vesting_period_months'] . ' months');
                $r['days_to_full_vest'] = (int) (new DateTime())->diff($fullVest)->days;
                $r['fully_vested_date'] = $fullVest->format('Y-m-d');
            } catch (Exception $e) {
                $r['days_to_full_vest'] = null;
                $r['fully_vested_date'] = null;
            }
        }
        unset($r);
        json_response(true, '', ['data' => $rows]);


    // ─── ADD GRANT ────────────────────────────────────────────────────────────
    case 'esop_add':
        $pid = esop_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $company        = clean($_POST['company_name']       ?? '');
        $symbol         = clean($_POST['company_symbol']     ?? '');
        $grantType      = in_array($_POST['grant_type'] ?? 'ESOP', ['ESOP','RSU','SAR','PHANTOM'])
                          ? $_POST['grant_type'] : 'ESOP';
        $grantDate      = clean($_POST['grant_date']         ?? '');
        $grantRef       = clean($_POST['grant_ref']          ?? '');
        $totalOpts      = (int)   ($_POST['total_options']       ?? 0);
        $exPrice        = (float) ($_POST['exercise_price']      ?? 0);
        $currency       = clean($_POST['currency']           ?? 'INR');
        $vestStart      = clean($_POST['vesting_start']      ?? $grantDate);
        $cliffMonths    = (int)   ($_POST['vesting_cliff_months']   ?? 12);
        $periodMonths   = (int)   ($_POST['vesting_period_months']  ?? 48);
        $schedule       = clean($_POST['vesting_schedule']   ?? '1/4 per year');
        $vestType       = in_array($_POST['vesting_type'] ?? 'graded', ['cliff','graded','custom'])
                          ? $_POST['vesting_type'] : 'graded';
        $currentFmv     = isset($_POST['current_fmv']) && (float)$_POST['current_fmv'] > 0
                          ? (float)$_POST['current_fmv'] : null;
        $expiryDate     = clean($_POST['expiry_date'] ?? '');
        $notes          = clean($_POST['notes']       ?? '');

        if (!$company || !$grantDate || $totalOpts <= 0) {
            json_response(false, 'Company name, grant date, and total options are required.');
        }

        DB::run(
            "INSERT INTO esop_grants
             (portfolio_id, company_name, company_symbol, grant_type,
              grant_date, grant_ref, total_options, exercise_price, currency,
              vesting_start, vesting_cliff_months, vesting_period_months,
              vesting_schedule, vesting_type, current_fmv, fmv_updated_at,
              expiry_date, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$pid, $company, $symbol ?: null, $grantType,
             $grantDate, $grantRef ?: null, $totalOpts, $exPrice, $currency,
             $vestStart, $cliffMonths, $periodMonths,
             $schedule, $vestType,
             $currentFmv, $currentFmv ? date('Y-m-d H:i:s') : null,
             $expiryDate ?: null, $notes ?: null]
        );
        $grantId = (int) DB::conn()->lastInsertId();

        // Auto-generate vesting schedule
        $grant    = DB::fetchOne("SELECT * FROM esop_grants WHERE id=?", [$grantId]);
        $eventsN  = 0;
        if ($vestType !== 'custom') {
            $eventsN = esop_generate_schedule($grant);
        }

        json_response(true, 'Grant added.', [
            'id'            => $grantId,
            'events_created'=> $eventsN,
        ]);


    // ─── UPDATE GRANT ─────────────────────────────────────────────────────────
    case 'esop_update':
        $grantId = (int) ($_POST['id'] ?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$grantId || !$pid) { json_response(false, 'Invalid request.'); }
        if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }

        $allowed_fields = ['company_name','company_symbol','grant_type','grant_ref',
                           'total_options','exercise_price','currency','vesting_start',
                           'vesting_cliff_months','vesting_period_months','vesting_schedule',
                           'vesting_type','expiry_date','notes','status'];
        $sets   = [];
        $params = [];
        foreach ($allowed_fields as $f) {
            if (array_key_exists($f, $_POST)) {
                $sets[]   = "`{$f}` = ?";
                $params[] = clean($_POST[$f]);
            }
        }
        if (isset($_POST['current_fmv'])) {
            $sets[]   = '`current_fmv` = ?';
            $params[] = (float) $_POST['current_fmv'];
            $sets[]   = '`fmv_updated_at` = NOW()';
        }
        if (!$sets) { json_response(false, 'Nothing to update.'); }

        $params[] = $grantId;
        DB::run("UPDATE esop_grants SET " . implode(', ', $sets) . " WHERE id=?", $params);

        // If vesting parameters changed, regenerate schedule
        $regenFields = ['vesting_start','vesting_cliff_months','vesting_period_months',
                        'vesting_type','vesting_schedule','total_options'];
        if (array_intersect($regenFields, array_keys($_POST))) {
            $grant = DB::fetchOne("SELECT * FROM esop_grants WHERE id=?", [$grantId]);
            if ($grant['vesting_type'] !== 'custom') {
                esop_generate_schedule($grant);
            }
            esop_sync_grant_counts($grantId);
        }

        json_response(true, 'Grant updated.');


    // ─── DELETE GRANT ─────────────────────────────────────────────────────────
    case 'esop_delete':
        $grantId = (int) ($_POST['id'] ?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$grantId || !$pid) { json_response(false, 'Invalid request.'); }
        if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }

        DB::run("UPDATE esop_grants SET status='cancelled' WHERE id=?", [$grantId]);
        json_response(true, 'Grant cancelled/deleted.');


    // ─── SUMMARY ──────────────────────────────────────────────────────────────
    case 'esop_summary':
        $pid = esop_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $row = DB::fetchOne(
            "SELECT
                COUNT(*)                                              AS total_grants,
                SUM(total_options)                                    AS total_options,
                SUM(options_vested)                                   AS total_vested,
                SUM(options_exercised)                                AS total_exercised,
                SUM(options_lapsed)                                   AS total_lapsed,
                SUM(CASE WHEN current_fmv > exercise_price
                         THEN (current_fmv - exercise_price) * (options_vested - options_exercised)
                         ELSE 0 END)                                  AS total_intrinsic_value,
                SUM(CASE WHEN current_fmv IS NOT NULL
                         THEN current_fmv * options_vested
                         ELSE 0 END)                                  AS total_fmv_vested
             FROM esop_grants
             WHERE portfolio_id=? AND status != 'cancelled'",
            [$pid]
        );

        // Upcoming vests in next 90 days
        $upcoming = DB::fetchAll(
            "SELECT ve.vest_date, ve.units_vested, g.company_name, g.grant_type,
                    g.exercise_price, g.current_fmv
             FROM esop_vesting_events ve
             JOIN esop_grants g ON g.id = ve.grant_id
             WHERE g.portfolio_id = ? AND g.status != 'cancelled'
               AND ve.vest_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
             ORDER BY ve.vest_date ASC
             LIMIT 10",
            [$pid]
        );

        // Tax exposure: vested but unexercised
        $taxRow = DB::fetchOne(
            "SELECT
                SUM((g.current_fmv - g.exercise_price) * (g.options_vested - g.options_exercised)) AS tax_base
             FROM esop_grants g
             WHERE g.portfolio_id=? AND g.status != 'cancelled'
               AND g.current_fmv > g.exercise_price",
            [$pid]
        );

        json_response(true, '', [
            'data' => array_merge($row, [
                'upcoming_vests'      => $upcoming,
                'perquisite_tax_base' => round((float)($taxRow['tax_base'] ?? 0), 2),
            ])
        ]);


    // ─── VESTING EVENTS LIST ─────────────────────────────────────────────────
    case 'esop_vesting_list':
        $grantId = (int) ($_GET['grant_id'] ?? $_POST['grant_id'] ?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        if ($grantId) {
            if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }
            $rows = DB::fetchAll(
                "SELECT ve.*,
                    CASE WHEN ve.vest_date <= CURDATE() THEN 1 ELSE 0 END AS is_due,
                    DATEDIFF(ve.vest_date, CURDATE()) AS days_until
                 FROM esop_vesting_events ve WHERE ve.grant_id=? ORDER BY ve.vest_date ASC",
                [$grantId]
            );
        } else {
            // All grants for portfolio
            $rows = DB::fetchAll(
                "SELECT ve.*,
                    g.company_name, g.grant_type, g.exercise_price, g.current_fmv,
                    CASE WHEN ve.vest_date <= CURDATE() THEN 1 ELSE 0 END AS is_due,
                    DATEDIFF(ve.vest_date, CURDATE()) AS days_until
                 FROM esop_vesting_events ve
                 JOIN esop_grants g ON g.id = ve.grant_id
                 WHERE g.portfolio_id=? AND g.status != 'cancelled'
                 ORDER BY ve.vest_date ASC",
                [$pid]
            );
        }

        json_response(true, '', ['data' => $rows]);


    // ─── ADD VESTING EVENT (custom / override) ────────────────────────────────
    case 'esop_vesting_add':
        $grantId = (int) ($_POST['grant_id']    ?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$grantId || !$pid) { json_response(false, 'Invalid request.'); }
        if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }

        $vestDate   = clean($_POST['vest_date']    ?? '');
        $units      = (int) ($_POST['units_vested'] ?? 0);
        $fmvOnVest  = isset($_POST['fmv_on_vest']) ? (float)$_POST['fmv_on_vest'] : null;
        $taxSlab    = isset($_POST['tax_slab_pct']) ? (float)$_POST['tax_slab_pct'] : null;
        $notes      = clean($_POST['notes'] ?? '');

        if (!$vestDate || $units <= 0) {
            json_response(false, 'vest_date and units_vested required.');
        }

        // Perquisite value
        $grant = DB::fetchOne("SELECT exercise_price FROM esop_grants WHERE id=?", [$grantId]);
        $perqValue = null;
        $perqTax   = null;
        if ($fmvOnVest && $fmvOnVest > (float)$grant['exercise_price']) {
            $perqValue = round(($fmvOnVest - (float)$grant['exercise_price']) * $units, 2);
            if ($taxSlab) {
                $perqTax = round($perqValue * $taxSlab / 100, 2);
            }
        }

        DB::run(
            "INSERT INTO esop_vesting_events
             (grant_id, vest_date, units_vested, fmv_on_vest, perquisite_value,
              perquisite_tax, tax_slab_pct, notes)
             VALUES (?,?,?,?,?,?,?,?)",
            [$grantId, $vestDate, $units, $fmvOnVest, $perqValue, $perqTax, $taxSlab, $notes ?: null]
        );
        $eventId = (int) DB::conn()->lastInsertId();
        esop_sync_grant_counts($grantId);
        json_response(true, 'Vesting event added.', ['id' => $eventId]);


    // ─── UPDATE VESTING EVENT (mark exercised / sold) ─────────────────────────
    case 'esop_vesting_update':
        $eventId = (int) ($_POST['id']       ?? 0);
        $grantId = (int) ($_POST['grant_id'] ?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$eventId || !$grantId || !$pid) { json_response(false, 'Invalid request.'); }
        if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }

        $fields = ['fmv_on_vest','perquisite_value','perquisite_tax','tax_slab_pct',
                   'is_exercised','exercise_date','exercise_price','units_exercised',
                   'sale_date','sale_price','units_sold','capital_gain','gain_type','notes'];
        $sets   = [];
        $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $_POST)) {
                $sets[]   = "`{$f}` = ?";
                $params[] = clean($_POST[$f]);
            }
        }
        if (!$sets) { json_response(false, 'Nothing to update.'); }

        $params[] = $eventId;
        DB::run("UPDATE esop_vesting_events SET " . implode(', ', $sets) . " WHERE id=?", $params);
        esop_sync_grant_counts($grantId);
        json_response(true, 'Vesting event updated.');


    // ─── EXERCISE LOG (add) ───────────────────────────────────────────────────
    case 'esop_exercise_add':
        $grantId   = (int)   ($_POST['grant_id']       ?? 0);
        $eventId   = (int)   ($_POST['vesting_event_id']?? 0);
        $pid       = esop_portfolio($userId, $isAdmin);
        if (!$grantId || !$pid) { json_response(false, 'Invalid request.'); }
        if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }

        $grant       = DB::fetchOne("SELECT * FROM esop_grants WHERE id=?", [$grantId]);
        $exDate      = clean($_POST['exercise_date']     ?? date('Y-m-d'));
        $units       = (int)   ($_POST['units']          ?? 0);
        $exPrice     = (float) ($_POST['exercise_price'] ?? (float)$grant['exercise_price']);
        $fmvOnEx     = isset($_POST['fmv_on_exercise']) ? (float)$_POST['fmv_on_exercise'] : null;
        $broker      = (float) ($_POST['broker_charges'] ?? 0);
        $tds         = isset($_POST['tds_deducted'])     ? (float)$_POST['tds_deducted'] : null;
        $saleDate    = clean($_POST['sale_date']   ?? '');
        $salePrice   = isset($_POST['sale_price']) ? (float)$_POST['sale_price'] : null;
        $notes       = clean($_POST['notes']       ?? '');

        if ($units <= 0) { json_response(false, 'Units must be > 0.'); }

        // Perquisite
        $perqValue = null;
        if ($fmvOnEx && $fmvOnEx > $exPrice) {
            $perqValue = round(($fmvOnEx - $exPrice) * $units, 2);
        }

        // Capital gain if sold
        $capitalGain = null;
        $gainType    = null;
        if ($saleDate && $salePrice && $fmvOnEx) {
            $capitalGain = round(($salePrice - $fmvOnEx) * $units - $broker, 2);
            try {
                $holdDays = (int) (new DateTime($saleDate))->diff(new DateTime($exDate))->days;
            } catch (Exception $e) { $holdDays = 0; }
            $gainType = $holdDays >= 365 ? 'LTCG' : 'STCG';
        }

        DB::run(
            "INSERT INTO esop_exercise_log
             (grant_id, vesting_event_id, exercise_date, units, exercise_price,
              fmv_on_exercise, perquisite_value, broker_charges, tds_deducted,
              sale_date, sale_price, capital_gain, gain_type, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$grantId, $eventId ?: null, $exDate, $units, $exPrice,
             $fmvOnEx, $perqValue, $broker, $tds,
             $saleDate ?: null, $salePrice, $capitalGain, $gainType, $notes ?: null]
        );
        $logId = (int) DB::conn()->lastInsertId();

        // Mark vesting event exercised if tied
        if ($eventId) {
            DB::run(
                "UPDATE esop_vesting_events
                 SET is_exercised=1, exercise_date=?, exercise_price=?, units_exercised=?,
                     sale_date=?, sale_price=?, capital_gain=?, gain_type=?
                 WHERE id=?",
                [$exDate, $exPrice, $units,
                 $saleDate ?: null, $salePrice, $capitalGain, $gainType, $eventId]
            );
        }
        esop_sync_grant_counts($grantId);
        json_response(true, 'Exercise recorded.', [
            'id'             => $logId,
            'perquisite_value'=> $perqValue,
            'capital_gain'   => $capitalGain,
            'gain_type'      => $gainType,
        ]);


    // ─── EXERCISE LOG (list) ──────────────────────────────────────────────────
    case 'esop_exercise_log':
        $grantId = (int) ($_GET['grant_id'] ?? $_POST['grant_id'] ?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        if ($grantId) {
            if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }
            $rows = DB::fetchAll(
                "SELECT * FROM esop_exercise_log WHERE grant_id=? ORDER BY exercise_date DESC",
                [$grantId]
            );
        } else {
            $rows = DB::fetchAll(
                "SELECT el.*, g.company_name, g.grant_type
                 FROM esop_exercise_log el
                 JOIN esop_grants g ON g.id = el.grant_id
                 WHERE g.portfolio_id=?
                 ORDER BY el.exercise_date DESC",
                [$pid]
            );
        }
        json_response(true, '', ['data' => $rows]);


    // ─── UPDATE FMV ───────────────────────────────────────────────────────────
    case 'esop_fmv_update':
        $grantId = (int)   ($_POST['grant_id']   ?? 0);
        $fmv     = (float) ($_POST['current_fmv']?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$grantId || !$pid || $fmv <= 0) { json_response(false, 'grant_id and current_fmv required.'); }
        if (!esop_grant_access($grantId, $pid)) { json_response(false, 'Grant not found.'); }

        DB::run(
            "UPDATE esop_grants SET current_fmv=?, fmv_updated_at=NOW() WHERE id=?",
            [$fmv, $grantId]
        );
        json_response(true, 'FMV updated.', ['current_fmv' => $fmv]);


    // ─── REGENERATE VESTING SCHEDULE ─────────────────────────────────────────
    case 'esop_schedule_generate':
        $grantId = (int) ($_POST['grant_id'] ?? 0);
        $pid     = esop_portfolio($userId, $isAdmin);
        if (!$grantId || !$pid) { json_response(false, 'Invalid request.'); }
        $grant = esop_grant_access($grantId, $pid);
        if (!$grant) { json_response(false, 'Grant not found.'); }
        if ($grant['vesting_type'] === 'custom') {
            json_response(false, 'Custom schedule — add events manually.');
        }
        $n = esop_generate_schedule($grant);
        esop_sync_grant_counts($grantId);
        json_response(true, "Schedule regenerated: {$n} events created.", ['events' => $n]);


    default:
        json_response(false, "Unknown ESOP action: {$action}", [], 400);
}
