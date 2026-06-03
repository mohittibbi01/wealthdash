# 🤖 WealthDash — Claude Project Analysis Prompt
**Yeh prompt copy karke Claude ko do, saath mein poora project zip attach karo**

---

## PROMPT (Copy karo — Start se End tak)

---

Main tumhe apna **WealthDash** project de raha hoon — ek personal finance dashboard (PHP backend + vanilla JS frontend). Tumhe is project ko deeply analyze karna hai aur ek **standalone HTML file** banana hai jo:

1. Sabhi **remaining bugs aur kmiyan** dikhaye
2. Sabhi **pending/incomplete tasks** list kare
3. **New improvement suggestions** bhi include kare
4. File ka **look & feel** bilkul `wealthdash_master_v54.html` jaisa ho (same dark theme, same UI style)

---

## 📁 Project Structure Samjho

Project mein ye main cheezein hain:

```
/api/              → PHP REST API endpoints (45+ folders)
/templates/        → PHP HTML templates (pages, partials, layouts)
/public/           → JS, CSS, assets
/includes/         → Shared PHP helpers (db.php, auth.php, etc.)
/migrations/       → SQL migration files (46+ numbered, 50+ named)
/goal/             → Master task tracker files (wealthdash_master_v54.html)
```

**Tech Stack:** PHP 8.1, MySQL 8, Vanilla JS, Chart.js, no framework

**`zzProjectList.txt`** mein poora file/folder structure hai — pehle woh padhna.

---

## 🔍 Analysis Kaise Karo — Step by Step

### Step 1: zzProjectList.txt padhna
Poori file tree dekho. Note karo:
- Kaunse folders exist karte hain
- File sizes (< 600 bytes = stub/empty)
- Kaunsi cheezein missing lag rahi hain

### Step 2: api/ folder audit
Har API subfolder ke liye check karo:
- **`api/router.php`** mein us folder ka route hai ya nahi
- File size: agar < 600 bytes hai toh stub hai (kaam nahi karta)
- Common stubs found: `api/ai/fund_recommend.php`, `api/ai/goal_advisor.php`, `api/automation/rules.php`, `api/broker/zerodha_sync.php`

### Step 3: templates/ audit
- Kaunse pages hain lekin unka API endpoint nahi
- Kaunse APIs hain lekin template nahi
- Incomplete pages (sirf HTML, koi JS logic nahi)

### Step 4: includes/ audit
- `db.php` — connection pool ya simple connection?
- `auth.php` — session management complete hai?
- `error_handler.php` — exist karta hai ya nahi?
- `validator.php` — centralized validation hai ya nahi?

### Step 5: migrations/ audit
- Latest migration number kya hai
- Koi missing indexes hain?
- Koi broken migrations hain?

### Step 6: public/js/ audit
- Duplicate functions across files
- Dead code
- Missing features jo HTML reference karta hai

### Step 7: Cross-cutting concerns
- Security: Rate limiting, input validation, CSRF
- Performance: Missing DB indexes, N+1 queries
- Mobile: Responsive issues
- Error handling: Centralized ya scattered

---

## 📊 Output File — Exact Format

**Banana hai:** Ek single HTML file, naam: `wealthdash_bugs_v[DATE].html`

File ka UI bilkul `wealthdash_master_v54.html` jaisa hona chahiye. Neeche exact CSS variables aur structure diya hai:

### CSS Variables (same as master):
```css
:root {
  --bg: #060c1a;
  --surface: #0d1528;
  --surface2: #141f35;
  --surface3: #1b2840;
  --border: #1f2e4a;
  --border2: #263857;
  --text: #d6e4ff;
  --muted: #5a7aaa;
  --accent: #4fa3ff;
  --accent2: #38e8b0;
  --warn: #f0b429;
  --danger: #ff5c5c;
  --done: #38e8b0;
  --p0: #ff4040;
  --p1: #ff8c42;
  --p2: #f0b429;
  --p3: #5a7aaa;
}
```

### File Structure:
```html
<!DOCTYPE html>
<html>
<head>
  <!-- Same CSS as master — dark theme -->
</head>
<body>

  <!-- TOP BAR: WealthDash logo + version + stats (total bugs, P0 count, etc.) -->

  <!-- TAB BAR: Bugs | Pending Tasks | New Improvements | Summary -->

  <!-- TAB 1: BUGS & KMIYAN -->
  <!-- TAB 2: PENDING TASKS -->  
  <!-- TAB 3: NEW IMPROVEMENTS -->
  <!-- TAB 4: SUMMARY / SPRINT PLAN -->

</body>
</html>
```

---

## 📋 Tab 1 — BUGS & KMIYAN

Har bug ke liye yeh card format use karo:

```
┌─────────────────────────────────────────────────┐
│ [BUG-001]  [P0]  [TYPE: syntax/logic/missing]   │
│ Title: Router.php mein dead routes               │
├─────────────────────────────────────────────────┤
│ File(s): api/router.php                         │
│ Impact: Sab API calls fail ho rahi hain         │
│ Root Cause: Workers ne files banaye, routes     │
│             add nahi kiye                       │
│ Fix: Routes add karo / stubs implement karo     │
└─────────────────────────────────────────────────┘
```

**Bug categories:**
- `syntax` — JS/PHP syntax error
- `missing` — file/function/route missing hai
- `logic` — galat calculation ya behavior
- `perf` — performance issue
- `security` — security vulnerability
- `ux` — user experience issue

---

## 📋 Tab 2 — PENDING TASKS

Analyze karke batao kaunsi cheezein **actually implement nahi hui** hain. Check karo:

1. **`wealthdash_master_v54.html`** mein `done:true` nahi hain woh tasks
2. Files jo exist karti hain lekin sirf stub hain (< 600 bytes)
3. Templates jo exist karti hain lekin API nahi bani
4. Features jo master mein mention hain lekin code mein nahi milte

