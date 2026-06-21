<?php
/**
 * WealthDash — t242: Investor Streak & Milestones Page
 * File: pages/gamification/streaks.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Investor Achievements'; $activePage='gamification'; $activeSection='gamification';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🏆 Investor Achievements</h1><p class="page-subtitle">Track your investing streak, milestones, and badges.</p></div>

<!-- Streak hero -->
<div class="card" style="margin-bottom:20px;text-align:center;background:linear-gradient(135deg,#f59e0b 0%,#ea580c 100%);border:none;">
  <div class="card-body" style="padding:32px;">
    <div id="st-streak-flame" style="font-size:4rem;margin-bottom:8px;">🔥</div>
    <div id="st-streak-count" style="font-size:42px;font-weight:900;color:#fff;"></div>
    <div style="color:rgba(255,255,255,.9);font-size:14px;">Month Investing Streak</div>
    <div id="st-streak-longest" style="color:rgba(255,255,255,.7);font-size:12px;margin-top:6px;"></div>
  </div>
</div>

<!-- Net worth milestone -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">💰 Wealth Milestones</span></div>
  <div class="card-body">
    <div id="st-milestone-track" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;"></div>
    <div id="st-next-milestone" style="font-size:13px;color:var(--text-muted);"></div>
  </div>
</div>

<!-- Badges -->
<div class="card">
  <div class="card-header"><span class="card-title">🎖 Badges</span> <span id="st-badge-count" style="margin-left:8px;font-size:12px;color:var(--text-muted);"></span></div>
  <div class="card-body"><div id="st-badges-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;"></div></div>
</div>

<style>
.st-milestone-pill{padding:8px 14px;border-radius:20px;font-size:13px;font-weight:600;border:2px solid var(--border);}
.st-milestone-pill.achieved{background:#16a34a20;border-color:#16a34a;color:#16a34a;}
.st-badge{text-align:center;padding:16px 10px;border-radius:12px;border:2px solid var(--border);}
.st-badge.earned{border-color:#f59e0b;background:#f59e0b10;}
.st-badge.locked{opacity:.4;}
.st-badge-icon{font-size:2rem;margin-bottom:6px;}
.st-badge-label{font-size:11px;font-weight:700;}
.st-badge-desc{font-size:10px;color:var(--text-muted);margin-top:2px;}
</style>

<script>
const ST={
  init(){
    apiPost({action:'streak_status'}).then(r=>{
      if(!r.ok)return;const d=r.data;
      document.getElementById('st-streak-count').textContent=d.current_streak;
      document.getElementById('st-streak-longest').textContent=`Longest streak: ${d.longest_streak} months`;
      if(d.new_badges&&d.new_badges.length){d.new_badges.forEach(b=>showToast(`🎉 New badge: ${b.label}!`,'success'));}
    });
    apiPost({action:'milestones_list'}).then(r=>{
      if(!r.ok)return;const d=r.data;
      document.getElementById('st-milestone-track').innerHTML=d.milestones.map(m=>`<span class="st-milestone-pill ${m.achieved?'achieved':''}">${m.achieved?'✅':'⬜'} ${esc(m.label)}</span>`).join('');
      document.getElementById('st-next-milestone').textContent=d.next_milestone?`Next: ${d.next_milestone.label} — ${d.progress_to_next}% there (Current: ${formatINR(d.portfolio_value)})`:'🎉 All milestones achieved!';
    });
    apiPost({action:'badges_list'}).then(r=>{
      if(!r.ok)return;const d=r.data;
      document.getElementById('st-badge-count').textContent=`${d.earned_count}/${d.total_count} earned`;
      document.getElementById('st-badges-grid').innerHTML=d.badges.map(b=>`<div class="st-badge ${b.earned?'earned':'locked'}"><div class="st-badge-icon">${b.icon}</div><div class="st-badge-label">${esc(b.label)}</div><div class="st-badge-desc">${esc(b.desc)}</div>${b.earned_at?`<div style="font-size:9px;color:var(--text-muted);margin-top:4px;">${esc(b.earned_at.substring(0,10))}</div>`:''}</div>`).join('');
    });
  }
};
document.addEventListener('DOMContentLoaded',()=>ST.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
