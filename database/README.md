# WealthDash — Database Setup
**Naye PC pe fresh install ke liye — sari files isi order mein run karo**
Last updated: 05 April 2026

---

## 📋 Files — Run Sequence

| # | File | Kya karta hai | Type |
|---|------|---------------|------|
| 01 | `01_schema_complete.sql` | **BASE** — Saare 43 core tables (migrations 001–016 included) | Schema |
| 02 | `02_seed.sql` | 36 AMCs, 25 NSE/BSE stocks, app_settings defaults | Data |
| 03 | `03_nps_schemes_seed.sql` | 34 NPS PFM schemes (SBI, LIC, UTI, HDFC, ICICI...) | Data |
| 04 | `04_fix_corrupted_data.sql` | mf_holdings data fix — fresh DB pe safe no-op | Fix |
| 05 | `05_backfill_fund_categories.sql` | funds category NULL fix — fresh DB pe safe no-op | Fix |
| 06 | `06_fix_mf_transactions_fy.sql` | investment_fy format fix — fresh DB pe safe no-op | Fix |
| 07 | `07_fund_returns.sql` | funds table mein 1Y/3Y/5Y/10Y returns + Sharpe + Max DD columns | Alter |
| 08 | `08_nfo_tracker.sql` | `nfo_tracker` table (NFO watch feature) | Table |
| 09 | `09_watchlist.sql` | `fund_watchlist` table | Table |
| 10 | `10_notifications.sql` | `notifications` + `notification_prefs` tables | Table |
| 11 | `11_price_alerts.sql` | `price_alerts` table | Table |
| 12 | `12_alpha_beta.sql` | funds table mein Alpha, Beta, Std Dev, R² columns | Alter |
| 13 | `13_screener_metrics.sql` | funds table mein Sortino Ratio + Category Rank columns | Alter |
| 14 | `14_mf_dividends_notes.sql` | `mf_dividends` + `mf_holding_notes` tables | Table |
| 15 | `15_fund_holdings.sql` | `fund_stock_holdings` + `fund_overlap_cache` tables | Table |
| 16 | `16_fund_managers.sql` | `fund_managers` table | Table |
| 17 | `17_expense_history_import_log.sql` | `expense_ratio_history` + `import_logs` tables | Table |
| 18 | `18_rebalancing_stepup.sql` | `rebalancing_targets` table | Table |
| 19 | `19_nav_proxy_cache.sql` | `nav_proxy_cache` table | Table |
| 20 | `20_networth_timeline.sql` | `net_worth_snapshots` table (Net Worth chart) | Table |
| 21 | `21_peak_nav_setup.sql` | `mf_peak_progress` table + seed from funds | Table |
| 22 | `22_NAV_fix_run_only_if_needed.sql` | ⚠️ OPTIONAL — Sirf tab run karo jab NAV status pe from_date NULL aaye | Fix |

**Total: 22 files → 21 run karo (22 optional hai)**

---

## 🚀 phpMyAdmin se kaise run karo

1. XAMPP start karo (Apache + MySQL)
2. `http://localhost/phpmyadmin` open karo
3. Left mein **"New"** → Database name: `wealthdash` → Collation: `utf8mb4_unicode_ci` → **Create**
4. `wealthdash` select karo (left panel)
5. **Import** tab → File choose → **Go**
6. **Isi sequence mein** 01 se 21 tak ek-ek import karo
7. File 22 sirf tab import karo jab NAV status page pe `from_date` NULL dikhe

---

## 💻 Command Line (ek hi shot mein sab)

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS wealthdash CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd /path/to/database/

for f in 01_schema_complete.sql 02_seed.sql 03_nps_schemes_seed.sql \
         04_fix_corrupted_data.sql 05_backfill_fund_categories.sql 06_fix_mf_transactions_fy.sql \
         07_fund_returns.sql 08_nfo_tracker.sql 09_watchlist.sql \
         10_notifications.sql 11_price_alerts.sql 12_alpha_beta.sql \
         13_screener_metrics.sql 14_mf_dividends_notes.sql 15_fund_holdings.sql \
         16_fund_managers.sql 17_expense_history_import_log.sql 18_rebalancing_stepup.sql \
         19_nav_proxy_cache.sql 20_networth_timeline.sql 21_peak_nav_setup.sql; do
  echo "▶ Running: $f"
  mysql -u root -p wealthdash < "$f"
  echo "  ✅ Done"
done
```

---

## ✅ Expected tables after full run (~57 total)

`app_settings`, `audit_log`, `bank_accounts`, `epf_accounts`, `expense_ratio_history`,
`fd_accounts`, `fund_houses`, `fund_managers`, `fund_overlap_cache`, `fund_stock_holdings`,
`fund_watchlist`, `funds`, `goal_buckets`, `goal_contributions`, `goal_fund_links`,
`google_auth`, `import_logs`, `insurance_policies`, `investment_goals`, `loan_accounts`,
`login_attempts`, `mf_dividends`, `mf_holding_notes`, `mf_holdings`, `mf_peak_progress`,
`mf_transactions`, `nav_download_progress`, `nav_history`, `nav_proxy_cache`,
`net_worth_snapshots`, `nfo_tracker`, `notification_prefs`, `notifications`, `nps_holdings`,
`nps_nav_history`, `nps_schemes`, `nps_transactions`, `otp_tokens`, `password_resets`,
`po_schemes`, `portfolios`, `price_alerts`, `rebalancing_targets`, `savings_accounts`,
`savings_interest`, `savings_interest_log`, `sessions`, `sip_schedules`,
`stock_corporate_actions`, `stock_dividends`, `stock_holdings`, `stock_master`,
`stock_transactions`, `users` + more

---

## ⚠️ Important

- **`01_schema_complete.sql`** pehle ZAROOR run karo — ye base hai
- Files 04, 05, 06 (data fixes) — fresh empty DB pe safe hain (no rows = no effect)
- `21_peak_nav_setup.sql` mein `DROP TABLE IF EXISTS mf_peak_progress` hai — fresh install pe safe
- Sab files `IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS` use karti hain — double-run safe
