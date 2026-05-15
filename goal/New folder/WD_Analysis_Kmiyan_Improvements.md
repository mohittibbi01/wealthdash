# WealthDash — Project Analysis Report
**Date:** May 14, 2026 | **Version:** v53 (Coordinator v8)

---

## 📊 Project Overview

| Item | Count |
|------|-------|
| Total Files | ~320+ PHP/JS/SQL files |
| API Modules | 45+ folders |
| Completed Tasks (Master) | ~300+ |
| Pending Tasks | **261** |
| Database Migrations | 46 numbered + 50+ named |
| Claude Workers | 6 (1 Master + 5 Workers) |

---

## 🐛 KMIYAN (Bugs & Issues)

### 🚨 P0 — Critical Issues

**1. Tax Regime Comparison missing (tv001)**
- Old vs New regime calculator nahi bana abhi tak
- User ko manually calculate karna padta hai
- FY 2024-25 ke updated slabs nahi hain

**2. Crypto VDA Tax incomplete (tc002)**
- Section 115BBH tracker nahi bana
- 1% TDS on transfer nahi track ho raha
- Set-off restriction UI nahi hai

**3. Database Indexes missing (tp003)**
- nav_history, mf_transactions, mf_holdings tables pe missing indexes
- Large dataset pe slow queries hoti hain
- 10,000+ transactions pe page load slow ho jata hai

---

### 🔥 P1 — High Priority Issues

**4. Coordinator vs Master Sync Problem**
- Coordinator v8 mein kuch tasks `done:true` hain jo Master mein pending dikhte hain
- V53 done set mein sirf 4 tasks hain (t211, t479, t480, t504) — jo actually pending hain coordinator mein bhi
- **Confusion:** Master file mein done tasks aur coordinator mein done tasks alag-alag track ho rahe hain

**5. api/router.php — Stale Routes**
- Bahut saare API files hain (crypto, esop, 2fa) lekin router mein routes nahi
- Workers sirf "note" karte hain, merge kabhi nahi hua properly
- Dead endpoints — files hain lekin routes nahi

**6. 2FA Implementation Incomplete**
- `api/auth/2fa.php`, `api/auth/totp.php` exist karte hain
- `templates/pages/security/2fa.php` bhi hai
- Lekin user flow properly connect nahi hua — coordinator mein t386 abhi bhi pending hai

**7. ESOP Module Half-Baked**
- `api/esop/esop.php` — 43KB file exist karti hai
- `templates/pages/esop.php` bhi hai
- Lekin coordinator mein t117 abhi bhi "pending" hai — mismatch

**8. Crypto Exchange Sync — Broken State**
- `crypto_exchange_sync.php` (20KB) exist karta hai
- `crypto_import.php` (22KB) bhi hai
- Lekin coordinator mein tc005 pending hai — unclear kya actually working hai

**9. EPFO Passbook — Inconsistent Files**
- `api/epfo/passbook.php` exist karta hai
- `pages/epfo/passbook.php` bhi hai
- Coordinator mein t151 pending — kaunsa use karna hai?

**10. ai_advisor vs ai folder Confusion**
- Dono `api/ai/` aur `api/ai_advisor/` folders hain
- Overlap: `api/ai/ai_chat.php` (17KB) aur `api/ai_advisor/chat.php` (455 bytes stub)
- Workers ko confusion — kahan kaam karna hai?

**11. Duplicate/Stub Files**
- `api/ai/fund_recommend.php` — 489 bytes (sirf stub)
- `api/ai/goal_advisor.php` — 472 bytes (stub)
- `api/ai/news_impact.php` — 488 bytes (stub)
- `api/ai/sip_optimizer.php` — 479 bytes (stub)
- `api/automation/rules.php` — 484 bytes (stub)
- `api/broker/zerodha_sync.php` — 482 bytes (stub)
- Ye sab stubs hain jo actual implementation nahi hain

**12. Peak NAV Processor — Unclear Status**
- `peak_nav/` folder exist karta hai
- Master mein t06 done hai (Peak NAV progress bar)
- Lekin peak_nav folder actual working hai ya nahi unclear

**13. Missing Financial Year Selector (t491)**
- Cross-FY analysis possible nahi hai
- FY 2024-25 vs 2023-24 comparison nahi ho sakta

