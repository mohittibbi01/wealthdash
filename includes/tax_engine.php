<?php
/**
 * WealthDash — Tax Engine
 * LTCG / STCG / TDS / FD accrual calculations
 */
declare(strict_types=1);

class TaxEngine {

    /**
     * Calculate MF gain tax for a holding or batch of sells
     *
     * @param float  $gainAmount     Absolute gain (sell - cost)
     * @param string $purchaseDate   YYYY-MM-DD first purchase
     * @param string $sellDate       YYYY-MM-DD date of sale
     * @param string $assetType      'equity' | 'debt' | 'elss'
     * @return array ['gain_type', 'tax_rate', 'tax_amount', 'ltcg_exemption_used']
     */
    public static function mf_gain_tax(
        float  $gainAmount,
        string $purchaseDate,
        string $sellDate,
        string $assetType = 'equity',
        int    $minLtcgDays = 0   // 0 = auto-detect from assetType
    ): array {

        $days = (int) (new DateTime($sellDate))->diff(new DateTime($purchaseDate))->days;

        // Use fund-specific days if provided, otherwise fall back to asset-type constant
        if ($minLtcgDays > 0) {
            $ltcgDays = $minLtcgDays;
        } else {
            $ltcgDays = match ($assetType) {
                'debt'  => DEBT_LTCG_DAYS,
                default => EQUITY_LTCG_DAYS,  // equity, elss
            };
        }

        $isLTCG = $days >= $ltcgDays;

        if ($assetType === 'debt') {
            // Post Apr 2023: Debt gains taxed as per slab (no LTCG benefit)
            $purchaseDateObj = new DateTime($purchaseDate);
            $debtCutoff      = new DateTime('2023-04-01');
            if ($purchaseDateObj >= $debtCutoff) {
                return [
                    'gain_type'           => 'SLAB',
                    'days_held'           => $days,
                    'tax_rate'            => null,
                    'tax_amount'          => null,
                    'ltcg_exemption_used' => 0,
                ];
            }
            // Pre Apr 2023 debt funds: 20% with indexation if LTCG
            $taxRate = $isLTCG ? DEBT_LTCG_RATE : 30.0; // slab approx
        } else {
            // Equity / ELSS
            $taxRate = $isLTCG ? EQUITY_LTCG_RATE : EQUITY_STCG_RATE;
        }

        $exemptionUsed = 0;
        $taxableGain   = $gainAmount;

        if ($isLTCG && $assetType !== 'debt') {
            // LTCG exemption ₹1.25L per FY (Budget 2024)
            $exemptionUsed = min($gainAmount, EQUITY_LTCG_EXEMPTION);
            $taxableGain   = max(0, $gainAmount - $exemptionUsed);
        }

        $taxAmount = $taxableGain > 0 ? round($taxableGain * $taxRate / 100, 2) : 0;

        return [
            'gain_type'           => $isLTCG ? 'LTCG' : 'STCG',
            'days_held'           => $days,
            'tax_rate'            => $taxRate,
            'tax_amount'          => $taxAmount,
            'ltcg_exemption_used' => $exemptionUsed,
        ];
    }

    /**
     * FD interest taxability
     */
    public static function fd_tax(
        float $annualInterest,
        bool  $isSenior = false,
        bool  $form15gSubmitted = false
    ): array {
        $threshold  = $isSenior ? FD_TDS_THRESHOLD_SENIOR : FD_TDS_THRESHOLD;
        $tdsRate    = $isSenior ? FD_TDS_SENIOR_RATE : FD_TDS_RATE;
        $tdsSection = $isSenior ? '80TTB' : '80TTA';

        $tdsApplicable = !$form15gSubmitted && $annualInterest > $threshold;
        $tdsAmount     = $tdsApplicable ? round($annualInterest * $tdsRate / 100, 2) : 0;

        return [
            'annual_interest' => $annualInterest,
            'threshold'       => $threshold,
            'tds_applicable'  => $tdsApplicable,
            'tds_rate'        => $tdsRate,
            'tds_amount'      => $tdsAmount,
            'net_interest'    => $annualInterest - $tdsAmount,
            'section'         => $tdsSection,
        ];
    }

