<?php
/**
 * WealthDash — SIP Tracker Page (Phase 5)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'SIP Tracker';
$activePage  = 'report_sip';

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">SIP Tracker</h1>
    <p class="page-subtitle">Track your Systematic Investment Plans · Upcoming · Performance</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" id="btnAddSip">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add SIP
    </button>
  </div>
</div>

<!-- Summary Cards -->
<div class="cards-grid cards-grid-4 mb-4" id="sipSummaryCards">
  <div class="stat-card"><div class="stat-label">Active SIPs</div><div class="stat-value" id="cardActiveSips">—</div></div>
  <div class="stat-card"><div class="stat-label">Monthly Investment</div><div class="stat-value" id="cardMonthlyAmt">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Invested (via SIP)</div><div class="stat-value" id="cardTotalInvested">—</div></div>
  <div class="stat-card"><div class="stat-label">Overall Gain</div><div class="stat-value" id="cardOverallGain">—</div></div>
</div>

<!-- Upcoming SIPs -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">Upcoming SIPs — Next 30 Days</h3>
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
<div class="card">
  <div class="card-header">
    <h3 class="card-title">All SIP Schedules</h3>
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
          <th>Total Invested</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody id="sipBody">
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
      <div class="form-group">
        <label class="form-label">Fund <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="sipFundSearch" placeholder="Search fund…" autocomplete="off">
        <input type="hidden" id="sipFundId">
        <div class="autocomplete-dropdown" id="sipFundDropdown" style="display:none"></div>
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
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="weekly">Weekly</option>
            <option value="yearly">Yearly</option>
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
document.addEventListener('DOMContentLoaded', () => {
  loadSipAnalysis();
  loadUpcoming();
  loadSipList();
  loadMonthlyChart(12);

  document.getElementById('btnAddSip').addEventListener('click', openAddSip);
  document.getElementById('btnSipSave').addEventListener('click', saveSip);
  document.getElementById('showInactive').addEventListener('change', loadSipList);
  document.getElementById('chartMonths').addEventListener('change', e => loadMonthlyChart(+e.target.value));
  initFundSearch('sipFundSearch', 'sipFundId', 'sipFundDropdown');
});

async function loadSipAnalysis() {
  try {
    const d = await API.post('/api/router.php', { action: 'sip_analysis' });
    document.getElementById('cardActiveSips').textContent    = d.sips?.length ?? 0;
    document.getElementById('cardMonthlyAmt').textContent    = formatINR(d.total_monthly_sip);
    document.getElementById('cardTotalInvested').textContent = formatINR(d.total_invested);
    const gainEl = document.getElementById('cardOverallGain');
    gainEl.textContent  = formatINR(d.overall_gain) + ' (' + (d.overall_gain_pct > 0 ? '+' : '') + d.overall_gain_pct + '%)';
    gainEl.className    = 'stat-value ' + (d.overall_gain >= 0 ? 'text-success' : 'text-danger');
  } catch(e) { console.error(e); }
}

async function loadUpcoming() {
  try {
    const d = await API.post('/api/router.php', { action: 'sip_upcoming', days: 30 });
    document.getElementById('upcomingCount').textContent = d.upcoming?.length ?? 0;
    const tbody = document.getElementById('upcomingBody');
    if (!d.upcoming?.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary">No upcoming SIPs in next 30 days</td></tr>';
      return;
    }
    tbody.innerHTML = d.upcoming.map(s => `
      <tr>
        <td>${esc(s.scheme_name || '—')}<br><small class="text-secondary">${esc(s.fund_house||'')}</small></td>
        <td class="text-right">${formatINR(s.sip_amount)}</td>
        <td><span class="badge badge-secondary">${s.frequency}</span></td>
        <td>${s.next_date ? formatDate(s.next_date) : '—'}</td>
        <td><span class="badge ${s.days_remaining <= 3 ? 'badge-danger' : 'badge-info'}">${s.days_remaining}d</span></td>
        <td>${esc(s.platform||'—')}</td>
      </tr>`).join('');
  } catch(e) { console.error(e); }
}

async function loadSipList() {
  try {
    const d = await API.post('/api/router.php', { action: 'sip_list' });
    const showInactive = document.getElementById('showInactive').checked;
    const sips = (d.sips || []).filter(s => showInactive || s.is_active == 1);
    const tbody = document.getElementById('sipBody');
    if (!sips.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center text-secondary">No SIPs found. Add your first SIP!</td></tr>';
      return;
    }
    tbody.innerHTML = sips.map(s => `
      <tr class="${s.is_active != 1 ? 'row-inactive' : ''}">
        <td>${esc(s.fund_name||'—')}<br><small class="text-secondary">${esc(s.fund_house||'')}</small></td>
        <td><span class="badge badge-secondary text-xs">${esc(s.fund_category||'—')}</span></td>
        <td class="text-right"><strong>${formatINR(s.sip_amount)}</strong></td>
        <td>${s.frequency}</td>
        <td>${s.sip_day}</td>
        <td>${formatDate(s.start_date)}</td>
        <td>${s.next_date ? formatDate(s.next_date) : '<span class="text-secondary">—</span>'}</td>
        <td class="text-right">${formatINR(s.total_invested)}</td>
        <td><span class="badge ${s.is_active==1 ? 'badge-success' : 'badge-secondary'}">${s.is_active==1?'Active':'Paused'}</span></td>
        <td>
          <button class="btn btn-ghost btn-xs" onclick="editSip(${s.id})">Edit</button>
          <button class="btn btn-ghost btn-xs text-danger" onclick="deleteSip(${s.id},'${esc(s.fund_name)}')">Delete</button>
        </td>
      </tr>`).join('');
  } catch(e) { console.error(e); }
}

let sipChartInst = null;
async function loadMonthlyChart(months) {
  try {
    const d = await API.post('/api/router.php', { action: 'sip_monthly_chart', months });
    const labels = d.chart.map(r => r.ym);
    const values = d.chart.map(r => +r.invested);
    const ctx    = document.getElementById('sipMonthlyChart').getContext('2d');
    if (sipChartInst) sipChartInst.destroy();
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
  document.getElementById('sipFundSearch').value = '';
  document.getElementById('sipFundId').value     = '';
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
}

async function editSip(id) {
  try {
    const d = await API.post('/api/router.php', { action: 'sip_list' });
    const sip = d.sips.find(s => s.id == id);
    if (!sip) return;
    document.getElementById('sipModalTitle').textContent = 'Edit SIP';
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

  const payload = {
    action    : id ? 'sip_edit'  : 'sip_add',
    sip_id    : id,
    fund_id   : fundId,
    sip_amount: amount,
    frequency : document.getElementById('sipFrequency').value,
    sip_day   : document.getElementById('sipDay').value,
    start_date: startDate,
    end_date  : document.getElementById('sipEndDate').value,
    folio_number: document.getElementById('sipFolio').value,
    platform  : document.getElementById('sipPlatform').value,
    notes     : document.getElementById('sipNotes').value,
    is_active : document.getElementById('sipIsActive')?.checked ? 1 : 0,
    csrf_token: window.CSRF_TOKEN,
  };
  try {
    await API.post('/api/router.php', payload);
    showToast('SIP saved!');
    closeSipModal();
    loadSipList(); loadSipAnalysis();
  } catch(e) { showToast(e.message,'error'); }
}

async function deleteSip(id, name) {
  if (!confirm(`Delete SIP for "${name}"?`)) return;
  try {
    await API.post('/api/router.php', { action:'sip_delete', sip_id:id, csrf_token:window.CSRF_TOKEN });
    showToast('SIP deleted.');
    loadSipList(); loadSipAnalysis();
  } catch(e) { showToast(e.message,'error'); }
}

function closeSipModal() {
  document.getElementById('sipModal').style.display = 'none';
}

function formatDate(d) {
  if (!d) return '—';
  const parts = d.split('-');
  if (parts.length === 3 && parts[0].length === 4) return `${parts[2]}-${parts[1]}-${parts[0]}`;
  return d;
}
</script>

<style>
.row-inactive { opacity:.55; }
</style>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>

