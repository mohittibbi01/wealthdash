<?php
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? $_POST['portfolio_id'] ?? 0);
$portCond    = $portfolioId ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}" : "AND p.user_id = {$userId}";

if ($action === 'epf_list') {
    $rows = DB::fetchAll(
        "SELECT ea.*, p.name AS portfolio_name,
                ROUND((ea.employee_contribution + ea.employer_contribution) * 12, 2) AS annual_contribution,
                ROUND(ea.current_balance * ea.interest_rate / 100, 2) AS annual_interest,
                TIMESTAMPDIFF(YEAR, ea.joining_date, CURDATE()) AS years_of_service
         FROM epf_accounts ea
         JOIN portfolios p ON p.id = ea.portfolio_id
         WHERE 1=1 {$portCond}
         ORDER BY ea.is_active DESC, ea.employer_name ASC"
    );
    json_response(true, '', $rows);
}
if ($action === 'epf_add') {
    $pId   = (int)($_POST['portfolio_id'] ?? 0);
    if (!can_access_portfolio($pId, $userId, $isAdmin)) json_response(false, 'Access denied.');
    $basic = (float)($_POST['basic_salary'] ?? 0);
    DB::run(
        "INSERT INTO epf_accounts (portfolio_id,uan,employer_name,employee_contribution,employer_contribution,eps_contribution,basic_salary,joining_date,current_balance,eps_balance,interest_rate,notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [$pId, clean($_POST['uan']??''), clean($_POST['employer_name']??''),
         (float)($_POST['employee_contribution'] ?? round($basic*0.12,2)),
         (float)($_POST['employer_contribution'] ?? round($basic*0.0367,2)),
         (float)($_POST['eps_contribution']      ?? round($basic*0.0833,2)),
         $basic, clean($_POST['joining_date']??'')?:null,
         (float)($_POST['current_balance']??0), (float)($_POST['eps_balance']??0),
         (float)($_POST['interest_rate']??8.15), clean($_POST['notes']??'')]
    );
    json_response(true, 'EPF account added.');
}
if ($action === 'epf_update_balance') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("UPDATE epf_accounts SET current_balance=?,eps_balance=?,updated_at=NOW() WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)",
        [(float)($_POST['current_balance']??0),(float)($_POST['eps_balance']??0),$id,$userId]);
    json_response(true, 'Balance updated.');
}
if ($action === 'epf_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("UPDATE epf_accounts SET is_active=0 WHERE id=? AND portfolio_id IN (SELECT id FROM portfolios WHERE user_id=?)", [$id,$userId]);
    json_response(true, 'Deleted.');
}

