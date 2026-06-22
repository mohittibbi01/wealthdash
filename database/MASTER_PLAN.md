# WealthDash Database Reorganization — Master Plan (Review ke liye)

Ye sirf **plan** hai, abhi koi file nahi banai. Pehle ye dekh lo, phir
confirm karo, tab main actual split-up shuru karunga.

---

## Scope — kitna bada kaam hai

- **226 SQL files** input mein (47 numbered root files + 179 `migrations/`
  folder ki task-ID files + 1 combined summary file)
- **303 distinct tables** total in sab files mein
- **39 tables aisi hain jo do-ya-zyada files mein bani hain** (genuine
  duplicates/conflicts — inhe resolve karna sabse zaroori/risky kaam hai)
- `migrations_20jun2026_combined.sql` ko main analysis se nikal raha hu
  kyunki woh khud ek summary/copy file hai (maine hi pichli baar banayi
  thi), source-of-truth nahi

---

## Final structure — naming aur organization

```
database/
  001_core/                    ← users, sessions, app_settings, audit_log (sabse pehle)
  002_mutual_funds/             ← funds, mf_holdings, nav_history, sip_tracker, NFO, etc.
  003_stocks/                   ← stock_holdings, screener, watchlist, ESOP, benchmark
  004_fd_rd_savings/            ← FD, RD, savings accounts, sweep FDs
  005_gold/                     ← gold holdings, transactions, SGB
  006_bonds_govt_securities/    ← bonds, G-Secs, T-Bills
  007_crypto/                   ← crypto holdings, DeFi, cold wallet, tax
  008_epf_nps_retirement/       ← EPF, NPS, PPF, gratuity, retirement plans
  009_insurance/                ← insurance policies, ULIP, premiums
  010_loans_credit/             ← loans, credit cards, CIBIL
  011_property/                 ← real estate, property valuations
  012_goals_budget/             ← goals, budget plans, buckets
  013_tax/                      ← AIS, indexation, LTA, 80C, capital gains
  014_ai_features/              ← AI chat, advisor, narrative, nudges
  015_admin_infra/              ← audit log, DB tools, perf monitoring, errors
  016_import_export/            ← Groww, CSV importers, bulk import
  017_reports_sharing/          ← report sharing, scheduled reports
  018_journal_gamification/     ← journal, streaks, badges, checkins
  019_notifications_alerts/     ← notifications, smart alerts, price alerts
  020_dashboard_ui/             ← dashboard layouts, widgets
  021_broker_integrations/      ← Zerodha (separate — bahut tables hain isme)
  022_other_investments/        ← ETF, PMS, REITs/InvITs, Smallcase

  seeds/
    001_app_settings_seed.sql
    002_amc_seed.sql
    003_stock_master_seed.sql
    004_nps_schemes_seed.sql
    ... (jitni bhi seed-data files milengi, alag se)

  install.sql  (ya install.php)  ← sabko sahi order mein run karne wali master script
  README.md    ← naye PC pe kaise setup karna hai, kis order mein
```

Har domain-folder ke andar, har table ki **apni alag `.sql` file** hogi,
naam format: `XXX_tablename.sql` (XXX = us domain ke andar ka number,
dependency order mein — jaise `funds` table `mf_holdings` se pehle
aani chahiye kyunki holdings funds ko reference karti hai).

**Estimated total files:** karib **300-320 table files** + **10-15 seed
files** + install script + README = **~330 files**. Tumne bola tha
"jada files ban rahi hai to koi dikkat nahi" — to ye scope expected
hai, bata raha hu taaki clear ho.

---

## 39 Duplicate/Conflict Tables — inka kya karunga

Ye sabse important aur risky hissa hai. Har ek ke liye main:
1. Dono/saari versions ka schema compare karunga (columns, types, keys)
2. Jo zyada complete/naya/sahi lage use "winner" maanunga
3. Agar dono mein kuch unique columns hain to merge kar dunga (sab columns ek file mein)
4. Jo discard hoga, usko bhi note kar dunga (kahin reference toot na jaye)

Neeche initial list hai (abhi tak sirf naam-level dekha hai, content
compare karna baaki hai):

| Table | Konsi files mein mila | Status |
|-------|------------------------|--------|
| `goals` | `41_pending_tasks.sql`, `migrations/025_goal_buckets.sql` | Review pending |
| `audit_log` | `01_schema_complete.sql`, `migrations/t50_migration.sql` | Review pending |
| `app_settings` | `01_schema_complete.sql`, `migrations/t52_migration.sql` | Review pending |
| `bank_accounts`, `bank_balance_history` | `01_schema_complete.sql`, `migrations/t43_migration.sql` | Review pending |
| `crypto_holdings`, `crypto_transactions` | `38_crypto_holdings.sql`, `41_pending_tasks.sql`, `migrations/t40_migration.sql` (3-way!) | Review pending |
| `sgb_holdings`, `sgb_interest_log`, `gold_price_cache` | 4 files each (`29_sgb_holdings.sql`, `017_sgb.sql`, `t113_migration.sql`, `t394_sgb_holdings.sql`) | Review pending |
| `esop_grants`, `esop_vesting_events` | 3 files each | Review pending |
| `insurance_policies` | 3 files | Review pending |
| `epf_monthly_log` | 3 files (`t340`, `t467`, `t46`) | Review pending |
| ...aur 30 aur (poori list humare paas hai) | | |

Ye 39 cases mein se kai sirf "ek table jisme baad mein columns add hue"
honge (normal evolution — easy fix), lekin kuch genuinely **do alag
implementations** ho sakte hain jaise humne pehle router-merge mein
dekha tha (cold_wallet wala case). Har ek ko individually dekhna padega.

---

## migrate.php ka kya hoga

Tumhare paas already ek working `migrate.php` runner hai jo numbered
aur task-ID files dono ko auto-sort karke chalata hai, aur ek
`migration_log` table mein track karta hai ki kya already run ho chuka
hai. Naye structure ke baad ye **kaam nahi karega** kyunki file-naming
pattern badal jayega.

Maine socha hai — naye structure ke liye bhi waisa hi ek runner
(`install.php` ya updated `migrate.php`) bana dunga jo naye
`XXX_domain/YYY_table.sql` pattern ko samjhe.

---

## Kaam karne ka order (main jis tarike se aage badhunga)

1. **Pehle saare 39 duplicate/conflict tables resolve karunga** — ye
   sabse risky hissa hai, pehle nipta lena better hai
2. **Phir domain-by-domain split karunga** (core → mutual funds → stocks
   → ... → broker integrations), file dwara file, naming ke saath
3. **Seed data files alag se nikalunga** (jo bhi `INSERT INTO` waale
   blocks milenge data-population ke liye, schema se alag)
4. **migrate.php ko update/naya install script banaunga**
5. **README likunga** poora naya setup-guide ke saath
6. **Final zip banaunga**, organized structure ke saath

---

## Mujhe tumse sirf itna confirm karna hai

1. **Domain-folder naming theek hai?** (jaise `002_mutual_funds`,
   `007_crypto` — ya koi alag naming convention chahiye?)
2. **`migrations_20jun2026_combined.sql` ko discard kar du?** (ye sirf
   summary file thi, ab har table apni alag file mein hoga to iski
   zaroorat nahi rahegi)
3. Koi specific domain hai jo tum pehle dekhna chahoge, ya jaisa order
   maine upar likha hai (core → mutual funds → stocks → ...) waisa hi
   theek hai?

Confirm kar do, phir main shuru karta hu — koi jaldबाzi nahi karunga,
ek-ek domain check karke aage badhunga.