Har pending task ke liye:
```
ID: tp001
Title: Redis Cache Layer
Priority: P1
Worker: W5 (UI/Infra)
Est. Effort: 3-4 hr
Files Needed: includes/cache.php (NEW), api/router.php (update)
Why Pending: No cache.php found in includes/, no Redis config in .env.example
```

---

## 📋 Tab 3 — NEW IMPROVEMENTS

Yeh woh cheezein hain jo original task list mein **nahi hain** lekin project ko better banayengi:

**Areas to check:**
1. Code quality — kahan duplicate logic hai
2. DX (Developer Experience) — kya workers ko kaam karna easy hai
3. New features jo 2026 mein relevant hain
4. Integration opportunities (jo abhi nahi hain)
5. Testing — koi tests nahi hain toh suggest karo

Format:
```
IMPROVEMENT: API Versioning
Type: Architecture
Impact: High
Effort: Medium
Detail: Abhi /api/mf/holdings.php hai, versioning nahi.
        Banana chahiye: /api/v1/mf/holdings
        Kyun: Breaking changes se protect karta hai
```

---

## 📋 Tab 4 — SPRINT PLAN / SUMMARY

Ek 4-week sprint plan banao:

```
Week 1 (P0 — Must Fix):
- BUG-001: Router dead routes (2-3 hr)
- BUG-002: t312 syntax fix (30 min)
- TASK: DB Indexes add karo (1-2 hr)

Week 2 (P1 — High Value):
...

Week 3 (P2 — UX Polish):
...

Week 4 (P3 — Nice to Have):
...
```

---

## ⚠️ Important Instructions

1. **Sirf actual findings likho** — agar koi file exist nahi karti toh "file not found" likho, guess mat karo
2. **File sizes matter** — < 600 bytes = stub = not implemented
3. **Router check mandatory** — har API file ke liye router mein route check karo
4. **Done ≠ Complete** — master mein `done:true` hone se kaam actually complete nahi hota, code check karo
5. **Hindi/English mix** — same style jo master file mein hai
6. **No hallucination** — agar kuch nahi mila toh "Unable to verify — file not in zip" likho
7. **Priority order:** P0 > P1 > P2 > P3
8. **Worker assignment:** W1=MF, W2=Crypto, W3=Stocks, W4=Tax/Goals, W5=UI/Infra, M=Master

---

## 🎨 UI Components Reference

Master file jaisa UI banana ke liye yeh patterns use karo:

### Bug Card:
```html
<div class="bug-card" data-priority="p0">
  <div class="bug-hdr">
    <span class="bug-id">BUG-001</span>
    <span class="prio p0">P0</span>
    <span class="bug-type">missing</span>
    <span class="bug-title">Router.php Dead Routes</span>
  </div>
  <div class="bug-body">
    <div class="bug-row"><span class="lbl">File:</span> api/router.php</div>
    <div class="bug-row"><span class="lbl">Impact:</span> All API routes broken</div>
    <div class="bug-row"><span class="lbl">Fix:</span> Add missing routes</div>
  </div>
</div>
```

### Task Card:
```html
<div class="task-card">
  <span class="task-id">tp001</span>
  <div class="task-info">
    <div class="task-title">Redis Cache Layer</div>
    <div class="task-desc">Heavy endpoints pe cache add karo</div>
  </div>
  <span class="prio p1">P1</span>
  <span class="worker w5">W5</span>
  <span class="effort">3-4 hr</span>
</div>
```

### Filter Bar:
```html
<div class="filter-bar">
  <button class="fbtn active" onclick="filter('all')">All</button>
  <button class="fbtn" onclick="filter('p0')">🚨 P0</button>
  <button class="fbtn" onclick="filter('p1')">🔥 P1</button>
  <button class="fbtn" onclick="filter('p2')">⚡ P2</button>
</div>
```

---

## 📌 Known Issues (Already Identified — Verify karo)

Yeh cheezein already identify ki gayi hain, **verify** karo aur file mein include karo agar sach mein exist karti hain:

| # | Issue | Location | Status |
|---|-------|----------|--------|
| 1 | t312 syntax bug `done:true,'` | wealthdash_master_v54.html | Fixed in v54 |
| 2 | Router dead routes | api/router.php | Verify |
| 3 | Stub files < 600B | api/ai/, api/automation/ | Verify |
| 4 | Missing DB indexes | migrations/ | Verify |
| 5 | ai/ vs ai_advisor/ duplicate | api/ | Verify |
| 6 | unified.php god file (37KB) | api/dashboard/ | Verify |
| 7 | No centralized error handler | includes/ | Verify |
| 8 | No input validator | includes/ | Verify |
| 9 | Mobile bottom nav missing | templates/ | Verify |
| 10 | 2FA incomplete | api/auth/, templates/ | Verify |

---

## 🚀 Final Output Requirements

Output file mein **minimum** yeh hona chahiye:

- [ ] Dark theme (same as master)
- [ ] Sticky header with stats
- [ ] 4 tabs (Bugs, Tasks, Improvements, Sprint)
- [ ] Filter by priority (P0/P1/P2/P3)
- [ ] Filter by worker (W1-W5/M)
- [ ] Search box
- [ ] Export button (copy to clipboard ya download)
- [ ] Total count in header (e.g., "23 Bugs | 47 Tasks | 12 Improvements")
- [ ] Each item has: ID, Title, Priority, Worker, Files, Description, Fix/Action

**File naam:** `wealthdash_bugs_v[YYYYMMDD].html`
**File size expected:** 100-300KB (detailed enough)

---

*Prompt version: v2.0 | Created: May 2026 | For: WealthDash Project*
