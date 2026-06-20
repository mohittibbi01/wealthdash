<?php
/**
 * WealthDash — t503: Credit Card Optimizer Page
 * File: pages/budget/credit_card_optimizer.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Credit Card Optimizer'; $activePage='budget'; $activeSection='budget';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">💳 Credit Card Optimizer</h1><p class="page-subtitle">Maximize rewards, minimize interest cost.</p></div>
  <button class="btn btn-primary" onclick="CC.openAdd()">+ Add Card</button>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">💳 My Cards</span></div>
  <div class="card-body p-0"><div id="cc-cards-table"></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="responsive-grid-1col">
  <!-- Spend Optimizer -->
  <div class="card">
    <div class="card-header"><span class="card-title">🎯 Which Card to Use?</span></div>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Spend Amount (₹)</label><input type="number" id="cc-opt-amount" class="form-control" value="5000" step="100"></div>
      <div class="form-group"><label class="form-label">Category</label>
        <select id="cc-opt-category" class="form-control"><option value="general">General</option><option value="dining">Dining</option><option value="travel">Travel</option><option value="fuel">Fuel</option><option value="online">Online Shopping</option></select>
      </div>
      <button class="btn btn-primary btn-sm" onclick="CC.optimize()">Find Best Card</button>
      <div id="cc-opt-result" style="margin-top:16px;"></div>
    </div>
  </div>

  <!-- Interest Calculator -->
  <div class="card">
    <div class="card-header"><span class="card-title">⚠️ Interest Cost Calculator</span></div>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Outstanding Balance (₹)</label><input type="number" id="cc-int-outstanding" class="form-control" value="50000" step="1000"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group"><label class="form-label">Interest Rate (% p.a.)</label><input type="number" id="cc-int-rate" class="form-control" value="42" step="1"></div>
        <div class="form-group"><label class="form-label">Monthly Payment (₹)</label><input type="number" id="cc-int-payment" class="form-control" value="5000" step="500"></div>
      </div>
      <button class="btn btn-primary btn-sm" onclick="CC.calcInterest()">Calculate</button>
      <div id="cc-int-result" style="margin-top:16px;"></div>
    </div>
  </div>
</div>

<!-- Add Card Modal -->
<div id="cc-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)CC.closeModal()">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header"><span class="modal-title" id="cc-modal-title">Add Credit Card</span><button class="modal-close" onclick="CC.closeModal()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="cc-edit-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Card Name *</label><input type="text" id="cc-f-name" class="form-control" placeholder="HDFC Regalia, SBI SimplyClick"></div>
        <div class="form-group"><label class="form-label">Bank</label><input type="text" id="cc-f-bank" class="form-control"></div>
        <div class="form-group"><label class="form-label">Credit Limit (₹)</label><input type="number" id="cc-f-limit" class="form-control" step="10000"></div>
        <div class="form-group"><label class="form-label">Current Outstanding (₹)</label><input type="number" id="cc-f-outstanding" class="form-control" step="100" value="0"></div>
        <div class="form-group"><label class="form-label">Reward Rate (%)</label><input type="number" id="cc-f-rewardrate" class="form-control" step="0.1" value="1"></div>
        <div class="form-group"><label class="form-label">Reward Type</label><select id="cc-f-rewardtype" class="form-control"><option value="cashback">Cashback</option><option value="points">Reward Points</option><option value="miles">Air Miles</option></select></div>
        <div class="form-group"><label class="form-label">Interest Rate (% p.a.)</label><input type="number" id="cc-f-intrate" class="form-control" step="1" value="42"></div>
        <div class="form-group"><label class="form-label">Bill Due Date (day)</label><input type="number" id="cc-f-duedate" class="form-control" min="1" max="31" value="15"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Annual Fee (₹)</label><input type="number" id="cc-f-fee" class="form-control" step="100" value="0"></div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="CC.closeModal()">Cancel</button><button class="btn btn-primary" onclick="CC.save()">Save Card</button></div>
  </div>
</div>

<script>
const CC={
  init(){this.load();},
  load(){
    apiPost({action:'cc_list'}).then(r=>{
      if(!r.ok)return;const rows=r.data.cards||[];
      const wrap=document.getElementById('cc-cards-table');
      if(!rows.length){wrap.innerHTML='<div class="empty-state" style="padding:30px;"><div class="empty-icon">💳</div><div>No cards added yet.</div></div>';return;}
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Card</th><th>Bank</th><th class="text-right">Limit</th><th class="text-right">Outstanding</th><th>Utilization</th><th class="text-right">Reward Rate</th><th></th></tr></thead><tbody>`;
      for(const c of rows){
        const utilColor=c.utilization_pct>50?'wd-loss':c.utilization_pct>30?'':'wd-gain';
        html+=`<tr><td style="font-weight:600;">${esc(c.card_name)}</td><td style="font-size:12px;">${esc(c.bank||'—')}</td><td class="text-right wd-num">${formatINR(c.credit_limit)}</td><td class="text-right wd-num">${formatINR(c.outstanding)}</td><td class="${utilColor}" style="font-weight:700;">${c.utilization_pct}%</td><td class="text-right wd-num">${c.reward_rate}%</td><td><button class="btn btn-ghost btn-sm" onclick="CC.edit(${c.id})">✏️</button><button class="btn btn-danger btn-sm" onclick="CC.del(${c.id})">✕</button></td></tr>`;
      }
      html+='</tbody></table></div>';wrap.innerHTML=html;
    });
  },
  optimize(){
    apiPost({action:'cc_optimize_spend',amount:document.getElementById('cc-opt-amount').value,category:document.getElementById('cc-opt-category').value}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      const d=r.data;
      let html=`<div class="alert alert-success"><strong>🏆 Best: ${esc(d.best_card.card_name)}</strong><br>Reward: ${formatINR(d.best_card.estimated_reward)} (${d.best_card.reward_rate}%)</div>`;
      html+='<table class="data-table" style="margin-top:10px;"><thead><tr><th>Card</th><th class="text-right">Reward</th><th>Note</th></tr></thead><tbody>';
      for(const c of d.all_cards){html+=`<tr><td style="font-size:13px;">${esc(c.card_name)}</td><td class="text-right wd-num">${formatINR(c.estimated_reward)}</td><td style="font-size:11px;color:var(--loss);">${c.warning?esc(c.warning):''}</td></tr>`;}
      html+='</tbody></table>';
      document.getElementById('cc-opt-result').innerHTML=html;
    });
  },
  calcInterest(){
    apiPost({action:'cc_interest_calculator',outstanding:document.getElementById('cc-int-outstanding').value,interest_rate:document.getElementById('cc-int-rate').value,monthly_payment:document.getElementById('cc-int-payment').value}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      const d=r.data;
      let html=d.trap_warning?`<div class="alert alert-danger">${esc(d.trap_warning)}</div>`:'';
      html+=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
        <div><div style="font-size:11px;color:var(--text-muted);">Months to pay off</div><div style="font-weight:700;font-size:18px;">${d.months_to_payoff}</div></div>
        <div><div style="font-size:11px;color:var(--text-muted);">Total Interest</div><div style="font-weight:700;font-size:18px;color:var(--loss);">${formatINR(d.total_interest_paid)}</div></div>
      </div>`;
      document.getElementById('cc-int-result').innerHTML=html;
    });
  },
  _clearForm(){['cc-f-name','cc-f-bank','cc-f-limit','cc-f-outstanding','cc-f-rewardrate','cc-f-intrate','cc-f-fee'].forEach(i=>{const el=document.getElementById(i);if(el)el.value='';});document.getElementById('cc-edit-id').value='';document.getElementById('cc-f-duedate').value='15';},
  openAdd(){this._clearForm();document.getElementById('cc-modal-title').textContent='Add Credit Card';document.getElementById('cc-modal').style.display='';},
  closeModal(){document.getElementById('cc-modal').style.display='none';},
  edit(id){apiPost({action:'cc_list'}).then(r=>{const c=(r.data.cards||[]).find(x=>x.id==id);if(!c)return;this._clearForm();document.getElementById('cc-edit-id').value=id;document.getElementById('cc-modal-title').textContent='Edit Card';const set=(f,v)=>{const el=document.getElementById(f);if(el)el.value=v||'';};set('cc-f-name',c.card_name);set('cc-f-bank',c.bank);set('cc-f-limit',c.credit_limit);set('cc-f-outstanding',c.outstanding);set('cc-f-rewardrate',c.reward_rate);set('cc-f-intrate',c.interest_rate);set('cc-f-duedate',c.due_date);set('cc-f-fee',c.annual_fee);document.getElementById('cc-f-rewardtype').value=c.reward_type;document.getElementById('cc-modal').style.display='';});},
  save(){const id=document.getElementById('cc-edit-id').value;const data={action:id?'cc_update':'cc_add',card_name:document.getElementById('cc-f-name').value,bank:document.getElementById('cc-f-bank').value,credit_limit:document.getElementById('cc-f-limit').value,outstanding:document.getElementById('cc-f-outstanding').value,reward_rate:document.getElementById('cc-f-rewardrate').value,reward_type:document.getElementById('cc-f-rewardtype').value,interest_rate:document.getElementById('cc-f-intrate').value,due_date:document.getElementById('cc-f-duedate').value,annual_fee:document.getElementById('cc-f-fee').value};if(id)data.id=id;apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeModal();this.load();}});},
  del(id){if(!confirm('Delete this card?'))return;apiPost({action:'cc_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});}
};
document.addEventListener('DOMContentLoaded',()=>CC.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
