<?php
/**
 * WealthDash — Insurance Portfolio (t122)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Insurance Portfolio';
$activePage  = 'insurance';

$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();
$portfolioId = (int)($portfolios[0]['id'] ?? 0);

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">🛡️ Insurance Portfolio</h1>
    <p class="page-subtitle">Term · Health · ULIP · Premium alerts · Coverage check</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openAddInsurance()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Policy
    </button>
  </div>
</div>

<!-- Summary tiles -->
<div class="stats-grid" style="margin-bottom:20px;" id="insSummary">
  <div class="stat-card"><div class="stat-label">Active Policies</div><div class="stat-value" id="insTotalPolicies">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Sum Assured</div><div class="stat-value" id="insTotalSum">—</div></div>
  <div class="stat-card"><div class="stat-label">Annual Premium</div><div class="stat-value" id="insTotalPremium">—</div></div>
  <div class="stat-card"><div class="stat-label">Due This Month</div><div class="stat-value text-warning" id="insDueMonth">—</div></div>
</div>

<!-- Filter bar -->
<div style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
  <select id="insFilterPortfolio" class="form-select" style="width:170px;" onchange="loadInsurance()">
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select id="insFilterType" class="form-select" style="width:150px;" onchange="renderInsTable()">
    <option value="">All Types</option>
    <option value="term">Term Life</option>
    <option value="health">Health</option>
    <option value="ulip">ULIP</option>
    <option value="endowment">Endowment</option>
    <option value="vehicle">Vehicle</option>
    <option value="other">Other</option>
  </select>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Policy / Insurer</th>
          <th>Type</th>
          <th class="text-right">Sum Assured</th>
          <th class="text-right">Annual Premium</th>
          <th>Start Date</th>
          <th>Next Premium</th>
          <th>Nominee</th>
          <th class="text-center">Status</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="insBody">
        <tr><td colspan="9" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Policy Modal -->
<div class="modal-overlay" id="modalAddIns" style="display:none;">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <h3 class="modal-title">Add Insurance Policy</h3>
      <button class="modal-close" onclick="hideModal('modalAddIns')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select id="insPPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Policy Type *</label>
          <select id="insPType" class="form-select">
            <option value="term">Term Life</option>
            <option value="health">Health</option>
            <option value="ulip">ULIP</option>
            <option value="endowment">Endowment</option>
            <option value="vehicle">Vehicle</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Insurer *</label>
          <input type="text" id="insPInsurer" class="form-input" placeholder="LIC, HDFC Life, etc.">
        </div>
        <div class="form-group">
          <label class="form-label">Policy Number</label>
          <input type="text" id="insPNumber" class="form-input" placeholder="Optional">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Sum Assured (₹) *</label>
          <input type="number" id="insPSumAssured" class="form-input" placeholder="e.g. 10000000">
        </div>
        <div class="form-group">
          <label class="form-label">Annual Premium (₹) *</label>
          <input type="number" id="insPPremium" class="form-input" placeholder="e.g. 25000">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Start Date *</label>
          <input type="date" id="insPStart" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Next Premium Date</label>
          <input type="date" id="insPNextPrem" class="form-input">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Maturity Date</label>
          <input type="date" id="insPMaturity" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Nominee Name</label>
          <input type="text" id="insPNominee" class="form-input" placeholder="Nominee">
        </div>
      </div>
      <div id="insError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddIns')">Cancel</button>
      <button class="btn btn-primary" onclick="saveInsurance()">Save Policy</button>
    </div>
  </div>
</div>

<script>
let _insData = [];
const INS_TYPE_ICONS = {term:'🛡️',health:'🏥',ulip:'📊',endowment:'📋',vehicle:'🚗',other:'📄'};
const INS_TYPE_LABELS= {term:'Term Life',health:'Health',ulip:'ULIP',endowment:'Endowment',vehicle:'Vehicle',other:'Other'};

async function loadInsurance() {
  const pid = document.getElementById('insFilterPortfolio')?.value || '';
  const res = await fetch(`${APP_URL}/api/router.php?action=insurance_list&portfolio_id=${pid}`);
  const d   = await res.json();
  if (!d.success) return;
  _insData = d.data || [];
  renderInsTable();
  updateInsSummary();
  checkInsPremiumAlerts();
}

function updateInsSummary() {
  const total     = _insData.length;
  const totalSum  = _insData.reduce((s,p) => s+(parseFloat(p.sum_assured)||0), 0);
  const totalPrem = _insData.reduce((s,p) => s+(parseFloat(p.annual_premium)||0), 0);
  const dueMonth  = _insData.filter(p => p.days_to_premium !== null && parseInt(p.days_to_premium) <= 30 && parseInt(p.days_to_premium) >= 0).length;

  function fmtI(v) { v=Math.abs(v); if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr'; if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L'; return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0}); }
  document.getElementById('insTotalPolicies').textContent = total;
  document.getElementById('insTotalSum').textContent      = fmtI(totalSum);
  document.getElementById('insTotalPremium').textContent  = fmtI(totalPrem) + '/yr';
  document.getElementById('insDueMonth').textContent      = dueMonth > 0 ? dueMonth + ' policies' : '—';
}

function checkInsPremiumAlerts() {
  _insData.forEach(p => {
    const days = parseInt(p.days_to_premium);
    if (!isNaN(days) && days >= 0 && days <= 7) {
      showToast(`⚠️ Premium due in ${days} days: ${p.insurer} — ₹${Number(p.annual_premium).toLocaleString('en-IN')}`, 'warning');
    }
  });
}

function renderInsTable() {
  const body   = document.getElementById('insBody');
  const filter = document.getElementById('insFilterType')?.value || '';
  const data   = filter ? _insData.filter(p => p.policy_type === filter) : _insData;

  if (!data.length) {
    body.innerHTML = '<tr><td colspan="9" class="text-center" style="padding:48px;color:var(--text-muted);">No insurance policies found. Add your first policy.</td></tr>';
    return;
  }

  function fmtD(d) { if(!d)return'—'; const [y,m,day]=d.split('-'); return `${day}-${m}-${y}`; }
  function fmtI(v) { v=Math.abs(v); if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr'; if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L'; return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0}); }

  body.innerHTML = data.map(p => {
    const days    = parseInt(p.days_to_premium);
    const premBadge = isNaN(days) || !p.next_premium_date ? '<span style="color:var(--text-muted)">—</span>'
      : days < 0  ? '<span class="badge badge-neutral">Paid</span>'
      : days <= 7 ? `<span class="badge badge-danger">${days}d!</span>`
      : days <= 30 ? `<span class="badge badge-warning">${days}d left</span>`
      : `<span style="font-size:12px;">${fmtD(p.next_premium_date)}</span>`;

    return `<tr>
      <td>
        <div class="fund-name">${INS_TYPE_ICONS[p.policy_type]||'📄'} ${escHtml(p.insurer)}</div>
        ${p.policy_number ? `<div class="fund-sub">#${escHtml(p.policy_number)}</div>` : ''}
      </td>
      <td><span class="badge badge-outline">${INS_TYPE_LABELS[p.policy_type]||p.policy_type}</span></td>
      <td class="text-right fw-600">${fmtI(p.sum_assured)}</td>
      <td class="text-right">${fmtI(p.annual_premium)}<br><small style="color:var(--text-muted);">per year</small></td>
      <td>${fmtD(p.start_date)}</td>
      <td>${premBadge}</td>
      <td>${escHtml(p.nominee_name||'—')}</td>
      <td class="text-center"><span class="badge badge-success">Active</span></td>
      <td class="text-center">
        <button class="btn btn-xs btn-ghost" onclick="deleteInsurance(${p.id})" title="Delete">✕</button>
      </td>
    </tr>`;
  }).join('');
}

function openAddInsurance() { showModal('modalAddIns'); }

async function saveInsurance() {
  const payload = {
    action:'insurance_add',
    portfolio_id: document.getElementById('insPPortfolio').value,
    policy_type:  document.getElementById('insPType').value,
    insurer:      document.getElementById('insPInsurer').value,
    policy_number:document.getElementById('insPNumber').value,
    sum_assured:  document.getElementById('insPSumAssured').value,
    annual_premium:document.getElementById('insPPremium').value,
    start_date:   document.getElementById('insPStart').value,
    next_premium_date: document.getElementById('insPNextPrem').value,
    maturity_date:document.getElementById('insPMaturity').value,
    nominee_name: document.getElementById('insPNominee').value,
  };
  if (!payload.insurer || !payload.sum_assured) {
    document.getElementById('insError').textContent = 'Insurer and sum assured are required.';
    document.getElementById('insError').style.display = '';
    return;
  }
  const res = await apiPost(payload);
  if (res.success) { hideModal('modalAddIns'); showToast('Policy added!','success'); loadInsurance(); }
  else { document.getElementById('insError').textContent = res.message; document.getElementById('insError').style.display = ''; }
}

async function deleteInsurance(id) {
  if (!confirm('Delete this policy?')) return;
  await apiPost({action:'insurance_delete', id});
  showToast('Policy deleted.','success');
  loadInsurance();
}

function escHtml(t) { const d=document.createElement('div'); d.appendChild(document.createTextNode(t||'')); return d.innerHTML; }
document.addEventListener('DOMContentLoaded', loadInsurance);
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
