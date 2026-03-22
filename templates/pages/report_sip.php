<?php
/**
 * WealthDash — SIP Tracker Page (Phase 5)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'MF SIP/SWP';
$activePage  = 'report_sip';

// ── Fetch user portfolios & set selected portfolio ──
$db = DB::conn();
$portfoliosStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id = ? ORDER BY name ASC");
$portfoliosStmt->execute([$currentUser['id']]);
$portfolios = $portfoliosStmt->fetchAll();

// Resolve portfolio for current user (one portfolio per user)
$portfolioId = get_user_portfolio_id((int)$currentUser['id']);
if (!$portfolioId && !empty($portfolios)) {
    $portfolioId = (int) $portfolios[0]['id'];
}

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">MF SIP / SWP</h1>
    <p class="page-subtitle">Track your Systematic Investment & Withdrawal Plans · Upcoming · Performance</p>
  </div>
  <div class="page-actions" style="display:flex;gap:8px;align-items:center;">
    <?php if (count($portfolios) > 1): ?>
    <select id="sipPortfolioSelect" class="form-control form-control-sm" style="width:auto;min-width:160px;"
            onchange="onPortfolioChange(this.value)">
      <?php foreach ($portfolios as $p): ?>
      <option value="<?= $p['id'] ?>" <?= $p['id'] == $portfolioId ? 'selected' : '' ?>>
        <?= e($p['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <a href="/wealthdash/templates/pages/mf_holdings.php" class="btn btn-primary">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add SIP — Go to Holdings
    </a>
  </div>
</div>

<script>
// Inject server-side portfolio data into JS
window._SIP_PORTFOLIO_ID = <?= $portfolioId ?>;
window._SIP_PORTFOLIOS   = <?= json_encode($portfolios) ?>;

// Override WD.selectedPortfolio with confirmed value
if (window.WD) window.WD.selectedPortfolio = <?= $portfolioId ?>;

function onPortfolioChange(id) {
  window._SIP_PORTFOLIO_ID = parseInt(id);
  if (window.WD) window.WD.selectedPortfolio = parseInt(id);
  loadSipAnalysis();
  loadUpcoming();
  loadSipList();
  loadMonthlyChart(12);
  _holdingsFunds = null;
  loadHoldingsFunds();
}
function getSipPortfolioId() {
  return window._SIP_PORTFOLIO_ID || window.WD?.selectedPortfolio || 0;
}

// Global unhandled promise rejection catcher — shows error on page
window.addEventListener('unhandledrejection', function(e) {
  const msg = e.reason?.message || String(e.reason);
  // Update any still-loading cells
  document.querySelectorAll('td[colspan]').forEach(td => {
    if (td.textContent.trim() === 'Loading...') {
      td.textContent = 'Error: ' + msg;
      td.style.color = '#dc2626';
    }
  });
  console.error('Unhandled rejection:', e.reason);
});
</script>

<!-- Summary Cards -->
<div class="cards-grid cards-grid-4 mb-4" id="sipSummaryCards">
  <div class="stat-card"><div class="stat-label">Active SIPs</div><div class="stat-value" id="cardActiveSips">—</div></div>
  <div class="stat-card"><div class="stat-label">Active SWPs</div><div class="stat-value" id="cardActiveSwps">—</div></div>
  <div class="stat-card"><div class="stat-label">Monthly Investment</div><div class="stat-value" id="cardMonthlyAmt">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Invested (via SIP)</div><div class="stat-value" id="cardTotalInvested">—</div></div>
  <div class="stat-card"><div class="stat-label">Overall Gain</div><div class="stat-value" id="cardOverallGain">—</div></div>
</div>

<!-- Upcoming SIPs -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">🔄 Upcoming SIPs — Next 30 Days</h3>
    <span class="badge badge-info" id="upcomingCount">0</span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Fund</th><th>Amount</th><th>Frequency</th>
          <th>Next Date</th><th>Days Left</th><th>Platform</th>
        </tr>
      </thead>
      <tbody id="upcomingBody">
        <tr><td colspan="6" class="text-center text-secondary">Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Upcoming SWPs -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">💸 Upcoming SWPs — Next 30 Days</h3>
    <span class="badge badge-danger" id="upcomingSwpCount">0</span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Fund</th><th>Amount</th><th>Frequency</th>
          <th>Next Date</th><th>Days Left</th><th>Platform</th>
        </tr>
      </thead>
      <tbody id="upcomingSwpBody">
        <tr><td colspan="6" class="text-center text-secondary">Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Monthly Investment Chart -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">Monthly Investment History</h3>
    <select class="form-select" id="chartMonths" style="width:auto">
      <option value="12">Last 12 months</option>
      <option value="24">Last 24 months</option>
      <option value="36">Last 36 months</option>
    </select>
  </div>
  <div class="card-body">
    <canvas id="sipMonthlyChart" height="80"></canvas>
  </div>
</div>

<!-- All SIPs Table -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">🔄 All SIP Schedules</h3>
    <div style="display:flex;gap:.5rem;align-items:center">
      <label class="form-check">
        <input type="checkbox" id="showInactive"> Show inactive
      </label>
    </div>
  </div>
  <div class="table-wrap">
    <table class="data-table" id="sipTable">
      <thead>
        <tr>
          <th>Fund</th><th>Category</th><th>Amount</th><th>Frequency</th>
          <th>SIP Day</th><th>Start Date</th><th>Next Date</th>
          <th>Total Invested</th><th>XIRR</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody id="sipBody">
        <tr><td colspan="11" class="text-center text-secondary">Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- All SWPs Table -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">💸 All SWP Schedules</h3>
    <span class="badge badge-danger" id="swpTableCount">0</span>
  </div>
  <div class="table-wrap">
    <table class="data-table" id="swpTable">
      <thead>
        <tr>
          <th>Fund</th><th>Category</th><th>Withdrawal Amt</th><th>Frequency</th>
          <th>SWP Day</th><th>Start Date</th><th>Next Date</th>
          <th>Total Withdrawn</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody id="swpBody">
        <tr><td colspan="10" class="text-center text-secondary">Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add/Edit SIP Modal -->
<div class="modal-overlay" id="sipModal" style="display:none">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 class="modal-title" id="sipModalTitle">Add SIP</h3>
      <button class="modal-close" onclick="closeSipModal()">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="sipId">
      <input type="hidden" id="sipScheduleType" value="SIP">
      <div class="form-group">
        <label class="form-label">Type</label>
        <div style="display:flex;gap:0;border:1px solid var(--border-color,#e2e8f0);border-radius:8px;overflow:hidden;">
          <button type="button" id="btnTypeSip" onclick="setSipType('SIP')"
            style="flex:1;padding:8px;border:none;cursor:pointer;background:var(--primary,#3b82f6);color:#fff;font-weight:600;transition:.15s">
            🔵 SIP
          </button>
          <button type="button" id="btnTypeSwp" onclick="setSipType('SWP')"
            style="flex:1;padding:8px;border:none;cursor:pointer;background:var(--bg-secondary,#f8fafc);color:var(--text-muted,#64748b);font-weight:600;transition:.15s">
            🔴 SWP
          </button>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Fund <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="sipFundSearch"
               placeholder="Select from your holdings or search any fund…"
               autocomplete="off" style="cursor:pointer">
        <input type="hidden" id="sipFundId">
        <!-- Dropdown — shows holdings first, then search results -->
        <div id="sipFundDropdown" style="
          display:none;position:absolute;left:0;right:0;top:100%;
          background:var(--bg-card,#fff);border:1px solid var(--border-color,#e2e8f0);
          border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);
          max-height:320px;overflow-y:auto;z-index:2000;">
        </div>
        <div id="sipFundInfo" style="font-size:12px;color:var(--text-muted);margin-top:4px;min-height:16px;"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Folio Number</label>
        <input type="text" class="form-control" id="sipFolio" placeholder="Optional">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">SIP Amount (₹) <span class="text-danger">*</span></label>
          <input type="number" class="form-control" id="sipAmount" min="100" step="100" placeholder="5000">
        </div>
        <div class="form-group">
          <label class="form-label">Frequency</label>
          <select class="form-select" id="sipFrequency">
            <option value="daily">Daily (Every Day)</option>
            <option value="weekly">Weekly (Every 7 Days)</option>
            <option value="fortnightly">Fortnightly (Every 15 Days)</option>
            <option value="monthly" selected>Monthly (Every Month)</option>
            <option value="quarterly">Quarterly (Every 3 Months)</option>
            <option value="yearly">Yearly (Every Year)</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">SIP Day (of month)</label>
          <input type="number" class="form-control" id="sipDay" min="1" max="28" value="1">
        </div>
        <div class="form-group">
          <label class="form-label">Platform</label>
          <input type="text" class="form-control" id="sipPlatform" placeholder="Groww, Zerodha, etc.">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Start Date <span class="text-danger">*</span></label>
          <input type="text" class="form-control date-input" id="sipStartDate" placeholder="DD-MM-YYYY">
        </div>
        <div class="form-group">
          <label class="form-label">End Date <span class="text-secondary">(blank = ongoing)</span></label>
          <input type="text" class="form-control date-input" id="sipEndDate" placeholder="DD-MM-YYYY">
        </div>
      </div>
      <div class="form-group" id="sipActiveGroup" style="display:none">
        <label class="form-check">
          <input type="checkbox" id="sipIsActive" checked> SIP is active
        </label>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" class="form-control" id="sipNotes" placeholder="Optional">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeSipModal()">Cancel</button>
      <button class="btn btn-primary" id="btnSipSave">Save SIP</button>
    </div>
  </div>
</div>

<script>
function setSipType(type) {
  document.getElementById('sipScheduleType').value = type;
  const isSWP = type === 'SWP';
  document.getElementById('btnTypeSip').style.background = isSWP ? 'var(--bg-secondary,#f8fafc)' : 'var(--primary,#3b82f6)';
  document.getElementById('btnTypeSip').style.color      = isSWP ? 'var(--text-muted,#64748b)' : '#fff';
  document.getElementById('btnTypeSwp').style.background = isSWP ? '#dc2626' : 'var(--bg-secondary,#f8fafc)';
  document.getElementById('btnTypeSwp').style.color      = isSWP ? '#fff' : 'var(--text-muted,#64748b)';
  document.getElementById('btnSipSave').textContent = 'Save ' + type;
  document.getElementById('sipModalTitle').textContent =
    document.getElementById('sipId').value ? ('Edit ' + type) : ('Add ' + type);
}
document.addEventListener('DOMContentLoaded', () => {
  loadSipAnalysis();
  loadUpcoming();
  loadSipList();
  loadMonthlyChart(12);

  document.getElementById('btnSipSave').addEventListener('click', saveSip);
  document.getElementById('showInactive').addEventListener('change', loadSipList);
  document.getElementById('chartMonths').addEventListener('change', e => loadMonthlyChart(+e.target.value));
});

async function loadSipAnalysis() {
  try {
    const r = await API.post('/api/router.php', { action: 'sip_analysis', portfolio_id: getSipPortfolioId() });
    const d = r.data || {};
    document.getElementById('cardActiveSips').textContent    = d.sips?.length ?? 0;
    document.getElementById('cardActiveSwps').textContent    = d.active_swp_count ?? 0;
    document.getElementById('cardMonthlyAmt').textContent    = formatINR(d.total_monthly_sip);
    document.getElementById('cardTotalInvested').textContent = formatINR(d.total_invested);
    const gainEl = document.getElementById('cardOverallGain');
    const gain   = d.overall_gain     ?? 0;
    const pct    = d.overall_gain_pct ?? 0;
    gainEl.textContent  = formatINR(gain) + ' (' + (pct > 0 ? '+' : '') + Number(pct).toFixed(2) + '%)';
    gainEl.className    = 'stat-value ' + (gain >= 0 ? 'text-success' : 'text-danger');
  } catch(e) {
    ['cardActiveSips','cardActiveSwps','cardMonthlyAmt','cardTotalInvested','cardOverallGain']
      .forEach(id => { const el = document.getElementById(id); if(el) el.textContent = '—'; });
    console.error('sip_analysis error:', e);
  }
}

async function loadUpcoming() {
  try {
    const r = await API.post('/api/router.php', { action: 'sip_upcoming', days: 30, portfolio_id: getSipPortfolioId() });
    const d = r.data || {};
    const all     = d.upcoming || [];
    const sips    = all.filter(s => (s.schedule_type||'SIP').toUpperCase() === 'SIP');
    const swps    = all.filter(s => (s.schedule_type||'').toUpperCase()    === 'SWP');

    // ── SIP upcoming ─────────────────────────────────────────
    const countEl = document.getElementById('upcomingCount');
    if (countEl) countEl.textContent = sips.length;
    const tbody = document.getElementById('upcomingBody');
    if (!sips.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary">No upcoming SIPs in next 30 days</td></tr>';
    } else {
      tbody.innerHTML = sips.map(s => `
        <tr>
          <td>${esc(s.scheme_name || '—')}<br><small class="text-secondary">${esc(s.fund_house||'')}</small></td>
          <td class="text-right">${formatINR(s.sip_amount)}</td>
          <td><span class="badge badge-secondary">${s.frequency}</span></td>
          <td>${s.next_date ? formatDate(s.next_date) : '—'}</td>
          <td><span class="badge ${s.days_remaining <= 3 ? 'badge-danger' : 'badge-info'}">${s.days_remaining}d</span></td>
          <td>${esc(s.platform||'—')}</td>
        </tr>`).join('');
    }

    // ── SWP upcoming ─────────────────────────────────────────
    const swpCountEl = document.getElementById('upcomingSwpCount');
    if (swpCountEl) swpCountEl.textContent = swps.length;
    const swpBody = document.getElementById('upcomingSwpBody');
    if (!swpBody) return;
    if (!swps.length) {
      swpBody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary">No upcoming SWPs in next 30 days</td></tr>';
    } else {
      swpBody.innerHTML = swps.map(s => `
        <tr>
          <td>${esc(s.scheme_name || '—')}<br><small class="text-secondary">${esc(s.fund_house||'')}</small></td>
          <td class="text-right" style="color:#dc2626;font-weight:600;">${formatINR(s.sip_amount)}</td>
          <td><span class="badge badge-secondary">${s.frequency}</span></td>
          <td>${s.next_date ? formatDate(s.next_date) : '—'}</td>
          <td><span class="badge ${s.days_remaining <= 3 ? 'badge-danger' : 'badge-warning'}">${s.days_remaining}d</span></td>
          <td>${esc(s.platform||'—')}</td>
        </tr>`).join('');
    }
  } catch(e) {
    const msg = e?.message || String(e) || 'Unknown error';
    const errHtml = `<tr><td colspan="6" class="text-center" style="color:#dc2626;font-size:12px;padding:16px;">
      <strong>⚠ Error:</strong> ${esc(msg)}<br>
      <small style="color:#94a3b8;">Check browser Console (F12) for details</small>
    </td></tr>`;
    const tbody = document.getElementById('upcomingBody');
    if (tbody) tbody.innerHTML = errHtml;
    const swpBody = document.getElementById('upcomingSwpBody');
    if (swpBody) swpBody.innerHTML = errHtml;
    console.error('❌ sip_upcoming JS error:', e);
    // Test direct fetch to see raw PHP response
    fetch('/api/router.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({action:'sip_upcoming', days:30, portfolio_id: getSipPortfolioId()})
    }).then(r => r.text()).then(t => console.log('📦 sip_upcoming raw PHP response:', t)).catch(err => console.error('fetch failed:', err));
  }
}

async function loadSipList() {
  try {
    const r = await API.post('/api/router.php', { action: 'sip_list', portfolio_id: getSipPortfolioId() });
    const d = r.data || {};
    const showInactive = document.getElementById('showInactive').checked;
    const all  = (d.sips || []).filter(s => showInactive || s.is_active == 1);
    const sips = all.filter(s => (s.schedule_type||'SIP').toUpperCase() === 'SIP');
    const swps = all.filter(s => (s.schedule_type||'').toUpperCase()    === 'SWP');

    // ── SIP table ─────────────────────────────────────────────
    const sipBody = document.getElementById('sipBody');
    if (!sips.length) {
      sipBody.innerHTML = '<tr><td colspan="11" class="text-center text-secondary">No SIPs found. Add your first SIP from Holdings page!</td></tr>';
    } else {
      sipBody.innerHTML = sips.map(function(s) {
        var stopBtn = s.is_active == 1
          ? '<button class="btn btn-ghost btn-xs" style="color:#d97706" onclick="stopSip(' + s.id + ',\'' + esc(s.fund_name||'') + '\',\'SIP\')">Stop</button>'
          : '<span style="font-size:11px;color:#94a3b8;">Stopped</span>';
        return '<tr class="' + (s.is_active != 1 ? 'row-inactive' : '') + '" data-sip-id="' + s.id + '">'
          + '<td>' + esc(s.fund_name||'—') + '<br><small class="text-secondary">' + esc(s.fund_house||'') + '</small></td>'
          + '<td><span class="badge badge-secondary text-xs">' + esc(s.fund_category||'—') + '</span></td>'
          + '<td class="text-right"><strong>' + formatINR(s.sip_amount) + '</strong></td>'
          + '<td>' + (s.frequency||'') + '</td>'
          + '<td>' + (s.sip_day||'') + '</td>'
          + '<td>' + formatDate(s.start_date) + '</td>'
          + '<td>' + (s.next_date ? formatDate(s.next_date) : '<span class="text-secondary">—</span>') + '</td>'
          + '<td class="text-right">' + formatINR(s.total_invested) + '</td>'
          + '<td class="text-right sip-xirr"><span style="color:var(--text-muted);font-size:12px;cursor:pointer" onclick="loadSipXirr(' + s.id + ')" title="Click to calculate XIRR">📊 Calc</span></td>'
          + '<td><span class="badge ' + (s.is_active==1 ? 'badge-success' : 'badge-secondary') + '">' + (s.is_active==1?'Active':'Stopped') + '</span></td>'
          + '<td style="white-space:nowrap"><button class="btn btn-ghost btn-xs" onclick="editSip(' + s.id + ')">Edit</button> '
          + stopBtn
          + ' <button class="btn btn-ghost btn-xs" style="color:#0284c7;border-color:#bae6fd;" onclick="syncSipTxns(' + s.id + ',this)" title="Past transactions sync karo">⚙ Sync</button>'
          + ' <button class="btn btn-ghost btn-xs text-danger" onclick="deleteSip(' + s.id + ',\'' + esc(s.fund_name||'') + '\')">Delete</button></td>'
          + '</tr>';
      }).join('');
    }

    // ── SWP table ─────────────────────────────────────────────
    const swpBody     = document.getElementById('swpBody');
    const swpCountEl  = document.getElementById('swpTableCount');
    if (swpCountEl) swpCountEl.textContent = swps.filter(s => s.is_active == 1).length;
    if (!swpBody) return;
    if (!swps.length) {
      swpBody.innerHTML = '<tr><td colspan="10" class="text-center text-secondary">No SWPs found.</td></tr>';
    } else {
      swpBody.innerHTML = swps.map(function(s) {
        var stopBtn = s.is_active == 1
          ? '<button class="btn btn-ghost btn-xs" style="color:#d97706" onclick="stopSip(' + s.id + ',\'' + esc(s.fund_name||'') + '\',\'SWP\')">Stop</button>'
          : '<span style="font-size:11px;color:#94a3b8;">Stopped</span>';
        return '<tr class="' + (s.is_active != 1 ? 'row-inactive' : '') + '" data-sip-id="' + s.id + '">'
          + '<td>' + esc(s.fund_name||'—') + '<br><small class="text-secondary">' + esc(s.fund_house||'') + '</small></td>'
          + '<td><span class="badge badge-secondary text-xs">' + esc(s.fund_category||'—') + '</span></td>'
          + '<td class="text-right" style="color:#dc2626;font-weight:600;"><strong>' + formatINR(s.sip_amount) + '</strong></td>'
          + '<td>' + (s.frequency||'') + '</td>'
          + '<td>' + (s.sip_day||'') + '</td>'
          + '<td>' + formatDate(s.start_date) + '</td>'
          + '<td>' + (s.next_date ? formatDate(s.next_date) : '<span class="text-secondary">—</span>') + '</td>'
          + '<td class="text-right">' + formatINR(s.total_invested) + '</td>'
          + '<td><span class="badge ' + (s.is_active==1 ? 'badge-danger' : 'badge-secondary') + '">' + (s.is_active==1?'Active':'Stopped') + '</span></td>'
          + '<td style="white-space:nowrap"><button class="btn btn-ghost btn-xs" onclick="editSip(' + s.id + ')">Edit</button> '
          + stopBtn
          + ' <button class="btn btn-ghost btn-xs text-danger" onclick="deleteSip(' + s.id + ',\'' + esc(s.fund_name||'') + '\')">Delete</button></td>'
          + '</tr>';
      }).join('');
    }
  } catch(e) {
    document.getElementById('sipBody').innerHTML = '<tr><td colspan="11" class="text-center text-danger">Error: ' + esc(e.message) + '</td></tr>';
    const swpBody = document.getElementById('swpBody');
    if (swpBody) swpBody.innerHTML = '<tr><td colspan="10" class="text-center text-danger">Error: ' + esc(e.message) + '</td></tr>';
    console.error('sip_list error:', e);
  }
}

let sipChartInst = null;
async function loadMonthlyChart(months) {
  try {
    const r = await API.post('/api/router.php', { action: 'sip_monthly_chart', months, portfolio_id: getSipPortfolioId() });
    const chart  = (r.data || {}).chart || [];
    const labels = chart.map(r => r.ym);
    const values = chart.map(r => +r.invested);
    const ctx    = document.getElementById('sipMonthlyChart').getContext('2d');
    if (sipChartInst) sipChartInst.destroy();
    if (!chart.length) {
      // Show helpful empty state
      const canvas = document.getElementById('sipMonthlyChart');
      const ctx2 = canvas.getContext('2d');
      ctx2.clearRect(0, 0, canvas.width, canvas.height);
      const parent = canvas.parentElement;
      let emptyMsg = parent.querySelector('.chart-empty-msg');
      if (!emptyMsg) {
        emptyMsg = document.createElement('div');
        emptyMsg.className = 'chart-empty-msg';
        emptyMsg.style.cssText = 'text-align:center;padding:32px;color:var(--text-muted,#94a3b8);font-size:13px;';
        parent.appendChild(emptyMsg);
      }
      emptyMsg.innerHTML = '📊 No transaction history yet.<br><small>Use the <strong>⚙ Sync</strong> button on each SIP to load past transactions.</small>';
      return;
    }
    // Remove empty msg if data exists
    const existingMsg = document.getElementById('sipMonthlyChart').parentElement.querySelector('.chart-empty-msg');
    if (existingMsg) existingMsg.remove();
    sipChartInst = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Monthly Investment (₹)',
          data: values,
          backgroundColor: 'rgba(37,99,235,0.7)',
          borderRadius: 4,
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          y: { ticks: { callback: v => '₹' + (v>=100000 ? (v/100000).toFixed(1)+'L' : v>=1000 ? (v/1000).toFixed(0)+'K' : v) } }
        }
      }
    });
  } catch(e) { console.error(e); }
}

function openAddSip() {
  document.getElementById('sipModalTitle').textContent = 'Add SIP';
  document.getElementById('sipId').value        = '';
  setSipType('SIP');
  document.getElementById('sipFundSearch').value = '';
  document.getElementById('sipFundId').value     = '';
  document.getElementById('sipFundInfo').textContent = '';
  document.getElementById('sipAmount').value     = '';
  document.getElementById('sipFrequency').value  = 'monthly';
  document.getElementById('sipDay').value        = '1';
  document.getElementById('sipStartDate').value  = '';
  document.getElementById('sipEndDate').value    = '';
  document.getElementById('sipFolio').value      = '';
  document.getElementById('sipPlatform').value   = '';
  document.getElementById('sipNotes').value      = '';
  document.getElementById('sipActiveGroup').style.display = 'none';
  document.getElementById('sipModal').style.display = 'flex';

  // Init smart search + show holdings immediately
  initSipFundSearch();
  setTimeout(() => {
    showHoldingsInDropdown(); // show holdings as soon as modal opens
  }, 100);
}

async function editSip(id) {
  try {
    const r = await API.post('/api/router.php', { action: 'sip_list', portfolio_id: getSipPortfolioId() });
    const sip = (r.data?.sips || []).find(s => s.id == id);
    if (!sip) return;
    const editType = (sip.schedule_type||'SIP').toUpperCase();
    setSipType(editType);
    document.getElementById('sipModalTitle').textContent = 'Edit ' + editType;
    document.getElementById('sipId').value        = sip.id;
    document.getElementById('sipFundSearch').value = sip.fund_name||'';
    document.getElementById('sipFundId').value     = sip.fund_id||'';
    document.getElementById('sipAmount').value     = sip.sip_amount;
    document.getElementById('sipFrequency').value  = sip.frequency;
    document.getElementById('sipDay').value        = sip.sip_day;
    document.getElementById('sipStartDate').value  = sip.start_date ? formatDate(sip.start_date) : '';
    document.getElementById('sipEndDate').value    = sip.end_date   ? formatDate(sip.end_date)   : '';
    document.getElementById('sipPlatform').value   = sip.platform||'';
    document.getElementById('sipNotes').value      = sip.notes||'';
    document.getElementById('sipIsActive').checked = sip.is_active == 1;
    document.getElementById('sipActiveGroup').style.display = 'block';
    document.getElementById('sipModal').style.display = 'flex';
  } catch(e) { showToast('Error loading SIP','error'); }
}

async function saveSip() {
  const id       = document.getElementById('sipId').value;
  const fundId   = document.getElementById('sipFundId').value;
  const amount   = document.getElementById('sipAmount').value;
  const startDate= document.getElementById('sipStartDate').value;

  if (!fundId || !amount || !startDate) {
    showToast('Fund, amount, and start date are required.','error'); return;
  }

  const btnSave = document.getElementById('btnSipSave');
  btnSave.disabled = true;
  btnSave.textContent = 'Saving...';

  const payload = {
    action      : id ? 'sip_edit'  : 'sip_add',
    portfolio_id: getSipPortfolioId(),
    sip_id      : id,
    fund_id     : fundId,
    sip_amount  : amount,
    frequency   : document.getElementById('sipFrequency').value,
    sip_day     : document.getElementById('sipDay').value,
    start_date  : startDate,
    end_date    : document.getElementById('sipEndDate').value,
    folio_number: document.getElementById('sipFolio').value,
    platform    : document.getElementById('sipPlatform').value,
    notes       : document.getElementById('sipNotes').value,
    schedule_type: document.getElementById('sipScheduleType').value,
    is_active   : document.getElementById('sipIsActive')?.checked ? 1 : 0,
    csrf_token  : window.CSRF_TOKEN,
  };

  try {
    const res = await API.post('/api/router.php', payload);

    closeSipModal();
    loadSipList();
    loadSipAnalysis();

    // ── Handle NAV download status ──────────────────────────
    if (!id) { // only on add
      const d          = res.data || {};
      const navStatus  = d.nav_status  || 'available';
      const navMessage = d.nav_message || '';
      const sipId      = d.id || 0;

      if (navStatus === 'downloading') {
        // Show persistent notice — poll for completion
        showNavDownloadBanner(sipId, navMessage);
        pollNavReady(sipId, fundId, startDate);
      } else if (navStatus === 'no_data') {
        showToast('⚠ ' + navMessage, 'warning');
      } else {
        // NAV available — calculate XIRR immediately
        showToast('SIP saved! Calculating performance...', 'success');
        setTimeout(() => loadSipXirr(sipId), 500);
      }
    } else {
      showToast('SIP updated!', 'success');
    }

  } catch(e) {
    showToast(e.message, 'error');
  } finally {
    btnSave.disabled = false;
    btnSave.textContent = 'Save SIP';
  }
}

// Show a banner when NAV is downloading
function showNavDownloadBanner(sipId, message) {
  let banner = document.getElementById('navDownloadBanner');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'navDownloadBanner';
    banner.style.cssText = `
      position:fixed;bottom:20px;left:50%;transform:translateX(-50%);
      background:#1e40af;color:#fff;padding:12px 20px;border-radius:10px;
      font-size:13px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.25);
      display:flex;align-items:center;gap:12px;max-width:480px;
    `;
    document.body.appendChild(banner);
  }
  banner.innerHTML = `
    <div style="width:16px;height:16px;border:2px solid rgba(255,255,255,.3);
                border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0"></div>
    <div>
      <div style="font-weight:600;">📥 Downloading NAV History</div>
      <div style="font-size:12px;opacity:.85;margin-top:2px;">${message}</div>
    </div>
  `;
  banner.style.display = 'flex';
}

function hideNavDownloadBanner() {
  const b = document.getElementById('navDownloadBanner');
  if (b) b.style.display = 'none';
}

// Poll until NAV is ready then calculate XIRR
async function pollNavReady(sipId, fundId, startDate, attempts = 0) {
  if (attempts > 30) {
    hideNavDownloadBanner();
    showToast('NAV download taking long. Open NAV Downloader page and run it.', 'warning');
    return;
  }

  // First attempt — trigger actual download via JS fetch to sip_nav_fetch.php
  if (attempts === 0) {
    try {
      const tRes = await API.post('/api/router.php', {
        action: 'sip_nav_token', portfolio_id: getSipPortfolioId(),
        fund_id: fundId, start_date: startDate,
      });
      if (tRes.token) {
        const url = window.WD.appUrl + '/api/sip/sip_nav_fetch.php'
          + '?fund_id=' + fundId
          + '&from_date=' + encodeURIComponent(startDate)
          + '&token=' + tRes.token;
        fetch(url).catch(() => {}); // fire & forget from browser
      }
    } catch(e) { /* silent */ }
  }

  await new Promise(r => setTimeout(r, 10000));

  try {
    const res = await API.post('/api/router.php', {
      action: 'sip_nav_status', portfolio_id: getSipPortfolioId(),
      fund_id: fundId, start_date: startDate,
    });

    const pdata = res.data || {};
    if (pdata.is_ready) {
      hideNavDownloadBanner();
      showToast('✅ NAV data ready! Calculating XIRR...', 'success');
      loadSipXirr(sipId);
      loadSipList();
    } else if (pdata.dl_status === 'error') {
      hideNavDownloadBanner();
      showToast('⚠ NAV download failed. Go to NAV Downloader page and run it manually.', 'error');
    } else {
      pollNavReady(sipId, fundId, startDate, attempts + 1);
    }
  } catch(e) {
    pollNavReady(sipId, fundId, startDate, attempts + 1);
  }
}

