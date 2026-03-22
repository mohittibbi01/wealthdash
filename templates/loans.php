<?php
/**
 * WealthDash — Loan Tracker (t123)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Loan Tracker';
$activePage  = 'loans';

$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">🏦 Loan Tracker</h1>
    <p class="page-subtitle">Home · Personal · Education · Vehicle · EMI schedule</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" onclick="openAddLoan()">+ Add Loan</button>
  </div>
</div>

<div class="stats-grid" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">Active Loans</div><div class="stat-value" id="loanCount">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Outstanding</div><div class="stat-value text-danger" id="loanOutstanding">—</div></div>
  <div class="stat-card"><div class="stat-label">Monthly EMI Total</div><div class="stat-value" id="loanEmi">—</div></div>
  <div class="stat-card"><div class="stat-label">EMI-to-Income Ratio</div><div class="stat-value" id="loanEmiRatio">—</div></div>
</div>

<!-- EMI-to-income input -->
<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
  <label style="font-size:12px;color:var(--text-muted);">Monthly Income (₹) for ratio:</label>
  <input type="number" id="monthlyIncome" placeholder="e.g. 100000" class="form-input" style="width:150px;" oninput="updateEmiRatio()">
  <select id="loanFilterPortfolio" class="form-select" style="width:170px;" onchange="loadLoans()">
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Lender / Type</th>
          <th class="text-right">Principal</th>
          <th class="text-right">Outstanding</th>
          <th class="text-right">Rate</th>
          <th class="text-right">EMI</th>
          <th>EMI Date</th>
          <th class="text-right">Monthly Interest</th>
          <th>Tax Benefit</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="loanBody">
        <tr><td colspan="9" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Amortization section -->
<div class="card mt-4" id="amortCard" style="display:none;">
  <div class="card-header">
    <h3 class="card-title" id="amortTitle">Amortization Schedule</h3>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('amortCard').style.display='none'">✕</button>
  </div>
  <div class="card-body" id="amortBody" style="overflow-x:auto;max-height:400px;overflow-y:auto;"></div>
</div>

<!-- Add Loan Modal -->
<div class="modal-overlay" id="modalAddLoan" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <h3 class="modal-title">Add Loan</h3>
      <button class="modal-close" onclick="hideModal('modalAddLoan')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select id="loanPPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Loan Type *</label>
          <select id="loanPType" class="form-select">
            <option value="home">Home Loan</option>
            <option value="personal">Personal Loan</option>
            <option value="education">Education Loan</option>
            <option value="vehicle">Vehicle Loan</option>
            <option value="gold">Gold Loan</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Lender *</label>
          <input type="text" id="loanPLender" class="form-input" placeholder="SBI, HDFC, etc.">
        </div>
        <div class="form-group">
          <label class="form-label">Loan Number</label>
          <input type="text" id="loanPNumber" class="form-input" placeholder="Optional">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Principal Amount (₹) *</label>
          <input type="number" id="loanPPrincipal" class="form-input" placeholder="e.g. 5000000">
        </div>
        <div class="form-group">
          <label class="form-label">Outstanding Balance (₹) *</label>
          <input type="number" id="loanPOutstanding" class="form-input" placeholder="Current balance">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Interest Rate (% p.a.) *</label>
          <input type="number" id="loanPRate" class="form-input" placeholder="e.g. 8.5" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">EMI Amount (₹) *</label>
          <input type="number" id="loanPEmi" class="form-input" placeholder="Monthly EMI" oninput="calcLoanEmi()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">EMI Date (day of month)</label>
          <input type="number" id="loanPEmiDate" class="form-input" value="5" min="1" max="31">
        </div>
        <div class="form-group">
          <label class="form-label">Start Date *</label>
          <input type="date" id="loanPStart" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Tenure (months) *</label>
        <input type="number" id="loanPTenure" class="form-input" placeholder="e.g. 240 for 20yr">
      </div>
      <div id="loanCalcPreview" style="padding:8px;background:var(--bg-secondary);border-radius:7px;font-size:12px;color:var(--text-muted);margin-top:8px;"></div>
      <div id="loanError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddLoan')">Cancel</button>
      <button class="btn btn-primary" onclick="saveLoan()">Save Loan</button>
    </div>
  </div>
</div>

<script>
let _loanData = [];
const LOAN_ICONS = {home:'🏠',personal:'💳',education:'🎓',vehicle:'🚗',gold:'🥇',other:'💰'};
const LOAN_LABELS= {home:'Home',personal:'Personal',education:'Education',vehicle:'Vehicle',gold:'Gold',other:'Other'};

async function loadLoans() {
  const pid = document.getElementById('loanFilterPortfolio')?.value || '';
  const res = await fetch(`${APP_URL}/api/router.php?action=loans_list&portfolio_id=${pid}`);
  const d   = await res.json();
  if (!d.success) return;
  _loanData = d.data || [];
  renderLoanTable();
  updateLoanSummary();
}

function updateLoanSummary() {
  const total       = _loanData.length;
  const outstanding = _loanData.reduce((s,l) => s+(parseFloat(l.outstanding)||0), 0);
  const emi         = _loanData.reduce((s,l) => s+(parseFloat(l.emi_amount)||0), 0);
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  document.getElementById('loanCount').textContent      = total;
  document.getElementById('loanOutstanding').textContent= fmtI(outstanding);
  document.getElementById('loanEmi').textContent        = fmtI(emi) + '/mo';
  window._totalEmi = emi;
  updateEmiRatio();
}

function updateEmiRatio() {
  const income = parseFloat(document.getElementById('monthlyIncome')?.value) || 0;
  const emi    = window._totalEmi || 0;
  const el     = document.getElementById('loanEmiRatio');
  if (!income || !emi) { el.textContent = '—'; return; }
  const ratio  = (emi/income*100).toFixed(1);
  el.textContent = ratio + '%';
  el.style.color = ratio > 50 ? '#dc2626' : ratio > 40 ? '#d97706' : '#16a34a';
}

function renderLoanTable() {
  const body = document.getElementById('loanBody');
  if (!_loanData.length) {
    body.innerHTML = '<tr><td colspan="9" class="text-center" style="padding:48px;color:var(--text-muted);">No loans found. Add your first loan.</td></tr>';
    return;
  }
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  body.innerHTML = _loanData.map(l => {
    const taxBenefit = l.loan_type === 'home'
      ? '<span title="80C: principal, 24(b): interest ₹2L" style="font-size:11px;color:#16a34a;cursor:help;">80C+24(b) ✅</span>'
      : l.loan_type === 'education' ? '<span style="font-size:11px;color:#3b82f6;">80E ✅</span>'
      : '<span style="color:var(--text-muted);font-size:11px;">—</span>';
    return `<tr>
      <td>
        <div class="fund-name">${LOAN_ICONS[l.loan_type]||'💰'} ${escHtml(l.lender)}</div>
        <div class="fund-sub">${LOAN_LABELS[l.loan_type]||l.loan_type}${l.loan_number?' · #'+escHtml(l.loan_number):''}</div>
      </td>
      <td class="text-right">${fmtI(l.principal)}</td>
      <td class="text-right fw-600 text-danger">${fmtI(l.outstanding)}</td>
      <td class="text-right">${parseFloat(l.interest_rate).toFixed(2)}%</td>
      <td class="text-right fw-600">${fmtI(l.emi_amount)}/mo</td>
      <td>${l.emi_date}<sup>th</sup></td>
      <td class="text-right text-danger">${fmtI(l.monthly_interest)}</td>
      <td>${taxBenefit}</td>
      <td class="text-center" style="display:flex;gap:4px;justify-content:center;">
        <button class="btn btn-xs btn-ghost" onclick="showAmortization(${JSON.stringify(l).replace(/"/g,'&quot;')})" title="Amortization">📋</button>
        <button class="btn btn-xs btn-ghost" onclick="updateLoanBalance(${l.id},${l.outstanding})" title="Update balance">✎</button>
        <button class="btn btn-xs btn-ghost" onclick="deleteLoan(${l.id})" title="Delete">✕</button>
      </td>
    </tr>`;
  }).join('');
}

function showAmortization(loan) {
  const card  = document.getElementById('amortCard');
  const title = document.getElementById('amortTitle');
  const body  = document.getElementById('amortBody');
  title.textContent = `Amortization — ${loan.lender}`;
  card.style.display = '';

  const P   = parseFloat(loan.outstanding);
  const r   = parseFloat(loan.interest_rate) / 100 / 12;
  const emi = parseFloat(loan.emi_amount);
  const n   = Math.ceil(P > 0 && emi > 0 ? Math.log(emi/(emi - P*r)) / Math.log(1+r) : 0);

  let rows = '', bal = P, totalInt = 0;
  function fmtI(v){return'₹'+Math.abs(v).toLocaleString('en-IN',{maximumFractionDigits:0});}
  for (let m = 1; m <= Math.min(n, 360); m++) {
    const int  = bal * r;
    const prin = emi - int;
    bal -= prin;
    totalInt += int;
    if (m <= 12 || m % 12 === 0 || m === Math.round(n)) {
      rows += `<tr style="${m % 12 === 0 ? 'background:rgba(99,102,241,.05);font-weight:700;' : ''}">
        <td style="padding:4px 8px;">${m}</td>
        <td style="padding:4px 8px;text-align:right;">${fmtI(emi)}</td>
        <td style="padding:4px 8px;text-align:right;">${fmtI(prin)}</td>
        <td style="padding:4px 8px;text-align:right;color:#dc2626;">${fmtI(int)}</td>
        <td style="padding:4px 8px;text-align:right;">${fmtI(Math.max(0,bal))}</td>
      </tr>`;
    }
  }
  body.innerHTML = `
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">
      Total Interest: <strong style="color:#dc2626;">${fmtI(totalInt)}</strong> · Months remaining: <strong>${Math.round(n)}</strong>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      <thead style="background:var(--bg-secondary);">
        <tr>
          <th style="padding:6px 8px;text-align:left;">Month</th>
          <th style="padding:6px 8px;text-align:right;">EMI</th>
          <th style="padding:6px 8px;text-align:right;">Principal</th>
          <th style="padding:6px 8px;text-align:right;">Interest</th>
          <th style="padding:6px 8px;text-align:right;">Balance</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>`;
  card.scrollIntoView({behavior:'smooth'});
}

function calcLoanEmi() {
  const P   = parseFloat(document.getElementById('loanPOutstanding')?.value || document.getElementById('loanPPrincipal')?.value) || 0;
  const r   = (parseFloat(document.getElementById('loanPRate')?.value) || 0) / 100 / 12;
  const n   = parseInt(document.getElementById('loanPTenure')?.value) || 0;
  const el  = document.getElementById('loanCalcPreview');
  if (!P || !r || !n) return;
  const emi = P * r * Math.pow(1+r,n) / (Math.pow(1+r,n)-1);
  const total = emi * n;
  el.innerHTML = `Calculated EMI: <strong>₹${Math.round(emi).toLocaleString('en-IN')}/mo</strong> · Total payable: <strong>₹${Math.round(total).toLocaleString('en-IN')}</strong> · Interest: ₹${Math.round(total-P).toLocaleString('en-IN')}`;
  document.getElementById('loanPEmi').value = Math.round(emi);
}

function openAddLoan() { showModal('modalAddLoan'); }
async function saveLoan() {
  const payload = {action:'loans_add',
    portfolio_id: document.getElementById('loanPPortfolio').value,
    loan_type:    document.getElementById('loanPType').value,
    lender:       document.getElementById('loanPLender').value,
    loan_number:  document.getElementById('loanPNumber').value,
    principal:    document.getElementById('loanPPrincipal').value,
    outstanding:  document.getElementById('loanPOutstanding').value,
    interest_rate:document.getElementById('loanPRate').value,
    emi_amount:   document.getElementById('loanPEmi').value,
    emi_date:     document.getElementById('loanPEmiDate').value,
    start_date:   document.getElementById('loanPStart').value,
    tenure_months:document.getElementById('loanPTenure').value,
  };
  if (!payload.lender || !payload.principal || !payload.emi_amount) {
    document.getElementById('loanError').textContent = 'Lender, principal and EMI are required.';
    document.getElementById('loanError').style.display = ''; return;
  }
  const res = await apiPost(payload);
  if (res.success) { hideModal('modalAddLoan'); showToast('Loan added!','success'); loadLoans(); }
  else { document.getElementById('loanError').textContent = res.message; document.getElementById('loanError').style.display = ''; }
}
async function updateLoanBalance(id, current) {
  const val = prompt('Enter current outstanding balance (₹):', current);
  if (!val) return;
  await apiPost({action:'loans_update_outstanding', id, outstanding:val});
  showToast('Balance updated.','success'); loadLoans();
}
async function deleteLoan(id) {
  if (!confirm('Delete this loan?')) return;
  await apiPost({action:'loans_delete', id});
  showToast('Loan deleted.','success'); loadLoans();
}
function escHtml(t){const d=document.createElement('div');d.appendChild(document.createTextNode(t||''));return d.innerHTML;}
document.addEventListener('DOMContentLoaded', loadLoans);
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
