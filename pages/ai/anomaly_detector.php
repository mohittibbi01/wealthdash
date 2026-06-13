<?php
/**
 * WealthDash — t246: AI Anomaly Detector Page
 * File: pages/ai/anomaly_detector.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Anomaly Detector'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🔍 AI Anomaly Detector</h1><p class="page-subtitle">Unusual transaction patterns flagged automatically.</p></div>
  <button class="btn btn-primary" onclick="AD.run()">🔍 Scan Transactions</button>
</div>
<div id="ad-loading" style="display:none;text-align:center;padding:60px;"><div style="font-size:3rem;">🔍</div><div style="margin-top:12px;color:var(--text-muted);">Scanning transactions…</div></div>
<div id="ad-results" style="display:none;">
  <div class="dashboard-grid" id="ad-cards" style="margin-bottom:20px;"></div>
  <div id="ad-ai-wrap" class="card" style="margin-bottom:20px;display:none;">
    <div class="card-header"><span class="card-title">🤖 AI Analysis</span></div>
    <div class="card-body"><div id="ad-narrative" style="font-size:14px;line-height:1.7;"></div></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">⚠️ Flagged Transactions</span></div>
    <div class="card-body p-0"><div id="ad-table"></div></div>
  </div>
</div>
<div id="ad-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🔍</div><div>Click "Scan Transactions" to check your last 90 days for anomalies.</div></div></div></div>
<script>
const AD={
run(){document.getElementById('ad-loading').style.display='';document.getElementById('ad-results').style.display='none';document.getElementById('ad-empty').style.display='none';
  apiPost({action:'ai_anomaly_detect'}).then(r=>{document.getElementById('ad-loading').style.display='none';if(!r.ok){showToast(r.message,'error');return;}const d=r.data;document.getElementById('ad-results').style.display='';
  document.getElementById('ad-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Transactions Scanned</div><div class="stat-value wd-num-xl">${d.txn_count}</div><div class="stat-sub">${d.period}</div></div><div class="stat-card"><div class="stat-label">Anomalies Found</div><div class="stat-value wd-num-xl ${d.anomalies.length?'wd-loss':'wd-gain'}">${d.anomalies.length}</div></div>`;
  if(d.narrative){document.getElementById('ad-ai-wrap').style.display='';document.getElementById('ad-narrative').textContent=d.narrative;}
  const wrap=document.getElementById('ad-table');
  if(!d.anomalies.length){wrap.innerHTML='<div class="empty-state" style="padding:40px;"><div style="font-size:2rem;">✅</div><div style="margin-top:8px;">No anomalies detected!</div></div>';return;}
  let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Fund</th><th>Type</th><th class="text-right">Amount</th><th>Reason</th><th>Severity</th></tr></thead><tbody>`;
  for(const a of d.anomalies){const sc=a.severity==='high'?'wd-loss':'';html+=`<tr><td class="wd-num">${esc(a.date)}</td><td style="font-size:12px;">${esc(a.fund||'—')}</td><td>${esc(a.type)}</td><td class="text-right wd-num">${formatINR(a.amount)}</td><td style="font-size:12px;">${esc(a.reason)}</td><td class="${sc}" style="font-weight:700;text-transform:capitalize;">${esc(a.severity)}</td></tr>`;}
  html+='</tbody></table></div>';wrap.innerHTML=html;});
}};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
