<?php
/**
 * WealthDash — t244: AI Portfolio Narrative Page
 * File: pages/ai/portfolio_narrative.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Portfolio Narrative'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📝 AI Portfolio Narrative</h1><p class="page-subtitle">Monthly natural language summary of your portfolio performance.</p></div>
  <div style="display:flex;gap:8px;align-items:center;">
    <input type="month" id="pn-month" class="form-control" style="width:160px;" value="<?= date('Y-m') ?>">
    <button class="btn btn-primary" onclick="PN.generate()">✨ Generate</button>
  </div>
</div>
<div id="pn-loading" style="display:none;text-align:center;padding:60px 20px;">
  <div style="font-size:3rem;animation:spin 1s linear infinite;display:inline-block;">📝</div>
  <div style="margin-top:12px;color:var(--text-muted);">Writing your portfolio story…</div>
</div>
<div id="pn-results" style="display:none;">
  <div class="dashboard-grid" id="pn-cards" style="margin-bottom:20px;"></div>
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;">
      <span class="card-title">📄 Monthly Summary</span>
      <span id="pn-mode" class="badge"></span>
      <span id="pn-month-label" style="margin-left:auto;font-size:12px;color:var(--text-muted);"></span>
    </div>
    <div class="card-body">
      <div id="pn-narrative" style="font-size:15px;line-height:1.8;color:var(--text);"></div>
      <div style="margin-top:20px;display:flex;gap:10px;">
        <button class="btn btn-secondary btn-sm" onclick="PN.copy()">📋 Copy</button>
        <button class="btn btn-ghost btn-sm" onclick="PN.generate()">🔄 Regenerate</button>
      </div>
    </div>
  </div>
</div>
<div id="pn-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">📝</div><div>Select a month and click Generate to get your portfolio story.</div></div></div></div>
<style>@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}</style>
<script>
const PN={
generate(){
  document.getElementById('pn-loading').style.display='';document.getElementById('pn-results').style.display='none';document.getElementById('pn-empty').style.display='none';
  apiPost({action:'ai_portfolio_narrative',month:document.getElementById('pn-month').value}).then(r=>{
    document.getElementById('pn-loading').style.display='none';
    if(!r.ok){showToast(r.message,'error');document.getElementById('pn-empty').style.display='';return;}
    const d=r.data;
    document.getElementById('pn-results').style.display='';
    document.getElementById('pn-month-label').textContent=d.month;
    document.getElementById('pn-mode').textContent=d.mode==='ai'?'🤖 Claude AI':'📊 Auto-generated';
    document.getElementById('pn-cards').innerHTML=`
      <div class="stat-card"><div class="stat-label">Portfolio Value</div><div class="stat-value wd-num-xl">${formatINR(d.stats.total_value)}</div></div>
      <div class="stat-card"><div class="stat-label">Total Gain</div><div class="stat-value wd-num-xl ${d.stats.total_gain>=0?'wd-gain':'wd-loss'}">${d.stats.total_gain>=0?'+':''}${formatINR(d.stats.total_gain)}</div><div class="stat-sub">${d.stats.gain_pct}%</div></div>
      <div class="stat-card"><div class="stat-label">Total Invested</div><div class="stat-value wd-num-xl">${formatINR(d.stats.total_invested)}</div></div>`;
    document.getElementById('pn-narrative').textContent=d.narrative;
  });
},
copy(){const t=document.getElementById('pn-narrative').textContent;navigator.clipboard.writeText(t).then(()=>showToast('Copied!','success'));}
};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
