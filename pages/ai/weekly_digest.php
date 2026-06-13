<?php
/**
 * WealthDash — t329: AI Weekly Digest Page
 * File: pages/ai/weekly_digest.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Weekly Digest'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📰 AI Weekly Digest</h1><p class="page-subtitle">Your personalized financial news + portfolio update — every week.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-ghost btn-sm" onclick="WD_Digest.history()">📋 History</button>
    <button class="btn btn-primary" id="digest-btn" onclick="WD_Digest.generate()">✨ Generate This Week's Digest</button>
  </div>
</div>

<div id="dg-loading" style="display:none;text-align:center;padding:60px;">
  <div style="font-size:3rem;margin-bottom:12px;">📰</div>
  <div style="color:var(--text-muted);">Generating your weekly digest…</div>
</div>

<div id="dg-results" style="display:none;">
  <!-- Headline card -->
  <div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,var(--accent) 0%,#7c3aed 100%);border:none;">
    <div class="card-body" style="padding:24px;">
      <div style="font-size:11px;color:rgba(255,255,255,.7);margin-bottom:6px;text-transform:uppercase;letter-spacing:1px;">Weekly Digest</div>
      <div id="dg-headline" style="font-size:20px;font-weight:800;color:#fff;margin-bottom:12px;"></div>
      <div id="dg-portfolio-update" style="font-size:14px;color:rgba(255,255,255,.85);line-height:1.6;"></div>
      <div style="display:flex;gap:20px;margin-top:16px;flex-wrap:wrap;" id="dg-stats-row"></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
    <!-- Market highlights -->
    <div class="card">
      <div class="card-header"><span class="card-title">📡 Market Highlights</span></div>
      <div class="card-body"><div id="dg-highlights"></div></div>
    </div>

    <!-- Action items -->
    <div class="card">
      <div class="card-header"><span class="card-title">✅ This Week's Actions</span></div>
      <div class="card-body"><div id="dg-actions"></div></div>
    </div>
  </div>

  <!-- Motivation -->
  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--accent);">
    <div class="card-body" style="padding:16px 20px;">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">💡 MOTIVATION OF THE WEEK</div>
      <div id="dg-motivation" style="font-size:15px;font-weight:600;font-style:italic;"></div>
    </div>
  </div>

  <div style="text-align:center;margin-top:16px;display:flex;justify-content:center;gap:10px;">
    <button class="btn btn-ghost btn-sm" id="dg-mode-badge"></button>
    <button class="btn btn-secondary btn-sm" onclick="WD_Digest.generate(true)">🔄 Regenerate</button>
  </div>
</div>

<div id="dg-empty" class="card">
  <div class="card-body"><div class="empty-state"><div class="empty-icon">📰</div><div>Click "Generate This Week's Digest" to get your personalized update.</div></div></div>
</div>

<!-- History Modal -->
<div id="dg-history-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><span class="modal-title">📋 Past Digests</span><button class="modal-close" onclick="document.getElementById('dg-history-modal').style.display='none'">×</button></div>
    <div class="modal-body" id="dg-history-body"></div>
  </div>
</div>

<script>
const WD_Digest = {
  generate(force=false) {
    document.getElementById('dg-loading').style.display='';
    document.getElementById('dg-results').style.display='none';
    document.getElementById('dg-empty').style.display='none';
    document.getElementById('digest-btn').disabled=true;
    apiPost({action:'ai_weekly_digest', force:force?1:0}).then(r=>{
      document.getElementById('dg-loading').style.display='none';
      document.getElementById('digest-btn').disabled=false;
      if(!r.ok){showToast(r.message,'error');document.getElementById('dg-empty').style.display='';return;}
      this._render(r.data);
    }).catch(()=>{document.getElementById('dg-loading').style.display='none';document.getElementById('digest-btn').disabled=false;showToast('Error generating','error');});
  },
  _render(d) {
    document.getElementById('dg-results').style.display='';
    document.getElementById('dg-headline').textContent = d.headline || '';
    document.getElementById('dg-portfolio-update').textContent = d.portfolio_update || '';
    document.getElementById('dg-motivation').textContent = d.motivation || '';

    // Stats row
    const s = d.stats || {};
    document.getElementById('dg-stats-row').innerHTML = `
      <div style="text-align:center;"><div style="font-size:20px;font-weight:800;color:#fff;">${formatINR(s.total_value)}</div><div style="font-size:11px;color:rgba(255,255,255,.6);">Portfolio</div></div>
      <div style="text-align:center;"><div style="font-size:20px;font-weight:800;color:${s.gain_pct>=0?'#86efac':'#fca5a5'}">${s.gain_pct>=0?'+':''}${s.gain_pct}%</div><div style="font-size:11px;color:rgba(255,255,255,.6);">Total Return</div></div>
      <div style="text-align:center;"><div style="font-size:20px;font-weight:800;color:#fff;">${s.active_sips}</div><div style="font-size:11px;color:rgba(255,255,255,.6);">Active SIPs</div></div>
      <div style="text-align:center;"><div style="font-size:20px;font-weight:800;color:#fff;">${s.txn_count}</div><div style="font-size:11px;color:rgba(255,255,255,.6);">Txns This Week</div></div>`;

    // Market highlights
    const hl = d.market_highlights || [];
    document.getElementById('dg-highlights').innerHTML = hl.map(h =>
      `<div style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;"><span>📌</span><span>${esc(h)}</span></div>`
    ).join('');

    // Action items
    const ai = d.action_items || [];
    const priColors = {high:'var(--loss)',medium:'var(--accent)',low:'var(--text-muted)'};
    document.getElementById('dg-actions').innerHTML = ai.map(a =>
      `<div style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border);">
        <span style="width:8px;height:8px;border-radius:50%;background:${priColors[a.priority]||'var(--accent)'};flex-shrink:0;margin-top:5px;"></span>
        <span style="font-size:13px;">${esc(a.item)}</span>
      </div>`
    ).join('');

    // Mode badge
    document.getElementById('dg-mode-badge').textContent = d._mode==='ai' ? '🤖 AI Generated' : '📊 Auto-generated';
    document.getElementById('dg-mode-badge').className = d._mode==='ai' ? 'btn btn-ghost btn-sm wd-gain' : 'btn btn-ghost btn-sm';
  },
  history() {
    apiPost({action:'ai_digest_history'}).then(r=>{
      const modal = document.getElementById('dg-history-modal');
      const body  = document.getElementById('dg-history-body');
      const rows  = r.data?.history || [];
      if(!rows.length){body.innerHTML='<div class="empty-state"><div>No past digests yet.</div></div>';}
      else{body.innerHTML=rows.map(h=>`
        <div style="padding:12px 0;border-bottom:1px solid var(--border);">
          <div style="font-weight:600;font-size:14px;">${esc(h.headline)}</div>
          <div style="font-size:12px;color:var(--text-muted);">Week ${esc(h.week_key)} · ${esc(h.created_at?.substring(0,10))}</div>
          ${h.stats?.total_value?`<div style="font-size:12px;margin-top:4px;">Portfolio: ${formatINR(h.stats.total_value)} · ${h.stats.gain_pct>=0?'+':''}${h.stats.gain_pct}%</div>`:''}
        </div>`).join('');}
      modal.style.display='';
    });
  }
};
document.addEventListener('DOMContentLoaded', ()=>WD_Digest.generate());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
