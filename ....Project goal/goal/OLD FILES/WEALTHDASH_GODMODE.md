# WEALTHDASH — GOD MODE CONTEXT FILE
> Feed this entire file to Claude before asking it to work on WealthDash.
> Generated: 2026-04-18 | Based on: wd.zip (405 files, ~4.3 MB)

---

## 1. PROJECT OVERVIEW

**WealthDash** is a self-hosted, PHP + vanilla JS personal finance dashboard for Indian investors. It tracks mutual funds, stocks, NPS, FDs, gold, crypto, post-office schemes, and provides tax reports, XIRR, SIP analysis, goal planning, and AI-assisted insights.

- **Stack:** PHP 8.x, MySQL (PDO via `DB::` static class), vanilla JS (no framework), Chart.js, XAMPP/Apache
- **Architecture:** Single-page-ish app. All pages are PHP templates. All AJAX hits `api/index.php` → `api/router.php` → delegated files. URL rewriting via `.htaccess`.
- **Auth:** Session-based, `require_auth()` in `includes/auth_check.php`. Admin role = `ROLE_ADMIN`.
- **Task tracking:** Tasks are labeled `tNNN` (e.g. t24 = Crypto, t386 = 2FA). Status: **117 done, 98 pending** as of Patch v2 (2026-04-05).

---

## 2. DIRECTORY STRUCTURE

```
wealthdash/
├── api/
│   ├── router.php              ← CENTRAL API ROUTER (all ?action= here)
│   ├── index.php               ← just requires router.php
│   ├── admin/                  ← admin-only APIs
│   ├── ai/                     ← AI endpoints (mostly STUBS)
│   ├── ai_advisor/             ← AI advisor endpoints (STUBS)
│   ├── auth/                   ← 2fa.php, session_security.php (⚠️ NOT ROUTED)
│   ├── crypto/                 ← crypto_list.php (LIVE), crypto.php (DEAD), vda_tax.php
│   ├── dashboard/              ← networth_projection.php
│   ├── fd/                     ← FD CRUD + alerts
│   ├── goals/                  ← goals_api.php, goals_advanced.php (⚠️ NOT ROUTED CORRECTLY)
│   ├── mutual_funds/           ← mf_list, mf_add, mf_import_csv_v3, fund_ratings, etc.
│   ├── nav/                    ← update_amfi.php
│   ├── nps/                    ← NPS CRUD
│   ├── notifications.php       ← Notifications + currently handles notif_ actions
│   ├── reports/                ← fy_gains, tax_planning, goal_planning, sip_tracker, etc.
│   ├── savings/                ← savings CRUD
│   ├── stocks/                 ← stocks CRUD
│   └── [many stub directories] ← bonds, broker, cashflow, esop, gold, etc. (all STUBS)
├── config/
│   └── config.php              ← loads .env, defines APP_ROOT, IS_LOCAL, DB class, helpers
├── database/
│   ├── 01_schema_complete.sql  ← Full base schema
│   ├── 02_seed.sql to 40_*.sql ← Incremental migrations (run in order)
│   ├── 41_pending_tasks.sql    ← Latest migration (2FA tables, crypto, goals, sessions)
│   └── [no migration runner]   ← Manual import via phpMyAdmin / mysql CLI
├── includes/
│   ├── auth_check.php          ← require_auth(), is_admin(), csrf helpers
│   ├── helpers.php             ← json_response(), clean(), redirect(), flash, etc.
│   └── tax_engine.php          ← LTCG/STCG tax calculation logic
├── nav_download/
│   ├── nav_worker.php          ← NAV batch download worker (⚠️ BROKEN - missing DB columns)
│   ├── status.php              ← NAV download status UI
│   └── logs/errors.log         ← Live errors from nav_worker
├── public/
│   ├── css/app.css             ← All styles (65KB)
│   └── js/
│       ├── app.js              ← Core JS (portfolios, CSRF, theme, API helper)
│       ├── mf.js               ← MF page JS (458KB — main feature file)
│       ├── mf.js.bak           ← ⚠️ BACKUP FILE EXPOSED IN WEB ROOT
│       ├── crypto.js           ← Crypto page JS
│       └── [many others]
├── templates/
│   ├── layout.php              ← Base HTML layout
│   ├── sidebar.php             ← Nav sidebar
│   ├── topbar.php              ← Top bar + notifications
│   └── pages/                  ← One .php per page
├── nav_download/               ← NAV download system (separate mini-app)
├── peak_nav/                   ← Peak NAV tracker (separate mini-app)
├── .env                        ← Secrets (DB, Google OAuth, MSG91, SMTP)
├── .htaccess                   ← URL rewrite + security headers
└── index.php                   ← Front controller → loads layout + current page
```

