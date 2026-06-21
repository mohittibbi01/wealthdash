<?php
/**
 * WealthDash — t150: Documents Vault Page
 * File: pages/documents/digilocker.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='My Documents'; $activePage='documents'; $activeSection='documents';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📁 My Documents</h1><p class="page-subtitle">All your financial documents in one secure place.</p></div>
  <button class="btn btn-primary" onclick="DL.openUpload()">+ Upload Document</button>
</div>

<!-- DigiLocker connect banner -->
<div id="dl-connect-banner" class="card" style="margin-bottom:20px;display:none;">
  <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div style="font-size:2rem;">🔗</div>
    <div style="flex:1;">
      <div style="font-weight:700;font-size:14px;">Connect DigiLocker</div>
      <div id="dl-connect-note" style="font-size:12px;color:var(--text-muted);"></div>
    </div>
    <button class="btn btn-secondary btn-sm" disabled>Coming Soon</button>
  </div>
</div>

<!-- Category filter -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;" id="dl-cat-filters"></div>

<!-- Documents grid -->
<div id="dl-docs-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;"></div>

<!-- Upload Modal -->
<div id="dl-upload-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)DL.closeUpload()">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header"><span class="modal-title">+ Upload Document</span><button class="modal-close" onclick="DL.closeUpload()">×</button></div>
    <div class="modal-body">
      <form id="dl-upload-form" enctype="multipart/form-data">
        <div class="form-group"><label class="form-label">Document Type</label><select id="dl-f-cat" class="form-control"></select></div>
        <div class="form-group"><label class="form-label">Document Name</label><input type="text" id="dl-f-name" class="form-control" placeholder="e.g. PAN Card, LIC Policy 2024"></div>
        <div class="form-group"><label class="form-label">File (PDF, JPG, PNG — max 5MB)</label><input type="file" id="dl-f-file" class="form-control" accept=".pdf,.jpg,.jpeg,.png"></div>
        <div class="form-group"><label class="form-label">Expiry Date (optional)</label><input type="date" id="dl-f-expiry" class="form-control"></div>
      </form>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="DL.closeUpload()">Cancel</button><button class="btn btn-primary" onclick="DL.upload()">Upload</button></div>
  </div>
</div>

<style>
.dl-doc-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center;position:relative;}
.dl-doc-icon{font-size:2.2rem;margin-bottom:8px;}
.dl-doc-name{font-weight:600;font-size:13px;margin-bottom:4px;word-break:break-word;}
.dl-doc-meta{font-size:11px;color:var(--text-muted);}
.dl-doc-actions{margin-top:10px;display:flex;gap:6px;justify-content:center;}
.dl-expiry-badge{position:absolute;top:8px;right:8px;background:#dc2626;color:#fff;font-size:10px;padding:2px 6px;border-radius:6px;}
</style>

<script>
const DL={_cats:{},_activeCat:'',

init(){
  apiPost({action:'doc_categories'}).then(r=>{
    this._cats=r.data?.categories||{};
    document.getElementById('dl-f-cat').innerHTML=Object.entries(this._cats).map(([k,v])=>`<option value="${k}">${v.icon} ${v.label}</option>`).join('');
    let filterHtml='<button class="btn btn-sm btn-primary" onclick="DL.filterCat(\'\')">All</button>';
    for(const[k,v] of Object.entries(this._cats)){filterHtml+=`<button class="btn btn-sm btn-ghost" onclick="DL.filterCat('${k}')">${v.icon} ${v.label}</button>`;}
    document.getElementById('dl-cat-filters').innerHTML=filterHtml;
    this.load();
  });
  apiPost({action:'digilocker_connect_status'}).then(r=>{
    if(r.ok&&!r.data.connected){document.getElementById('dl-connect-banner').style.display='';document.getElementById('dl-connect-note').textContent=r.data.note;}
  });
},

filterCat(cat){
  this._activeCat=cat;
  document.querySelectorAll('#dl-cat-filters button').forEach(b=>b.classList.replace('btn-primary','btn-ghost'));
  event.target.classList.replace('btn-ghost','btn-primary');
  this.load();
},

load(){
  apiPost({action:'doc_list',category:this._activeCat}).then(r=>{
    if(!r.ok)return;
    const docs=r.data.documents||[];
    const wrap=document.getElementById('dl-docs-grid');
    if(!docs.length){wrap.innerHTML='<div class="empty-state" style="grid-column:1/-1;padding:40px;"><div class="empty-icon">📁</div><div>No documents uploaded yet.</div></div>';return;}
    wrap.innerHTML=docs.map(d=>`<div class="dl-doc-card">
      ${d.expiring_soon?'<span class="dl-expiry-badge">Expiring soon</span>':''}
      <div class="dl-doc-icon">${d.icon}</div>
      <div class="dl-doc-name">${esc(d.doc_name)}</div>
      <div class="dl-doc-meta">${esc(d.label)} · ${d.file_size_kb}KB</div>
      <div class="dl-doc-meta">${esc(d.uploaded_at?.substring(0,10))}</div>
      <div class="dl-doc-actions"><button class="btn btn-danger btn-sm" onclick="DL.del(${d.id})">🗑 Delete</button></div>
    </div>`).join('');
  });
},

openUpload(){document.getElementById('dl-upload-form').reset();document.getElementById('dl-upload-modal').style.display='';},
closeUpload(){document.getElementById('dl-upload-modal').style.display='none';},

upload(){
  const fileInput=document.getElementById('dl-f-file');
  if(!fileInput.files.length){showToast('Select a file','warning');return;}
  const fd=new FormData();
  fd.append('action','doc_upload');
  fd.append('category',document.getElementById('dl-f-cat').value);
  fd.append('doc_name',document.getElementById('dl-f-name').value);
  fd.append('expiry_date',document.getElementById('dl-f-expiry').value);
  fd.append('document',fileInput.files[0]);
  fd.append(window.CSRF_TOKEN,'1');
  fetch(window.WD.appUrl+'/api/router.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':window.WD.csrf}})
    .then(r=>r.json()).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeUpload();this.load();}});
},

del(id){if(!confirm('Delete this document?'))return;apiPost({action:'doc_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});}
};
document.addEventListener('DOMContentLoaded',()=>DL.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
