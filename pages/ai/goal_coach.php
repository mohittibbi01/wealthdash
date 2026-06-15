<?php
/**
 * WealthDash — t385: AI Goal Coach Page
 * File: pages/ai/goal_coach.php
 * Also usable as dashboard widget — see _widget render at bottom.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Goal Coach'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🧑‍🏫 AI Goal Coach</h1><p class="page-subtitle">Daily personalized nudges to keep you on track.</p></div>
  <button class="btn btn-ghost btn-sm" onclick="GC.refresh(true)">🔄 Refresh</button>
</div>

<div id="gc-nudges"></div>

<style>
.gc-nudge{display:flex;gap:14px;align-items:flex-start;background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:12px;}
.gc-nudge-icon{font-size:1.8rem;flex-shrink:0;}
.gc-nudge-msg{flex:1;font-size:14px;line-height:1.6;}
.gc-nudge.type-behind{border-left:4px solid #dc2626;}
.gc-nudge.type-milestone_close{border-left:4px solid #eab308;}
.gc-nudge.type-completed{border-left:4px solid #16a34a;}
.gc-nudge.type-checkin{border-left:4px solid #2563eb;}
.gc-nudge.type-all_good{border-left:4px solid #16a34a;}
.gc-nudge.type-stepup{border-left:4px solid #7c3aed;}
.gc-nudge.type-setup{border-left:4px solid #6b7280;}
</style>

<script>
const GC={
  refresh(force){
    document.getElementById('gc-nudges').innerHTML='<div style="text-align:center;padding:40px;color:var(--text-muted);">Loading nudges…</div>';
    apiPost({action:'ai_goal_coach_nudges',force:force?1:0}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      this._render(r.data.nudges||[]);
    });
  },
  _render(nudges){
    const wrap=document.getElementById('gc-nudges');
    if(!nudges.length){wrap.innerHTML='<div class="empty-state"><div class="empty-icon">🧑‍🏫</div><div>No nudges today!</div></div>';return;}
    wrap.innerHTML=nudges.map(n=>`
      <div class="gc-nudge type-${esc(n.type)}">
        <div class="gc-nudge-icon">${n.icon}</div>
        <div class="gc-nudge-msg">
          ${esc(n.message)}
          ${n.cta?`<div style="margin-top:8px;"><a href="${window.WD.appUrl}?page=goals_tracker${n.goal_id?'&goal_id='+n.goal_id:''}" class="btn btn-primary btn-sm">${esc(n.cta)} →</a></div>`:''}
        </div>
      </div>`).join('');
  }
};
document.addEventListener('DOMContentLoaded',()=>GC.refresh(false));
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
