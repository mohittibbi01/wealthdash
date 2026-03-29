<?php
/**
 * WealthDash — MF Report & Tools Page
 * t181: Quick Add SIP from holdings
 * t182: Fund Notes
 * t183: Portfolio Report Card
 * t184: Investment Calendar
 * t185: Keyboard Shortcuts
 * t186: Print / PDF Report
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'MF Report & Tools';
$activePage  = 'mf_report';

$db          = DB::conn();
$portfolioId = get_user_portfolio_id((int)$currentUser['id']);

// Summary for report card
$summary = $db->prepare("
    SELECT COUNT(DISTINCT h.fund_id) AS fund_count,
           SUM(h.total_invested) AS total_invested,
           SUM(h.value_now) AS value_now,
           SUM(h.gain_loss) AS gain_loss
    FROM mf_holdings h
    JOIN portfolios p ON p.id = h.portfolio_id
    WHERE p.user_id = ? AND h.is_active = 1
");
$summary->execute([$currentUser['id']]);
$sum = $summary->fetch();
$totalInvested = (float)($sum['total_invested'] ?? 0);
$valueNow      = (float)($sum['value_now'] ?? 0);
$gainPct       = $totalInvested > 0 ? round(($valueNow - $totalInvested) / $totalInvested * 100, 2) : 0;
$fundCount     = (int)($sum['fund_count'] ?? 0);

ob_start();
?>
<!-- ============================================================
     PAGE HEADER
     ============================================================ -->
<div class="page-header no-print">
  <div>
    <h1 class="page-title">MF Report &amp; Tools</h1>
    <p class="page-subtitle">Portfolio health check, notes, SIP calendar &amp; export</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost" id="btnKbShortcuts" title="Keyboard Shortcuts (?)">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8"/></svg>
      Shortcuts
    </button>
    <button class="btn btn-primary" onclick="window.print()">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print / PDF
    </button>
  </div>
</div>

<!-- ============================================================
     TABS
     ============================================================ -->
<div class="tab-bar no-print" id="reportTabBar">
  <button class="tab-btn active" data-tab="report-card">📊 Report Card</button>
  <button class="tab-btn" data-tab="calendar">📅 SIP Calendar</button>
  <button class="tab-btn" data-tab="notes">📝 Fund Notes</button>
  <button class="tab-btn" data-tab="quick-sip">⚡ Quick Add SIP</button>
</div>

<!-- ============================================================
     TAB: REPORT CARD  (t183)
     ============================================================ -->
<div id="tab-report-card" class="tab-pane active">

  <!-- Summary banner -->
  <div class="rc-banner print-section">
    <div class="rc-banner-inner">
      <div class="rc-banner-logo">
        <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="m7 16 4-8 4 6 4-4"/></svg>
        WealthDash
      </div>
      <div class="rc-banner-title">Portfolio Report Card</div>
      <div class="rc-banner-date" id="rcReportDate"></div>
    </div>
  </div>

  <!-- Grade cards row -->
  <div class="rc-grade-row" id="rcGradeRow">
    <div class="rc-grade-card loading-pulse" id="gcReturns">
      <div class="rc-grade-label">Returns</div>
      <div class="rc-grade-val">—</div>
      <div class="rc-grade-sub"></div>
    </div>
    <div class="rc-grade-card loading-pulse" id="gcDiversification">
      <div class="rc-grade-label">Diversification</div>
      <div class="rc-grade-val">—</div>
      <div class="rc-grade-sub"></div>
    </div>
    <div class="rc-grade-card loading-pulse" id="gcSipConsistency">
      <div class="rc-grade-label">SIP Consistency</div>
      <div class="rc-grade-val">—</div>
      <div class="rc-grade-sub"></div>
    </div>
    <div class="rc-grade-card loading-pulse" id="gcDrawdown">
      <div class="rc-grade-label">Drawdown Risk</div>
      <div class="rc-grade-val">—</div>
      <div class="rc-grade-sub"></div>
    </div>
    <div class="rc-grade-card rc-overall loading-pulse" id="gcOverall">
      <div class="rc-grade-label">Overall Grade</div>
      <div class="rc-grade-val">—</div>
      <div class="rc-grade-sub"></div>
    </div>
  </div>

  <!-- Insights list -->
  <div class="rc-insights-wrap">
    <h3 class="rc-insights-title">💡 Insights</h3>
    <ul class="rc-insights-list" id="rcInsights">
      <li class="loading-pulse">Loading your portfolio data…</li>
    </ul>
  </div>

  <!-- Holdings table (for print) -->
  <div class="print-section" style="margin-top:24px;">
    <h3 class="rc-section-title">Holdings Snapshot</h3>
    <table class="rc-table print-table" id="rcHoldingsTable">
      <thead>
        <tr>
          <th>Fund</th><th>Category</th><th>Invested</th>
          <th>Value</th><th>Gain/Loss</th><th>CAGR</th>
        </tr>
      </thead>
      <tbody id="rcHoldingsTbody">
        <tr><td colspan="6" class="text-muted" style="text-align:center">Loading…</td></tr>
      </tbody>
      <tfoot id="rcHoldingsFoot"></tfoot>
    </table>
  </div>
</div>

<!-- ============================================================
     TAB: SIP CALENDAR  (t184)
     ============================================================ -->
<div id="tab-calendar" class="tab-pane" style="display:none">
  <div class="cal-wrap">
    <!-- Month nav -->
    <div class="cal-nav no-print">
      <button class="btn btn-ghost btn-sm" id="calPrev">‹ Prev</button>
      <h2 class="cal-month-label" id="calMonthLabel">—</h2>
      <button class="btn btn-ghost btn-sm" id="calNext">Next ›</button>
    </div>

    <!-- Legend -->
    <div class="cal-legend no-print">
      <span class="leg-dot leg-sip"></span>SIP
      <span class="leg-dot leg-swp" style="margin-left:14px"></span>SWP
      <span class="leg-dot leg-both" style="margin-left:14px"></span>Both
    </div>

    <!-- Calendar grid -->
    <div class="cal-grid" id="calGrid"></div>

    <!-- Event list for selected day -->
    <div class="cal-event-panel" id="calEventPanel" style="display:none">
      <h3 id="calEventTitle">Events</h3>
      <ul id="calEventList"></ul>
    </div>

    <!-- Monthly summary -->
    <div class="cal-summary-row" id="calSummaryRow">
      <div class="cal-sum-card"><div class="cal-sum-label">Active SIPs</div><div class="cal-sum-val" id="calActiveSips">—</div></div>
      <div class="cal-sum-card"><div class="cal-sum-label">Monthly SIP Total</div><div class="cal-sum-val" id="calMonthlySip">—</div></div>
      <div class="cal-sum-card"><div class="cal-sum-label">Active SWPs</div><div class="cal-sum-val" id="calActiveSWPs">—</div></div>
      <div class="cal-sum-card"><div class="cal-sum-label">Monthly SWP Total</div><div class="cal-sum-val" id="calMonthlySwp">—</div></div>
    </div>
  </div>
</div>

<!-- ============================================================
     TAB: FUND NOTES  (t182)
     ============================================================ -->
<div id="tab-notes" class="tab-pane" style="display:none">

  <!-- Search + Add -->
  <div class="notes-topbar no-print">
    <div class="input-wrap" style="flex:1;max-width:360px">
      <input type="text" id="noteFundSearch" class="form-control" placeholder="Search fund to add note…" autocomplete="off">
      <div class="search-dropdown" id="noteFundDropdown" style="display:none"></div>
    </div>
    <button class="btn btn-primary" id="btnSaveNote" disabled>Save Note</button>
  </div>

  <!-- Note editor (hidden until fund selected) -->
  <div id="noteEditorWrap" style="display:none;margin-bottom:20px;">
    <div class="notes-editor-header">
      <span id="noteEditorFundName" class="font-bold"></span>
      <button class="btn btn-ghost btn-sm btn-danger" id="btnDeleteNote">🗑 Delete Note</button>
    </div>
    <textarea id="noteTextarea" class="form-control notes-textarea" rows="5"
              placeholder="Write your personal note, target NAV, exit strategy, observations…"></textarea>
  </div>

  <!-- Existing notes list -->
  <div id="notesListWrap">
    <h3 class="section-heading">Saved Notes</h3>
    <div id="notesList" class="notes-cards-grid">
      <p class="text-muted">Loading notes…</p>
    </div>
  </div>
</div>

<!-- ============================================================
     TAB: QUICK ADD SIP  (t181)
     ============================================================ -->
<div id="tab-quick-sip" class="tab-pane" style="display:none">
  <p class="text-muted" style="margin-bottom:16px">
    Select any holding below → instantly add a SIP/SWP without leaving this page.
  </p>

  <!-- Holdings picker table -->
  <div class="table-responsive" style="margin-bottom:24px">
    <table class="data-table">
      <thead><tr>
        <th>Fund</th><th>Category</th><th>Value</th><th>CAGR</th><th>Action</th>
      </tr></thead>
      <tbody id="qsHoldingsTbody">
        <tr><td colspan="5" class="text-muted text-center">Loading…</td></tr>
      </tbody>
    </table>
  </div>

  <!-- Inline SIP add form (shows after clicking Add SIP) -->
  <div id="qsForm" class="qs-form-card" style="display:none">
    <h3 id="qsFormTitle">Add SIP/SWP</h3>
    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Type</label>
        <select id="qsType" class="form-control">
          <option value="SIP">SIP (Systematic Investment)</option>
          <option value="SWP">SWP (Systematic Withdrawal)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Amount (₹)</label>
        <input type="number" id="qsAmount" class="form-control" placeholder="e.g. 5000" min="100">
      </div>
      <div class="form-group">
        <label class="form-label">Frequency</label>
        <select id="qsFrequency" class="form-control">
          <option value="monthly">Monthly</option>
          <option value="weekly">Weekly</option>
          <option value="fortnightly">Fortnightly</option>
          <option value="quarterly">Quarterly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">SIP Day (1–28)</label>
        <input type="number" id="qsDay" class="form-control" placeholder="e.g. 5" min="1" max="28" value="5">
      </div>
      <div class="form-group">
        <label class="form-label">Start Date</label>
        <input type="date" id="qsStartDate" class="form-control">
      </div>
      <div class="form-group">
        <label class="form-label">End Date (optional)</label>
        <input type="date" id="qsEndDate" class="form-control">
      </div>
    </div>
    <input type="hidden" id="qsFundId">
    <input type="hidden" id="qsPortfolioId" value="<?= $portfolioId ?>">
    <div class="form-actions" style="margin-top:12px;gap:10px;display:flex">
      <button class="btn btn-primary" id="btnQsSave">✅ Save SIP/SWP</button>
      <button class="btn btn-ghost" id="btnQsCancel">Cancel</button>
    </div>
    <div id="qsMsg" style="margin-top:10px"></div>
  </div>
</div>

<!-- ============================================================
     KEYBOARD SHORTCUTS MODAL  (t185)
     ============================================================ -->
<div id="kbModal" class="modal-overlay" style="display:none" role="dialog" aria-modal="true">
  <div class="modal-box" style="max-width:460px">
    <div class="modal-header">
      <h3>⌨️ Keyboard Shortcuts</h3>
      <button class="modal-close" id="kbClose">✕</button>
    </div>
    <div class="modal-body">
      <table class="kb-table">
        <tbody>
          <tr><td><kbd>1</kbd></td><td>Go to Report Card tab</td></tr>
          <tr><td><kbd>2</kbd></td><td>Go to SIP Calendar tab</td></tr>
          <tr><td><kbd>3</kbd></td><td>Go to Fund Notes tab</td></tr>
          <tr><td><kbd>4</kbd></td><td>Go to Quick Add SIP tab</td></tr>
          <tr><td><kbd>P</kbd></td><td>Print / Save PDF</td></tr>
          <tr><td><kbd>?</kbd></td><td>Show / hide shortcuts</td></tr>
          <tr><td><kbd>Esc</kbd></td><td>Close any modal / panel</td></tr>
          <tr><td><kbd>←</kbd> <kbd>→</kbd></td><td>Previous / next calendar month</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============================================================
     PRINT HEADER (only visible when printing)
     ============================================================ -->
<div class="print-only print-header-block">
  <h1>WealthDash — MF Portfolio Report</h1>
  <p id="printDateLine"></p>
</div>

<!-- ============================================================
     STYLES
     ============================================================ -->
<style>
/* ── Tab bar ── */
.tab-bar{display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:20px;overflow-x:auto}
.tab-btn{background:none;border:none;padding:9px 16px;font-size:13px;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:color .15s,border-color .15s}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-btn:hover:not(.active){color:var(--text)}

