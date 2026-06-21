<?php
/**
 * WealthDash — t205: NSC Interest — Deemed Reinvestment Tracker (80C)
 *
 * NSC Rules (India):
 *  - Tenure: 5 years, compounded annually at current rate (7.7% FY25-26)
 *  - Interest NOT paid out — reinvested internally each year
 *  - Years 1–4: Deemed reinvestment = 80C eligible (add to that FY's 80C declarations)
 *  - Year 5 (final): Interest taxable as "Income from Other Sources" — NOT 80C eligible
 *  - No TDS; self-declare in ITR
 *  - Principal: 80C eligible in purchase year
 *  - Max investment: No upper limit (but 80C benefit capped at ₹1,50,000)
 *
 * Actions (read — CSRF exempt):
 *   nsc_list            — all NSC holdings with year-wise accrual
 *   nsc_80c_schedule    — year-wise 80C eligible interest per holding
 *   nsc_fy_declaration  — total NSC 80C + IFOS to declare in a given FY
 *   nsc_maturity_calc   — maturity projector for a given principal/rate
 *
 * Actions (write — CSRF required):
 *   nsc_add             — add NSC (delegates to po_schemes standard add)
 *   nsc_80c_log_save    — save user's manual 80C declaration record
 *   nsc_80c_log_delete  — remove a declaration record
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'nsc_list';

// ── CONSTANTS ─────────────────────────────────────────────────────────────
const NSC_TENURE_YEARS  = 5;
const NSC_CURRENT_RATE  = 7.7;   // Q1 FY2025-26 rate
const NSC_80C_LIMIT     = 150000;

// ── HELPERS ───────────────────────────────────────────────────────────────

/**
 * Compute year-by-year accrual for NSC.
 * Returns array of 5 entries: [year, fy, opening_balance, interest, closing_balance, is_80c_eligible, is_taxable_ifos]
 */
function _nsc_year_schedule(float $principal, float $rate, string $purchaseDate): array {
    $schedule = [];
    $balance  = $principal;

    for ($yr = 1; $yr <= NSC_TENURE_YEARS; $yr++) {
        $yearStart   = date('Y-m-d', strtotime($purchaseDate . ' +' . ($yr - 1) . ' years'));
        $yearEnd     = date('Y-m-d', strtotime($purchaseDate . ' +' . $yr . ' years'));
        $interest    = round($balance * $rate / 100, 2);
        $newBalance  = round($balance + $interest, 2);

        // FY in which this year-end falls
        $fy = _nsc_fy_from_date($yearEnd);

        $schedule[] = [
            'year'                => $yr,
            'year_start'          => $yearStart,
            'year_end'            => $yearEnd,
            'fy'                  => $fy,
            'opening_balance'     => round($balance, 2),
            'interest_accrued'    => $interest,
            'closing_balance'     => $newBalance,
            'is_80c_eligible'     => $yr < NSC_TENURE_YEARS,   // years 1-4 only
            'is_taxable_ifos'     => $yr === NSC_TENURE_YEARS, // year 5 taxable
            'note'                => $yr < NSC_TENURE_YEARS
                ? "Year {$yr}: ₹" . number_format($interest, 2) . " deemed reinvestment — 80C mein add karo"
                : "Year 5 (Final): ₹" . number_format($interest, 2) . " IFOS taxable hai — 80C nahi milega",
        ];

        $balance = $newBalance;
    }
    return $schedule;
}

function _nsc_fy_from_date(string $date): string {
    $y  = (int)date('Y', strtotime($date));
    $m  = (int)date('n', strtotime($date));
    $fy = $m >= 4 ? $y : $y - 1;
    return $fy . '-' . ($fy + 1);
}

function _nsc_fy_dates(string $fy = ''): array {
    if ($fy && preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
        return [$m[1] . '-04-01', $m[2] . '-03-31', $fy];
    }
    $yr  = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    $lbl = $yr . '-' . ($yr + 1);
    return [$yr . '-04-01', ($yr + 1) . '-03-31', $lbl];
}

/** Verify NSC belongs to user */
function _nsc_owned(int $userId, int $schemeId): ?array {
    return DB::fetchOne(
        "SELECT po.* FROM po_schemes po
         JOIN portfolios p ON p.id = po.portfolio_id
         WHERE po.id=? AND p.user_id=? AND LOWER(po.scheme_type)='nsc'",
        [$schemeId, $userId]
    ) ?: null;
}

// ── ACTIONS ───────────────────────────────────────────────────────────────

