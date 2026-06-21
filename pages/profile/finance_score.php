<?php
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Finance Score'; $activePage='settings'; $activeSection='profile';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🏆 Personal Finance Score</h1><p class="page-subtitle">Your overall financial health — 0 to 100.</p></div>
<div class="card" style="margin-bottom:20px;text-align:center;">
  <div class="card-body" style="padding:40px 20px;">
    <div style="display:inline-block;position:relative;width:180px;height:180px;margin-bottom:20px;">
      <canvas id="fs-canvas" width="180" height="180"></canvas>
      <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
        <div id="fs-score-num" style="font-size:52px;font-weight:900;line-height:1;"></div>
        <div id="fs-grade" style="font-size:20px;font-weight:700;"></div>
      </div>
    </div>
    <div id="fs-message" style="font-size:15px;font-weight:600;margin-bottom:8px;"></div>
    <div id="fs-warn" style="display:none;" class="alert alert-warning" style="font-size:13px;">⚠️ <a href="<?= APP_URL ?>?page=finance_profile" style="color:var(--accent);">Complete your Finance Profile</a> for accurate score.</div>
  </div>
</div>
<div id="fs-components" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;" class="responsive-grid-1col"></div>
<div class="card"><div class="card-header"><span class="card-title">📊 Score Radar</span></div><div class="card-body" style="height:300px;"><canvas id="fs-radar"></canvas></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
apiPost({action:'finance_score'}).then(r=>{if(!r.ok)return;const d=r.data;
if(!d.profile_set)document.getElementById('fs-warn').style.display='';
const canvas=document.getElementById('fs-canvas');const ctx=canvas.getContext('2d');const score=d.score;
const color=score>=70?'#16a34a':score>=55?'#eab308':score>=40?'#f97316':'#dc2626';
ctx.clearRect(0,0,180,180);ctx.beginPath();ctx.arc(90,90,72,0,Math.PI*2);ctx.strokeStyle='var(--border)';ctx.lineWidth=14;ctx.stroke();
const end=(-Math.PI/2)+(Math.PI*2*score/100);ctx.beginPath();ctx.arc(90,90,72,-Math.PI/2,end);ctx.strokeStyle=color;ctx.lineWidth=14;ctx.lineCap='round';ctx.stroke();
document.getElementById('fs-score-num').textContent=score;document.getElementById('fs-score-num').style.color=color;
document.getElementById('fs-grade').textContent='Grade: '+d.grade;document.getElementById('fs-grade').style.color=color;
document.getElementById('fs-message').textContent=d.message;
const sc={'good':'#16a34a','fair':'#eab308','poor':'#dc2626'};
let ch='';for(const c of d.components){const pct=Math.round((c.score/c.max)*100);const cc=sc[c.status]||'#6b7280';ch+=`<div class="card" style="border-left:4px solid ${cc};"><div class="card-body" style="padding:14px 16px;"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><div style="font-weight:700;font-size:14px;">${esc(c.icon)} ${esc(c.name)}</div><div style="font-weight:800;font-size:16px;color:${cc};">${c.score}/${c.max}</div></div><div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;margin-bottom:8px;"><div style="width:${pct}%;height:100%;background:${cc};border-radius:3px;"></div></div><div style="font-size:12px;color:var(--text-muted);">${esc(c.detail)}</div></div></div>`;}
document.getElementById('fs-components').innerHTML=ch;
new Chart(document.getElementById('fs-radar'),{type:'radar',data:{labels:d.components.map(c=>c.name),datasets:[{label:'Score',data:d.components.map(c=>Math.round((c.score/c.max)*100)),backgroundColor:'rgba(37,99,235,.15)',borderColor:'#2563eb',borderWidth:2,pointBackgroundColor:'#2563eb'}]},options:{responsive:true,maintainAspectRatio:false,scales:{r:{min:0,max:100,ticks:{stepSize:20,color:'#6b7280'},grid:{color:'var(--border)'},pointLabels:{color:'var(--text-primary)',font:{size:12}}}},plugins:{legend:{display:false}}}});});});
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
