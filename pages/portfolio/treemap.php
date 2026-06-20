<?php
/**
 * WealthDash — t485: Portfolio Treemap Page
 * File: pages/portfolio/treemap.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Portfolio Treemap'; $activePage='portfolio'; $activeSection='portfolio';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🗺 Portfolio Treemap</h1><p class="page-subtitle">Visualize your portfolio composition by value.</p></div>
  <div style="display:flex;gap:6px;">
    <button class="btn btn-sm btn-primary" data-grp="category" onclick="TM.setGroup('category',this)">By Category</button>
    <button class="btn btn-sm btn-ghost" data-grp="fund" onclick="TM.setGroup('fund',this)">By Fund</button>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <svg id="tm-svg" viewBox="0 0 1000 600" style="width:100%;height:auto;max-height:600px;" preserveAspectRatio="xMidYMid meet"></svg>
  </div>
</div>

<!-- Legend / detail table -->
<div class="card" style="margin-top:20px;">
  <div class="card-header"><span class="card-title">📋 Breakdown</span></div>
  <div class="card-body p-0"><div id="tm-table"></div></div>
</div>

<style>
.tm-rect{stroke:var(--bg);stroke-width:2;cursor:pointer;transition:opacity .15s;}
.tm-rect:hover{opacity:.85;}
.tm-label{font-size:13px;font-weight:700;fill:#fff;pointer-events:none;}
.tm-sublabel{font-size:11px;fill:rgba(255,255,255,.85);pointer-events:none;}
</style>

<script>
const TM={_groupBy:'category',

init(){this.load();},

setGroup(g,btn){
  this._groupBy=g;
  document.querySelectorAll('[data-grp]').forEach(b=>b.classList.replace('btn-primary','btn-ghost'));
  btn.classList.replace('btn-ghost','btn-primary');
  this.load();
},

load(){
  apiPost({action:'portfolio_treemap',group_by:this._groupBy}).then(r=>{
    if(!r.ok){showToast(r.message,'error');return;}
    const d=r.data;
    this._renderSvg(d.layout);
    this._renderTable(d.items,d.total_value);
  });
},

_renderSvg(layout){
  const svg=document.getElementById('tm-svg');
  const SCALE_X=10,SCALE_Y=10; // 100x60 units -> 1000x600 viewbox
  if(!layout.length){svg.innerHTML='<text x="500" y="300" text-anchor="middle" fill="var(--text-muted)">No holdings to display</text>';return;}
  let svgContent='';
  for(const item of layout){
    const x=item.x*SCALE_X,y=item.y*SCALE_Y,w=item.w*SCALE_X,h=item.h*SCALE_Y;
    if(w<2||h<2)continue;
    svgContent+=`<g>
      <rect class="tm-rect" x="${x}" y="${y}" width="${w}" height="${h}" fill="${item.color}" rx="4">
        <title>${esc(item.name)}: ${formatINR(item.value)} (${item.weight_pct}%)</title>
      </rect>`;
    if(w>60&&h>30){
      svgContent+=`<text class="tm-label" x="${x+8}" y="${y+20}">${esc(this._truncate(item.name,Math.floor(w/8)))}</text>`;
      if(h>50)svgContent+=`<text class="tm-sublabel" x="${x+8}" y="${y+38}">${item.weight_pct}%</text>`;
    }
    svgContent+='</g>';
  }
  svg.innerHTML=svgContent;
},

_truncate(s,len){return s.length>len?s.substring(0,len-1)+'…':s;},

_renderTable(items,total){
  if(!items.length){document.getElementById('tm-table').innerHTML='<div class="empty-state" style="padding:30px;"><div>No holdings found.</div></div>';return;}
  let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th></th><th>Name</th><th class="text-right">Value</th><th class="text-right">Weight</th></tr></thead><tbody>`;
  for(const item of items){
    html+=`<tr><td><span style="display:inline-block;width:14px;height:14px;background:${item.color};border-radius:3px;"></span></td><td style="font-weight:600;">${esc(item.name)}</td><td class="text-right wd-num">${formatINR(item.value)}</td><td class="text-right wd-num">${item.weight_pct}%</td></tr>`;
  }
  html+=`</tbody></table></div><div style="padding:10px 16px;font-size:12px;color:var(--text-muted);">Total: ${formatINR(total)}</div>`;
  document.getElementById('tm-table').innerHTML=html;
}
};
document.addEventListener('DOMContentLoaded',()=>TM.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
