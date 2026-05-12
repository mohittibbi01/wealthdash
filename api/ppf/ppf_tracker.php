<?php
/**
 * WealthDash — PPF Annual Deposit Tracker API
 * Task t203: Yearly ₹1,50,000 limit tracking
 *
 * Actions (read-only — CSRF exempt):
 *   ppf_deposit_get      — get FY deposit total for one PPF account
 *   ppf_deposits_list    — all PPF accounts with current FY status
 *   ppf_deposit_history  — year-by-year history for one account
 *
 * Actions (write — CSRF required):
 *   ppf_deposit_save     — upsert total or add an entry
 *   ppf_deposit_delete   — remove a single entry from JSON log
 */
defined('WEALTHDASH') or die();

$userId = (int)$_SESSION['user_id'];
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

const PPF_ANNUAL_LIMIT = 150000;
const PPF_ANNUAL_MIN   = 500;

// ── HELPERS ───────────────────────────────────────────────────────────────────

/** Return [fy_year (int), fy_start (date), fy_end (date), fy_label] for today or given date */
function _ppf_fy(?string $date = null): array {
    $ts     = $date ? strtotime($date) : time();
    $month  = (int)date('n', $ts);
    $year   = (int)date('Y', $ts);
    $fyYear = $month >= 4 ? $year : $year - 1;
    return [
        'fy_year'  => $fyYear,
        'fy_start' => "$fyYear-04-01",
        'fy_end'   => ($fyYear + 1) . "-03-31",
        'fy_label' => "$fyYear-" . substr((string)($fyYear + 1), 2),
    ];
}

/** Verify PPF scheme belongs to user */
function _ppf_owned(int $userId, int $schemeId): bool {
    return (bool)DB::fetchVal(
        "SELECT po.id FROM po_schemes po
         JOIN portfolios p ON p.id = po.portfolio_id
         WHERE po.id=? AND p.user_id=? AND po.scheme_type='ppf'",
        [$schemeId, $userId]
    );
}

/** Calculate total from entries JSON */
function _ppf_entries_total(string $entriesJson): float {
    $arr = json_decode($entriesJson, true);
    if (!is_array($arr)) return 0.0;
    return (float)array_sum(array_column($arr, 'amount'));
}

