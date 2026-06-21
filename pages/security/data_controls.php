<?php
/**
 * WealthDash — t389: GDPR-style Data Controls Page
 * File: pages/security/data_controls.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Data & Privacy'; $activePage='settings'; $activeSection='security';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🔒 Data & Privacy</h1><p class="page-subtitle">Export your data or request account deletion — GDPR compliant.</p></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="responsive-grid-1col">

  <!-- Export -->
  <div class="card">
    <div class="card-header"><span class="card-title">📦 Export Your Data</span></div>
    <div class="card-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Download all your data — holdings, transactions, goals, chat history — in JSON format. Available for 7 days after generation.</p>
      <button class="btn btn-primary" id="dc-export-btn" onclick="DC.requestExport()">📥 Generate Export</button>
      <div id="dc-exports-list" style="margin-top:16px;"></div>
    </div>
  </div>

  <!-- Delete -->
  <div class="card">
    <div class="card-header"><span class="card-title">⚠️ Delete Account</span></div>
    <div class="card-body">
      <div id="dc-delete-status"></div>
      <div id="dc-delete-form">
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">Account deletion has a 14-day grace period — you can cancel anytime during this window. After 14 days, all your data will be permanently removed.</p>
        <div class="form-group">
          <label class="form-label">Type "DELETE MY ACCOUNT" to confirm</label>
          <input type="text" id="dc-confirm-text" class="form-control" placeholder="DELETE MY ACCOUNT">
        </div>
        <button class="btn btn-danger" onclick="DC.requestDelete()">🗑 Request Account Deletion</button>
      </div>
    </div>
  </div>
</div>

<script>
const DC={
  init(){this.loadExports();this.loadDeleteStatus();},
  requestExport(){
    document.getElementById('dc-export-btn').disabled=true;
    document.getElementById('dc-export-btn').textContent='Generating…';
    apiPost({action:'data_export_request'}).then(r=>{
      document.getElementById('dc-export-btn').disabled=false;
      document.getElementById('dc-export-btn').textContent='📥 Generate Export';
      showToast(r.message,r.ok?'success':'error');
      if(r.ok)this.loadExports();
    });
  },
  loadExports(){
    apiPost({action:'data_export_list'}).then(r=>{
      if(!r.ok)return;
      const rows=r.data.exports||[];
      const wrap=document.getElementById('dc-exports-list');
      if(!rows.length){wrap.innerHTML='';return;}
      let html='<div style="font-size:12px;font-weight:700;margin-bottom:8px;">Recent Exports:</div>';
      for(const e of rows){
        html+=`<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px;">
          <div><div>${esc(e.created_at?.substring(0,16))}</div><div style="color:var(--text-muted);">${(e.file_size/1024).toFixed(1)} KB · expires ${esc(e.expires_at?.substring(0,10))}</div></div>
          <a href="${window.WD.appUrl}/api/router.php?action=data_export_download&id=${e.id}" class="btn btn-ghost btn-sm">⬇ Download</a>
        </div>`;
      }
      wrap.innerHTML=html;
    });
  },
  loadDeleteStatus(){
    apiPost({action:'data_delete_status'}).then(r=>{
      if(!r.ok)return;
      const d=r.data;
      if(d.pending){
        document.getElementById('dc-delete-form').style.display='none';
        document.getElementById('dc-delete-status').innerHTML=`
          <div class="alert alert-warning">
            <strong>⚠️ Deletion Scheduled</strong><br>
            Your account will be deleted on <strong>${esc(d.scheduled_for?.substring(0,10))}</strong> (${d.days_left} days left).<br>
            <button class="btn btn-secondary btn-sm" style="margin-top:8px;" onclick="DC.cancelDelete()">↩ Cancel Deletion</button>
          </div>`;
      }
    });
  },
  requestDelete(){
    const confirmText=document.getElementById('dc-confirm-text').value;
    if(confirmText!=='DELETE MY ACCOUNT'){showToast('Please type exactly: DELETE MY ACCOUNT','error');return;}
    if(!confirm('Are you absolutely sure? This will schedule your account for permanent deletion in 14 days.'))return;
    apiPost({action:'data_delete_request',confirm_text:confirmText}).then(r=>{
      showToast(r.message,r.ok?'success':'error');
      if(r.ok)this.loadDeleteStatus();
    });
  },
  cancelDelete(){
    apiPost({action:'data_delete_cancel'}).then(r=>{
      showToast(r.message,r.ok?'success':'error');
      if(r.ok){document.getElementById('dc-delete-status').innerHTML='';document.getElementById('dc-delete-form').style.display='';}
    });
  }
};
document.addEventListener('DOMContentLoaded',()=>DC.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
