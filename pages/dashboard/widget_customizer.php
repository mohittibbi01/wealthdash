<?php
/**
 * WealthDash — t55: Dashboard Widget Customizer Page
 * File: pages/dashboard/widget_customizer.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Customize Dashboard'; $activePage='dashboard'; $activeSection='dashboard';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🎛 Customize Dashboard</h1><p class="page-subtitle">Drag, toggle, and arrange your dashboard widgets.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-ghost btn-sm" onclick="WC.reset()">↩ Reset</button>
    <button class="btn btn-primary" id="wc-edit-btn" onclick="WC.toggleEdit()">✏️ Edit Mode</button>
  </div>
</div>

<!-- Widget picker (edit mode only) -->
<div id="wc-picker" style="display:none;margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><span class="card-title">Available Widgets — click to add</span></div>
    <div class="card-body" id="wc-picker-list" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
  </div>
</div>

<!-- Dashboard grid -->
<div id="wc-grid" class="wc-grid"></div>

<style>
.wc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
@media(max-width:900px){.wc-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.wc-grid{grid-template-columns:1fr;}}
.wc-widget{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:16px;min-height:120px;position:relative;}
.wc-widget.lg{grid-column:span 2;}
@media(max-width:900px){.wc-widget.lg{grid-column:span 1;}}
.wc-widget[data-edit="1"]{cursor:grab;border-style:dashed;border-color:var(--accent);}
.wc-widget.dragging{opacity:.4;}
.wc-widget.drag-over{background:var(--bg-secondary);}
.wc-widget-header{display:flex;align-items:center;gap:8px;margin-bottom:10px;font-weight:700;font-size:13px;}
.wc-remove-btn{position:absolute;top:8px;right:8px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;display:none;}
.wc-widget[data-edit="1"] .wc-remove-btn{display:flex;align-items:center;justify-content:center;}
</style>

<script>
const WC={_editing:false,_layout:[],_registry:{},

init(){
  apiPost({action:'widget_layout_get'}).then(r=>{
    if(!r.ok)return;
    this._layout=r.data.layout||[];
    for(const w of(r.data.registry||[]))this._registry[w.id]=w;
    this._render();
  });
},

_render(){
  const visible=this._layout.filter(l=>l.visible).sort((a,b)=>a.row!==b.row?a.row-b.row:a.col-b.col);
  document.getElementById('wc-grid').innerHTML=visible.map(l=>{
    const w=this._registry[l.id];if(!w)return'';
    const sizeClass=w.size==='lg'?'lg':'';
    return `<div class="wc-widget ${sizeClass}" draggable="${this._editing}" data-id="${esc(l.id)}" data-edit="${this._editing?1:0}"
      ondragstart="WC._dragStart(event)" ondragover="WC._dragOver(event)" ondrop="WC._drop(event)" ondragleave="WC._dragLeave(event)">
      ${this._editing?`<button class="wc-remove-btn" onclick="WC.toggleWidget('${esc(l.id)}')">✕</button>`:''}
      <div class="wc-widget-header">${w.icon}<span>${esc(w.title)}</span></div>
      <div id="wcw-${esc(l.id)}" style="font-size:12px;color:var(--text-muted);">
        ${this._editing?esc(w.desc):'<div class="loading-row" style="height:30px;"></div>'}
      </div>
    </div>`;
  }).join('');

  if(!this._editing)this._loadData(visible.map(l=>l.id));

  document.getElementById('wc-picker-list').innerHTML=this._layout.map(l=>{
    const w=this._registry[l.id];if(!w)return'';
    return `<button class="btn btn-sm ${l.visible?'btn-primary':'btn-ghost'}" onclick="WC.toggleWidget('${esc(l.id)}')">${w.icon} ${esc(w.title)}</button>`;
  }).join('');
},

_loadData(ids){
  for(const id of ids){
    apiPost({action:'widget_data_get',widget_id:id}).then(r=>{
      const el=document.getElementById('wcw-'+id);if(!el||!r.ok)return;
      const d=r.data.data;
      let html='';
      if(d.value!==undefined){html=`<div style="font-size:18px;font-weight:700;">${formatINR(d.value)}</div><div style="${d.gain_pct>=0?'color:var(--gain)':'color:var(--loss)'}">${d.gain_pct>=0?'+':''}${d.gain_pct}%</div>`;}
      else if(d.monthly_total!==undefined){html=`<div style="font-size:18px;font-weight:700;">${formatINR(d.monthly_total)}/mo</div><div>${d.count} active SIPs</div>`;}
      else if(d.estimated_tax!==undefined){html=`<div style="font-size:18px;font-weight:700;">${formatINR(d.estimated_tax)}</div><div>Taxable gain: ${formatINR(d.taxable)}</div>`;}
      else if(d.count!==undefined&&d.upcoming){html=d.count?`<div>${d.count} premium(s) due in 30 days</div>`:'<div style="color:var(--gain);">No premiums due</div>';}
      else if(d.income!==undefined){html=`<div>Income: ${formatINR(d.income)} · Expense: ${formatINR(d.expenses)}</div><div style="${d.savings>=0?'color:var(--gain)':'color:var(--loss)'}">Savings: ${formatINR(d.savings)}</div>`;}
      else if(d.total_value!==undefined){html=`<div style="font-size:18px;font-weight:700;">${formatINR(d.total_value)}</div><div>Equity: ${formatINR(d.total_equity)}</div>`;}
      else html=`<div style="font-size:11px;">${esc(d.message||'No data')}</div>`;
      el.innerHTML=html;
    });
  }
},

toggleEdit(){
  this._editing=!this._editing;
  document.getElementById('wc-edit-btn').textContent=this._editing?'💾 Save Layout':'✏️ Edit Mode';
  document.getElementById('wc-picker').style.display=this._editing?'':'none';
  if(!this._editing)this._save();
  this._render();
},

toggleWidget(id){const l=this._layout.find(x=>x.id===id);if(!l)return;l.visible=!l.visible;this._render();},

_save(){apiPost({action:'widget_layout_save',layout:JSON.stringify(this._layout)}).then(r=>showToast(r.message,r.ok?'success':'error'));},

reset(){if(!confirm('Reset dashboard to default layout?'))return;apiPost({action:'widget_layout_reset'}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.init();});},

_dragSrc:null,
_dragStart(e){this._dragSrc=e.currentTarget.dataset.id;e.currentTarget.classList.add('dragging');},
_dragOver(e){e.preventDefault();e.currentTarget.classList.add('drag-over');},
_dragLeave(e){e.currentTarget.classList.remove('drag-over');},
_drop(e){
  e.preventDefault();e.currentTarget.classList.remove('drag-over');
  const srcId=this._dragSrc;const dstId=e.currentTarget.dataset.id;
  if(!srcId||srcId===dstId)return;
  const src=this._layout.find(l=>l.id===srcId);const dst=this._layout.find(l=>l.id===dstId);
  if(!src||!dst)return;
  [src.row,src.col,dst.row,dst.col]=[dst.row,dst.col,src.row,src.col];
  this._render();
}
};
document.addEventListener('DOMContentLoaded',()=>WC.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
