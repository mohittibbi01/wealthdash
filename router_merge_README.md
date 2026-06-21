# WealthDash — Router Merge Complete (20 June 2026)

Ye merge maine khud kar diya hai. Neeche poora detail hai ki kya-kya kiya, kya
check karna hai, aur agar kuch test karte waqt गड़बड़ मिले to kahan dekhना hai.

---

## 1. Kya kiya gaya

### api/router.php
- **92 pending tasks** (80 pehle pass mein + 12 jo doosre pass mein
  HANDOFF.md format ki files se mile, jo pehli baar mein miss ho gayi
  thi) ab merge ho chuke hain.
- **10 REST-style orphan tasks** (Gold, Bonds, RBI Securities, Watchlist,
  Tax Report, Portfolio P/E, Stock SIP, Reality Check, Screener,
  International Stocks) ek naye bridge file `api/rest_dispatch.php` ke
  through wire kiye gaye — neeche Section 3 mein detail hai.
- File 1378 lines se badhke 2366 lines ki ho gayi.
- **1001 unique case-statements**, koi duplicate nahi (verify kiya gaya).
- Saare braces/parens balanced hain (verify kiya gaya).

**Doosra pass kyun zaroori tha:** Pehle analysis mein maine sirf un files
ko scan kiya tha jiska naam `ROUTER_NOTES*` ya `ROUTER_AND_HANDOFF*` tha.
Baad mein pata chala ki kuch sessions ne sirf `HANDOFF.md` naam se files
chhodi thi (jaise file 11 aur 17) jisme bhi router case-blocks the —
woh pehli baar miss ho gayi thi. Doosre pass mein in 12 extra tasks ko
dhoonda aur merge kiya:
- **t302** Groww CSV Import, **t334** Bulk Excel Import,
  **t392** Groww API Sync, **t490** CSV Importer v3 Extensions
- **t136** AIS/TIS Reconciliation, **t138** Indexation Calculator,
  **t198** NPS Screener Enhanced, **t314** Monthly P&L Statement,
  **t340** EPFO UAN Integration, **t422** Auto Sweep FD Tracker,
  **t45** RD Tracker, **t49** Leave Encashment + LTA Tracker

### templates/sidebar.php
- **65 naye navigation links** add kiye, 10 categories mein organized.
- 8 tasks (AIS, Indexation, Monthly P&L, UAN, Sweep FD, RD, LTA — sab
  doosre pass ke) ke liye sirf backend complete hai, frontend page
  abhi tak nahi bani — isliye inka sidebar link nahi diya. Jab page
  banao, sidebar mein add kar lena.

### Database Migrations
- `database/migrations_20jun2026_combined.sql` — saari **98 relevant**
  migration files ek jagah combine kar di hain, sahi order mein
  (foreign-key dependency check kiya gaya — `t302` `t392` se pehle
  rakha gaya hai kyunki t392, t302 ke `groww_fund_map` table ko use
  karta hai).
- Saari `CREATE TABLE IF NOT EXISTS` use karti hain — safe hai re-run
  karne ke liye agar kuch already chal chuka ho.



---

## 2. Conflicts jo maine resolve kiye

Merge karte waqt **16 jagah duplicate/colliding case-names** mile — yani
do alag sessions ne (anjaane mein) same action-name use kar liya tha do
alag features ke liye. Maine har ek ko check karke decide kiya:

