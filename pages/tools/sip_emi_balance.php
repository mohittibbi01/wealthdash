<?php
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='SIP vs EMI Balance'; $activePage='tools'; $activeSection='tools';
ob_start();
?>
<div class="page-header"><h1 class="page-title">⚖️ SIP vs EMI Monthly Load</h1><p class="page-subtitle">Monthly cash outflow split between investments and loan repayments.</p></div>
<div id="se-cards" class="dashboard-grid" style="margin-bottom:20px;"></div>
<div class="card" style="margin-bottom:20px;"><div class="card-header"><span class="card-title">Ratio Split</span></div>
<div class="card-body"><div id="se-ratio" style="display:none;"><div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;"><span style="color:var(--gain);font-weight:600;">SIP</span><span style="color:#dc2626;font-weight:600;">EMI</span></div><div style="height:24px;border-radius:12px;overflow:hidden;background:var(--bg-secondary);display:flex;"><div id="se-sip-bar" style="background:var(--gain);height:100%;transition:width .4s;"></div><div id="se-emi-bar" style="background:#dc2626;height:100%;transition:width .4s;"></div></div><div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-top:4px;"><span id="se-sip-pct"></span><span id="se-emi-pct"></span></div></div>
<div style="height:220px;margin-top:16px;"><canvas id="se-chart"></canvas></div></div></div>
<div class="card"><div class="card-header"><span class="card-title">12-Month Projection</span></div><div class="card-body p-0"><div id="se-table"></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
apiPost({action:'sip_emi_summary'}).then(r=>{if(!r.ok)return;const d=r.data;
document.getElementById('se-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Monthly SIP</div><div class="stat-value wd-num-xl wd-gain">${formatINR(d.sip_monthly)}</div><div class="stat-sub">${d.sips.length} SIPs</div></div><div class="stat-card"><div class="stat-label">Monthly EMI</div><div class="stat-value wd-num-xl wd-loss">${formatINR(d.emi_monthly)}</div><div class="stat-sub">${d.emis.length} loans</div></div><div class="stat-card"><div class="stat-label">Total Outflow</div><div class="stat-value wd-num-xl">${formatINR(d.total_load)}</div></div>`;
if(d.total_load>0){document.getElementById('se-ratio').style.display='';document.getElementById('se-sip-bar').style.width=d.sip_ratio+'%';document.getElementById('se-emi-bar').style.width=d.emi_ratio+'%';document.getElementById('se-sip-pct').textContent=d.sip_ratio+'% SIP';document.getElementById('se-emi-pct').textContent=d.emi_ratio+'% EMI';}
if(d.projection?.length){new Chart(document.getElementById('se-chart'),{type:'bar',data:{labels:d.projection.map(p=>p.label),datasets:[{label:'SIP',data:d.projection.map(p=>p.sip),backgroundColor:'#16a34a',borderRadius:4,stack:'a'},{label:'EMI',data:d.projection.map(p=>p.emi),backgroundColor:'#dc2626',borderRadius:4,stack:'a'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'var(--text-primary)'}},tooltip:{callbacks:{label:c=>` ${c.dataset.label}: ${formatINR(c.raw)}`}}},scales:{x:{stacked:true,ticks:{color:'#6b7280'},grid:{display:false}},y:{stacked:true,ticks:{color:'#6b7280',callback:v=>formatINR(v,0)},grid:{color:'var(--border)'}}}}});}
let th=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Month</th><th class="text-right">SIP</th><th class="text-right">EMI</th><th class="text-right">Total</th><th>Health</th></tr></thead><tbody>`;
for(const p of(d.projection||[])){const sp=p.total>0?Math.round((p.sip/p.total)*100):0;const h=sp>=60?'🟢 Investment-heavy':sp>=40?'🟡 Balanced':'🔴 Debt-heavy';th+=`<tr><td style="font-weight:600;">${esc(p.label)}</td><td class="text-right wd-num wd-gain">${formatINR(p.sip)}</td><td class="text-right wd-num wd-loss">${formatINR(p.emi)}</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(p.total)}</td><td style="font-size:12px;">${h}</td></tr>`;}
th+='</tbody></table></div>';document.getElementById('se-table').innerHTML=th;});});
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
