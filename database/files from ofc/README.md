# WealthDash — Fixed Database Files
**Fresh install ke liye — sari files isi order mein run karo**

---

## ✅ Kya fix hua hai in files mein

| File | Bug | Fix |
|------|-----|-----|
| `02_seed.sql` | `registration_open` key missing tha → Register page "closed" dikhta tha | Key add ki with value `'1'` |
| `04_fix_corrupted_data.sql` | Step 4 `funds.min_ltcg_days` column reference karta tha jo base schema mein nahi tha | Auto-add guard lagaya |
| `07_fund_returns.sql` | `ADD COLUMN IF NOT EXISTS` — MySQL 8.0 mein error deta tha | INFORMATION_SCHEMA check se replace kiya |
| `12_alpha_beta.sql` | Same — `IF NOT EXISTS` syntax error | Fixed |
| `13_screener_metrics.sql` | Same — `IF NOT EXISTS` syntax error | Fixed |
| `15_fund_holdings.sql` | Same — `IF NOT EXISTS` syntax error | Fixed |
| `16_fund_managers.sql` | Same — `IF NOT EXISTS` syntax error | Fixed |
| `18_rebalancing_stepup.sql` | Same — `IF NOT EXISTS` syntax error | Fixed |
| `22_NAV_fix_run_only_if_needed.sql` | `status = 'completed'` invalid enum value tha | `'done'` se replace kiya |
| `23_fund_ratings.sql` | `IF NOT EXISTS` syntax error | Fixed |
| `24_style_box.sql` | `IF NOT EXISTS` syntax error | Fixed |

---

## 📋 Run Order (phpMyAdmin → SQL tab → Import)

| # | File | Type |
|---|------|------|
| 01 | `01_schema_complete.sql` | **BASE** — pehle ZAROOR chalao |
| 02 | `02_seed.sql` ⚡ | Data + registration fix |
| 03 | `03_nps_schemes_seed.sql` | NPS schemes data |
| 04 | `04_fix_corrupted_data.sql` ⚡ | Data fix (self-guarded) |
| 05 | `05_backfill_fund_categories.sql` | Category fix |
| 06 | `06_fix_mf_transactions_fy.sql` | FY format fix |
| 07 | `07_fund_returns.sql` ⚡ | Returns columns |
| 08 | `08_nfo_tracker.sql` | NFO table |
| 09 | `09_watchlist.sql` | Watchlist table |
| 10 | `10_notifications.sql` | Notifications |
| 11 | `11_price_alerts.sql` | Price alerts |
| 12 | `12_alpha_beta.sql` ⚡ | Alpha/Beta columns |
| 13 | `13_screener_metrics.sql` ⚡ | Screener columns |
| 14 | `14_mf_dividends_notes.sql` | Dividends table |
| 15 | `15_fund_holdings.sql` ⚡ | Holdings table |
| 16 | `16_fund_managers.sql` ⚡ | Fund managers table |
| 17 | `17_expense_history_import_log.sql` | Expense history |
| 18 | `18_rebalancing_stepup.sql` ⚡ | Rebalancing table |
| 19 | `19_nav_proxy_cache.sql` | NAV cache table |
| 20 | `20_networth_timeline.sql` | Net worth table |
| 21 | `21_peak_nav_setup.sql` | Peak NAV table |
| 22 | `22_NAV_fix_run_only_if_needed.sql` ⚡ | ⚠️ OPTIONAL — sirf tab run karo jab NAV status mein from_date NULL aaye |
| 23 | `23_fund_ratings.sql` ⚡ | Fund ratings table |
| 24 | `24_style_box.sql` ⚡ | Style box columns |

⚡ = Fixed file

---

## 🚀 Sab kuch ek baar mein (Command Line)

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS wealthdash CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd /path/to/database/
for f in 01_schema_complete.sql 02_seed.sql 03_nps_schemes_seed.sql \
         04_fix_corrupted_data.sql 05_backfill_fund_categories.sql 06_fix_mf_transactions_fy.sql \
         07_fund_returns.sql 08_nfo_tracker.sql 09_watchlist.sql \
         10_notifications.sql 11_price_alerts.sql 12_alpha_beta.sql \
         13_screener_metrics.sql 14_mf_dividends_notes.sql 15_fund_holdings.sql \
         16_fund_managers.sql 17_expense_history_import_log.sql 18_rebalancing_stepup.sql \
         19_nav_proxy_cache.sql 20_networth_timeline.sql 21_peak_nav_setup.sql \
         23_fund_ratings.sql 24_style_box.sql; do
  echo "▶ Running: $f"
  mysql -u root -p wealthdash < "$f"
done
```

---

## ⚠️ Important Notes

- Ye sare files **double-run safe** hain — agar column already exist karta hai to skip ho jaega, error nahi aayega
- MySQL 8.0 aur MariaDB dono pe kaam karta hai
- Registration fix `02_seed.sql` mein hai — woh run hone ke baad `localhost/wealthdash/auth/register.php` kaam karega
