<?php
/**
 * WealthDash — t297: Customizable Dashboard Page
 * File: pages/dashboard/custom_dashboard.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='My Dashboard'; $activePage='dashboard'; $activeSection='dashboard';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📊 My Dashboard</h1><p class="page-subtitle">Drag widgets to customize your layout.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-secondary btn-sm" id="cd-edit-btn" onclick="CD.toggleEdit()">✏️ Edit Layout</button>
    <button class="btn btn-ghost btn-sm" onclick="CD.reset()">↩ Reset</button>
  </div>
</div>

<!-- Widget picker (edit mode) -->
<div id="cd-widget-picker" style="display:none;margin-bottom:16px;" class="card">
  <div class="card-body">
    <div style="font-weight:700;font-size:13px;margin-bottom:12px;">Available Widgets — click to toggle visibility</div>
    <div id="cd-picker-list" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
  </div>
</div>

<!-- Dashboard grid -->
<div id="cd-grid" class="cd-grid"></div>

<style>
.cd-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
@media(max-width:900px){.cd-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.cd-grid{grid-template-columns:1fr;}}
.cd-widget{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:120px;transition:border-color .2s;}
.cd-widget.dragging{opacity:.5;border:2px dashed var(--accent);}
.cd-widget.drag-over{border:2px dashed var(--gain);background:var(--bg-secondary);}
.cd-widget[data-edit="true"]{cursor:grab;border-style:dashed;}
.cd-widget-header{display:flex;align-items:center;gap:8px;margin-bottom:10px;font-weight:700;font-size:13px;}
.cd-widget-icon{font-size:1.4rem;}
.cd-widget-content{font-size:13px;color:var(--text-muted);}
.cd-handle{cursor:grab;font-size:18px;margin-left:auto;opacity:.4;}
</style>

<script>
const CD = {
  _editing: false,
  _layout: [],
  _widgets: {},
  _dragSrc: null,

  init() {
    apiPost({action:'dashboard_layout_get'}).then(r=>{
      if(!r.ok)return;
      this._layout=r.data.layout||[];
      for(const w of(r.data.widgets||[])) this._widgets[w.id]=w;
      this._render();
    });
  },

  _render() {
    const grid=document.getElementById('cd-grid');
    const visible=this._layout.filter(l=>l.visible).sort((a,b)=>a.row!==b.row?a.row-b.row:a.col-b.col);
    grid.innerHTML=visible.map(l=>{
      const w=this._widgets[l.id];if(!w)return'';
      return `<div class="cd-widget" draggable="${this._editing}" data-id="${esc(l.id)}" data-edit="${this._editing}"
        ondragstart="CD._onDragStart(event)" ondragover="CD._onDragOver(event)" ondrop="CD._onDrop(event)" ondragleave="CD._onDragLeave(event)">
        <div class="cd-widget-header">${esc(w.icon)}<span>${esc(w.title)}</span>${this._editing?'<span class="cd-handle">⠿</span>':''}</div>
        <div class="cd-widget-content" id="cdw-${esc(l.id)}">
          ${this._editing?`<span style="font-size:12px;">${esc(w.description)}</span>`:'<div class="loading-row" style="height:40px;"></div>'}
        </div>
      </div>`;
    }).join('');

    if(!this._editing) this._loadWidgetData(visible.map(l=>l.id));

    // Picker
    const picker=document.getElementById('cd-picker-list');
    picker.innerHTML=this._layout.map(l=>{
      const w=this._widgets[l.id];if(!w)return'';
      return `<button class="btn btn-sm ${l.visible?'btn-primary':'btn-ghost'}" onclick="CD.toggleWidget('${esc(l.id)}')">${w.icon} ${esc(w.title)}</button>`;
    }).join('');
  },

  _loadWidgetData(ids) {
    // Simple data loading per widget type
    const loaders={
      portfolio_value:()=>apiPost({action:'lazy_portfolio_summary'}).then(d=>{if(!d.ok)return;const s=d.data;_fill('portfolio_value',`<div class="wd-num-xl" style="font-size:22px;font-weight:800;">${formatINR(s.current_value)}</div><div style="font-size:12px;margin-top:4px;" class="${s.gain>=0?'wd-gain':'wd-loss'}">${s.gain>=0?'+':''}${formatINR(s.gain)} (${s.gain_pct}%)</div>`);}),
      sip_summary:()=>apiPost({action:'lazy_sip_analysis'}).then(d=>{if(!d.ok)return;_fill('sip_summary',`<div style="font-size:18px;font-weight:700;">${formatINR(d.data.monthly_total)}<span style="font-size:12px;font-weight:400;">/mo</span></div><div style="font-size:12px;color:var(--text-muted);margin-top:4px;">${d.data.sip_count} active SIPs</div>`);}),
      goal_progress:()=>apiPost({action:'lazy_goal_progress'}).then(d=>{if(!d.ok)return;_fill('goal_progress',`<div style="font-size:18px;font-weight:700;">${d.data.on_track}/${d.data.total} on track</div><div style="font-size:12px;color:var(--text-muted);margin-top:4px;">${d.data.behind} behind schedule</div>`);}),
      sip_discipline:()=>apiPost({action:'sip_discipline_score',months:6}).then(d=>{if(!d.ok)return;_fill('sip_discipline',`<div style="font-size:22px;font-weight:800;">${d.data.overall_score}%</div><div style="font-size:12px;color:var(--text-muted);">Grade: ${d.data.grade} · Streak: ${d.data.streak}🔥</div>`);}),
      market_pulse:()=>apiPost({action:'market_pulse'}).then(d=>{if(!d.ok)return;const m=d.data?.indices||[];_fill('market_pulse',m.slice(0,2).map(i=>`<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;"><span>${esc(i.name)}</span><span class="${(i.change||0)>=0?'wd-gain':'wd-loss'}">${i.value} (${i.change>0?'+':''}${i.change}%)</span></div>`).join(''));}),
    };
    for(const id of ids){if(loaders[id])loaders[id]();}
  },

  toggleEdit(){
    this._editing=!this._editing;
    document.getElementById('cd-edit-btn').textContent=this._editing?'💾 Save Layout':'✏️ Edit Layout';
    document.getElementById('cd-widget-picker').style.display=this._editing?'':'none';
    if(!this._editing) this._save();
    this._render();
  },

  toggleWidget(id){
    const l=this._layout.find(x=>x.id===id);if(!l)return;
    l.visible=!l.visible;this._render();
  },

  _save(){
    apiPost({action:'dashboard_layout_save',layout:JSON.stringify(this._layout)}).then(r=>showToast(r.message,r.ok?'success':'error'));
  },

  reset(){if(!confirm('Reset dashboard to default?'))return;apiPost({action:'dashboard_layout_reset'}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.init();});},

  _onDragStart(e){this._dragSrc=e.currentTarget.dataset.id;e.currentTarget.classList.add('dragging');},
  _onDragOver(e){e.preventDefault();e.currentTarget.classList.add('drag-over');},
  _onDragLeave(e){e.currentTarget.classList.remove('drag-over');},
  _onDrop(e){
    e.preventDefault();e.currentTarget.classList.remove('drag-over');
    const srcId=this._dragSrc; const dstId=e.currentTarget.dataset.id;
    if(!srcId||srcId===dstId)return;
    const src=this._layout.find(l=>l.id===srcId); const dst=this._layout.find(l=>l.id===dstId);
    if(!src||!dst)return;
    [src.row,src.col,dst.row,dst.col]=[dst.row,dst.col,src.row,src.col];
    this._render();
  }
};

function _fill(id,html){const el=document.getElementById('cdw-'+id);if(el)el.innerHTML=html;}
document.addEventListener('DOMContentLoaded',()=>CD.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
