<?php
/**
 * WealthDash — NPS List (Holdings + Transactions)
 * GET /api/?action=nps_list&type=holdings|transactions
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$type        = clean($_GET['type'] ?? 'holdings');
$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$tier        = clean($_GET['tier'] ?? '');
$schemeId    = (int)($_GET['scheme_id'] ?? 0);
$contribType = clean($_GET['contrib_type'] ?? '');

// Build portfolio access filter
$portWhere = $isAdmin && !$portfolioId
    ? ''
    : ($portfolioId ? 'AND p.id = ' . $portfolioId . ' AND p.user_id = ' . $userId
    : 'AND p.user_id = ' . $userId);

if ($type === 'holdings') {
    $tierCond = $tier ? "AND h.tier = " . DB::pdo()->quote($tier) : '';
    $rows = DB::fetchAll(
        "SELECT h.*, s.scheme_name, s.pfm_name, s.tier AS scheme_tier,
                s.latest_nav, s.latest_nav_date, s.asset_class,
                p.name AS portfolio_name
         FROM nps_holdings h
         JOIN nps_schemes s  ON s.id = h.scheme_id
         JOIN portfolios p   ON p.id = h.portfolio_id
         WHERE 1=1 {$portWhere} {$tierCond}
         ORDER BY s.pfm_name, s.tier, s.scheme_name"
    );

    // t102: Recalculate CAGR properly for each holding
    foreach ($rows as &$h) {
        $invested   = (float)$h['total_invested'];
        $value      = (float)$h['latest_value'];
        $firstDate  = $h['first_contribution_date'] ?? null;

        // Simple CAGR: (current/invested)^(1/years) - 1
        $cagr = null;
        if ($invested > 0 && $value > 0 && $firstDate) {
            $days  = (int)(new DateTime())->diff(new DateTime($firstDate))->days;
            $years = $days / 365.25;
            if ($years >= 0.1) {
                $cagr = round((pow($value / $invested, 1 / $years) - 1) * 100, 2);
            }
        }
        $h['cagr']     = $cagr;
        $h['gain_pct'] = $invested > 0 ? round(($value - $invested) / $invested * 100, 2) : 0;
    }
    unset($h);

    json_response(true, '', $rows);
}

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
         JOIN portfolios p  ON p.id = t.portfolio_id
         WHERE {$where}
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT 500"
    );
    json_response(true, '', $rows);
}

json_response(false, 'Invalid type parameter.');