// ── t339: EPF Interest Calculator ──────────────────────────────────────────
// EPFO formula: monthly running balance method
// Interest = sum of monthly running balances × (annual_rate / 1200)
// Tax flag: employee contribution > ₹2.5L/yr → interest on excess taxable
if ($action === 'epf_interest_calc') {
    $basic         = (float)($_POST['basic_salary']    ?? 0);
    $openingBal    = (float)($_POST['opening_balance'] ?? 0);
    $employeeRate  = (float)($_POST['employee_rate']   ?? 12);    // % of basic
    $employerRate  = (float)($_POST['employer_rate']   ?? 3.67);  // % of basic (EPF only)
    $annualRate    = (float)($_POST['interest_rate']   ?? 8.25);  // % p.a. current FY
    $basicGrowth   = (float)($_POST['basic_growth']    ?? 0);     // % annual increment
    $projYears     = (int)  ($_POST['proj_years']      ?? 10);
    $retirementAge = (int)  ($_POST['retirement_age']  ?? 58);
    $currentAge    = (int)  ($_POST['current_age']     ?? 30);
    $vpfRate       = (float)($_POST['vpf_rate']        ?? 0);     // VPF % of basic

    if ($basic <= 0) json_response(false, 'Basic salary required.');

    $yearsToRetire = max(1, $retirementAge - $currentAge);
    $projYears     = min(max(1, $projYears), $yearsToRetire);

    // Historical EPFO rates
    $historicalRates = [
        '2016-17'=>8.65,'2017-18'=>8.55,'2018-19'=>8.65,
        '2019-20'=>8.50,'2020-21'=>8.50,'2021-22'=>8.10,
        '2022-23'=>8.15,'2023-24'=>8.15,'2024-25'=>8.25,
    ];

    $fyData      = [];
    $runningBal  = $openingBal;
    $bSalary     = $basic;
    $currentYear = (int)date('Y');
    $currentMonth= (int)date('n');
    $startFY     = ($currentMonth >= 4) ? $currentYear : $currentYear - 1;

    for ($y = 0; $y < $projYears; $y++) {
        $fyLabel    = ($startFY + $y) . '-' . substr((string)($startFY + $y + 1), -2);
        $bSalary    = $basic * pow(1 + $basicGrowth / 100, $y);
        $empEeMo    = round($bSalary * $employeeRate / 100, 2);
        $empErMo    = round($bSalary * $employerRate / 100, 2);
        $vpfMo      = round($bSalary * $vpfRate / 100, 2);
        $totalEeMo  = $empEeMo + $vpfMo;
        $totalMo    = $totalEeMo + $empErMo;

        $rate       = $historicalRates[$fyLabel] ?? $annualRate;
        $monthlyRate= $rate / 1200;

        // Monthly running balance method (EPFO exact):
        // Contributions credited at month-end; sum all month-end balances; multiply by monthly rate.
        $bal = $runningBal;
        $sumBals = 0;
        for ($m = 0; $m < 12; $m++) {
            $bal    += $totalMo;
            $sumBals += $bal;
        }
        $fyInterest      = round($sumBals * $monthlyRate, 2);
        $annualEeContrib = $totalEeMo * 12;
        $annualErContrib = $empErMo   * 12;
        $annualTotal     = $annualEeContrib + $annualErContrib;

        // Tax on interest for contributions > ₹2.5L/yr (Budget 2021)
        $taxableContrib  = max(0, $annualEeContrib - 250000);
        $taxableInterest = 0;
        if ($taxableContrib > 0 && $annualEeContrib > 0) {
            $excessRatio     = $taxableContrib / $annualEeContrib;
            $taxableInterest = round($fyInterest * $excessRatio, 2);
        }

        $closingBal = round($runningBal + $annualTotal + $fyInterest, 2);

        $fyData[] = [
            'fy'               => $fyLabel,
            'basic_salary'     => round($bSalary),
            'emp_ee_monthly'   => $empEeMo,
            'vpf_monthly'      => $vpfMo,
            'emp_er_monthly'   => $empErMo,
            'annual_ee'        => $annualEeContrib,
            'annual_er'        => $annualErContrib,
            'annual_total'     => $annualTotal,
            'interest_rate'    => $rate,
            'opening_balance'  => round($runningBal, 2),
            'fy_interest'      => $fyInterest,
            'closing_balance'  => $closingBal,
            'taxable_contrib'  => round($taxableContrib, 2),
            'taxable_interest' => $taxableInterest,
            'tax_flag'         => $taxableContrib > 0,
        ];
        $runningBal = $closingBal;
    }

    $totalInterest  = array_sum(array_column($fyData, 'fy_interest'));
    $totalContrib   = array_sum(array_column($fyData, 'annual_total'));
    $totalEeContrib = array_sum(array_column($fyData, 'annual_ee'));
    $totalTaxIntr   = array_sum(array_column($fyData, 'taxable_interest'));
    $finalCorpus    = $runningBal;

    // EPS pension estimate: min(₹15K, basic) × service_yrs / 70
    $epsMonthly = round(min($bSalary, 15000) * $projYears / 70);

    json_response(true, '', [
        'fy_data' => $fyData,
        'summary' => [
            'opening_balance'    => $openingBal,
            'final_corpus'       => round($finalCorpus, 2),
            'total_contribution' => round($totalContrib, 2),
            'total_ee_contrib'   => round($totalEeContrib, 2),
            'total_interest'     => round($totalInterest, 2),
            'interest_pct'       => ($totalContrib + $openingBal) > 0
                                     ? round($totalInterest / ($totalContrib + $openingBal) * 100, 1) : 0,
            'proj_years'         => $projYears,
            'years_to_retire'    => $yearsToRetire,
            'total_taxable_intr' => round($totalTaxIntr, 2),
            'has_tax_risk'       => $totalTaxIntr > 0,
            'eps_monthly'        => $epsMonthly,
        ],
    ]);
}
