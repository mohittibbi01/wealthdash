<?php
/**
 * WealthDash — t470: EPF vs NPS vs PPF — Side-by-Side Comparison
 *
 * Actions (read — CSRF exempt):
 *   epf_nps_ppf_current     — actual balances + contributions from DB
 *   epf_nps_ppf_projector   — future projector: given inputs, simulate all 3 till retirement
 *   epf_nps_ppf_tax_compare — tax efficiency comparison for current FY
 *   epf_nps_ppf_liquidity   — liquidity + withdrawal rules comparison
 *   epf_nps_ppf_full        — all 4 sections combined
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'epf_nps_ppf_full';

// ── RATES & CONSTANTS ──────────────────────────────────────────────────────
const CMP_EPF_RATE        = 8.25;
const CMP_NPS_EQUITY_RATE = 11.0;   // 10yr avg aggressive equity NPS
const CMP_NPS_MIXED_RATE  = 9.0;    // moderate (50% equity)
const CMP_PPF_RATE        = 7.1;
const CMP_INFLATION       = 6.0;    // assumed long-term inflation
const CMP_EPF_EE_PCT      = 12.0;
const CMP_EPF_ER_PCT      = 3.67;
const CMP_EPF_EPS_PCT     = 8.33;
const CMP_EPF_EPS_CAP     = 15000;
const CMP_PPF_MAX         = 150000;
const CMP_NPS_ANNUITY_PCT = 40;     // 40% compulsory annuity at retirement
const CMP_ANNUITY_RATE    = 6.0;    // conservative annuity rate

// ── HELPERS ────────────────────────────────────────────────────────────────

function _cmp_fy(): array {
    $yr = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    return [$yr . '-04-01', ($yr + 1) . '-03-31', $yr . '-' . ($yr + 1)];
}

function _cmp_portfolio_id(int $userId): int {
    return (int)(DB::fetchVal("SELECT id FROM portfolios WHERE user_id=? LIMIT 1", [$userId]) ?? 0);
}

/**
 * Compound interest (annual, monthly contributions)
 * Returns closing balance after N years
 */
function _cmp_fv(float $opening, float $monthlyContrib, float $annualRate, int $years): float {
    $mr  = $annualRate / 1200;
    $bal = $opening;
    for ($y = 0; $y < $years; $y++) {
        for ($m = 0; $m < 12; $m++) {
            $bal = ($bal + $monthlyContrib) * (1 + $mr);
        }
    }
    return round($bal, 2);
}

/**
 * EPF corpus: EPFO formula — annual interest on monthly running balances
 */
function _cmp_epf_fv(float $opening, float $monthlyCredit, float $annualRate, int $years): float {
    $bal = $opening;
    for ($y = 0; $y < $years; $y++) {
        $sumBals = 0;
        $b = $bal;
        for ($m = 0; $m < 12; $m++) {
            $b += $monthlyCredit;
            $sumBals += $b;
        }
        $interest = $sumBals * ($annualRate / 1200);
        $bal = $b + $interest;
    }
    return round($bal, 2);
}

/**
 * Year-by-year projection array for chart
 */
function _cmp_yearly(float $opening, float $monthly, float $annualRate, int $years, callable $fvFn): array {
    $data = [];
    $bal  = $opening;
    for ($y = 1; $y <= $years; $y++) {
        $bal    = $fvFn($bal, $monthly, $annualRate, 1);
        $data[] = ['year' => $y, 'balance' => $bal];
    }
    return $data;
}

// ── ACTIONS ────────────────────────────────────────────────────────────────

