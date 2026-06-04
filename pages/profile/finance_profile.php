<?php
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Finance Profile'; $activePage='settings'; $activeSection='profile';
ob_start();
?>
<div class="page-header"><h1 class="page-title">👤 Personal Finance Profile</h1><p class="page-subtitle">Your risk appetite, tax slab, goals, and financial overview.</p></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="responsive-grid-1col">
  <div>
    <div class="card" style="margin-bottom:20px;"><div class="card-header"><span class="card-title">👤 Personal Details</span></div><div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Age</label><input type="number" id="fp-age" class="form-control" min="18" max="80" placeholder="30"></div>
        <div class="form-group"><label class="form-label">Dependents</label><input type="number" id="fp-dep" class="form-control" min="0" placeholder="0"></div>
      </div>
      <div class="form-group"><label class="form-label">Employment Type</label><select id="fp-emp" class="form-control"><option value="salaried">Salaried</option><option value="self_employed">Self-Employed</option><option value="business">Business</option><option value="freelancer">Freelancer</option><option value="retired">Retired</option></select></div>
      <div class="form-group"><label class="form-label">Annual Income (₹)</label><input type="number" id="fp-income" class="form-control" placeholder="1200000" step="10000"></div>
      <div class="form-group"><label class="form-label">Tax Slab</label><select id="fp-tax" class="form-control"><option value="0">NIL</option><option value="5">5%</option><option value="10">10%</option><option value="15">15%</option><option value="20">20%</option><option value="30">30%</option></select></div>
    </div></div>
    <div class="card" style="margin-bottom:20px;"><div class="card-header"><span class="card-title">💰 Cash Flow</span></div><div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Monthly Expenses (₹)</label><input type="number" id="fp-exp" class="form-control" placeholder="50000" step="1000"></div>
        <div class="form-group"><label class="form-label">Monthly Savings (₹)</label><input type="number" id="fp-sav" class="form-control" placeholder="20000" step="1000"></div>
      </div>
      <div class="form-group"><label class="form-label">Emergency Fund (months)</label>
        <div style="display:flex;align-items:center;gap:12px;"><input type="range" id="fp-ef" min="0" max="24" value="3" class="form-range" oninput="document.getElementById('fp-ef-val').textContent=this.value+' months'"><span id="fp-ef-val" style="min-width:80px;font-weight:700;">3 months</span></div>
      </div>
    </div></div>
    <div class="card"><div class="card-header"><span class="card-title">🛡 Insurance</span></div><div class="card-body">
      <div style="display:flex;gap:20px;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="fp-life" style="width:16px;height:16px;"><span>Life Insurance</span></label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="fp-health" style="width:16px;height:16px;"><span>Health Insurance</span></label>
      </div>
    </div></div>
  </div>
  <div>
    <div class="card" style="margin-bottom:20px;"><div class="card-header"><span class="card-title">⚖️ Risk & Investment Profile</span></div><div class="card-body">
      <div class="form-group"><label class="form-label">Risk Profile</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;" id="fp-risk-btns">
          <?php foreach(['conservative'=>'🛡 Conservative','moderate'=>'⚖️ Moderate','moderately_aggressive'=>'📈 Mod. Aggressive','aggressive'=>'🚀 Aggressive'] as $v=>$l): ?>
            <button type="button" class="btn btn-secondary btn-sm fp-risk-btn" data-val="<?=$v?>" onclick="FP.setRisk('<?=$v?>')" style="font-size:12px;"><?=$l?></button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="fp-risk" value="moderate">
      </div>
      <div class="form-group"><label class="form-label">Investment Horizon</label>
        <select id="fp-horizon" class="form-control"><option value="short">Short (&lt;3 yrs)</option><option value="medium">Medium (3-7 yrs)</option><option value="long" selected>Long (7+ yrs)</option></select>
      </div>
      <div id="fp-risk-desc" class="alert alert-info" style="font-size:13px;margin-top:8px;"></div>
    </div></div>
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;"><span class="card-title">🎯 Financial Goals</span><button type="button" class="btn btn-secondary btn-sm" onclick="FP.addGoal()">+ Add</button></div>
      <div class="card-body"><div id="fp-goals-list"></div></div>
    </div>
    <div class="card"><div class="card-header"><span class="card-title">📝 Notes</span></div><div class="card-body"><textarea id="fp-notes" class="form-control" rows="3" placeholder="Any notes..."></textarea></div></div>
  </div>