/* ── Report Card ── */
.rc-banner{background:linear-gradient(135deg,var(--accent) 0%,#a855f7 100%);border-radius:var(--radius-lg);padding:18px 24px;margin-bottom:20px;color:#fff}
.rc-banner-inner{display:flex;align-items:center;gap:14px}
.rc-banner-logo{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:800;flex:1}
.rc-banner-title{font-size:18px;font-weight:800}
.rc-banner-date{font-size:12px;opacity:.8}
.rc-grade-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
@media(max-width:900px){.rc-grade-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:500px){.rc-grade-row{grid-template-columns:1fr}}
.rc-grade-card{background:var(--surface);border:2px solid var(--border);border-radius:var(--radius-lg);padding:16px 12px;text-align:center;transition:border-color .2s,transform .15s}
.rc-grade-card:hover{transform:translateY(-2px);border-color:var(--accent-border)}
.rc-grade-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.rc-grade-val{font-size:36px;font-weight:900;line-height:1}
.rc-grade-sub{font-size:11px;color:var(--muted);margin-top:6px}
.rc-overall{border-color:var(--accent);background:rgba(91,94,244,.06)}
.grade-a-plus{color:#16a34a}
.grade-a{color:#22c55e}
.grade-b{color:#84cc16}
.grade-c{color:#f59e0b}
.grade-d{color:#f97316}
.grade-f{color:#ef4444}

.rc-insights-wrap{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px}
.rc-insights-title{font-size:14px;font-weight:700;margin:0 0 12px}
.rc-insights-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px}
.rc-insights-list li{font-size:13px;padding-left:20px;position:relative}
.rc-insights-list li::before{content:attr(data-icon);position:absolute;left:0}

.rc-section-title{font-size:14px;font-weight:700;margin:0 0 10px}
.rc-table{width:100%;border-collapse:collapse;font-size:12px}
.rc-table th,.rc-table td{padding:7px 10px;border-bottom:1px solid var(--border);text-align:left}
.rc-table th{font-weight:700;font-size:11px;color:var(--muted);text-transform:uppercase;background:var(--surface)}
.rc-table tfoot td{font-weight:700;background:var(--surface)}

/* ── Calendar ── */
.cal-wrap{max-width:760px;margin:0 auto}
.cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.cal-month-label{font-size:18px;font-weight:800;margin:0}
.cal-legend{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin-bottom:14px}
.leg-dot{width:10px;height:10px;border-radius:50%;display:inline-block}
.leg-sip{background:#6366f1}
.leg-swp{background:#f59e0b}
.leg-both{background:#a855f7}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:16px}
.cal-dow{text-align:center;font-size:10px;font-weight:700;color:var(--muted);padding:4px;text-transform:uppercase}
.cal-cell{min-height:62px;border-radius:var(--radius);border:1.5px solid var(--border);padding:5px;cursor:pointer;transition:border-color .15s,background .15s;position:relative}
.cal-cell:hover{border-color:var(--accent-border);background:rgba(91,94,244,.04)}
.cal-cell.today{border-color:var(--accent);background:rgba(91,94,244,.07)}
.cal-cell.other-month{opacity:.35;cursor:default}
.cal-cell.selected{border-color:var(--accent);background:rgba(91,94,244,.1)}
.cal-day-num{font-size:11px;font-weight:700;color:var(--muted);margin-bottom:3px}
.cal-cell.today .cal-day-num{color:var(--accent)}
.cal-dots{display:flex;flex-wrap:wrap;gap:2px}
.cal-dot{width:7px;height:7px;border-radius:50%}
.cal-dot.sip{background:#6366f1}
.cal-dot.swp{background:#f59e0b}
.cal-cell-label{font-size:9px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cal-event-panel{background:var(--surface);border:1.5px solid var(--accent-border);border-radius:var(--radius-lg);padding:14px 18px;margin-bottom:16px}
.cal-event-panel h3{font-size:13px;font-weight:700;margin:0 0 10px}
.cal-event-panel ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:7px}
.cal-event-panel li{font-size:13px;display:flex;align-items:center;gap:8px}
.cal-ev-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px}
.sip-badge{background:rgba(99,102,241,.15);color:#6366f1}
.swp-badge{background:rgba(245,158,11,.15);color:#d97706}
.cal-summary-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px}
@media(max-width:600px){.cal-summary-row{grid-template-columns:repeat(2,1fr)}}
.cal-sum-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);padding:10px 14px}
.cal-sum-label{font-size:10px;font-weight:700;color:var(--muted);margin-bottom:4px;text-transform:uppercase}
.cal-sum-val{font-size:18px;font-weight:800;color:var(--accent)}

/* ── Notes ── */
.notes-topbar{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;position:relative}
.search-dropdown{position:absolute;top:100%;left:0;right:0;background:var(--surface);border:1.5px solid var(--accent-border);border-radius:var(--radius);z-index:200;max-height:220px;overflow-y:auto;box-shadow:var(--shadow-md)}
.search-dropdown .sd-item{padding:9px 14px;font-size:13px;cursor:pointer;border-bottom:1px solid var(--border)}
.search-dropdown .sd-item:hover{background:rgba(91,94,244,.07)}
.sd-item-name{font-weight:600}
.sd-item-sub{font-size:11px;color:var(--muted)}
.notes-editor-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.notes-textarea{width:100%;resize:vertical;font-family:inherit;font-size:13px}
.notes-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:10px}
.note-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:14px 16px;cursor:pointer;transition:border-color .15s}
.note-card:hover{border-color:var(--accent-border)}
.note-card-name{font-size:13px;font-weight:700;margin-bottom:6px;color:var(--accent)}
.note-card-text{font-size:12px;color:var(--muted);line-height:1.5;white-space:pre-wrap;max-height:80px;overflow:hidden}
.note-card-date{font-size:10px;color:var(--muted);margin-top:8px}
.section-heading{font-size:14px;font-weight:700;margin:0 0 10px}

/* ── Quick Add SIP ── */
.qs-form-card{background:var(--surface);border:2px solid var(--accent-border);border-radius:var(--radius-lg);padding:20px 24px;margin-top:4px}
.qs-form-card h3{margin:0 0 16px;font-size:15px}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:600px){.form-grid-2{grid-template-columns:1fr}}
.qs-add-btn{font-size:12px;padding:4px 10px;border-radius:99px}
.text-center{text-align:center}
.font-bold{font-weight:700}
.btn-danger{color:#ef4444}

/* ── Keyboard table ── */
.kb-table{width:100%;border-collapse:collapse}
.kb-table td{padding:8px 12px;border-bottom:1px solid var(--border);font-size:13px}
.kb-table td:first-child{white-space:nowrap}
kbd{display:inline-block;padding:2px 7px;border:1.5px solid var(--border);border-radius:5px;font-size:11px;font-weight:700;background:var(--surface);font-family:monospace}

/* ── Loading pulse ── */
.loading-pulse{animation:pulse 1.4s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* ── Print styles ── */
@media print{
  .no-print,.tab-bar,.page-header,.cal-nav,.cal-legend{display:none!important}
  .tab-pane{display:block!important}
  .print-only{display:block!important}
  .rc-grade-row{grid-template-columns:repeat(5,1fr)!important;page-break-inside:avoid}
  .rc-grade-card{border:1.5px solid #ccc!important}
  body{background:#fff!important}
  .print-header-block{text-align:center;margin-bottom:20px}
  .print-header-block h1{font-size:20px;margin-bottom:4px}
  .print-table{page-break-inside:auto}
  .print-table tr{page-break-inside:avoid}
}
.print-only{display:none}
</style>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
(function(){
'use strict';

const CSRF   = window.CSRF_TOKEN || '';
const API_BASE = (window.WD?.appUrl || '') + '/api/router.php';

// ── Helpers ────────────────────────────────────────────────────
const inr = n => new Intl.NumberFormat('en-IN',{style:'currency',currency:'INR',maximumFractionDigits:0}).format(n);
const pct = n => (n >= 0 ? '+' : '') + Number(n).toFixed(2) + '%';
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

async function apiGet(params) {
  const url = API_BASE + '?' + new URLSearchParams(params);
  const r = await fetch(url);
  return r.json();
}
async function apiPost(data) {
  const r = await fetch(API_BASE, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({...data, csrf_token: CSRF})
  });
  return r.json();
}

// ── Tabs ───────────────────────────────────────────────────────
const tabs = document.querySelectorAll('.tab-btn');
tabs.forEach(btn => btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

function switchTab(id) {
  tabs.forEach(b => b.classList.toggle('active', b.dataset.tab === id));
  document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
  document.getElementById('tab-' + id).style.display = '';
  if (id === 'calendar' && !calLoaded) loadCalendar();
  if (id === 'notes'    && !notesLoaded) loadNotes();
  if (id === 'quick-sip' && !qsLoaded) loadQS();
}

// ── REPORT CARD ─────────────────────────────────────────────────
let rcHoldings = [];

async function loadReportCard() {
  document.getElementById('rcReportDate').textContent = new Date().toLocaleDateString('en-IN',{dateStyle:'long'});
  document.getElementById('printDateLine').textContent = 'Generated: ' + new Date().toLocaleString('en-IN');

  // Load holdings + SIPs in parallel
  const [holdRes, sipRes] = await Promise.all([
    apiGet({action:'mf_list', view:'holdings'}),
    apiPost({action:'sip_list', show_inactive:0})
  ]);

  if (!holdRes.success) return;
  rcHoldings = holdRes.data || [];
  const sips  = sipRes.success ? (sipRes.data || []) : [];

  renderGrades(rcHoldings, sips);
  renderInsights(rcHoldings, sips);
  renderHoldingsTable(rcHoldings);
}

function gradeFromScore(s) { // 0-100
  if (s >= 90) return {g:'A+', cls:'grade-a-plus'};
  if (s >= 80) return {g:'A',  cls:'grade-a'};
  if (s >= 70) return {g:'B+', cls:'grade-a'};
  if (s >= 60) return {g:'B',  cls:'grade-b'};
  if (s >= 50) return {g:'C',  cls:'grade-c'};
  if (s >= 35) return {g:'D',  cls:'grade-d'};
  return {g:'F', cls:'grade-f'};
}

function renderGrades(holdings, sips) {
  if (!holdings.length) {
    document.getElementById('rcGradeRow').innerHTML = '<p class="text-muted" style="grid-column:1/-1">No holdings found.</p>';
    return;
  }

  // 1) Returns grade: based on avg CAGR
  const validCagrs = holdings.filter(h => h.cagr != null).map(h => h.cagr);
  const avgCagr = validCagrs.length ? validCagrs.reduce((a,b) => a+b,0)/validCagrs.length : 0;
  const returnsScore = Math.min(100, Math.max(0, (avgCagr/20)*100)); // 20% CAGR = 100 score
  const returnsGrade = gradeFromScore(returnsScore);

  // 2) Diversification: number of categories
  const cats = new Set(holdings.map(h => h.category?.split(' ')[0] || 'Other'));
  const divScore = Math.min(100, cats.size * 20);
  const divGrade = gradeFromScore(divScore);

  // 3) SIP Consistency: active SIPs / funds
  const activeSips = sips.filter(s => s.schedule_type === 'SIP' && s.is_active == 1).length;
  const sipScore = holdings.length > 0 ? Math.min(100, (activeSips/holdings.length)*100) : 0;
  const sipGrade = gradeFromScore(sipScore);

  // 4) Drawdown risk: avg drawdown (lower = better)
  const drawdowns = holdings.filter(h => h.drawdown_pct != null).map(h => h.drawdown_pct);
  const avgDd = drawdowns.length ? drawdowns.reduce((a,b) => a+b,0)/drawdowns.length : 0;
  const ddScore = Math.max(0, 100 - avgDd * 3); // 33% drawdown = 0
  const ddGrade = gradeFromScore(ddScore);

  // Overall
  const overallScore = (returnsScore + divScore + sipScore + ddScore) / 4;
  const overallGrade = gradeFromScore(overallScore);

  setGradeCard('gcReturns',       returnsGrade, pct(avgCagr) + ' avg CAGR');
  setGradeCard('gcDiversification', divGrade,   cats.size + ' categories');
  setGradeCard('gcSipConsistency', sipGrade,    activeSips + ' active SIPs');
  setGradeCard('gcDrawdown',       ddGrade,     pct(-avgDd) + ' avg drawdown');
  setGradeCard('gcOverall',        overallGrade,'Overall score: ' + overallScore.toFixed(0) + '/100');
}

function setGradeCard(id, grade, sub) {
  const el = document.getElementById(id);
  el.classList.remove('loading-pulse');
  el.querySelector('.rc-grade-val').textContent = grade.g;
  el.querySelector('.rc-grade-val').className = 'rc-grade-val ' + grade.cls;
  el.querySelector('.rc-grade-sub').textContent = sub;
}

function renderInsights(holdings, sips) {
  const ul = document.getElementById('rcInsights');
  const insights = [];

  if (!holdings.length) {
    ul.innerHTML = '<li data-icon="ℹ️">No MF holdings found. Add funds to get insights.</li>';
    return;
  }

  // Gain/loss summary
  const totalInv = holdings.reduce((a,h) => a + h.total_invested, 0);
  const totalVal = holdings.reduce((a,h) => a + h.value_now, 0);
  const gain     = totalVal - totalInv;
  const gainP    = totalInv > 0 ? (gain/totalInv*100).toFixed(2) : 0;
  insights.push({icon: gain>=0 ? '📈':'📉', text:`Portfolio is ${gain>=0?'up':'down'} ${Math.abs(gainP)}% (${inr(Math.abs(gain))}) overall.`});

  // Best performer
  const withCagr = holdings.filter(h => h.cagr != null);
  if (withCagr.length) {
    const best = withCagr.reduce((a,b) => a.cagr > b.cagr ? a : b);
    insights.push({icon:'🏆', text:`Best performer: ${best.scheme_name.slice(0,50)} at ${pct(best.cagr)} CAGR.`});
    const worst = withCagr.reduce((a,b) => a.cagr < b.cagr ? a : b);
    if (worst.cagr < 0) insights.push({icon:'⚠️', text:`Underperformer: ${worst.scheme_name.slice(0,50)} at ${pct(worst.cagr)} CAGR — review exit strategy.`});
  }

  // Diversification
  const cats = {};
  holdings.forEach(h => { const c = h.category||'Other'; cats[c] = (cats[c]||0) + h.total_invested; });
  const catEntries = Object.entries(cats).sort((a,b) => b[1]-a[1]);
  if (catEntries.length === 1) insights.push({icon:'⚠️', text:`All funds are in ${catEntries[0][0]}. Consider diversifying across equity, debt and hybrid.`});
  if (catEntries.length >= 3) insights.push({icon:'✅', text:`Good diversification across ${catEntries.length} categories.`});

  // SIP check
  const activeSips = sips.filter(s => s.schedule_type==='SIP' && s.is_active==1);
  if (activeSips.length === 0) insights.push({icon:'💡', text:`No active SIPs found. Use Quick Add SIP tab to start a systematic investment plan.`});
  else {
    const monthly = activeSips.reduce((a,s) => {
      if (s.frequency==='monthly') return a + parseFloat(s.sip_amount||0);
      if (s.frequency==='weekly')  return a + parseFloat(s.sip_amount||0)*4.33;
      return a;
    },0);
    if (monthly > 0) insights.push({icon:'🔄', text:`${activeSips.length} SIP(s) totalling ~${inr(monthly)}/month are active.`});
  }

  // High drawdown warning
  const highDd = holdings.filter(h => h.drawdown_pct > 25);
  if (highDd.length) insights.push({icon:'🔔', text:`${highDd.length} fund(s) are >25% below their peak NAV — monitor closely.`});

  // Folio count
  const multiFollio = holdings.filter(h => h.folio_count > 1);
  if (multiFollio.length) insights.push({icon:'📋', text:`${multiFollio.length} fund(s) have multiple folios — consider consolidating with your AMC.`});

  ul.innerHTML = insights.map(i => `<li data-icon="${i.icon}">${esc(i.text)}</li>`).join('');
}

function renderHoldingsTable(holdings) {
  const tbody = document.getElementById('rcHoldingsTbody');
  const tfoot = document.getElementById('rcHoldingsFoot');
  if (!holdings.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-muted text-center">No holdings.</td></tr>';
    return;
  }
  tbody.innerHTML = holdings.map(h => `
    <tr>
      <td>${esc(h.scheme_name)}</td>
      <td><span style="font-size:11px">${esc(h.category||'')}</span></td>
      <td>${inr(h.total_invested)}</td>
      <td>${inr(h.value_now)}</td>
      <td style="color:${h.gain_loss>=0?'var(--green)':'var(--red)'}">${inr(h.gain_loss)} (${pct(h.gain_pct)})</td>
      <td>${h.cagr != null ? pct(h.cagr) : '—'}</td>
    </tr>
  `).join('');

  const totInv = holdings.reduce((a,h) => a+h.total_invested,0);
  const totVal = holdings.reduce((a,h) => a+h.value_now,0);
  const totGain = totVal - totInv;
  const totPct  = totInv > 0 ? totGain/totInv*100 : 0;
  tfoot.innerHTML = `<tr>
    <td colspan="2"><strong>Total (${holdings.length} funds)</strong></td>
    <td><strong>${inr(totInv)}</strong></td>
    <td><strong>${inr(totVal)}</strong></td>
    <td style="color:${totGain>=0?'var(--green)':'var(--red)'}"><strong>${inr(totGain)} (${pct(totPct)})</strong></td>
    <td>—</td>
  </tr>`;
}

loadReportCard();

// ── SIP CALENDAR ──────────────────────────────────────────────
let calLoaded = false, calSips = [];
let calYear = new Date().getFullYear(), calMonth = new Date().getMonth(); // 0-indexed

async function loadCalendar() {
  calLoaded = true;
  const res = await apiPost({action:'sip_list', show_inactive:0});
  if (!res.success) return;
  calSips = res.data || [];
  updateCalSummary();
  renderCalendar();
}

function updateCalSummary() {
  const activeSips = calSips.filter(s => s.schedule_type==='SIP' && s.is_active==1);
  const activeSWPs = calSips.filter(s => s.schedule_type==='SWP' && s.is_active==1);
  const monthlySip = activeSips.reduce((a,s) => {
    const amt = parseFloat(s.sip_amount||0);
    if (s.frequency==='monthly') return a+amt;
    if (s.frequency==='weekly')  return a+amt*4.33;
    if (s.frequency==='fortnightly') return a+amt*2;
    if (s.frequency==='yearly') return a+amt/12;
    if (s.frequency==='quarterly') return a+amt/3;
    return a;
  },0);
  const monthlySwp = activeSWPs.reduce((a,s) => {
    const amt = parseFloat(s.sip_amount||0);
    if (s.frequency==='monthly') return a+amt;
    if (s.frequency==='weekly')  return a+amt*4.33;
    if (s.frequency==='fortnightly') return a+amt*2;
    return a;
  },0);
  document.getElementById('calActiveSips').textContent  = activeSips.length;
  document.getElementById('calMonthlySip').textContent  = inr(monthlySip);
  document.getElementById('calActiveSWPs').textContent  = activeSWPs.length;
  document.getElementById('calMonthlySwp').textContent  = inr(monthlySwp);
}

function sipDaysInMonth(sip, year, month) {
  // Returns array of day numbers (1–31) in given month when this SIP fires
  if (!sip.is_active) return [];
  const amt    = parseFloat(sip.sip_amount||0);
  const day    = parseInt(sip.sip_day||1);
  const freq   = sip.frequency;
  const result = [];
  const daysInMonth = new Date(year, month+1, 0).getDate();

  if (freq==='monthly' || freq==='quarterly' || freq==='yearly') {
    if (day >= 1 && day <= daysInMonth) {
      // For quarterly/yearly: check if this month is a firing month
      if (freq === 'monthly') { result.push(day); }
      else if (freq === 'quarterly') {
        const startDate = sip.start_date ? new Date(sip.start_date) : new Date();
        const monthsDiff = (year - startDate.getFullYear())*12 + (month - startDate.getMonth());
        if (monthsDiff >= 0 && monthsDiff % 3 === 0) result.push(day);
      } else if (freq === 'yearly') {
        const startDate = sip.start_date ? new Date(sip.start_date) : new Date();
        if (month === startDate.getMonth()) result.push(day);
      }
    }
  } else if (freq === 'weekly') {
    for (let d = 1; d <= daysInMonth; d++) result.push(d);
    return result.filter((_,i) => i%7===0).slice(0,5);
  } else if (freq === 'fortnightly') {
    return [1, 15].filter(d => d <= daysInMonth);
  } else if (freq === 'daily') {
    for (let d=1; d<=daysInMonth; d++) result.push(d);
  }
  return result;
}

function renderCalendar() {
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  document.getElementById('calMonthLabel').textContent = months[calMonth] + ' ' + calYear;

  const grid = document.getElementById('calGrid');
  const dows  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  let html = dows.map(d => `<div class="cal-dow">${d}</div>`).join('');

  const firstDay = new Date(calYear, calMonth, 1).getDay(); // 0=Sun
  const daysInMonth = new Date(calYear, calMonth+1, 0).getDate();
  const today = new Date();

  // Prev month days
  const prevDays = new Date(calYear, calMonth, 0).getDate();
  for (let i = firstDay-1; i >= 0; i--) {
    html += `<div class="cal-cell other-month"><div class="cal-day-num">${prevDays-i}</div></div>`;
  }

  // Build event map for month
  const eventMap = {}; // day → {sips:[], swps:[]}
  calSips.forEach(s => {
    const days = sipDaysInMonth(s, calYear, calMonth);
    days.forEach(d => {
      if (!eventMap[d]) eventMap[d] = {sips:[], swps:[]};
      if (s.schedule_type === 'SIP') eventMap[d].sips.push(s);
      else eventMap[d].swps.push(s);
    });
  });

  for (let d = 1; d <= daysInMonth; d++) {
    const isToday = d===today.getDate() && calMonth===today.getMonth() && calYear===today.getFullYear();
    const evs = eventMap[d] || {sips:[], swps:[]};
    const hasSip = evs.sips.length > 0, hasSwp = evs.swps.length > 0;
    const dotType = hasSip && hasSwp ? 'both' : hasSip ? 'sip' : hasSwp ? 'swp' : '';
    const dotsHtml = dotType ? `<div class="cal-dots"><span class="cal-dot ${dotType}"></span></div>` : '';
    const labelHtml = dotType ? `<div class="cal-cell-label">${[
      hasSip ? evs.sips.length+' SIP'+(evs.sips.length>1?'s':'') : '',
      hasSwp ? evs.swps.length+' SWP'+(evs.swps.length>1?'s':'') : '',
    ].filter(Boolean).join(', ')}</div>` : '';

    html += `<div class="cal-cell${isToday?' today':''}${dotType?' has-event':''}"
        onclick="calCellClick(${d})"
        data-d="${d}">
      <div class="cal-day-num">${d}</div>
      ${dotsHtml}${labelHtml}
    </div>`;
  }

  // Remaining cells
  const cellsUsed = firstDay + daysInMonth;
  const remaining = cellsUsed % 7 === 0 ? 0 : 7 - (cellsUsed % 7);
  for (let i = 1; i <= remaining; i++) {
    html += `<div class="cal-cell other-month"><div class="cal-day-num">${i}</div></div>`;
  }
  grid.innerHTML = html;
  window._calEventMap = eventMap;
}

window.calCellClick = function(d) {
  const evs = (window._calEventMap||{})[d] || {sips:[], swps:[]};
  if (!evs.sips.length && !evs.swps.length) {
    document.getElementById('calEventPanel').style.display='none'; return;
  }
  document.querySelectorAll('.cal-cell').forEach(c => c.classList.remove('selected'));
  document.querySelector(`.cal-cell[data-d="${d}"]`)?.classList.add('selected');
  document.getElementById('calEventTitle').textContent = `Events on ${d} ${['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][calMonth]}`;
  const list = document.getElementById('calEventList');
  list.innerHTML = [...evs.sips.map(s => `
    <li><span class="cal-ev-badge sip-badge">SIP</span>
      <strong>${esc(s.fund_name)}</strong> — ${inr(s.sip_amount)} (${s.frequency})
    </li>`),
    ...evs.swps.map(s => `
    <li><span class="cal-ev-badge swp-badge">SWP</span>
      <strong>${esc(s.fund_name)}</strong> — ${inr(s.sip_amount)} (${s.frequency})
    </li>`)
  ].join('');
  document.getElementById('calEventPanel').style.display='';
};

document.getElementById('calPrev').addEventListener('click', () => {
  calMonth--; if (calMonth < 0) { calMonth=11; calYear--; } renderCalendar();
});
document.getElementById('calNext').addEventListener('click', () => {
  calMonth++; if (calMonth > 11) { calMonth=0; calYear++; } renderCalendar();
});

// ── FUND NOTES ────────────────────────────────────────────────
let notesLoaded = false, allNotes = {}, selectedNotesFundId = null;

async function loadNotes() {
  notesLoaded = true;
  const res = await apiGet({action:'fund_notes_get'});
  if (res.success) { allNotes = res.notes || {}; renderNotesList(); }
}

function renderNotesList() {
  const wrap = document.getElementById('notesList');
  const keys = Object.keys(allNotes);
  if (!keys.length) {
    wrap.innerHTML = '<p class="text-muted">No notes yet. Search a fund above to add one.</p>';
    return;
  }
  wrap.innerHTML = keys.map(fid => {
    const n = allNotes[fid];
    return `<div class="note-card" onclick="editNote(${fid})">
      <div class="note-card-name">${esc(n.scheme_name)}</div>
      <div class="note-card-text">${esc(n.note)}</div>
      <div class="note-card-date">Updated: ${n.updated_at?.slice(0,10)||''}</div>
    </div>`;
  }).join('');
}

window.editNote = function(fundId) {
  const n = allNotes[fundId];
  if (!n) return;
  selectedNotesFundId = fundId;
  document.getElementById('noteFundSearch').value = n.scheme_name;
  document.getElementById('noteEditorFundName').textContent = n.scheme_name;
  document.getElementById('noteTextarea').value = n.note || '';
  document.getElementById('noteEditorWrap').style.display = '';
  document.getElementById('btnSaveNote').disabled = false;
};

// Fund search
let noteSearchTimer;
document.getElementById('noteFundSearch').addEventListener('input', function() {
  clearTimeout(noteSearchTimer);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('noteFundDropdown').style.display='none'; return; }
  noteSearchTimer = setTimeout(() => doNoteSearch(q), 300);
});

async function doNoteSearch(q) {
  const res = await apiGet({action:'mf_search', q});
  if (!res.success || !res.data?.length) {
    document.getElementById('noteFundDropdown').innerHTML = '<div class="sd-item text-muted">No funds found.</div>';
    document.getElementById('noteFundDropdown').style.display='';
    return;
  }
  const dd = document.getElementById('noteFundDropdown');
  dd.innerHTML = res.data.slice(0,8).map(f => `
    <div class="sd-item" onclick="selectNotesFund(${f.id},'${esc(f.scheme_name)}')">
      <div class="sd-item-name">${esc(f.scheme_name)}</div>
      <div class="sd-item-sub">${esc(f.fund_house||'')} · ${esc(f.category||'')}</div>
    </div>`).join('');
  dd.style.display='';
}

window.selectNotesFund = function(id, name) {
  selectedNotesFundId = id;
  document.getElementById('noteFundSearch').value = name;
  document.getElementById('noteFundDropdown').style.display='none';
  document.getElementById('noteEditorFundName').textContent = name;
  document.getElementById('noteTextarea').value = allNotes[id]?.note || '';
  document.getElementById('noteEditorWrap').style.display='';
  document.getElementById('btnSaveNote').disabled = false;
};

document.getElementById('btnSaveNote').addEventListener('click', async () => {
  if (!selectedNotesFundId) return;
  const note = document.getElementById('noteTextarea').value.trim();
  const res = await apiPost({action:'fund_note_save', fund_id: selectedNotesFundId, note});
  if (res.success) {
    await loadNotes();
    // Refresh local map
    if (note) allNotes[selectedNotesFundId] = {
      ...allNotes[selectedNotesFundId],
      scheme_name: document.getElementById('noteFundSearch').value,
      note, updated_at: new Date().toISOString()
    };
    else delete allNotes[selectedNotesFundId];
    renderNotesList();
    document.getElementById('noteEditorWrap').style.display='none';
    selectedNotesFundId = null;
    document.getElementById('noteFundSearch').value = '';
    document.getElementById('btnSaveNote').disabled = true;
  }
});

document.getElementById('btnDeleteNote').addEventListener('click', async () => {
  if (!selectedNotesFundId) return;
  if (!confirm('Delete this note?')) return;
  await apiPost({action:'fund_note_delete', fund_id: selectedNotesFundId});
  delete allNotes[selectedNotesFundId];
  renderNotesList();
  document.getElementById('noteEditorWrap').style.display='none';
  selectedNotesFundId = null;
  document.getElementById('noteFundSearch').value = '';
  document.getElementById('btnSaveNote').disabled = true;
});

// ── QUICK ADD SIP ─────────────────────────────────────────────
let qsLoaded = false, qsHoldings = [], qsSelectedFund = null;

async function loadQS() {
  qsLoaded = true;
  const res = await apiGet({action:'mf_list', view:'holdings'});
  if (!res.success) return;
  qsHoldings = res.data || [];
  renderQSTable();

  // Default start date = today
  document.getElementById('qsStartDate').value = new Date().toISOString().slice(0,10);
}

function renderQSTable() {
  const tbody = document.getElementById('qsHoldingsTbody');
  if (!qsHoldings.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">No holdings found.</td></tr>';
    return;
  }
  tbody.innerHTML = qsHoldings.map(h => `
    <tr>
      <td>
        <div style="font-size:13px;font-weight:600">${esc(h.scheme_name)}</div>
        <div style="font-size:11px;color:var(--muted)">${esc(h.fund_house_short||'')}</div>
      </td>
      <td style="font-size:12px">${esc(h.category||'')}</td>
      <td>${inr(h.value_now)}</td>
      <td style="color:${(h.cagr||0)>=0?'var(--green)':'var(--red)'}">${h.cagr!=null?pct(h.cagr):'—'}</td>
      <td>
        <button class="btn btn-primary qs-add-btn"
          onclick="qsShowForm(${h.fund_id},'${esc(h.scheme_name)}')">
          + Add SIP
        </button>
      </td>
    </tr>
  `).join('');
}

window.qsShowForm = function(fundId, name) {
  qsSelectedFund = fundId;
  document.getElementById('qsFormTitle').textContent = '⚡ Add SIP/SWP — ' + name;
  document.getElementById('qsFundId').value = fundId;
  document.getElementById('qsForm').style.display='';
  document.getElementById('qsMsg').innerHTML = '';
  document.getElementById('qsForm').scrollIntoView({behavior:'smooth', block:'nearest'});
};

document.getElementById('btnQsCancel').addEventListener('click', () => {
  document.getElementById('qsForm').style.display='none';
  qsSelectedFund = null;
});

document.getElementById('btnQsSave').addEventListener('click', async () => {
  const fundId    = parseInt(document.getElementById('qsFundId').value);
  const amount    = parseFloat(document.getElementById('qsAmount').value);
  const type      = document.getElementById('qsType').value;
  const frequency = document.getElementById('qsFrequency').value;
  const day       = parseInt(document.getElementById('qsDay').value);
  const startDate = document.getElementById('qsStartDate').value;
  const endDate   = document.getElementById('qsEndDate').value;
  const portfolioId = parseInt(document.getElementById('qsPortfolioId').value);

  const msgEl = document.getElementById('qsMsg');

  if (!fundId || !amount || amount < 100) {
    msgEl.innerHTML = '<span style="color:var(--red)">Amount must be ≥ ₹100.</span>';
    return;
  }
  if (!day || day < 1 || day > 28) {
    msgEl.innerHTML = '<span style="color:var(--red)">SIP day must be 1–28.</span>';
    return;
  }
  if (!portfolioId) {
    msgEl.innerHTML = '<span style="color:var(--red)">No portfolio found.</span>';
    return;
  }

  msgEl.innerHTML = '<span style="color:var(--muted)">Saving…</span>';
  const res = await apiPost({
    action: 'sip_add',
    fund_id: fundId,
    portfolio_id: portfolioId,
    schedule_type: type,
    sip_amount: amount,
    frequency,
    sip_day: day,
    start_date: startDate,
    end_date: endDate || null,
    is_active: 1
  });

  if (res.success) {
    msgEl.innerHTML = `<span style="color:var(--green)">✅ ${type} added successfully! Refreshing calendar…</span>`;
    document.getElementById('qsAmount').value = '';
    document.getElementById('qsEndDate').value = '';
    // Refresh calendar data silently
    calLoaded = false;
    if (document.getElementById('tab-calendar').style.display !== 'none') loadCalendar();
    setTimeout(() => { document.getElementById('qsForm').style.display='none'; msgEl.innerHTML=''; }, 2000);
  } else {
    msgEl.innerHTML = `<span style="color:var(--red)">Error: ${esc(res.message||'Unknown error')}</span>`;
  }
});

// ── KEYBOARD SHORTCUTS (t185) ─────────────────────────────────
const kbModal = document.getElementById('kbModal');
document.getElementById('btnKbShortcuts').addEventListener('click', () => kbModal.style.display='flex');
document.getElementById('kbClose').addEventListener('click', () => kbModal.style.display='none');
kbModal.addEventListener('click', e => { if(e.target===kbModal) kbModal.style.display='none'; });

document.addEventListener('keydown', e => {
  // Don't fire shortcuts if user is typing
  const tag = document.activeElement?.tagName;
  if (['INPUT','TEXTAREA','SELECT'].includes(tag)) {
    if (e.key === 'Escape') { kbModal.style.display='none'; document.activeElement.blur(); }
    return;
  }
  switch(e.key) {
    case '1': switchTab('report-card'); break;
    case '2': switchTab('calendar'); break;
    case '3': switchTab('notes'); break;
    case '4': switchTab('quick-sip'); break;
    case 'p': case 'P': window.print(); break;
    case '?': kbModal.style.display = kbModal.style.display==='flex' ? 'none' : 'flex'; break;
    case 'Escape': kbModal.style.display='none'; document.getElementById('calEventPanel').style.display='none'; break;
    case 'ArrowLeft':
      if (document.getElementById('tab-calendar').style.display !== 'none') {
        calMonth--; if (calMonth<0){calMonth=11;calYear--;} renderCalendar();
      }
      break;
    case 'ArrowRight':
      if (document.getElementById('tab-calendar').style.display !== 'none') {
        calMonth++; if (calMonth>11){calMonth=0;calYear++;} renderCalendar();
      }
      break;
  }
});

})();
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