// ── ACTIONS ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── ppf_deposits_list ────────────────────────────────────────────────────
    case 'ppf_deposits_list': {
        $fy = _ppf_fy();

        $accounts = DB::fetchAll(
            "SELECT po.id, po.holder_name, po.account_number, po.principal,
                    po.opening_date, po.interest_rate, po.maturity_date,
                    po.deposit_amount, po.notes,
                    d.total_deposited, d.entries, d.fy_year
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             LEFT JOIN ppf_fy_deposits d
               ON d.ppf_scheme_id = po.id AND d.fy_year = ?
             WHERE p.user_id = ? AND po.scheme_type = 'ppf' AND po.status = 'active'
             ORDER BY po.opening_date ASC",
            [$fy['fy_year'], $userId]
        );

        foreach ($accounts as &$acc) {
            $deposited  = (float)($acc['total_deposited'] ?? 0);
            $remaining  = max(0, PPF_ANNUAL_LIMIT - $deposited);
            $pct        = min(100, round($deposited / PPF_ANNUAL_LIMIT * 100, 1));
            $yearsOpen  = max(1, (int)ceil((time() - strtotime($acc['opening_date'])) / (365.25 * 86400)));
            $balance    = (float)($acc['principal'] ?? 0);
            $rate       = (float)($acc['interest_rate'] ?? 7.1);
            $daysToEnd  = max(0, (int)ceil((strtotime($fy['fy_end']) - time()) / 86400));

            $acc['fy']                = $fy;
            $acc['deposited']         = $deposited;
            $acc['remaining']         = $remaining;
            $acc['pct']               = $pct;
            $acc['limit_reached']     = $remaining <= 0;
            $acc['days_to_fy_end']    = $daysToEnd;
            $acc['urgent']            = $daysToEnd <= 30 && $remaining > 0;
            $acc['years_open']        = $yearsOpen;
            $acc['partial_eligible']  = $yearsOpen >= 5;
            $acc['partial_from_year'] = date('Y', strtotime($acc['opening_date'] . ' +5 years'));
            $acc['maturity_year']     = date('Y', strtotime($acc['opening_date'] . ' +15 years'));
            $acc['est_annual_int']    = round($balance * $rate / 100);
            $acc['account_number_masked'] = $acc['account_number']
                ? '••••' . substr($acc['account_number'], -4)
                : '—';
            $acc['entries']           = $acc['entries'] ? json_decode($acc['entries'], true) : [];
        }
        unset($acc);

        json_response(true, '', [
            'accounts' => $accounts,
            'limit'    => PPF_ANNUAL_LIMIT,
            'min'      => PPF_ANNUAL_MIN,
            'fy'       => $fy,
        ]);
    }

    // ── ppf_deposit_get ──────────────────────────────────────────────────────
    case 'ppf_deposit_get': {
        $schemeId = (int)($_POST['scheme_id'] ?? $_GET['scheme_id'] ?? 0);
        $fyYear   = (int)($_POST['fy_year']   ?? $_GET['fy_year']   ?? _ppf_fy()['fy_year']);

        if (!$schemeId || !_ppf_owned($userId, $schemeId)) {
            json_response(false, 'PPF account not found.', [], 404);
        }

        $row = DB::fetchOne(
            "SELECT * FROM ppf_fy_deposits WHERE ppf_scheme_id=? AND fy_year=?",
            [$schemeId, $fyYear]
        );

        $deposited = (float)($row['total_deposited'] ?? 0);
        $entries   = $row ? json_decode($row['entries'] ?? '[]', true) : [];

        json_response(true, '', [
            'scheme_id'  => $schemeId,
            'fy_year'    => $fyYear,
            'deposited'  => $deposited,
            'remaining'  => max(0, PPF_ANNUAL_LIMIT - $deposited),
            'pct'        => min(100, round($deposited / PPF_ANNUAL_LIMIT * 100, 1)),
            'entries'    => $entries ?: [],
            'limit'      => PPF_ANNUAL_LIMIT,
        ]);
    }

    // ── ppf_deposit_save ─────────────────────────────────────────────────────
    case 'ppf_deposit_save': {
        $schemeId  = (int)($_POST['scheme_id'] ?? 0);
        $amount    = (float)($_POST['amount']    ?? 0);
        $note      = trim(clean($_POST['note']   ?? ''));
        $depDate   = clean($_POST['date']         ?? date('Y-m-d'));
        $override  = (int)($_POST['override']    ?? 0); // 1 = replace total; 0 = add entry

        if (!$schemeId || !_ppf_owned($userId, $schemeId)) {
            json_response(false, 'PPF account not found.', [], 404);
        }
        if ($amount <= 0 || $amount > PPF_ANNUAL_LIMIT) {
            json_response(false, 'Amount must be between ₹1 and ₹1,50,000.');
        }

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $depDate)) $depDate = date('Y-m-d');
        $fy = _ppf_fy($depDate);

        // Load existing row
        $existing = DB::fetchOne(
            "SELECT * FROM ppf_fy_deposits WHERE ppf_scheme_id=? AND fy_year=?",
            [$schemeId, $fy['fy_year']]
        );

        if ($override) {
            // Hard-set total_deposited (no entry log)
            if ($existing) {
                DB::run(
                    "UPDATE ppf_fy_deposits SET total_deposited=?, updated_at=NOW() WHERE id=?",
                    [$amount, $existing['id']]
                );
            } else {
                DB::insert(
                    "INSERT INTO ppf_fy_deposits (ppf_scheme_id, fy_year, total_deposited, entries) VALUES (?,?,?,?)",
                    [$schemeId, $fy['fy_year'], $amount, '[]']
                );
            }
            json_response(true, 'PPF deposit total updated.', [
                'deposited' => $amount,
                'remaining' => max(0, PPF_ANNUAL_LIMIT - $amount),
            ]);
        }

        // Add entry mode
        $entries    = $existing ? (json_decode($existing['entries'] ?? '[]', true) ?: []) : [];
        $currentSum = (float)($existing['total_deposited'] ?? 0);

        if ($currentSum + $amount > PPF_ANNUAL_LIMIT) {
            $allowed = PPF_ANNUAL_LIMIT - $currentSum;
            json_response(false, sprintf(
                'This deposit would exceed ₹1,50,000 limit. Max allowed: ₹%s.',
                number_format($allowed, 0)
            ));
        }

        $entry = [
            'id'     => uniqid('e', true),
            'date'   => $depDate,
            'amount' => $amount,
            'note'   => $note ?: null,
        ];
        $entries[]  = $entry;
        $newTotal   = $currentSum + $amount;

        if ($existing) {
            DB::run(
                "UPDATE ppf_fy_deposits SET total_deposited=?, entries=?, updated_at=NOW() WHERE id=?",
                [$newTotal, json_encode($entries), $existing['id']]
            );
        } else {
            DB::insert(
                "INSERT INTO ppf_fy_deposits (ppf_scheme_id, fy_year, total_deposited, entries) VALUES (?,?,?,?)",
                [$schemeId, $fy['fy_year'], $newTotal, json_encode($entries)]
            );
        }

        json_response(true, 'Deposit entry saved ✅', [
            'deposited'    => $newTotal,
            'remaining'    => max(0, PPF_ANNUAL_LIMIT - $newTotal),
            'pct'          => min(100, round($newTotal / PPF_ANNUAL_LIMIT * 100, 1)),
            'limit_reached'=> $newTotal >= PPF_ANNUAL_LIMIT,
            'entry_id'     => $entry['id'],
        ]);
    }

    // ── ppf_deposit_delete ───────────────────────────────────────────────────
    case 'ppf_deposit_delete': {
        $schemeId = (int)($_POST['scheme_id'] ?? 0);
        $fyYear   = (int)($_POST['fy_year']   ?? 0);
        $entryId  = clean($_POST['entry_id']  ?? '');

        if (!$schemeId || !_ppf_owned($userId, $schemeId)) {
            json_response(false, 'PPF account not found.', [], 404);
        }

        $row = DB::fetchOne(
            "SELECT * FROM ppf_fy_deposits WHERE ppf_scheme_id=? AND fy_year=?",
            [$schemeId, $fyYear]
        );
        if (!$row) json_response(false, 'No deposit record found.');

        $entries = json_decode($row['entries'] ?? '[]', true) ?: [];
        $removed = null;
        $entries = array_values(array_filter($entries, function($e) use ($entryId, &$removed) {
            if ($e['id'] === $entryId) { $removed = $e; return false; }
            return true;
        }));

        if (!$removed) json_response(false, 'Entry not found.');

        $newTotal = max(0, (float)$row['total_deposited'] - (float)$removed['amount']);
        DB::run(
            "UPDATE ppf_fy_deposits SET total_deposited=?, entries=?, updated_at=NOW() WHERE id=?",
            [$newTotal, json_encode($entries), $row['id']]
        );

        json_response(true, 'Entry deleted.', [
            'deposited' => $newTotal,
            'remaining' => max(0, PPF_ANNUAL_LIMIT - $newTotal),
        ]);
    }

    // ── ppf_deposit_history ──────────────────────────────────────────────────
    case 'ppf_deposit_history': {
        $schemeId = (int)($_POST['scheme_id'] ?? $_GET['scheme_id'] ?? 0);

        if (!$schemeId || !_ppf_owned($userId, $schemeId)) {
            json_response(false, 'PPF account not found.', [], 404);
        }

        $rows = DB::fetchAll(
            "SELECT fy_year, total_deposited, entries, updated_at
             FROM ppf_fy_deposits
             WHERE ppf_scheme_id=?
             ORDER BY fy_year DESC",
            [$schemeId]
        );

        foreach ($rows as &$r) {
            $r['fy_label']       = $r['fy_year'] . '-' . substr((string)($r['fy_year'] + 1), 2);
            $r['deposited']      = (float)$r['total_deposited'];
            $r['pct']            = min(100, round($r['deposited'] / PPF_ANNUAL_LIMIT * 100, 1));
            $r['limit_used_pct'] = $r['pct'];
            $r['entries']        = json_decode($r['entries'] ?? '[]', true) ?: [];
        }
        unset($r);

        json_response(true, '', [
            'scheme_id' => $schemeId,
            'history'   => $rows,
            'limit'     => PPF_ANNUAL_LIMIT,
        ]);
    }

    default:
        json_response(false, 'Unknown action: ' . $action, [], 400);
}
