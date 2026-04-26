<?php
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

/*
 * t341 — Gratuity Tracker API
 * Payment of Gratuity Act 1972:
 *   Gratuity = (Last Basic+DA / 26) × 15 × completed_years
 *   Min service: 5 years (except death/disability: any service)
 *   Tax-free limit: ₹20L (private) | fully exempt (govt)
 *   For non-Act employees: (Last salary / 30) × 15 × years
 */

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}" : "AND p.user_id = {$userId}";

// ── List ───────────────────────────────────────────────────────────────────
if ($action === 'gratuity_list') {
    $rows = DB::fetchAll(
        "SELECT ga.*, p.name AS portfolio_name,
                TIMESTAMPDIFF(YEAR, ga.joining_date, IFNULL(ga.separation_date, CURDATE())) AS years_of_service,
                TIMESTAMPDIFF(MONTH, ga.joining_date, IFNULL(ga.separation_date, CURDATE())) AS months_of_service
         FROM gratuity_accounts ga
         JOIN portfolios p ON p.id = ga.portfolio_id
         WHERE ga.is_active=1 {$portCond}
         ORDER BY ga.separation_date IS NOT NULL ASC, ga.joining_date DESC"
    );

    foreach ($rows as &$row) {
        $row['computed'] = computeGratuity(
            (float)$row['last_drawn_salary'],
            (int)$row['months_of_service'],
            (bool)$row['is_covered_by_act'],
            (bool)$row['is_govt_employee'],
            $row['separation_type']
        );
    }
    json_response(true, '', $rows);
}

// ── Add ────────────────────────────────────────────────────────────────────
if ($action === 'gratuity_add') {
    $pId = (int)($_POST['portfolio_id'] ?? 0);
    if (!can_access_portfolio($pId, $userId, $isAdmin)) json_response(false, 'Access denied.');
    if (!trim($_POST['employer_name'] ?? '')) json_response(false, 'Employer name required.');
    if (!trim($_POST['joining_date']  ?? '')) json_response(false, 'Joining date required.');

    DB::run(
        "INSERT INTO gratuity_accounts
           (portfolio_id, employer_name, designation, joining_date, last_drawn_salary,
            separation_date, separation_type, actual_gratuity, is_govt_employee, is_covered_by_act, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        [
            $pId,
            clean($_POST['employer_name'] ?? ''),
            clean($_POST['designation']   ?? ''),
            clean($_POST['joining_date']  ?? ''),
            (float)($_POST['last_drawn_salary'] ?? 0),
            trim($_POST['separation_date'] ?? '') ?: null,
            clean($_POST['separation_type'] ?? 'employed'),
            trim($_POST['actual_gratuity'] ?? '') !== '' ? (float)$_POST['actual_gratuity'] : null,
            (int)(bool)($_POST['is_govt_employee']  ?? 0),
            (int)(bool)($_POST['is_covered_by_act'] ?? 1),
            clean($_POST['notes'] ?? ''),
        ]
    );
    json_response(true, 'Gratuity record added.');
}

// ── Update ─────────────────────────────────────────────────────────────────
if ($action === 'gratuity_update') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run(
        "UPDATE gratuity_accounts
            SET employer_name=?, designation=?, joining_date=?, last_drawn_salary=?,
                separation_date=?, separation_type=?, actual_gratuity=?,
                is_govt_employee=?, is_covered_by_act=?, notes=?, updated_at=NOW()
          WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)",
        [
            clean($_POST['employer_name'] ?? ''),
            clean($_POST['designation']   ?? ''),
            clean($_POST['joining_date']  ?? ''),
            (float)($_POST['last_drawn_salary'] ?? 0),
            trim($_POST['separation_date'] ?? '') ?: null,
            clean($_POST['separation_type'] ?? 'employed'),
            trim($_POST['actual_gratuity'] ?? '') !== '' ? (float)$_POST['actual_gratuity'] : null,
            (int)(bool)($_POST['is_govt_employee']  ?? 0),
            (int)(bool)($_POST['is_covered_by_act'] ?? 1),
            clean($_POST['notes'] ?? ''),
            $id, $userId,
        ]
    );
    json_response(true, 'Record updated.');
}

