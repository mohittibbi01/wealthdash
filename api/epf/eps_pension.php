<?php
/**
 * WealthDash — EPS Pension Calculator API (t468)
 * Handles: eps_pension_calc
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

if ($action === 'eps_pension_calc') {

    // ── Inputs ────────────────────────────────────────────────────────────────
    $basicSalary        = (float)($_POST['basic_salary']        ?? 15000);
    $eps_balance        = (float)($_POST['eps_balance']         ?? 0);       // existing EPS corpus
    $serviceYears       = (float)($_POST['service_years']       ?? 20);      // total pensionable service
    $currentAge         = (int)($_POST['current_age']           ?? 35);
    $dob                = $_POST['dob']                         ?? '';
    $claimAge           = (int)($_POST['claim_age']             ?? 58);      // age at which pension claimed
    $higherPension      = (bool)($_POST['higher_pension']       ?? false);   // SC 2022 higher pension option
    $higherPensionSal   = (float)($_POST['higher_pension_salary'] ?? 0);     // actual salary if higher pension
    $spouseAge          = (int)($_POST['spouse_age']            ?? 0);       // 0 = no spouse info
    $commute            = (bool)($_POST['commute']              ?? false);   // commute 1/3rd?
    $fpServiceFraction  = (bool)($_POST['fp_service_fraction']  ?? false);   // fractional service yr bonus?
    $projYears          = (int)($_POST['proj_years']            ?? 20);      // how many years to show projection
    $pastService        = (float)($_POST['past_service']        ?? 0);       // service before 16 Nov 1995

    // ── Constants ─────────────────────────────────────────────────────────────
    $SALARY_CAP         = 15000;    // EPS pensionable salary cap
    $MIN_SERVICE_PENSION= 10;       // 10 years minimum for full pension
    $MIN_SERVICE_REDUCED= 0;        // withdrawal possible for <10 years
    $NORMAL_PENSION_AGE = 58;
    $EARLY_PENSION_AGE  = 50;
    $MAX_DEFERRED_AGE   = 60;
    $EARLY_REDUCTION    = 0.04;     // 4% per year before 58
    $DEFERRED_INCREMENT = 0.04;     // 4% per year after 58 (up to 60)
    $EPS_RATE           = 8.33;     // 8.33% of basic goes to EPS

    // ── Calculations ──────────────────────────────────────────────────────────

    // Pensionable salary
    $pensionableSalary = $higherPension && $higherPensionSal > 0
        ? min($higherPensionSal, $basicSalary)   // actual salary, no cap
        : min($basicSalary, $SALARY_CAP);

    // Pensionable service capped at 35 years; add 2-year bonus if ≥20 years
    $effService = min($serviceYears, 35);
    $serviceBonus = ($effService >= 20) ? 2 : 0;
    $pensionableService = $effService + $serviceBonus;

    // Past service factor (pre-Nov 1995 members)
    // Table-based factor (simplified linear approximation)
    $pastServiceFactor = 0;
    if ($pastService > 0) {
        // EPFO Table B factors (approximate)
        $pastFactors = [1=>2.3,2=>4.5,3=>6.7,4=>8.8,5=>10.8,6=>12.7,7=>14.5,8=>16.2,9=>17.7,10=>19.3,
                        11=>20.7,12=>22.0,13=>23.2,14=>24.2,15=>25.1,16=>25.8,17=>26.3,18=>26.8,19=>27.1,20=>27.4];
        $psYr = min(20, (int)$pastService);
        $pastServiceFactor = $pastFactors[$psYr] ?? ($psYr * 1.37);
    }

    // Base monthly pension (EPFO formula)
    $basePension = ($pensionableSalary * $pensionableService / 70) + ($pastServiceFactor / 70 * $pensionableSalary / 10);
    $basePension = max(1000, round($basePension, 2)); // Minimum pension ₹1000

    // Early / deferred adjustment
    $claimAge = max($EARLY_PENSION_AGE, min($MAX_DEFERRED_AGE, $claimAge));
    $ageDiff  = $claimAge - $NORMAL_PENSION_AGE;
    $adjustedPension = $basePension;

    if ($ageDiff < 0) {
        // Early pension: reduce 4% per year
        $adjustedPension = $basePension * pow(1 - $EARLY_REDUCTION, abs($ageDiff));
    } elseif ($ageDiff > 0) {
        // Deferred pension: increase 4% per year
        $adjustedPension = $basePension * pow(1 + $DEFERRED_INCREMENT, $ageDiff);
    }
    $adjustedPension = max(1000, round($adjustedPension, 2));

    // Commutation: lump sum for 1/3rd of pension (if requested, age ≤ 58)
    $commutedAmt   = 0;
    $residualPension = $adjustedPension;
    if ($commute && $claimAge <= $NORMAL_PENSION_AGE) {
        // EPFO commutation table: factor ~8.194 at age 58
        // Approximate factor based on age
        $commFactors = [50=>10.48,51=>10.13,52=>9.78,53=>9.43,54=>9.09,55=>8.74,56=>8.40,57=>8.17,58=>8.19];
        $commFactor  = $commFactors[$claimAge] ?? 8.19;
        $commutedPension  = $adjustedPension / 3;
        $commutedAmt      = round($commutedPension * 12 * $commFactor, 0);
        $residualPension  = $adjustedPension - $commutedPension;
        // Commutation reverts after 15 years
    }

    // Family pension (spouse / nominee) = 50% of member pension (min ₹1000)
    $familyPension   = max(1000, round($adjustedPension * 0.5, 2));
    $orphanPension   = max(750, round($adjustedPension * 0.25, 2));  // 25% to orphan
    $disabledPension = max(750, round($adjustedPension * 0.75, 2)); // 75% to disabled

    // EPS Withdrawal (if service < 10 years)
    $epsWithdrawal = null;
    if ($serviceYears < $MIN_SERVICE_PENSION) {
        // EPS withdrawal table (scheme certificate amounts)
        // Approximate: eps_balance or formula based
        $withdrawalFactor = [1=>1.02,2=>2.08,3=>3.18,4=>4.34,5=>5.54,6=>6.80,7=>8.10,8=>9.45,9=>10.86];
        $yr = (int)min(9, $serviceYears);
        $factor = $withdrawalFactor[$yr] ?? $yr * 1.1;
        $epsWithdrawal = $eps_balance > 0
            ? $eps_balance
            : round(min($pensionableSalary, $SALARY_CAP) * $factor, 0);
        $epsWithdrawal = max($epsWithdrawal, 0);
    }

    // Monthly contribution to EPS (for reference)
    $monthlyEPS = min($basicSalary, $SALARY_CAP) * ($EPS_RATE / 100);

    // ── Pension Projection Table ───────────────────────────────────────────────
    $pensionProjection = [];
    $totalLifetimePension = 0;
    $breakEvenYears = null;
    $cumulative = 0;

    for ($i = 1; $i <= $projYears; $i++) {
        $inflation     = 0; // EPS pension is fixed (no DA/inflation indexation)
        $annualPension = $residualPension * 12;
        $cumulative   += $annualPension;
        $totalLifetimePension += $annualPension;

        // Break-even: years until cumulative pension > commuted lump sum
        if ($commute && $breakEvenYears === null && $commutedAmt > 0 && $cumulative >= $commutedAmt) {
            $breakEvenYears = $i;
        }

        $pensionProjection[] = [
            'year'           => "Year $i (" . ($claimAge + $i - 1) . ")",
            'age'            => $claimAge + $i - 1,
            'monthly_pension'=> round($residualPension, 0),
            'annual_pension' => round($annualPension, 0),
            'cumulative'     => round($cumulative, 0),
        ];
    }

    // ── Higher Pension Comparison ─────────────────────────────────────────────
    $higherPensionComparison = null;
    if (!$higherPension && $basicSalary > $SALARY_CAP) {
        // What if higher pension was opted?
        $hpPensionableSal = $basicSalary;
        $hpBasePension    = ($hpPensionableSal * $pensionableService / 70);
        $hpPension        = max(1000, round($hpBasePension, 2));

        // Higher pension = higher EPS contribution (8.33% of actual salary vs capped)
        $extraMonthlyContrib = ($basicSalary - $SALARY_CAP) * ($EPS_RATE / 100);
        $extraAnnualContrib  = $extraMonthlyContrib * 12;

        $higherPensionComparison = [
            'normal_pension'       => $basePension,
            'higher_pension'       => $hpPension,
            'extra_monthly_pension'=> round($hpPension - $basePension, 0),
            'extra_annual_contrib' => round($extraAnnualContrib, 0),
            'payback_years'        => $extraAnnualContrib > 0
                ? round($extraAnnualContrib * $serviceYears / (($hpPension - $basePension) * 12), 1)
                : null,
        ];
    }

    // ── Summary ───────────────────────────────────────────────────────────────
    $summary = [
        'pensionable_salary'     => $pensionableSalary,
        'pensionable_service'    => $pensionableService,
        'service_bonus'          => $serviceBonus,
        'base_pension'           => $basePension,
        'adjusted_pension'       => $adjustedPension,
        'residual_pension'       => $residualPension,
        'commuted_lump_sum'      => $commutedAmt,
        'commute_breakeven_yrs'  => $breakEvenYears,
        'family_pension'         => $familyPension,
        'orphan_pension'         => $orphanPension,
        'disabled_pension'       => $disabledPension,
        'claim_age'              => $claimAge,
        'age_adjustment_pct'     => round($ageDiff * 4, 1), // negative = reduction
        'eps_withdrawal'         => $epsWithdrawal,
        'pension_eligible'       => $serviceYears >= $MIN_SERVICE_PENSION,
        'monthly_eps_contrib'    => round($monthlyEPS, 0),
        'total_proj_pension'     => round($totalLifetimePension, 0),
        'proj_years'             => $projYears,
        'higher_pension_eligible'=> $basicSalary > $SALARY_CAP,
        'salary_capped'          => !$higherPension && $basicSalary > $SALARY_CAP,
    ];

    json_response(true, '', [
        'summary'                  => $summary,
        'projection'               => $pensionProjection,
        'higher_pension_comparison'=> $higherPensionComparison,
    ]);
}
