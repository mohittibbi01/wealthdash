<?php
/**
 * WealthDash — t117: ESOP / RSU Grant Tracking + Vesting
 * Full UI: Grants list, vesting timeline, exercise log, tax summary
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'ESOP / RSU';
$activePage    = 'esop';
$activeSection = 'alt_investments';
$pageScript    = 'app.js';

ob_start();
?>
<style>
.esop-stat { background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:20px;text-align:center; }
.esop-stat-label { font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px; }
.esop-stat-value { font-size:22px;font-weight:700;color:var(--text-primary); }
.esop-stat-sub { font-size:12px;color:var(--text-secondary);margin-top:2px; }
.grant-card { background:var(--card-bg);border:1px solid var(--border);border-radius:12px;margin-bottom:16px;overflow:hidden;transition:.15s; }
.grant-card:hover { border-color:var(--primary,#6366f1);box-shadow:0 2px 12px rgba(0,0,0,.07); }
.grant-card-header { display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);cursor:pointer; }
.grant-card-body { padding:16px 20px; }
.grant-type-badge { display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.3px; }
.badge-esop { background:rgba(99,102,241,.12);color:#6366f1; }
.badge-rsu  { background:rgba(16,185,129,.12);color:#059669; }
.badge-sar  { background:rgba(245,158,11,.12);color:#d97706; }
.badge-phantom { background:rgba(148,163,184,.12);color:#64748b; }
.status-badge { display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.status-active       { background:rgba(34,197,94,.12);color:#16a34a; }
.status-fully_vested { background:rgba(59,130,246,.12);color:#2563eb; }
.status-exercised_partial { background:rgba(245,158,11,.12);color:#d97706; }
.status-exercised_full    { background:rgba(148,163,184,.12);color:#64748b; }
.vest-bar-wrap { background:var(--border);border-radius:6px;height:8px;overflow:hidden;margin-top:8px;position:relative; }
.vest-bar-vested    { background:#6366f1;height:100%;position:absolute;left:0;top:0; }
.vest-bar-exercised { background:#10b981;height:100%;position:absolute;left:0;top:0; }
.vest-bar-lapsed    { background:#ef4444;height:100%;position:absolute;right:0;top:0; }
.esop-grid-3 { display:grid;grid-template-columns:repeat(3,1fr);gap:12px; }
.esop-grid-4 { display:grid;grid-template-columns:repeat(4,1fr);gap:12px; }
.esop-meta-label { font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.3px;margin-bottom:2px; }
.esop-meta-val { font-size:13px;font-weight:600;color:var(--text-primary); }
.vest-event-row { display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px; }
.vest-dot { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
.vest-dot.past   { background:#10b981; }
.vest-dot.today  { background:#f59e0b; }
.vest-dot.future { background:var(--border);border:2px solid #6366f1; }
.tbl { width:100%;border-collapse:collapse;font-size:13px; }
.tbl th { background:var(--table-header-bg,rgba(0,0,0,.04));padding:10px 14px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-secondary);border-bottom:1px solid var(--border);white-space:nowrap; }
.tbl td { padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle; }
.tbl tr:hover td { background:var(--hover-bg,rgba(0,0,0,.02)); }
.tbl .num { text-align:right; }
.gain-pos { color:#16a34a;font-weight:600; }
.gain-neg { color:#dc2626;font-weight:600; }
.btn-icon { background:none;border:1px solid var(--border);border-radius:6px;padding:4px 8px;cursor:pointer;color:var(--text-secondary);font-size:12px;transition:.15s; }
.btn-icon:hover { background:var(--hover-bg);color:var(--text-primary); }
.tab-btn { padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;color:var(--text-secondary);border-bottom:2px solid transparent;transition:.15s; }
.tab-btn.active { color:var(--primary,#6366f1);border-bottom-color:var(--primary,#6366f1);font-weight:600; }
.esop-empty { text-align:center;padding:48px 24px;color:var(--text-secondary); }
</style>

<div class="page-wrapper">
  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:24px 0 20px;border-bottom:1px solid var(--border);margin-bottom:24px;">
    <div>
      <h1 style="margin:0;font-size:24px;font-weight:700;">📈 ESOP / RSU</h1>
      <p style="color:var(--text-secondary);margin:4px 0 0;font-size:13px;">Employee Stock Options & Restricted Stock Units — vesting tracker &amp; exercise log</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="esopOpenAddModal()">＋ Add Grant</button>
  </div>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:28px;" id="esopSummaryCards">
    <div class="esop-stat"><div class="esop-stat-label">Grants</div><div class="esop-stat-value" id="sumGrants">—</div></div>
    <div class="esop-stat"><div class="esop-stat-label">Total Options</div><div class="esop-stat-value" id="sumOptions">—</div></div>
    <div class="esop-stat"><div class="esop-stat-label">Vested</div><div class="esop-stat-value" id="sumVested">—</div></div>
    <div class="esop-stat"><div class="esop-stat-label">Exercised</div><div class="esop-stat-value" id="sumExercised">—</div></div>
    <div class="esop-stat"><div class="esop-stat-label">Intrinsic Value</div><div class="esop-stat-value" id="sumIntrinsic">—</div><div class="esop-stat-sub">Vested unexercised</div></div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:20px;">
    <button class="tab-btn active" id="tabGrants" onclick="switchTab('grants')">Grants</button>
    <button class="tab-btn" id="tabVesting" onclick="switchTab('vesting')">Vesting Schedule</button>
    <button class="tab-btn" id="tabExercise" onclick="switchTab('exercise')">Exercise Log</button>
    <button class="tab-btn" id="tabTax" onclick="switchTab('tax')">Tax Summary</button>
  </div>

  <!-- Grants Tab -->
  <div id="panelGrants">
    <div id="grantsContainer">
      <div class="esop-empty"><p style="font-size:15px;font-weight:500;margin:0 0 8px;">Loading…</p></div>
    </div>
  </div>

  <!-- Vesting Schedule Tab -->
  <div id="panelVesting" style="display:none;">
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl" id="vestingTable">
        <thead>
          <tr>
            <th>Company / Grant</th><th>Vest Date</th><th class="num">Units</th>
            <th class="num">FMV on Vest</th><th class="num">Perquisite Value</th>
            <th>Exercised?</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="vestingTbody">
          <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Exercise Log Tab -->
  <div id="panelExercise" style="display:none;">
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl" id="exerciseTable">
        <thead>
          <tr>
            <th>Company / Grant</th><th>Exercise Date</th><th class="num">Units</th>
            <th class="num">Exercise Price</th><th class="num">FMV on Exercise</th>
            <th class="num">Perquisite Value</th><th class="num">Capital Gain</th><th>Type</th>
          </tr>
        </thead>
        <tbody id="exerciseTbody">
          <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Tax Summary Tab -->
  <div id="panelTax" style="display:none;">
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:24px;" id="taxSummaryPanel">
      <p style="color:var(--text-secondary);text-align:center;">Loading…</p>
    </div>
  </div>
</div>

<!-- ── Add / Edit Grant Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="esopModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:600px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="esopModalTitle">Add Grant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="esopEditId">
        <div class="row g-3">
          <div class="col-8">
            <label class="form-label">Company Name *</label>
            <input class="form-control form-control-sm" id="esopCompany" placeholder="Infosys Ltd.">
          </div>
          <div class="col-4">
            <label class="form-label">Symbol (NSE)</label>
            <input class="form-control form-control-sm" id="esopSymbol" placeholder="INFY">
          </div>
          <div class="col-4">
            <label class="form-label">Grant Type *</label>
            <select class="form-select form-select-sm" id="esopGrantType">
              <option value="ESOP">ESOP</option>
              <option value="RSU">RSU</option>
              <option value="SAR">SAR</option>
              <option value="PHANTOM">Phantom</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label">Grant Date *</label>
            <input type="date" class="form-control form-control-sm" id="esopGrantDate">
          </div>
          <div class="col-4">
            <label class="form-label">Grant Ref / ID</label>
            <input class="form-control form-control-sm" id="esopGrantRef" placeholder="GRANT-001">
          </div>
          <div class="col-4">
            <label class="form-label">Total Options / Units *</label>
            <input type="number" class="form-control form-control-sm" id="esopTotalOpts" min="1" placeholder="1000">
          </div>
          <div class="col-4">
            <label class="form-label">Exercise Price (₹)</label>
            <input type="number" class="form-control form-control-sm" id="esopExPrice" min="0" step="0.01" placeholder="0 for RSU">
          </div>
          <div class="col-4">
            <label class="form-label">Currency</label>
            <select class="form-select form-select-sm" id="esopCurrency">
              <option value="INR">INR</option>
              <option value="USD">USD</option>
              <option value="GBP">GBP</option>
              <option value="EUR">EUR</option>
            </select>
          </div>
          <div class="col-12"><hr style="margin:4px 0;"></div>
          <div class="col-4">
            <label class="form-label">Vesting Start *</label>
            <input type="date" class="form-control form-control-sm" id="esopVestStart">
          </div>
          <div class="col-4">
            <label class="form-label">Cliff (months)</label>
            <input type="number" class="form-control form-control-sm" id="esopCliff" value="12" min="0">
          </div>
          <div class="col-4">
            <label class="form-label">Vesting Period (months)</label>
            <input type="number" class="form-control form-control-sm" id="esopPeriod" value="48" min="1">
          </div>
          <div class="col-6">
            <label class="form-label">Vesting Type</label>
            <select class="form-select form-select-sm" id="esopVestType">
              <option value="graded">Graded</option>
              <option value="cliff">Cliff (100% at cliff)</option>
              <option value="custom">Custom (manual events)</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Schedule Description</label>
            <input class="form-control form-control-sm" id="esopSchedule" value="1/4 per year" placeholder="1/4 per year">
          </div>
          <div class="col-4">
            <label class="form-label">Current FMV (₹)</label>
            <input type="number" class="form-control form-control-sm" id="esopFmv" min="0" step="0.01" placeholder="Market price">
          </div>
          <div class="col-4">
            <label class="form-label">Expiry Date</label>
            <input type="date" class="form-control form-control-sm" id="esopExpiry">
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control form-control-sm" id="esopNotes" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="btnEsopSave" onclick="esopSave()">Save Grant</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Exercise Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="esopExModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:520px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Record Exercise</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="exGrantId">
        <input type="hidden" id="exEventId">
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Exercise Date *</label>
            <input type="date" class="form-control form-control-sm" id="exDate">
          </div>
          <div class="col-6">
            <label class="form-label">Units to Exercise *</label>
            <input type="number" class="form-control form-control-sm" id="exUnits" min="1">
          </div>
          <div class="col-6">
            <label class="form-label">Exercise Price (₹)</label>
            <input type="number" class="form-control form-control-sm" id="exPrice" min="0" step="0.01">
          </div>
          <div class="col-6">
            <label class="form-label">FMV on Exercise (₹)</label>
            <input type="number" class="form-control form-control-sm" id="exFmv" min="0" step="0.01">
          </div>
          <div class="col-6">
            <label class="form-label">Broker Charges (₹)</label>
            <input type="number" class="form-control form-control-sm" id="exBroker" value="0" min="0" step="0.01">
          </div>
          <div class="col-6">
            <label class="form-label">TDS Deducted (₹)</label>
            <input type="number" class="form-control form-control-sm" id="exTds" min="0" step="0.01" placeholder="Optional">
          </div>
          <div class="col-12"><hr style="margin:4px 0;"><p style="font-size:12px;color:var(--text-secondary);margin:0;">Sale details (if sold same day / later)</p></div>
          <div class="col-4">
            <label class="form-label">Sale Date</label>
            <input type="date" class="form-control form-control-sm" id="exSaleDate">
          </div>
          <div class="col-4">
            <label class="form-label">Sale Price (₹)</label>
            <input type="number" class="form-control form-control-sm" id="exSalePrice" min="0" step="0.01">
          </div>
          <div class="col-4">
            <label class="form-label">Notes</label>
            <input class="form-control form-control-sm" id="exNotes">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="btnExSave" onclick="esopExerciseSave()">Record</button>
      </div>
    </div>
  </div>
</div>

<!-- ── FMV Update Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="esopFmvModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:360px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Update FMV</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="fmvGrantId">
        <p id="fmvGrantName" style="font-weight:600;margin-bottom:12px;"></p>
        <label class="form-label">Current FMV per share (₹) *</label>
        <input type="number" class="form-control" id="fmvValue" min="0.01" step="0.01">
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="esopFmvSave()">Update FMV</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  'use strict';
  const API = '<?= APP_URL ?>/api/index.php';
  let grantsData = [];
  let currentTab = 'grants';

  // ── Init ───────────────────────────────────────────────────────────────────
  async function init() {
    await Promise.all([loadSummary(), loadGrants()]);
  }

  // ── Tab Switch ─────────────────────────────────────────────────────────────
  window.switchTab = function(tab) {
    currentTab = tab;
    ['grants','vesting','exercise','tax'].forEach(t => {
      document.getElementById('panel' + t.charAt(0).toUpperCase() + t.slice(1)).style.display = t === tab ? '' : 'none';
      document.getElementById('tab' + t.charAt(0).toUpperCase() + t.slice(1)).classList.toggle('active', t === tab);
    });
    if (tab === 'vesting')  loadVestingSchedule();
    if (tab === 'exercise') loadExerciseLog();
    if (tab === 'tax')      loadTaxSummary();
  };

  // ── Summary ────────────────────────────────────────────────────────────────
  async function loadSummary() {
    try {
      const r = await fetch(`${API}?action=esop_summary`);
      const d = await r.json();
      if (!d.success) return;
      const s = d.data;
      document.getElementById('sumGrants').textContent    = s.total_grants || 0;
      document.getElementById('sumOptions').textContent   = fmt(s.total_options);
      document.getElementById('sumVested').textContent    = fmt(s.total_vested);
      document.getElementById('sumExercised').textContent = fmt(s.total_exercised);
      document.getElementById('sumIntrinsic').textContent = '₹' + fmtMoney(s.total_intrinsic_value || 0);
    } catch(e) {}
  }

  // ── Grants ─────────────────────────────────────────────────────────────────
  async function loadGrants() {
    try {
      const r = await fetch(`${API}?action=esop_list`);
      const d = await r.json();
      const container = document.getElementById('grantsContainer');
      if (!d.success || !d.data.length) {
        container.innerHTML = `<div class="esop-empty">
          <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="opacity:.25;margin-bottom:12px;"><path d="M9 17H7A5 5 0 0 1 7 7h2m6 0h2a5 5 0 0 1 0 10h-2m-7-5h8"/></svg>
          <p style="font-size:15px;font-weight:500;margin:0 0 6px;">No ESOP / RSU grants yet</p>
          <p style="font-size:13px;margin:0;">Click <strong>+ Add Grant</strong> to start tracking.</p>
        </div>`;
        return;
      }
      grantsData = d.data;
      container.innerHTML = d.data.map(g => renderGrantCard(g)).join('');
    } catch(e) {
      document.getElementById('grantsContainer').innerHTML = '<div class="esop-empty"><p>Error loading grants.</p></div>';
    }
  }

  function renderGrantCard(g) {
    const b = g.breakdown || {};
    const total = parseInt(b.total) || 1;
    const vestedPct   = Math.min(100, ((b.vested || 0) / total * 100));
    const exercisedPct= Math.min(vestedPct, ((b.exercised || 0) / total * 100));
    const lapsedPct   = Math.min(100 - vestedPct, ((b.lapsed || 0) / total * 100));

    const typeClass = { ESOP:'badge-esop', RSU:'badge-rsu', SAR:'badge-sar', PHANTOM:'badge-phantom' }[g.grant_type] || 'badge-esop';
    const statusLabel = { active:'Active', fully_vested:'Fully Vested', exercised_partial:'Partial Exercise',
                          exercised_full:'Fully Exercised', lapsed:'Lapsed', cancelled:'Cancelled' }[g.status] || g.status;
    const statusClass = 'status-' + (g.status || 'active');

    const intrinsic = parseFloat(g.intrinsic_value || 0);
    const nextVest  = g.next_vest_date ? `<span style="color:var(--text-secondary);font-size:12px;">Next vest: <strong>${fmtDate(g.next_vest_date)}</strong> (${g.next_vest_units} units)</span>` : '';

    return `<div class="grant-card">
      <div class="grant-card-header" onclick="toggleGrantBody(${g.id})">
        <div style="display:flex;align-items:center;gap:12px;">
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
              <strong style="font-size:15px;">${esc(g.company_name)}</strong>
              <span class="grant-type-badge ${typeClass}">${g.grant_type}</span>
              <span class="status-badge ${statusClass}">${statusLabel}</span>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);">
              Grant: ${fmtDate(g.grant_date)}
              ${g.grant_ref ? `· Ref: ${esc(g.grant_ref)}` : ''}
              · Vesting: ${g.vesting_schedule || '—'}
              ${nextVest}
            </div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <div style="text-align:right;">
            <div style="font-size:13px;font-weight:700;">${b.vested || 0} / ${total} vested</div>
            <div style="font-size:11px;color:var(--text-secondary);">${g.vesting_pct || 0}% complete</div>
          </div>
          <button class="btn-icon" onclick="event.stopPropagation();esopOpenEditModal(${g.id})" title="Edit">✏️</button>
          <button class="btn-icon" onclick="event.stopPropagation();esopOpenFmvModal(${g.id},'${esc(g.company_name)}')" title="Update FMV">💹</button>
          <button class="btn-icon" onclick="event.stopPropagation();esopOpenExModal(${g.id})" title="Record Exercise">🏦</button>
          <button class="btn-icon" onclick="event.stopPropagation();esopDelete(${g.id},'${esc(g.company_name)}')" title="Remove">🗑️</button>
        </div>
      </div>
      <div class="grant-card-body" id="grantBody${g.id}" style="display:none;">
        <!-- Vesting Bar -->
        <div style="margin-bottom:16px;">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-secondary);margin-bottom:4px;">
            <span>Vesting Progress</span>
            <span>${b.vested || 0} vested · ${b.exercised || 0} exercised · ${b.lapsed || 0} lapsed · ${b.unvested || 0} unvested</span>
          </div>
          <div class="vest-bar-wrap">
            <div class="vest-bar-vested" style="width:${vestedPct}%;"></div>
            <div class="vest-bar-exercised" style="width:${exercisedPct}%;"></div>
            ${lapsedPct > 0 ? `<div class="vest-bar-lapsed" style="width:${lapsedPct}%;"></div>` : ''}
          </div>
        </div>
        <!-- Details Grid -->
        <div class="esop-grid-4" style="margin-bottom:16px;">
          <div>
            <div class="esop-meta-label">Exercise Price</div>
            <div class="esop-meta-val">${g.grant_type === 'RSU' ? 'N/A (RSU)' : '₹' + fmtNum(g.exercise_price)}</div>
          </div>
          <div>
            <div class="esop-meta-label">Current FMV</div>
            <div class="esop-meta-val">${g.current_fmv ? '₹' + fmtNum(g.current_fmv) : '<span style="color:var(--text-secondary);">Not set</span>'}</div>
          </div>
          <div>
            <div class="esop-meta-label">Intrinsic Value</div>
            <div class="esop-meta-val ${intrinsic >= 0 ? 'gain-pos' : 'gain-neg'}">${intrinsic > 0 ? '₹' + fmtMoney(intrinsic) : '—'}</div>
          </div>
          <div>
            <div class="esop-meta-label">Full Vest Date</div>
            <div class="esop-meta-val">${fmtDate(g.fully_vested_date)}</div>
          </div>
          <div>
            <div class="esop-meta-label">Cliff (months)</div>
            <div class="esop-meta-val">${g.vesting_cliff_months}</div>
          </div>
          <div>
            <div class="esop-meta-label">Period (months)</div>
            <div class="esop-meta-val">${g.vesting_period_months}</div>
          </div>
          <div>
            <div class="esop-meta-label">Expiry</div>
            <div class="esop-meta-val">${g.expiry_date ? fmtDate(g.expiry_date) : '—'}</div>
          </div>
          <div>
            <div class="esop-meta-label">Currency</div>
            <div class="esop-meta-val">${g.currency || 'INR'}</div>
          </div>
        </div>
        ${g.notes ? `<div style="font-size:12px;color:var(--text-secondary);background:var(--hover-bg,rgba(0,0,0,.03));padding:8px 12px;border-radius:6px;">${esc(g.notes)}</div>` : ''}
      </div>
    </div>`;
  }

  window.toggleGrantBody = function(id) {
    const el = document.getElementById('grantBody' + id);
    if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
  };

  // ── Vesting Schedule ───────────────────────────────────────────────────────
  async function loadVestingSchedule() {
    try {
      const r = await fetch(`${API}?action=esop_vesting_list`);
      const d = await r.json();
      const tbody = document.getElementById('vestingTbody');
      if (!d.success || !d.data.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-secondary);">No vesting events found.</td></tr>';
        return;
      }
      tbody.innerHTML = d.data.map(v => {
        const isPast   = v.days_until <= 0;
        const perqVal  = v.perquisite_value ? '₹' + fmtMoney(v.perquisite_value) : '—';
        const fmv      = v.fmv_on_vest ? '₹' + fmtNum(v.fmv_on_vest) : '—';
        const exercised = v.is_exercised ? '<span class="status-badge status-fully_vested">Exercised</span>' : (isPast ? '<span class="status-badge status-active">Available</span>' : '<span style="color:var(--text-secondary);">Pending</span>');
        return `<tr>
          <td>
            <strong>${esc(v.company_name || '')}</strong>
            <div style="font-size:11px;color:var(--text-secondary);">${v.grant_type || ''}</div>
          </td>
          <td>
            <span style="font-weight:600;${isPast ? '' : 'color:var(--primary,#6366f1);'}">${fmtDate(v.vest_date)}</span>
            ${isPast ? '' : `<div style="font-size:11px;color:var(--text-secondary);">in ${v.days_until} days</div>`}
          </td>
          <td class="num" style="font-weight:600;">${v.units_vested}</td>
          <td class="num">${fmv}</td>
          <td class="num">${perqVal}</td>
          <td>${exercised}</td>
          <td>
            ${!v.is_exercised && isPast ? `<button class="btn-icon" onclick="esopOpenExModal(${v.grant_id},${v.id})" title="Exercise">🏦 Exercise</button>` : ''}
          </td>
        </tr>`;
      }).join('');
    } catch(e) {}
  }

  // ── Exercise Log ───────────────────────────────────────────────────────────
  async function loadExerciseLog() {
    try {
      const r = await fetch(`${API}?action=esop_exercise_log`);
      const d = await r.json();
      const tbody = document.getElementById('exerciseTbody');
      if (!d.success || !d.data.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-secondary);">No exercises recorded yet.</td></tr>';
        return;
      }
      tbody.innerHTML = d.data.map(e => {
        const gain = parseFloat(e.capital_gain || 0);
        return `<tr>
          <td><strong>${esc(e.company_name || '')}</strong><div style="font-size:11px;color:var(--text-secondary);">${e.grant_type || ''}</div></td>
          <td>${fmtDate(e.exercise_date)}</td>
          <td class="num">${e.units}</td>
          <td class="num">₹${fmtNum(e.exercise_price)}</td>
          <td class="num">${e.fmv_on_exercise ? '₹' + fmtNum(e.fmv_on_exercise) : '—'}</td>
          <td class="num">${e.perquisite_value ? '₹' + fmtMoney(e.perquisite_value) : '—'}</td>
          <td class="num ${gain >= 0 ? 'gain-pos' : 'gain-neg'}">${e.capital_gain ? (gain >= 0 ? '+' : '') + '₹' + fmtMoney(gain) : '—'}</td>
          <td>${e.gain_type ? `<span class="grant-type-badge badge-esop">${e.gain_type}</span>` : '—'}</td>
        </tr>`;
      }).join('');
    } catch(e) {}
  }

  // ── Tax Summary ────────────────────────────────────────────────────────────
  async function loadTaxSummary() {
    try {
      const r = await fetch(`${API}?action=esop_summary`);
      const d = await r.json();
      if (!d.success) return;
      const s = d.data;
      const taxBase = parseFloat(s.perquisite_tax_base || 0);
      const upcoming = s.upcoming_vests || [];
      document.getElementById('taxSummaryPanel').innerHTML = `
        <h6 style="font-weight:700;margin-bottom:16px;">Tax Exposure Summary</h6>
        <div class="esop-grid-3" style="margin-bottom:24px;">
          <div class="esop-stat">
            <div class="esop-stat-label">Perquisite Tax Base</div>
            <div class="esop-stat-value">₹${fmtMoney(taxBase)}</div>
            <div class="esop-stat-sub">Vested unexercised options</div>
          </div>
          <div class="esop-stat">
            <div class="esop-stat-label">Upcoming Vests (90d)</div>
            <div class="esop-stat-value">${upcoming.length}</div>
            <div class="esop-stat-sub">events</div>
          </div>
          <div class="esop-stat">
            <div class="esop-stat-label">Tax Treatment</div>
            <div class="esop-stat-value" style="font-size:14px;">Perquisite</div>
            <div class="esop-stat-sub">Taxed as salary on exercise</div>
          </div>
        </div>
        <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;padding:16px;margin-bottom:24px;font-size:13px;">
          <strong>🇮🇳 Indian Tax Rules for ESOPs:</strong>
          <ul style="margin:8px 0 0;padding-left:18px;line-height:2;">
            <li><strong>Perquisite Tax:</strong> (FMV on exercise − Exercise price) × units, taxed as <strong>salary income</strong> in year of exercise.</li>
            <li><strong>Capital Gains:</strong> (Sale price − FMV on exercise), STCG if held &lt; 12 months, LTCG if ≥ 12 months.</li>
            <li><strong>LTCG Rate:</strong> 12.5% without indexation (post-Budget 2024) on listed shares. STCG: 20%.</li>
            <li><strong>Startup ESOPs:</strong> TDS deferred — payable 5 years / sale / leaving company (whichever is earlier).</li>
            <li><strong>RSUs:</strong> FMV on vest date = perquisite value; subsequent gain is capital gain.</li>
          </ul>
        </div>
        ${upcoming.length ? `
        <h6 style="font-weight:700;margin-bottom:12px;">Upcoming Vesting Events (Next 90 days)</h6>
        <table class="tbl"><thead><tr><th>Company</th><th>Vest Date</th><th class="num">Units</th><th class="num">Est. Perquisite</th></tr></thead>
        <tbody>${upcoming.map(u => {
          const perq = u.current_fmv && u.exercise_price ? (u.current_fmv - u.exercise_price) * u.units_vested : null;
          return `<tr><td>${esc(u.company_name)}</td><td>${fmtDate(u.vest_date)}</td><td class="num">${u.units_vested}</td><td class="num">${perq ? '₹' + fmtMoney(perq) : '—'}</td></tr>`;
        }).join('')}</tbody></table>` : ''}
      `;
    } catch(e) {}
  }

  // ── Modals ─────────────────────────────────────────────────────────────────
  window.esopOpenAddModal = function() {
    document.getElementById('esopEditId').value = '';
    document.getElementById('esopModalTitle').textContent = 'Add Grant';
    ['esopCompany','esopSymbol','esopGrantRef','esopFmv','esopNotes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('esopGrantType').value = 'ESOP';
    document.getElementById('esopGrantDate').value = '';
    document.getElementById('esopVestStart').value = '';
    document.getElementById('esopExpiry').value = '';
    document.getElementById('esopTotalOpts').value = '';
    document.getElementById('esopExPrice').value = '0';
    document.getElementById('esopCurrency').value = 'INR';
    document.getElementById('esopCliff').value = '12';
    document.getElementById('esopPeriod').value = '48';
    document.getElementById('esopVestType').value = 'graded';
    document.getElementById('esopSchedule').value = '1/4 per year';
    new bootstrap.Modal(document.getElementById('esopModal')).show();
  };

  window.esopOpenEditModal = function(id) {
    const g = grantsData.find(x => x.id === id);
    if (!g) return;
    document.getElementById('esopEditId').value       = id;
    document.getElementById('esopModalTitle').textContent = 'Edit Grant';
    document.getElementById('esopCompany').value      = g.company_name;
    document.getElementById('esopSymbol').value       = g.company_symbol || '';
    document.getElementById('esopGrantType').value    = g.grant_type;
    document.getElementById('esopGrantDate').value    = g.grant_date;
    document.getElementById('esopGrantRef').value     = g.grant_ref || '';
    document.getElementById('esopTotalOpts').value    = g.total_options;
    document.getElementById('esopExPrice').value      = g.exercise_price;
    document.getElementById('esopCurrency').value     = g.currency;
    document.getElementById('esopVestStart').value    = g.vesting_start;
    document.getElementById('esopCliff').value        = g.vesting_cliff_months;
    document.getElementById('esopPeriod').value       = g.vesting_period_months;
    document.getElementById('esopVestType').value     = g.vesting_type;
    document.getElementById('esopSchedule').value     = g.vesting_schedule || '';
    document.getElementById('esopFmv').value          = g.current_fmv || '';
    document.getElementById('esopExpiry').value       = g.expiry_date || '';
    document.getElementById('esopNotes').value        = g.notes || '';
    new bootstrap.Modal(document.getElementById('esopModal')).show();
  };

  window.esopSave = async function() {
    const id      = document.getElementById('esopEditId').value;
    const company = document.getElementById('esopCompany').value.trim();
    const total   = document.getElementById('esopTotalOpts').value;
    const grantDate = document.getElementById('esopGrantDate').value;
    if (!company || !total || !grantDate) { alert('Company name, grant date, and total options are required.'); return; }

    const body = new URLSearchParams({
      action:                id ? 'esop_update' : 'esop_add',
      company_name:          company,
      company_symbol:        document.getElementById('esopSymbol').value.trim(),
      grant_type:            document.getElementById('esopGrantType').value,
      grant_date:            grantDate,
      grant_ref:             document.getElementById('esopGrantRef').value.trim(),
      total_options:         total,
      exercise_price:        document.getElementById('esopExPrice').value || 0,
      currency:              document.getElementById('esopCurrency').value,
      vesting_start:         document.getElementById('esopVestStart').value || grantDate,
      vesting_cliff_months:  document.getElementById('esopCliff').value,
      vesting_period_months: document.getElementById('esopPeriod').value,
      vesting_type:          document.getElementById('esopVestType').value,
      vesting_schedule:      document.getElementById('esopSchedule').value.trim(),
      current_fmv:           document.getElementById('esopFmv').value || '',
      expiry_date:           document.getElementById('esopExpiry').value,
      notes:                 document.getElementById('esopNotes').value.trim(),
    });
    if (id) body.set('id', id);

    const btn = document.getElementById('btnEsopSave');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
      const r = await fetch(API, { method:'POST', body });
      const d = await r.json();
      if (d.success) {
        bootstrap.Modal.getInstance(document.getElementById('esopModal'))?.hide();
        await Promise.all([loadSummary(), loadGrants()]);
        if (d.events_created) {
          console.info(`Auto-generated ${d.events_created} vesting events.`);
        }
      } else { alert('Error: ' + d.message); }
    } finally { btn.disabled = false; btn.textContent = 'Save Grant'; }
  };

  window.esopDelete = async function(id, name) {
    if (!confirm(`Remove grant for "${name}"? This cannot be undone.`)) return;
    const r = await fetch(API, { method:'POST', body: new URLSearchParams({ action:'esop_delete', id }) });
    const d = await r.json();
    if (d.success) { await Promise.all([loadSummary(), loadGrants()]); }
    else alert('Error: ' + d.message);
  };

  window.esopOpenExModal = function(grantId, eventId) {
    document.getElementById('exGrantId').value  = grantId;
    document.getElementById('exEventId').value  = eventId || '';
    const g = grantsData.find(x => x.id === grantId);
    document.getElementById('exPrice').value    = g ? g.exercise_price : '';
    document.getElementById('exDate').value     = new Date().toISOString().split('T')[0];
    document.getElementById('exUnits').value    = '';
    document.getElementById('exFmv').value      = g && g.current_fmv ? g.current_fmv : '';
    document.getElementById('exBroker').value   = '0';
    document.getElementById('exTds').value      = '';
    document.getElementById('exSaleDate').value = '';
    document.getElementById('exSalePrice').value= '';
    document.getElementById('exNotes').value    = '';
    new bootstrap.Modal(document.getElementById('esopExModal')).show();
  };

  window.esopExerciseSave = async function() {
    const grantId = document.getElementById('exGrantId').value;
    const units   = document.getElementById('exUnits').value;
    const date    = document.getElementById('exDate').value;
    if (!units || !date) { alert('Exercise date and units required.'); return; }

    const body = new URLSearchParams({
      action:          'esop_exercise_add',
      grant_id:        grantId,
      vesting_event_id:document.getElementById('exEventId').value,
      exercise_date:   date,
      units,
      exercise_price:  document.getElementById('exPrice').value || 0,
      fmv_on_exercise: document.getElementById('exFmv').value,
      broker_charges:  document.getElementById('exBroker').value || 0,
      tds_deducted:    document.getElementById('exTds').value,
      sale_date:       document.getElementById('exSaleDate').value,
      sale_price:      document.getElementById('exSalePrice').value,
      notes:           document.getElementById('exNotes').value.trim(),
    });

    const btn = document.getElementById('btnExSave');
    btn.disabled = true;
    try {
      const r = await fetch(API, { method:'POST', body });
      const d = await r.json();
      if (d.success) {
        bootstrap.Modal.getInstance(document.getElementById('esopExModal'))?.hide();
        await Promise.all([loadSummary(), loadGrants()]);
        if (d.perquisite_value) {
          alert(`Exercise recorded!\nPerquisite value: ₹${fmtMoney(d.perquisite_value)}\nCapital gain: ${d.capital_gain ? '₹' + fmtMoney(d.capital_gain) : 'N/A'} (${d.gain_type || '—'})`);
        }
      } else { alert('Error: ' + d.message); }
    } finally { btn.disabled = false; }
  };

  window.esopOpenFmvModal = function(grantId, name) {
    document.getElementById('fmvGrantId').value    = grantId;
    document.getElementById('fmvGrantName').textContent = name;
    const g = grantsData.find(x => x.id === grantId);
    document.getElementById('fmvValue').value      = g && g.current_fmv ? g.current_fmv : '';
    new bootstrap.Modal(document.getElementById('esopFmvModal')).show();
  };

  window.esopFmvSave = async function() {
    const grantId = document.getElementById('fmvGrantId').value;
    const fmv     = document.getElementById('fmvValue').value;
    if (!fmv || parseFloat(fmv) <= 0) { alert('Enter a valid FMV.'); return; }
    const r = await fetch(API, { method:'POST', body: new URLSearchParams({ action:'esop_fmv_update', grant_id:grantId, current_fmv:fmv }) });
    const d = await r.json();
    if (d.success) {
      bootstrap.Modal.getInstance(document.getElementById('esopFmvModal'))?.hide();
      await Promise.all([loadSummary(), loadGrants()]);
    } else { alert('Error: ' + d.message); }
  };

  // ── Utils ──────────────────────────────────────────────────────────────────
  function fmt(n)       { return parseInt(n || 0).toLocaleString('en-IN'); }
  function fmtNum(n)    { return parseFloat(n || 0).toLocaleString('en-IN', {minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fmtMoney(n)  {
    n = parseFloat(n) || 0;
    if (n >= 1e7) return (n/1e7).toFixed(2) + ' Cr';
    if (n >= 1e5) return (n/1e5).toFixed(2) + ' L';
    return n.toLocaleString('en-IN', {maximumFractionDigits:0});
  }
  function fmtDate(s)   {
    if (!s) return '—';
    return new Date(s).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'});
  }
  function esc(s)       {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  init();
})();
</script>
<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
