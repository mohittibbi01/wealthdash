<?php
/**
 * WealthDash — t240: Onboarding Flow Page
 * File: pages/onboarding/onboarding.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Get Started'; $activePage='onboarding'; $activeSection='onboarding';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">👋 Welcome to WealthDash!</h1><p class="page-subtitle">Complete these steps to get the most out of your financial dashboard.</p></div>
  <button class="btn btn-ghost btn-sm" onclick="OB.skip()">Skip for now</button>
</div>

<!-- Progress bar -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-body" style="padding:20px 24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <span style="font-weight:700;font-size:15px;">Setup Progress</span>
      <span id="ob-pct" style="font-weight:800;font-size:18px;color:var(--accent);"></span>
    </div>
    <div style="height:10px;background:var(--bg-secondary);border-radius:5px;overflow:hidden;">
      <div id="ob-prog-bar" style="height:100%;background:var(--accent);border-radius:5px;transition:width .5s;width:0%;"></div>
    </div>
    <div id="ob-done-msg" style="display:none;margin-top:12px;" class="alert alert-success">🎉 All steps complete! Your WealthDash is fully set up.</div>
  </div>
</div>

<!-- Steps grid -->
<div id="ob-steps" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;"></div>

<style>
.ob-card{background:var(--bg-surface);border:2px solid var(--border);border-radius:14px;padding:20px;transition:border-color .2s;}
.ob-card.done{border-color:var(--gain);opacity:.85;}
.ob-card.active{border-color:var(--accent);}
.ob-card-icon{font-size:2.4rem;margin-bottom:12px;}
.ob-card-title{font-weight:700;font-size:15px;margin-bottom:6px;}
.ob-card-desc{font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.5;}
.ob-check{width:22px;height:22px;border-radius:50%;background:var(--gain);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;float:right;margin-top:-4px;}
</style>

<script>
const OB={
  init(){this.load();},
  load(){
    apiPost({action:'onboarding_status'}).then(r=>{
      if(!r.ok)return;
      const d=r.data;
      document.getElementById('ob-pct').textContent=d.pct+'%';
      document.getElementById('ob-prog-bar').style.width=d.pct+'%';
      if(d.done)document.getElementById('ob-done-msg').style.display='';
      if(d.skipped&&!d.done){document.getElementById('ob-steps').innerHTML='<div class="alert alert-info">Onboarding skipped. <a href="#" onclick="OB.resume()">Resume setup</a></div>';return;}
      const wrap=document.getElementById('ob-steps');
      wrap.innerHTML=d.steps.map(s=>`
        <div class="ob-card ${s.completed?'done':d.steps.findIndex(x=>!x.completed)===d.steps.indexOf(s)?'active':''}">
          ${s.completed?'<span class="ob-check">✓</span>':''}
          <div class="ob-card-icon">${s.icon}</div>
          <div class="ob-card-title">${esc(s.title)}</div>
          <div class="ob-card-desc">${esc(s.desc)}</div>
          ${s.completed
            ? '<span class="badge wd-gain">Completed</span>'
            : `<a href="${window.WD.appUrl}?page=${esc(s.page)}" class="btn btn-primary btn-sm" onclick="OB.markStep('${esc(s.key)}')">Start →</a>`
          }
        </div>`).join('');
    });
  },
  markStep(key){apiPost({action:'onboarding_complete_step',step:key}).then(()=>this.load());},
  skip(){if(!confirm('Skip the setup wizard?'))return;apiPost({action:'onboarding_skip'}).then(r=>{showToast(r.message,'info');this.load();});},
  resume(){apiPost({action:'onboarding_skip'}).then(()=>this.load());}
};
document.addEventListener('DOMContentLoaded',()=>OB.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