---

## 3. CORE PATTERNS TO KNOW

### API Pattern
Every API call hits `api/router.php` via `?action=`. Files are `require`d, not called as classes.
```php
// In router.php:
case 'some_action':
    require APP_ROOT . '/api/some/file.php'; exit;
```
Inside those files, `$userId`, `$isAdmin`, `$action`, `$db` (via `DB::conn()`) are available from router scope.

### DB Class
```php
DB::fetchAll($sql, $params)   // returns array of assoc arrays
DB::fetchOne($sql, $params)   // returns single assoc array
DB::fetchVal($sql, $params)   // returns scalar
DB::run($sql, $params)        // execute (INSERT/UPDATE/DELETE)
DB::conn()                    // returns PDO instance
```

### JSON Response
```php
json_response(bool $success, string $message, array $data = [], int $code = 200)
```

### Auth
```php
$currentUser = require_auth();   // redirects if not logged in, returns user array
$userId = (int)$currentUser['id'];
$isAdmin = is_admin();
```

### CSRF
Router calls `csrf_verify()` for all non-exempt write actions. CSRF token is in `$_SESSION['csrf_token']` and sent as `X-CSRF-Token` header by the JS `API` class in `app.js`.

---

## 4. CONFIRMED BUGS (Fix These)

---

### 🔴 BUG-01 — NAV Download Completely Broken
**Severity:** CRITICAL — NAV downloads have been failing since at least 2026-04-16.
**File:** `nav_download/nav_worker.php`
**Error (from logs):**
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'retry_count' in 'field list'
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'nav_date' in 'field list'
```
**Root Cause:** `nav_download_queue` table was created by an older version of `ensureQueueTable()` that didn't include `retry_count` and `nav_records_added` columns. The current `ensureQueueTable()` uses `CREATE TABLE IF NOT EXISTS` so it silently skips recreating the table, leaving it without the new columns.

Also: `nav_worker.php` queries `funds.nav_date` but some MySQL setups may have this column missing (same pattern).

**Fix:** Add a migration to `ALTER TABLE` the existing queue table:
```sql
-- Run once to fix nav_download_queue
ALTER TABLE nav_download_queue
  ADD COLUMN IF NOT EXISTS retry_count       TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS nav_records_added INT     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS error_msg         VARCHAR(500)    DEFAULT NULL;

-- Verify funds table has nav_date (it does in 01_schema_complete.sql but verify)
-- ALTER TABLE funds ADD COLUMN IF NOT EXISTS nav_date DATE DEFAULT NULL;
```
Or update `ensureQueueTable()` to also run `ALTER TABLE` statements for missing columns (like `ensureNavIndexes()` does for indexes).

---

### 🔴 BUG-02 — Goal CRUD Entirely Broken (Router Misconfiguration)
**Severity:** CRITICAL — `goal_list`, `goal_add`, `goal_edit`, `goal_delete`, `goal_mark_achieved`, `goal_contribute` all silently route to the wrong file.

**File:** `api/router.php` lines 342–355

**The bug:**
```php
// In router.php — these cases fall through to notifications.php:
case 'goal_list':
case 'goal_add':
case 'goal_edit':
case 'goal_delete':
case 'goal_mark_achieved':
case 'goal_contribute':
case 'notif_list':
// ...
    require APP_ROOT . '/api/notifications.php'; exit;  // ← WRONG FILE
```
Goal actions are implemented in `api/reports/goal_planning.php` but that file is only reached via `goal_projection`.

**Fix:** Split the router cases:
```php
// Goals
case 'goal_list':
case 'goal_add':
case 'goal_edit':
case 'goal_delete':
case 'goal_mark_achieved':
case 'goal_contribute':
    require APP_ROOT . '/api/reports/goal_planning.php'; exit;

