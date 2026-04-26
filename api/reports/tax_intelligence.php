<?php
/**
 * WealthDash — Tax Intelligence API
 * t287: Debt Fund Tax — slab rate (post Apr 2023) vs old LTCG
 * t290: LTCG Harvesting Scheduler — when to book gains optimally
 * Actions: debt_fund_tax | ltcg_harvest_schedule
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'ltcg_harvest_schedule';
$db          = DB::conn();

// ── FY helpers ─────────────────────────────────────────────────────
function fyStart(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y : $y-1) . '-04-01';
}
function fyEnd(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y+1 : $y) . '-03-31';
}
function currentFyLabel(): string {
    $m=(int)date('n');$y=(int)date('Y');
    $s=$m>=4?$y:$y-1;
    return "FY $s-".($s+1);
}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════
// t287: debt_fund_tax
// Categorise debt fund holdings by purchase date vs Apr 1 2023 cutoff
// Pre-cutoff: old LTCG rules (20% + indexation if >3Y)
// Post-cutoff: slab rate regardless of holding
// ══════════════════════════════════════════════════════════════════════
case 'debt_fund_tax':
    $pid = (int)($_POST['portfolio_id'] ?? 0);
    if (!$pid) {
        $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
        $r->execute([$userId]);
        $pid = (int)($r->fetchColumn() ?: 0);
    }
    $taxSlab = (float)($_POST['tax_slab'] ?? 0.30); // 30% default

    $DEBT_CATEGORIES = ['Debt','Liquid','Money Market','Credit Risk','Banking & PSU',
                        'Gilt','Corporate Bond','Short Duration','Medium Duration','Ultra Short Duration',
                        'Low Duration','Overnight','Floater','Dynamic Bond','Medium to Long Duration'];

    $in = implode(',', array_fill(0, count($DEBT_CATEGORIES), '?'));
    $stmt = $db->prepare("
        SELECT
          f.fund_name, f.category, f.latest_nav,
          mh.id AS holding_id, mh.invested_value, mh.latest_value, mh.units,
          (SELECT MIN(t.tx_date) FROM mf_transactions t
           WHERE t.holding_id=mh.id AND t.tx_type IN ('buy','sip','lumpsum')) AS first_buy_date,
          (SELECT AVG(t.price_per_unit) FROM mf_transactions t
           WHERE t.holding_id=mh.id AND t.tx_type IN ('buy','sip','lumpsum')) AS avg_cost_nav
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id=? AND mh.units > 0.001
          AND f.category IN ($in)
        ORDER BY mh.latest_value DESC
    ");
    $stmt->execute(array_merge([$pid], $DEBT_CATEGORIES));
    $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $CUTOFF = '2023-04-01';

    $result = [];
    foreach ($holdings as $h) {
        $buyDate    = $h['first_buy_date'];
        $latestVal  = (float)$h['latest_value'];
        $invested   = (float)$h['invested_value'];
        $gain       = $latestVal - $invested;
        $holdingDays= $buyDate ? (int)((time() - strtotime($buyDate)) / 86400) : 0;
        $isPreCutoff= $buyDate && $buyDate < $CUTOFF;

        $taxRate    = null;
        $taxNote    = '';
        $taxAmount  = 0;

        if ($gain > 0) {
            if ($isPreCutoff && $holdingDays > 1095) {
                // Pre-Apr2023 AND held >3Y: 20% with indexation benefit
                // Rough indexation factor (CII 2020→2024 ~1.15)
                $indexationFactor = 1.15;
                $indexedCost = $invested * $indexationFactor;
                $indexedGain = max(0, $latestVal - $indexedCost);
                $taxRate  = 0.20;
                $taxAmount = $indexedGain * 0.20 * 1.04; // +4% cess
                $taxNote  = "Old LTCG — 20% with indexation (pre-Apr 2023 purchase, >3Y hold)";
            } elseif ($isPreCutoff && $holdingDays <= 1095) {
                // Pre-Apr2023 but <3Y: slab rate
                $taxRate  = $taxSlab;
                $taxAmount = $gain * $taxSlab * 1.04;
                $taxNote  = "Slab rate — pre-Apr 2023 purchase but held <3Y";
            } else {
                // Post-Apr2023 (any holding period): slab rate
                $taxRate  = $taxSlab;
                $taxAmount = $gain * $taxSlab * 1.04;
                $taxNote  = "Slab rate — post Apr 2023 amendment (Finance Act 2023)";
            }
        }

        $result[] = [
            'fund_name'       => $h['fund_name'],
            'category'        => $h['category'],
            'first_buy_date'  => $buyDate,
            'holding_days'    => $holdingDays,
            'is_pre_cutoff'   => $isPreCutoff,
            'invested_value'  => round($invested),
            'latest_value'    => round($latestVal),
            'unrealised_gain' => round($gain),
            'gain_pct'        => $invested > 0 ? round($gain/$invested*100,2) : 0,
            'tax_rate_pct'    => $taxRate !== null ? round($taxRate*100,1) : null,
            'estimated_tax'   => round($taxAmount),
            'tax_note'        => $taxNote,
            'regime'          => $isPreCutoff && $holdingDays>1095 ? 'old_ltcg_indexation' : 'slab',
        ];
    }

    $totalTax  = array_sum(array_column($result, 'estimated_tax'));
    $totalGain = array_sum(array_column($result, 'unrealised_gain'));

    echo json_encode(['success'=>true,'data'=>[
        'holdings'       => $result,
        'total_gain'     => round($totalGain),
        'total_est_tax'  => round($totalTax),
        'tax_slab_used'  => round($taxSlab*100).'%',
        'cutoff_date'    => $CUTOFF,
        'summary'        => "Finance Act 2023: Debt MF purchased on/after $CUTOFF — slab rate regardless of holding period. Pre-$CUTOFF + 3Y hold = old 20%+indexation rules.",
    ]]);
    break;

// ══════════════════════════════════════════════════════════════════════
// t290: ltcg_harvest_schedule
// Which funds to partially redeem to use ₹1.25L LTCG exemption this FY
// ══════════════════════════════════════════════════════════════════════
case 'ltcg_harvest_schedule':
    $pid = (int)($_POST['portfolio_id'] ?? 0);
    if (!$pid) {
        $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
        $r->execute([$userId]);
        $pid = (int)($r->fetchColumn() ?: 0);
    }

    $LTCG_EXEMPTION = 125000; // ₹1.25L from Budget 2024

    // LTCG already booked this FY
    $bookedStmt = $db->prepare("
        SELECT COALESCE(SUM(
            (t.price_per_unit - COALESCE(
                (SELECT b.price_per_unit FROM mf_transactions b
                 WHERE b.holding_id=t.holding_id
                   AND b.tx_type IN ('buy','sip','lumpsum','switch_in')
                   AND b.tx_date <= t.tx_date
                 ORDER BY b.tx_date ASC LIMIT 1), 0)
            ) * t.units
        ), 0) AS ltcg_booked
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id=t.holding_id
        JOIN portfolios p ON p.id=mh.portfolio_id
        WHERE p.user_id=?
          AND t.tx_type IN ('sell','swp','switch_out','redemption')
          AND t.tx_date BETWEEN ? AND ?
          AND DATEDIFF(t.tx_date,
              (SELECT MIN(b2.tx_date) FROM mf_transactions b2
               WHERE b2.holding_id=t.holding_id AND b2.tx_type IN ('buy','sip','lumpsum'))) > 365
    ");
    $bookedStmt->execute([$userId, fyStart(), fyEnd()]);
    $ltcgBooked   = (float)$bookedStmt->fetchColumn();
    $remaining    = max(0, $LTCG_EXEMPTION - $ltcgBooked);

    // Equity funds with unrealised LTCG (held > 1Y)
    $equityStmt = $db->prepare("
        SELECT
          f.fund_name, f.category, f.latest_nav,
          mh.id AS holding_id, mh.units, mh.invested_value, mh.latest_value,
          (SELECT MIN(t.tx_date) FROM mf_transactions t
           WHERE t.holding_id=mh.id AND t.tx_type IN ('buy','sip','lumpsum')) AS first_buy,
          (SELECT AVG(t.price_per_unit) FROM mf_transactions t
           WHERE t.holding_id=mh.id AND t.tx_type IN ('buy','sip','lumpsum')) AS avg_cost
        FROM mf_holdings mh
        JOIN funds f ON f.id=mh.fund_id
        WHERE mh.portfolio_id=? AND mh.units > 0.001
          AND f.category NOT IN ('Debt','Liquid','Money Market','Credit Risk')
        ORDER BY mh.latest_value DESC
    ");
    $equityStmt->execute([$pid]);
    $equityHoldings = $equityStmt->fetchAll(PDO::FETCH_ASSOC);

    $candidates = [];
    foreach ($equityHoldings as $h) {
        $firstBuy   = $h['first_buy'];
        if (!$firstBuy) continue;
        $holdDays   = (int)((time() - strtotime($firstBuy)) / 86400);
        if ($holdDays <= 365) continue; // must be LTCG (>1Y)

        $avgCost  = (float)$h['avg_cost'];
        $nav      = (float)$h['latest_nav'];
        $units    = (float)$h['units'];
        $gainPerUnit = $nav - $avgCost;
        if ($gainPerUnit <= 0) continue; // no gain, skip

        $totalUnrealisedLtcg = $gainPerUnit * $units;

        // Units to redeem to use exactly the remaining exemption
        $unitsToRedeem = $remaining > 0
            ? min($units * 0.95, $remaining / $gainPerUnit) // leave 5% buffer
            : 0;
        $valueToRedeem = round($unitsToRedeem * $nav);
        $ltcgIfRedeemed = round($gainPerUnit * $unitsToRedeem);

        $candidates[] = [
            'fund_name'           => $h['fund_name'],
            'category'            => $h['category'],
            'first_buy_date'      => $firstBuy,
            'holding_days'        => $holdDays,
            'holding_years'       => round($holdDays/365, 1),
            'total_units'         => round($units, 4),
            'current_nav'         => round($nav, 4),
            'avg_cost_nav'        => round($avgCost, 4),
            'gain_per_unit'       => round($gainPerUnit, 4),
            'total_unrealised_ltcg' => round($totalUnrealisedLtcg),
            'units_to_redeem'     => round($unitsToRedeem, 3),
            'value_to_redeem'     => $valueToRedeem,
            'ltcg_if_redeemed'    => $ltcgIfRedeemed,
            'tax_saved'           => round($ltcgIfRedeemed * 0.125), // 12.5% LTCG tax
        ];
    }

    // Sort: most LTCG first
    usort($candidates, fn($a,$b) => $b['total_unrealised_ltcg'] <=> $a['total_unrealised_ltcg']);

    $daysToFYEnd = max(0, (int)((strtotime(fyEnd()) - time()) / 86400));

    echo json_encode(['success'=>true,'data'=>[
        'fy_label'            => currentFyLabel(),
        'ltcg_exemption'      => $LTCG_EXEMPTION,
        'ltcg_booked_this_fy' => round($ltcgBooked),
        'remaining_exemption' => round($remaining),
        'days_to_fy_end'      => $daysToFYEnd,
        'candidates'          => $candidates,
        'total_potential_savings' => array_sum(array_column($candidates,'tax_saved')),
        'strategy' => $remaining > 0
            ? "Book ₹".number_format($remaining,0)." in LTCG before March 31 to use your tax-free allowance. Immediately reinvest to reset cost basis."
            : "You have already used your full ₹1.25L LTCG exemption for ".currentFyLabel().".",
    ]]);
    break;

default:
    echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
}
