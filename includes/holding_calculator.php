<?php
/**
 * WealthDash — Holding Calculator
 * Computes MF, Stock, NPS holdings from transactions
 * Uses FIFO for cost basis on SELL transactions
 */
declare(strict_types=1);

class HoldingCalculator {

    /**
     * Recalculate all MF holdings for a portfolio
     */
    public static function recalculate_mf(int $portfolioId): void {
        // Get all active funds in this portfolio
        $funds = DB::fetchAll(
            'SELECT DISTINCT fund_id, folio_number FROM mf_transactions WHERE portfolio_id = ?',
            [$portfolioId]
        );

        foreach ($funds as $f) {
            self::recalculate_mf_holding($portfolioId, (int)$f['fund_id'], $f['folio_number']);
        }
    }

    /**
     * Recalculate a single MF holding (fund + folio combination)
     */
    public static function recalculate_mf_holding(int $portfolioId, int $fundId, ?string $folio): void {
        // Fetch all transactions ordered by date
        $txns = DB::fetchAll(
            'SELECT t.*, f.latest_nav, f.min_ltcg_days, f.lock_in_days, f.category
             FROM mf_transactions t
             JOIN funds f ON f.id = t.fund_id
             WHERE t.portfolio_id = ? AND t.fund_id = ?
               AND (t.folio_number = ? OR (t.folio_number IS NULL AND ? IS NULL))
             ORDER BY t.txn_date ASC, t.id ASC',
            [$portfolioId, $fundId, $folio, $folio]
        );

        if (empty($txns)) return;

        // FIFO queue: [['units' => x, 'nav' => y, 'date' => 'YYYY-MM-DD'], ...]
        $queue           = [];
        $totalUnits      = 0.0;
        $totalInvested   = 0.0;
        $firstPurchase   = null;

        foreach ($txns as $t) {
            $type  = $t['transaction_type'];
            $units = (float) $t['units'];
            $nav   = (float) $t['nav'];
            $date  = $t['txn_date'];

            if (in_array($type, ['BUY', 'DIV_REINVEST', 'SWITCH_IN', 'STP_IN'])) {
                $cost = (float) $t['value_at_cost'];
                $queue[] = ['units' => $units, 'nav' => $nav, 'cost' => $cost, 'date' => $date];
                $totalUnits    += $units;
                $totalInvested += $cost;
                if (!$firstPurchase) $firstPurchase = $date;

            } elseif (in_array($type, ['SELL', 'SWITCH_OUT', 'STP_OUT', 'SWP'])) {
                // FIFO: consume from earliest lots
                $remaining = $units;
                while ($remaining > 0.0001 && !empty($queue)) {
                    $lot = &$queue[0];
                    if ($lot['units'] <= $remaining) {
                        $remaining        -= $lot['units'];
                        $totalUnits       -= $lot['units'];
                        $totalInvested    -= $lot['cost'];
                        array_shift($queue);
                    } else {
                        $fraction          = $remaining / $lot['units'];
                        $totalInvested    -= $lot['cost'] * $fraction;
                        $lot['units']     -= $remaining;
                        $lot['cost']      *= (1 - $fraction);
                        $totalUnits       -= $remaining;
                        $remaining         = 0;
                    }
                }
                unset($lot);
            }
        }

        // Round precision errors
        $totalUnits    = round($totalUnits, 4);
        $totalInvested = round(max(0, $totalInvested), 2);
        $isActive      = $totalUnits > 0.001;

        // Latest NAV from funds table
        $fund = DB::fetchOne('SELECT * FROM funds WHERE id = ?', [$fundId]);
        $latestNav    = (float) ($fund['latest_nav'] ?? 0);
        $valueNow     = round($totalUnits * $latestNav, 2);
        $gainLoss     = round($valueNow - $totalInvested, 2);
        $gainPct      = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 4) : 0;

