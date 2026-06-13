<?php
/**
 * WealthDash — t123: Loan Tracker Page
 * File: pages/loans/loans.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Loan Tracker'; $activePage='loans'; $activeSection='loans';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🏦 Loan Tracker</h1><p class="page-subtitle">Home, personal, education loans and EMI schedule.</p></div>
  <button class="btn btn-primary" onclick="LT.openAdd()">+ Add Loan</button>
</div>

<div id="lt-summary-cards" class="dashboard-grid" style="margin-bottom:20px;"></div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-body p-0"><div id="lt-table"></div></div>
</div>

<!-- Amortization panel -->
<div id="lt-amort-panel" class="card" style="display:none;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span class="card-title" id="lt-amort-title">Amortization Schedule</span>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('lt-amort-panel').style.display='none'">✕</button>
  </div>
  <div class="card-body p-0"><div id="lt-amort-table"></div></div>
</div>

<!-- Add/Edit Modal -->
<div id="lt-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)LT.closeModal()">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header"><span class="modal-title" id="lt-modal-title">Add Loan</span><button class="modal-close" onclick="LT.closeModal()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="lt-edit-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Loan Name *</label><input type="text" id="lt-f-name" class="form-control" placeholder="Home Loan - SBI"></div>
        <div class="form-group"><label class="form-label">Type</label>
          <select id="lt-f-type" class="form-control"><option value="home">Home Loan</option><option value="personal">Personal</option><option value="education">Education</option><option value="vehicle">Vehicle</option><option value="gold">Gold Loan</option><option value="other">Other</option></select>
        </div>
        <div class="form-group"><label class="form-label">Lender</label><input type="text" id="lt-f-lender" class="form-control" placeholder="SBI / HDFC"></div>
        <div class="form-group"><label class="form-label">Loan Amount (₹)</label><input type="number" id="lt-f-amt" class="form-control" step="10000" min="0"></div>
        <div class="form-group"><label class="form-label">Outstanding (₹)</label><input type="number" id="lt-f-os" class="form-control" step="1000" min="0" placeholder="Current balance"></div>
        <div class="form-group"><label class="form-label">Interest Rate (%)</label><input type="number" id="lt-f-rate" class="form-control" step="0.1" min="0" placeholder="8.5"></div>
        <div class="form-group"><label class="form-label">Tenure (months)</label><input type="number" id="lt-f-tenure" class="form-control" min="1" placeholder="240"></div>
        <div class="form-group"><label class="form-label">EMI Amount (₹)</label><input type="number" id="lt-f-emi" class="form-control" step="100" placeholder="Auto-calc if blank"></div>
        <div class="form-group"><label class="form-label">EMI Date (day of month)</label><input type="number" id="lt-f-emidate" class="form-control" min="1" max="31" value="5"></div>
        <div class="form-group"><label class="form-label">Start Date</label><input type="date" id="lt-f-start" class="form-control"></div>
        <div class="form-group"><label class="form-label">End Date</label><input type="date" id="lt-f-end" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Notes</label><textarea id="lt-f-notes" class="form-control" rows="2"></textarea></div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="LT.closeModal()">Cancel</button><button class="btn btn-primary" onclick="LT.save()">Save Loan</button></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const LT = {
  init() { this.loadSummary(); this.load(); },
  loadSummary() {
    apiPost({action:'loan_summary'}).then(r=>{
      if(!r.ok)return;
      const d=r.data;
      let cards=`<div class="stat-card"><div class="stat-label">Total Outstanding</div><div class="stat-value wd-num-xl wd-loss">${formatINR(d.total_outstanding)}</div></div>
        <div class="stat-card"><div class="stat-label">Total EMI / Month</div><div class="stat-value wd-num-xl">${formatINR(d.total_emi)}</div></div>`;
      for(const t of(d.by_type||[])){cards+=`<div class="stat-card"><div class="stat-label">${esc(t.loan_type.charAt(0).toUpperCase()+t.loan_type.slice(1))}</div><div class="stat-value wd-num-xl">${formatINR(t.total_outstanding)}</div><div class="stat-sub">EMI: ${formatINR(t.total_emi)}/mo</div></div>`;}
      document.getElementById('lt-summary-cards').innerHTML=cards;
    });
  },
  load() {
    apiPost({action:'loans_list'}).then(r=>{
      if(!r.ok)return;
      const rows=r.data.loans||[];
      const wrap=document.getElementById('lt-table');
      if(!rows.length){wrap.innerHTML='<div class="empty-state"><div class="empty-icon">🏦</div><div>No loans added yet.</div></div>';return;}
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Loan</th><th>Type</th><th>Lender</th><th class="text-right">Principal</th><th class="text-right">Outstanding</th><th class="text-right">EMI</th><th>Rate</th><th>Progress</th><th></th></tr></thead><tbody>`;
      for(const l of rows){
        html+=`<tr>
          <td style="font-weight:600;">${esc(l.loan_name)}</td>
          <td style="text-transform:capitalize;">${esc(l.loan_type)}</td>
          <td style="font-size:12px;">${esc(l.lender||'—')}</td>
          <td class="text-right wd-num">${formatINR(l.loan_amount)}</td>
          <td class="text-right wd-num wd-loss">${formatINR(l.outstanding_principal)}</td>
          <td class="text-right wd-num">${formatINR(l.emi_amount)}/mo</td>
          <td>${l.interest_rate}%</td>
          <td style="min-width:100px;">
            <div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;">
              <div style="width:${l.completion_pct}%;height:100%;background:var(--gain);border-radius:3px;"></div>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${l.completion_pct}% paid</div>
          </td>
          <td><button class="btn btn-ghost btn-sm" onclick="LT.showAmort(${l.id},'${esc(l.loan_name)}')">📋</button><button class="btn btn-ghost btn-sm" onclick="LT.edit(${l.id})">✏️</button><button class="btn btn-danger btn-sm" onclick="LT.del(${l.id})">✕</button></td>
        </tr>`;
      }
      html+='</tbody></table></div>';
      wrap.innerHTML=html;
    });
  },
  showAmort(id,name) {
    apiPost({action:'loan_amortization',loan_id:id}).then(r=>{
      if(!r.ok)return;
      const sc=r.data.schedule||[];
      document.getElementById('lt-amort-title').textContent='📋 '+name+' — Amortization';
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Month</th><th>Date</th><th class="text-right">EMI</th><th class="text-right">Interest</th><th class="text-right">Principal</th><th class="text-right">Balance</th></tr></thead><tbody>`;
      for(const s of sc){html+=`<tr><td>${s.month}</td><td class="wd-num">${esc(s.date)}</td><td class="text-right wd-num">${formatINR(s.emi)}</td><td class="text-right wd-num wd-loss">${formatINR(s.interest)}</td><td class="text-right wd-num wd-gain">${formatINR(s.principal_paid)}</td><td class="text-right wd-num">${formatINR(s.balance)}</td></tr>`;}
      html+='</tbody></table></div>';
      document.getElementById('lt-amort-table').innerHTML=html;
      document.getElementById('lt-amort-panel').style.display='';
      document.getElementById('lt-amort-panel').scrollIntoView({behavior:'smooth'});
    });
  },
  _clearForm(){['lt-f-name','lt-f-lender','lt-f-amt','lt-f-os','lt-f-rate','lt-f-tenure','lt-f-emi','lt-f-start','lt-f-end','lt-f-notes'].forEach(i=>{const el=document.getElementById(i);if(el)el.value='';});document.getElementById('lt-edit-id').value='';},
  openAdd(){this._clearForm();document.getElementById('lt-modal-title').textContent='Add Loan';document.getElementById('lt-modal').style.display='';},
  closeModal(){document.getElementById('lt-modal').style.display='none';},
  edit(id){apiPost({action:'loans_list'}).then(r=>{const l=(r.data.loans||[]).find(x=>x.id==id);if(!l)return;this._clearForm();document.getElementById('lt-edit-id').value=id;document.getElementById('lt-modal-title').textContent='Edit Loan';const s=(f,v)=>{const el=document.getElementById(f);if(el)el.value=v||'';};s('lt-f-name',l.loan_name);s('lt-f-lender',l.lender);s('lt-f-amt',l.loan_amount);s('lt-f-os',l.outstanding_principal);s('lt-f-rate',l.interest_rate);s('lt-f-tenure',l.tenure_months);s('lt-f-emi',l.emi_amount);s('lt-f-start',l.start_date);s('lt-f-end',l.end_date);s('lt-f-notes',l.notes);document.getElementById('lt-f-type').value=l.loan_type;document.getElementById('lt-f-emidate').value=l.emi_date||5;document.getElementById('lt-modal').style.display='';});},
  save(){const id=document.getElementById('lt-edit-id').value;const data={action:id?'loan_update':'loan_add',loan_name:document.getElementById('lt-f-name').value,loan_type:document.getElementById('lt-f-type').value,lender:document.getElementById('lt-f-lender').value,loan_amount:document.getElementById('lt-f-amt').value,outstanding_principal:document.getElementById('lt-f-os').value,interest_rate:document.getElementById('lt-f-rate').value,tenure_months:document.getElementById('lt-f-tenure').value,emi_amount:document.getElementById('lt-f-emi').value,emi_date:document.getElementById('lt-f-emidate').value,start_date:document.getElementById('lt-f-start').value,end_date:document.getElementById('lt-f-end').value,notes:document.getElementById('lt-f-notes').value};if(id)data.id=id;apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeModal();this.load();this.loadSummary();}});},
  del(id){if(!confirm('Delete this loan?'))return;apiPost({action:'loan_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.load();this.loadSummary();}});}
};
document.addEventListener('DOMContentLoaded',()=>LT.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