**14. Quick Transaction Drawer Missing (t494)**
- Har page se transaction add karna padta hai us page pe jaake
- Global shortcut nahi hai
- UX friction bahut zyada hai

---

### ⚡ P2 — Medium Priority Issues

**15. Number Format Inconsistency**
- `api/format/number_format.php` + `public/js/numberFormat.js` + `public/css/number-format.css` — teen jagah format logic
- Kabhi lakhs mein, kabhi crores mein dikhta hai
- Toggle state kabhi kabhi reset ho jata hai

**16. CSS Variables Duplication**
- Master file ka pink theme (--pink: #be185d) aur coordinator ka dark theme (--accent: #7c6fcd) alag hain
- Workers ko dono files milti hain — conflict possible hai

**17. Empty State Screens Missing (t452)**
- Jab koi data nahi hota (no MFs, no stocks) — blank page dikhta hai
- No guidance for new users
- Onboarding flow nahi hai (t454 pending)

**18. Loading States Inconsistent (t449)**
- Kuch pages skeleton loading karte hain, kuch nahi
- `public/js/skeletons.js` exist karta hai lekin sabhi pages use nahi karte

**19. Pagination Missing on Multiple Pages**
- t33 coordinator mein "done" mark hai lekin master mein t33 "FD + Savings pagination" abhi bhi pending-like state mein
- `api/savings/savings_list.php` (700 bytes) — koi pagination nahi

**20. Export CSV Filters Not Applied (t35)**
- Export pe current filters bypass ho jaate hain
- User filtered data export karna chahta hai, pura data milta hai

**21. Mobile Responsiveness Issues**
- Bottom Navigation Bar missing (t256 pending)
- Pull-to-Refresh nahi hai (t257 pending)
- Many tables overflow on mobile

**22. Cache Layer Missing (tp001)**
- Redis/APCu nahi hai
- Har request pe full DB query
- Heavy endpoints (unified dashboard) pe slow response

**23. Error Monitoring Missing (t414)**
- Client-side errors track nahi hote
- PHP errors sirf log file mein
- Admin ko real-time alerts nahi milte

---

### 📝 Architecture Issues

**24. God File Pattern — dashboard/unified.php (37KB)**
- Bahut saari logic ek hi file mein
- Difficult to maintain/update
- Should be split into smaller components

**25. Worker Scope Overlap**
- W3 (Stocks) aur W1 (MF) dono ETF handle karte hain — confusion
- W4 (Tax) aur W5 (UI) dono AI features mein kaam karte hain

**26. No Error Boundary in Frontend**
- JS error aane pe pura page crash ho sakta hai
- No graceful degradation

**27. Hard-coded API Keys Risk**
- `.env` file mein sab keys hain
- `.env.example` mein keys ki list hai — kya actual keys exposed hain?

---

## 💡 IMPROVEMENTS

### 🎯 Immediate (Next 2 Sprints)

**1. Task Sync Tool Banao**
- Ek script jo coordinator aur master mein done tasks sync kare
- Auto-detect mismatch

**2. Router.php Cleanup**
- Sab stub files identify karo
- Working routes vs dead routes audit karo
- Missing routes add karo

**3. Mobile First Pass**
- Bottom nav bar (t256)
- Responsive tables fix
- Touch-friendly modals

**4. Performance Quick Wins**
- DB indexes add karo (tp003 — P0!)
- OPcache enable karo (tp004)
- Lazy loading turn on (tp002)

---

### 📈 Feature Priority Recommendations

**Bundle These Tasks Together (Same files):**

| Bundle | Tasks | Worker | Why |
|--------|-------|--------|-----|
| Tax Calculator Bundle | tv001 + t496 + t287 | W4 | Same tax logic |
| Goals Super Feature | tg002 + tg003 + tg004 + tg005 | W4/W5 | Shared DB |
| AI Chat Unification | t381 + t382 + t330 | W5 | Merge ai/ai_advisor |
| Crypto Complete | tc001 + tc002 + tc005 | W2 | Linked features |
| EPF/NPS Complete | t467 + t469 + t470 + t428 | W4 | Same tables |

**Biggest UX Wins (Low effort, high impact):**
- t494 — Quick Transaction Drawer (1-2 days, massive UX improvement)
- t254 — Financial Year Summary Card (1 day)
- t298 — Market Pulse Widget (1 day)
- t291 — Goal Progress Ring (2 days)
- t293 — FIRE Calculator (2 days)

---

### 🏗️ Architecture Improvements

**1. api/ai/ Cleanup**
- `api/ai_advisor/` folder merge karo `api/ai/` mein
- Stub files ya implement karo ya delete karo
- Ek consistent AI interface banao

**2. Split unified.php**
- `api/dashboard/unified.php` (37KB) ko split karo
- Market data, Portfolio summary, Goals summary alag endpoints

**3. Proper Error Handling Layer**
- `includes/error_handler.php` banao
- Sab PHP errors ek format mein
- JS error boundary add karo

**4. API Response Standardization**
- Kuch files `{success: true, data: [...]}` return karte hain
- Kuch sirf `[...]` return karte hain
- Standardize: `{success: bool, data: any, error: string|null, meta: {}}`

**5. Frontend State Management**
- Global state `window.WD = {}` hai lekin inconsistent use
- Proper state management pattern adopt karo

---

### 🔒 Security Improvements

**1. Rate Limiting Gaps**
- `api/security/rate_limit.php` exist karta hai
- Lekin sabhi endpoints pe apply nahi hua
- AI endpoints pe especially needed (cost control)

**2. Input Validation**
- `api/mutual_funds/data_validation.php` exist karta hai
- Lekin sab APIs isko use nahi karte
- SQL injection possible on some endpoints

**3. Session Timeout**
- t387 (Session Security) pending hai
- Multiple device sessions manage nahi hote

---

### 📊 Data Quality Issues

**1. Duplicate Detection (t479 - pending P1)**
- CAS import pe duplicate transactions possible
- No dedup logic in import flow
- Financial data mein duplicates = wrong calculations

**2. Data Validation (t480 - pending P1)**
- Invalid NAV values store ho sakte hain
- Negative quantities possible
- Date format inconsistencies

---

## 📋 NEW TASKS — Coordinator V9 Ke Liye

*(Ye tasks current coordinator mein nahi hain)*

| ID | Priority | Title | Worker |
|----|----------|--------|--------|
| tnew01 | P0 | Router.php Audit — Dead routes + missing routes fix | M |
| tnew02 | P0 | Stub Files Cleanup — Empty API files implement ya delete | M |
| tnew03 | P1 | ai/ai_advisor Merge — Duplicate AI folders unify karo | W5 |
| tnew04 | P1 | API Response Standardization — Consistent JSON format | M |
| tnew05 | P1 | Coordinator-Master Sync — Done tasks match karo | M |
| tnew06 | P2 | Error Handler Layer — Global PHP + JS error management | W5 |
| tnew07 | P2 | API Rate Limiting — All endpoints pe consistent limit | W5 |
| tnew08 | P2 | Input Validation Layer — Centralized validation | M |
| tnew09 | P2 | Mobile Navigation Audit — Bottom bar + swipe + touch | W5 |
| tnew10 | P2 | unified.php Split — Break god file into modules | M |

---

## 🎯 Recommended Sprint Order

### Sprint 1 (This week — Priority P0)
1. ✅ tp003 — DB Indexes (1-2 hr)
2. ✅ tv001 — Tax Regime Calculator (2-3 days, most requested)
3. ✅ tc002 — Crypto VDA Tax complete (2-3 days)
4. ✅ tnew01 — Router Audit (2-3 hr)

### Sprint 2 (Next week — P1 High Impact)
1. ✅ t494 — Quick Transaction Drawer
2. ✅ t295 — Net Worth Trend Chart
3. ✅ tg002 — Goal Progress Dashboard
4. ✅ tc001 — Live Crypto Prices WebSocket

### Sprint 3 (Week 3 — UX)
1. ✅ t256 — Bottom Navigation Bar
2. ✅ t452 — Empty States
3. ✅ t449 — Loading Skeletons everywhere
4. ✅ t293 — FIRE Calculator

---

*Total pending tasks: 261 | Estimated completion at 5 tasks/week: ~52 weeks*