switch ($action) {

    // ── epf_nps_ppf_current ────────────────────────────────────────────────
    case 'epf_nps_ppf_current': {
        $portfolioId = _cmp_portfolio_id($userId);
        [$fyStart, $fyEnd, $fyLabel] = _cmp_fy();

        // ── EPF ──
        $epfAccounts = DB::fetchAll(
            "SELECT ea.id, ea.employer_name, ea.uan, ea.current_balance, ea.eps_balance,
                    ea.employee_contribution, ea.employer_contribution, ea.basic_salary,
                    ea.vpf_rate, ea.interest_rate, ea.joining_date
             FROM epf_accounts ea
             JOIN portfolios p ON p.id = ea.portfolio_id
             WHERE p.user_id=? AND ea.is_active=1",
            [$userId]
        );

        $epfBalance     = 0;
        $epfEpsBalance  = 0;
        $epfMonthly     = 0;
        $epfAnnualEe    = 0;
        $epfAnnualEr    = 0;
        $epfAvgRate     = CMP_EPF_RATE;

        foreach ($epfAccounts as $a) {
            $basic      = (float)$a['basic_salary'];
            $vpfPct     = (float)($a['vpf_rate'] ?? 0);
            $eeMo       = (float)$a['employee_contribution'] ?: round($basic * 0.12, 2);
            $vpfMo      = round($basic * $vpfPct / 100, 2);
            $erMo       = (float)$a['employer_contribution'] ?: round($basic * 0.0367, 2);
            $epfBalance    += (float)$a['current_balance'];
            $epfEpsBalance += (float)$a['eps_balance'];
            $epfMonthly    += $eeMo + $vpfMo + $erMo;
            $epfAnnualEe   += ($eeMo + $vpfMo) * 12;
            $epfAnnualEr   += $erMo * 12;
            $epfAvgRate     = (float)($a['interest_rate'] ?: CMP_EPF_RATE);
        }

        // ── NPS ──
        $npsHoldings = DB::fetchAll(
            "SELECT nh.id, nh.scheme_name, nh.tier, nh.current_value,
                    nh.invested_amount, nh.pran_number,
                    (SELECT COALESCE(SUM(nt.amount),0) FROM nps_transactions nt
                     WHERE nt.portfolio_id=nh.portfolio_id
                       AND nt.transaction_date BETWEEN ? AND ?
                       AND nt.transaction_type='contribution') AS fy_contrib
             FROM nps_holdings nh
             JOIN portfolios p ON p.id = nh.portfolio_id
             WHERE p.user_id=? AND nh.is_active=1",
            [$fyStart, $fyEnd, $userId]
        );

        $npsBalance   = array_sum(array_column($npsHoldings, 'current_value'));
        $npsFyContrib = array_sum(array_column($npsHoldings, 'fy_contrib'));
        $npsMonthly   = round($npsFyContrib / 12, 2);
        $npsTier1     = array_filter($npsHoldings, fn($h) => strtoupper($h['tier']) === 'T1');
        $npsTier2     = array_filter($npsHoldings, fn($h) => strtoupper($h['tier']) === 'T2');

        // ── PPF ──
        $ppfAccounts = DB::fetchAll(
            "SELECT po.id, po.holder_name, po.account_number, po.principal,
                    po.interest_rate, po.opening_date, po.maturity_date,
                    (SELECT COALESCE(d.total_deposited, 0)
                     FROM ppf_fy_deposits d
                     WHERE d.ppf_scheme_id=po.id AND d.fy_year=YEAR(CURDATE())
                       - IF(MONTH(CURDATE()) >= 4, 0, 1)) AS fy_deposited
             FROM po_schemes po
             JOIN portfolios p ON p.id = po.portfolio_id
             WHERE p.user_id=? AND LOWER(po.scheme_type)='ppf' AND po.status='active'",
            [$userId]
        );

        $ppfBalance    = array_sum(array_column($ppfAccounts, 'principal'));
        $ppfFyDeposit  = array_sum(array_column($ppfAccounts, 'fy_deposited'));
        $ppfMonthly    = round($ppfFyDeposit / 12, 2) ?: round(CMP_PPF_MAX / 12, 2);
        $ppfRate       = count($ppfAccounts) ? (float)$ppfAccounts[0]['interest_rate'] : CMP_PPF_RATE;

        json_response(true, '', [
            'fy'  => $fyLabel,
            'epf' => [
                'balance'          => round($epfBalance, 2),
                'eps_balance'      => round($epfEpsBalance, 2),
                'total'            => round($epfBalance + $epfEpsBalance, 2),
                'monthly_credit'   => round($epfMonthly, 2),
                'annual_ee'        => round($epfAnnualEe, 2),
                'annual_er'        => round($epfAnnualEr, 2),
                'interest_rate'    => $epfAvgRate,
                'accounts_count'   => count($epfAccounts),
                'accounts'         => $epfAccounts,
            ],
            'nps' => [
                'balance'          => round($npsBalance, 2),
                'monthly_contrib'  => round($npsMonthly, 2),
                'fy_contribution'  => round($npsFyContrib, 2),
                'tier1_balance'    => round(array_sum(array_column(array_values($npsTier1), 'current_value')), 2),
                'tier2_balance'    => round(array_sum(array_column(array_values($npsTier2), 'current_value')), 2),
                'holdings_count'   => count($npsHoldings),
            ],
            'ppf' => [
                'balance'          => round($ppfBalance, 2),
                'monthly_deposit'  => round($ppfMonthly, 2),
                'fy_deposited'     => round($ppfFyDeposit, 2),
                'fy_remaining'     => round(max(0, CMP_PPF_MAX - $ppfFyDeposit), 2),
                'interest_rate'    => $ppfRate,
                'accounts_count'   => count($ppfAccounts),
            ],
            'combined_retirement_corpus' => round($epfBalance + $npsBalance + $ppfBalance, 2),
            'combined_monthly_savings'   => round($epfMonthly + $npsMonthly + $ppfMonthly, 2),
        ]);
    }

    // ── epf_nps_ppf_projector ──────────────────────────────────────────────
    case 'epf_nps_ppf_projector': {
        $currentAge     = max(18, min(65, (int)($_GET['current_age']    ?? 30)));
        $retirementAge  = max($currentAge + 1, min(70, (int)($_GET['retirement_age'] ?? 60)));
        $years          = $retirementAge - $currentAge;

        // EPF inputs
        $epfOpening     = (float)($_GET['epf_balance']      ?? 0);
        $epfMonthly     = (float)($_GET['epf_monthly']      ?? 5000);  // employee+employer
        $epfRate        = (float)($_GET['epf_rate']         ?? CMP_EPF_RATE);

        // NPS inputs
        $npsOpening     = (float)($_GET['nps_balance']      ?? 0);
        $npsMonthly     = (float)($_GET['nps_monthly']      ?? 5000);
        $npsProfile     = clean($_GET['nps_profile']        ?? 'moderate'); // aggressive|moderate|conservative
        $npsRate        = $npsProfile === 'aggressive' ? CMP_NPS_EQUITY_RATE
                        : ($npsProfile === 'conservative' ? 8.5 : CMP_NPS_MIXED_RATE);

        // PPF inputs
        $ppfOpening     = (float)($_GET['ppf_balance']      ?? 0);
        $ppfMonthly     = min(CMP_PPF_MAX / 12, (float)($_GET['ppf_monthly'] ?? 12500));
        $ppfRate        = (float)($_GET['ppf_rate']         ?? CMP_PPF_RATE);

        if ($years <= 0) json_response(false, 'Retirement age must be greater than current age.');

        // ── EPF Projection ──
        $epfFinal   = _cmp_epf_fv($epfOpening, $epfMonthly, $epfRate, $years);
        $epfYearly  = _cmp_yearly($epfOpening, $epfMonthly, $epfRate, $years, '_cmp_epf_fv');

        // EPS estimate
        $basicEst   = (float)($_GET['basic_salary'] ?? 30000);
        $epsMonthly = round(min($basicEst, CMP_EPF_EPS_CAP) * $years / 70);

        // EPF tax at withdrawal: tax-free if >5 yrs service (assume yes)
        $epfNetWithdrawal = $epfFinal; // Tax-free
        $epfTaxNote       = 'Tax-free (5+ years service assumed)';

        // ── NPS Projection ──
        $npsFinal   = _cmp_fv($npsOpening, $npsMonthly, $npsRate, $years);
        $npsYearly  = _cmp_yearly($npsOpening, $npsMonthly, $npsRate, $years, '_cmp_fv');

        $npsAnnuityCorpus   = round($npsFinal * CMP_NPS_ANNUITY_PCT / 100, 2);
        $npsLumpSum         = round($npsFinal - $npsAnnuityCorpus, 2);
        $npsMonthlyPension  = round($npsAnnuityCorpus * CMP_ANNUITY_RATE / 1200, 2);
        $npsLumpSumTax      = 0; // 60% lump sum from NPS is tax-free
        $npsNetWithdrawal   = $npsLumpSum; // Tax-free
        $npsTaxNote         = '60% lump sum tax-free; 40% annuity taxable as salary';

        // ── PPF Projection ──
        // PPF tenure: 15yr + 5yr extensions — simulate till retirement
        $ppfFinal  = _cmp_fv($ppfOpening, $ppfMonthly, $ppfRate, $years);
        $ppfYearly = _cmp_yearly($ppfOpening, $ppfMonthly, $ppfRate, $years, '_cmp_fv');

        $ppfNetWithdrawal = $ppfFinal; // Fully tax-free (EEE)
        $ppfTaxNote       = 'Fully tax-free (EEE — exempt-exempt-exempt)';

        // ── Summary Table ──
        $totalMonthly = $epfMonthly + $npsMonthly + $ppfMonthly;
        $totalInvested = $totalMonthly * 12 * $years;

        // Real return (inflation-adjusted corpus)
        $inflationFactor = pow(1 + CMP_INFLATION / 100, $years);
        $epfReal  = round($epfFinal / $inflationFactor, 2);
        $npsReal  = round($npsFinal / $inflationFactor, 2);
        $ppfReal  = round($ppfFinal / $inflationFactor, 2);

        json_response(true, '', [
            'inputs' => [
                'current_age'     => $currentAge,
                'retirement_age'  => $retirementAge,
                'years'           => $years,
                'epf_monthly'     => $epfMonthly,
                'nps_monthly'     => $npsMonthly,
                'ppf_monthly'     => $ppfMonthly,
                'nps_profile'     => $npsProfile,
                'total_monthly'   => round($totalMonthly, 2),
                'total_invested'  => round($totalInvested, 2),
            ],
            'epf' => [
                'final_corpus'      => $epfFinal,
                'real_corpus'       => $epfReal,
                'net_withdrawal'    => $epfNetWithdrawal,
                'tax_note'          => $epfTaxNote,
                'rate_used'         => $epfRate,
                'eps_monthly_pension' => $epsMonthly,
                'yearly'            => $epfYearly,
            ],
            'nps' => [
                'final_corpus'      => $npsFinal,
                'real_corpus'       => $npsReal,
                'annuity_corpus'    => $npsAnnuityCorpus,
                'lump_sum'          => $npsLumpSum,
                'monthly_pension'   => $npsMonthlyPension,
                'net_withdrawal'    => $npsNetWithdrawal,
                'tax_note'          => $npsTaxNote,
                'rate_used'         => $npsRate,
                'profile'           => $npsProfile,
                'yearly'            => $npsYearly,
            ],
            'ppf' => [
                'final_corpus'      => $ppfFinal,
                'real_corpus'       => $ppfReal,
                'net_withdrawal'    => $ppfNetWithdrawal,
                'tax_note'          => $ppfTaxNote,
                'rate_used'         => $ppfRate,
                'yearly'            => $ppfYearly,
            ],
            'winner' => [
                'highest_corpus'       => max($epfFinal, $npsFinal, $ppfFinal) === $epfFinal ? 'EPF'
                                        : (max($epfFinal, $npsFinal, $ppfFinal) === $npsFinal ? 'NPS' : 'PPF'),
                'highest_real_return'  => max($epfReal, $npsReal, $ppfReal) === $npsReal ? 'NPS'
                                        : (max($epfReal, $npsReal, $ppfReal) === $epfReal ? 'EPF' : 'PPF'),
                'most_tax_efficient'   => 'PPF',  // EEE status
                'most_liquid'          => 'PPF',  // partial withdrawal after 5yr
                'highest_pension'      => 'NPS',  // guaranteed monthly pension component
            ],
            'inflation_note'  => "Real corpus = nominal ÷ {$inflationFactor} (inflation {CMP_INFLATION}% for {$years}yr)",
        ]);
    }

    // ── epf_nps_ppf_tax_compare ────────────────────────────────────────────
    case 'epf_nps_ppf_tax_compare': {
        $income  = max(0, (float)($_GET['income']  ?? 1000000)); // Annual income
        $basic   = max(0, (float)($_GET['basic']   ?? 30000));   // Monthly basic
        $age     = max(18, (int)($_GET['age']      ?? 35));
        $vpfRate = max(0, (float)($_GET['vpf_rate'] ?? 0));

        // ── 80C bucket (₹1.5L limit) ──
        $eeMonthly    = round($basic * 0.12, 2);
        $vpfMonthly   = round($basic * $vpfRate / 100, 2);
        $annualEe     = ($eeMonthly + $vpfMonthly) * 12;
        $epf80c       = min($annualEe, 150000);   // EPF 80C limited to ₹1.5L
        $ppf80c       = 150000;                   // Max PPF deposit = ₹1.5L
        $nps80c       = 150000;                   // NPS 80CCD(1) within 80C ₹1.5L

        // ── NPS exclusive 80CCD(1B) — ₹50,000 EXTRA ──
        $npsExtra80ccd1b = 50000;

        // ── Tax saving comparison (at various slabs) ──
        $slabs = [
            ['rate' => 5,  'label' => '5% slab',  'min' => 300000, 'max' => 600000],
            ['rate' => 10, 'label' => '10% slab', 'min' => 600000, 'max' => 900000],
            ['rate' => 15, 'label' => '15% slab', 'min' => 900000, 'max' => 1200000],
            ['rate' => 20, 'label' => '20% slab', 'min' => 1200000,'max' => 1500000],
            ['rate' => 30, 'label' => '30% slab', 'min' => 1500000,'max' => PHP_INT_MAX],
        ];

        $applicableSlab = 30;
        foreach ($slabs as $s) {
            if ($income >= $s['min'] && $income < $s['max']) {
                $applicableSlab = $s['rate'];
                break;
            }
        }

        $taxSaving80c     = round(150000 * $applicableSlab / 100, 2);
        $taxSavingNpsExtra= round($npsExtra80ccd1b * $applicableSlab / 100, 2);
        $taxSavingTotal   = $taxSaving80c + $taxSavingNpsExtra;

        // ── Withdrawal tax ──
        $withdrawalRules = [
            'epf' => [
                'label'              => 'EPF',
                'tax_on_contribution'=> 'Employee EPF — 80C deduction (within ₹1.5L)',
                'tax_on_interest'    => 'Tax-free (unless contribution > ₹2.5L/yr — Budget 2021)',
                'tax_on_withdrawal'  => 'Tax-free after 5 years service',
                'tax_category'       => 'EEE (mostly)',
                'caveat'             => 'Interest taxable on excess contribution; premature < 5yr taxable',
            ],
            'nps' => [
                'label'              => 'NPS',
                'tax_on_contribution'=> '80CCD(1) within ₹1.5L + exclusive 80CCD(1B) ₹50K extra',
                'tax_on_interest'    => 'Tax-deferred (no tax till withdrawal)',
                'tax_on_withdrawal'  => '60% lump sum tax-free; 40% annuity taxable as salary income',
                'tax_category'       => 'EET (Exempt-Exempt-Taxable on 40%)',
                'caveat'             => 'Annuity income taxable as salary every year',
            ],
            'ppf' => [
                'label'              => 'PPF',
                'tax_on_contribution'=> '80C deduction (within ₹1.5L)',
                'tax_on_interest'    => 'Completely tax-free',
                'tax_on_withdrawal'  => 'Completely tax-free (maturity + partial)',
                'tax_category'       => 'EEE (full)',
                'caveat'             => 'Max ₹1.5L/yr investment limit; 15yr lock-in',
            ],
        ];

        // ── Optimal strategy ──
        $strategies = [];

        // Step 1: Max 80C via EPF (automatic if salaried)
        $strategies[] = [
            'priority' => 1,
            'step'     => 'EPF employee contribution (automatic)',
            'amount'   => round(min($annualEe, 150000)),
            'section'  => '80C',
            'tax_save' => round(min($annualEe, 150000) * $applicableSlab / 100),
            'note'     => 'Salary se auto-deducted — no extra action',
        ];

        // Step 2: If 80C not full, add PPF
        $ppfNeeded = max(0, 150000 - min($annualEe, 150000));
        if ($ppfNeeded > 0) {
            $strategies[] = [
                'priority' => 2,
                'step'     => 'PPF deposit to fill ₹1.5L 80C limit',
                'amount'   => $ppfNeeded,
                'section'  => '80C',
                'tax_save' => round($ppfNeeded * $applicableSlab / 100),
                'note'     => 'EEE status — best for top-up if EPF below ₹1.5L',
            ];
        }

        // Step 3: NPS 80CCD(1B) exclusive deduction
        $strategies[] = [
            'priority' => 3,
            'step'     => 'NPS contribution (80CCD1B exclusive ₹50K)',
            'amount'   => 50000,
            'section'  => '80CCD(1B)',
            'tax_save' => round(50000 * $applicableSlab / 100),
            'note'     => '₹50K EXTRA deduction over 80C limit — kisi aur se nahi milta',
        ];

        // Step 4: VPF if high earner and want more
        if ($income > 1000000 && $vpfRate == 0) {
            $strategies[] = [
                'priority' => 4,
                'step'     => 'Consider VPF contribution',
                'amount'   => 0,
                'section'  => '80C (already within limit)',
                'tax_save' => 0,
                'note'     => 'Income > ₹10L — VPF ka interest tax-free (till ₹2.5L ee limit). After that NPS better hai.',
            ];
        }

        json_response(true, '', [
            'income'           => $income,
            'basic_monthly'    => $basic,
            'applicable_slab'  => $applicableSlab,
            'annual_ee_epf'    => round($annualEe, 2),
            'epf_80c_eligible' => $epf80c,
            'nps_80ccd1b_exclusive' => $npsExtra80ccd1b,
            'total_max_deduction' => 150000 + $npsExtra80ccd1b,
            'total_max_tax_save'  => $taxSavingTotal,
            'tax_breakdown'    => [
                '80c_saving'         => $taxSaving80c,
                '80ccd1b_nps_saving' => $taxSavingNpsExtra,
                'total_saving'       => $taxSavingTotal,
            ],
            'withdrawal_rules' => $withdrawalRules,
            'optimal_strategy' => $strategies,
            'comparison_table' => [
                ['feature' => 'Annual Limit',            'epf' => 'No limit (12% of basic)',    'nps' => 'No limit',               'ppf' => '₹1,50,000'],
                ['feature' => 'Interest Rate',           'epf' => CMP_EPF_RATE . '% (declared)', 'nps' => '9-12% (market linked)',  'ppf' => CMP_PPF_RATE . '% (fixed)'],
                ['feature' => 'Tax on Contribution',     'epf' => '80C (₹1.5L limit)',          'nps' => '80C + 80CCD(1B) ₹50K',  'ppf' => '80C (₹1.5L limit)'],
                ['feature' => 'Tax on Interest',         'epf' => 'EEE (below ₹2.5L limit)',   'nps' => 'Tax-deferred',           'ppf' => 'Tax-free (EEE)'],
                ['feature' => 'Tax on Withdrawal',       'epf' => 'Tax-free (5yr+)',            'nps' => '60% free, 40% taxable',  'ppf' => 'Tax-free (EEE)'],
                ['feature' => 'Lock-in',                 'epf' => 'Till retirement/leaving job','nps' => 'Till 60 (partial exit)',  'ppf' => '15 years'],
                ['feature' => 'Partial Withdrawal',      'epf' => 'Allowed (specific reasons)', 'nps' => 'Partial after 3yr',      'ppf' => 'Partial after 5yr'],
                ['feature' => 'Employer Contribution',   'epf' => 'Yes — 3.67% EPF + 8.33% EPS','nps' => 'Optional (govt: 14%)','ppf' => 'No'],
                ['feature' => 'Pension Component',       'epf' => 'EPS — ₹7,500/mo max',       'nps' => 'Yes — annuity on 40%',   'ppf' => 'No'],
                ['feature' => 'Market Risk',             'epf' => 'None (fixed rate)',          'nps' => 'Equity/debt mix',        'ppf' => 'None (fixed rate)'],
                ['feature' => 'Best For',                'epf' => 'Salaried — mandatory + VPF', 'nps' => 'Extra ₹50K deduction',  'ppf' => 'Safe, EEE, top-up savings'],
            ],
        ]);
    }

    // ── epf_nps_ppf_liquidity ──────────────────────────────────────────────
    case 'epf_nps_ppf_liquidity': {
        json_response(true, '', [
            'epf' => [
                'lock_in'             => 'Effectively till retirement or job change',
                'premature_exit'      => 'Allowed on leaving job; taxable if < 5yr service',
                'partial_withdrawal'  => [
                    'medical'        => 'Self/family medical — up to 6 months salary or employee share',
                    'housing'        => 'House purchase/construction — up to 36 months salary',
                    'education'      => 'Own/child higher education — after 7yr service',
                    'marriage'       => 'Own/sibling/children marriage — after 7yr',
                    'home_loan'      => 'Home loan repayment — after 10yr',
                ],
                'full_withdrawal'     => 'On retirement (58yr), 2 months unemployment, or death',
                'tds_on_withdrawal'   => '10% TDS if < 5yr service + < ₹50K withdrawal',
                'score'               => 7,  // out of 10
            ],
            'nps' => [
                'lock_in'             => 'Till age 60 (Tier 1); Tier 2 is liquid',
                'premature_exit'      => 'After 3yr, can exit with 80% annuity (only 20% lump sum)',
                'partial_withdrawal'  => [
                    'allowed_after'  => '3 years contribution',
                    'max_pct'        => '25% of own contributions',
                    'reasons'        => 'Medical, children education/marriage, housing, disability',
                    'max_times'      => '3 times in entire tenure',
                ],
                'full_withdrawal'     => 'At 60: 60% lump sum (tax-free) + 40% annuity',
                'tier2_note'         => 'Tier 2 — freely withdrawable, but no tax benefit',
                'score'              => 5,  // less liquid than EPF/PPF
            ],
            'ppf' => [
                'lock_in'             => '15 years (extendable in 5yr blocks)',
                'premature_exit'      => 'Closure allowed after 5yr for specific reasons (1% interest penalty)',
                'partial_withdrawal'  => [
                    'allowed_after'  => '5th year (from 7th year onward)',
                    'max_per_year'   => '50% of balance at end of 4th year preceding the year of withdrawal',
                    'frequency'      => 'Once per financial year',
                ],
                'loan_against_ppf'    => 'Loan available from 3rd to 6th year at 1% above PPF rate',
                'full_withdrawal'     => 'Full at maturity (15yr) — completely tax-free',
                'score'              => 6,
            ],
            'comparison' => [
                ['feature' => 'Emergency access',      'epf' => 'Good (specific reasons)', 'nps' => 'Limited (Tier 1)',    'ppf' => 'Moderate (after 5yr)'],
                ['feature' => 'Premature full exit',   'epf' => 'Allowed (job change)',    'nps' => 'After 3yr (80% annuity)','ppf' => 'After 5yr (penalty)'],
                ['feature' => 'Loan facility',         'epf' => 'No',                      'nps' => 'No',                 'ppf' => 'Yes (3rd-6th year)'],
                ['feature' => 'Liquidity score',       'epf' => '7/10',                    'nps' => '5/10',               'ppf' => '6/10'],
            ],
        ]);
    }

    // ── epf_nps_ppf_full ──────────────────────────────────────────────────
    case 'epf_nps_ppf_full': {
        // Try cache first
        $cacheKey = "epf_nps_ppf_{$userId}_" . date('Y-m');
        $cached   = DB::fetchVal(
            "SELECT payload FROM comparison_snapshots WHERE user_id=? AND snapshot_key=? AND expires_at > NOW()",
            [$userId, $cacheKey]
        );

        if ($cached) {
            $payload = json_decode($cached, true);
            $payload['_cached'] = true;
            json_response(true, '', $payload);
        }

        // Build each section inline (reuse the switch cases above)
        // Current balances
        ob_start();
        $_GET['action'] = 'epf_nps_ppf_current';

        // We call the sub-functions directly
        $portfolioId = _cmp_portfolio_id($userId);
        [$fyStart, $fyEnd, $fyLabel] = _cmp_fy();

        // EPF
        $epfAccounts = DB::fetchAll(
            "SELECT ea.id, ea.employer_name, ea.current_balance, ea.eps_balance,
                    ea.employee_contribution, ea.employer_contribution, ea.basic_salary,
                    ea.vpf_rate, ea.interest_rate
             FROM epf_accounts ea JOIN portfolios p ON p.id=ea.portfolio_id
             WHERE p.user_id=? AND ea.is_active=1", [$userId]
        );
        $epfBalance = array_sum(array_column($epfAccounts, 'current_balance'));
        $epfEps     = array_sum(array_column($epfAccounts, 'eps_balance'));

        // NPS
        $npsBalance = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(nh.current_value),0) FROM nps_holdings nh
             JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=? AND nh.is_active=1",
            [$userId]
        ) ?? 0);

        // PPF
        $ppfBalance = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(po.principal),0) FROM po_schemes po
             JOIN portfolios p ON p.id=po.portfolio_id
             WHERE p.user_id=? AND LOWER(po.scheme_type)='ppf' AND po.status='active'",
            [$userId]
        ) ?? 0);

        $total = $epfBalance + $epfEps + $npsBalance + $ppfBalance;

        $allocation = $total > 0 ? [
            'epf_pct' => round($epfBalance / $total * 100, 1),
            'eps_pct' => round($epfEps / $total * 100, 1),
            'nps_pct' => round($npsBalance / $total * 100, 1),
            'ppf_pct' => round($ppfBalance / $total * 100, 1),
        ] : ['epf_pct' => 0, 'eps_pct' => 0, 'nps_pct' => 0, 'ppf_pct' => 0];

        $result = [
            'fy'              => $fyLabel,
            'current_balances' => [
                'epf'   => round($epfBalance, 2),
                'eps'   => round($epfEps, 2),
                'nps'   => round($npsBalance, 2),
                'ppf'   => round($ppfBalance, 2),
                'total' => round($total, 2),
            ],
            'allocation_pct'  => $allocation,
            'accounts_count'  => [
                'epf' => count($epfAccounts),
            ],
            'feature_matrix'  => [
                ['feature' => 'Rate (FY25)',         'epf' => CMP_EPF_RATE . '%',  'nps' => '9-12%',           'ppf' => CMP_PPF_RATE . '%'],
                ['feature' => 'Tax Status',          'epf' => 'EEE (mostly)',      'nps' => 'EET (40% annuity)','ppf' => 'EEE (full)'],
                ['feature' => '80C benefit',         'epf' => '✅ Yes',            'nps' => '✅ + extra ₹50K', 'ppf' => '✅ Yes'],
                ['feature' => 'Employer share',      'epf' => '✅ 3.67%+EPS',     'nps' => 'Govt only',        'ppf' => '❌ No'],
                ['feature' => 'Market linked',       'epf' => '❌ Fixed',          'nps' => '✅ Equity/debt',   'ppf' => '❌ Fixed'],
                ['feature' => 'Pension component',   'epf' => '✅ EPS',            'nps' => '✅ Annuity',       'ppf' => '❌ No'],
                ['feature' => 'Liquidity',           'epf' => '⭐⭐⭐',           'nps' => '⭐⭐',             'ppf' => '⭐⭐⭐'],
                ['feature' => 'Return potential',    'epf' => '⭐⭐⭐',           'nps' => '⭐⭐⭐⭐⭐',      'ppf' => '⭐⭐'],
                ['feature' => 'Risk',                'epf' => 'Low',              'nps' => 'Medium-High',      'ppf' => 'Very Low'],
            ],
            'recommendation'  => [
                'salaried'  => 'EPF mandatory + max VPF till ₹2.5L → NPS ₹50K extra (80CCD1B) → PPF balance',
                'high_earner' => 'EPF auto + NPS ₹50K deduction → PPF ₹1.5L → rest in equity MF/NPS aggressive',
                'risk_averse' => 'EPF + PPF ₹1.5L + NPS conservative — all fixed rate instruments',
                'near_retirement' => 'Shift NPS to conservative. EPF continue. Dont break PPF prematurely.',
            ],
        ];

        // Cache for 1 hour
        $expires = date('Y-m-d H:i:s', time() + 3600);
        DB::run(
            "INSERT INTO comparison_snapshots (user_id, snapshot_key, payload, expires_at)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE payload=VALUES(payload), expires_at=VALUES(expires_at)",
            [$userId, $cacheKey, json_encode($result), $expires]
        );

        json_response(true, '', $result);
    }

    default:
        json_response(false, 'Unknown action: ' . htmlspecialchars($action), [], 400);
}
