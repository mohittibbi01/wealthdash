<?php
/**
 * WealthDash — t332: AI Goal Advisor Page
 * File: pages/ai/goal_advisor.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Goal Advisor'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🎯 AI Goal Advisor</h1><p class="page-subtitle">Smart path to your financial goals.</p></div>
  <button class="btn btn-primary" id="ga-btn" onclick="GA.run()">✨ Get Goal Advice</button>
</div>

<div id="ga-loading" style="display:none;text-align:center;padding:60px;"><div style="font-size:3rem;">🎯</div><div style="margin-top:12px;color:var(--text-muted);">Analysing your goals…</div></div>

<div id="ga-results" style="display:none;">
  <!-- Summary cards -->
  <div class="dashboard-grid" id="ga-cards" style="margin-bottom:20px;"></div>

  <!-- AI Narrative -->
  <div id="ga-narrative-card" class="card" style="margin-bottom:20px;display:none;">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;">
      <span class="card-title">🤖 AI Goal Analysis</span>
      <span id="ga-mode" class="badge"></span>
    </div>
    <div class="card-body"><div id="ga-narrative" style="font-size:14px;line-height:1.7;white-space:pre-wrap;"></div></div>
  </div>

  <!-- Goals list -->
  <div id="ga-goals-wrap"></div>
</div>

<div id="ga-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🎯</div><div>Click "Get Goal Advice" to analyse your financial goals.<br><small>Add goals from Goals section first.</small></div></div></div></div>

<style>
.ga-goal-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;}
.ga-goal-card.status-on_track{border-left:4px solid #16a34a;}
.ga-goal-card.status-behind{border-left:4px solid #dc2626;}
.ga-goal-card.status-slightly_behind{border-left:4px solid #eab308;}
.ga-goal-card.status-completed{border-left:4px solid #2563eb;}
.ga-progress-bar{height:8px;background:var(--bg-secondary);border-radius:4px;overflow:hidden;margin:8px 0;}
.ga-progress-fill{height:100%;border-radius:4px;transition:width .5s;}
</style>

<script>
const GA = {
  run() {
    document.getElementById('ga-btn').disabled = true;
    document.getElementById('ga-loading').style.display = '';
    document.getElementById('ga-results').style.display = 'none';
    document.getElementById('ga-empty').style.display = 'none';
    apiPost({action:'ai_goal_advice'}).then(r => {
      document.getElementById('ga-loading').style.display = 'none';
      document.getElementById('ga-btn').disabled = false;
      if (!r.ok) { showToast(r.message,'error'); document.getElementById('ga-empty').style.display=''; return; }
      this._render(r.data);
    }).catch(()=>{ document.getElementById('ga-loading').style.display='none'; document.getElementById('ga-btn').disabled=false; });
  },

  _statusLabel(s) {
    return { on_track:'✅ On Track', completed:'🏆 Completed', behind:'🔴 Behind', slightly_behind:'🟡 Slightly Behind' }[s] || s;
  },

  _render(d) {
    document.getElementById('ga-results').style.display = '';
    const sipGapClass = d.sip_gap > 0 ? 'wd-loss' : 'wd-gain';
    document.getElementById('ga-cards').innerHTML = `
      <div class="stat-card"><div class="stat-label">Total Goals</div><div class="stat-value wd-num-xl">${d.total_goals}</div><div class="stat-sub">${d.on_track} on track · ${d.behind} behind</div></div>
      <div class="stat-card"><div class="stat-label">Portfolio Value</div><div class="stat-value wd-num-xl">${formatINR(d.portfolio_value)}</div></div>
      <div class="stat-card"><div class="stat-label">Current Monthly SIP</div><div class="stat-value wd-num-xl">${formatINR(d.current_sip)}</div><div class="stat-sub">Needed for all goals: ${formatINR(d.total_sip_needed)}</div></div>
      <div class="stat-card"><div class="stat-label">SIP Gap</div><div class="stat-value wd-num-xl ${sipGapClass}">${d.sip_gap > 0 ? '+' : ''}${formatINR(d.sip_gap)}<span style="font-size:12px;font-weight:400;">/mo</span></div><div class="stat-sub">${d.sip_gap > 0 ? 'Need to invest more' : '✅ SIP covers all goals'}</div></div>`;

    // AI narrative
    if (d.ai_narrative) {
      document.getElementById('ga-narrative-card').style.display = '';
      document.getElementById('ga-narrative').textContent = d.ai_narrative;
      document.getElementById('ga-mode').textContent = d.mode === 'ai' ? '🤖 AI' : '📊 Rule-based';
    }

    // Goals
    const wrap = document.getElementById('ga-goals-wrap');
    if (!d.goals.length) {
      wrap.innerHTML = '<div class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🎯</div><div>No active goals found. Add goals from the Goals section.</div></div></div></div>';
      return;
    }

    wrap.innerHTML = d.goals.map(g => {
      const barColor = { on_track:'#16a34a', completed:'#2563eb', behind:'#dc2626', slightly_behind:'#eab308' }[g.status] || '#6b7280';
      const varClass = g.variance >= 0 ? 'wd-gain' : 'wd-loss';
      return `<div class="ga-goal-card status-${esc(g.status)}">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;">
          <div>
            <div style="font-weight:700;font-size:15px;">${esc(g.goal_name)}</div>
            <div style="font-size:12px;color:var(--text-muted);">Target: ${esc(g.target_date)} · ${g.months_left} months left</div>
          </div>
          <span class="badge" style="background:${barColor}20;color:${barColor};font-weight:700;">${this._statusLabel(g.status)}</span>
        </div>
        <div class="ga-progress-bar">
          <div class="ga-progress-fill" style="width:${g.progress_pct}%;background:${barColor};"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:12px;">
          <span>Invested: <strong>${formatINR(g.invested)}</strong></span>
          <span>Target: <strong>${formatINR(g.target_amount)}</strong></span>
          <span class="${varClass}"><strong>${g.progress_pct}% done</strong></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
          <div style="background:var(--bg-secondary);border-radius:8px;padding:8px;text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);">Time Progress</div>
            <div style="font-weight:700;">${g.time_progress}%</div>
          </div>
          <div style="background:var(--bg-secondary);border-radius:8px;padding:8px;text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);">Variance</div>
            <div style="font-weight:700;" class="${varClass}">${g.variance >= 0 ? '+' : ''}${g.variance}%</div>
          </div>
          <div style="background:var(--bg-secondary);border-radius:8px;padding:8px;text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);">SIP Needed</div>
            <div style="font-weight:700;">${formatINR(g.sip_needed)}/mo</div>
          </div>
        </div>
      </div>`;
    }).join('');
  }
};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