// Load and display XIRR for a SIP
async function loadSipXirr(sipId) {
  try {
    const res = await API.post('/api/router.php', {
      action: 'sip_xirr',
      portfolio_id: getSipPortfolioId(),
      sip_id: sipId,
    });
    const xdata = res.data || {};

    // Update the SIP row in table with XIRR
    const row = document.querySelector(`tr[data-sip-id="${sipId}"]`);
    if (row) {
      const xirrEl = row.querySelector('.sip-xirr');
      if (xirrEl && xdata.xirr !== null) {
        const pct   = xdata.xirr;
        const color = pct >= 0 ? 'var(--gain,#16a34a)' : 'var(--loss,#dc2626)';
        xirrEl.innerHTML = `<span style="color:${color};font-weight:600;">${pct > 0 ? '+' : ''}${pct}%</span>`;
      }
    }
  } catch(e) {
    console.warn('XIRR load failed:', e);
  }
}

async function syncSipTxns(sipId, btn) {
  const orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = '⏳ Syncing...';
  btn.style.color = '#9ca3af';

  const appUrl = window.WD?.appUrl || window.APP_URL || '';
  const csrf   = document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN || '';

  try {
    const res  = await fetch(appUrl + '/api/router.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ action: 'sip_sync_txns', sip_id: sipId, csrf_token: csrf }),
    });
    const json = await res.json();
    if (json.success) {
      btn.textContent = '✓ ' + (json.txns_generated||0) + ' txns';
      btn.style.color = '#16a34a';
      btn.style.borderColor = '#86efac';
      setTimeout(() => loadSips(), 1500); // refresh table
    } else {
      btn.textContent = '✗ ' + (json.message||'Error');
      btn.style.color = '#dc2626';
      btn.disabled = false;
    }
  } catch(e) {
    btn.textContent = '✗ Error';
    btn.style.color = '#dc2626';
    btn.disabled = false;
  }
}

