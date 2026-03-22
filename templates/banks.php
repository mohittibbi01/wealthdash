<?php
/**
 * WealthDash — Bank Accounts Tracker (t43)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$pageTitle = 'Bank Accounts'; $activePage = 'banks';
$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">🏦 Bank Accounts</h1><p class="page-subtitle">Savings · Current · NRE/NRO · Balance tracking</p></div>
  <div class="page-header-actions"><button class="btn btn-primary" onclick="openAddBank()">+ Add Account</button></div>
</div>
<div class="stats-grid" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">Accounts</div><div class="stat-value" id="bankCount">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Balance</div><div class="stat-value" id="bankTotalBal">—</div></div>
  <div class="stat-card"><div class="stat-label">Annual Interest (est.)</div><div class="stat-value" id="bankTotalInt">—</div></div>
  <div class="stat-card"><div class="stat-label">Primary Account</div><div class="stat-value" id="bankPrimary" style="font-size:14px;">—</div></div>
</div>
<div style="margin-bottom:12px;">
  <select id="bankFilterPortfolio" class="form-select" style="width:170px;" onchange="loadBanks()">
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead><tr>
        <th>Bank / Account</th><th>Type</th><th>IFSC</th>
        <th class="text-right">Balance</th><th class="text-right">Rate</th>
        <th class="text-right">Annual Interest</th><th>Portfolio</th>
        <th class="text-center">Actions</th>
      </tr></thead>
      <tbody id="bankBody"><tr><td colspan="8" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr></tbody>
    </table>
  </div>
</div>
<div class="modal-overlay" id="modalAddBank" style="display:none;">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><h3 class="modal-title">Add Bank Account</h3><button class="modal-close" onclick="hideModal('modalAddBank')">✕</button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Portfolio *</label>
          <select id="bankPPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">Account Type *</label>
          <select id="bankPType" class="form-select">
            <option value="savings">Savings</option><option value="current">Current</option>
            <option value="salary">Salary</option><option value="nre">NRE</option>
            <option value="nro">NRO</option><option value="other">Other</option>
          </select></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Bank Name *</label><input type="text" id="bankPName" class="form-input" placeholder="SBI, HDFC, etc."></div>
        <div class="form-group"><label class="form-label">Account Number</label><input type="text" id="bankPAccNo" class="form-input" placeholder="Last 4 digits ok"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">IFSC Code</label><input type="text" id="bankPIfsc" class="form-input" placeholder="SBIN0001234"></div>
        <div class="form-group"><label class="form-label">Interest Rate (% p.a.)</label><input type="number" id="bankPRate" class="form-input" value="4.0" step="0.1"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Current Balance (₹) *</label><input type="number" id="bankPBal" class="form-input" placeholder="0"></div>
        <div class="form-group" style="display:flex;align-items:center;padding-top:22px;">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
            <input type="checkbox" id="bankPPrimary"> Mark as Primary Account
          </label></div>
      </div>
      <div id="bankError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddBank')">Cancel</button>
      <button class="btn btn-primary" onclick="saveBank()">Save Account</button>
    </div>
  </div>
</div>
<script>
let _bankData = [];
const BTYPE_LABELS = {savings:'Savings',current:'Current',salary:'Salary',nre:'NRE',nro:'NRO',other:'Other'};
async function loadBanks() {
  const pid = document.getElementById('bankFilterPortfolio')?.value||'';
  const res = await fetch(`${APP_URL}/api/router.php?action=banks_list&portfolio_id=${pid}`);
  const d = await res.json(); if(!d.success)return;
  _bankData = d.data||[]; renderBankTable(); updateBankSummary();
}
function updateBankSummary() {
  const total=_bankData.length, bal=_bankData.reduce((s,b)=>s+(parseFloat(b.balance)||0),0);
  const int=_bankData.reduce((s,b)=>s+(parseFloat(b.annual_interest)||0),0);
  const primary=_bankData.find(b=>parseInt(b.is_primary));
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  document.getElementById('bankCount').textContent=total;
  document.getElementById('bankTotalBal').textContent=fmtI(bal);
  document.getElementById('bankTotalInt').textContent=fmtI(int)+'/yr';
  document.getElementById('bankPrimary').textContent=primary?primary.bank_name:'—';
}
function renderBankTable() {
  const body=document.getElementById('bankBody');
  if(!_bankData.length){body.innerHTML='<tr><td colspan="8" class="text-center" style="padding:40px;color:var(--text-muted);">No bank accounts. Add your first account.</td></tr>';return;}
  function fmtI(v){v=Math.abs(v);if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  function escH(t){const d=document.createElement('div');d.appendChild(document.createTextNode(t||''));return d.innerHTML;}
  body.innerHTML=_bankData.map(b=>`<tr>
    <td><div class="fund-name">${escH(b.bank_name)}${b.is_primary?'<span style="margin-left:6px;font-size:10px;background:#fef3c7;color:#b45309;padding:1px 5px;border-radius:3px;">PRIMARY</span>':''}</div>
    ${b.account_number?`<div class="fund-sub">••••${escH(b.account_number.toString().slice(-4))}</div>`:''}</td>
    <td><span class="badge badge-outline">${BTYPE_LABELS[b.account_type]||b.account_type}</span></td>
    <td style="font-size:12px;font-family:monospace;">${escH(b.ifsc_code||'—')}</td>
    <td class="text-right fw-600">${fmtI(b.balance)}</td>
    <td class="text-right">${parseFloat(b.interest_rate).toFixed(2)}%</td>
    <td class="text-right text-success">${fmtI(b.annual_interest)}/yr</td>
    <td>${escH(b.portfolio_name||'—')}</td>
    <td class="text-center" style="display:flex;gap:4px;justify-content:center;">
      <button class="btn btn-xs btn-ghost" onclick="updateBankBal(${b.id},${b.balance})" title="Update balance">₹</button>
      <button class="btn btn-xs btn-ghost" onclick="deleteBank(${b.id})" title="Delete">✕</button>
    </td>
  </tr>`).join('');
}
function openAddBank(){showModal('modalAddBank');}
async function saveBank(){
  const payload={action:'banks_add',portfolio_id:document.getElementById('bankPPortfolio').value,bank_name:document.getElementById('bankPName').value,account_type:document.getElementById('bankPType').value,account_number:document.getElementById('bankPAccNo').value,ifsc_code:document.getElementById('bankPIfsc').value,balance:document.getElementById('bankPBal').value,interest_rate:document.getElementById('bankPRate').value,is_primary:document.getElementById('bankPPrimary').checked?1:0};
  if(!payload.bank_name){document.getElementById('bankError').textContent='Bank name required.';document.getElementById('bankError').style.display='';return;}
  const res=await apiPost(payload);
  if(res.success){hideModal('modalAddBank');showToast('Account added!','success');loadBanks();}
  else{document.getElementById('bankError').textContent=res.message;document.getElementById('bankError').style.display='';}
}
async function updateBankBal(id,current){
  const val=prompt('Enter current balance (₹):',current);
  if(!val)return;
  await apiPost({action:'banks_update_balance',id,balance:val});
  showToast('Balance updated.','success');loadBanks();
}
async function deleteBank(id){if(!confirm('Delete this account?'))return;await apiPost({action:'banks_delete',id});showToast('Deleted.','success');loadBanks();}
document.addEventListener('DOMContentLoaded',loadBanks);
</script>
<?php $pageContent=ob_get_clean(); require_once APP_ROOT.'/templates/layout.php'; ?>
