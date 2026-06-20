<?php
/**
 * WealthDash — t61: AI Goal-based Planning Page
 * File: pages/ai/goal_planning.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Goal Planner'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📐 AI Goal Planner</h1><p class="page-subtitle">Optimal SIP allocation across multiple financial goals.</p></div>
  <button class="btn btn-primary" onclick="GP.openCreate()">+ New Goal Plan</button>
</div>

<div class="dashboard-grid" id="gp-cards" style="margin-bottom:20px;"></div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">📋 Your Goal Plans</span></div>
  <div class="card-body p-0"><div id="gp-table"></div></div>
</div>

<!-- Optimizer -->
<div class="card">
  <div class="card-header"><span class="card-title">⚡ SIP Allocation Optimizer</span></div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Enter your available monthly surplus and get an AI-optimized SIP split across all goals based on priority and time horizon.</p>
    <div style="display:flex;gap:10px;align-items:flex-end;">
      <div class="form-group" style="margin-bottom:0;flex:1;"><label class="form-label">Monthly Surplus (₹)</label><input type="number" id="gp-surplus" class="form-control" step="500" placeholder="e.g. 25000"></div>
      <button class="btn btn-primary" onclick="GP.optimize()">Optimize Allocation</button>
    </div>
    <div id="gp-optimize-result" style="margin-top:20px;"></div>
  </div>
</div>

<!-- Create Goal Plan Modal -->
<div id="gp-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)GP.closeCreate()">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><span class="modal-title">+ New Goal Plan</span><button class="modal-close" onclick="GP.closeCreate()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Goal Name *</label><input type="text" id="gp-f-name" class="form-control" placeholder="e.g. Child Education, Retirement"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Target Amount (₹) *</label><input type="number" id="gp-f-target" class="form-control" step="10000"></div>
        <div class="form-group"><label class="form-label">Target Date *</label><input type="date" id="gp-f-date" class="form-control"></div>
      </div>
      <div class="form-group"><label class="form-label">Priority</label>
        <select id="gp-f-priority" class="form-control"><option value="high">🔴 High</option><option value="medium" selected>🟡 Medium</option><option value="low">🟢 Low</option></select>
      </div>
      <div class="form-group"><label class="form-label">Existing Corpus (₹)</label><input type="number" id="gp-f-corpus" class="form-control" step="1000" value="0"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="GP.closeCreate()">Cancel</button><button class="btn btn-primary" onclick="GP.save()">Create Plan</button></div>
  </div>
</div>

<script>
const GP={
  init(){this.load();},
  load(){
    apiPost({action:'ai_goal_plan_list'}).then(r=>{
      if(!r.ok)return;const goals=r.data.goals||[];
      document.getElementById('gp-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Total Goals</div><div class="stat-value wd-num-xl">${goals.length}</div></div><div class="stat-card"><div class="stat-label">Total Required SIP</div><div class="stat-value wd-num-xl">${formatINR(r.data.total_required_sip)}<span style="font-size:12px;font-weight:400;">/mo</span></div></div>`;
      const wrap=document.getElementById('gp-table');
      if(!goals.length){wrap.innerHTML='<div class="empty-state" style="padding:30px;"><div class="empty-icon">📐</div><div>No goal plans yet. Click "+ New Goal Plan".</div></div>';return;}
      const pc={high:'#dc2626',medium:'#eab308',low:'#16a34a'};
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Goal</th><th>Priority</th><th class="text-right">Target</th><th class="text-right">Invested</th><th>Progress</th><th class="text-right">Required SIP</th><th>Months Left</th></tr></thead><tbody>`;
      for(const g of goals){html+=`<tr><td style="font-weight:600;">${esc(g.goal_name)}</td><td><select onchange="GP.changePriority(${g.id},this.value)" style="border:none;background:${pc[g.priority]}20;color:${pc[g.priority]};font-weight:700;padding:2px 6px;border-radius:6px;font-size:12px;"><option value="high" ${g.priority==='high'?'selected':''}>High</option><option value="medium" ${g.priority==='medium'?'selected':''}>Medium</option><option value="low" ${g.priority==='low'?'selected':''}>Low</option></select></td><td class="text-right wd-num">${formatINR(g.target_amount)}</td><td class="text-right wd-num">${formatINR(g.invested)}</td><td style="min-width:100px;"><div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;"><div style="width:${g.progress_pct}%;height:100%;background:var(--gain);"></div></div><div style="font-size:11px;color:var(--text-muted);">${g.progress_pct}%</div></td><td class="text-right wd-num" style="font-weight:700;">${formatINR(g.required_sip)}</td><td>${g.months_left}mo</td></tr>`;}
      html+='</tbody></table></div>';wrap.innerHTML=html;
    });
  },
  changePriority(goalId,priority){apiPost({action:'ai_goal_priority_simulate',goal_id:goalId,priority}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});},
  optimize(){
    const surplus=document.getElementById('gp-surplus').value;
    if(!surplus||surplus<=0){showToast('Enter a valid surplus amount','warning');return;}
    apiPost({action:'ai_goal_plan_optimize',monthly_surplus:surplus}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      const d=r.data;
      let html=`<div class="alert ${d.fully_covered?'alert-success':'alert-warning'}" style="margin-bottom:14px;">${d.fully_covered?'✅ Your surplus fully covers all goal requirements!':'⚠️ Surplus insufficient — proportional allocation shown below based on priority.'}</div>`;
      html+=`<table class="data-table"><thead><tr><th>Goal</th><th class="text-right">Allocated SIP</th><th>Status</th></tr></thead><tbody>`;
      for(const a of d.allocation){html+=`<tr><td>${esc(a.goal_name)}</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(a.allocated)}/mo</td><td>${a.fully_funded?'<span class="badge wd-gain">✓ Fully funded</span>':`<span class="badge wd-loss">Shortfall: ${formatINR(a.shortfall||0)}</span>`}</td></tr>`;}
      html+='</tbody></table>';
      document.getElementById('gp-optimize-result').innerHTML=html;
    });
  },
  openCreate(){document.getElementById('gp-f-name').value='';document.getElementById('gp-f-target').value='';document.getElementById('gp-f-date').value='';document.getElementById('gp-f-corpus').value='0';document.getElementById('gp-modal').style.display='';},
  closeCreate(){document.getElementById('gp-modal').style.display='none';},
  save(){apiPost({action:'ai_goal_plan_create',goal_name:document.getElementById('gp-f-name').value,target_amount:document.getElementById('gp-f-target').value,target_date:document.getElementById('gp-f-date').value,priority:document.getElementById('gp-f-priority').value,existing_corpus:document.getElementById('gp-f-corpus').value}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeCreate();this.load();}});}
};
document.addEventListener('DOMContentLoaded',()=>GP.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