        // XIRR — pass all transactions + current value
        $cagrVal = null;
        if (!empty($txns) && $valueNow > 0) {
            $cagrVal = xirr_from_txns($txns, $valueNow, date('Y-m-d'));
            // Fallback to simple CAGR if XIRR didn't converge (e.g. very new holding)
            if ($cagrVal === null && $firstPurchase && $totalInvested > 0) {
                $years = days_between($firstPurchase) / 365;
                if ($years >= 0.08) {
                    $cagrVal = round(cagr($totalInvested, $valueNow, $years) ?? 0, 2);
                }
            }
        }

        // Dates
        $ltcgDate        = null;
        $lockInDate      = null;
        $withdrawableDate = null;
        $gainType        = 'NA';

        if ($firstPurchase) {
            $ltcgDays  = (int) ($fund['min_ltcg_days'] ?? EQUITY_LTCG_DAYS);
            $lockDays  = (int) ($fund['lock_in_days'] ?? 0);
            $ltcgDate  = date('Y-m-d', strtotime($firstPurchase . " +{$ltcgDays} days"));

            if ($lockDays > 0) {
                $lockInDate       = date('Y-m-d', strtotime($firstPurchase . " +{$lockDays} days"));
                $withdrawableDate = $lockInDate > $ltcgDate ? $lockInDate : $ltcgDate;
            } else {
                $withdrawableDate = $ltcgDate;
            }

            $gainType = days_between($firstPurchase) >= $ltcgDays ? 'LTCG' : 'STCG';
        }

        $fy              = $firstPurchase ? date_to_fy($firstPurchase) : null;
        $withdrawableFy  = $withdrawableDate ? date_to_fy($withdrawableDate) : null;

        // Upsert mf_holdings
        $existing = DB::fetchOne(
            'SELECT id FROM mf_holdings WHERE portfolio_id = ? AND fund_id = ? AND (folio_number = ? OR (folio_number IS NULL AND ? IS NULL))',
            [$portfolioId, $fundId, $folio, $folio]
        );