| # | Conflict | Resolution |
|---|----------|------------|
| 1 | `journal_list/add/delete` — t408 (Investment Journal, already-live) vs th001 (Daily Financial Journal, naya) | th001 ke actions `djournal_*` rename kiye — router, `api/journal/journal.php`, aur `pages/journal/journal.php` teeno mein update kiya |
| 2 | `cc_list/cc_add/cc_delete` — t503 do baar bana (already-live `api/mutual_funds/credit_card.php` vs naya `api/budget/credit_card_optimizer.php`) | Sirf naye unique actions rakhe (`cc_optimize_spend`, `cc_interest_calculator`); duplicate cases drop kiye |
| 3 | `insurance_list/add/delete/premium_calendar` — t122 do baar bana | Sirf `insurance_summary` (naya unique action) rakha |
| 4 | `loans_list` — t123 do baar bana | Sirf naye unique actions rakhe (`loan_add/update/delete/summary/amortization`) |
| 5 | `sessions_list/session_revoke/session_revoke_all` — t387 already-live tha | Sirf naye unique actions rakhe (`session_touch`, `session_current`) |
| 6 | `goal_projection` — calculator (raw numbers) vs tg005's goal-checkin (saved goal_id) — alag purpose, same naam | tg005 ka version `goal_checkin_projection` rename kiya |
| 7 | `benchmark_compare` — tv11 (Mutual Fund benchmark) vs t406 (Anonymous Peer benchmark) — bilkul alag features | t406 ka version `peer_benchmark_compare` rename kiya, frontend bhi update kiya |
| 8 | `ai_chat_history`/`ai_chat_sessions` — t330 (Chatbot) vs t381 (AI Chat Assistant, already-live) | t330 ke actions `ai_chatbot_history`/`ai_chatbot_sessions` rename kiye — router aur `api/ai/chatbot.php` dono mein |
| 9 | `audit_log_list/stats/export` — already router mein the par GALAT file (`api/mutual_funds/audit_log.php`) point kar rahe the | Sahi file (`api/admin/audit_log.php`) pe repoint kiya. t307 ke naye unique actions (`al_*`) alag se add kiye |
| 10 | Crypto Tax Calculator (t42) do baar bana — purana `crypto_tax_calc.php` (May 14) vs naya `tax_calculator.php` (Jun 3) | File content check karke naya (sahi) version use kiya; purana archive kiya |
| 11 | Cold Wallet Tracker (tc006) do baar bana — `cold_wallets`/`cold_wallet_holdings` table wala (already-live, migration maujood) vs `crypto_cold_wallets` table wala (`crypto_cold_wallet.php`, koi migration nahi mili) | Doosra version orphaned/incomplete tha (uski table kabhi banti hi nahi) — archive kar diya |

**Important:** `api/admin/users.php` aur `api/admin/settings.php` ko maine
pehle galti se "duplicate" samajh kar archive kar diya tha — turant pakad
kar wapas restore kiya. Yeh files already-live hain (`admin_users`,
`admin_settings_get` etc actions in router.php se already merged the,
sirf naye t50/t52 task-notes ne unhe galat tareeke se "duplicate" dikhaya
tha). Agar kabhi koi aur file delete/archive karne ka socho, pehle
`grep -rn "filename.php" api/router.php` zaroor chala lena.

---

## 3. REST-style Orphan Tasks — special handling

10 tasks (t38, t39, t114, t116, t118, t121, t145, t432, t435, t436) worker
**ID-W3** ne REST-convention (`GET /api/gold/{id}` style) mein banaye the,
jabki poora router `?action=xxx` pattern follow karta hai. Inhe maine
seedha case-block paste karke merge nahi kiya — ek naya bridge file banaya:

**`api/rest_dispatch.php`** — ye 10 classes ko purane router pattern se
connect karta hai. Frontend se call karne ka tareeka:

```js
apiPost({action: 'gold_action', method: 'getHoldings', type: 'PHYSICAL'})
apiPost({action: 'watchlist_action', method: 'addToWatchlist', symbol: 'TCS'})
```

**⚠️ Security note (zaroor padhna):** In 10 classes ke original code mein
`user_id` seedha `$_GET`/`$_POST` se liya ja raha tha — matlab koi bhi
logged-in user dusre ka `user_id` pass karke unka data dekh sakta tha. Maine
`rest_dispatch.php` mein ek fix lagaya hai jo `user_id` ko hamesha current
session se force-overwrite karta hai, taaki spoofing na ho sake. Lekin yeh
**stop-gap fix** hai — asli sahi tareeka hoga in 10 classes ko khud edit
karke `require_auth()`/`$_SESSION` use karwana, jaise baaki poora app karta
hai. Jab time mile to inhe properly rewrite kar dena behtar hoga.

In 10 tasks ke frontend pages already maujood hain aur sidebar mein
"More Investments" category ke andar link diye gaye hain.

---

## 4. Skip kiya gaya / Manual action chahiye

- **t411 (Test Runner):** Backend file `api/admin/test_runner_api.php`
  disk par exist hi nahi karti thi. Router.php mein case-block comment
  kar diya hai (line ~1619) — uncomment karne se pehle ye file banani
  hogi. Filhal tests `debug/runner.php` se already chal rahe hain, to
  urgency nahi hai.
- **t122's `insurance_update`** aur **t503's `cc_update`** — colliding
  duplicate actions the jinka koi safe non-colliding naam nahi tha.
  Agar inki functionality chahiye, `api/insurance/insurance.php` aur
  `api/budget/credit_card_optimizer.php` ke andar dekh kar manually
  ek naya unique action-name de kar add karna hoga.
