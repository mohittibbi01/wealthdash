<?php
/**
 * WealthDash — MF Compare Tool Page
 * Task: tv12 — Side-by-side comparison of up to 5 mutual funds
 */
defined('WEALTHDASH') or die();
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Compare Mutual Funds';
$activePage  = 'mf_compare';

ob_start();
?>

<style>
/* ─── Compare Page ──────────────────────────────────────────── */
.cmp-search-wrap    { position:relative; }
.cmp-fund-pills     { display:flex; flex-wrap:wrap; gap:8px; min-height:44px;
                       align-items:center; padding:8px 12px;
                       background:var(--bg-secondary); border-radius:10px;
                       border:1.5px solid var(--border-color); }
.cmp-pill           { display:inline-flex; align-items:center; gap:6px;
                       background:var(--accent-light,rgba(59,130,246,.1));
                       color:var(--accent,#3b82f6); border:1px solid var(--accent-border,rgba(59,130,246,.25));
                       border-radius:99px; padding:4px 10px 4px 12px; font-size:12px; font-weight:600; }
.cmp-pill button    { background:none; border:none; cursor:pointer; color:inherit;
                       opacity:.7; font-size:14px; line-height:1; padding:0; }
.cmp-pill button:hover { opacity:1; }
.cmp-add-pill       { display:inline-flex; align-items:center; gap:4px;
                       color:var(--text-muted); font-size:12px; font-weight:500;
                       cursor:pointer; border:1.5px dashed var(--border-color);
                       border-radius:99px; padding:4px 12px; background:none;
                       transition:all .15s; }
.cmp-add-pill:hover { border-color:var(--accent,#3b82f6); color:var(--accent,#3b82f6); }

/* Fund search dropdown */
.cmp-search-dd      { display:none; position:absolute; left:0; right:0; top:calc(100% + 4px);
                       background:var(--bg-card); border:1px solid var(--border-color);
                       border-radius:10px; box-shadow:0 8px 28px rgba(0,0,0,.15);
                       max-height:300px; overflow-y:auto; z-index:500; }
.cmp-search-dd .dd-item { padding:10px 14px; cursor:pointer; font-size:13px;
                            border-bottom:1px solid var(--border-color); }
.cmp-search-dd .dd-item:last-child { border-bottom:none; }
.cmp-search-dd .dd-item:hover { background:var(--bg-secondary); }
.cmp-search-dd .dd-name  { font-weight:600; color:var(--text-primary); }
.cmp-search-dd .dd-meta  { font-size:11px; color:var(--text-muted); margin-top:2px; }

/* Period tabs */
.period-tabs        { display:inline-flex; gap:2px; background:var(--bg-secondary);
                       padding:3px; border-radius:8px; }
.period-tab         { padding:5px 14px; font-size:12px; font-weight:600;
                       border:none; border-radius:6px; cursor:pointer;
                       color:var(--text-muted); background:none; transition:all .15s; }
.period-tab.active  { background:var(--bg-card); color:var(--text-primary);
                       box-shadow:0 1px 4px rgba(0,0,0,.1); }

/* Compare table */
.cmp-table          { width:100%; border-collapse:collapse; font-size:13px; }
.cmp-table th       { padding:12px 14px; text-align:left; font-size:12px;
                       font-weight:700; color:var(--text-muted); text-transform:uppercase;
                       letter-spacing:.04em; background:var(--bg-secondary);
                       border-bottom:1px solid var(--border-color); white-space:nowrap; }
.cmp-table th:first-child { width:150px; position:sticky; left:0; z-index:2;
                              background:var(--bg-secondary); }
.cmp-table td       { padding:11px 14px; border-bottom:1px solid var(--border-color);
                       color:var(--text-primary); vertical-align:middle; }
.cmp-table td:first-child { font-weight:600; font-size:12px; color:var(--text-muted);
                              position:sticky; left:0; z-index:1;
                              background:var(--bg-card); white-space:nowrap; }
.cmp-table tr:hover td  { background:var(--bg-secondary); }
.cmp-table tr:hover td:first-child { background:var(--bg-secondary); }
.cmp-table .section-row td { background:var(--bg-secondary) !important;
                               font-size:11px; font-weight:800; letter-spacing:.06em;
                               color:var(--accent,#3b82f6); text-transform:uppercase;
                               padding:7px 14px; }
.cmp-best           { background:linear-gradient(135deg,#f0fdf4,#dcfce7);
                       color:#15803d; font-weight:800; padding:2px 8px;
                       border-radius:6px; font-size:12px; }
.cmp-worst          { color:#dc2626; font-weight:700; }

/* Fund header card in th */
.cmp-fund-header    { text-align:left; }
.cmp-fund-hname     { font-size:12px; font-weight:700; color:var(--text-primary);
                       line-height:1.4; }
.cmp-fund-hhouse    { font-size:10px; color:var(--text-muted); margin-top:2px; }
.cmp-fund-hbadge    { display:inline-flex; align-items:center; gap:4px;
                       font-size:10px; font-weight:700; padding:2px 7px;
                       border-radius:99px; margin-top:4px; }

/* NAV chart */
#cmpChart           { width:100%; height:300px; }

/* Loading overlay */
.cmp-loading        { display:flex; align-items:center; justify-content:center;
                       min-height:200px; }

/* Score bar */
.score-bar          { display:inline-flex; align-items:center; gap:6px; font-size:12px; }
.score-bar-track    { width:60px; height:6px; background:var(--bg-secondary);
                       border-radius:99px; overflow:hidden; }
.score-bar-fill     { height:100%; border-radius:99px; transition:width .4s; }

@media (max-width:768px) {
  .cmp-table th, .cmp-table td { padding:9px 10px; font-size:12px; }
  .cmp-table th:first-child, .cmp-table td:first-child { width:110px; font-size:11px; }
}
</style>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Compare Mutual Funds</h1>
    <p class="page-subtitle">Side-by-side analysis — up to 5 funds</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/templates/pages/mf_holdings.php" class="btn btn-ghost btn-sm">← Holdings</a>
    <a href="<?= APP_URL ?>/templates/pages/mf_screener.php" class="btn btn-ghost btn-sm">Screener</a>
    <button class="btn btn-outline btn-sm" id="btnCmpExportCsv" style="display:none;">
      ⬇ Export CSV
    </button>
  </div>
</div>

<!-- ═══ FUND SELECTOR ═══ -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:16px;">
    <div style="margin-bottom:10px;font-size:13px;font-weight:600;color:var(--text-muted);">
      Select 2–5 funds to compare
    </div>

    <!-- Pills row -->
    <div class="cmp-fund-pills" id="cmpPills">
      <span style="font-size:12px;color:var(--text-muted);">No funds selected yet…</span>
    </div>

    <!-- Search box -->
    <div class="cmp-search-wrap" style="margin-top:10px;">
      <input type="search" id="cmpFundSearch" class="form-control"
             placeholder="Search fund name or scheme code to add…"
             autocomplete="off" style="max-width:480px;">
      <div id="cmpSearchDd" class="cmp-search-dd"></div>
    </div>

    <div style="display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap;">
      <button class="btn btn-primary" id="btnRunCompare" disabled>
        <span id="btnRunLabel">Compare Funds</span>
        <div class="spinner-sm" id="btnRunSpinner" style="display:none;margin-left:6px;"></div>
      </button>
      <button class="btn btn-ghost btn-sm" id="btnCmpClear">Clear All</button>
      <button class="btn btn-ghost btn-sm" id="btnCmpLoadLast" title="Load your last saved comparison">
        ↩ Load Last
      </button>
      <span id="cmpFundCount" style="font-size:12px;color:var(--text-muted);"></span>
    </div>
  </div>
</div>

<!-- ═══ COMPARE RESULTS ═══ -->
<div id="cmpResults" style="display:none;">

  <!-- NAV Chart Card -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <h3 class="card-title" style="margin:0;">NAV Performance (Normalised to 100)</h3>
      <div class="period-tabs" id="cmpPeriodTabs">
        <button class="period-tab" data-period="1y">1Y</button>
        <button class="period-tab active" data-period="3y">3Y</button>
        <button class="period-tab" data-period="5y">5Y</button>
        <button class="period-tab" data-period="max">Max</button>
      </div>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="cmpChartWrap">
        <canvas id="cmpChart"></canvas>
      </div>
    </div>
  </div>

  <!-- SIP Simulation Card -->
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <h3 class="card-title" style="margin:0;">SIP Simulation (₹5,000/mo · 3 Years)</h3>
      <div style="font-size:12px;color:var(--text-muted);">Based on 3Y CAGR · Indicative only</div>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="cmpSipGrid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;"></div>
    </div>
  </div>

  <!-- Detailed Comparison Table -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title" style="margin:0;">Detailed Comparison</h3>
    </div>
    <div class="table-wrapper" id="cmpTableWrap">
      <div class="cmp-loading"><div class="spinner"></div></div>
    </div>
  </div>
</div>

<!-- ═══ EMPTY STATE ═══ -->
<div id="cmpEmpty" style="text-align:center;padding:60px 20px;color:var(--text-muted);">
  <div style="font-size:48px;margin-bottom:16px;">⚖️</div>
  <div style="font-size:16px;font-weight:600;margin-bottom:8px;">Select funds to compare</div>
  <div style="font-size:13px;">Search and add 2–5 funds above, then click <strong>Compare Funds</strong></div>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="' . APP_URL . '/public/js/mf_compare.js?v=' . time() . '"></script>
<script>
window._CMP_BASE = "' . APP_URL . '";
document.addEventListener("DOMContentLoaded", () => MfCompare.init());
</script>';

require_once APP_ROOT . '/templates/layout.php';
?>