async function deleteSip(id, name) {
  if (!confirm(`Delete SIP for "${name}"?`)) return;
  try {
    await API.post('/api/router.php', { action:'sip_delete', sip_id:id, portfolio_id: getSipPortfolioId(), csrf_token:window.CSRF_TOKEN });
    showToast('SIP deleted.');
    loadSipList(); loadSipAnalysis();
  } catch(e) { showToast(e.message,'error'); }
}


function stopSip(id, name, type) {
  // name/type may come from dataset attributes
  if (typeof name === 'object' && name && name.dataset) { type = name.dataset.type; name = name.dataset.name; }
  if (!confirm((type||'SIP') + ' stop karna chahte ho?')) return;
  API.post('/api/router.php', {
    action: 'sip_stop', sip_id: id,
    end_date: new Date().toISOString().split('T')[0],
    portfolio_id: getSipPortfolioId(),
    csrf_token: window.CSRF_TOKEN
  }).then(function(res) {
    if (res.success) { showToast(type + ' stopped!', 'success'); loadSipList(); loadSipAnalysis(); }
    else showToast(res.message || 'Error', 'error');
  }).catch(function(e) { showToast('Error: ' + e.message, 'error'); });
}

function closeSipModal() {
  document.getElementById('sipModal').style.display = 'none';
}

