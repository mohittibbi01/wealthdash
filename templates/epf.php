<?php
/**
 * WealthDash — EPF Tracker (t46)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$pageTitle = 'EPF Tracker'; $activePage = 'epf';
$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">🏢 EPF Tracker</h1><p class="page-subtitle">Employee Provident Fund · EPS · Interest @8.15%</p></div>
  <div class="page-header-actions"><button class="btn btn-primary" onclick="openAddEpf()">+ Add EPF Account</button></div>
</div>
<div class="stats-grid" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">Accounts</div><div class="stat-value" id="epfCount">—</div></div>
  <div class="stat-card"><div class="stat-label">EPF Balance</div><div class="stat-value text-success" id="epfBalance">—</div></div>
  <div class="stat-card"><div class="stat-label">EPS Balance</div><div class="stat-value" id="epfEps">—</div></div>
  <div class="stat-card"><div class="stat-label">Annual Contribution</div><div class="stat-value" id="epfContrib">—</div></div>
</div>
<div style="margin-bottom:12px;">
  <select id="epfFilterPortfolio" class="form-select" style="width:170px;" onchange="loadEpf()">
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
        <th>Employer / UAN</th><th class="text-right">Basic Salary</th>
        <th class="text-right">Employee (12%)</th><th class="text-right">Employer (3.67%)</th>
        <th class="text-right">EPS (8.33%)</th><th class="text-right">EPF Balance</th>
        <th class="text-right">EPS Balance</th><th>Service</th>
        <th class="text-center">Actions</th>
      </tr></thead>
      <tbody id="epfBody"><tr><td colspan="9" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr></tbody>
    </table>
  </div>
</div>
<!-- Info card -->
<div class="card mt-4" style="background:rgba(29,78,216,.04);border:1px solid rgba(29,78,216,.15);">
  <div class="card-body" style="padding:14px;">
    <div style="font-size:12px;color:var(--text-muted);line-height:1.8;">
      <strong>EPF Interest Rate FY 2023-24: 8.15% p.a.</strong> (compounded annually) · 
      Employee contribution: 12% of Basic+DA · Employer: 3.67% to EPF + 8.33% to EPS · 
      80C: employee contribution eligible (up to ₹1.5L combined) · 
      Withdrawal: fully tax-free after 5 years continuous service
    </div>
  </div>
</div>
<div class="modal-overlay" id="modalAddEpf" style="display:none;">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header"><h3 class="modal-title">Add EPF Account</h3><button class="modal-close" onclick="hideModal('modalAddEpf')">✕</button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Portfolio *</label>
          <select id="epfPPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">UAN (Universal Account No.)</label>
          <input type="text" id="epfPUan" class="form-input" placeholder="12-digit UAN"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Employer Name *</label>
          <input type="text" id="epfPEmployer" class="form-input" placeholder="Company name"></div>
        <div class="form-group"><label class="form-label">Basic Salary (₹/month) *</label>
          <input type="number" id="epfPBasic" class="form-input" placeholder="e.g. 50000" oninput="calcEpfContribs()"></div>
      </div>
      <div id="epfContribPreview" style="padding:8px;background:var(--bg-secondary);border-radius:7px;font-size:12px;color:var(--text-muted);margin-bottom:10px;"></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Current EPF Balance (₹)</label>
          <input type="number" id="epfPBal" class="form-input" placeholder="From passbook"></div>
        <div class="form-group"><label class="form-label">EPS Balance (₹)</label>
          <input type="number" id="epfPEps" class="form-input" placeholder="EPS corpus"></div>
      </div>
      <div class="form-group"><label class="form-label">Joining Date</label>
        <input type="date" id="epfPJoining" class="form-input"></div>
      <div id="epfError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddEpf')">Cancel</button>
      <button class="btn btn-primary" onclick="saveEpf()">Save EPF Account</button>
    </div>
  </div>
</div>
<script>
let _epfData=[];
async function loadEpf(){
  const pid=document.getElementById('epfFilterPortfolio')?.value||'';
  const res=await fetch(`${APP_URL}/api/router.php?action=epf_list&portfolio_id=${pid}`);
  const d=await res.json();if(!d.success)return;
  _epfData=d.data||[];renderEpfTable();updateEpfSummary();
}
function updateEpfSummary(){
  const total=_epfData.length;
  const bal=_epfData.reduce((s,e)=>s+(parseFloat(e.current_balance)||0),0);
  const eps=_epfData.reduce((s,e)=>s+(parseFloat(e.eps_balance)||0),0);
  const contrib=_epfData.reduce((s,e)=>s+(parseFloat(e.annual_contribution)||0),0);
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  document.getElementById('epfCount').textContent=total;
  document.getElementById('epfBalance').textContent=fmtI(bal);
  document.getElementById('epfEps').textContent=fmtI(eps);
  document.getElementById('epfContrib').textContent=fmtI(contrib)+'/yr';
}
function renderEpfTable(){
  const body=document.getElementById('epfBody');
  if(!_epfData.length){body.innerHTML='<tr><td colspan="9" class="text-center" style="padding:48px;color:var(--text-muted);">No EPF accounts. Add your EPF details.</td></tr>';return;}
  function fmtI(v){v=Math.abs(v);if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  function escH(t){const d=document.createElement('div');d.appendChild(document.createTextNode(t||''));return d.innerHTML;}
  body.innerHTML=_epfData.map(e=>`<tr>
    <td><div class="fund-name">${escH(e.employer_name)}</div>${e.uan?`<div class="fund-sub">UAN: ${escH(e.uan)}</div>`:''}</td>
    <td class="text-right">${fmtI(e.basic_salary)}/mo</td>
    <td class="text-right">${fmtI(e.employee_contribution)}/mo</td>
    <td class="text-right">${fmtI(e.employer_contribution)}/mo</td>
    <td class="text-right">${fmtI(e.eps_contribution)}/mo</td>
    <td class="text-right fw-600 text-success">${fmtI(e.current_balance)}</td>
    <td class="text-right">${fmtI(e.eps_balance)}</td>
    <td>${e.years_of_service?e.years_of_service+' yrs':'—'}</td>
    <td class="text-center" style="display:flex;gap:4px;justify-content:center;">
      <button class="btn btn-xs btn-ghost" onclick="updateEpfBal(${e.id},${e.current_balance},${e.eps_balance})" title="Update balance">₹</button>
      <button class="btn btn-xs btn-ghost" onclick="deleteEpf(${e.id})" title="Delete">✕</button>
    </td>
  </tr>`).join('');
}
function calcEpfContribs(){
  const basic=parseFloat(document.getElementById('epfPBasic')?.value)||0;
  const el=document.getElementById('epfContribPreview');
  if(!basic||!el){if(el)el.innerHTML='';return;}
  const empEe=Math.round(basic*0.12),empEr=Math.round(basic*0.0367),eps=Math.round(basic*0.0833);
  el.innerHTML=`Employee: <strong>₹${empEe.toLocaleString('en-IN')}/mo</strong> · Employer EPF: <strong>₹${empEr.toLocaleString('en-IN')}/mo</strong> · EPS: <strong>₹${eps.toLocaleString('en-IN')}/mo</strong>`;
}
function openAddEpf(){showModal('modalAddEpf');}
async function saveEpf(){
  const payload={action:'epf_add',portfolio_id:document.getElementById('epfPPortfolio').value,uan:document.getElementById('epfPUan').value,employer_name:document.getElementById('epfPEmployer').value,basic_salary:document.getElementById('epfPBasic').value,current_balance:document.getElementById('epfPBal').value||0,eps_balance:document.getElementById('epfPEps').value||0,joining_date:document.getElementById('epfPJoining').value};
  if(!payload.employer_name){document.getElementById('epfError').textContent='Employer name required.';document.getElementById('epfError').style.display='';return;}
  const res=await apiPost(payload);
  if(res.success){hideModal('modalAddEpf');showToast('EPF account added!','success');loadEpf();}
  else{document.getElementById('epfError').textContent=res.message;document.getElementById('epfError').style.display='';}
}
async function updateEpfBal(id,curBal,curEps){
  const bal=prompt('EPF Balance (₹):',curBal);if(!bal)return;
  const eps=prompt('EPS Balance (₹):',curEps)||curEps;
  await apiPost({action:'epf_update_balance',id,current_balance:bal,eps_balance:eps});
  showToast('Balance updated.','success');loadEpf();
}
async function deleteEpf(id){if(!confirm('Delete this EPF account?'))return;await apiPost({action:'epf_delete',id});showToast('Deleted.','success');loadEpf();}
document.addEventListener('DOMContentLoaded',loadEpf);
</script>
<?php $pageContent=ob_get_clean(); require_once APP_ROOT.'/templates/layout.php'; ?>
