<?php
/**
 * WealthDash — PPF + VPF + EPF Combined Retirement View API (t342)
 * Action: retirement_combined
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

if ($action === 'retirement_combined') {

    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
    $vpfRate     = (float)($_POST['vpf_rate']    ?? 0);   // % of basic
    $projYears   = max(1, min(40, (int)($_POST['proj_years']  ?? 20)));
    $growthRate  = (float)($_POST['growth_rate'] ?? 5);   // annual salary growth %

    // Portfolio filter
    $portCond = $portfolioId
        ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
        : "AND p.user_id = {$userId}";

    // ── Fetch EPF accounts ────────────────────────────────────────────────────
    $epfRows = DB::fetchAll(
        "SELECT ea.*,
                TIMESTAMPDIFF(YEAR, ea.joining_date, CURDATE()) AS years_of_service
         FROM epf_accounts ea
         JOIN portfolios p ON p.id = ea.portfolio_id
         WHERE ea.is_active = 1 {$portCond}
         ORDER BY ea.employer_name ASC"
    );

    // ── Fetch PPF accounts ────────────────────────────────────────────────────
    // po_schemes scheme_type = 'ppf' (case insensitive)
    $ppfRows = DB::fetchAll(
        "SELECT pos.*,
                COALESCE(pos.current_value, pos.principal_amount) AS balance,
                pos.annual_deposit
         FROM po_schemes pos
         JOIN portfolios p ON p.id = pos.portfolio_id
         WHERE UPPER(pos.scheme_type) = 'PPF'
           AND pos.status IN ('active','extended')
           {$portCond}
         ORDER BY pos.start_date ASC"
    );

    // Rates
    $EPF_RATE = 8.25;   // EPF interest % FY24-25
    $PPF_RATE = 7.10;   // PPF interest % current

    // ── Compute EPF side ──────────────────────────────────────────────────────
    $epfCorpus          = 0.0;
    $epfEmployeeAnnual  = 0.0;   // 80C eligible (employee share)
    $epfEmployerAnnual  = 0.0;
    $vpfAnnual          = 0.0;
    $epfMonthly         = 0.0;

    $epfAccountsOut = [];
    foreach ($epfRows as $e) {
        $basic     = (float)($e['basic_salary']           ?? 0);
        $empEeMo   = (float)($e['employee_contribution']  ?? round($basic * 0.12, 2));
        $empErMo   = (float)($e['employer_contribution']  ?? round($basic * 0.0367, 2));
        $bal       = (float)($e['current_balance']        ?? 0);
        $vpfMo     = round($basic * $vpfRate / 100, 2);

        $epfCorpus         += $bal;
        $epfEmployeeAnnual += ($empEeMo + $vpfMo) * 12;
        $epfEmployerAnnual += $empErMo * 12;
        $vpfAnnual         += $vpfMo * 12;
        $epfMonthly        += $empEeMo + $empErMo + $vpfMo;

        $epfAccountsOut[] = array_merge($e, ['vpf_monthly' => $vpfMo]);
    }

    // ── Compute PPF side ──────────────────────────────────────────────────────
    $ppfCorpus  = 0.0;
    $ppfAnnual  = 0.0;   // estimated annual deposit (for 80C)
    foreach ($ppfRows as $p) {
        $ppfCorpus += (float)($p['balance'] ?? 0);
        $ppfAnnual += (float)($p['annual_deposit'] ?? 0);
    }

    $hasData = !empty($epfRows) || !empty($ppfRows);

    if (!$hasData) {
        json_response(true, '', ['has_data' => false]);
    }

    // ── 80C calculation ───────────────────────────────────────────────────────
    $ec80Annual    = $epfEmployeeAnnual + $ppfAnnual;  // VPF included in employee annual
    $ec80Limit     = 150000;
    $ec80Remaining = $ec80Limit - $ec80Annual;

    // ── Projection ───────────────────────────────────────────────────────────
    // Assumptions:
    //   - EPF grows at 8.25%, contributions grow by growthRate% each year
    //   - PPF grows at 7.1%, contributions stable (max ₹1.5L cap)
    //   - VPF grows at EPF rate
    $projection = [];
    $epfBal  = $epfCorpus;
    $ppfBal  = $ppfCorpus;
    $vpfBal  = 0.0;  // VPF tracked separately from existing EPF balance

    // Monthly EPF+VPF employee+employer contributions
    $epfMoContrib = $epfMonthly;  // includes VPF
    $ppfMoContrib = $ppfAnnual / 12;

    for ($yr = 1; $yr <= $projYears; $yr++) {
        // Salary growth applied after year 1
        $growFactor = pow(1 + $growthRate / 100, $yr - 1);
        $epfAnnualContribThisYr = $epfMoContrib * 12 * $growFactor;
        $vpfAnnualContribThisYr = $vpfAnnual * $growFactor;
        $ppfAnnualContribThisYr = min($ppfAnnual, 150000); // PPF capped at 1.5L

        // Interest
        $epfInterest = ($epfBal + $epfAnnualContribThisYr / 2) * ($EPF_RATE / 100);
        $ppfInterest = ($ppfBal + $ppfAnnualContribThisYr / 2) * ($PPF_RATE / 100);
        $vpfInterest = ($vpfBal + $vpfAnnualContribThisYr / 2) * ($EPF_RATE / 100);

        $epfBal += $epfAnnualContribThisYr + $epfInterest;
        $ppfBal += $ppfAnnualContribThisYr + $ppfInterest;
        $vpfBal += $vpfAnnualContribThisYr + $vpfInterest;

        $combined       = $epfBal + $ppfBal + ($vpfAnnual > 0 ? 0 : 0); // VPF included in EPF
        $annualContrib  = $epfAnnualContribThisYr + $ppfAnnualContribThisYr;
        $annualInterest = $epfInterest + $ppfInterest + $vpfInterest;

        $projection[] = [
            'label'          => "Year $yr",
            'epf_balance'    => round($epfBal, 0),
            'vpf_balance'    => $vpfAnnual > 0 ? round($vpfBal, 0) : 0,
            'ppf_balance'    => round($ppfBal, 0),
            'combined'       => round($epfBal + $ppfBal, 0),
            'annual_contrib' => round($annualContrib, 0),
            'annual_interest'=> round($annualInterest, 0),
        ];
    }

    $finalProjected = end($projection)['combined'] ?? 0;

    $summary = [
        'total_corpus'       => round($epfCorpus + $ppfCorpus, 0),
        'epf_corpus'         => round($epfCorpus, 0),
        'ppf_corpus'         => round($ppfCorpus, 0),
        'epf_accounts'       => count($epfRows),
        'ppf_accounts'       => count($ppfRows),
        'monthly_contrib'    => round($epfMonthly + $ppfMoContrib, 0),
        'epf_employee_annual'=> round($epfEmployeeAnnual, 0),
        'epf_employer_annual'=> round($epfEmployerAnnual, 0),
        'vpf_annual'         => round($vpfAnnual, 0),
        'ppf_annual'         => round($ppfAnnual, 0),
        'ec80_annual'        => round($ec80Annual, 0),
        'ec80_remaining'     => round($ec80Remaining, 0),
        'projected_corpus'   => round($finalProjected, 0),
        'proj_years'         => $projYears,
    ];

    json_response(true, '', [
        'has_data'     => true,
        'summary'      => $summary,
        'epf_accounts' => $epfAccountsOut,
        'ppf_accounts' => $ppfRows,
        'projection'   => $projection,
    ]);
}