- **`recalc_holdings.php`** router.php line ~836 par require ho raha hai
  par file disk par nahi hai — **ye humara kiya hua nahi hai**, original
  baseline router (14 May wala) mein bhi yeh same tha. Alag se dekhna
  hoga, isse merge ka koi lena-dena nahi.
- **8 backend-only tasks (frontend page abhi nahi bani):** AIS/TIS
  Reconciliation (t136), Indexation Calculator (t138), Monthly P&L
  (t314), EPFO UAN (t340), Auto Sweep FD (t422), RD Tracker (t45),
  Leave/LTA Tracker (t49). In sabka backend `router.php` mein wired hai
  aur kaam karega (Postman/curl se test kar sakte ho), lekin koi
  dedicated UI page nahi mili to sidebar mein link nahi diya. Jab page
  banao, `templates/sidebar.php` mein "Import Tools" section ke pattern
  jaisa add kar lena.

---

## 5. Sidebar mein link NAHI mila in 28 tasks ke liye

Inka koi obvious dedicated page file nahi mila (zyada chance hai ye
admin-only/backend-only/internal-tool features hain jinhe sidebar link
ki zaroorat hi nahi):

```
t106, t234, t297, t307, t308, t335, t336, t350, t358, t378, t40, t411,
t414, t415, t46, t461, t467, t469, t470, t48, t50, t52, t60, tc003,
tc004, tp001, tp002, tv09
```

Agar inme se kisi ka page banao future mein, sidebar.php mein khud add
kar lena — pattern `templates/sidebar.php` mein "20 June 2026 router-merge
addendum" comment ke neeche dekh lena, wahi style follow karna.

---

## 6. Deploy karne se pehle verify karne ke steps

1. **Database backup le lena** migrations run karne se pehle.
2. Phir `database/migrations_20jun2026_combined.sql` ko apne MySQL/MariaDB
   par run karo (phpMyAdmin se ya CLI se):
   ```
   mysql -u root -p wealthdash < database/migrations_20jun2026_combined.sql
   ```
3. Project ko XAMPP `htdocs/wealthdash` mein deploy karo (ya jis folder
   mein abhi hai usi ko replace karo — pura project isi zip mein hai).
4. Browser mein login karke check karo:
   - Sidebar mein nayi categories dikh rahi hain (Admin/Infra, AI
     Features, etc.)
   - Kuch nayi pages khol kar dekho ki error to nahi aa raha
   - Existing features (jo pehle se chal rahe the) abhi bhi kaam kar
     rahe hain — khaaskar Investment Journal, Credit Card, Insurance,
     Loans, Sessions, Goals, Benchmarking, AI Chat (jinke naam maine
     rename kiye hain)
5. Agar koi "Unknown action" error aaye, woh action-name note kar ke
   `api/router.php` mein dhoondo — ho sakta hai koi aur frontend page
   bhi purane naam use kar rahi ho jo maine miss kar diya ho.

---

## 7. Files is zip mein

```
wealthdash/                          ← poora merged project (deploy-ready)
  api/router.php                     ← merged, 92+10 tasks ke saath
  api/rest_dispatch.php              ← naya bridge file (REST orphans ke liye)
  templates/sidebar.php              ← 65 naye links ke saath
  database/migrations_20jun2026_combined.sql  ← run karne wali combined SQL (98 files)
  archive/duplicate_builds_20jun2026/         ← purane duplicate/dead files
    crypto_tax_calc.php              ← t42 ka purana duplicate (Jun-3 wala use ho raha hai)
    crypto_cold_wallet.php           ← tc006 ka orphaned duplicate (koi migration nahi thi)
    epf.php, epfo.php                ← abandoned stub files (kabhi complete nahi hue)

router_merge_README.md               ← yahi file
wd_router_audit_report.html          ← original analysis report (reference ke liye)
```

---

## 8. Agar future mein aisa backlog dobara ho

Pattern dikha ki worker apna code complete karke "router notes" file
chhod deta hai, par router.php mein paste karna agle session tak ke liye
reh jaata hai — aur fir wahi backlog ban jaata hai. Suggestion: jab bhi
koi task complete ho, **usi session mein turant router.php mein paste
kar dena**, taaki yeh 5-6 hafte wala backlog dobara na bane.
