-- ============================================================
-- Migration 007: Fund Returns + Risk Metrics Columns
-- Tasks: t27, t93, t94, t164, t165, t166, t167
-- Run: php database/migrate.php
-- ============================================================

ALTER TABLE funds
  ADD COLUMN IF NOT EXISTS returns_1y        DECIMAL(8,4)  DEFAULT NULL COMMENT '1-Year CAGR %',
  ADD COLUMN IF NOT EXISTS returns_3y        DECIMAL(8,4)  DEFAULT NULL COMMENT '3-Year CAGR %',
  ADD COLUMN IF NOT EXISTS returns_5y        DECIMAL(8,4)  DEFAULT NULL COMMENT '5-Year CAGR %',
  ADD COLUMN IF NOT EXISTS returns_6m        DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS returns_1m        DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS returns_3m        DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS returns_since_inception DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS sharpe_ratio      DECIMAL(8,4)  DEFAULT NULL COMMENT 'Risk-adjusted return (Rf=6.5%)',
  ADD COLUMN IF NOT EXISTS sortino_ratio     DECIMAL(8,4)  DEFAULT NULL COMMENT 'Downside risk adjusted',
  ADD COLUMN IF NOT EXISTS calmar_ratio      DECIMAL(8,4)  DEFAULT NULL COMMENT 'Return / Max Drawdown',
  ADD COLUMN IF NOT EXISTS max_drawdown      DECIMAL(8,4)  DEFAULT NULL COMMENT 'Worst peak-to-trough %',
  ADD COLUMN IF NOT EXISTS standard_deviation DECIMAL(8,4) DEFAULT NULL COMMENT 'Annualised volatility %',
  ADD COLUMN IF NOT EXISTS alpha             DECIMAL(8,4)  DEFAULT NULL COMMENT 'Excess return vs benchmark',
  ADD COLUMN IF NOT EXISTS beta              DECIMAL(8,4)  DEFAULT NULL COMMENT 'Market sensitivity',
  ADD COLUMN IF NOT EXISTS r_squared         DECIMAL(5,4)  DEFAULT NULL COMMENT 'Correlation with benchmark',
  ADD COLUMN IF NOT EXISTS momentum_score    DECIMAL(5,2)  DEFAULT NULL COMMENT '0-100 weighted momentum',
  ADD COLUMN IF NOT EXISTS category_avg_1y   DECIMAL(8,4)  DEFAULT NULL COMMENT 'Category average 1Y',
  ADD COLUMN IF NOT EXISTS category_avg_3y   DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS category_avg_5y   DECIMAL(8,4)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS returns_updated_at DATETIME     DEFAULT NULL;

-- Indexes for screener sorting
CREATE INDEX IF NOT EXISTS idx_funds_returns_1y  ON funds (returns_1y);
CREATE INDEX IF NOT EXISTS idx_funds_returns_3y  ON funds (returns_3y);
CREATE INDEX IF NOT EXISTS idx_funds_sharpe      ON funds (sharpe_ratio);
CREATE INDEX IF NOT EXISTS idx_funds_alpha        ON funds (alpha);
CREATE INDEX IF NOT EXISTS idx_funds_momentum    ON funds (momentum_score);