</div>
<div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
  <button class="btn btn-ghost" onclick="FP.load()">↩ Reset</button>
  <button class="btn btn-primary" onclick="FP.save()">💾 Save Profile</button>
</div>
<script>
const FP={_gid:0,
init(){this.load();},
load(){apiPost({action:'fp_get'}).then(r=>{if(!r.ok||!r.data.profile){this.setRisk('moderate');this.addGoal();return;}const p=r.data.profile;document.getElementById('fp-age').value=p.age||'';document.getElementById('fp-dep').value=p.dependents||0;document.getElementById('fp-emp').value=p.employment_type||'salaried';document.getElementById('fp-income').value=p.annual_income||'';document.getElementById('fp-tax').value=p.tax_slab||'30';document.getElementById('fp-exp').value=p.monthly_expenses||'';document.getElementById('fp-sav').value=p.monthly_savings||'';document.getElementById('fp-ef').value=p.emergency_fund_months||3;document.getElementById('fp-ef-val').textContent=(p.emergency_fund_months||3)+' months';document.getElementById('fp-life').checked=!!p.has_life_insurance;document.getElementById('fp-health').checked=!!p.has_health_insurance;document.getElementById('fp-horizon').value=p.investment_horizon||'long';document.getElementById('fp-notes').value=p.notes||'';this.setRisk(p.risk_profile||'moderate');document.getElementById('fp-goals-list').innerHTML='';this._gid=0;for(const g of(p.goals||[]))this.addGoal(g);});},
setRisk(v){document.getElementById('fp-risk').value=v;document.querySelectorAll('.fp-risk-btn').forEach(b=>{b.classList.toggle('btn-primary',b.dataset.val===v);b.classList.toggle('btn-secondary',b.dataset.val!==v);});const d={'conservative':'🛡 Capital protection. FDs, debt funds, bonds.','moderate':'⚖️ Balanced mix of equity & debt.','moderately_aggressive':'📈 Growth-oriented. Mostly equity.','aggressive':'🚀 Maximum growth. High risk tolerance.'};document.getElementById('fp-risk-desc').textContent=d[v]||'';},
addGoal(g){const id=++this._gid;g=g||{};const div=document.createElement('div');div.id='fp-g-'+id;div.style.cssText='display:flex;gap:8px;align-items:center;margin-bottom:8px;';div.innerHTML=`<input type="text" class="form-control form-control-sm" placeholder="Goal name" value="${esc(g.name||'')}" data-gf="name" style="flex:2;"><input type="number" class="form-control form-control-sm" placeholder="Target ₹" value="${esc(g.target||'')}" data-gf="target" style="flex:1;" step="10000"><input type="number" class="form-control form-control-sm" placeholder="Year" value="${esc(g.year||'')}" data-gf="year" style="width:80px;" min="2024" max="2060"><button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('fp-g-${id}').remove()">✕</button>`;document.getElementById('fp-goals-list').appendChild(div);},
_goals(){const goals=[];document.querySelectorAll('#fp-goals-list > div').forEach(r=>{const g={};r.querySelectorAll('[data-gf]').forEach(el=>{g[el.dataset.gf]=el.value;});if(g.name)goals.push(g);});return goals;},
save(){apiPost({action:'fp_save',age:document.getElementById('fp-age').value,dependents:document.getElementById('fp-dep').value,employment_type:document.getElementById('fp-emp').value,annual_income:document.getElementById('fp-income').value,tax_slab:document.getElementById('fp-tax').value,monthly_expenses:document.getElementById('fp-exp').value,monthly_savings:document.getElementById('fp-sav').value,emergency_fund_months:document.getElementById('fp-ef').value,has_life_insurance:document.getElementById('fp-life').checked?1:0,has_health_insurance:document.getElementById('fp-health').checked?1:0,risk_profile:document.getElementById('fp-risk').value,investment_horizon:document.getElementById('fp-horizon').value,goals:JSON.stringify(this._goals()),income_sources:'[]',notes:document.getElementById('fp-notes').value}).then(r=>showToast(r.message,r.ok?'success':'error'));}};
document.addEventListener('DOMContentLoaded',()=>FP.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