    /**
     * FD accrued interest per FY (pro-rata)
     */
    public static function fd_fy_accrual(
        float  $principal,
        float  $rateAnnual,
        string $openDate,
        string $maturityDate,
        string $fy
    ): array {
        ['start' => $fyStart, 'end' => $fyEnd] = fy_dates($fy);

        $overlapStart = max($openDate, $fyStart);
        $overlapEnd   = min($maturityDate, $fyEnd);

        if ($overlapStart > $overlapEnd) {
            return ['accrued_interest' => 0, 'days' => 0];
        }

        $days = days_between($overlapStart, $overlapEnd) + 1;
        $accrued = fd_accrued_interest($principal, $rateAnnual, $days);

        return ['accrued_interest' => $accrued, 'days' => $days];
    }

    /**
     * Savings 80TTA / 80TTB deduction
     */
    public static function savings_deduction(float $totalInterest, bool $isSenior = false): array {
        $limit     = $isSenior ? SAVINGS_80TTB_LIMIT : SAVINGS_80TTA_LIMIT;
        $section   = $isSenior ? '80TTB' : '80TTA';
        $deduction = min($totalInterest, $limit);
        $taxable   = max(0, $totalInterest - $deduction);

        return [
            'total_interest'  => $totalInterest,
            'deduction_limit' => $limit,
            'deduction'       => $deduction,
            'taxable_amount'  => $taxable,
            'section'         => $section,
        ];
    }

    /**
     * Stock gain tax (t39 — with grandfathering clause)
     * Grandfathering: For stocks bought BEFORE Jan 31 2018,
     * cost = MAX(actual cost, FMV on Jan 31 2018)
     * This limits the pre-2018 gain that's taxable.
     *
     * @param float  $gainAmount       Actual gain (proceeds - cost)
     * @param string $purchaseDate     YYYY-MM-DD first purchase
     * @param string $sellDate         YYYY-MM-DD date of sale
     * @param float  $proceedsAmount   Sell proceeds (needed to recalc gain with FMV)
     * @param float  $fmvJan2018       FMV on Jan 31 2018 (optional, 0 = no grandfathering)
     */
    public static function stock_gain_tax(
        float  $gainAmount,
        string $purchaseDate,
        string $sellDate,
        float  $proceedsAmount = 0,
        float  $fmvJan2018     = 0
    ): array {
        $GRANDFATHERING_DATE = '2018-02-01'; // Gains up to Jan 31 2018 exempt

        // Apply grandfathering if:
        // 1. Stock bought before Jan 31 2018
        // 2. FMV provided
        // 3. Proceeds > FMV (otherwise no grandfathering benefit)
        $grandfathered = false;
        $adjustedGain  = $gainAmount;

        if ($fmvJan2018 > 0
            && $purchaseDate < $GRANDFATHERING_DATE
            && $proceedsAmount > 0
        ) {
            // Effective cost = max(actual cost, FMV on Jan 31 2018)
            // But effective cost cannot exceed sell price
            $actualCost    = $proceedsAmount - $gainAmount;
            $effectiveCost = min($proceedsAmount, max($actualCost, $fmvJan2018));
            $adjustedGain  = $proceedsAmount - $effectiveCost;
            $grandfathered = ($effectiveCost > $actualCost);
        }

        $result = self::mf_gain_tax($adjustedGain, $purchaseDate, $sellDate, 'equity');
        $result['grandfathered']    = $grandfathered;
        $result['adjusted_gain']    = round($adjustedGain, 2);
        $result['fmv_jan_2018']     = $fmvJan2018;
        return $result;
    }

    /**
     * LTCG harvest suggestion:
     * How much more LTCG gain can be booked this FY within ₹1.25L limit?
     */
    public static function ltcg_remaining(float $ltcgBookedThisFy): float {
        return max(0, EQUITY_LTCG_EXEMPTION - $ltcgBookedThisFy);
    }
}