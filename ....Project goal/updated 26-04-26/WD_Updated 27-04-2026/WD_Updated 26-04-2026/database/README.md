# WealthDash — Database Setup Guide

## Folder Structure

```
database/
├── new/                        ← ✅ USE THESE FILES
│   ├── 01_schema.sql           ← Fresh install: base schema (all tables, merged)
│   ├── 02_seed.sql             ← Fresh install: seed data (AMCs, NPS schemes, stocks, settings)
│   ├── 03_data_fixes.sql       ← Existing DB: fix corrupted data (run ONCE)
│   └── 04_migrations.sql       ← Existing DB: add missing columns/tables (run ONCE)
│
└── archive/                    ← ❌ OLD FILES — kept for reference only, do not run
    ├── 01_schema_complete.sql
    ├── 02_seed.sql ... 45_db_indexes.sql
    └── migrations/
```

---

## Fresh Install (New Database)

Run **in order**, skip nothing:

```bash
# Option A: phpMyAdmin
# Import each file one by one in sequence

# Option B: MySQL CLI
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS wealthdash CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p wealthdash < database/new/01_schema.sql
mysql -u root -p wealthdash < database/new/02_seed.sql
```

> Skip `03_data_fixes.sql` and `04_migrations.sql` — not needed on fresh install.

---

## Existing Database (Upgrade)

Run in order:

```bash
mysql -u root -p wealthdash < database/new/04_migrations.sql   # adds missing columns/tables
mysql -u root -p wealthdash < database/new/03_data_fixes.sql   # fixes corrupted data
```

> Both files are **idempotent** — safe to run multiple times (IF NOT EXISTS / IF EXISTS guards everywhere).

---

## What Changed vs Old Files

| Old Problem | Fix Applied |
|---|---|
| 45 files running in sequence, frequently breaking | Consolidated into 4 logical files |
| `style_box` column defined in both `15_` and `24_` (conflict) | Merged: single GENERATED column definition in `01_schema.sql` |
| `fund_managers` table in both `16_` and `migrations/013_` | Merged: one canonical definition |
| `import_logs` in both `17_` and `migrations/015_` | Merged: one canonical definition |
| `net_worth_snapshots` vs `networth_snapshots` naming | Standardised to `net_worth_snapshots` |
| Files 27–40 were mostly TODO stubs with no real SQL | Removed stubs; real tables (sgb, esop, goals, crypto) moved to correct files |
| `peak_nav/setup.sql` duplicated `21_peak_nav_setup.sql` | Merged into `02_seed.sql` (seeding step) and `01_schema.sql` (table creation) |
| `fund_watchlist` vs `mf_watchlist` naming inconsistency | Standardised to `mf_watchlist` (matches PHP codebase) |
| `returns_1y` (migration) vs `return_1y` (base schema) naming clash | Both kept in `funds` table for PHP backward compatibility |
| migration 22 had no header/context | Moved to `03_data_fixes.sql` (FIX 004) with explanation |

---

## Table Count

`01_schema.sql` creates **~65 tables** covering:
- Auth & users (users, sessions, user_sessions, otp_tokens, google_auth, audit_log)  
- Portfolios & MF (portfolios, funds, nav_history, mf_holdings, mf_transactions, sip_schedules)
- Screener & analytics (mf_watchlist, price_alerts, fund_ratings, fund_rolling_returns, fund_stock_holdings)
- Stocks (stock_master, stock_holdings, stock_transactions, stock_price_alerts)
- NPS, FD, Savings, EPF, Post Office
- SGB, ESOP, Crypto, Insurance, Loans
- Goals, AI, Notifications, Calendar
- NAV download queue & progress tracking