function formatDate(d) {
  if (!d || d === '0000-00-00' || d === '—' || d === null) return '—';
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  let day, mon, yr;
  // YYYY-MM-DD format (correct DB format)
  if (/^\d{4}-\d{2}-\d{2}$/.test(d) && !d.startsWith('0000')) {
    const parts = d.split('-');
    yr = parts[0]; mon = parseInt(parts[1],10)-1; day = parseInt(parts[2],10);
  }
  // DD-MM-YYYY format (old bad data in DB)
  else if (/^\d{2}-\d{2}-\d{4}$/.test(d)) {
    const parts = d.split('-');
    day = parseInt(parts[0],10); mon = parseInt(parts[1],10)-1; yr = parts[2];
  }
  else return '—';
  if (mon < 0 || mon > 11 || day < 1 || day > 31) return '—';
  return `${String(day).padStart(2,'0')} ${months[mon]} ${yr}`;
}
let _holdingsFunds = null;

async function loadHoldingsFunds() {
  if (_holdingsFunds) return _holdingsFunds;
  try {
    const res = await API.get('/api/mutual_funds/mf_list.php?view=holdings');
    _holdingsFunds = (res.data || []).map(h => ({
      id:          h.fund_id,
      scheme_name: h.scheme_name,
      fund_house:  h.fund_house_short || h.fund_house || '',
      category:    h.category || '',
      latest_nav:  h.latest_nav,
      latest_nav_date: h.latest_nav_date,
      from_holdings: true,
    }));
  } catch(e) {
    _holdingsFunds = [];
  }
  return _holdingsFunds;
}

