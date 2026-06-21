<?php
/**
 * WealthDash — t445: Customizable Overview Cards Page
 * File: pages/dashboard/overview_cards.php
 *
 * Use OverviewCards.renderInto('#target-element') from any page
 * to embed the customizable card row.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Customize Overview Cards'; $activePage='dashboard'; $activeSection='dashboard';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🎛 Customize Overview Cards</h1><p class="page-subtitle">Choose up to 6 KPI cards to show at the top of your dashboard.</p></div>
  <button class="btn btn-ghost btn-sm" onclick="OC.reset()">↩ Reset to Default</button>
</div>

<!-- Live preview -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">👁 Live Preview</span></div>
  <div class="card-body"><div id="oc-preview" class="dashboard-grid"></div></div>
</div>

<!-- Card picker -->
<div class="card">
  <div class="card-header"><span class="card-title">Select Cards (max 6)</span></div>
  <div class="card-body">
    <div id="oc-picker" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;"></div>
    <button class="btn btn-primary" style="margin-top:16px;" onclick="OC.save()">💾 Save Selection</button>
  </div>
</div>

<style>
.oc-pick{display:flex;align-items:center;gap:10px;padding:12px 14px;border:2px solid var(--border);border-radius:10px;cursor:pointer;transition:.15s;}
.oc-pick.selected{border-color:var(--accent);background:var(--bg-secondary);}
.oc-pick-icon{font-size:1.4rem;}
.oc-pick-order{margin-left:auto;background:var(--accent);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;}
</style>

<script>
const OC={
  _available:[],_selected:[],

  init(){
    apiPost({action:'overview_cards_get'}).then(r=>{
      if(!r.ok)return;
      this._available=r.data.available||[];
      this._selected=r.data.selected||[];
      this._renderPicker();
      this._loadPreview();
    });
  },

  _renderPicker(){
    document.getElementById('oc-picker').innerHTML=this._available.map(c=>{
      const idx=this._selected.indexOf(c.id);
      const sel=idx>=0;
      return `<div class="oc-pick ${sel?'selected':''}" onclick="OC.toggle('${c.id}')">
        <span class="oc-pick-icon">${c.icon}</span>
        <span style="font-size:13px;font-weight:600;">${esc(c.title)}</span>
        ${sel?`<span class="oc-pick-order">${idx+1}</span>`:''}
      </div>`;
    }).join('');
  },

  toggle(id){
    const idx=this._selected.indexOf(id);
    if(idx>=0)this._selected.splice(idx,1);
    else{
      if(this._selected.length>=6){showToast('Maximum 6 cards allowed','warning');return;}
      this._selected.push(id);
    }
    this._renderPicker();
    this._loadPreview();
  },

  _loadPreview(){
    // Temporarily save then fetch data preview (or compute client-side from cached data)
    apiPost({action:'overview_cards_save',cards:JSON.stringify(this._selected)}).then(()=>{
      apiPost({action:'overview_cards_data'}).then(r=>{
        if(!r.ok)return;
        const cards=r.data.cards||[];
        document.getElementById('oc-preview').innerHTML=cards.map(c=>{
          let valDisplay='';
          if(c.format==='currency')valDisplay=formatINR(c.value);
          else if(c.format==='currency_pct')valDisplay=`${c.value>=0?'+':''}${formatINR(c.value)} <span style="font-size:12px;">(${c.extra>=0?'+':''}${c.extra}%)</span>`;
          else if(c.format==='percent')valDisplay=`${c.value>=0?'+':''}${c.value}%`;
          else if(c.format==='fraction')valDisplay=`${c.value}/${c.extra}`;
          else valDisplay=c.value;
          const colorCls=(c.format==='currency_pct'||c.format==='percent')?(c.value>=0?'wd-gain':'wd-loss'):'';
          return `<div class="stat-card"><div class="stat-label">${c.icon} ${esc(c.title)}</div><div class="stat-value wd-num-xl ${colorCls}">${valDisplay}</div></div>`;
        }).join('') || '<div style="color:var(--text-muted);padding:20px;">Select at least 1 card.</div>';
      });
    });
  },

  save(){
    apiPost({action:'overview_cards_save',cards:JSON.stringify(this._selected)}).then(r=>showToast(r.message,r.ok?'success':'error'));
  },

  reset(){
    if(!confirm('Reset to default cards?'))return;
    apiPost({action:'overview_cards_reset'}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.init();});
  }
};
document.addEventListener('DOMContentLoaded',()=>OC.init());

// ── Embeddable widget for other pages ──────────────────────────────
window.OverviewCards = {
  renderInto(selector){
    const el=document.querySelector(selector);
    if(!el)return;
    el.innerHTML='<div style="padding:12px;color:var(--text-muted);font-size:12px;">Loading…</div>';
    apiPost({action:'overview_cards_data'}).then(r=>{
      if(!r.ok)return;
      const cards=r.data.cards||[];
      el.innerHTML=cards.map(c=>{
        let valDisplay='';
        if(c.format==='currency')valDisplay=formatINR(c.value);
        else if(c.format==='currency_pct')valDisplay=`${c.value>=0?'+':''}${formatINR(c.value)} <span style="font-size:12px;">(${c.extra>=0?'+':''}${c.extra}%)</span>`;
        else if(c.format==='percent')valDisplay=`${c.value>=0?'+':''}${c.value}%`;
        else if(c.format==='fraction')valDisplay=`${c.value}/${c.extra}`;
        else valDisplay=c.value;
        const colorCls=(c.format==='currency_pct'||c.format==='percent')?(c.value>=0?'wd-gain':'wd-loss'):'';
        return `<div class="stat-card"><div class="stat-label">${c.icon} ${esc(c.title)}</div><div class="stat-value wd-num-xl ${colorCls}">${valDisplay}</div></div>`;
      }).join('');
    });
  }
};
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
