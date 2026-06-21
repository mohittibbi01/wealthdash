<?php
/**
 * WealthDash — t155: Child Education Planner Page
 * File: pages/goal/education_planner.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Child Education Planner'; $activePage='goal'; $activeSection='goal';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🎓 Child Education Planner</h1><p class="page-subtitle">Plan the corpus needed for your child's education.</p></div>
<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start;" class="responsive-grid-1col">
  <!-- Input panel -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header"><span class="card-title">⚙️ Plan Details</span></div>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Child Name</label><input type="text" id="ep-name" class="form-control" value="Child" placeholder="Child's name"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Child's Age</label><input type="number" id="ep-age" class="form-control" value="5" min="0" max="17"></div>
        <div class="form-group"><label class="form-label">Education Starts Age</label><input type="number" id="ep-target-age" class="form-control" value="18" min="5" max="25"></div>
      </div>
      <div class="form-group"><label class="form-label">Current Education Cost (₹)</label><input type="number" id="ep-cost" class="form-control" value="1500000" step="100000"></div>
      <div class="form-group"><label class="form-label">Education Inflation (%)</label>
        <div style="display:flex;gap:10px;align-items:center;"><input type="range" id="ep-inf" min="5" max="15" value="8" step="0.5" class="form-range" oninput="document.getElementById('ep-inf-val').textContent=this.value+'%'"><span id="ep-inf-val" style="font-weight:700;min-width:40px;">8%</span></div>
      </div>
      <div class="form-group"><label class="form-label">Investment Return (%)</label>
        <div style="display:flex;gap:10px;align-items:center;"><input type="range" id="ep-ret" min="6" max="18" value="12" step="0.5" class="form-range" oninput="document.getElementById('ep-ret-val').textContent=this.value+'%'"><span id="ep-ret-val" style="font-weight:700;min-width:40px;">12%</span></div>
      </div>
      <div class="form-group"><label class="form-label">Existing Corpus (₹)</label><input type="number" id="ep-existing" class="form-control" value="0" step="10000"></div>
      <div class="form-group"><label class="form-label">Monthly SIP (₹)</label><input type="number" id="ep-sip" class="form-control" value="5000" step="500"></div>
      <button class="btn btn-primary" style="width:100%;margin-top:8px;" onclick="EP.calculate()">Calculate 🎓</button>
      <button class="btn btn-secondary btn-sm" style="width:100%;margin-top:8px;" onclick="EP.save()">💾 Save Plan</button>
    </div>
  </div>
  <!-- Results -->
  <div>
    <div id="ep-results" style="display:none;">
      <div class="dashboard-grid" id="ep-cards" style="margin-bottom:20px;"></div>
      <div id="ep-banner" class="alert" style="margin-bottom:20px;font-weight:600;font-size:14px;"></div>
      <div class="card" style="margin-bottom:20px;"><div class="card-header"><span class="card-title">📈 Corpus Growth</span></div><div class="card-body" style="height:260px;"><canvas id="ep-chart"></canvas></div></div>
    </div>
    <div id="ep-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🎓</div><div>Fill details and click Calculate.</div></div></div></div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const EP={_chart:null,_last:null,
calculate(){
  apiPost({action:'edu_plan_calculate',child_name:document.getElementById('ep-name').value,child_age:document.getElementById('ep-age').value,target_age:document.getElementById('ep-target-age').value,current_cost:document.getElementById('ep-cost').value,inflation:document.getElementById('ep-inf').value,return_rate:document.getElementById('ep-ret').value,existing_corpus:document.getElementById('ep-existing').value,monthly_sip:document.getElementById('ep-sip').value}).then(r=>{
    if(!r.ok){showToast(r.message,'error');return;}
    this._last=r.data; this._render(r.data);
  });
},
_render(d){
  document.getElementById('ep-empty').style.display='none';
  document.getElementById('ep-results').style.display='';
  document.getElementById('ep-cards').innerHTML=`
    <div class="stat-card"><div class="stat-label">Future Education Cost</div><div class="stat-value wd-num-xl">${formatINR(d.future_cost)}</div><div class="stat-sub">Today: ${formatINR(d.current_cost)} · ${d.years}y @ ${document.getElementById('ep-inf').value}% inflation</div></div>
    <div class="stat-card"><div class="stat-label">Projected Corpus</div><div class="stat-value wd-num-xl ${d.on_track?'wd-gain':'wd-loss'}">${formatINR(d.projected_corpus)}</div></div>
    <div class="stat-card"><div class="stat-label">SIP Needed (to fill gap)</div><div class="stat-value wd-num-xl">${d.sip_needed>0?formatINR(d.sip_needed)+'/mo':'—'}</div><div class="stat-sub">${d.on_track?'Already covered!':'Additional monthly'}</div></div>`;
  const banner=document.getElementById('ep-banner');
  if(d.on_track){banner.className='alert alert-success';banner.textContent='✅ On Track! Projected corpus covers '+esc(d.child_name)+'\'s education.';}
  else{banner.className='alert alert-danger';banner.textContent=`⚠️ Shortfall of ${formatINR(d.gap)}. Invest ${formatINR(d.sip_needed)}/month more.`;}
  if(this._chart)this._chart.destroy();
  this._chart=new Chart(document.getElementById('ep-chart'),{type:'line',data:{labels:d.yearly.map(y=>'Age '+y.year),datasets:[{label:'Projected',data:d.yearly.map(y=>y.corpus),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.1)',fill:true,tension:0.4},{label:'Target',data:d.yearly.map(y=>y.target),borderColor:'#dc2626',borderDash:[6,3],fill:false,tension:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'var(--text-primary)'}},tooltip:{callbacks:{label:c=>` ${c.dataset.label}: ${formatINR(c.raw)}`}}},scales:{x:{ticks:{color:'#6b7280'},grid:{display:false}},y:{ticks:{color:'#6b7280',callback:v=>formatINR(v,0)},grid:{color:'var(--border)'}}}}});
},
save(){if(!this._last){showToast('Calculate first','warning');return;}apiPost({action:'edu_plan_save',child_name:this._last.child_name,target_age:document.getElementById('ep-target-age').value,inputs:JSON.stringify({child_age:document.getElementById('ep-age').value,current_cost:document.getElementById('ep-cost').value,inflation:document.getElementById('ep-inf').value,return_rate:document.getElementById('ep-ret').value,existing_corpus:document.getElementById('ep-existing').value,monthly_sip:document.getElementById('ep-sip').value}),results:JSON.stringify(this._last)}).then(r=>showToast(r.message,r.ok?'success':'error'));}
};
document.addEventListener('DOMContentLoaded',()=>EP.calculate());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
