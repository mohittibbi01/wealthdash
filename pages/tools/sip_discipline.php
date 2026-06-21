<?php
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='SIP Discipline Score'; $activePage='tools'; $activeSection='tools';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🏅 SIP Discipline Score</h1><p class="page-subtitle">Track SIP consistency, streaks and missed payments.</p></div>
  <select id="sd-months" class="form-control" style="width:150px;" onchange="SD.load()">
    <option value="6">Last 6 months</option><option value="12" selected>Last 12 months</option>
    <option value="24">Last 24 months</option><option value="36">Last 36 months</option>
  </select>
</div>
<div id="sd-hero" class="card" style="margin-bottom:20px;display:none;">
  <div class="card-body" style="text-align:center;padding:32px 20px;">
    <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Overall SIP Discipline Score</div>
    <div style="display:inline-block;position:relative;width:140px;height:140px;margin-bottom:16px;">
      <canvas id="sd-canvas" width="140" height="140"></canvas>
      <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
        <div id="sd-score-val" style="font-size:36px;font-weight:800;line-height:1;"></div>
        <div id="sd-grade-val" style="font-size:16px;font-weight:700;color:var(--text-muted);"></div>
      </div>
    </div>
    <div id="sd-summary-row" style="display:flex;justify-content:center;gap:32px;flex-wrap:wrap;"></div>
  </div>
</div>
<div class="card" style="margin-bottom:20px;"><div class="card-header"><span class="card-title">📈 Monthly Execution</span></div><div class="card-body" style="height:200px;"><canvas id="sd-hist-chart"></canvas></div></div>
<div class="card"><div class="card-header"><span class="card-title">📋 Per-SIP Breakdown</span></div><div class="card-body p-0"><div id="sd-sips-table"></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const SD={_hc:null,
init(){this.load();},
load(){const m=document.getElementById('sd-months').value;
Promise.all([apiPost({action:'sip_discipline_score',months:m}),apiPost({action:'sip_discipline_history',months:m})]).then(([s,h])=>{if(s.ok)this._renderScore(s.data);if(h.ok)this._renderHist(h.data.history||[]);});},
_gc(g){return{'A+':'#16a34a','A':'#22c55e','B+':'#84cc16','B':'#eab308','C':'#f97316','D':'#ef4444','F':'#dc2626','N/A':'#6b7280'}[g]||'#6b7280';},
_renderScore(d){document.getElementById('sd-hero').style.display='';
const canvas=document.getElementById('sd-canvas');const ctx=canvas.getContext('2d');const score=d.overall_score;const color=this._gc(d.grade);
ctx.clearRect(0,0,140,140);ctx.beginPath();ctx.arc(70,70,55,0,Math.PI*2);ctx.strokeStyle='var(--border)';ctx.lineWidth=12;ctx.stroke();
const end=(-Math.PI/2)+(Math.PI*2*score/100);ctx.beginPath();ctx.arc(70,70,55,-Math.PI/2,end);ctx.strokeStyle=color;ctx.lineWidth=12;ctx.lineCap='round';ctx.stroke();
document.getElementById('sd-score-val').textContent=score+'%';document.getElementById('sd-score-val').style.color=color;
document.getElementById('sd-grade-val').textContent='Grade: '+d.grade;
document.getElementById('sd-summary-row').innerHTML=`<div style="text-align:center;"><div style="font-size:22px;font-weight:800;">${d.streak}</div><div style="font-size:12px;color:var(--text-muted);">Streak 🔥</div></div><div style="text-align:center;"><div style="font-size:22px;font-weight:800;">${d.total_executed}/${d.total_expected}</div><div style="font-size:12px;color:var(--text-muted);">Payments</div></div><div style="text-align:center;"><div style="font-size:22px;font-weight:800;color:var(--loss);">${d.total_missed}</div><div style="font-size:12px;color:var(--text-muted);">Missed</div></div>`;
if(!d.sips.length){document.getElementById('sd-sips-table').innerHTML='<div class="empty-state"><div class="empty-icon">📈</div><div>No SIPs found.</div></div>';return;}
let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Fund</th><th class="text-right">Amount</th><th class="text-right">Executed</th><th class="text-right">Missed</th><th class="text-right">Streak</th><th class="text-right">Consistency</th><th class="text-right">Score</th><th class="text-right">Grade</th></tr></thead><tbody>`;
for(const s of d.sips){const gc=this._gc(s.grade);const ms=s.missed_months.length?`<span title="${s.missed_months.join(', ')}" style="cursor:help;text-decoration:underline dotted;">${s.missed_count}</span>`:'0';html+=`<tr><td style="font-size:13px;">${esc(s.fund_name)}</td><td class="text-right wd-num">${formatINR(s.sip_amount)}</td><td class="text-right wd-num wd-gain">${s.executed_months}</td><td class="text-right wd-num wd-loss">${ms}</td><td class="text-right wd-num">${s.streak} 🔥</td><td class="text-right wd-num">${s.consistency_pct}%</td><td class="text-right wd-num" style="font-weight:700;">${s.score}%</td><td class="text-right" style="font-weight:800;color:${gc};">${s.grade}</td></tr>`;}
html+='</tbody></table></div>';document.getElementById('sd-sips-table').innerHTML=html;},
_renderHist(hist){if(this._hc)this._hc.destroy();if(!hist.length)return;
this._hc=new Chart(document.getElementById('sd-hist-chart'),{type:'bar',data:{labels:hist.map(h=>h.month),datasets:[{label:'Invested',data:hist.map(h=>+h.total_invested),backgroundColor:'#2563eb',borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>` ${formatINR(c.raw)}`}}},scales:{x:{ticks:{color:'#6b7280',font:{size:11}},grid:{display:false}},y:{ticks:{color:'#6b7280',callback:v=>formatINR(v,0)},grid:{color:'var(--border)'}}}}});}};
document.addEventListener('DOMContentLoaded',()=>SD.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
