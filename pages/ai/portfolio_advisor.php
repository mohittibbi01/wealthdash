<?php
/**
 * WealthDash — t58: AI Portfolio Advisor Page
 * File: pages/ai/portfolio_advisor.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Portfolio Advisor'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🧠 AI Portfolio Advisor</h1><p class="page-subtitle">Your comprehensive AI financial advisor — full holistic review.</p></div>
  <button class="btn btn-primary" id="ad-btn" onclick="AD.generate()">✨ Get Full Review</button>
</div>

<div id="ad-loading" style="display:none;text-align:center;padding:60px;"><div style="font-size:3rem;">🧠</div><div style="margin-top:12px;color:var(--text-muted);">Analysing your complete financial picture…</div></div>

<div id="ad-results" style="display:none;">
  <!-- Health score hero -->
  <div class="card" style="margin-bottom:20px;text-align:center;">
    <div class="card-body" style="padding:28px;">
      <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Financial Health Score</div>
      <div id="ad-score" style="font-size:48px;font-weight:900;"></div>
      <div id="ad-verdict" style="font-size:15px;font-weight:600;margin-top:8px;"></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
    <div class="card"><div class="card-header"><span class="card-title">✅ Strengths</span></div><div class="card-body" id="ad-strengths"></div></div>
    <div class="card"><div class="card-header"><span class="card-title">⚠️ Concerns</span></div><div class="card-body" id="ad-concerns"></div></div>
  </div>

  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><span class="card-title">🛡 Insurance Assessment</span></div>
    <div class="card-body" id="ad-insurance"></div>
  </div>

  <div class="card" style="margin-bottom:20px;">
    <div class="card-header"><span class="card-title">🎯 Action Plan (Next 30 Days)</span></div>
    <div class="card-body p-0" id="ad-actions"></div>
  </div>

  <div class="card" style="margin-bottom:20px;border-left:4px solid var(--accent);">
    <div class="card-body">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">📅 LONG-TERM STRATEGY</div>
      <div id="ad-strategy" style="font-size:14px;line-height:1.6;"></div>
    </div>
  </div>

  <!-- Follow-up Q&A -->
  <div class="card">
    <div class="card-header"><span class="card-title">💬 Ask a Follow-up Question</span></div>
    <div class="card-body">
      <div id="ad-qa-history" style="margin-bottom:14px;"></div>
      <div style="display:flex;gap:10px;">
        <input type="text" id="ad-question" class="form-control" placeholder="e.g. Should I increase my SIP amount?" onkeydown="if(event.key==='Enter')AD.ask()">
        <button class="btn btn-primary" onclick="AD.ask()">Ask</button>
      </div>
    </div>
  </div>

  <div id="ad-mode-badge" style="text-align:center;margin-top:16px;"></div>
</div>

<div id="ad-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🧠</div><div>Click "Get Full Review" for a complete AI-powered financial health check.</div></div></div></div>

<script>
const AD={
generate(){
  document.getElementById('ad-btn').disabled=true;
  document.getElementById('ad-loading').style.display='';
  document.getElementById('ad-results').style.display='none';
  document.getElementById('ad-empty').style.display='none';
  apiPost({action:'ai_advisor_full_review'}).then(r=>{
    document.getElementById('ad-loading').style.display='none';
    document.getElementById('ad-btn').disabled=false;
    if(!r.ok){showToast(r.message,'error');document.getElementById('ad-empty').style.display='';return;}
    this._render(r.data);
  }).catch(()=>{document.getElementById('ad-loading').style.display='none';document.getElementById('ad-btn').disabled=false;});
},
_render(d){
  document.getElementById('ad-results').style.display='';
  const sc=d.health_score>=70?'#16a34a':d.health_score>=50?'#eab308':'#dc2626';
  document.getElementById('ad-score').textContent=d.health_score;
  document.getElementById('ad-score').style.color=sc;
  document.getElementById('ad-verdict').textContent=d.verdict;
  document.getElementById('ad-strengths').innerHTML=(d.strengths||[]).map(s=>`<div style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">✅ ${esc(s)}</div>`).join('')||'<div style="color:var(--text-muted);font-size:13px;">None identified yet.</div>';
  document.getElementById('ad-concerns').innerHTML=(d.concerns||[]).map(c=>`<div style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">⚠️ ${esc(c)}</div>`).join('')||'<div style="color:var(--gain);font-size:13px;">No major concerns!</div>';
  document.getElementById('ad-insurance').innerHTML=`<div style="font-size:14px;">${esc(d.insurance_comment)}</div>`;
  const acts=d.action_items||[];
  const pc={high:'var(--loss)',medium:'var(--accent)',low:'var(--text-muted)'};
  document.getElementById('ad-actions').innerHTML=acts.length?`<table class="data-table"><thead><tr><th>Priority</th><th>Action</th><th>Reason</th></tr></thead><tbody>${acts.map(a=>`<tr><td style="font-weight:700;color:${pc[a.priority]||'var(--accent)'};text-transform:capitalize;">${esc(a.priority)}</td><td style="font-size:13px;">${esc(a.action)}</td><td style="font-size:12px;color:var(--text-muted);">${esc(a.reason||'')}</td></tr>`).join('')}</tbody></table>`:'<div class="empty-state" style="padding:20px;"><div>No urgent actions.</div></div>';
  document.getElementById('ad-strategy').textContent=d.strategic_suggestion;
  document.getElementById('ad-mode-badge').innerHTML=`<span class="badge ${d.mode==='ai'?'wd-gain':''}">${d.mode==='ai'?'🤖 Claude AI':'📊 Rule-based'}</span>`;
},
ask(){
  const q=document.getElementById('ad-question').value.trim();
  if(!q)return;
  document.getElementById('ad-question').value='';
  const hist=document.getElementById('ad-qa-history');
  hist.innerHTML+=`<div style="margin-bottom:10px;"><div style="font-weight:600;font-size:13px;">🙋 ${esc(q)}</div><div id="ad-qa-loading" style="font-size:13px;color:var(--text-muted);">Thinking…</div></div>`;
  apiPost({action:'ai_advisor_ask',question:q}).then(r=>{
    const loadingEl=document.getElementById('ad-qa-loading');
    if(loadingEl)loadingEl.outerHTML=`<div style="font-size:13px;line-height:1.6;padding:8px 12px;background:var(--bg-secondary);border-radius:8px;">🤖 ${esc(r.ok?r.data.answer:r.message)}</div>`;
  });
}
};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
