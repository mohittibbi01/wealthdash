<?php
/**
 * WealthDash — t462: Premium Calendar Page
 * File: pages/insurance/premium_calendar.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Premium Calendar'; $activePage='insurance'; $activeSection='premium_calendar';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📅 Premium Calendar</h1><p class="page-subtitle">Insurance premium due dates and payment tracking.</p></div>
  <div style="display:flex;gap:8px;align-items:center;">
    <button class="btn btn-ghost btn-sm" onclick="PC.changeYear(-1)">◀</button>
    <span id="pc-year" style="font-weight:700;font-size:16px;min-width:50px;text-align:center;"></span>
    <button class="btn btn-ghost btn-sm" onclick="PC.changeYear(1)">▶</button>
    <button class="btn btn-ghost btn-sm" onclick="PC.viewHistory()">📜 History</button>
  </div>
</div>
<div class="dashboard-grid" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">Annual Premium Total</div><div class="stat-value wd-num-xl" id="pc-annual-total">—</div></div>
</div>
<div id="pc-calendar" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;"></div>
<!-- Pay Modal -->
<div id="pc-pay-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)PC.closePay()">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header"><span class="modal-title">✅ Mark Premium Paid</span><button class="modal-close" onclick="PC.closePay()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="pc-pay-pid"><input type="hidden" id="pc-pay-dd"><input type="hidden" id="pc-pay-amt">
      <div id="pc-pay-summary" style="margin-bottom:14px;padding:12px;background:var(--bg-secondary);border-radius:8px;font-size:13px;"></div>
      <div class="form-group"><label class="form-label">Paid Date</label><input type="date" id="pc-pay-date" class="form-control"></div>
      <div class="form-group"><label class="form-label">Payment Method</label>
        <select id="pc-pay-method" class="form-control"><option value="auto_debit">Auto Debit</option><option value="netbanking">Net Banking</option><option value="upi">UPI</option><option value="card">Card</option><option value="other">Other</option></select>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="PC.closePay()">Cancel</button><button class="btn btn-primary" onclick="PC.confirmPaid()">✅ Confirm</button></div>
  </div>
</div>
<!-- History Modal -->
<div id="pc-hist-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal" style="max-width:580px;">
    <div class="modal-header"><span class="modal-title">📜 Payment History</span><button class="modal-close" onclick="document.getElementById('pc-hist-modal').style.display='none'">×</button></div>
    <div class="modal-body p-0"><div id="pc-hist-body"></div></div>
  </div>
</div>
<style>
.pc-month{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:14px;}
.pc-month-title{font-weight:700;font-size:13px;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--border);}
.pc-entry{display:flex;align-items:center;justify-content:space-between;padding:5px 0;font-size:12px;}
.pc-entry.paid{opacity:.5;}
</style>
<script>
const PC={_year:new Date().getFullYear(),
init(){document.getElementById('pc-pay-date').value=new Date().toISOString().substring(0,10);this.load();},
changeYear(d){this._year+=d;this.load();},
load(){
  document.getElementById('pc-year').textContent=this._year;
  apiPost({action:'premium_calendar_get',year:this._year}).then(r=>{
    if(!r.ok)return;
    document.getElementById('pc-annual-total').textContent=formatINR(r.data.annual_total);
    const mn=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let html='';
    for(let m=1;m<=12;m++){
      const entries=r.data.months[m]||[];
      html+=`<div class="pc-month"><div class="pc-month-title">${mn[m-1]} ${this._year}</div>`;
      if(!entries.length){html+='<div style="font-size:12px;color:var(--text-muted);text-align:center;padding:6px;">No premiums due</div>';}
      else{for(const e of entries){html+=`<div class="pc-entry ${e.paid?'paid':''}"><div><div style="font-weight:600;">${esc(e.policy_name)}</div><div style="color:var(--text-muted);">${esc(e.due_date)} · ${formatINR(e.amount)}</div></div>${e.paid?'<span class="badge wd-gain">✓</span>':`<button class="btn btn-primary btn-sm" onclick="PC.openPay('${e.policy_id}','${e.due_date}',${e.amount},'${esc(e.policy_name)}','${esc(e.insurer||'')}')">Pay</button>`}</div>`;}}
      html+='</div>';
    }
    document.getElementById('pc-calendar').innerHTML=html;
  });
},
openPay(pid,dd,amt,name,ins){
  document.getElementById('pc-pay-pid').value=pid;document.getElementById('pc-pay-dd').value=dd;document.getElementById('pc-pay-amt').value=amt;
  document.getElementById('pc-pay-summary').innerHTML=`<strong>${esc(name)}</strong>${ins?` (${esc(ins)})`:''}  <br>Due: ${esc(dd)} · ${formatINR(amt)}`;
  document.getElementById('pc-pay-modal').style.display='';
},
closePay(){document.getElementById('pc-pay-modal').style.display='none';},
confirmPaid(){apiPost({action:'premium_mark_paid',policy_id:document.getElementById('pc-pay-pid').value,due_date:document.getElementById('pc-pay-dd').value,amount:document.getElementById('pc-pay-amt').value,paid_date:document.getElementById('pc-pay-date').value,payment_method:document.getElementById('pc-pay-method').value}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closePay();this.load();}});},
viewHistory(){apiPost({action:'premium_history_list'}).then(r=>{const rows=r.data?.payments||[];const body=document.getElementById('pc-hist-body');body.innerHTML=rows.length?`<table class="data-table"><thead><tr><th>Policy</th><th>Due</th><th>Paid</th><th class="text-right">Amount</th><th>Method</th><th></th></tr></thead><tbody>${rows.map(p=>`<tr><td style="font-size:12px;">${esc(p.policy_name)}</td><td style="font-size:12px;">${esc(p.due_date)}</td><td style="font-size:12px;">${esc(p.paid_date)}</td><td class="text-right wd-num">${formatINR(p.amount)}</td><td style="font-size:12px;">${esc(p.payment_method?.replace('_',' ')||'—')}</td><td><button class="btn btn-ghost btn-sm" onclick="PC.delPay(${p.id})">✕</button></td></tr>`).join('')}</tbody></table>`:'<div class="empty-state" style="padding:30px;"><div>No history yet.</div></div>';document.getElementById('pc-hist-modal').style.display='';});},
delPay(id){if(!confirm('Remove?'))return;apiPost({action:'premium_payment_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.viewHistory();this.load();}});}
};
document.addEventListener('DOMContentLoaded',()=>PC.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
