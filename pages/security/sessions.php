<?php
/**
 * WealthDash — t387: Session Security Page
 * File: pages/security/sessions.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Active Sessions'; $activePage='settings'; $activeSection='security';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🖥 Active Sessions</h1><p class="page-subtitle">Manage devices logged into your account.</p></div>
  <button class="btn btn-danger btn-sm" onclick="SS.revokeAll()">🚫 Log Out All Other Devices</button>
</div>

<!-- Session timeout info -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div style="font-size:2rem;">⏱</div>
    <div>
      <div style="font-weight:700;font-size:14px;">Session Timeout</div>
      <div id="ss-timeout-info" style="font-size:13px;color:var(--text-muted);">Loading…</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Logged-in Devices</span></div>
  <div class="card-body p-0"><div id="ss-table"></div></div>
</div>

<script>
const SS={
  init(){this.loadCurrent();this.load();},
  loadCurrent(){
    apiPost({action:'session_current'}).then(r=>{
      if(!r.ok)return;
      const mins=Math.floor(r.data.remaining_seconds/60);
      document.getElementById('ss-timeout-info').textContent=`Your session expires after ${Math.floor(r.data.timeout_seconds/60)} minutes of inactivity. ~${mins} minutes remaining.`;
    });
  },
  load(){
    apiPost({action:'sessions_list'}).then(r=>{
      if(!r.ok)return;
      const rows=r.data.sessions||[];
      const wrap=document.getElementById('ss-table');
      if(!rows.length){wrap.innerHTML='<div class="empty-state" style="padding:30px;"><div>No session data yet.</div></div>';return;}
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Device</th><th>IP Address</th><th>First Login</th><th>Last Active</th><th></th></tr></thead><tbody>`;
      for(const s of rows){
        html+=`<tr ${s.is_current?'style="background:var(--bg-secondary);"':''}>
          <td style="font-weight:600;font-size:13px;">${esc(s.device_label||'Unknown')} ${s.is_current?'<span class="badge wd-gain" style="margin-left:6px;">This device</span>':''}</td>
          <td style="font-family:monospace;font-size:12px;">${esc(s.ip_masked||'—')}</td>
          <td style="font-size:12px;">${esc(s.created_at?.substring(0,16))}</td>
          <td style="font-size:12px;">${esc(s.last_active?.substring(0,16))}</td>
          <td>${s.is_current?'':`<button class="btn btn-danger btn-sm" onclick="SS.revoke(${s.id})">Revoke</button>`}</td>
        </tr>`;
      }
      html+='</tbody></table></div>';
      wrap.innerHTML=html;
    });
  },
  revoke(id){if(!confirm('Log out this device?'))return;apiPost({action:'session_revoke',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});},
  revokeAll(){if(!confirm('Log out from ALL other devices? You will remain logged in here.'))return;apiPost({action:'session_revoke_all'}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});}
};
document.addEventListener('DOMContentLoaded',()=>{SS.init();apiPost({action:'session_touch'});});
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