// ── Smart Fund Search ───────────────────────────────────
let _sipSearchTimer = null;

function initSipFundSearch() {
  const input    = document.getElementById('sipFundSearch');
  const dropdown = document.getElementById('sipFundDropdown');
  if (!input || !dropdown) return;

  // Relative positioning for dropdown
  input.parentElement.style.position = 'relative';

  input.addEventListener('focus', async () => {
    // On focus with empty input — show holdings
    if (!input.value.trim()) {
      showHoldingsInDropdown();
    }
  });

  input.addEventListener('input', () => {
    clearTimeout(_sipSearchTimer);
    const q = input.value.trim();

    document.getElementById('sipFundId').value = '';
    document.getElementById('sipFundInfo').textContent = '';

    if (!q) {
      showHoldingsInDropdown();
      return;
    }

    // Show holdings filter first (instant)
    showHoldingsInDropdown(q);

    // Then also search AMFI after 350ms
    _sipSearchTimer = setTimeout(() => searchSipFunds(q), 350);
  });

  input.addEventListener('blur', () => {
    setTimeout(() => { dropdown.style.display = 'none'; }, 200);
  });
}

async function showHoldingsInDropdown(query = '') {
  const dropdown = document.getElementById('sipFundDropdown');
  const funds    = await loadHoldingsFunds();

  let filtered = funds;
  if (query) {
    const q = query.toLowerCase();
    filtered = funds.filter(f =>
      f.scheme_name.toLowerCase().includes(q) ||
      f.fund_house.toLowerCase().includes(q)
    );
  }

  if (!filtered.length && !query) {
    dropdown.style.display = 'none';
    return;
  }

  const header = filtered.length
    ? `<div style="padding:8px 12px 4px;font-size:11px;font-weight:700;color:var(--text-muted,#94a3b8);
                   text-transform:uppercase;letter-spacing:.5px;background:var(--bg-secondary,#f8fafc);
                   border-bottom:1px solid var(--border-color,#e2e8f0);">
         📊 Your Holdings (${filtered.length})
       </div>`
    : '';

  const items = filtered.map(f => buildFundItem(f, true)).join('');
  const searchHint = query
    ? `<div style="padding:8px 12px;font-size:12px;color:var(--text-muted);
                   border-top:1px solid var(--border-color,#e2e8f0);text-align:center;">
         <span style="opacity:.7">🔍 Searching all funds for "${escHtml(query)}"...</span>
       </div>`
    : `<div style="padding:8px 12px;font-size:12px;color:var(--text-muted);
                   border-top:1px solid var(--border-color,#e2e8f0);text-align:center;">
         Type to search from all 14,000+ funds
       </div>`;

  dropdown.innerHTML = header + (items || '<div style="padding:12px;font-size:13px;color:var(--text-muted);">No matching holdings — try typing to search all funds</div>') + searchHint;
  dropdown.style.display = 'block';
}

