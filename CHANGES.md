# t164 + t166 — Sharpe Ratio + Max Drawdown in MF Screener

## Files Changed
- `api/mutual_funds/fund_screener.php`
- `templates/pages/mf_screener.php`

## Prerequisites
Run these first (already exist in cron/):
  php cron/populate_nav_history.php   ← t160
  php cron/calculate_returns.php      ← t161+t164+t166

These populate `funds.sharpe_ratio`, `funds.max_drawdown`, `funds.max_drawdown_date`

## What's New

### fund_screener.php
- Detects `sharpe_ratio` column (safe fallback if cron hasn't run yet)
- SELECTs: `sharpe_ratio`, `max_drawdown`, `max_drawdown_date`
- Maps to API response as `sharpe_ratio`, `max_drawdown`, `max_drawdown_date`
- Sort keys added: `sharpe_desc`, `sharpe_asc`, `mdd_asc`, `mdd_desc`
- Also added return sorts: `ret1y_desc/asc`, `ret3y_desc/asc`, `ret5y_desc/asc`
- Guard: falls back to name sort if columns don't exist yet

### mf_screener.php
- Table: 2 new columns — "MDD%" (Max Drawdown) + "Sharpe"
  - MDD: color coded #16a34a(<15%) #d97706(<25%) #dc2626(<40%) #9f1239(>40%)
  - Sharpe: 🌟(≥1.5) ✓(≥1) ⚠(<0), color coded green→red
  - Both sortable by clicking header
- Drawer (side panel): "Risk Metrics" section with MDD + Sharpe boxes
  - Sharpe shows grade: Excellent/Good/Fair/Below Avg/Poor + Rf=6.5% note
  - MDD shows % + date of worst drawdown
- Fund comparison table: Max Drawdown + Sharpe Ratio rows added
- Sort thId map updated for new column headers
