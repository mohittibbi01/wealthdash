<?php
/**
 * WealthDash — Stocks List (Holdings + Transactions)
 * GET /api/?action=stocks_list&type=holdings|transactions
 * t215: Added CAGR, gain_pct_live, years_held to holdings response
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$type        = clean($_GET['type'] ?? 'holdings');
$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$gainType    = clean($_GET['gain_type'] ?? '');
$txnType     = clean($_GET['txn_type'] ?? '');
$fy          = clean($_GET['investment_fy'] ?? '');
$symbol      = clean($_GET['symbol'] ?? '');

$portJoin  = "JOIN portfolios p ON p.id = h.portfolio_id";
$portWhere = $isAdmin && !$portfolioId
    ? ''
    : ($portfolioId
        ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
        : "AND p.user_id = {$userId}");

if ($type === 'holdings') {
    $gainCond = $gainType ? "AND h.gain_type = " . DB::pdo()->quote($gainType) : '';
    $rows = DB::fetchAll(
        "SELECT h.*,
                sm.symbol, sm.company_name, sm.exchange, sm.sector,
                sm.latest_price       AS current_price,
                sm.latest_price_date,
                p.name                AS portfolio_name,
                /* t215: Gain% from avg_buy_price vs live price */
                ROUND((sm.latest_price - h.avg_buy_price) / NULLIF(h.avg_buy_price,0) * 100, 2) AS gain_pct_live,
                /* t215: CAGR — annualised return since first_purchase_date */
                CASE
                  WHEN h.first_purchase_date IS NOT NULL
                       AND h.avg_buy_price > 0
                       AND DATEDIFF(CURDATE(), h.first_purchase_date) > 30
                  THEN ROUND(
                         (POW(sm.latest_price / NULLIF(h.avg_buy_price,0),
                              365.0 / GREATEST(DATEDIFF(CURDATE(), h.first_purchase_date),1)) - 1) * 100,
                         2)
                  ELSE NULL
                END AS cagr,
                /* t215: Years held (for tooltip) */
                ROUND(DATEDIFF(CURDATE(), h.first_purchase_date) / 365.0, 1) AS years_held
         FROM stock_holdings h
         {$portJoin}
         JOIN stock_master sm ON sm.id = h.stock_id
         WHERE h.quantity > 0 {$portWhere} {$gainCond}
         ORDER BY h.current_value DESC"
    );
    json_response(true, '', $rows);
}

if ($type === 'transactions') {
    $portJoin2  = "JOIN portfolios p ON p.id = t.portfolio_id";
    $portWhere2 = str_replace('h.', 't.', $portWhere);
    $conds      = ["1=1 {$portWhere2}"];
    if ($txnType) $conds[] = "t.txn_type = " . DB::pdo()->quote($txnType);
    if ($fy)      $conds[] = "t.investment_fy = " . DB::pdo()->quote($fy);
    if ($symbol)  $conds[] = "sm.symbol LIKE " . DB::pdo()->quote('%' . $symbol . '%');
    $where = implode(' AND ', $conds);
    $rows = DB::fetchAll(
        "SELECT t.*, sm.symbol, sm.company_name, sm.exchange
         FROM stock_transactions t
         {$portJoin2}
         JOIN stock_master sm ON sm.id = t.stock_id
         WHERE {$where}
         ORDER BY t.txn_date DESC, t.id DESC
         LIMIT 500"
    );
    json_response(true, '', $rows);
}

json_response(false, 'Invalid type.');