async function searchSipFunds(q) {
  const dropdown = document.getElementById('sipFundDropdown');
  try {
    const res   = await API.get(`/api/mutual_funds/mf_search.php?q=${encodeURIComponent(q)}&limit=8`);
    const funds = res.data || [];
    const holdings = await loadHoldingsFunds();
    const holdingIds = new Set(holdings.map(h => h.id));

    // Filter out funds already shown as holdings
    const otherFunds = funds.filter(f => !holdingIds.has(f.id));

    // Holdings section (filtered)
    const holdingFiltered = holdings.filter(h =>
      h.scheme_name.toLowerCase().includes(q.toLowerCase()) ||
      h.fund_house.toLowerCase().includes(q.toLowerCase())
    );

    let html = '';

    if (holdingFiltered.length) {
      html += `<div style="padding:8px 12px 4px;font-size:11px;font-weight:700;
                            color:var(--text-muted,#94a3b8);text-transform:uppercase;
                            letter-spacing:.5px;background:var(--bg-secondary,#f8fafc);
                            border-bottom:1px solid var(--border-color,#e2e8f0);">
                 📊 Your Holdings
               </div>`;
      html += holdingFiltered.map(f => buildFundItem(f, true)).join('');
    }

    if (otherFunds.length) {
      html += `<div style="padding:8px 12px 4px;font-size:11px;font-weight:700;
                            color:var(--text-muted,#94a3b8);text-transform:uppercase;
                            letter-spacing:.5px;background:var(--bg-secondary,#f8fafc);
                            border-bottom:1px solid var(--border-color,#e2e8f0);
                            ${holdingFiltered.length ? 'border-top:2px solid var(--border-color,#e2e8f0)' : ''}">
                 🔍 All Funds
               </div>`;
      html += otherFunds.map(f => buildFundItem({
        id: f.id, scheme_name: f.scheme_name,
        fund_house: f.fund_house_short || f.fund_house || '',
        category: f.category || '', latest_nav: f.latest_nav,
        latest_nav_date: f.latest_nav_date, from_holdings: false,
      }, false)).join('');
    }

    if (!html) {
      html = `<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px;">
                No funds found for "${escHtml(q)}"
              </div>`;
    }

    dropdown.innerHTML = html;
    dropdown.style.display = 'block';
  } catch(e) {
    // Keep showing holdings if search fails
  }
}

