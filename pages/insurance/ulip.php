<?php
/**
 * WealthDash — t323: ULIP Tracker Page
 * File: pages/insurance/ulip.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='ULIP Tracker'; $activePage='insurance'; $activeSection='ulip';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🏛️ ULIP Tracker</h1><p class="page-subtitle">Unit Linked Insurance Plans — track fund value, premiums, and returns.</p></div>
  <button class="btn btn-primary" onclick="UP.openAdd()">+ Add ULIP</button>
</div>

<div id="up-summary-cards" class="dashboard-grid" style="margin-bottom:20px;"></div>

<div class="card">
  <div class="card-body p-0"><div id="up-table"></div></div>
</div>

<!-- Add/Edit Modal -->
<div id="up-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)UP.closeModal()">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header"><span class="modal-title" id="up-modal-title">Add ULIP</span><button class="modal-close" onclick="UP.closeModal()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="up-edit-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Policy Name *</label><input type="text" id="up-f-name" class="form-control" placeholder="HDFC Life Click2Invest"></div>
        <div class="form-group"><label class="form-label">Insurer</label><input type="text" id="up-f-ins" class="form-control" placeholder="HDFC Life"></div>
        <div class="form-group"><label class="form-label">Policy Number</label><input type="text" id="up-f-polno" class="form-control"></div>
        <div class="form-group"><label class="form-label">Annual Premium (₹)</label><input type="number" id="up-f-prem" class="form-control" step="1000" min="0"></div>
        <div class="form-group"><label class="form-label">Sum Assured (₹)</label><input type="number" id="up-f-sa" class="form-control" step="10000" min="0"></div>
        <div class="form-group"><label class="form-label">Current Fund Value (₹)</label><input type="number" id="up-f-cv" class="form-control" step="1000" min="0"></div>
        <div class="form-group"><label class="form-label">Total Premium Paid (₹)</label><input type="number" id="up-f-tpp" class="form-control" step="1000" min="0"></div>
        <div class="form-group"><label class="form-label">Start Date</label><input type="date" id="up-f-start" class="form-control"></div>
        <div class="form-group"><label class="form-label">Maturity Date</label><input type="date" id="up-f-mat" class="form-control"></div>
        <div class="form-group"><label class="form-label">Lock-in Years</label><input type="number" id="up-f-lock" class="form-control" value="5" min="1" max="10"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Notes</label><textarea id="up-f-notes" class="form-control" rows="2"></textarea></div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="UP.closeModal()">Cancel</button><button class="btn btn-primary" onclick="UP.save()">Save ULIP</button></div>
  </div>
</div>

<script>
const UP={
  init(){this.loadSummary();this.load();},
  loadSummary(){
    apiPost({action:'ulip_summary'}).then(r=>{
      if(!r.ok)return;const d=r.data;const gc=d.gain>=0?'wd-gain':'wd-loss';
      document.getElementById('up-summary-cards').innerHTML=`
        <div class="stat-card"><div class="stat-label">Total Fund Value</div><div class="stat-value wd-num-xl">${formatINR(d.total_fund_value)}</div><div class="stat-sub">${d.count} active ULIP${d.count!==1?'s':''}</div></div>
        <div class="stat-card"><div class="stat-label">Total Invested</div><div class="stat-value wd-num-xl">${formatINR(d.total_invested)}</div></div>
        <div class="stat-card"><div class="stat-label">Total Gain</div><div class="stat-value wd-num-xl ${gc}">${d.gain>=0?'+':''}${formatINR(d.gain)}</div></div>
        <div class="stat-card"><div class="stat-label">Total Life Cover</div><div class="stat-value wd-num-xl">${formatINR(d.total_cover)}</div></div>`;
    });
  },
  load(){
    apiPost({action:'ulip_list'}).then(r=>{
      if(!r.ok)return;const rows=r.data.ulips||[];
      const wrap=document.getElementById('up-table');
      if(!rows.length){wrap.innerHTML='<div class="empty-state"><div class="empty-icon">🏛️</div><div>No ULIPs added yet.</div></div>';return;}
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Policy</th><th>Insurer</th><th class="text-right">Premium/yr</th><th class="text-right">Fund Value</th><th class="text-right">Invested</th><th class="text-right">Gain</th><th>Maturity</th><th></th></tr></thead><tbody>`;
      for(const u of rows){
        const gc=u.gain>=0?'wd-gain':'wd-loss';
        html+=`<tr><td style="font-weight:600;">${esc(u.policy_name)}</td><td style="font-size:12px;">${esc(u.insurer||'—')}</td><td class="text-right wd-num">${formatINR(u.premium_amount)}</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(u.current_fund_value)}</td><td class="text-right wd-num">${formatINR(u.total_premium_paid)}</td><td class="text-right wd-num ${gc}">${u.gain>=0?'+':''}${formatINR(u.gain)} (${u.gain_pct}%)</td><td style="font-size:12px;">${esc(u.maturity_date||'—')}</td><td><button class="btn btn-ghost btn-sm" onclick="UP.edit(${u.id})">✏️</button><button class="btn btn-danger btn-sm" onclick="UP.del(${u.id})">✕</button></td></tr>`;
      }
      html+='</tbody></table></div>';wrap.innerHTML=html;
    });
  },
  _clearForm(){['up-f-name','up-f-ins','up-f-polno','up-f-prem','up-f-sa','up-f-cv','up-f-tpp','up-f-start','up-f-mat','up-f-notes'].forEach(i=>{const el=document.getElementById(i);if(el)el.value='';});document.getElementById('up-edit-id').value='';document.getElementById('up-f-lock').value='5';},
  openAdd(){this._clearForm();document.getElementById('up-modal-title').textContent='Add ULIP';document.getElementById('up-modal').style.display='';},
  closeModal(){document.getElementById('up-modal').style.display='none';},
  edit(id){apiPost({action:'ulip_list'}).then(r=>{const u=(r.data.ulips||[]).find(x=>x.id==id);if(!u)return;this._clearForm();document.getElementById('up-edit-id').value=id;document.getElementById('up-modal-title').textContent='Edit ULIP';const s=(f,v)=>{const el=document.getElementById(f);if(el)el.value=v||'';};s('up-f-name',u.policy_name);s('up-f-ins',u.insurer);s('up-f-polno',u.policy_number);s('up-f-prem',u.premium_amount);s('up-f-sa',u.sum_assured);s('up-f-cv',u.current_fund_value);s('up-f-tpp',u.total_premium_paid);s('up-f-start',u.start_date);s('up-f-mat',u.maturity_date);s('up-f-notes',u.notes);document.getElementById('up-f-lock').value=u.lock_in_years||5;document.getElementById('up-modal').style.display='';});},
  save(){const id=document.getElementById('up-edit-id').value;const data={action:id?'ulip_update':'ulip_add',policy_name:document.getElementById('up-f-name').value,insurer:document.getElementById('up-f-ins').value,policy_number:document.getElementById('up-f-polno').value,premium_amount:document.getElementById('up-f-prem').value,sum_assured:document.getElementById('up-f-sa').value,current_fund_value:document.getElementById('up-f-cv').value,total_premium_paid:document.getElementById('up-f-tpp').value,start_date:document.getElementById('up-f-start').value,maturity_date:document.getElementById('up-f-mat').value,lock_in_years:document.getElementById('up-f-lock').value,notes:document.getElementById('up-f-notes').value};if(id)data.id=id;apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeModal();this.load();this.loadSummary();}});},
  del(id){if(!confirm('Delete this ULIP?'))return;apiPost({action:'ulip_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.load();this.loadSummary();}});}
};
document.addEventListener('DOMContentLoaded',()=>UP.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
