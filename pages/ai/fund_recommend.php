<?php
/**
 * WealthDash — t243: AI Fund Recommendation Page
 * File: pages/ai/fund_recommend.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Fund Recommendations'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header">
  <h1 class="page-title">🤖 AI Fund Recommendations</h1>
  <p class="page-subtitle">Portfolio gap analysis — what to add based on your risk profile.</p>
</div>
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="text-align:center;padding:32px 20px;">
    <div style="font-size:3rem;margin-bottom:12px;">🤖</div>
    <p style="font-size:14px;color:var(--text-muted);max-width:500px;margin:0 auto 20px;">AI will analyse your current portfolio allocation vs ideal allocation for your risk profile and suggest what to add or reduce.</p>
    <button class="btn btn-primary" id="ai-rec-btn" onclick="AIR.generate()">✨ Generate AI Recommendation</button>
  </div>
</div>
<div id="air-results" style="display:none;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
    <div class="card"><div class="card-header"><span class="card-title">Current Allocation</span></div><div class="card-body" style="height:220px;"><canvas id="air-current-chart"></canvas></div></div>
    <div class="card"><div class="card-header"><span class="card-title">Ideal Allocation</span></div><div class="card-body" style="height:220px;"><canvas id="air-ideal-chart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;">
      <span class="card-title">🤖 AI Insights</span>
      <span id="air-mode-badge" class="badge"></span>
    </div>
    <div class="card-body">
      <div id="air-narrative" style="font-size:14px;line-height:1.7;white-space:pre-wrap;"></div>
      <div id="air-gaps-wrap" style="margin-top:16px;"></div>
    </div>
  </div>
</div>
<div id="air-loading" style="display:none;text-align:center;padding:40px;">
  <div style="font-size:2rem;animation:spin 1s linear infinite;display:inline-block;">⚙️</div>
  <div style="margin-top:12px;color:var(--text-muted);">Analysing your portfolio…</div>
</div>
<style>@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const COLORS=['#2563EB','#7C3AED','#059669','#DC2626','#D97706','#0891B2','#BE185D','#4338CA','#065F46'];
const AIR={_cc:null,_ic:null,
generate(){
  document.getElementById('ai-rec-btn').disabled=true;
  document.getElementById('air-loading').style.display='';
  document.getElementById('air-results').style.display='none';
  apiPost({action:'ai_fund_recommend'}).then(r=>{
    document.getElementById('air-loading').style.display='none';
    document.getElementById('ai-rec-btn').disabled=false;
    if(!r.ok){showToast(r.message||'Error','error');return;}
    this._render(r.data);
  }).catch(()=>{document.getElementById('air-loading').style.display='none';document.getElementById('ai-rec-btn').disabled=false;showToast('Error generating','error');});
},
_render(d){
  document.getElementById('air-results').style.display='';
  const modeBadge=document.getElementById('air-mode-badge');
  modeBadge.textContent=d.mode==='ai'?'🤖 Claude AI':'📊 Rule-based';
  modeBadge.className=d.mode==='ai'?'badge wd-gain':'badge';
  document.getElementById('air-narrative').textContent=d.narrative||'';
  // Current chart
  const curLabels=Object.keys(d.allocation.current);
  const curVals=Object.values(d.allocation.current).map(v=>Math.round(v));
  if(this._cc)this._cc.destroy();
  this._cc=new Chart(document.getElementById('air-current-chart'),{type:'doughnut',data:{labels:curLabels,datasets:[{data:curVals,backgroundColor:COLORS.slice(0,curLabels.length),borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'right',labels:{color:'var(--text-primary)',font:{size:11},boxWidth:10}}}}});
  // Ideal chart
  const idLabels=Object.keys(d.allocation.ideal);
  const idVals=Object.values(d.allocation.ideal);
  if(this._ic)this._ic.destroy();
  this._ic=new Chart(document.getElementById('air-ideal-chart'),{type:'doughnut',data:{labels:idLabels,datasets:[{data:idVals,backgroundColor:COLORS.slice(0,idLabels.length),borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'right',labels:{color:'var(--text-primary)',font:{size:11},boxWidth:10}}}}});
  // Gaps (rule-based mode)
  if(d.gaps&&d.gaps.length){let gh=`<div style="font-weight:700;font-size:13px;margin-bottom:10px;">📊 Gaps to Fill:</div>`;for(const g of d.gaps){gh+=`<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;"><span style="font-weight:600;min-width:120px;">${esc(g.category)}</span><div style="flex:1;height:8px;background:var(--bg-secondary);border-radius:4px;overflow:hidden;"><div style="width:${Math.min(100,g.gap_pct*2)}%;height:100%;background:#dc2626;border-radius:4px;"></div></div><span class="wd-loss" style="font-size:12px;min-width:60px;text-align:right;">+${g.gap_pct}% needed</span></div>`;} document.getElementById('air-gaps-wrap').innerHTML=gh;}
}};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
