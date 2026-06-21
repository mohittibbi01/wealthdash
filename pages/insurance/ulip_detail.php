<?php
/**
 * WealthDash — t461: ULIP Fund Detail Page
 * File: pages/insurance/ulip_detail.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='ULIP Fund Detail'; $activePage='insurance'; $activeSection='ulip';
$ulipId=(int)($_GET['id']??0);
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📊 ULIP Fund Detail</h1><p class="page-subtitle" id="ud-policy-name">Loading…</p></div>
  <div style="display:flex;gap:8px;">
    <a href="<?=APP_URL?>?page=ulip" class="btn btn-ghost btn-sm">← Back</a>
    <button class="btn btn-secondary btn-sm" onclick="UD.openSwitch()">🔄 Fund Switch</button>
    <button class="btn btn-primary" onclick="UD.openAdd()">+ Add Fund Value</button>
  </div>
</div>
<div class="dashboard-grid" id="ud-cards" style="margin-bottom:20px;"></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
  <div class="card"><div class="card-header"><span class="card-title">🥧 Fund Allocation</span></div><div class="card-body" style="height:240px;"><canvas id="ud-alloc-chart"></canvas></div></div>
  <div class="card"><div class="card-header"><span class="card-title">📈 Value History</span></div><div class="card-body" style="height:240px;"><canvas id="ud-history-chart"></canvas></div></div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">📋 Funds</span></div>
  <div class="card-body p-0"><div id="ud-funds-table"></div></div>
</div>
<!-- Add Modal -->
<div id="ud-add-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)UD.closeAdd()">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header"><span class="modal-title">Add/Update Fund Value</span><button class="modal-close" onclick="UD.closeAdd()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Fund Name</label><input type="text" id="ud-f-name" class="form-control"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group"><label class="form-label">Units</label><input type="number" id="ud-f-units" class="form-control" step="0.0001"></div>
        <div class="form-group"><label class="form-label">NAV (₹)</label><input type="number" id="ud-f-nav" class="form-control" step="0.01"></div>
      </div>
      <div class="form-group"><label class="form-label">Date</label><input type="date" id="ud-f-date" class="form-control"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="UD.closeAdd()">Cancel</button><button class="btn btn-primary" onclick="UD.saveFund()">Save</button></div>
  </div>
</div>
<!-- Switch Modal -->
<div id="ud-switch-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)UD.closeSwitch()">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><span class="modal-title">🔄 Fund Switch</span><button class="modal-close" onclick="UD.closeSwitch()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">From Fund</label><select id="ud-s-from" class="form-control"></select></div>
      <div class="form-group"><label class="form-label">To Fund</label><input type="text" id="ud-s-to" class="form-control" placeholder="Target fund name"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group"><label class="form-label">Amount (₹)</label><input type="number" id="ud-s-amt" class="form-control" step="100"></div>
        <div class="form-group"><label class="form-label">To Fund NAV (₹)</label><input type="number" id="ud-s-nav" class="form-control" step="0.01"></div>
      </div>
      <div class="form-group"><label class="form-label">Switch Date</label><input type="date" id="ud-s-date" class="form-control"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="UD.closeSwitch()">Cancel</button><button class="btn btn-primary" onclick="UD.doSwitch()">Switch</button></div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ULIP_ID=<?=$ulipId?>;
const UD={_ac:null,_hc:null,_funds:[],
init(){
  const td=new Date().toISOString().substring(0,10);
  document.getElementById('ud-f-date').value=td;document.getElementById('ud-s-date').value=td;
  this.load();this.loadHistory();
  apiPost({action:'ulip_performance'}).then(r=>{const p=(r.data?.policies||[]).find(x=>x.id==ULIP_ID);if(p)document.getElementById('ud-policy-name').textContent=p.policy_name;});
},
load(){
  apiPost({action:'ulip_fund_list',ulip_id:ULIP_ID}).then(r=>{
    if(!r.ok){showToast(r.message,'error');return;}
    this._funds=r.data.funds||[];
    document.getElementById('ud-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Total Fund Value</div><div class="stat-value wd-num-xl">${formatINR(r.data.total_value)}</div></div><div class="stat-card"><div class="stat-label">Number of Funds</div><div class="stat-value wd-num-xl">${this._funds.length}</div></div>`;
    if(this._ac)this._ac.destroy();
    if(this._funds.length){this._ac=new Chart(document.getElementById('ud-alloc-chart'),{type:'doughnut',data:{labels:this._funds.map(f=>f.fund_name),datasets:[{data:this._funds.map(f=>f.current_value),backgroundColor:['#2563EB','#7C3AED','#059669','#DC2626','#D97706','#0891B2'],borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'right',labels:{color:'var(--text-primary)',font:{size:11}}}}}})}
    let html=this._funds.length?`<div class="table-responsive"><table class="data-table"><thead><tr><th>Fund</th><th class="text-right">Units</th><th class="text-right">NAV</th><th class="text-right">Value</th><th class="text-right">Weight</th></tr></thead><tbody>${this._funds.map(f=>`<tr><td style="font-weight:600;">${esc(f.fund_name)}</td><td class="text-right wd-num">${f.units.toFixed(4)}</td><td class="text-right wd-num">₹${f.nav.toFixed(2)}</td><td class="text-right wd-num">${formatINR(f.current_value)}</td><td class="text-right wd-num">${f.weight_pct}%</td></tr>`).join('')}</tbody></table></div>`:'<div class="empty-state" style="padding:30px;"><div>No fund values yet. Click "+ Add Fund Value".</div></div>';
    document.getElementById('ud-funds-table').innerHTML=html;
    document.getElementById('ud-s-from').innerHTML=this._funds.map(f=>`<option value="${esc(f.fund_name)}">${esc(f.fund_name)}</option>`).join('');
  });
},
loadHistory(){
  apiPost({action:'ulip_fund_history',ulip_id:ULIP_ID}).then(r=>{
    if(!r.ok)return;const h=r.data.history||[];
    if(this._hc)this._hc.destroy();
    this._hc=new Chart(document.getElementById('ud-history-chart'),{type:'line',data:{labels:h.map(x=>x.date),datasets:[{label:'Total Value',data:h.map(x=>x.value),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.1)',fill:true,tension:0.4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#6b7280'}},y:{ticks:{color:'#6b7280',callback:v=>formatINR(v,0)}}}}});
  });
},
openAdd(){document.getElementById('ud-f-name').value='';document.getElementById('ud-f-units').value='';document.getElementById('ud-f-nav').value='';document.getElementById('ud-add-modal').style.display='';},
closeAdd(){document.getElementById('ud-add-modal').style.display='none';},
saveFund(){apiPost({action:'ulip_fund_add',ulip_id:ULIP_ID,fund_name:document.getElementById('ud-f-name').value,units:document.getElementById('ud-f-units').value,nav:document.getElementById('ud-f-nav').value,value_date:document.getElementById('ud-f-date').value}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeAdd();this.load();this.loadHistory();}});},
openSwitch(){if(!this._funds.length){showToast('Add fund values first','warning');return;}document.getElementById('ud-s-to').value='';document.getElementById('ud-s-amt').value='';document.getElementById('ud-s-nav').value='';document.getElementById('ud-switch-modal').style.display='';},
closeSwitch(){document.getElementById('ud-switch-modal').style.display='none';},
doSwitch(){apiPost({action:'ulip_switch',ulip_id:ULIP_ID,from_fund:document.getElementById('ud-s-from').value,to_fund:document.getElementById('ud-s-to').value,amount:document.getElementById('ud-s-amt').value,to_nav:document.getElementById('ud-s-nav').value,switch_date:document.getElementById('ud-s-date').value}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeSwitch();this.load();this.loadHistory();}});}
};
document.addEventListener('DOMContentLoaded',()=>UD.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