        if ($existing) {
            DB::run(
                'UPDATE mf_holdings SET
                    total_units = ?, avg_cost_nav = ?, total_invested = ?, value_now = ?,
                    gain_loss = ?, gain_pct = ?, cagr = ?, first_purchase_date = ?,
                    ltcg_date = ?, lock_in_date = ?, withdrawable_date = ?,
                    investment_fy = ?, withdrawable_fy = ?, gain_type = ?,
                    is_active = ?, last_calculated = NOW()
                 WHERE id = ?',
                [
                    $totalUnits,
                    $totalUnits > 0 ? round($totalInvested / $totalUnits, 4) : 0,
                    $totalInvested, $valueNow, $gainLoss, $gainPct, $cagrVal,
                    $firstPurchase, $ltcgDate, $lockInDate, $withdrawableDate,
                    $fy, $withdrawableFy, $gainType, $isActive ? 1 : 0,
                    $existing['id'],
                ]
            );
        } else {
            DB::run(
                'INSERT INTO mf_holdings
                    (portfolio_id, fund_id, folio_number, total_units, avg_cost_nav, total_invested,
                     value_now, gain_loss, gain_pct, cagr, first_purchase_date, ltcg_date,
                     lock_in_date, withdrawable_date, investment_fy, withdrawable_fy, gain_type, is_active, last_calculated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $portfolioId, $fundId, $folio, $totalUnits,
                    $totalUnits > 0 ? round($totalInvested / $totalUnits, 4) : 0,
                    $totalInvested, $valueNow, $gainLoss, $gainPct, $cagrVal,
                    $firstPurchase, $ltcgDate, $lockInDate, $withdrawableDate,
                    $fy, $withdrawableFy, $gainType, $isActive ? 1 : 0,
                ]
            );
        }
    }

    /**
     * Recalculate stock holdings for a portfolio
     */
    public static function recalculate_stocks(int $portfolioId): void {
        $stocks = DB::fetchAll(
            'SELECT DISTINCT stock_id FROM stock_transactions WHERE portfolio_id = ?',
            [$portfolioId]
        );
        foreach ($stocks as $s) {
            self::recalculate_stock_holding($portfolioId, (int)$s['stock_id']);
        }
    }

    public static function recalculate_stock_holding(int $portfolioId, int $stockId): void {
        $txns = DB::fetchAll(
            'SELECT * FROM stock_transactions WHERE portfolio_id = ? AND stock_id = ?
             ORDER BY txn_date ASC, id ASC',
            [$portfolioId, $stockId]
        );

        if (empty($txns)) return;

        $queue         = [];
        $totalQty      = 0.0;
        $totalCost     = 0.0;
        $firstBuyDate  = null;

        foreach ($txns as $t) {
            $type = $t['txn_type'];
            $qty  = (float) $t['quantity'];
            $price = (float) $t['price'];
            $cost  = (float) $t['value_at_cost'];
            $date  = $t['txn_date'];

            if ($type === 'BUY') {
                $queue[] = ['qty' => $qty, 'cost' => $cost, 'date' => $date];
                $totalQty  += $qty;
                $totalCost += $cost;
                if (!$firstBuyDate) $firstBuyDate = $date;

            } elseif ($type === 'SELL') {
                $remaining = $qty;
                while ($remaining > 0.001 && !empty($queue)) {
                    $lot = &$queue[0];
                    if ($lot['qty'] <= $remaining) {
                        $remaining   -= $lot['qty'];
                        $totalQty    -= $lot['qty'];
                        $totalCost   -= $lot['cost'];
                        array_shift($queue);
                    } else {
                        $frac         = $remaining / $lot['qty'];
                        $totalCost   -= $lot['cost'] * $frac;
                        $lot['cost'] *= (1 - $frac);
                        $lot['qty']  -= $remaining;
                        $totalQty    -= $remaining;
                        $remaining    = 0;
                    }
                }
                unset($lot);

            } elseif ($type === 'BONUS') {
                // Bonus shares: cost = 0, adjust avg
                $queue[] = ['qty' => $qty, 'cost' => 0, 'date' => $date];
                $totalQty += $qty;

            } elseif ($type === 'SPLIT') {
                $totalQty += $qty; // pre-processed split units
            }
        }

        $totalQty  = round($totalQty, 4);
        $totalCost = round(max(0, $totalCost), 2);
        $isActive  = $totalQty > 0.001;

        $stock        = DB::fetchOne('SELECT * FROM stock_master WHERE id = ?', [$stockId]);
        $latestPrice  = (float) ($stock['latest_price'] ?? 0);
        $currentValue = round($totalQty * $latestPrice, 2);
        $gainLoss     = round($currentValue - $totalCost, 2);
        $gainPct      = $totalCost > 0 ? round(($gainLoss / $totalCost) * 100, 4) : 0;

        $cagrVal = null;
        if ($firstBuyDate && $totalCost > 0 && $currentValue > 0) {
            $years = days_between($firstBuyDate) / 365;
            if ($years >= 0.08) {
                $cagrVal = round(cagr($totalCost, $currentValue, $years), 4);
            }
        }

        $ltcgDate = null;
        $gainType = 'NA';
        if ($firstBuyDate) {
            $ltcgDate = date('Y-m-d', strtotime($firstBuyDate . ' +' . EQUITY_LTCG_DAYS . ' days'));
            $gainType = days_between($firstBuyDate) >= EQUITY_LTCG_DAYS ? 'LTCG' : 'STCG';
        }

        $existing = DB::fetchOne(
            'SELECT id FROM stock_holdings WHERE portfolio_id = ? AND stock_id = ?',
            [$portfolioId, $stockId]
        );

        if ($existing) {
            DB::run(
                'UPDATE stock_holdings SET
                    quantity = ?, avg_buy_price = ?, total_invested = ?, current_value = ?,
                    gain_loss = ?, gain_pct = ?, cagr = ?, first_buy_date = ?,
                    ltcg_date = ?, gain_type = ?, is_active = ?, last_calculated = NOW()
                 WHERE id = ?',
                [
                    $totalQty,
                    $totalQty > 0 ? round($totalCost / $totalQty, 2) : 0,
                    $totalCost, $currentValue, $gainLoss, $gainPct, $cagrVal,
                    $firstBuyDate, $ltcgDate, $gainType, $isActive ? 1 : 0,
                    $existing['id'],
                ]
            );
        } else {
            DB::run(
                'INSERT INTO stock_holdings
                    (portfolio_id, stock_id, quantity, avg_buy_price, total_invested,
                     current_value, gain_loss, gain_pct, cagr, first_buy_date, ltcg_date, gain_type, is_active, last_calculated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $portfolioId, $stockId, $totalQty,
                    $totalQty > 0 ? round($totalCost / $totalQty, 2) : 0,
                    $totalCost, $currentValue, $gainLoss, $gainPct, $cagrVal,
                    $firstBuyDate, $ltcgDate, $gainType, $isActive ? 1 : 0,
                ]
            );
        }
    }

    /**
     * Recalculate NPS holdings
     */
    public static function recalculate_nps(int $portfolioId): void {
        $schemes = DB::fetchAll(
            'SELECT DISTINCT scheme_id, tier FROM nps_transactions WHERE portfolio_id = ?',
            [$portfolioId]
        );
        foreach ($schemes as $s) {
            self::recalculate_nps_holding($portfolioId, (int)$s['scheme_id'], $s['tier']);
        }
    }

    public static function recalculate_nps_holding(int $portfolioId, int $schemeId, string $tier): void {
        $agg = DB::fetchOne(
            'SELECT SUM(units) as total_units, SUM(amount) as total_invested,
                    MIN(txn_date) as first_date
             FROM nps_transactions
             WHERE portfolio_id = ? AND scheme_id = ? AND tier = ?',
            [$portfolioId, $schemeId, $tier]
        );

        if (!$agg || !$agg['total_units']) return;

        $totalUnits    = (float) $agg['total_units'];
        $totalInvested = (float) $agg['total_invested'];
        $firstDate     = $agg['first_date'];

        $scheme      = DB::fetchOne('SELECT * FROM nps_schemes WHERE id = ?', [$schemeId]);
        $latestNav   = (float) ($scheme['latest_nav'] ?? 0);
        $latestValue = round($totalUnits * $latestNav, 2);
        $gainLoss    = round($latestValue - $totalInvested, 2);
        $gainPct     = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 4) : 0;

        $cagrVal = null;
        if ($firstDate && $totalInvested > 0 && $latestValue > 0) {
            $years = days_between($firstDate) / 365;
            if ($years >= 0.08) {
                $cagrVal = round(cagr($totalInvested, $latestValue, $years), 4);
            }
        }

        $existing = DB::fetchOne(
            'SELECT id FROM nps_holdings WHERE portfolio_id = ? AND scheme_id = ? AND tier = ?',
            [$portfolioId, $schemeId, $tier]
        );

        if ($existing) {
            DB::run(
                'UPDATE nps_holdings SET
                    total_units = ?, total_invested = ?, latest_value = ?,
                    gain_loss = ?, gain_pct = ?, cagr = ?,
                    first_contribution_date = ?, last_calculated = NOW()
                 WHERE id = ?',
                [$totalUnits, $totalInvested, $latestValue, $gainLoss, $gainPct, $cagrVal, $firstDate, $existing['id']]
            );
        } else {
            DB::run(
                'INSERT INTO nps_holdings (portfolio_id, scheme_id, tier, total_units, total_invested,
                    latest_value, gain_loss, gain_pct, cagr, first_contribution_date, last_calculated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$portfolioId, $schemeId, $tier, $totalUnits, $totalInvested, $latestValue, $gainLoss, $gainPct, $cagrVal, $firstDate]
            );
        }
    }
}