// Notifications (separate)
case 'notif_list':
case 'notif_unread_count':
case 'notif_mark_read':
case 'notif_mark_all_read':
case 'notif_clear_all':
case 'notif_prefs_get':
case 'notif_prefs_save':
    require APP_ROOT . '/api/notifications.php'; exit;
```

Also add `goal_list` to the `$csrfExempt` array (it's a read action).

---

### 🔴 BUG-03 — 2FA and Session Security Features Completely Unreachable
**Severity:** HIGH — These are newly implemented features (t386, t387) but have NO router entries.

**Files:**
- `api/auth/2fa.php` — TOTP-based 2FA setup, verify, disable
- `api/auth/session_security.php` — session list, revoke, device management

**Fix:** Add to `api/router.php`:
```php
// ── t386: 2FA ────────────────────────────────────────────
case '2fa_status':
case '2fa_setup':
case '2fa_verify_setup':
case '2fa_disable':
case '2fa_verify_login':
    require APP_ROOT . '/api/auth/2fa.php'; exit;

// ── t387: Session Security ───────────────────────────────
case 'sessions_list':
case 'session_revoke':
case 'session_revoke_all':
    require APP_ROOT . '/api/auth/session_security.php'; exit;
```

Add read-only ones to `$csrfExempt`: `'2fa_status'`, `'sessions_list'`.

---

### 🟠 BUG-04 — `mf.js.bak` Exposed in Web Root
**Severity:** HIGH (Security)

`public/js/mf.js.bak` (439KB backup file) is publicly accessible. `.htaccess` blocks `.sql`, `.log`, `.sh`, `.bat` but NOT `.bak` files. This exposes full source code of the MF module including any internal logic.

**Fix:** Add to `.htaccess`:
```apache
<FilesMatch "\.(bak|tmp|orig|swp|old)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```
Or simply delete the file: `public/js/mf.js.bak`.

---

### 🟠 BUG-05 — `database/` Directory Has No Web Access Protection
**Severity:** HIGH (Security)

The `database/` folder sits in the web root and contains all 41 SQL migration files including schema, seed data, and table structures. `.htaccess` blocks `.sql` files via `FilesMatch`, but only at the root level. This should be explicitly blocked:

**Fix:** Add to `.htaccess`:
```apache
<DirectoryMatch "^.*/(database|debug|cron|config|includes|nav_download/logs)/">
    Order allow,deny
    Deny from all
</DirectoryMatch>
```
Or move `database/` above the web root.

---

### 🟡 BUG-06 — Crypto Schema Conflict Between Migration Files
**Severity:** MEDIUM (Causes confusion; runtime behaviour is currently correct)

Two migrations define `crypto_holdings` and `crypto_transactions` with different schemas:

| | `38_crypto_holdings.sql` | `41_pending_tasks.sql` |
|---|---|---|
| Key column | `portfolio_id` | `user_id` |
| Unique key | `(portfolio_id, coin_id)` | `(user_id, coin_id, exchange)` |
| Extra columns | `total_invested`, `wallet_address` | `current_price`, `current_value_inr`, `invested_inr`, `is_active` |

**Runtime outcome:** Migration 38 runs first → table created with `portfolio_id`. Migration 41's `CREATE TABLE IF NOT EXISTS` is silently skipped. The active router (`crypto_list.php`) uses `portfolio_id` so it works.

**Dead file:** `api/crypto/crypto.php` uses the `user_id` schema from 41 but is **not routed anywhere** in `router.php`. It is orphaned dead code.

**Fix:**
1. Delete `api/crypto/crypto.php` (dead, never routed).
2. Remove the duplicate `crypto_holdings`/`crypto_transactions` CREATE statements from `41_pending_tasks.sql` (they are already properly created by `38_crypto_holdings.sql`).

---

### 🟡 BUG-07 — 61 Stub API Files Return "Not Yet Implemented"
**Severity:** MEDIUM (Missing features, not bugs per se)

The following API endpoints exist as placeholder stubs and will return `{"success":false,"message":"Not yet implemented — tXXX"}`:

**AI / Advisor (8 files):**
`api/ai/fund_recommend.php`, `api/ai/goal_advisor.php`, `api/ai/news_impact.php`, `api/ai/portfolio_narrative.php`, `api/ai/report_card.php`, `api/ai/sip_optimizer.php`, `api/ai_advisor/chat.php`, `api/ai_advisor/goal_plan.php`, `api/ai_advisor/tax.php`

