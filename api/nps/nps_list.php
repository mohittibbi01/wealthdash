<?php
/**
 * WealthDash — NPS List (Holdings + Transactions)
 * t99 + t102: XIRR from nps_transactions, CAGR from nav_history
 * GET /api/?action=nps_list&type=holdings|transactions|summary
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/helpers.php';

$type        = clean($_GET['type'] ?? 'holdings');
$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$tier        = clean($_GET['tier'] ?? '');
$schemeId    = (int)($_GET['scheme_id'] ?? 0);
$contribType = clean($_GET['contrib_type'] ?? '');

$portWhere = $isAdmin && !$portfolioId
    ? ''
    : ($portfolioId
        ? 'AND p.id = ' . $portfolioId . ' AND p.user_id = ' . $userId
        : 'AND p.user_id = ' . $userId);

// ── HOLDINGS ─────────────────────────────────────────────────────────────────
if ($type === 'holdings') {
    $tierCond = $tier ? "AND h.tier = " . DB::pdo()->quote($tier) : '';
    $rows = DB::fetchAll(
        "SELECT h.*, s.scheme_name, s.pfm_name, s.tier AS scheme_tier,
                s.latest_nav, s.latest_nav_date, s.asset_class,
                s.return_1y, s.return_3y, s.return_5y, s.return_since,
                p.name AS portfolio_name
         FROM nps_holdings h
         JOIN nps_schemes  s ON s.id = h.scheme_id
         JOIN portfolios   p ON p.id = h.portfolio_id
         WHERE 1=1 {$portWhere} {$tierCond}
         ORDER BY s.pfm_name, s.tier, s.scheme_name"
    );

    foreach ($rows as &$h) {
        $invested = (float)$h['total_invested'];
        $value    = (float)$h['latest_value'];
        $sid      = (int)$h['scheme_id'];
        $portId   = (int)$h['portfolio_id'];
        $tierH    = $h['tier'];

        // ── XIRR from transactions ────────────────────────────────────────────
        $txns = DB::fetchAll(
            "SELECT txn_date, amount, contribution_type FROM nps_transactions
             WHERE portfolio_id=? AND scheme_id=? AND tier=?
             ORDER BY txn_date ASC",
            [$portId, $sid, $tierH]
        );

        $cashFlows = [];
        $totalSelf     = 0.0;
        $totalEmployer = 0.0;
        foreach ($txns as $t) {
            $amt = (float)$t['amount'];
            $cashFlows[] = ['amount' => -$amt, 'date' => $t['txn_date']];
            if ($t['contribution_type'] === 'EMPLOYER') $totalEmployer += $amt;
            else $totalSelf += $amt;
        }

        $xirr = null;
        if (!empty($cashFlows) && $value > 0) {
            $cashFlows[] = ['amount' => $value, 'date' => date('Y-m-d')];
            $xirr = xirr($cashFlows);
            if ($xirr !== null) $xirr = round($xirr * 100, 2);
        }

        // Fallback: simple CAGR from nav_history
        if ($xirr === null && $h['first_contribution_date'] && $value > 0 && $invested > 0) {
            // Nav-based CAGR using first contribution nav vs today
            $firstNavRow = DB::fetchOne(
                "SELECT nav FROM nps_nav_history WHERE scheme_id=? AND nav_date >= ?
                 ORDER BY nav_date ASC LIMIT 1",
                [$sid, $h['first_contribution_date']]
            );
            $latestNav = (float)($h['latest_nav'] ?? 0);
            if ($firstNavRow && (float)$firstNavRow['nav'] > 0 && $latestNav > 0) {
                $days  = max(1, (int)((time() - strtotime($h['first_contribution_date'])) / 86400));
                $years = $days / 365.25;
                if ($years >= 0.1) {
                    $navRatio = $latestNav / (float)$firstNavRow['nav'];
                    $xirr = round((pow($navRatio, 1 / $years) - 1) * 100, 2);
                }
            }
            // Final fallback: simple cost-based CAGR
            if ($xirr === null) {
                $days  = max(1, (int)((time() - strtotime($h['first_contribution_date'])) / 86400));
                $years = $days / 365.25;
                if ($years >= 0.1) {
                    $xirr = round((pow($value / $invested, 1 / $years) - 1) * 100, 2);
                }
            }
        }

        $h['xirr']            = $xirr;
        $h['cagr']            = $xirr; // Keep 'cagr' for backward compat with UI
        $h['gain_pct']        = $invested > 0 ? round(($value - $invested) / $invested * 100, 2) : 0;
        $h['self_contributed']     = round($totalSelf, 2);
        $h['employer_contributed'] = round($totalEmployer, 2);

        // NAV history availability flag (for screener/charts)
        $histCount = (int)(DB::fetchVal(
            "SELECT COUNT(*) FROM nps_nav_history WHERE scheme_id=?", [$sid]
        ) ?: 0);
        $h['nav_history_count'] = $histCount;
        $h['has_nav_history']   = $histCount > 0;
    }
    unset($h);

    json_response(true, '', $rows);
}

// ── TRANSACTIONS ─────────────────────────────────────────────────────────────
if ($type === 'transactions') {
    $conds = ["1=1 {$portWhere}"];
    if ($schemeId)    $conds[] = "t.scheme_id = {$schemeId}";
    if ($tier)        $conds[] = "t.tier = " . DB::pdo()->quote($tier);
    if ($contribType) $conds[] = "t.contribution_type = " . DB::pdo()->quote($contribType);

    $where = implode(' AND ', $conds);
    $rows  = DB::fetchAll(
        "SELECT t.*, s.scheme_name, s.pfm_name, p.name AS portfolio_name
         FROM nps_transactions t
         JOIN nps_schemes s ON s.id = t.scheme_id
         JOIN portfolios  p ON p.id = t.portfolio_id
         WHERE {$where}
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT 500"
    );
    json_response(true, '', $rows);
}

// ── SUMMARY (for asset allocation + tax dashboard) ───────────────────────────
if ($type === 'summary') {
    $portCond = $portfolioId
        ? "AND p.id={$portfolioId} AND p.user_id={$userId}"
        : "AND p.user_id={$userId}";

    // Asset class allocation
    $allocation = DB::fetchAll(
        "SELECT s.asset_class,
                SUM(h.total_invested) AS invested,
                SUM(h.latest_value)   AS value
         FROM nps_holdings h
         JOIN nps_schemes  s ON s.id = h.scheme_id
         JOIN portfolios   p ON p.id = h.portfolio_id
         WHERE 1=1 {$portCond}
         GROUP BY s.asset_class"
    );

    // Tier breakdown
    $tiers = DB::fetchAll(
        "SELECT h.tier,
                SUM(h.total_invested) AS invested,
                SUM(h.latest_value)   AS value,
                SUM(h.gain_loss)      AS gain
         FROM nps_holdings h
         JOIN portfolios   p ON p.id = h.portfolio_id
         WHERE 1=1 {$portCond}
         GROUP BY h.tier"
    );

    // FY-wise contributions (for 80C tracker)
    $currentFy = date('n') >= 4
        ? date('Y') . '-' . (date('Y') + 1)
        : (date('Y') - 1) . '-' . date('Y');

    $fyContrib = DB::fetchAll(
        "SELECT t.investment_fy, t.contribution_type,
                SUM(t.amount) AS total_amount
         FROM nps_transactions t
         JOIN portfolios p ON p.id = t.portfolio_id
         WHERE 1=1 {$portCond}
         GROUP BY t.investment_fy, t.contribution_type
         ORDER BY t.investment_fy DESC"
    );

    // Current FY totals for tax section
    $fyTotals = ['self' => 0.0, 'employer' => 0.0];
    foreach ($fyContrib as $row) {
        if ($row['investment_fy'] === $currentFy) {
            if ($row['contribution_type'] === 'EMPLOYER') $fyTotals['employer'] += (float)$row['total_amount'];
            else $fyTotals['self'] += (float)$row['total_amount'];
        }
    }

    json_response(true, '', [
        'allocation'  => $allocation,
        'tiers'       => $tiers,
        'fy_contrib'  => $fyContrib,
        'current_fy'  => $currentFy,
        'fy_totals'   => $fyTotals,
    ]);
}

json_response(false, 'Invalid type parameter.');
