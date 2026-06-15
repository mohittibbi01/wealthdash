<?php
/**
 * WealthDash — t360: Life Events Calendar Page
 * File: pages/life_events.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Life Events Calendar'; $activePage='tools'; $activeSection='life_events';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🗓 Life Events Calendar</h1><p class="page-subtitle">Track financial milestones — marriage, home purchase, retirement, etc.</p></div>
  <button class="btn btn-primary" onclick="LE.openAdd()">+ Add Event</button>
</div>
<div id="le-timeline" style="margin-bottom:24px;"></div>

<!-- Add/Edit Modal -->
<div id="le-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)LE.close()">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header"><span class="modal-title" id="le-modal-title">Add Life Event</span><button class="modal-close" onclick="LE.close()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="le-id">
      <div class="form-group"><label class="form-label">Event Name *</label><input type="text" id="le-name" class="form-control" placeholder="Marriage / House Purchase / First SIP…"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Type</label>
          <select id="le-type" class="form-control">
            <option value="milestone">💰 Financial Milestone</option>
            <option value="personal">👤 Personal Event</option>
            <option value="career">💼 Career</option>
            <option value="family">👨‍👩‍👧 Family</option>
            <option value="goal">🎯 Goal Achieved</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Date *</label><input type="date" id="le-date" class="form-control"></div>
      </div>
      <div class="form-group"><label class="form-label">Financial Impact</label><input type="text" id="le-impact" class="form-control" placeholder="e.g. ₹50L down payment, Started ₹10K SIP"></div>
      <div class="form-group"><label class="form-label">Notes</label><textarea id="le-notes" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="LE.close()">Cancel</button><button class="btn btn-primary" onclick="LE.save()">Save</button></div>
  </div>
</div>

<style>
.le-timeline{position:relative;padding-left:32px;}
.le-timeline::before{content:'';position:absolute;left:10px;top:0;bottom:0;width:2px;background:var(--border);}
.le-item{position:relative;margin-bottom:20px;}
.le-dot{position:absolute;left:-28px;width:18px;height:18px;border-radius:50%;border:3px solid var(--bg-surface);top:4px;display:flex;align-items:center;justify-content:center;font-size:10px;}
.le-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;padding:14px 16px;}
.le-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;}
.le-card-title{font-weight:700;font-size:14px;}
.le-card-meta{font-size:12px;color:var(--text-muted);}
.le-card-impact{font-size:12px;color:var(--accent);margin-top:4px;}
.type-milestone{background:#4f46e5;}.type-personal{background:#059669;}.type-career{background:#d97706;}.type-family{background:#ec4899;}.type-goal{background:#eab308;}
</style>

<script>
const LE={
  init(){this.load();},
  load(){
    apiPost({action:'life_events_list'}).then(r=>{
      if(!r.ok)return;
      const events=r.data.events||[];
      const wrap=document.getElementById('le-timeline');
      if(!events.length){wrap.innerHTML='<div class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🗓</div><div>No life events yet. Add your first financial milestone!</div></div></div></div>';return;}
      const typeColors={milestone:'#4f46e5',personal:'#059669',career:'#d97706',family:'#ec4899',goal:'#eab308'};
      let html='<div class="le-timeline">';
      for(const e of events){
        const color=typeColors[e.event_type]||'#6b7280';
        const future=new Date(e.event_date)>new Date();
        html+=`<div class="le-item">
          <div class="le-dot type-${e.event_type}" style="background:${color};"></div>
          <div class="le-card" style="${future?'border-style:dashed;opacity:.7;':''}">
            <div class="le-card-header">
              <div>
                <div class="le-card-title">${esc(e.event_name)} ${future?'<span class="badge" style="font-size:10px;">Upcoming</span>':''}</div>
                <div class="le-card-meta">${esc(e.event_date)} · <span style="text-transform:capitalize;">${esc(e.event_type)}</span></div>
              </div>
              <div style="display:flex;gap:6px;">
                <button class="btn btn-ghost btn-sm" onclick="LE.edit(${e.id})">✏️</button>
                <button class="btn btn-danger btn-sm" onclick="LE.del(${e.id})">✕</button>
              </div>
            </div>
            ${e.financial_impact?`<div class="le-card-impact">💰 ${esc(e.financial_impact)}</div>`:''}
            ${e.notes?`<div style="font-size:12px;color:var(--text-muted);margin-top:4px;">${esc(e.notes)}</div>`:''}
          </div>
        </div>`;
      }
      html+='</div>';
      wrap.innerHTML=html;
    });
  },
  openAdd(){document.getElementById('le-id').value='';document.getElementById('le-name').value='';document.getElementById('le-date').value='';document.getElementById('le-impact').value='';document.getElementById('le-notes').value='';document.getElementById('le-modal-title').textContent='Add Life Event';document.getElementById('le-modal').style.display='';},
  close(){document.getElementById('le-modal').style.display='none';},
  edit(id){apiPost({action:'life_events_list'}).then(r=>{const e=(r.data.events||[]).find(x=>x.id==id);if(!e)return;document.getElementById('le-id').value=e.id;document.getElementById('le-name').value=e.event_name;document.getElementById('le-type').value=e.event_type;document.getElementById('le-date').value=e.event_date;document.getElementById('le-impact').value=e.financial_impact||'';document.getElementById('le-notes').value=e.notes||'';document.getElementById('le-modal-title').textContent='Edit Event';document.getElementById('le-modal').style.display='';});},
  save(){const id=document.getElementById('le-id').value;const data={action:id?'life_event_update':'life_event_add',event_name:document.getElementById('le-name').value,event_type:document.getElementById('le-type').value,event_date:document.getElementById('le-date').value,financial_impact:document.getElementById('le-impact').value,notes:document.getElementById('le-notes').value};if(id)data.id=id;apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.close();this.load();}});},
  del(id){if(!confirm('Delete this event?'))return;apiPost({action:'life_event_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.load();});}
};
document.addEventListener('DOMContentLoaded',()=>LE.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