// ── Delete ─────────────────────────────────────────────────────────────────
if ($action === 'gratuity_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run(
        "UPDATE gratuity_accounts SET is_active=0 WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)",
        [$id, $userId]
    );
    json_response(true, 'Record deleted.');
}

// ── Pure Calculator (no DB) ────────────────────────────────────────────────
if ($action === 'gratuity_calc') {
    $salary       = (float)($_POST['last_drawn_salary'] ?? 0);
    $months       = (int)  ($_POST['months_of_service'] ?? 0);
    $coveredByAct = (bool) ($_POST['is_covered_by_act'] ?? 1);
    $isGovt       = (bool) ($_POST['is_govt_employee']  ?? 0);
    $sepType      = clean($_POST['separation_type'] ?? 'resigned');

    if ($salary <= 0) json_response(false, 'Salary required.');

    json_response(true, '', computeGratuity($salary, $months, $coveredByAct, $isGovt, $sepType));
}

// ── Helper function ────────────────────────────────────────────────────────
function computeGratuity(
    float  $salary,
    int    $months,
    bool   $coveredByAct,
    bool   $isGovt,
    string $sepType
): array {
    $completedYears = intdiv($months, 12);
    $remainMonths   = $months % 12;

    // Rounding rule: ≥6 months rounds up to next year (Gratuity Act)
    $effectiveYears = $completedYears + ($remainMonths >= 6 ? 1 : 0);

    // Eligibility: 5 years min, except death/disability
    $deathOrDisability = in_array($sepType, ['death', 'disability'], true);
    $eligible          = $deathOrDisability || $effectiveYears >= 5;

    // Gratuity formula
    // Act employees:     (salary / 26) × 15 × effective_years
    // Non-Act employees: (salary / 30) × 15 × effective_years  [as per SC judgment]
    $divisor  = $coveredByAct ? 26 : 30;
    $gratuity = $eligible ? round(($salary / $divisor) * 15 * $effectiveYears, 2) : 0;

    // Tax exemption
    // Govt / local authority: fully exempt
    // Private (Act): min(gratuity, ₹20L, last_salary×15/26×years) — all same formula
    // Private (non-Act): min(gratuity, ₹10L, half_month×years)
    $exemptLimit = $isGovt ? PHP_INT_MAX : ($coveredByAct ? 2000000 : 1000000);
    $taxExempt   = min($gratuity, $exemptLimit);
    $taxable     = max(0, $gratuity - $taxExempt);

    // Future projection at current salary growth (simple: for each additional year)
    // Provide table: 5,10,15,20,25,30 years
    $milestones = [];
    foreach ([5,10,15,20,25,30] as $yr) {
        $g = round(($salary / $divisor) * 15 * $yr, 2);
        $e = min($g, $exemptLimit);
        $milestones[] = [
            'years'    => $yr,
            'gratuity' => $g,
            'exempt'   => $e,
            'taxable'  => max(0, $g - $e),
        ];
    }

    return [
        'salary'          => $salary,
        'months'          => $months,
        'completed_years' => $completedYears,
        'effective_years' => $effectiveYears,
        'eligible'        => $eligible,
        'gratuity'        => $gratuity,
        'tax_exempt'      => $taxExempt,
        'taxable'         => $taxable,
        'covered_by_act'  => $coveredByAct,
        'is_govt'         => $isGovt,
        'exempt_limit'    => $exemptLimit === PHP_INT_MAX ? null : $exemptLimit,
        'milestones'      => $milestones,
        'note'            => $eligible
            ? ($isGovt ? 'Fully tax-exempt (Govt employee).' : ($taxable > 0 ? "₹" . number_format($taxable, 0) . " taxable above ₹" . ($coveredByAct ? '20L' : '10L') . " limit." : "Fully within tax-exempt limit."))
            : ($deathOrDisability ? 'Eligible (death/disability — no minimum service).' : "Not eligible yet — need 5 years (currently {$completedYears} years {$remainMonths} months)."),
    ];
}