// ── Procedural wrappers for direct PDO usage ────────────────────────────────

/**
 * Recalculate MF holding for a specific fund+folio combination
 * Called after add/edit/delete transactions
 *
 * ✅ FIX: $folio is now ?string (nullable) — handles optional folio numbers
 *         Empty string is normalized to null so DB NULL comparisons work correctly
 */
function recalculate_mf_holdings(PDO $db, int $portfolio_id, int $fund_id, ?string $folio = null): void {
    // Normalize: treat empty string same as null
    if ($folio === '') $folio = null;

    // Fetch all transactions in order — handle NULL folio with IS NULL check
    $stmt = $db->prepare("
        SELECT t.id, t.transaction_type, t.txn_date, t.units, t.nav, t.value_at_cost,
               t.investment_fy, f.latest_nav, f.latest_nav_date, f.min_ltcg_days,
               f.lock_in_days, f.category, f.sub_category
        FROM mf_transactions t
        JOIN funds f ON f.id = t.fund_id
        WHERE t.portfolio_id = ? AND t.fund_id = ?
          AND (t.folio_number = ? OR (t.folio_number IS NULL AND ? IS NULL))
        ORDER BY t.txn_date ASC, t.id ASC
    ");
    $stmt->execute([$portfolio_id, $fund_id, $folio, $folio]);
    $txns = $stmt->fetchAll();

    // ── FIFO cost basis ────────────────────────────────
    $queue           = [];
    $totalUnits      = 0.0;
    $totalInvested   = 0.0;
    $firstPurchase   = null;
    $investmentFy    = null;
    $latestNav       = null;
    $latestNavDate   = null;
    $minLtcgDays     = 365;
    $lockInDays      = 0;
    $category        = '';

    foreach ($txns as $t) {
        $type      = $t['transaction_type'];
        $units     = (float)$t['units'];
        $nav       = (float)$t['nav'];
        $cost      = (float)$t['value_at_cost'];
        $date      = $t['txn_date'];
        $latestNav = $t['latest_nav'] ? (float)$t['latest_nav'] : $latestNav;
        $latestNavDate = $t['latest_nav_date'] ?? $latestNavDate;
        $minLtcgDays = (int)$t['min_ltcg_days'];
        $lockInDays  = (int)$t['lock_in_days'];
        $category    = $t['category'];

        if (in_array($type, ['BUY','DIV_REINVEST','SWITCH_IN'])) {
            $queue[] = ['units'=>$units,'nav'=>$nav,'cost'=>$cost,'date'=>$date];
            $totalUnits    += $units;
            $totalInvested += $cost;
            if (!$firstPurchase) { $firstPurchase = $date; $investmentFy = $t['investment_fy']; }
        } elseif (in_array($type, ['SELL','SWITCH_OUT'])) {
            $remaining = $units;
            while ($remaining > 0.0001 && !empty($queue)) {
                $lot = &$queue[0];
                if ($lot['units'] <= $remaining) {
                    $remaining -= $lot['units'];
                    $totalUnits    -= $lot['units'];
                    $totalInvested -= $lot['cost'];
                    array_shift($queue);
                } else {
                    $fraction = $remaining / $lot['units'];
                    $totalUnits    -= $remaining;
                    $totalInvested -= $lot['cost'] * $fraction;
                    $lot['units']  -= $remaining;
                    $lot['cost']   *= (1 - $fraction);
                    $remaining = 0;
                }
            }
        }
    }

    $totalUnits    = max(0, round($totalUnits, 4));
    $totalInvested = max(0, round($totalInvested, 4));
    $isActive      = $totalUnits > 0.001 ? 1 : 0;
    $avgCostNav    = $totalUnits > 0 ? round($totalInvested / $totalUnits, 4) : 0;
    $navForCalc    = $latestNav ?? ($totalUnits > 0 ? $avgCostNav : 0);
    $valueNow      = round($totalUnits * $navForCalc, 2);
    $gainLoss      = round($valueNow - $totalInvested, 2);
    $gainPct       = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 2) : 0;
    $xirr_val = 0.0;

    if (!empty($txns) && $valueNow > 0) {
        $xirr_val = xirr_from_txns($txns, $valueNow, date('Y-m-d')) ?? 0.0;
        if ($xirr_val == 0.0 && $firstPurchase && $totalInvested > 0) {
            $days_held = (int)((strtotime('today') - strtotime($firstPurchase)) / 86400);
            if ($days_held > 0) {
                $years    = $days_held / 365;
                $xirr_val = round((pow($valueNow / $totalInvested, 1 / $years) - 1) * 100, 2);
            }
        }
    }
    $cagr = $xirr_val;

    $ltcgDate   = $firstPurchase ? date('Y-m-d', strtotime($firstPurchase) + ($minLtcgDays * 86400)) : null;
    $lockInDate = ($firstPurchase && $lockInDays > 0)
                    ? date('Y-m-d', strtotime($firstPurchase) + ($lockInDays * 86400)) : null;

    $withdrawableDate = null;
    if ($ltcgDate && $lockInDate) {
        $withdrawableDate = $lockInDate > $ltcgDate ? $lockInDate : $ltcgDate;
    } elseif ($ltcgDate) {
        $withdrawableDate = $ltcgDate;
    }

    $today    = date('Y-m-d');
    $gainType = 'STCG';
    if ($firstPurchase && $ltcgDate && $today >= $ltcgDate) $gainType = 'LTCG';

    $withdrawableFy = null;
    if ($ltcgDate) {
        $ltcgYear = (int)date('Y', strtotime($ltcgDate));
        $ltcgMon  = (int)date('n', strtotime($ltcgDate));
        $fy = $ltcgMon >= 4 ? $ltcgYear : $ltcgYear - 1;
        $withdrawableFy = $fy . '-' . substr((string)($fy + 1), -2);
    }

    $highestNavStmt = $db->prepare("
        SELECT nav AS max_nav, nav_date FROM nav_history
        WHERE fund_id = ? ORDER BY nav DESC LIMIT 1
    ");
    $highestNavStmt->execute([$fund_id]);
    $hn = $highestNavStmt->fetch();
    $highestNav     = $hn ? (float)$hn['max_nav'] : $latestNav;
    $highestNavDate = $hn ? $hn['nav_date'] : $latestNavDate;

    // ✅ FIX: Upsert also uses NULL-safe comparison for folio
    $existStmt = $db->prepare("
        SELECT id FROM mf_holdings
        WHERE portfolio_id = ? AND fund_id = ?
          AND (folio_number = ? OR (folio_number IS NULL AND ? IS NULL))
    ");
    $existStmt->execute([$portfolio_id, $fund_id, $folio, $folio]);
    $existing = $existStmt->fetch();

    if ($existing) {
        $upd = $db->prepare("
            UPDATE mf_holdings SET
                total_units=?, avg_cost_nav=?, total_invested=?,
                value_now=?, gain_loss=?, gain_pct=?, cagr=?,
                first_purchase_date=?, ltcg_date=?, lock_in_date=?, withdrawable_date=?,
                investment_fy=?, withdrawable_fy=?, gain_type=?,
                highest_nav=?, highest_nav_date=?,
                is_active=?
            WHERE id=?
        ");
        $upd->execute([
            $totalUnits, $avgCostNav, $totalInvested,
            $valueNow, $gainLoss, $gainPct, $cagr,
            $firstPurchase, $ltcgDate, $lockInDate, $withdrawableDate,
            $investmentFy, $withdrawableFy, $gainType,
            $highestNav, $highestNavDate,
            $isActive, $existing['id']
        ]);
    } else {
        $ins = $db->prepare("
            INSERT INTO mf_holdings
            (portfolio_id, fund_id, folio_number, total_units, avg_cost_nav, total_invested,
             value_now, gain_loss, gain_pct, cagr, first_purchase_date, ltcg_date,
             lock_in_date, withdrawable_date, investment_fy, withdrawable_fy, gain_type,
             highest_nav, highest_nav_date, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
            $portfolio_id, $fund_id, $folio, $totalUnits, $avgCostNav, $totalInvested,
            $valueNow, $gainLoss, $gainPct, $cagr, $firstPurchase, $ltcgDate,
            $lockInDate, $withdrawableDate, $investmentFy, $withdrawableFy, $gainType,
            $highestNav, $highestNavDate, $isActive
        ]);
    }
}