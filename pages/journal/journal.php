<?php
/**
 * WealthDash — th001: Daily Financial Journal Page
 * File: pages/journal/journal.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Financial Journal'; $activePage='journal'; $activeSection='journal';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📔 Financial Journal</h1><p class="page-subtitle">Record your investment thoughts, decisions, and market emotions.</p></div>
  <button class="btn btn-primary" onclick="JL.openAdd()">+ New Entry</button>
</div>

<div class="dashboard-grid" id="jl-stats-cards" style="margin-bottom:20px;"></div>

<!-- Search -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="display:flex;gap:10px;">
    <input type="text" id="jl-search" class="form-control" placeholder="Search your journal entries…" onkeydown="if(event.key==='Enter')JL.search()">
    <button class="btn btn-secondary btn-sm" onclick="JL.search()">🔍</button>
    <button class="btn btn-ghost btn-sm" onclick="JL.clearSearch()">✕ Clear</button>
  </div>
</div>

<!-- Entries timeline -->
<div id="jl-entries"></div>
<div id="jl-pagination" style="display:flex;justify-content:center;gap:8px;margin-top:16px;"></div>

<!-- Add/Edit Modal -->
<div id="jl-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)JL.closeModal()">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><span class="modal-title" id="jl-modal-title">New Journal Entry</span><button class="modal-close" onclick="JL.closeModal()">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="jl-edit-id">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Date</label><input type="date" id="jl-f-date" class="form-control"></div>
        <div class="form-group"><label class="form-label">Mood</label>
          <select id="jl-f-mood" class="form-control">
            <option value="confident">😎 Confident</option><option value="optimistic">🙂 Optimistic</option>
            <option value="neutral" selected>😐 Neutral</option><option value="anxious">😟 Anxious</option>
            <option value="fearful">😨 Fearful</option><option value="excited">🤩 Excited</option><option value="regretful">😔 Regretful</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Title</label><input type="text" id="jl-f-title" class="form-control" placeholder="e.g. Market dip thoughts, New SIP decision"></div>
      <div class="form-group"><label class="form-label">Journal Entry *</label><textarea id="jl-f-content" class="form-control" rows="6" placeholder="What's on your mind about your investments today?"></textarea></div>
      <div class="form-group"><label class="form-label">Related Action</label>
        <select id="jl-f-related" class="form-control"><option value="">None</option><option value="bought_fund">Bought a fund</option><option value="redeemed">Redeemed</option><option value="market_event">Market event reaction</option><option value="goal_review">Goal review</option><option value="general_thought">General thought</option></select>
      </div>
      <div class="form-group"><label class="form-label">Tags (comma-separated)</label><input type="text" id="jl-f-tags" class="form-control" placeholder="e.g. sip, market-crash, lessons-learned"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="JL.closeModal()">Cancel</button><button class="btn btn-primary" onclick="JL.save()">Save Entry</button></div>
  </div>
</div>

<style>
.jl-entry{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;}
.jl-entry-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;}
.jl-entry-title{font-weight:700;font-size:15px;}
.jl-entry-date{font-size:12px;color:var(--text-muted);}
.jl-entry-content{font-size:14px;line-height:1.6;white-space:pre-wrap;margin:10px 0;}
.jl-tag{display:inline-block;background:var(--bg-secondary);color:var(--text-muted);font-size:11px;padding:2px 8px;border-radius:10px;margin-right:6px;}
</style>

<script>
const JL={_page:1,_search:'',

init(){this.loadStats();this.load();},

loadStats(){
  apiPost({action:'journal_stats'}).then(r=>{
    if(!r.ok)return;const d=r.data;
    document.getElementById('jl-stats-cards').innerHTML=`
      <div class="stat-card"><div class="stat-label">Total Entries</div><div class="stat-value wd-num-xl">${d.total_entries}</div></div>
      <div class="stat-card"><div class="stat-label">This Month</div><div class="stat-value wd-num-xl">${d.this_month}</div></div>
      <div class="stat-card"><div class="stat-label">Journaling Streak</div><div class="stat-value wd-num-xl">${d.streak_days} 🔥</div></div>`;
  });
},

load(page=1){
  this._page=page;
  apiPost({action:'journal_list',page,month:''}).then(r=>{
    if(!r.ok)return;
    this._renderEntries(r.data.entries);
    let pg='';
    for(let i=1;i<=r.data.total_pages;i++){pg+=`<button class="btn btn-sm ${i===this._page?'btn-primary':'btn-ghost'}" onclick="JL.load(${i})">${i}</button>`;}
    document.getElementById('jl-pagination').innerHTML=pg;
  });
},

search(){
  const q=document.getElementById('jl-search').value.trim();
  if(!q)return;
  apiPost({action:'journal_search',q}).then(r=>{if(r.ok)this._renderEntries(r.data.entries);document.getElementById('jl-pagination').innerHTML='';});
},
clearSearch(){document.getElementById('jl-search').value='';this.load(1);},

_renderEntries(entries){
  const wrap=document.getElementById('jl-entries');
  if(!entries.length){wrap.innerHTML='<div class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">📔</div><div>No journal entries yet. Start writing!</div></div></div></div>';return;}
  wrap.innerHTML=entries.map(e=>`
    <div class="jl-entry">
      <div class="jl-entry-header">
        <div><div class="jl-entry-title">${e.mood_emoji} ${esc(e.title)}</div><div class="jl-entry-date">${esc(e.entry_date)}${e.related_action?' · '+esc(e.related_action.replace('_',' ')):''}</div></div>
        <div><button class="btn btn-ghost btn-sm" onclick="JL.edit(${e.id})">✏️</button><button class="btn btn-danger btn-sm" onclick="JL.del(${e.id})">✕</button></div>
      </div>
      <div class="jl-entry-content">${esc(e.content)}</div>
      ${e.tags.length?e.tags.map(t=>`<span class="jl-tag">#${esc(t.trim())}</span>`).join(''):''}
    </div>`).join('');
},

_clearForm(){document.getElementById('jl-f-title').value='';document.getElementById('jl-f-content').value='';document.getElementById('jl-f-tags').value='';document.getElementById('jl-f-related').value='';document.getElementById('jl-f-mood').value='neutral';document.getElementById('jl-f-date').value=new Date().toISOString().substring(0,10);document.getElementById('jl-edit-id').value='';},
openAdd(){this._clearForm();document.getElementById('jl-modal-title').textContent='New Journal Entry';document.getElementById('jl-modal').style.display='';},
closeModal(){document.getElementById('jl-modal').style.display='none';},

edit(id){
  apiPost({action:'journal_list',page:1}).then(r=>{
    let e=(r.data.entries||[]).find(x=>x.id==id);
    if(!e){apiPost({action:'journal_search',q:''}).then(()=>{});return;}
    document.getElementById('jl-edit-id').value=id;
    document.getElementById('jl-f-date').value=e.entry_date;document.getElementById('jl-f-title').value=e.title;document.getElementById('jl-f-content').value=e.content;document.getElementById('jl-f-mood').value=e.mood;document.getElementById('jl-f-tags').value=e.tags.join(', ');document.getElementById('jl-f-related').value=e.related_action||'';
    document.getElementById('jl-modal-title').textContent='Edit Entry';
    document.getElementById('jl-modal').style.display='';
  });
},

save(){
  const id=document.getElementById('jl-edit-id').value;
  const data={action:id?'journal_update':'journal_add',entry_date:document.getElementById('jl-f-date').value,title:document.getElementById('jl-f-title').value,content:document.getElementById('jl-f-content').value,mood:document.getElementById('jl-f-mood').value,tags:document.getElementById('jl-f-tags').value,related_action:document.getElementById('jl-f-related').value};
  if(id)data.id=id;
  apiPost(data).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeModal();this.load(this._page);this.loadStats();}});
},

del(id){if(!confirm('Delete this journal entry?'))return;apiPost({action:'journal_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.load(this._page);this.loadStats();}});}
};
document.addEventListener('DOMContentLoaded',()=>JL.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
