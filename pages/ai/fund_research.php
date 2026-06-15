<?php
/**
 * WealthDash — t382: AI Fund Research Page
 * File: pages/ai/fund_research.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Fund Research'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🔬 AI Fund Research</h1><p class="page-subtitle">Ask about any mutual fund, category, or comparison in natural language.</p></div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-body">
    <div style="display:flex;gap:10px;">
      <input type="text" id="fr-query" class="form-control" placeholder="e.g. 'Tell me about Parag Parikh Flexi Cap' or 'ELSS vs PPF for tax saving'" onkeydown="if(event.key==='Enter')FR.search()">
      <button class="btn btn-primary" onclick="FR.search()">🔍 Research</button>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
      <?php foreach([
          "What is a Flexi Cap fund?",
          "ELSS vs PPF for tax saving",
          "Small cap vs Large cap risk",
          "Is gold fund good for me?",
          "What is an index fund?"
      ] as $q): ?>
        <button class="btn btn-ghost btn-sm" style="font-size:11px;" onclick="document.getElementById('fr-query').value='<?= htmlspecialchars($q, ENT_QUOTES) ?>';FR.search();"><?= htmlspecialchars($q) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div id="fr-loading" style="display:none;text-align:center;padding:40px;"><div style="font-size:2.5rem;">🔬</div><div style="color:var(--text-muted);margin-top:8px;">Researching…</div></div>

<div id="fr-results" style="display:none;">
  <div id="fr-own-holdings" style="margin-bottom:16px;display:none;" class="alert alert-info"></div>
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;"><span class="card-title">📋 Research Result</span><span id="fr-mode" class="badge"></span></div>
    <div class="card-body"><div id="fr-reply" style="font-size:14px;line-height:1.8;white-space:pre-wrap;"></div></div>
  </div>
</div>

<div id="fr-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🔬</div><div>Ask anything about mutual funds — categories, comparisons, or specific schemes.</div></div></div></div>

<script>
const FR={
  search(){
    const q=document.getElementById('fr-query').value.trim();
    if(!q)return;
    document.getElementById('fr-loading').style.display='';
    document.getElementById('fr-results').style.display='none';
    document.getElementById('fr-empty').style.display='none';
    apiPost({action:'ai_fund_research',query:q}).then(r=>{
      document.getElementById('fr-loading').style.display='none';
      if(!r.ok){showToast(r.message,'error');document.getElementById('fr-empty').style.display='';return;}
      const d=r.data;
      document.getElementById('fr-results').style.display='';
      document.getElementById('fr-reply').textContent=d.reply;
      document.getElementById('fr-mode').textContent=d.mode==='ai'?'🤖 Claude AI':'📊 Rule-based';
      const ownWrap=document.getElementById('fr-own-holdings');
      if(d.own_holdings&&d.own_holdings.length){
        ownWrap.style.display='';
        ownWrap.innerHTML='<strong>📌 Aapke Portfolio Mein:</strong><br>'+d.own_holdings.map(h=>`${esc(h.fund_name)}: ${formatINR(h.current_value)}`).join('<br>');
      }else{ownWrap.style.display='none';}
    }).catch(()=>{document.getElementById('fr-loading').style.display='none';showToast('Error','error');});
  }
};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