**Integrations (9 files):**
`api/integrations/account_aggregator.php`, `api/integrations/brokers.php`, `api/integrations/cibil.php`, `api/integrations/digilocker.php`, `api/integrations/epfo.php`, `api/integrations/gmail_parser.php`, `api/integrations/google_sheets.php`, `api/integrations/groww.php`, `api/integrations/zerodha.php`

**Asset Classes (8 files):**
`api/bonds/bonds.php`, `api/bonds/govt_bonds.php`, `api/esop/esop.php`, `api/gold/gold.php`, `api/international/intl.php`, `api/pms/pms.php`, `api/realestate/realestate.php`, `api/reits/reits.php`

**Reports (9 files):**
`api/reports/annual_report.php`, `api/reports/behavioral_score.php`, `api/reports/benchmark_compare.php`, `api/reports/export_excel.php`, `api/reports/health_score.php`, `api/reports/monthly_pl.php`, `api/reports/peer_benchmark.php`, `api/reports/snapshot.php`, `api/reports/wealth_statement.php`

**Others (27 files):**
`api/automation/rules.php`, `api/broker/zerodha_sync.php`, `api/cashflow/cashflow.php`, `api/community/benchmarking.php`, `api/crypto/crypto_add.php` (note: add is in crypto_list.php), `api/crypto/crypto_holdings.php`, `api/crypto/crypto_import.php`, `api/external/api_auth.php`, `api/external/api_v1.php`, `api/govt_schemes/epf.php`, `api/govt_schemes/leave_lta.php`, `api/mutual_funds/investment_calendar.php`, `api/notifications/push.php`, `api/notifications/whatsapp.php`, `api/nps/nps_import.php`, `api/public/api_v1.php`, `api/reports/annual_report.php`, `api/search/global_search.php`, `api/sgb/sgb.php`, `api/smallcase/smallcase.php`, `api/stocks/alerts.php`, `api/stocks/corporate_actions.php`, `api/stocks/dividends.php`, `api/stocks/fundamentals.php`, `api/user/data_export.php`, `api/user/data_import.php`, `api/watchlist/watchlist.php`

---

## 5. DATABASE SCHEMA QUICK REFERENCE

### Core Tables (from `01_schema_complete.sql`)
| Table | Purpose |
|---|---|
| `users` | Auth, role, theme, totp_secret (added by 41) |
| `portfolios` | User portfolios (one-to-many users:portfolios) |
| `funds` | MF master data: scheme_code, nav, nav_date |
| `mf_holdings` | User MF holdings per portfolio |
| `mf_transactions` | Buy/sell/switch transactions |
| `nav_history` | Historical NAV per fund per date |
| `nav_download_queue` | ⚠️ Created by `ensureQueueTable()`, NOT in schema files |
| `stock_holdings` | Stock positions |
| `stock_transactions` | Stock transactions |
| `nps_holdings` | NPS fund holdings |
| `fd_accounts` | Fixed deposits |
| `savings_accounts` | Savings account balances |
| `po_schemes` | Post office schemes (PPF, NSC, SSY, RD, MIS) |
| `app_settings` | Key-value store for app config |
| `sip_plans` | SIP schedules linked to MF holdings |

### Tables Added by Migrations 02–41
| Table | Migration | Purpose |
|---|---|---|
| `fund_ratings` | 23 | 1–5 star ratings per fund |
| `style_box_data` | 24 | Morningstar-style box |
| `sector_rotation` | 25 | Sector performance tracking |
| `investment_calendar` | 26 | Upcoming events |
| `user_sessions` | 41 | Session management (t387) |
| `rate_limit_buckets` | 41 | API rate limiting |
| `ai_chat_history` | 41 | Chat logs |
| `ai_portfolio_reviews` | 41 | Monthly AI reviews |
| `ai_anomalies` | 41 | AI-detected anomalies |
| `fd_maturity_alerts` | 41 | FD alert schedules |
| `fd_market_rates` | 41 | Bank rate comparison |
| `goals` | 41 | Goal planning |
| `goal_holdings` | 41 | Assets linked to goals |
| `networth_snapshots` | 41 | Monthly NW history |
| `crypto_holdings` | 38 | Crypto positions (portfolio_id based) |
| `crypto_transactions` | 38 | Crypto trades |
| `crypto_price_cache` | 38 | CoinGecko price cache |

