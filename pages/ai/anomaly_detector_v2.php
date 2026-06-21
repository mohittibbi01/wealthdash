<?php
/**
 * WealthDash — t384: AI Anomaly Detector v2 Page
 * File: pages/ai/anomaly_detector_v2.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Anomaly Detector v2'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🛡 AI Anomaly Detector v2</h1><p class="page-subtitle">Detects SIP gaps, large redemptions, duplicates, and outlier transactions.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-ghost btn-sm" onclick="AD2.toggleResolved()">📋 <span id="ad2-toggle-label">View Resolved</span></button>
    <button class="btn btn-primary" onclick="AD2.scan()">🔍 Scan Now</button>
  </div>
</div>

<div id="ad2-loading" style="display:none;text-align:center;padding:40px;"><div style="font-size:2.5rem;">🔍</div><div style="color:var(--text-muted);margin-top:8px;">Scanning 180-day history…</div></div>

<div id="ad2-narrative-wrap" class="card" style="margin-bottom:16px;display:none;">
  <div class="card-header"><span class="card-title">🤖 AI Summary</span></div>
  <div class="card-body"><div id="ad2-narrative" style="font-size:13px;line-height:1.7;"></div></div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title" id="ad2-list-title">⚠️ Active Anomalies</span></div>
  <div class="card-body p-0"><div id="ad2-table"></div></div>
</div>

<style>
.ad2-badge-outlier_amount{background:#fef3c7;color:#92400e;}
.ad2-badge-duplicate_txn{background:#fee2e2;color:#991b1b;}
.ad2-badge-sip_gap{background:#dbeafe;color:#1e40af;}
.ad2-badge-large_redemption{background:#f3e8ff;color:#6b21a8;}
</style>

<script>
const AD2={_showResolved:0,
init(){this.load();},
scan(){
  document.getElementById('ad2-loading').style.display='';
  apiPost({action:'ai_anomaly_v2_scan'}).then(r=>{
    document.getElementById('ad2-loading').style.display='none';
    if(!r.ok){showToast(r.message,'error');return;}
    showToast(`Scan done: ${r.data.found_count} anomalies found (${r.data.new_saved} new)`,'success');
    if(r.data.narrative){document.getElementById('ad2-narrative-wrap').style.display='';document.getElementById('ad2-narrative').textContent=r.data.narrative;}
    this.load();
  });
},
toggleResolved(){
  this._showResolved=this._showResolved?0:1;
  document.getElementById('ad2-toggle-label').textContent=this._showResolved?'View Active':'View Resolved';
  document.getElementById('ad2-list-title').textContent=this._showResolved?'✅ Resolved Anomalies':'⚠️ Active Anomalies';
  this.load();
},
load(){
  apiPost({action:'ai_anomaly_v2_list',resolved:this._showResolved}).then(r=>{
    if(!r.ok)return;
    const rows=r.data.anomalies||[];
    const wrap=document.getElementById('ad2-table');
    if(!rows.length){wrap.innerHTML=`<div class="empty-state" style="padding:40px;"><div style="font-size:2rem;">${this._showResolved?'📋':'✅'}</div><div style="margin-top:8px;">${this._showResolved?'No resolved anomalies.':'No active anomalies! Click Scan Now to check.'}</div></div>`;return;}
    const typeLabels={outlier_amount:'💰 Large Amount',duplicate_txn:'📑 Duplicate',sip_gap:'⏸ SIP Gap',large_redemption:'⬇️ Big Redemption'};
    let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Type</th><th>Date</th><th>Fund</th><th class="text-right">Amount</th><th>Reason</th><th>Severity</th>${this._showResolved?'':'<th></th>'}</tr></thead><tbody>`;
    for(const a of rows){
      const sevCls=a.severity==='high'?'wd-loss':'';
      html+=`<tr><td><span class="badge ad2-badge-${a.anomaly_type}">${typeLabels[a.anomaly_type]||a.anomaly_type}</span></td><td class="wd-num" style="font-size:12px;">${esc(a.txn_date||'—')}</td><td style="font-size:12px;">${esc(a.fund_name||'—')}</td><td class="text-right wd-num">${formatINR(a.amount)}</td><td style="font-size:12px;">${esc(a.reason)}</td><td class="${sevCls}" style="font-weight:700;text-transform:capitalize;">${esc(a.severity)}</td>${this._showResolved?'':`<td><button class="btn btn-ghost btn-sm" onclick="AD2.resolve(${a.id})">✓ Resolve</button></td>`}</tr>`;
    }
    html+='</tbody></table></div>';
    if(!this._showResolved&&rows.length>1){html+=`<div style="padding:12px 16px;"><button class="btn btn-ghost btn-sm" onclick="AD2.resolveAll()">✓ Mark All Resolved</button></div>`;}
    wrap.innerHTML=html;
  });
},
resolve(id){apiPost({action:'ai_anomaly_v2_resolve',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});},
resolveAll(){if(!confirm('Mark all anomalies as resolved?'))return;apiPost({action:'ai_anomaly_v2_resolve',all:1}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});}
};
document.addEventListener('DOMContentLoaded',()=>AD2.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
