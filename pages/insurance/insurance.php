<?php
/**
 * WealthDash — t122: Insurance Portfolio Page
 * File: pages/insurance/insurance.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Insurance Portfolio'; $activePage='insurance'; $activeSection='insurance';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🛡 Insurance Portfolio</h1><p class="page-subtitle">Term, Health, ULIP and all policies in one place.</p></div>
  <div style="display:flex;gap:8px;align-items:center;">
    <select id="ins-type-filter" class="form-control" style="width:150px;" onchange="INS.load()">
      <option value="">All Types</option>
      <option value="term">Term</option>
      <option value="health">Health</option>
      <option value="ulip">ULIP</option>
      <option value="endowment">Endowment</option>
      <option value="vehicle">Vehicle</option>
      <option value="other">Other</option>
    </select>
    <button class="btn btn-primary" onclick="INS.openAdd()">+ Add Policy</button>
  </div>
</div>

<div id="ins-summary-cards" class="dashboard-grid" style="margin-bottom:20px;"></div>

<!-- Upcoming premiums alert -->
<div id="ins-upcoming-wrap" style="margin-bottom:16px;"></div>

<!-- Policies table -->
<div class="card">
  <div class="card-body p-0"><div id="ins-table"></div></div>
</div>

<!-- Add/Edit Modal -->
<div id="ins-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)INS.closeModal()">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><span class="modal-title" id="ins-modal-title">Add Policy</span><button class="modal-close" onclick="INS.closeModal()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="ins-edit-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Policy Name *</label><input type="text" id="ins-f-name" class="form-control" placeholder="LIC Term Plan"></div>
        <div class="form-group"><label class="form-label">Type</label>
          <select id="ins-f-type" class="form-control"><option value="term">Term</option><option value="health">Health</option><option value="ulip">ULIP</option><option value="endowment">Endowment</option><option value="vehicle">Vehicle</option><option value="other">Other</option></select>
        </div>
        <div class="form-group"><label class="form-label">Insurer</label><input type="text" id="ins-f-insurer" class="form-control" placeholder="LIC / HDFC / ICICI"></div>
        <div class="form-group"><label class="form-label">Policy Number</label><input type="text" id="ins-f-polno" class="form-control"></div>
        <div class="form-group"><label class="form-label">Sum Assured (₹) *</label><input type="number" id="ins-f-sa" class="form-control" step="10000" min="0"></div>
        <div class="form-group"><label class="form-label">Premium Amount (₹)</label><input type="number" id="ins-f-prem" class="form-control" step="100" min="0"></div>
        <div class="form-group"><label class="form-label">Premium Frequency</label>
          <select id="ins-f-freq" class="form-control"><option value="monthly">Monthly</option><option value="quarterly">Quarterly</option><option value="half_yearly">Half-Yearly</option><option value="annual">Annual</option><option value="single">Single Premium</option></select>
        </div>
        <div class="form-group"><label class="form-label">Start Date</label><input type="date" id="ins-f-start" class="form-control"></div>
        <div class="form-group"><label class="form-label">Maturity Date</label><input type="date" id="ins-f-mat" class="form-control"></div>
        <div class="form-group"><label class="form-label">Next Premium Date</label><input type="date" id="ins-f-nextprem" class="form-control"></div>
        <div class="form-group"><label class="form-label">Maturity Amount (₹)</label><input type="number" id="ins-f-matamt" class="form-control" step="1000" min="0"></div>
        <div class="form-group"><label class="form-label">Nominee</label><input type="text" id="ins-f-nominee" class="form-control" placeholder="Spouse / Child"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Notes</label><textarea id="ins-f-notes" class="form-control" rows="2"></textarea></div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="INS.closeModal()">Cancel</button><button class="btn btn-primary" onclick="INS.save()">Save Policy</button></div>
  </div>
</div>

<script>
const INS = {
  init() { this.loadSummary(); this.load(); },
  loadSummary() {
    apiPost({action:'insurance_summary'}).then(r=>{
      if(!r.ok)return;
      const d=r.data;
      let cards=`<div class="stat-card"><div class="stat-label">Total Coverage</div><div class="stat-value wd-num-xl">${formatINR(d.grand_cover)}</div></div>
        <div class="stat-card"><div class="stat-label">Annual Premium</div><div class="stat-value wd-num-xl">${formatINR(d.grand_premium)}</div></div>`;
      for(const t of(d.by_type||[])){cards+=`<div class="stat-card"><div class="stat-label">${esc(t.policy_type.toUpperCase())}</div><div class="stat-value wd-num-xl">${formatINR(t.total_cover)}</div><div class="stat-sub">${t.count} polic${t.count>1?'ies':'y'}</div></div>`;}
      document.getElementById('ins-summary-cards').innerHTML=cards;
      if(d.upcoming?.length){
        let ul=`<div class="alert alert-warning" style="font-size:13px;"><strong>⏰ Upcoming Premiums (30 days):</strong> `;
        ul+=d.upcoming.map(u=>`${esc(u.policy_name)} — ${formatINR(u.premium_amount)} on ${esc(u.next_premium_date)}`).join(' | ');
        ul+='</div>';
        document.getElementById('ins-upcoming-wrap').innerHTML=ul;
      }
    });
  },
  load() {
    const type=document.getElementById('ins-type-filter').value;
    apiPost({action:'insurance_list',type}).then(r=>{
      if(!r.ok)return;
      const rows=r.data.policies||[];
      const wrap=document.getElementById('ins-table');
      if(!rows.length){wrap.innerHTML='<div class="empty-state"><div class="empty-icon">🛡</div><div>No policies yet.</div></div>';return;}
      const typeBadge=t=>({term:'🔵 Term',health:'🟢 Health',ulip:'🟣 ULIP',endowment:'🟡 Endowment',vehicle:'🟠 Vehicle',other:'⚪ Other'}[t]||t);
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Policy</th><th>Type</th><th>Insurer</th><th class="text-right">Sum Assured</th><th class="text-right">Premium</th><th>Frequency</th><th>Next Due</th><th>Nominee</th><th></th></tr></thead><tbody>`;
      for(const p of rows){
        const dueCls=p.days_to_premium!==null&&p.days_to_premium<=30?'wd-loss':'';
        html+=`<tr><td style="font-weight:600;">${esc(p.policy_name)}</td><td>${typeBadge(p.policy_type)}</td><td style="font-size:12px;">${esc(p.insurer||'—')}</td>
          <td class="text-right wd-num">${formatINR(p.sum_assured)}</td>
          <td class="text-right wd-num">${formatINR(p.premium_amount)}</td>
          <td style="font-size:12px;text-transform:capitalize;">${esc(p.premium_frequency)}</td>
          <td style="font-size:12px;" class="${dueCls}">${esc(p.next_premium_date||'—')}${p.days_to_premium!==null?` <small>(${p.days_to_premium}d)</small>`:''}</td>
          <td style="font-size:12px;">${esc(p.nominee||'—')}</td>
          <td><button class="btn btn-ghost btn-sm" onclick="INS.edit(${p.id})">✏️</button><button class="btn btn-danger btn-sm" onclick="INS.del(${p.id})">✕</button></td></tr>`;
      }
      html+='</tbody></table></div>';
      wrap.innerHTML=html;
    });
  },
  _clearForm(){['ins-f-name','ins-f-insurer','ins-f-polno','ins-f-sa','ins-f-prem','ins-f-start','ins-f-mat','ins-f-nextprem','ins-f-matamt','ins-f-nominee','ins-f-notes'].forEach(i=>{const el=document.getElementById(i);if(el)el.value='';});document.getElementById('ins-edit-id').value='';},
  openAdd(){this._clearForm();document.getElementById('ins-modal-title').textContent='Add Policy';document.getElementById('ins-modal').style.display='';},
  closeModal(){document.getElementById('ins-modal').style.display='none';},
  edit(id){
    apiPost({action:'insurance_list'}).then(r=>{
      const p=(r.data.policies||[]).find(x=>x.id==id);if(!p)return;
      this._clearForm();document.getElementById('ins-edit-id').value=id;
      document.getElementById('ins-modal-title').textContent='Edit Policy';
      const set=(f,v)=>{const el=document.getElementById(f);if(el)el.value=v||'';};
      set('ins-f-name',p.policy_name);set('ins-f-insurer',p.insurer);set('ins-f-polno',p.policy_number);
      set('ins-f-sa',p.sum_assured);set('ins-f-prem',p.premium_amount);
      document.getElementById('ins-f-type').value=p.policy_type;
      document.getElementById('ins-f-freq').value=p.premium_frequency;
      set('ins-f-start',p.start_date);set('ins-f-mat',p.maturity_date);set('ins-f-nextprem',p.next_premium_date);
      set('ins-f-matamt',p.maturity_amount);set('ins-f-nominee',p.nominee);set('ins-f-notes',p.notes);
      document.getElementById('ins-modal').style.display='';
    });
  },
  save(){
    const id=document.getElementById('ins-edit-id').value;
    const data={action:id?'insurance_update':'insurance_add',
      policy_name:document.getElementById('ins-f-name').value,policy_type:document.getElementById('ins-f-type').value,
      insurer:document.getElementById('ins-f-insurer').value,policy_number:document.getElementById('ins-f-polno').value,
      sum_assured:document.getElementById('ins-f-sa').value,premium_amount:document.getElementById('ins-f-prem').value,
      premium_frequency:document.getElementById('ins-f-freq').value,start_date:document.getElementById('ins-f-start').value,
      maturity_date:document.getElementById('ins-f-mat').value,next_premium_date:document.getElementById('ins-f-nextprem').value,
      maturity_amount:document.getElementById('ins-f-matamt').value,nominee:document.getElementById('ins-f-nominee').value,
      notes:document.getElementById('ins-f-notes').value};
    if(id)data.id=id;
    apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeModal();this.load();this.loadSummary();}});
  },
  del(id){if(!confirm('Delete this policy?'))return;apiPost({action:'insurance_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.load();this.loadSummary();}}); }
};
document.addEventListener('DOMContentLoaded',()=>INS.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