---

## 6. ENVIRONMENT / CONFIG

From `.env`:
```
DB_HOST=localhost
DB_NAME=wealthdash
DB_USER=root
DB_PASS=          ← blank on local XAMPP
APP_ENV=local
APP_URL=http://localhost/wealthdash
APP_SECRET=wealthdash2024xK9mP3qR7nL2vB8hJ5
GOOGLE_CLIENT_ID=xxxx
GOOGLE_CLIENT_SECRET=xxxx
SMS_PROVIDER=msg91
MSG91_AUTH_KEY=xxxx
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
```

`IS_LOCAL` is `true` when `APP_ENV=local`. Errors are shown in full detail when `IS_LOCAL` is true.

---

## 7. RECENTLY CHANGED FILES (High Activity)

| File | Last Modified | What Changed |
|---|---|---|
| `api/crypto/crypto_list.php` | 2026-04-18 | Crypto holdings CRUD (t24) |
| `api/crypto/crypto.php` | 2026-04-16 | Dead file, user_id schema |
| `api/crypto/vda_tax.php` | 2026-04-18 | VDA/crypto tax calculator |
| `templates/pages/crypto.php` | 2026-04-18 | Crypto UI page |
| `public/js/crypto.js` | 2026-04-18 | Crypto frontend |
| `database/38_crypto_holdings.sql` | 2026-04-18 | Crypto schema |
| `database/41_pending_tasks.sql` | 2026-04-16 | 2FA, sessions, goals, anomalies |
| `api/auth/2fa.php` | 2026-04-16 | 2FA TOTP (t386) — not routed |
| `api/auth/session_security.php` | 2026-04-16 | Session mgmt (t387) — not routed |
| `nav_download/logs/errors.log` | 2026-04-18 | Active errors |
| `public/js/mf.js` | 2026-04-14 | MF page (large feature file) |
| `templates/pages/admin.php` | 2026-04-12 | Admin panel |

---

## 8. PRIORITY FIX ORDER

| Priority | Bug | File(s) to Change |
|---|---|---|
| 1 🔴 | BUG-01: NAV downloads broken | Create `database/42_fix_nav_queue.sql` + optionally patch `nav_download/nav_worker.php` |
| 2 🔴 | BUG-02: Goal CRUD broken | `api/router.php` |
| 3 🔴 | BUG-03: 2FA unreachable | `api/router.php` |
| 4 🟠 | BUG-04: .bak file exposed | `.htaccess` + delete `public/js/mf.js.bak` |
| 5 🟠 | BUG-05: database/ dir exposed | `.htaccess` |
| 6 🟡 | BUG-06: Dead crypto.php | Delete `api/crypto/crypto.php`, clean `41_pending_tasks.sql` |
| 7 🟡 | BUG-07: Stub files | Implement per task priority |

---

## 9. KNOWN WORKING FEATURES (Do Not Break)

- MF holdings: add, edit, delete, import CSV (v1, v2, v3), XIRR, SIP tracker
- NAV download system (once BUG-01 is fixed)
- FD: add, delete, mature, analytics, alerts
- NPS: add, delete, nav update, statement
- Stocks: add, delete, search, price refresh
- Post Office schemes: PPF, NSC, SSY, RD, MIS (full CRUD)
- Savings accounts
- Tax reports: FY gains, LTCG/STCG, advance tax, grandfathering, HIFO
- Admin: user management, settings, DB manager, data quality, fund rules
- Market indexes (30-sec auto-refresh)
- Fund ratings, health score, style box, benchmark comparison
- ISIN validator
- Watchlist alerts
- Notifications system
- NFO tracker
- Crypto: list, add, price refresh, VDA tax (once migration applied)

---

## 10. HOW TO ADD A NEW FEATURE

1. Create the API file: `api/{module}/{action}.php`
2. Add route in `api/router.php`:
   ```php
   case 'my_action':
       require APP_ROOT . '/api/{module}/{action}.php'; exit;
   ```
3. If read-only, add to `$csrfExempt` array in `router.php`
4. If new DB tables needed, create `database/{NN}_description.sql`
5. Create/update template in `templates/pages/{page}.php`
6. Add JS in `public/js/{module}.js`

---

*End of God Mode Context — WealthDash v2 (Patch 2026-04-05)*
