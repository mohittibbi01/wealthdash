<?php
/**
 * WealthDash — t333: AI Portfolio Report Card Page
 * File: pages/ai/report_card.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Portfolio Report Card'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📋 AI Portfolio Report Card</h1><p class="page-subtitle">Monthly financial health grade — 0 to 100.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-ghost btn-sm" onclick="RC.loadHistory()">📅 History</button>
    <button class="btn btn-primary" id="rc-btn" onclick="RC.generate()">✨ Generate Report Card</button>
  </div>
</div>

<div id="rc-loading" style="display:none;text-align:center;padding:60px;"><div style="font-size:3rem;">📋</div><div style="margin-top:12px;color:var(--text-muted);">Grading your portfolio…</div></div>

<div id="rc-results" style="display:none;">
  <!-- Grade hero -->
  <div class="card" style="margin-bottom:20px;text-align:center;">
    <div class="card-body" style="padding:36px 20px;">
      <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Monthly Portfolio Grade — <span id="rc-month"></span></div>
      <div id="rc-grade-ring" style="display:inline-block;position:relative;width:160px;height:160px;margin-bottom:16px;">
        <canvas id="rc-canvas" width="160" height="160"></canvas>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
          <div id="rc-grade-val" style="font-size:52px;font-weight:900;line-height:1;"></div>
          <div id="rc-score-val" style="font-size:16px;font-weight:600;color:var(--text-muted);"></div>
        </div>
      </div>
      <div id="rc-summary" style="font-size:14px;max-width:500px;margin:0 auto 16px;line-height:1.6;"></div>
      <div id="rc-stats-row" style="display:flex;justify-content:center;gap:28px;flex-wrap:wrap;"></div>
      <div id="rc-mode-badge" style="margin-top:12px;"></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
    <!-- Score breakdown -->
    <div class="card">
      <div class="card-header"><span class="card-title">📊 Score Breakdown</span></div>
      <div class="card-body"><div id="rc-scores"></div></div>
    </div>

    <!-- Strengths & Weaknesses -->
    <div>
      <div class="card" style="margin-bottom:16px;">
        <div class="card-header"><span class="card-title">✅ Strengths</span></div>
        <div class="card-body"><div id="rc-strengths"></div></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">⚠️ Needs Improvement</span></div>
        <div class="card-body"><div id="rc-weaknesses"></div></div>
      </div>
    </div>
  </div>

  <!-- Action items -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><span class="card-title">🎯 Action Items</span></div>
    <div class="card-body p-0"><div id="rc-actions"></div></div>
  </div>
</div>

<div id="rc-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">📋</div><div>Click "Generate Report Card" to get your monthly portfolio grade.</div></div></div></div>

<!-- History Modal -->
<div id="rc-history-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header"><span class="modal-title">📅 Report Card History</span><button class="modal-close" onclick="document.getElementById('rc-history-modal').style.display='none'">×</button></div>
    <div class="modal-body" id="rc-history-body"></div>
  </div>
</div>

<script>
const RC = {
  generate(force=false) {
    document.getElementById('rc-btn').disabled = true;
    document.getElementById('rc-loading').style.display = '';
    document.getElementById('rc-results').style.display = 'none';
    document.getElementById('rc-empty').style.display = 'none';
    apiPost({action:'ai_report_card',force:force?1:0}).then(r => {
      document.getElementById('rc-loading').style.display = 'none';
      document.getElementById('rc-btn').disabled = false;
      if (!r.ok) { showToast(r.message,'error'); document.getElementById('rc-empty').style.display=''; return; }
      this._render(r.data);
    }).catch(()=>{document.getElementById('rc-loading').style.display='none'; document.getElementById('rc-btn').disabled=false;});
  },

  _gradeColor(g) {
    return {'A+':'#16a34a','A':'#22c55e','B+':'#84cc16','B':'#eab308','C+':'#f97316','C':'#ef4444','D':'#dc2626'}[g]||'#6b7280';
  },

  _render(d) {
    document.getElementById('rc-results').style.display = '';
    document.getElementById('rc-month').textContent = d.month || '';

    // Grade ring
    const canvas = document.getElementById('rc-canvas');
    const ctx = canvas.getContext('2d');
    const color = this._gradeColor(d.grade);
    ctx.clearRect(0,0,160,160);
    ctx.beginPath(); ctx.arc(80,80,65,0,Math.PI*2); ctx.strokeStyle='var(--border)'; ctx.lineWidth=12; ctx.stroke();
    const end = (-Math.PI/2)+(Math.PI*2*d.score/100);
    ctx.beginPath(); ctx.arc(80,80,65,-Math.PI/2,end); ctx.strokeStyle=color; ctx.lineWidth=12; ctx.lineCap='round'; ctx.stroke();
    document.getElementById('rc-grade-val').textContent = d.grade; document.getElementById('rc-grade-val').style.color = color;
    document.getElementById('rc-score-val').textContent = d.score + '/100';

    document.getElementById('rc-summary').textContent = d.summary || '';
    document.getElementById('rc-mode-badge').innerHTML = `<span class="badge ${d._mode==='ai'?'wd-gain':''}">${d._mode==='ai'?'🤖 AI Generated':'📊 Auto-generated'}</span>${d._cached?'<span class="badge" style="margin-left:8px;">📦 Cached</span>':''}`;

    // Stats
    const s = d.stats || {};
    document.getElementById('rc-stats-row').innerHTML = `
      <div style="text-align:center;"><div style="font-weight:800;font-size:18px;">${formatINR(s.total_value)}</div><div style="font-size:11px;color:var(--text-muted);">Portfolio</div></div>
      <div style="text-align:center;"><div style="font-weight:800;font-size:18px;" class="${s.gain_pct>=0?'wd-gain':'wd-loss'}">${s.gain_pct>=0?'+':''}${s.gain_pct}%</div><div style="font-size:11px;color:var(--text-muted);">Returns</div></div>
      <div style="text-align:center;"><div style="font-weight:800;font-size:18px;">${s.sips}</div><div style="font-size:11px;color:var(--text-muted);">SIPs</div></div>
      <div style="text-align:center;"><div style="font-weight:800;font-size:18px;">${s.goals}</div><div style="font-size:11px;color:var(--text-muted);">Goals</div></div>`;

    // Score breakdown
    let sh = '';
    for (const sc of (d.scores||[])) {
      const pct = Math.round(sc.score/sc.max*100);
      const barColor = pct>=70?'#16a34a':pct>=50?'#eab308':'#dc2626';
      sh += `<div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
          <span>${sc.icon} ${esc(sc.label)}</span>
          <span style="font-weight:700;color:${barColor};">${sc.score}/${sc.max}</span>
        </div>
        <div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;">
          <div style="width:${pct}%;height:100%;background:${barColor};border-radius:3px;"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${esc(sc.detail)}</div>
      </div>`;
    }
    document.getElementById('rc-scores').innerHTML = sh;

    // Strengths
    const str = d.strengths||[];
    document.getElementById('rc-strengths').innerHTML = str.length
      ? str.map(s=>`<div style="font-size:13px;padding:6px 0;border-bottom:1px solid var(--border);">✅ ${esc(s)}</div>`).join('')
      : '<div style="color:var(--text-muted);font-size:13px;">No strong areas yet — keep investing!</div>';

    // Weaknesses
    const wk = d.weaknesses||[];
    document.getElementById('rc-weaknesses').innerHTML = wk.length
      ? wk.map(w=>`<div style="font-size:13px;padding:6px 0;border-bottom:1px solid var(--border);">⚠️ ${esc(w)}</div>`).join('')
      : '<div style="color:var(--gain);font-size:13px;">No major weaknesses found! 🎉</div>';

    // Actions
    const acts = d.actions||[];
    if (!acts.length) { document.getElementById('rc-actions').innerHTML='<div class="empty-state" style="padding:20px;"><div>No action items — great job!</div></div>'; }
    else {
      const priColors = {high:'var(--loss)',medium:'var(--accent)',low:'var(--text-muted)'};
      document.getElementById('rc-actions').innerHTML = `<table class="data-table"><thead><tr><th>Priority</th><th>Action</th><th>Why</th></tr></thead><tbody>` +
        acts.map(a=>`<tr><td style="font-weight:700;color:${priColors[a.priority]||'var(--accent)'};text-transform:capitalize;">${esc(a.priority)}</td><td style="font-size:13px;">${esc(a.action)}</td><td style="font-size:12px;color:var(--text-muted);">${esc(a.reason||'')}</td></tr>`).join('') + '</tbody></table>';
    }
  },

  loadHistory() {
    apiPost({action:'ai_report_card_history'}).then(r=>{
      const modal = document.getElementById('rc-history-modal');
      const body  = document.getElementById('rc-history-body');
      const rows  = r.data?.history||[];
      if(!rows.length){body.innerHTML='<div class="empty-state"><div>No past report cards.</div></div>';}
      else {
        body.innerHTML=rows.map(h=>{
          const gc=this._gradeColor(h.grade);
          return `<div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid var(--border);">
            <div style="width:44px;height:44px;border-radius:50%;background:${gc}20;border:2px solid ${gc};display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;color:${gc};">${esc(h.grade)}</div>
            <div><div style="font-weight:600;">${esc(h.review_month)} — ${h.score}/100</div><div style="font-size:12px;color:var(--text-muted);">${esc(h.summary?.substring(0,80))}…</div></div>
          </div>`;
        }).join('');
      }
      modal.style.display='';
    });
  }
};

document.addEventListener('DOMContentLoaded', ()=>RC.generate());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
