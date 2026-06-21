<?php
/**
 * WealthDash — PMS / AIF Tracker Page
 * Task: t119
 */
defined('WEALTHDASH') or die();
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'PMS & AIF Tracker';
$activePage  = 'pms_aif';

ob_start();
?>

<style>
/* ─── PMS/AIF Page ──────────────────────────────────────────── */
.pms-badge          { display:inline-block; padding:2px 8px; border-radius:99px;
                       font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.pms-badge-pms      { background:rgba(59,130,246,.12); color:#2563eb; }
.pms-badge-aif1     { background:rgba(22,163,74,.12);  color:#15803d; }
.pms-badge-aif2     { background:rgba(245,158,11,.12); color:#b45309; }
.pms-badge-aif3     { background:rgba(239,68,68,.12);  color:#b91c1c; }

.pms-card           { background:var(--bg-card); border:1.5px solid var(--border-color);
                       border-radius:12px; padding:18px 20px; transition:box-shadow .15s; }
.pms-card:hover     { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.pms-card-name      { font-size:14px; font-weight:700; color:var(--text-primary);
                       margin-bottom:2px; }
.pms-card-sub       { font-size:12px; color:var(--text-muted); margin-bottom:12px; }
.pms-metrics        { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.pms-metric         { text-align:center; }
.pms-metric-label   { font-size:10px; color:var(--text-muted); text-transform:uppercase;
                       letter-spacing:.03em; margin-bottom:2px; }
.pms-metric-value   { font-size:13px; font-weight:700; color:var(--text-primary); }

.pms-gain-pos       { color:#16a34a; }
.pms-gain-neg       { color:#dc2626; }

.pms-locked-tag     { display:inline-flex; align-items:center; gap:4px;
                       font-size:10px; font-weight:700; color:#d97706;
                       background:rgba(245,158,11,.1); padding:2px 7px; border-radius:99px;
                       margin-left:6px; }

/* Tabs */
.pms-tab-bar        { display:flex; gap:0; border-bottom:1.5px solid var(--border-color);
                       margin-bottom:20px; overflow-x:auto; }
.pms-tab            { padding:10px 18px; font-size:13px; font-weight:600; cursor:pointer;
                       color:var(--text-muted); border:none; background:none;
                       border-bottom:2.5px solid transparent; margin-bottom:-1.5px;
                       white-space:nowrap; transition:all .15s; }
.pms-tab.active     { color:var(--accent,#3b82f6); border-bottom-color:var(--accent,#3b82f6); }

/* Detail drawer */
.pms-drawer         { display:none; position:fixed; right:0; top:0; bottom:0;
                       width:min(480px,100vw); background:var(--bg-card);
                       box-shadow:-8px 0 32px rgba(0,0,0,.15); z-index:400;
                       overflow-y:auto; padding:24px; }
.pms-drawer.open    { display:block; }
.pms-drawer-ov      { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:399; }
.pms-drawer-ov.open { display:block; }

/* Chart */
#pmsNavChart        { max-height:220px; }
</style>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">PMS &amp; AIF Tracker</h1>
    <p class="page-subtitle">Portfolio Management Services &amp; Alternative Investment Funds</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" onclick="PmsTracker.exportCsv()">⬇ Export CSV</button>
    <button class="btn btn-primary" id="btnAddPms">+ Add PMS / AIF</button>
  </div>
</div>

<!-- ═══ SUMMARY STATS ═══ -->
<div class="stats-grid" style="margin-bottom:18px;" id="pmsSummaryGrid">
  <div class="stat-card"><div class="stat-label">Total Invested</div><div class="stat-value" id="statPmsInvested">—</div></div>
  <div class="stat-card"><div class="stat-label">Current Value</div><div class="stat-value" id="statPmsCurrent">—</div></div>
  <div class="stat-card"><div class="stat-label">Gain / Loss</div><div class="stat-value" id="statPmsGain">—</div></div>
  <div class="stat-card"><div class="stat-label">Avg XIRR</div><div class="stat-value" id="statPmsXirr">—</div></div>
  <div class="stat-card"><div class="stat-label">Holdings</div><div class="stat-value" id="statPmsCount">—</div></div>
</div>

<!-- ═══ FILTER TABS ═══ -->
<div class="pms-tab-bar">
  <button class="pms-tab active" data-filter="">All</button>
  <button class="pms-tab" data-filter="PMS">PMS</button>
  <button class="pms-tab" data-filter="AIF_CAT1">AIF Cat I</button>
  <button class="pms-tab" data-filter="AIF_CAT2">AIF Cat II</button>
  <button class="pms-tab" data-filter="AIF_CAT3">AIF Cat III</button>
</div>

<!-- ═══ HOLDINGS GRID ═══ -->
<div id="pmsGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
  <div style="text-align:center;padding:60px;grid-column:1/-1;"><div class="spinner"></div></div>
</div>

<!-- ═══ EMPTY STATE ═══ -->
<div id="pmsEmpty" style="display:none;text-align:center;padding:60px 20px;color:var(--text-muted);">
  <div style="font-size:48px;margin-bottom:16px;">🏦</div>
  <div style="font-size:16px;font-weight:600;margin-bottom:8px;">No PMS / AIF holdings yet</div>
  <div style="font-size:13px;margin-bottom:20px;">Track your high-value investments in Portfolio Management Services and Alternative Investment Funds</div>
  <button class="btn btn-primary" id="btnAddPmsEmpty">+ Add First Holding</button>
</div>

<!-- ═══ DETAIL DRAWER ═══ -->
<div class="pms-drawer-ov" id="pmsDrawerOv" onclick="PmsTracker.closeDrawer()"></div>
<div class="pms-drawer" id="pmsDrawer">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <h3 style="margin:0;font-size:16px;" id="drawerTitle">PMS Detail</h3>
    <button class="btn btn-ghost btn-sm" onclick="PmsTracker.closeDrawer()">✕</button>
  </div>
  <div id="drawerContent">
    <div style="text-align:center;padding:40px;"><div class="spinner"></div></div>
  </div>
</div>

<!-- ═══ ADD / EDIT MODAL ═══ -->
<div class="modal-overlay" id="modalPmsAdd" style="display:none;">
  <div class="modal" style="max-width:620px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="pmsModalTitle">Add PMS / AIF Holding</h3>
      <button class="btn btn-ghost btn-sm" onclick="PmsTracker.closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="pmsEditId">
      <input type="hidden" id="pmsCsrf" value="<?= generate_csrf() ?>">

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Asset Class *</label>
          <select id="pmsAssetClass" class="form-control">
            <option value="PMS">PMS — Portfolio Management Service</option>
            <option value="AIF_CAT1">AIF Category I</option>
            <option value="AIF_CAT2">AIF Category II</option>
            <option value="AIF_CAT3">AIF Category III (Hedge Fund)</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:2;">
          <label class="form-label">PMS / Fund Name *</label>
          <input type="text" id="pmsFundName" class="form-control" placeholder="e.g. Marcellus CCP, ASK IEP…">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Manager / AMC</label>
          <input type="text" id="pmsManagerName" class="form-control" placeholder="e.g. Marcellus">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:2;">
          <label class="form-label">Strategy Name</label>
          <input type="text" id="pmsStrategyName" class="form-control" placeholder="e.g. Consistent Compounders">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Folio / Account No</label>
          <input type="text" id="pmsFolioNo" class="form-control" placeholder="Optional">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Investment Date *</label>
          <input type="date" id="pmsInvestDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Invested Amount (₹) *</label>
          <input type="number" id="pmsInvested" class="form-control" placeholder="Minimum ₹50L for PMS" min="0" step="1000">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Current Value (₹)</label>
          <input type="number" id="pmsCurValue" class="form-control" placeholder="Optional — update later" min="0" step="1000">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">XIRR (%)</label>
          <input type="number" id="pmsXirr" class="form-control" placeholder="Optional" step="0.01">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Current NAV (₹)</label>
          <input type="number" id="pmsNav" class="form-control" placeholder="Optional" step="0.0001">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">NAV Date</label>
          <input type="date" id="pmsNavDate" class="form-control">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Units</label>
          <input type="number" id="pmsUnits" class="form-control" placeholder="Optional" step="0.0001">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Lock-in (months)</label>
          <input type="number" id="pmsLockMonths" class="form-control" placeholder="e.g. 36" min="0">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Mgmt Fee (%/yr)</label>
          <input type="number" id="pmsMgmtFee" class="form-control" placeholder="e.g. 2.5" step="0.01">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Perf. Fee (%)</label>
          <input type="number" id="pmsPerfFee" class="form-control" placeholder="e.g. 20" step="0.01">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Hurdle Rate (%)</label>
          <input type="number" id="pmsHurdle" class="form-control" placeholder="e.g. 10" step="0.01">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Benchmark</label>
          <input type="text" id="pmsBenchmark" class="form-control" placeholder="e.g. Nifty 500 TRI">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Platform</label>
          <select id="pmsPlatform" class="form-control">
            <option value="">— Select —</option>
            <option>Zerodha</option><option>IIFL</option><option>Motilal Oswal</option>
            <option>Kotak</option><option>HDFC Securities</option><option>SBI</option>
            <option>Direct AMC</option><option>Other</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" id="pmsNotes" class="form-control" placeholder="Optional…">
      </div>

      <div id="pmsError" style="display:none;color:var(--danger);font-size:13px;
           padding:8px 12px;background:rgba(239,68,68,.1);border-radius:6px;margin-top:8px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="PmsTracker.closeModal()">Cancel</button>
      <button class="btn btn-primary" id="btnSavePms">
        <span id="btnSavePmsLabel">Save</span>
        <div class="spinner-sm" id="btnSavePmsSpinner" style="display:none;margin-left:6px;"></div>
      </button>
    </div>
  </div>
</div>

<!-- ═══ ADD TRANSACTION MODAL ═══ -->
<div class="modal-overlay" id="modalPmsTxn" style="display:none;">
  <div class="modal" style="max-width:480px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">Add Transaction</h3>
      <button class="btn btn-ghost btn-sm" onclick="PmsTracker.closeTxnModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="txnHoldingId">
      <div class="form-group">
        <label class="form-label">Transaction Type *</label>
        <select id="pmsTxnType" class="form-control">
          <option value="investment">Additional Investment</option>
          <option value="withdrawal">Withdrawal / Redemption</option>
          <option value="dividend">Dividend / Distribution</option>
          <option value="management_fee">Management Fee Deducted</option>
          <option value="performance_fee">Performance Fee</option>
          <option value="nav_update">NAV Update</option>
          <option value="switch">Switch</option>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Date *</label>
          <input type="date" id="pmsTxnDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Amount (₹) *</label>
          <input type="number" id="pmsTxnAmount" class="form-control" placeholder="0" step="1000">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">NAV (₹)</label>
          <input type="number" id="pmsTxnNav" class="form-control" placeholder="Optional" step="0.0001">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Units</label>
          <input type="number" id="pmsTxnUnits" class="form-control" placeholder="Optional" step="0.0001">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" id="pmsTxnNotes" class="form-control" placeholder="Optional…">
      </div>
      <div id="pmsTxnError" style="display:none;color:var(--danger);font-size:13px;
           padding:8px 12px;background:rgba(239,68,68,.1);border-radius:6px;margin-top:8px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="PmsTracker.closeTxnModal()">Cancel</button>
      <button class="btn btn-primary" id="btnSavePmsTxn">
        <span id="btnSavePmsTxnLabel">Save Transaction</span>
        <div class="spinner-sm" id="btnSavePmsTxnSpinner" style="display:none;margin-left:6px;"></div>
      </button>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="' . APP_URL . '/public/js/pms_tracker.js?v=' . time() . '"></script>
<script>
window._PMS_BASE = "' . APP_URL . '";
document.addEventListener("DOMContentLoaded", () => PmsTracker.init());
</script>';

require_once APP_ROOT . '/templates/layout.php';
?>
