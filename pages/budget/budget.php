<?php
/**
 * WealthDash — t471: Monthly Budget Tracker Page
 * File: pages/budget/budget.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Monthly Budget'; $activePage='budget'; $activeSection='budget';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">💹 Monthly Budget Tracker</h1><p class="page-subtitle">Plan your income, expenses, and savings every month.</p></div>
  <div style="display:flex;gap:8px;align-items:center;">
    <button class="btn btn-ghost btn-sm" onclick="BT.changeMonth(-1)">◀</button>
    <input type="month" id="bt-month" class="form-control" style="width:160px;" value="<?= date('Y-m') ?>" onchange="BT.load()">
    <button class="btn btn-ghost btn-sm" onclick="BT.changeMonth(1)">▶</button>
    <button class="btn btn-secondary btn-sm" onclick="BT.openPlan()">📋 Set Budget</button>
    <button class="btn btn-primary" onclick="BT.openAdd()">+ Add Transaction</button>
  </div>
</div>

<div class="dashboard-grid" id="bt-summary-cards" style="margin-bottom:20px;"></div>

<div style="display:flex;gap:0;margin-bottom:0;border-bottom:2px solid var(--border);">
  <button class="btn btn-ghost" id="bt-tab-overview" onclick="BT.showTab('overview')" style="border-radius:0;border-bottom:2px solid var(--accent);">📊 Overview</button>
  <button class="btn btn-ghost" id="bt-tab-transactions" onclick="BT.showTab('transactions')" style="border-radius:0;border-bottom:2px solid transparent;">📋 Transactions</button>
</div>

<div id="bt-pane-overview" style="margin-top:20px;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="responsive-grid-1col">
    <div class="card"><div class="card-header"><span class="card-title">📈 Income</span></div><div class="card-body p-0"><div id="bt-income-bars"></div></div></div>
    <div class="card"><div class="card-header"><span class="card-title">📉 Expenses</span></div><div class="card-body p-0"><div id="bt-expense-bars"></div></div></div>
  </div>
</div>
<div id="bt-pane-transactions" style="display:none;margin-top:20px;">
  <div class="card"><div class="card-body p-0"><div id="bt-txn-table"></div></div></div>
</div>

<!-- Plan Modal -->
<div id="bt-plan-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)BT.closePlan()">
  <div class="modal" style="max-width:540px;max-height:85vh;overflow-y:auto;">
    <div class="modal-header"><span class="modal-title">📋 Set Monthly Budget</span><button class="modal-close" onclick="BT.closePlan()">×</button></div>
    <div class="modal-body" id="bt-plan-body"></div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="BT.closePlan()">Cancel</button><button class="btn btn-primary" onclick="BT.savePlan()">Save Budget</button></div>
  </div>
</div>

<!-- Add/Edit Transaction Modal -->
<div id="bt-add-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)BT.closeAdd()">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header"><span class="modal-title" id="bt-add-title">Add Transaction</span><button class="modal-close" onclick="BT.closeAdd()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="bt-edit-id">
      <div class="form-group"><label class="form-label">Type</label>
        <select id="bt-f-type" class="form-control" onchange="BT.refreshCatList()">
          <option value="expense" selected>Expense</option><option value="income">Income</option><option value="savings">Savings</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Category</label><select id="bt-f-cat" class="form-control"></select></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Amount (₹)</label><input type="number" id="bt-f-amount" class="form-control" step="10" min="0"></div>
        <div class="form-group"><label class="form-label">Date</label><input type="date" id="bt-f-date" class="form-control"></div>
      </div>
      <div class="form-group"><label class="form-label">Description</label><input type="text" id="bt-f-desc" class="form-control" placeholder="Optional note"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="BT.closeAdd()">Cancel</button><button class="btn btn-primary" onclick="BT.saveActual()">Save</button></div>
  </div>
</div>

<script>
const BT={_cats:[],_tab:'overview',
init(){
  document.getElementById('bt-f-date').value=new Date().toISOString().substring(0,10);
  apiPost({action:'budget_categories_list'}).then(r=>{this._cats=r.data?.categories||[];this.refreshCatList();this.load();});
},
changeMonth(d){const m=document.getElementById('bt-month');const[y,mo]=m.value.split('-').map(Number);const nd=new Date(y,mo-1+d,1);m.value=`${nd.getFullYear()}-${String(nd.getMonth()+1).padStart(2,'0')}`;this.load();},
load(){
  const month=document.getElementById('bt-month').value;
  apiPost({action:'budget_summary',month}).then(r=>{
    if(!r.ok)return;const d=r.data;const sc=d.savings>=0?'wd-gain':'wd-loss';
    document.getElementById('bt-summary-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Income</div><div class="stat-value wd-num-xl wd-gain">${formatINR(d.income_actual)}</div><div class="stat-sub">Budget: ${formatINR(d.income_planned)}</div></div><div class="stat-card"><div class="stat-label">Expenses</div><div class="stat-value wd-num-xl wd-loss">${formatINR(d.expense_actual)}</div><div class="stat-sub">Budget: ${formatINR(d.expense_planned)}</div></div><div class="stat-card"><div class="stat-label">Savings</div><div class="stat-value wd-num-xl ${sc}">${d.savings>=0?'+':''}${formatINR(d.savings)}</div><div class="stat-sub">Rate: ${d.savings_rate}%</div></div>`;
    const income=d.summary.filter(s=>s.type==='income');const expense=d.summary.filter(s=>s.type==='expense');
    document.getElementById('bt-income-bars').innerHTML=this._renderBars(income,'income');
    document.getElementById('bt-expense-bars').innerHTML=this._renderBars(expense,'expense');
  });
  this.loadActuals(month);
},
_renderBars(cats,type){
  if(!cats.length)return'<div class="empty-state" style="padding:20px;"><div>No data yet.</div></div>';
  const maxAmt=Math.max(...cats.map(c=>Math.max(c.budgeted,c.actual)),1);
  return cats.map(c=>{const ap=Math.min(100,c.actual/maxAmt*100);const bp=Math.min(100,c.budgeted/maxAmt*100);const over=type==='expense'&&c.actual>c.budgeted&&c.budgeted>0;return`<div style="padding:8px 14px;border-bottom:1px solid var(--border);"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;"><span>${c.icon} ${esc(c.name)}</span><span><strong>${formatINR(c.actual)}</strong>${c.budgeted?` / ${formatINR(c.budgeted)}`:''}${over?' <span style="color:var(--loss);">⚠️</span>':''}</span></div><div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;position:relative;">${c.budgeted?`<div style="position:absolute;left:0;top:0;bottom:0;width:${bp}%;background:var(--border);"></div>`:''}<div style="position:absolute;left:0;top:0;bottom:0;width:${ap}%;background:${over?'#dc2626':c.color||'var(--accent)'};border-radius:3px;"></div></div></div>`;}).join('');
},
loadActuals(month){
  apiPost({action:'budget_actual_list',month}).then(r=>{
    const rows=r.data?.actuals||[];const wrap=document.getElementById('bt-txn-table');
    if(!rows.length){wrap.innerHTML='<div class="empty-state" style="padding:30px;"><div class="empty-icon">💹</div><div>No transactions this month.</div></div>';return;}
    const tc={income:'var(--gain)',expense:'var(--loss)',savings:'var(--accent)'};
    let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Category</th><th>Type</th><th class="text-right">Amount</th><th>Note</th><th></th></tr></thead><tbody>`;
    for(const t of rows){html+=`<tr><td style="font-size:12px;">${esc(t.txn_date)}</td><td style="font-size:13px;">${esc(t.category)}</td><td style="font-size:12px;color:${tc[t.txn_type]||'var(--text)'};font-weight:600;text-transform:capitalize;">${t.txn_type}</td><td class="text-right wd-num" style="color:${tc[t.txn_type]||'var(--text)'};">${t.txn_type==='income'?'+':'−'}${formatINR(t.amount)}</td><td style="font-size:12px;color:var(--text-muted);">${esc(t.description||'—')}</td><td><button class="btn btn-ghost btn-sm" onclick="BT.editActual(${JSON.stringify(t).replace(/"/g,'&quot;')})">✏️</button><button class="btn btn-danger btn-sm" onclick="BT.delActual(${t.id})">✕</button></td></tr>`;}
    html+='</tbody></table></div>';wrap.innerHTML=html;
  });
},
showTab(tab){this._tab=tab;['overview','transactions'].forEach(t=>{document.getElementById('bt-pane-'+t).style.display=t===tab?'':'none';document.getElementById('bt-tab-'+t).style.borderBottom=t===tab?'2px solid var(--accent)':'2px solid transparent';});},
openPlan(){
  const month=document.getElementById('bt-month').value;
  apiPost({action:'budget_get',month}).then(r=>{
    const plan=r.data?.plan||{};let html='<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
    for(const c of this._cats){html+=`<div class="form-group"><label class="form-label" style="font-size:12px;">${c.icon} ${esc(c.name)}</label><input type="number" class="form-control" id="bp-${esc(c.name.replace(/\W/g,'_'))}" value="${plan[c.name]||''}" placeholder="0" step="100"></div>`;}
    html+='</div>';document.getElementById('bt-plan-body').innerHTML=html;document.getElementById('bt-plan-modal').style.display='';
  });
},
closePlan(){document.getElementById('bt-plan-modal').style.display='none';},
savePlan(){const plan={};for(const c of this._cats){const el=document.getElementById('bp-'+c.name.replace(/\W/g,'_'));const v=parseFloat(el?.value||0);if(v>0)plan[c.name]=v;}apiPost({action:'budget_save',month:document.getElementById('bt-month').value,plan:JSON.stringify(plan)}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closePlan();this.load();}});},
refreshCatList(){const type=document.getElementById('bt-f-type').value;const cats=this._cats.filter(c=>c.type===type);document.getElementById('bt-f-cat').innerHTML=cats.map(c=>`<option value="${esc(c.name)}">${c.icon} ${esc(c.name)}</option>`).join('');},
openAdd(){document.getElementById('bt-edit-id').value='';document.getElementById('bt-f-amount').value='';document.getElementById('bt-f-desc').value='';document.getElementById('bt-f-date').value=new Date().toISOString().substring(0,10);document.getElementById('bt-add-title').textContent='Add Transaction';document.getElementById('bt-f-type').value='expense';this.refreshCatList();document.getElementById('bt-add-modal').style.display='';},
closeAdd(){document.getElementById('bt-add-modal').style.display='none';},
editActual(t){document.getElementById('bt-edit-id').value=t.id;document.getElementById('bt-f-type').value=t.txn_type;this.refreshCatList();document.getElementById('bt-f-cat').value=t.category;document.getElementById('bt-f-amount').value=t.amount;document.getElementById('bt-f-date').value=t.txn_date;document.getElementById('bt-f-desc').value=t.description||'';document.getElementById('bt-add-title').textContent='Edit Transaction';document.getElementById('bt-add-modal').style.display='';},
saveActual(){const id=document.getElementById('bt-edit-id').value;const data={action:id?'budget_actual_update':'budget_actual_add',category:document.getElementById('bt-f-cat').value,txn_type:document.getElementById('bt-f-type').value,amount:document.getElementById('bt-f-amount').value,txn_date:document.getElementById('bt-f-date').value,description:document.getElementById('bt-f-desc').value};if(id)data.id=id;apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeAdd();this.load();}});},
delActual(id){if(!confirm('Delete?'))return;apiPost({action:'budget_actual_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});}
};
document.addEventListener('DOMContentLoaded',()=>BT.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
