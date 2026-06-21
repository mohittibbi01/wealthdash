<?php
/**
 * WealthDash — t463: Property Portfolio Page
 * File: pages/property/property.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Property Portfolio'; $activePage='property'; $activeSection='property';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🏠 Property Portfolio</h1><p class="page-subtitle">Real estate holdings, valuations, equity and rental yield.</p></div>
  <button class="btn btn-primary" onclick="PP.openAdd()">+ Add Property</button>
</div>
<div id="pp-summary-cards" class="dashboard-grid" style="margin-bottom:20px;"></div>
<div class="card" style="margin-bottom:20px;"><div class="card-body p-0"><div id="pp-table"></div></div></div>
<div id="pp-valuation-panel" class="card" style="display:none;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span class="card-title" id="pp-val-title">Valuations</span>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('pp-valuation-panel').style.display='none'">✕</button>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;padding:16px;" class="responsive-grid-1col">
    <div>
      <input type="hidden" id="pp-val-prop-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
        <div class="form-group"><label class="form-label">Value (₹)</label><input type="number" id="pp-val-value" class="form-control" step="10000"></div>
        <div class="form-group"><label class="form-label">Date</label><input type="date" id="pp-val-date" class="form-control"></div>
      </div>
      <div class="form-group"><label class="form-label">Source</label>
        <select id="pp-val-source" class="form-control"><option value="manual">Manual estimate</option><option value="govt_circle">Govt circle rate</option><option value="agent">Real estate agent</option><option value="registered">Registered valuation</option></select>
      </div>
      <button class="btn btn-primary btn-sm" onclick="PP.saveValuation()">Add Valuation</button>
    </div>
    <div><div style="font-weight:700;font-size:13px;margin-bottom:8px;">History</div><div id="pp-val-history-list"></div></div>
  </div>
</div>
<!-- Add/Edit Modal -->
<div id="pp-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)PP.closeModal()">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header"><span class="modal-title" id="pp-modal-title">Add Property</span><button class="modal-close" onclick="PP.closeModal()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="pp-edit-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Property Name *</label><input type="text" id="pp-f-name" class="form-control" placeholder="2BHK Koramangala / Plot Jaipur"></div>
        <div class="form-group"><label class="form-label">Type</label>
          <select id="pp-f-type" class="form-control"><option value="residential">🏠 Residential</option><option value="commercial">🏢 Commercial</option><option value="land">🌾 Land/Plot</option><option value="agricultural">🌱 Agricultural</option><option value="other">📦 Other</option></select>
        </div>
        <div class="form-group"><label class="form-label">Area (sq ft)</label><input type="number" id="pp-f-area" class="form-control" step="1"></div>
        <div class="form-group"><label class="form-label">Purchase Price (₹) *</label><input type="number" id="pp-f-price" class="form-control" step="10000"></div>
        <div class="form-group"><label class="form-label">Purchase Date</label><input type="date" id="pp-f-date" class="form-control"></div>
        <div class="form-group"><label class="form-label">Loan Outstanding (₹)</label><input type="number" id="pp-f-loan" class="form-control" step="1000"></div>
        <div class="form-group"><label class="form-label">Monthly Rental (₹)</label><input type="number" id="pp-f-rental" class="form-control" step="500"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Address</label><input type="text" id="pp-f-addr" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Notes</label><textarea id="pp-f-notes" class="form-control" rows="2"></textarea></div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="PP.closeModal()">Cancel</button><button class="btn btn-primary" onclick="PP.save()">Save</button></div>
  </div>
</div>
<script>
const PP={
  init(){this.loadSummary();this.load();document.getElementById('pp-val-date').value=new Date().toISOString().substring(0,10);},
  loadSummary(){apiPost({action:'property_summary'}).then(r=>{if(!r.ok)return;const d=r.data;const gc=d.gain>=0?'wd-gain':'wd-loss';document.getElementById('pp-summary-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Total Value</div><div class="stat-value wd-num-xl">${formatINR(d.total_value)}</div></div><div class="stat-card"><div class="stat-label">Total Equity</div><div class="stat-value wd-num-xl wd-gain">${formatINR(d.total_equity)}</div><div class="stat-sub">Loan: ${formatINR(d.total_loan)}</div></div><div class="stat-card"><div class="stat-label">Appreciation</div><div class="stat-value wd-num-xl ${gc}">${d.gain>=0?'+':''}${formatINR(d.gain)} (${d.gain_pct}%)</div></div><div class="stat-card"><div class="stat-label">Rental</div><div class="stat-value wd-num-xl">${formatINR(d.total_rental)}/mo</div><div class="stat-sub">Yield: ${d.rental_yield}% p.a.</div></div>`;});},
  load(){apiPost({action:'property_list'}).then(r=>{if(!r.ok)return;const rows=r.data.properties||[];const wrap=document.getElementById('pp-table');if(!rows.length){wrap.innerHTML='<div class="empty-state"><div class="empty-icon">🏠</div><div>No properties yet.</div></div>';return;}const te={residential:'🏠',commercial:'🏢',land:'🌾',agricultural:'🌱',other:'📦'};let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Property</th><th>Type</th><th class="text-right">Cost</th><th class="text-right">Current Value</th><th class="text-right">Equity</th><th class="text-right">Gain</th><th>CAGR</th><th></th></tr></thead><tbody>`;for(const p of rows){const gc=p.gain>=0?'wd-gain':'wd-loss';html+=`<tr><td><div style="font-weight:600;">${esc(p.property_name)}</div><div style="font-size:11px;color:var(--text-muted);">${esc(p.address||'—')}</div></td><td>${te[p.property_type]||'📦'}</td><td class="text-right wd-num">${formatINR(p.purchase_price)}</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(p.current_value)}</td><td class="text-right wd-num wd-gain">${formatINR(p.equity)}</td><td class="text-right wd-num ${gc}">${p.gain>=0?'+':''}${formatINR(p.gain)}<br><small>${p.gain_pct}%</small></td><td class="wd-num" style="${p.cagr>=12?'color:var(--gain)':''}">${p.cagr}%</td><td><button class="btn btn-ghost btn-sm" onclick="PP.showValuation(${p.id},'${esc(p.property_name)}')">📈</button><button class="btn btn-ghost btn-sm" onclick="PP.edit(${p.id})">✏️</button><button class="btn btn-danger btn-sm" onclick="PP.del(${p.id})">✕</button></td></tr>`;}html+='</tbody></table></div>';wrap.innerHTML=html;});},
  showValuation(pid,name){document.getElementById('pp-val-prop-id').value=pid;document.getElementById('pp-val-title').textContent='📈 '+name;document.getElementById('pp-val-value').value='';this.loadValHistory(pid);document.getElementById('pp-valuation-panel').style.display='';document.getElementById('pp-valuation-panel').scrollIntoView({behavior:'smooth'});},
  loadValHistory(pid){apiPost({action:'property_valuation_history',property_id:pid}).then(r=>{const vs=r.data?.valuations||[];if(!vs.length){document.getElementById('pp-val-history-list').innerHTML='<div style="color:var(--text-muted);font-size:13px;">No valuations yet.</div>';return;}let html='';for(const v of vs.slice().reverse()){html+=`<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border);font-size:12px;"><span>${esc(v.valuation_date)} <span style="color:var(--text-muted);">(${esc(v.source)})</span></span><strong>${formatINR(v.current_value)}</strong></div>`;}document.getElementById('pp-val-history-list').innerHTML=html;});},
  saveValuation(){const pid=document.getElementById('pp-val-prop-id').value;apiPost({action:'property_valuation_add',property_id:pid,current_value:document.getElementById('pp-val-value').value,valuation_date:document.getElementById('pp-val-date').value,source:document.getElementById('pp-val-source').value}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.loadValHistory(pid);this.load();this.loadSummary();}});},
  _clearForm(){['pp-f-name','pp-f-area','pp-f-price','pp-f-date','pp-f-loan','pp-f-rental','pp-f-addr','pp-f-notes'].forEach(i=>{const el=document.getElementById(i);if(el)el.value='';});document.getElementById('pp-edit-id').value='';},
  openAdd(){this._clearForm();document.getElementById('pp-modal-title').textContent='Add Property';document.getElementById('pp-modal').style.display='';},
  closeModal(){document.getElementById('pp-modal').style.display='none';},
  edit(id){apiPost({action:'property_list'}).then(r=>{const p=(r.data.properties||[]).find(x=>x.id==id);if(!p)return;this._clearForm();document.getElementById('pp-edit-id').value=id;document.getElementById('pp-modal-title').textContent='Edit Property';const set=(f,v)=>{const el=document.getElementById(f);if(el)el.value=v||'';};set('pp-f-name',p.property_name);set('pp-f-area',p.area_sqft);set('pp-f-price',p.purchase_price);set('pp-f-date',p.purchase_date);set('pp-f-loan',p.loan_outstanding);set('pp-f-rental',p.monthly_rental);set('pp-f-addr',p.address);set('pp-f-notes',p.notes);document.getElementById('pp-f-type').value=p.property_type;document.getElementById('pp-modal').style.display='';});},
  save(){const id=document.getElementById('pp-edit-id').value;const data={action:id?'property_update':'property_add',property_name:document.getElementById('pp-f-name').value,property_type:document.getElementById('pp-f-type').value,area_sqft:document.getElementById('pp-f-area').value,purchase_price:document.getElementById('pp-f-price').value,purchase_date:document.getElementById('pp-f-date').value,loan_outstanding:document.getElementById('pp-f-loan').value,monthly_rental:document.getElementById('pp-f-rental').value,address:document.getElementById('pp-f-addr').value,notes:document.getElementById('pp-f-notes').value};if(id)data.id=id;apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeModal();this.load();this.loadSummary();}});},
  del(id){if(!confirm('Delete this property?'))return;apiPost({action:'property_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.load();this.loadSummary();}});}
};
document.addEventListener('DOMContentLoaded',()=>PP.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
