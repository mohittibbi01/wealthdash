-- ============================================================
-- WealthDash — Migration t215
-- Task   : t215 — Stocks: CAGR, Gain%, Cost Basis per stock
-- Tables : stock_master (ALTER), stock_holdings (ALTER)
-- Run    : php database/migrate.php t215
-- ============================================================

-- ── stock_master: Add latest_price + latest_price_date ───────
ALTER TABLE `stock_master`
    ADD COLUMN IF NOT EXISTS `latest_price`      DECIMAL(12,4)  NULL DEFAULT NULL
        COMMENT 'Last fetched live price (INR)'
        AFTER `pe_ratio`,
    ADD COLUMN IF NOT EXISTS `latest_price_date` DATE           NULL DEFAULT NULL
        COMMENT 'Date of latest_price fetch'
        AFTER `latest_price`;

-- ── stock_holdings: Add t215 columns ─────────────────────────
--   avg_buy_price  — weighted avg of all BUY + BONUS + SPLIT txns
--   total_invested — sum of value_at_cost for BUY transactions
--   gain_type      — STCG / LTCG (derived from first_purchase_date)
--   is_active      — 0 when quantity reaches 0 (fully sold)
ALTER TABLE `stock_holdings`
    ADD COLUMN IF NOT EXISTS `avg_buy_price`      DECIMAL(12,4)       NOT NULL DEFAULT 0.0000
        COMMENT 'Weighted average cost per share (cost basis)'
        AFTER `quantity`,
    ADD COLUMN IF NOT EXISTS `total_invested`     DECIMAL(16,2)       NOT NULL DEFAULT 0.00
        COMMENT 'Total cost basis = sum(qty * price + charges) for BUY txns'
        AFTER `avg_buy_price`,
    ADD COLUMN IF NOT EXISTS `gain_type`          ENUM('STCG','LTCG') NULL DEFAULT NULL
        COMMENT 'STCG if held < 12m, LTCG if >= 12m (equity)'
        AFTER `gain_pct`,
    ADD COLUMN IF NOT EXISTS `is_active`          TINYINT(1)          NOT NULL DEFAULT 1
        COMMENT '0 when holding is fully sold out'
        AFTER `notes`;

-- ── Indexes for t215 queries ──────────────────────────────────
ALTER TABLE `stock_holdings`
    ADD INDEX IF NOT EXISTS `idx_sh_active`    (`is_active`),
    ADD INDEX IF NOT EXISTS `idx_sh_gain_type` (`gain_type`);

-- ── Backfill: compute avg_buy_price + total_invested from existing txns ──
--   Only runs where columns exist but are zero (safe to run repeatedly)
UPDATE `stock_holdings` sh
JOIN (
    SELECT
        portfolio_id,
        stock_id,
        ROUND(SUM(value_at_cost) / NULLIF(SUM(
            CASE WHEN txn_type IN ('BUY','BONUS','SPLIT') THEN quantity ELSE 0 END
        ), 0), 4)                                          AS avg_bp,
        ROUND(SUM(CASE WHEN txn_type = 'BUY' THEN value_at_cost ELSE 0 END), 2) AS tot_inv,
        MIN(CASE WHEN txn_type = 'BUY' THEN txn_date END) AS first_buy
    FROM `stock_transactions`
    WHERE txn_type IN ('BUY','BONUS','SPLIT')
    GROUP BY portfolio_id, stock_id
) calc ON calc.portfolio_id = sh.portfolio_id AND calc.stock_id = sh.stock_id
SET
    sh.avg_buy_price     = COALESCE(calc.avg_bp,  0),
    sh.total_invested    = COALESCE(calc.tot_inv, 0),
    sh.first_purchase_date = COALESCE(sh.first_purchase_date, calc.first_buy)
WHERE sh.avg_buy_price = 0;

-- ── Backfill: gain_type based on holding period ───────────────
UPDATE `stock_holdings`
SET gain_type = CASE
    WHEN first_purchase_date IS NOT NULL
         AND DATEDIFF(CURDATE(), first_purchase_date) >= 365
    THEN 'LTCG'
    WHEN first_purchase_date IS NOT NULL
    THEN 'STCG'
    ELSE NULL
END
WHERE gain_type IS NULL;

-- ── Backfill: is_active = 0 for fully sold holdings ──────────
UPDATE `stock_holdings`
SET is_active = 0
WHERE quantity <= 0 AND is_active = 1;

SELECT 'Migration t215 complete — stock_holdings: avg_buy_price, total_invested, gain_type, is_active added; stock_master: latest_price, latest_price_date added' AS status;
