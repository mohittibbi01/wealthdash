<?php
/**
 * WealthDash — Insurance Portfolio API
 * t321: Term Insurance Tracker
 * t322: Health Insurance Tracker
 * t459: Term Insurance Adequacy
 * t324: Premium Calendar
 *
 * DB columns: id, portfolio_id, policy_number, insurer_name, policy_type,
 *   insured_name, sum_assured, premium_amount, premium_frequency,
 *   start_date, end_date, maturity_date, maturity_amount,
 *   surrender_value, status, nominee, notes, next_premium_date (via migration t321)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'insurance_list') {
    $filterType = clean($_GET['policy_type'] ?? '');
    $typeCond   = $filterType ? "AND ip.policy_type = " . $db->quote($filterType) : '';

    $rows = DB::fetchAll(
        "SELECT ip.*, p.name AS portfolio_name,
                DATEDIFF(ip.next_premium_date, CURDATE()) AS days_to_premium,
                DATEDIFF(ip.end_date, CURDATE())          AS days_to_end,
                DATEDIFF(ip.maturity_date, CURDATE())     AS days_to_maturity
         FROM insurance_policies ip
         JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE ip.status = 'active' {$portCond} {$typeCond}
         ORDER BY ip.next_premium_date ASC, ip.policy_type ASC"
    );
    json_response(true, '', $rows);
    exit;
}

// ── ADD ──────────────────────────────────────────────────────────────────────
if ($action === 'insurance_add') {
    $pId = (int)($_POST['portfolio_id'] ?? 0);
    if (!can_access_portfolio($pId, $userId, $isAdmin)) {
        json_response(false, 'Access denied.');
        exit;
    }

    $policyType   = clean($_POST['policy_type']       ?? 'term');
    $insurerName  = clean($_POST['insurer_name']      ?? '');
    $policyNumber = clean($_POST['policy_number']     ?? '') ?: null;
    $insuredName  = clean($_POST['insured_name']      ?? '') ?: null;
    $sumAssured   = (float)($_POST['sum_assured']     ?? 0);
    $premAmt      = (float)($_POST['premium_amount']  ?? 0);
    $premFreq     = clean($_POST['premium_frequency'] ?? 'yearly');
    $startDate    = clean($_POST['start_date']        ?? date('Y-m-d'));
    $endDate      = clean($_POST['end_date']          ?? '') ?: null;
    $matDate      = clean($_POST['maturity_date']     ?? '') ?: null;
    $matAmt       = strlen($_POST['maturity_amount']  ?? '') ? (float)$_POST['maturity_amount']  : null;
    $surrenderVal = strlen($_POST['surrender_value']  ?? '') ? (float)$_POST['surrender_value']  : null;
    $nominee      = clean($_POST['nominee']           ?? '') ?: null;
    $notes        = clean($_POST['notes']             ?? '') ?: null;
    $nextPremDate = clean($_POST['next_premium_date'] ?? '') ?: null;

    if (!$insurerName || $sumAssured <= 0) {
        json_response(false, 'Insurer name and sum assured are required.', [], 422);
        exit;
    }

    // Auto-compute next_premium_date from start_date if not provided
    if (!$nextPremDate && $startDate && $premFreq !== 'single') {
        $freqMap = ['monthly'=>'+1 month','quarterly'=>'+3 months','half_yearly'=>'+6 months','yearly'=>'+1 year'];
        $interval = $freqMap[$premFreq] ?? '+1 year';
        $d = new DateTime($startDate);
        $today = new DateTime();
        while ($d <= $today) { $d->modify($interval); }
        $nextPremDate = $d->format('Y-m-d');
    }

    DB::run(
        "INSERT INTO insurance_policies
           (portfolio_id, policy_type, insurer_name, policy_number, insured_name,
            sum_assured, premium_amount, premium_frequency, start_date,
            end_date, maturity_date, maturity_amount, surrender_value,
            nominee, notes, next_premium_date, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')",
        [$pId, $policyType, $insurerName, $policyNumber, $insuredName,
         $sumAssured, $premAmt, $premFreq, $startDate,
         $endDate, $matDate, $matAmt, $surrenderVal,
         $nominee, $notes, $nextPremDate]
    );
    json_response(true, 'Policy added.', ['id' => $db->lastInsertId()]);
    exit;
}

// ── EDIT ─────────────────────────────────────────────────────────────────────
if ($action === 'insurance_edit') {
    $id           = (int)($_POST['id']               ?? 0);
    $insurerName  = clean($_POST['insurer_name']     ?? '');
    $policyNumber = clean($_POST['policy_number']    ?? '') ?: null;
    $insuredName  = clean($_POST['insured_name']     ?? '') ?: null;
    $policyType   = clean($_POST['policy_type']      ?? 'term');
    $sumAssured   = (float)($_POST['sum_assured']    ?? 0);
    $premAmt      = (float)($_POST['premium_amount'] ?? 0);
    $premFreq     = clean($_POST['premium_frequency']?? 'yearly');
    $startDate    = clean($_POST['start_date']       ?? date('Y-m-d'));
    $endDate      = clean($_POST['end_date']         ?? '') ?: null;
    $matDate      = clean($_POST['maturity_date']    ?? '') ?: null;
    $matAmt       = strlen($_POST['maturity_amount']  ?? '') ? (float)$_POST['maturity_amount']  : null;
    $surrenderVal = strlen($_POST['surrender_value']  ?? '') ? (float)$_POST['surrender_value']  : null;
    $nominee      = clean($_POST['nominee']          ?? '') ?: null;
    $notes        = clean($_POST['notes']            ?? '') ?: null;
    $nextPremDate = clean($_POST['next_premium_date']?? '') ?: null;
    $status       = clean($_POST['status']           ?? 'active');

    DB::run(
        "UPDATE insurance_policies SET
           insurer_name=?, policy_number=?, insured_name=?, policy_type=?,
           sum_assured=?, premium_amount=?, premium_frequency=?, start_date=?,
           end_date=?, maturity_date=?, maturity_amount=?, surrender_value=?,
           nominee=?, notes=?, next_premium_date=?, status=?
         WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)",
        [$insurerName, $policyNumber, $insuredName, $policyType,
         $sumAssured, $premAmt, $premFreq, $startDate,
         $endDate, $matDate, $matAmt, $surrenderVal,
         $nominee, $notes, $nextPremDate, $status,
         $id, $userId]
    );
    json_response(true, 'Policy updated.');
    exit;
}

// ── SOFT DELETE ───────────────────────────────────────────────────────────────
if ($action === 'insurance_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run(
        "UPDATE insurance_policies SET status='surrendered'
         WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)",
        [$id, $userId]
    );
    json_response(true, 'Policy removed.');
    exit;
}

// ── PREMIUM CALENDAR (t324) ───────────────────────────────────────────────────
if ($action === 'insurance_premium_calendar') {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));

    $rows = DB::fetchAll(
        "SELECT ip.id, ip.insurer_name, ip.policy_type, ip.premium_amount,
                ip.premium_frequency, ip.next_premium_date, p.name AS portfolio_name
         FROM insurance_policies ip
         JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE ip.status='active' AND p.user_id=?
           AND ip.next_premium_date IS NOT NULL
           AND YEAR(ip.next_premium_date)=? AND MONTH(ip.next_premium_date)=?",
        [$userId, $year, $month]
    );

    json_response(true, '', ['premiums' => $rows, 'year' => $year, 'month' => $month]);
    exit;
}

// ── ADEQUACY CHECK (t459) ─────────────────────────────────────────────────────
if ($action === 'insurance_adequacy') {
    $annualIncome   = (float)($_POST['annual_income']   ?? 0);
    $age            = (int)($_POST['age']               ?? 35);
    $dependents     = (int)($_POST['dependents']        ?? 2);
    $liabilities    = (float)($_POST['liabilities']     ?? 0);
    $monthlyExpense = (float)($_POST['monthly_expense'] ?? 0);

    // Existing life cover
    $existingCover = (float)(DB::fetchOne(
        "SELECT SUM(ip.sum_assured) AS total
         FROM insurance_policies ip
         JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE p.user_id=? AND ip.status='active'
           AND ip.policy_type IN ('term','endowment','ulip','money_back')",
        [$userId]
    )['total'] ?? 0);

    // Existing health cover
    $existingHealth = (float)(DB::fetchOne(
        "SELECT SUM(ip.sum_assured) AS total
         FROM insurance_policies ip
         JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE p.user_id=? AND ip.status='active' AND ip.policy_type='health'",
        [$userId]
    )['total'] ?? 0);

    // HLV Method
    $yearsToRetire    = max(0, 60 - $age);
    $hlvCover         = $annualIncome * $yearsToRetire;
    // Expense Method: 10× annual expense
    $expenseCover     = $monthlyExpense * 12 * 10;
    // Liability-adjusted
    $liabilityCover   = $hlvCover + $liabilities;
    // Dependents factor
    $dependentFactor  = 1 + ($dependents * 0.1);
    $recommendedCover = max($hlvCover, $expenseCover, $liabilityCover) * $dependentFactor;
    $shortfall        = max(0, $recommendedCover - $existingCover);
    $coverageRatio    = $annualIncome > 0 ? $existingCover / $annualIncome : 0;

    // Health adequacy: ₹5L per person minimum, ₹10L recommended
    $familySize          = $dependents + 1;
    $recommendedHealth   = $familySize * 1000000; // ₹10L per person
    $healthShortfall     = max(0, $recommendedHealth - $existingHealth);

    $rating = 'Critical';
    if ($coverageRatio >= 20)     $rating = 'Excellent';
    elseif ($coverageRatio >= 15) $rating = 'Good';
    elseif ($coverageRatio >= 10) $rating = 'Adequate';
    elseif ($coverageRatio >= 5)  $rating = 'Low';

    json_response(true, '', [
        'existing_cover'      => $existingCover,
        'existing_health'     => $existingHealth,
        'hlv_cover'           => $hlvCover,
        'expense_cover'       => $expenseCover,
        'liability_cover'     => $liabilityCover,
        'recommended_cover'   => $recommendedCover,
        'shortfall'           => $shortfall,
        'coverage_ratio'      => round($coverageRatio, 1),
        'rating'              => $rating,
        'years_to_retire'     => $yearsToRetire,
        'recommended_health'  => $recommendedHealth,
        'health_shortfall'    => $healthShortfall,
    ]);
    exit;
}

json_response(false, 'Unknown insurance action.', [], 400);