switch ($action) {

    // ── nsc_list ──────────────────────────────────────────────────────────
    case 'nsc_list': {
        $holdings = DB::fetchAll(
            "SELECT po.id, po.holder_name, po.account_number, po.post_office,
                    po.principal, po.interest_rate, po.opening_date, po.maturity_date,
                    po.maturity_amount, po.current_value, po.status, po.notes,
                    po.interest_earned,
                    DATEDIFF(po.maturity_date, CURDATE()) AS days_to_maturity,
                    DATEDIFF(CURDATE(), po.opening_date)  AS days_held
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id = ? AND LOWER(po.scheme_type) = 'nsc'
             ORDER BY po.opening_date ASC",
            [$userId]
        );

        $totalInvested      = 0;
        $totalCurrentValue  = 0;
        $total80cEligibleFY = 0;  // current FY
        $totalIfosCurrentFY = 0;
        [$fyStart, $fyEnd, $fyLabel] = _nsc_fy_dates();

        foreach ($holdings as &$h) {
            $principal  = (float)$h['principal'];
            $rate       = (float)($h['interest_rate'] ?: NSC_CURRENT_RATE);
            $purchDate  = $h['opening_date'];

            $schedule = _nsc_year_schedule($principal, $rate, $purchDate);
            $h['year_schedule'] = $schedule;

            // Current value = opening_balance of year we're in now
            $today  = date('Y-m-d');
            $cv     = $principal;
            foreach ($schedule as $sy) {
                if ($sy['year_end'] <= $today) {
                    $cv = $sy['closing_balance'];
                } else {
                    // Partial year: pro-rata
                    $daysInYear   = 365;
                    $daysElapsed  = max(0, (int)((strtotime($today) - strtotime($sy['year_start'])) / 86400));
                    $cv = $sy['opening_balance'] + round($sy['interest_accrued'] * $daysElapsed / $daysInYear, 2);
                    break;
                }
            }
            $h['current_value_calc'] = $cv;

            // 80C eligible interest for CURRENT FY
            $fy80c = 0;
            $fyIfos = 0;
            foreach ($schedule as $sy) {
                if ($sy['year_end'] >= $fyStart && $sy['year_end'] <= $fyEnd) {
                    if ($sy['is_80c_eligible']) $fy80c  += $sy['interest_accrued'];
                    if ($sy['is_taxable_ifos']) $fyIfos += $sy['interest_accrued'];
                }
            }
            $h['current_fy_80c_interest']  = round($fy80c, 2);
            $h['current_fy_ifos_interest']  = round($fyIfos, 2);
            $h['maturity_amount_calc']       = end($schedule)['closing_balance'];
            $h['total_interest']             = round(end($schedule)['closing_balance'] - $principal, 2);
            $h['years_elapsed']              = min(NSC_TENURE_YEARS, (int)floor($h['days_held'] / 365));
            $h['is_matured']                 = $h['days_to_maturity'] !== null && (int)$h['days_to_maturity'] <= 0;

            $totalInvested     += $principal;
            $totalCurrentValue += $cv;
            $total80cEligibleFY += $fy80c;
            $totalIfosCurrentFY += $fyIfos;
        }
        unset($h);

        json_response(true, '', [
            'holdings'    => $holdings,
            'count'       => count($holdings),
            'summary' => [
                'total_invested'           => round($totalInvested, 2),
                'total_current_value'      => round($totalCurrentValue, 2),
                'total_gain'               => round($totalCurrentValue - $totalInvested, 2),
                'current_fy_80c_eligible'  => round($total80cEligibleFY, 2),
                'current_fy_ifos_taxable'  => round($totalIfosCurrentFY, 2),
                'current_fy'               => $fyLabel,
            ],
            'nsc_rate'  => NSC_CURRENT_RATE,
            'nsc_rules' => [
                'tenure'            => '5 years',
                'compounding'       => 'Annual',
                'tax_80c_principal' => 'Purchase year mein 80C eligible (₹1.5L limit)',
                'tax_80c_interest'  => 'Year 1-4 ka deemed reinvestment interest 80C eligible hai',
                'tax_ifos_year5'    => 'Year 5 ka interest ITR mein IFOS declare karna hoga',
                'no_tds'            => 'NSC par koi TDS nahi hota. Self-declare in ITR.',
            ],
        ]);
    }

    // ── nsc_80c_schedule ──────────────────────────────────────────────────
    case 'nsc_80c_schedule': {
        $schemeId = (int)($_GET['scheme_id'] ?? 0);
        if (!$schemeId) json_response(false, 'scheme_id required.');

        $h = _nsc_owned($userId, $schemeId);
        if (!$h) json_response(false, 'NSC holding not found.', [], 404);

        $principal = (float)$h['principal'];
        $rate      = (float)($h['interest_rate'] ?: NSC_CURRENT_RATE);
        $purchDate = $h['opening_date'];

        $schedule  = _nsc_year_schedule($principal, $rate, $purchDate);

        // Get saved 80C log entries for this scheme
        $logs = DB::fetchAll(
            "SELECT * FROM nsc_80c_log WHERE nsc_scheme_id=? ORDER BY fy ASC",
            [$schemeId]
        );
        $logMap = array_column($logs, null, 'fy');

        foreach ($schedule as &$sy) {
            $log = $logMap[$sy['fy']] ?? null;
            $sy['declared_in_80c']  = $log ? (int)$log['declared_80c'] : 0;
            $sy['declaration_note'] = $log['notes'] ?? null;
        }
        unset($sy);

        json_response(true, '', [
            'scheme_id'          => $schemeId,
            'holder_name'        => $h['holder_name'],
            'account_number'     => $h['account_number'],
            'principal'          => $principal,
            'purchase_date'      => $purchDate,
            'maturity_date'      => $h['maturity_date'],
            'rate'               => $rate,
            'schedule'           => $schedule,
            'maturity_amount'    => end($schedule)['closing_balance'],
            'total_interest'     => round(end($schedule)['closing_balance'] - $principal, 2),
            'total_80c_eligible' => round(array_sum(
                array_column(array_filter($schedule, fn($s) => $s['is_80c_eligible']), 'interest_accrued')
            ), 2),
            'year5_ifos_amount'  => round(end($schedule)['interest_accrued'], 2),
        ]);
    }

    // ── nsc_fy_declaration ────────────────────────────────────────────────
    case 'nsc_fy_declaration': {
        $fy = clean($_GET['fy'] ?? '');
        [$fyStart, $fyEnd, $fyLabel] = _nsc_fy_dates($fy);

        $holdings = DB::fetchAll(
            "SELECT po.id, po.holder_name, po.account_number, po.principal,
                    po.interest_rate, po.opening_date, po.maturity_date, po.status
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id = ? AND LOWER(po.scheme_type) = 'nsc'
               AND po.opening_date <= ?
               AND (po.maturity_date IS NULL OR po.maturity_date >= ?)",
            [$userId, $fyEnd, $fyStart]
        );

        $total80c  = 0;
        $totalIfos = 0;
        $items     = [];

        foreach ($holdings as $h) {
            $principal = (float)$h['principal'];
            $rate      = (float)($h['interest_rate'] ?: NSC_CURRENT_RATE);
            $schedule  = _nsc_year_schedule($principal, $rate, $h['opening_date']);

            $fy80c  = 0;
            $fyIfos = 0;

            foreach ($schedule as $sy) {
                if ($sy['year_end'] >= $fyStart && $sy['year_end'] <= $fyEnd) {
                    if ($sy['is_80c_eligible']) $fy80c  += $sy['interest_accrued'];
                    if ($sy['is_taxable_ifos']) $fyIfos += $sy['interest_accrued'];
                }
            }

            // Check if purchased in this FY (principal itself is 80C eligible)
            $principalEligible = ($h['opening_date'] >= $fyStart && $h['opening_date'] <= $fyEnd)
                ? $principal : 0;

            if ($fy80c > 0 || $fyIfos > 0 || $principalEligible > 0) {
                $items[] = [
                    'scheme_id'            => $h['id'],
                    'holder_name'          => $h['holder_name'],
                    'account_number'       => $h['account_number'],
                    'principal'            => $principal,
                    'principal_80c'        => $principalEligible,
                    'interest_80c'         => round($fy80c, 2),
                    'interest_ifos'        => round($fyIfos, 2),
                    'total_80c_this_fy'    => round($principalEligible + $fy80c, 2),
                    'itr_section'          => $fyIfos > 0
                        ? 'Schedule OS → Income from Other Sources'
                        : null,
                    'itr_80c_section'      => ($principalEligible + $fy80c) > 0
                        ? 'Part C — Deductions → 80C → NSC'
                        : null,
                ];
                $total80c  += $principalEligible + $fy80c;
                $totalIfos += $fyIfos;
            }
        }

        // Saved declarations
        $savedLogs = DB::fetchAll(
            "SELECT nl.*, po.holder_name, po.account_number
             FROM nsc_80c_log nl
             JOIN po_schemes po ON po.id = nl.nsc_scheme_id
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id=? AND nl.fy=?",
            [$userId, $fyLabel]
        );

        json_response(true, '', [
            'fy'                      => $fyLabel,
            'fy_start'                => $fyStart,
            'fy_end'                  => $fyEnd,
            'items'                   => $items,
            'total_80c_eligible'      => round($total80c, 2),
            'total_ifos_taxable'      => round($totalIfos, 2),
            '80c_limit'               => NSC_80C_LIMIT,
            'saved_declarations'      => $savedLogs,
            'itr_reminder' => [
                '80C_note'   => '80C deduction claim karo: Principal (purchase year) + Years 1-4 deemed interest',
                'ifos_note'  => 'Year 5 interest ITR-1/2 → Schedule OS mein declare karo',
                'no_tds'     => 'NSC par TDS nahi kata — self-declaration zaroori hai',
            ],
        ]);
    }

    // ── nsc_maturity_calc ─────────────────────────────────────────────────
    case 'nsc_maturity_calc': {
        $principal  = max(100, (float)($_GET['principal'] ?? 1000));
        $rate       = (float)($_GET['rate']       ?? NSC_CURRENT_RATE);
        $purchDate  = clean($_GET['purchase_date'] ?? date('Y-m-d'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchDate)) {
            $purchDate = date('Y-m-d');
        }

        $schedule    = _nsc_year_schedule($principal, $rate, $purchDate);
        $maturityAmt = end($schedule)['closing_balance'];
        $matDate     = date('Y-m-d', strtotime($purchDate . ' +5 years'));

        json_response(true, '', [
            'principal'         => round($principal, 2),
            'rate'              => $rate,
            'purchase_date'     => $purchDate,
            'maturity_date'     => $matDate,
            'maturity_amount'   => $maturityAmt,
            'total_interest'    => round($maturityAmt - $principal, 2),
            'total_80c_interest'=> round(array_sum(
                array_column(array_filter($schedule, fn($s) => $s['is_80c_eligible']), 'interest_accrued')
            ), 2),
            'year5_ifos_taxable'=> round(end($schedule)['interest_accrued'], 2),
            'schedule'          => $schedule,
            'effective_rate'    => round(($maturityAmt / $principal - 1) * 100 / NSC_TENURE_YEARS, 2),
        ]);
    }

    // ── nsc_80c_log_save ──────────────────────────────────────────────────
    case 'nsc_80c_log_save': {
        require_csrf();

        $schemeId  = (int)($_POST['scheme_id']   ?? 0);
        $fy        = clean($_POST['fy']          ?? '');
        $amount    = (float)($_POST['amount']    ?? 0);
        $declared  = (int)(bool)($_POST['declared_80c'] ?? 0);
        $notes     = substr(trim(clean($_POST['notes'] ?? '')), 0, 255);

        if (!$schemeId || !_nsc_owned($userId, $schemeId)) {
            json_response(false, 'NSC scheme not found.', [], 404);
        }
        if (!preg_match('/^\d{4}-\d{4}$/', $fy)) {
            json_response(false, 'Invalid FY format. Use YYYY-YYYY.');
        }
        if ($amount < 0) json_response(false, 'Amount cannot be negative.');

        DB::run(
            "INSERT INTO nsc_80c_log (nsc_scheme_id, fy, amount, declared_80c, notes)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE amount=VALUES(amount), declared_80c=VALUES(declared_80c), notes=VALUES(notes)",
            [$schemeId, $fy, $amount, $declared, $notes ?: null]
        );

        json_response(true, '80C declaration saved ✅', ['scheme_id' => $schemeId, 'fy' => $fy]);
    }

    // ── nsc_80c_log_delete ────────────────────────────────────────────────
    case 'nsc_80c_log_delete': {
        require_csrf();

        $logId = (int)($_POST['log_id'] ?? 0);
        if (!$logId) json_response(false, 'log_id required.');

        // Verify ownership via join
        $deleted = DB::run(
            "DELETE nl FROM nsc_80c_log nl
             JOIN po_schemes po ON po.id = nl.nsc_scheme_id
             JOIN portfolios p  ON p.id  = po.portfolio_id
             WHERE nl.id=? AND p.user_id=?",
            [$logId, $userId]
        );

        json_response((bool)$deleted, $deleted ? 'Entry deleted.' : 'Entry not found.');
    }

    default:
        json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);
}
