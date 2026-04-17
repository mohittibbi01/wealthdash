<?php
/**
 * WealthDash — t286: Schedule 112A — LTCG Equity Gains Table for ITR
 * t437: Tax P&L Statement — Complete income declaration
 * t440: Capital Gains Optimizer — Minimize tax this FY
 * Actions: tax_112a | tax_pl_statement | tax_capital_gains_optimize | tax_summary
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'tax_112a';

// FY helper
function getFY(?string $fy_param = null): array {
    if ($fy_param && preg_match('/^(\d{4})-(\d{4})$/', $fy_param, $m)) {
        return [$m[1] . '-04-01', $m[2] . '-03-31', $m[1] . '-' . $m[2]];
    }
    $yr = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    return [$yr . '-04-01', ($yr + 1) . '-03-31', $yr . '-' . ($yr + 1)];
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── SCHEDULE 112A — ITR Equity LTCG Table ─────────────────
    case 'tax_112a':
        $fy = $_GET['fy'] ?? null;
        [$fyStart, $fyEnd, $fyLabel] = getFY($fy);

        // Get all equity MF redemptions where holding > 365 days
        $transactions = DB::fetchAll(
            "SELECT
               f.isin, f.fund_name, f.fund_house,
               tr.transaction_date AS sell_date,
               tr.units AS units_sold,
               tr.nav AS sell_nav,
               tr.amount AS sale_consideration,
               mh.avg_cost_nav AS cost_nav,
               mh.first_investment_date AS buy_date,
               ROUND(tr.units * mh.avg_cost_nav, 2) AS cost_of_acquisition,
               ROUND(tr.amount - tr.units * mh.avg_cost_nav, 2) AS ltcg_gain
             FROM mf_transactions tr
             JOIN mf_holdings mh ON mh.id = tr.holding_id
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ?
               AND tr.transaction_type IN ('redeem','switch_out')
               AND tr.transaction_date BETWEEN ? AND ?
               AND f.asset_class = 'equity'
               AND DATEDIFF(tr.transaction_date, mh.first_investment_date) >= 365
             ORDER BY tr.transaction_date",
            [$userId, $fyStart, $fyEnd]
        );

        $totalSaleConsideration = array_sum(array_column($transactions, 'sale_consideration'));
        $totalCostAcquisition   = array_sum(array_column($transactions, 'cost_of_acquisition'));
        $totalLtcgGain          = array_sum(array_column($transactions, 'ltcg_gain'));

        // LTCG tax: gains above ₹1,00,000 at 10%
        $exemptLimit     = 100000;
        $taxableGain     = max(0, $totalLtcgGain - $exemptLimit);
        $estimatedTax    = $taxableGain * 0.10;
        $surchargeEduCess = $estimatedTax * 0.04; // 4% cess

        json_response(true, '', [
            'fy'               => $fyLabel,
            'transactions'     => $transactions,
            'total_count'      => count($transactions),
            'summary' => [
                'total_sale_consideration' => round($totalSaleConsideration, 2),
                'total_cost_acquisition'   => round($totalCostAcquisition, 2),
                'total_ltcg_gain'          => round($totalLtcgGain, 2),
                'exempt_limit'             => $exemptLimit,
                'taxable_gain'             => round($taxableGain, 2),
                'tax_rate_pct'             => 10,
                'estimated_tax'            => round($estimatedTax, 2),
                'cess_4pct'                => round($surchargeEduCess, 2),
                'total_tax_payable'        => round($estimatedTax + $surchargeEduCess, 2),
            ],
            'itr_note' => 'Ye data ITR-2 ke Schedule 112A mein enter karo. Har transaction ek row hai.',
        ]);

    // ── COMPLETE TAX P&L STATEMENT ─────────────────────────────
    case 'tax_pl_statement':
        $fy = $_GET['fy'] ?? null;
        [$fyStart, $fyEnd, $fyLabel] = getFY($fy);

        // Equity STCG (< 1 year)
        $stcg_equity = DB::fetchAll(
            "SELECT f.fund_name, tr.transaction_date AS sell_date, tr.units AS units,
                    tr.amount AS sale_value,
                    ROUND(tr.units * mh.avg_cost_nav, 2) AS cost,
                    ROUND(tr.amount - tr.units * mh.avg_cost_nav, 2) AS gain
             FROM mf_transactions tr
             JOIN mf_holdings mh ON mh.id = tr.holding_id
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ?
               AND tr.transaction_type IN ('redeem','switch_out')
               AND tr.transaction_date BETWEEN ? AND ?
               AND f.asset_class = 'equity'
               AND DATEDIFF(tr.transaction_date, mh.first_investment_date) < 365",
            [$userId, $fyStart, $fyEnd]
        );

        // Equity LTCG (> 1 year)
        $ltcg_equity = DB::fetchAll(
            "SELECT f.fund_name, tr.transaction_date AS sell_date, tr.units AS units,
                    tr.amount AS sale_value,
                    ROUND(tr.units * mh.avg_cost_nav, 2) AS cost,
                    ROUND(tr.amount - tr.units * mh.avg_cost_nav, 2) AS gain
             FROM mf_transactions tr
             JOIN mf_holdings mh ON mh.id = tr.holding_id
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ?
               AND tr.transaction_type IN ('redeem','switch_out')
               AND tr.transaction_date BETWEEN ? AND ?
               AND f.asset_class = 'equity'
               AND DATEDIFF(tr.transaction_date, mh.first_investment_date) >= 365",
            [$userId, $fyStart, $fyEnd]
        );

        // Debt fund gains (always slab rate post Apr 2023)
        $debt_gains = DB::fetchAll(
            "SELECT f.fund_name, tr.transaction_date AS sell_date, tr.units AS units,
                    tr.amount AS sale_value,
                    ROUND(tr.units * mh.avg_cost_nav, 2) AS cost,
                    ROUND(tr.amount - tr.units * mh.avg_cost_nav, 2) AS gain,
                    DATEDIFF(tr.transaction_date, mh.first_investment_date) AS holding_days
             FROM mf_transactions tr
             JOIN mf_holdings mh ON mh.id = tr.holding_id
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ?
               AND tr.transaction_type IN ('redeem','switch_out')
               AND tr.transaction_date BETWEEN ? AND ?
               AND f.asset_class IN ('debt','hybrid_debt')",
            [$userId, $fyStart, $fyEnd]
        );

        // FD interest this FY
        $fd_interest = DB::fetchVal(
            "SELECT COALESCE(SUM(interest_earned), 0)
             FROM fd_interest_log
             WHERE user_id = ? AND credit_date BETWEEN ? AND ?",
            [$userId, $fyStart, $fyEnd]
        ) ?? 0;

        $stcg_total  = array_sum(array_column($stcg_equity, 'gain'));
        $ltcg_total  = array_sum(array_column($ltcg_equity, 'gain'));
        $debt_total  = array_sum(array_column($debt_gains, 'gain'));

        $ltcg_taxable = max(0, $ltcg_total - 100000);

        json_response(true, '', [
            'fy'           => $fyLabel,
            'equity_stcg'  => ['transactions' => $stcg_equity, 'total_gain' => round($stcg_total,2), 'tax_rate' => '15%', 'estimated_tax' => round($stcg_total * 0.15 * 1.04, 2)],
            'equity_ltcg'  => ['transactions' => $ltcg_equity, 'total_gain' => round($ltcg_total,2), 'exempt_limit' => 100000, 'taxable_gain' => round($ltcg_taxable,2), 'tax_rate' => '10%', 'estimated_tax' => round($ltcg_taxable * 0.10 * 1.04, 2)],
            'debt_gains'   => ['transactions' => $debt_gains, 'total_gain' => round($debt_total,2), 'tax_rate' => 'Slab rate (add to income)', 'note' => 'Post Apr 2023 — no indexation, slab rate apply hoga'],
            'fd_interest'  => ['amount' => round($fd_interest,2), 'tax_rate' => 'Slab rate', 'tds_threshold' => 40000],
            'grand_total_tax_approx' => round(($stcg_total * 0.15 + $ltcg_taxable * 0.10) * 1.04, 2),
        ]);

    // ── CAPITAL GAINS OPTIMIZER ────────────────────────────────
    case 'tax_capital_gains_optimize':
        // Find opportunities to harvest gains tax-free up to ₹1L
        [$fyStart, $fyEnd, $fyLabel] = getFY(null);

        $realisedLtcg = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(tr.amount - tr.units * mh.avg_cost_nav), 0)
             FROM mf_transactions tr
             JOIN mf_holdings mh ON mh.id = tr.holding_id
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ?
               AND tr.transaction_type IN ('redeem','switch_out')
               AND tr.transaction_date BETWEEN ? AND ?
               AND f.asset_class = 'equity'
               AND DATEDIFF(tr.transaction_date, mh.first_investment_date) >= 365",
            [$userId, $fyStart, $fyEnd]
        ) ?? 0);

        $exemptRemaining = max(0, 100000 - $realisedLtcg);

        // Holdings that can be partially redeemed within exemption
        $opportunities = DB::fetchAll(
            "SELECT f.fund_name, f.isin,
                    mh.units, mh.current_value, mh.invested_amount,
                    (mh.current_value - mh.invested_amount) AS unrealised_gain,
                    mh.current_nav,
                    DATEDIFF(NOW(), mh.first_investment_date) AS holding_days
             FROM mf_holdings mh
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ? AND mh.is_active = 1
               AND f.asset_class = 'equity'
               AND DATEDIFF(NOW(), mh.first_investment_date) >= 365
               AND mh.current_value > mh.invested_amount
             ORDER BY unrealised_gain DESC",
            [$userId]
        );

        // For each fund, calculate how many units to redeem to harvest up to remaining exemption
        $harvest_plan = [];
        $remaining    = $exemptRemaining;
        foreach ($opportunities as $op) {
            if ($remaining <= 0) break;
            $gainPerUnit  = ($op['unrealised_gain'] / $op['units']);
            if ($gainPerUnit <= 0) continue;
            $unitsToHarvest = min($op['units'], $remaining / $gainPerUnit);
            $gainToHarvest  = round($unitsToHarvest * $gainPerUnit, 2);
            $remaining     -= $gainToHarvest;

            $harvest_plan[] = [
                'fund_name'           => $op['fund_name'],
                'units_to_redeem'     => round($unitsToHarvest, 3),
                'gain_to_book'        => $gainToHarvest,
                'tax_saving_vs_later' => round($gainToHarvest * 0.10, 2),
                'action'              => "₹" . number_format($gainToHarvest) . " ka gain book karo (zero tax), phir reinvest karo same fund mein",
            ];
        }

        json_response(true, '', [
            'fy'                    => $fyLabel,
            'ltcg_realised_so_far'  => round($realisedLtcg, 2),
            'exemption_limit'       => 100000,
            'exemption_remaining'   => round($exemptRemaining, 2),
            'harvest_opportunities' => $harvest_plan,
            'total_harvestable_gain'=> round(array_sum(array_column($harvest_plan, 'gain_to_book')), 2),
            'total_tax_saving'      => round(array_sum(array_column($harvest_plan, 'tax_saving_vs_later')), 2),
            'tip'                   => 'Redeem karo → same day reinvest karo → cost basis high ho jayega → future tax reduce.',
        ]);

    // ── QUICK TAX SUMMARY ──────────────────────────────────────
    case 'tax_summary':
        [$fyStart, $fyEnd, $fyLabel] = getFY($_GET['fy'] ?? null);

        $gains = DB::fetchRow(
            "SELECT
               SUM(CASE WHEN f.asset_class='equity' AND DATEDIFF(tr.transaction_date, mh.first_investment_date)>=365
                        THEN tr.amount - tr.units*mh.avg_cost_nav ELSE 0 END) AS equity_ltcg,
               SUM(CASE WHEN f.asset_class='equity' AND DATEDIFF(tr.transaction_date, mh.first_investment_date)<365
                        THEN tr.amount - tr.units*mh.avg_cost_nav ELSE 0 END) AS equity_stcg,
               SUM(CASE WHEN f.asset_class!='equity'
                        THEN tr.amount - tr.units*mh.avg_cost_nav ELSE 0 END) AS debt_gains
             FROM mf_transactions tr
             JOIN mf_holdings mh ON mh.id = tr.holding_id
             JOIN funds f ON f.id = mh.fund_id
             JOIN portfolios p ON p.id = mh.portfolio_id
             WHERE p.user_id = ?
               AND tr.transaction_type IN ('redeem','switch_out')
               AND tr.transaction_date BETWEEN ? AND ?",
            [$userId, $fyStart, $fyEnd]
        ) ?? [];

        $ltcg  = (float)($gains['equity_ltcg'] ?? 0);
        $stcg  = (float)($gains['equity_stcg'] ?? 0);
        $debt  = (float)($gains['debt_gains']  ?? 0);

        json_response(true, '', [
            'fy'              => $fyLabel,
            'equity_ltcg'     => round($ltcg, 2),
            'equity_stcg'     => round($stcg, 2),
            'debt_gains'      => round($debt, 2),
            'ltcg_exempt'     => 100000,
            'ltcg_taxable'    => round(max(0, $ltcg - 100000), 2),
            'estimated_taxes' => [
                'ltcg_tax'  => round(max(0, $ltcg - 100000) * 0.104, 2),
                'stcg_tax'  => round($stcg * 0.156, 2), // 15% + 4% cess
                'debt_note' => 'Add ₹' . number_format(round($debt,2)) . ' to income — slab rate apply',
            ],
        ]);

    default:
        json_response(false, 'Unknown tax action.', [], 400);
}
