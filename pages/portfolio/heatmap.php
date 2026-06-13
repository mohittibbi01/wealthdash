<?php
/**
 * WealthDash — t300: Portfolio Heatmap Page
 * File: pages/portfolio/heatmap.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Portfolio Heatmap'; $activePage='portfolio'; $activeSection='portfolio';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🌡 Portfolio Heatmap</h1><p class="page-subtitle">Color-coded performance of all your holdings.</p></div>
  <div style="display:flex;gap:6px;">
    <?php foreach(['1d'=>'1D','1w'=>'1W','1m'=>'1M','3m'=>'3M','6m'=>'6M','1y'=>'1Y'] as $v=>$l): ?>
      <button class="btn btn-sm <?=$v==='1m'?'btn-primary':'btn-ghost'?>" data-period="<?=$v?>" onclick="HM.setPeriod('<?=$v?>',this)"><?=$l?></button>
    <?php endforeach; ?>
  </div>
</div>

<!-- Legend -->
<div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
  <div style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <div style="width:16px;height:16px;background:#16a34a;border-radius:3px;"></div><span>Strong gain (&gt;10%)</span>
  </div>
  <div style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <div style="width:16px;height:16px;background:#4ade80;border-radius:3px;"></div><span>Moderate gain (5-10%)</span>
  </div>
  <div style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <div style="width:16px;height:16px;background:#bbf7d0;border-radius:3px;"></div><span>Small gain (0-5%)</span>
  </div>
  <div style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <div style="width:16px;height:16px;background:#fecaca;border-radius:3px;"></div><span>Small loss (0-5%)</span>
  </div>
  <div style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <div style="width:16px;height:16px;background:#dc2626;border-radius:3px;"></div><span>Loss (&gt;5%)</span>
  </div>
  <div style="margin-left:auto;font-size:12px;color:var(--text-muted);">Box size = portfolio weight</div>
</div>

<!-- Heatmap -->
<div id="hm-grid" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;align-items:flex-start;"></div>

<!-- Table view -->
<div class="card">
  <div class="card-header"><span class="card-title">📋 Holdings Detail</span></div>
  <div class="card-body p-0"><div id="hm-table"></div></div>
</div>

<style>
.hm-cell{border-radius:10px;padding:10px 12px;cursor:default;transition:transform .15s;position:relative;overflow:hidden;min-width:80px;}
.hm-cell:hover{transform:scale(1.03);z-index:2;}
.hm-cell-name{font-size:11px;font-weight:700;color:#fff;line-height:1.3;margin-bottom:4px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}
.hm-cell-ret{font-size:14px;font-weight:800;color:#fff;}
.hm-cell-val{font-size:10px;color:rgba(255,255,255,.8);margin-top:3px;}
</style>

<script>
const HM={_period:'1m',
init(){this.load();},
setPeriod(p,btn){this._period=p;document.querySelectorAll('[data-period]').forEach(b=>b.classList.replace('btn-primary','btn-ghost'));btn.classList.replace('btn-ghost','btn-primary');this.load();},
_color(ret){
  if(ret>=15)return['#14532d','#16a34a'];
  if(ret>=10)return['#15803d','#4ade80'];
  if(ret>=5) return['#166534','#86efac'];
  if(ret>=2) return['#14532d','#bbf7d0'];
  if(ret>=0) return['#166534','#d1fae5'];
  if(ret>=-2) return['#7f1d1d','#fecaca'];
  if(ret>=-5) return['#991b1b','#fca5a5'];
  return['#7f1d1d','#dc2626'];
},
load(){
  document.getElementById('hm-grid').innerHTML='<div style="padding:20px;color:var(--text-muted);">Loading…</div>';
  apiPost({action:'portfolio_heatmap',period:this._period}).then(r=>{
    if(!r.ok){showToast(r.message,'error');return;}
    const d=r.data; const cells=d.cells||[];
    if(!cells.length){document.getElementById('hm-grid').innerHTML='<div class="empty-state"><div class="empty-icon">🌡</div><div>No holdings found.</div></div>';document.getElementById('hm-table').innerHTML='';return;}
    // Heatmap grid (treemap-style using flex)
    const maxW=Math.max(...cells.map(c=>c.weight_pct));
    let gridHtml='';
    for(const c of cells){
      const ret=c.display_return;
      const [bg,text]=this._color(ret);
      const w=Math.max(80,Math.round(c.weight_pct/maxW*220));
      const h=Math.max(70,Math.round(c.weight_pct/maxW*130));
      const retStr=(ret>=0?'+':'')+ret.toFixed(1)+'%';
      gridHtml+=`<div class="hm-cell" style="background:${bg};width:${w}px;height:${h}px;" title="${esc(c.fund_name)}: ${retStr}">
        <div class="hm-cell-name">${esc(c.fund_name)}</div>
        <div class="hm-cell-ret">${retStr}</div>
        <div class="hm-cell-val">${formatINR(c.current_value)} · ${c.weight_pct.toFixed(1)}%</div>
      </div>`;
    }
    document.getElementById('hm-grid').innerHTML=gridHtml;
    // Table
    let th=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Fund</th><th>Category</th><th class="text-right">Value</th><th class="text-right">Gain</th><th class="text-right">Period Return</th><th class="text-right">Weight</th></tr></thead><tbody>`;
    for(const c of cells){
      const gc=c.gain>=0?'wd-gain':'wd-loss';
      const pr=c.period_return!==null?`${c.period_return>=0?'+':''}${c.period_return.toFixed(2)}%`:'—';
      const prc=c.period_return===null?'':(c.period_return>=0?'wd-gain':'wd-loss');
      th+=`<tr><td style="font-weight:600;font-size:13px;">${esc(c.fund_name)}</td><td style="font-size:12px;">${esc(c.category)}</td><td class="text-right wd-num">${formatINR(c.current_value)}</td><td class="text-right wd-num ${gc}">${c.gain>=0?'+':''}${formatINR(c.gain)} (${c.gain_pct.toFixed(1)}%)</td><td class="text-right wd-num ${prc}">${pr}</td><td class="text-right wd-num">${c.weight_pct.toFixed(1)}%</td></tr>`;
    }
    th+='</tbody></table></div>';
    document.getElementById('hm-table').innerHTML=th;
  });
}};
document.addEventListener('DOMContentLoaded',()=>HM.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