function buildFundItem(f, isHolding) {
  const badge = isHolding
    ? `<span style="background:#dbeafe;color:#1d4ed8;padding:1px 6px;border-radius:4px;
                    font-size:10px;font-weight:700;margin-left:4px;">In Portfolio</span>`
    : '';
  const navInfo = f.latest_nav
    ? `NAV: ₹${Number(f.latest_nav).toFixed(4)}${f.latest_nav_date ? ' · ' + f.latest_nav_date : ''}`
    : '';

  return `<div
    onmousedown="selectSipFund(${f.id},'${escAttr(f.scheme_name)}',${f.latest_nav||0},'${escAttr(f.fund_house)}','${escAttr(f.category)}')"
    onmouseover="this.style.background='var(--bg-secondary,#f8fafc)'"
    onmouseout="this.style.background=''"
    style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color,#e2e8f0);transition:background .1s;">
      <div style="font-size:13px;font-weight:500;">${escHtml(f.scheme_name)}${badge}</div>
      <div style="font-size:11px;color:var(--text-muted,#94a3b8);margin-top:2px;">
        ${escHtml(f.fund_house)} · ${escHtml(f.category)}
        ${navInfo ? ' · <span style="color:var(--text-secondary)">' + navInfo + '</span>' : ''}
      </div>
  </div>`;
}

function selectSipFund(id, name, nav, fundHouse, category) {
  document.getElementById('sipFundSearch').value = name;
  document.getElementById('sipFundId').value     = id;
  document.getElementById('sipFundDropdown').style.display = 'none';
  document.getElementById('sipFundInfo').innerHTML =
    `<span style="color:var(--text-muted)">${escHtml(fundHouse)} · ${escHtml(category)}</span>` +
    (nav ? ` · <strong>NAV: ₹${Number(nav).toFixed(4)}</strong>` : '');
}

function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escAttr(s) { return String(s||'').replace(/'/g,"\\'").replace(/"/g,'&quot;'); }
</script>

<style>
.row-inactive { opacity:.55; }
</style>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>