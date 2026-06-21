<?php
/**
 * WealthDash — HoldingCalculator
 * Recalculates stock_holdings from stock_transactions.
 * Called after every BUY / SELL / BONUS / SPLIT / DELETE.
 *
 * t215: Computes avg_buy_price (cost basis), total_invested,
 *       gain_type (STCG/LTCG), cagr, gain_pct.
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

class HoldingCalculator
{
    /**
     * Recalculate a single stock holding for a portfolio.
     * Creates the holding row if it doesn't exist yet.
     */
    public static function recalculate_stock_holding(int $portfolioId, int $stockId): void
    {
        /* ── Aggregate all transactions ──────────────────────────── */
        $txns = DB::fetchAll(
            "SELECT txn_type, txn_date, quantity, price, value_at_cost
             FROM stock_transactions
             WHERE portfolio_id = ? AND stock_id = ?
             ORDER BY txn_date ASC, id ASC",
            [$portfolioId, $stockId]
        );

        if (empty($txns)) {
            // No transactions left — zero-out and deactivate
            DB::run(
                "UPDATE stock_holdings
                 SET quantity=0, avg_buy_price=0, total_invested=0,
                     current_value=0, gain_loss=0, gain_pct=0,
                     gain_type=NULL, is_active=0
                 WHERE portfolio_id=? AND stock_id=?",
                [$portfolioId, $stockId]
            );
            return;
        }

        /* ── FIFO-like running totals ────────────────────────────── */
        $holdingQty      = 0.0;
        $totalCost       = 0.0;   // running cost basis for remaining shares
        $totalInvested   = 0.0;   // sum of all BUY value_at_cost
        $firstPurchase   = null;
        $lastTxnDate     = null;

        foreach ($txns as $t) {
            $qty  = (float)$t['quantity'];
            $vac  = (float)$t['value_at_cost'];  // pre-computed in stocks_add.php
            $type = strtoupper($t['txn_type']);
            $lastTxnDate = $t['txn_date'];

            if ($type === 'BUY') {
                $holdingQty    += $qty;
                $totalCost     += $vac;
                $totalInvested += $vac;
                if (!$firstPurchase) $firstPurchase = $t['txn_date'];

            } elseif ($type === 'SELL') {
                if ($holdingQty > 0) {
                    // Reduce cost proportionally (avg cost method)
                    $costPerShare  = $holdingQty > 0 ? $totalCost / $holdingQty : 0;
                    $costReduced   = $costPerShare * min($qty, $holdingQty);
                    $holdingQty    = max(0.0, $holdingQty - $qty);
                    $totalCost     = max(0.0, $totalCost - $costReduced);
                }

            } elseif ($type === 'BONUS') {
                // Bonus shares: zero cost, just increase quantity
                $holdingQty += $qty;
                if (!$firstPurchase) $firstPurchase = $t['txn_date'];

            } elseif ($type === 'SPLIT') {
                // Split: quantity changes, avg price adjusts
                // value_at_cost stored as 0 for splits; quantity = new total or ratio
                // Convention: quantity field holds the NEW total shares after split
                // Recalculate avg_price: same totalCost, new qty
                $holdingQty = $qty;   // direct new total
            }
        }

        /* ── Avg buy price (cost basis per share) ────────────────── */
        $avgBuyPrice = ($holdingQty > 0) ? round($totalCost / $holdingQty, 4) : 0.0;
        $totalCost   = round($totalCost, 2);
        $totalInvested = round($totalInvested, 2);

        /* ── Live price from stock_master ────────────────────────── */
        $sm = DB::fetchRow(
            "SELECT latest_price, latest_price_date FROM stock_master WHERE id = ?",
            [$stockId]
        );
        $livePrice   = (float)($sm['latest_price'] ?? 0);
        $currentVal  = $livePrice > 0 ? round($holdingQty * $livePrice, 2) : 0.0;
        $gainLoss    = round($currentVal - $totalCost, 2);
        $gainPct     = $totalCost > 0 ? round($gainLoss / $totalCost * 100, 4) : 0.0;

        /* ── STCG / LTCG classification ──────────────────────────── */
        $gainType = null;
        if ($firstPurchase && $holdingQty > 0) {
            $daysHeld = (int)(new DateTime('today'))->diff(new DateTime($firstPurchase))->days;
            $gainType = $daysHeld >= 365 ? 'LTCG' : 'STCG';
        }

        $isActive = $holdingQty > 0 ? 1 : 0;

        /* ── Upsert stock_holdings ───────────────────────────────── */
        DB::run(
            "INSERT INTO stock_holdings
                 (portfolio_id, stock_id, quantity, avg_buy_price, total_invested,
                  avg_price, invested_amount, current_value, gain_loss, gain_pct,
                  current_price, first_purchase_date, gain_type, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                 quantity            = VALUES(quantity),
                 avg_buy_price       = VALUES(avg_buy_price),
                 total_invested      = VALUES(total_invested),
                 avg_price           = VALUES(avg_price),
                 invested_amount     = VALUES(invested_amount),
                 current_value       = VALUES(current_value),
                 gain_loss           = VALUES(gain_loss),
                 gain_pct            = VALUES(gain_pct),
                 current_price       = VALUES(current_price),
                 first_purchase_date = VALUES(first_purchase_date),
                 gain_type           = VALUES(gain_type),
                 is_active           = VALUES(is_active)",
            [
                $portfolioId, $stockId,
                round($holdingQty, 4),
                $avgBuyPrice,
                $totalInvested,
                $avgBuyPrice,        // legacy avg_price column
                $totalInvested,      // legacy invested_amount column
                $currentVal,
                $gainLoss,
                $gainPct,
                $livePrice,
                $firstPurchase,
                $gainType,
                $isActive,
            ]
        );
    }

    /**
     * Recalculate ALL stock holdings for a portfolio.
     * Used after bulk price refresh.
     */
    public static function recalculate_stocks(int $portfolioId): void
    {
        $stocks = DB::fetchAll(
            "SELECT DISTINCT stock_id FROM stock_transactions WHERE portfolio_id = ?",
            [$portfolioId]
        );
        foreach ($stocks as $s) {
            self::recalculate_stock_holding($portfolioId, (int)$s['stock_id']);
        }
    }
}
