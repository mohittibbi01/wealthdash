# WealthDash — Changelog

---

## v36 — April 12, 2026

### ✅ New Features
- **FD Portfolio Diversification** (t423) — Bank-wise DICGC ₹5L limit checker. Visual concentration bar chart, summary table with Safe/Exceeds badges, smart recommendations. Appears on FD page automatically. `templates/pages/fd.php`
- **HRA Exemption Calculator** (t438) — Full Section 10(13A) calculator. Shows all 3 components (Actual HRA / Rent−10%Basic / 50%/40%Basic), highlights the minimum, flags PAN requirement if rent >₹1L/yr. `templates/pages/report_tax.php`
- **Changelog Modal** (t476) — "What's New" button in topbar. Parses version history and displays in a clean modal with New Feature / Bug Fix / Improvement badges. `templates/topbar.php`

### 🐛 Already Implemented (marked done)
- **Gratuity Calculator** (t47) — Basic×15/26×Years formula, joining date auto-calculate, ₹20L tax exemption. `templates/pages/report_tax.php`
- **Shared Screens** (t407) — URL-based screener filter sharing, scShareScreen() + _scRestoreFromURL(). `templates/pages/mf_screener.php`

---

## v35 — April 12, 2026

### ✅ Merged from v31–v34
- All completed tasks from v31, v32, v33, v34 merged into single file
- t262 Rolling Returns Chart, t407 Shared Screens, tmfi01-08 (Portfolio Health, Fund Recommendation, Risk Analysis, Smart Insights, Tax Loss Harvesting, What-If Simulator, Auto Cleanup, SIP Optimization)

---

## v34 — April 11, 2026

### ✅ New Features
- **New vs Old Tax Regime Comparator** (tv001) — Auto-pull deductions from WealthDash, break-even calc, 87A rebate integrated
- **Advance Tax Calculator** (tv002) — Quarterly installment tracker, 234C penalty, TDS estimate
- **Section 87A Rebate Tracker** (tv003) — Eligibility check, gap advisory
- **80C Dashboard** (tv005) — Full ₹1.5L utilization tracker with ELSS/EPF/NPS/LIC auto-pull
- **SIP Streak Tracker** (tj002) — Consecutive month tracking, badge system (3m/6m/12m/24m/36m/60m)
- **Goal Simulator** (t356) — SIP amount slider → goal date live update
- **Retirement Planner v2** (t357) — Income replacement model, SWP sustainability, bucket strategy
- **SWP Calculator** (t359) — Month-by-month simulation, depletion finder, safe withdrawal rate
- **Rolling Returns Chart** (t262) — 1Y/3Y/5Y rolling windows, best/worst/median/P10-P90
- **NPS Tier II Tracking** (t271) — Separate Tier I/II views, tax treatment difference
- **PFM Performance Comparison** (t272) — All 8 PFMs, 1Y/3Y/5Y/since inception
- **NPS Pension Estimator** (t274) — FV + annuity split + monthly pension scenarios
- **NPS Tax Benefit Dashboard** (t275) — 80CCD(1) + 80CCD(1B) tracker, tax saved at 30%
- **FD Interest Rate Tracker** (t276) — 16 banks curated, opportunity cost per FD
- **TDS Tracker on FD** (t277) — Per-bank TDS threshold, Form 15G/H advisory
- **Recurring Deposit Module** (t278) — Full lifecycle tracker, quarterly compounding

---

## v33 — April 11, 2026

### ✅ New Features
- **Volatility Meter** (t263) — Std Dev gauge, category avg comparison
- **Sortino Ratio** (t264) — Downside deviation only, RF rate 5.5%
- **Calmar Ratio** (t265) — Return/max drawdown, bulk_calmar() admin action
- **Fund Age Analysis** (t266) — Inception date, age bucket, since-inception CAGR
- **Dividend History Tracker** (t268) — IDCW payout history, annual breakdown, yield
- **Momentum Score** (t270) — Weighted 40%×1M+30%×3M+20%×6M+10%×12M, normalized 0-100
- **Lumpsum vs SIP Optimizer** (t361) — Nifty P/E zones, actual CAGR comparison from nav_history
- **Tax Loss Harvesting Automation** (t363) — STCG/LTCG classification, FY timeline, alternatives
- **SIP Pause/Resume Intelligence** (t364) — Market-signal-based pause advice
- **Fund Overlap Optimizer** (t365) — Redundant fund removal suggestions
- **Dividend Reinvestment Tracker** (t366) — IDCW vs Growth comparison
- **Fund Category Migration Alert** (t368) — Auto-detect category changes, user-per dismiss

---

## v32 — April 11, 2026

### ✅ New Features
- **Style Box** (t179) — Morningstar 3×3 grid, size+value/blend/growth classification
- **NFO Tracker Enhancement** (tv08) — 4 categories, urgency badges, expense ratio, dates
- **MF Watchlist Alerts** (tv10) — DB-based alerts, 4 alert types, toast notifications
- **AMFI Data Quality Check** (tv13) — 10 quality checks, severity grid, admin fix button

---

## v31 — April 10, 2026

### ✅ Previously Completed (confirmed April 2026)
- Phase 1 Critical Bugs: t01–t06 (1D Change, Sort Menu, Button Icons, Format, NAV auto-update, Peak NAV)
- Phase 2 MF Holdings: t07–t13 (SIP/SWP badges, tracker, stop)
- Phase 3 Post Office: t14–t16, t18–t19 (TD tenures, MIS/SCSS payout, KVP, NSC, Tax badges)
- Phase 5 Screener: t25–t30 (NAV Chart, SIP Calc, Returns, Top Performers, Compare, Alerts)
- Phase 14 Screener Advanced: t63–t69 (AUM, NFO, Risk, Returns Filter, Fund Manager, Watchlist, Export)
- Phase 15 MF Analytics: t70–t73 (Portfolio Overlap, Asset Allocation, XIRR, SIP Performance)
- Phase 20 Advanced Analytics: t125 (TWRR)
- Phase 25 Data Pipeline: t160–t163 (NAV History cron, Returns calc, Daily NAV, NAV Proxy)
- Phase 26 Screener Level 3: t164–t167 (Sharpe, MDD, returns filters)
- Phase 27 MF Holdings Advanced: t172, t174–t176 (Fund overlap)
- Phase 28: t75 (Investment Calendar), t168 (Fund House Rankings)
- Phase 30: t187 (CAMS CAS Import), t190 (Import History)
- Phase 31 NAV History: t191–t195
- Other: t20 (Pagination), t22 (Tooltips), t54, t57, t77–t79, t81, t84, t86, t89, t99, t154, t169, t180, t210, t407 (Shared Screens)
