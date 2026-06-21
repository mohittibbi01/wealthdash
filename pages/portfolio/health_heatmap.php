<?php
/**
 * WealthDash — t446: Portfolio Health Heatmap Page
 * File: pages/portfolio/health_heatmap.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Portfolio Health'; $activePage='portfolio'; $activeSection='portfolio';
ob_start();
?>
<div class="page-header"><h1 class="page-title">💚 Portfolio Health Heatmap</h1><p class="page-subtitle">6-dimension health check of your portfolio.</p></div>

<!-- Overall score -->
<div class="card" style="margin-bottom:20px;text-align:center;">
  <div class="card-body" style="padding:28px;">
    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Overall Health Score</div>
    <div id="hh-overall" style="font-size:48px;font-weight:900;"></div>
    <div id="hh-overall-label" style="font-size:14px;color:var(--text-muted);margin-top:4px;"></div>
  </div>
</div>

<!-- Dimension heatmap grid -->
<div id="hh-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:24px;"></div>

<!-- Category breakdown -->
<div class="card">
  <div class="card-header"><span class="card-title">📊 Asset Category Breakdown</span></div>
  <div class="card-body p-0"><div id="hh-categories"></div></div>
</div>

<style>
.hh-tile{border-radius:14px;padding:18px;color:#fff;text-align:center;}
.hh-tile-icon{font-size:1.8rem;margin-bottom:6px;}
.hh-tile-score{font-size:28px;font-weight:900;}
.hh-tile-label{font-size:12px;font-weight:600;margin-top:4px;}
.hh-tile-detail{font-size:11px;opacity:.85;margin-top:4px;}
</style>

<script>
const HH={
  init(){this.load();},
  _color(score){
    if(score>=80)return'#16a34a';
    if(score>=60)return'#65a30d';
    if(score>=40)return'#eab308';
    if(score>=20)return'#f97316';
    return'#dc2626';
  },
  load(){
    apiPost({action:'portfolio_health_heatmap'}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      const d=r.data;
      const oc=this._color(d.overall_score);
      document.getElementById('hh-overall').textContent=d.overall_score;
      document.getElementById('hh-overall').style.color=oc;
      document.getElementById('hh-overall-label').textContent=d.overall_score>=80?'Excellent! Portfolio is well-managed.':d.overall_score>=60?'Good — minor improvements possible.':d.overall_score>=40?'Fair — some areas need attention.':'Needs Improvement — review recommendations.';

      document.getElementById('hh-grid').innerHTML=d.dimensions.map(dim=>{
        const c=this._color(dim.score);
        return `<div class="hh-tile" style="background:${c};">
          <div class="hh-tile-icon">${dim.icon}</div>
          <div class="hh-tile-score">${dim.score}</div>
          <div class="hh-tile-label">${esc(dim.label)}</div>
          <div class="hh-tile-detail">${esc(dim.detail)}</div>
        </div>`;
      }).join('');

      if(!d.categories.length){
        document.getElementById('hh-categories').innerHTML='<div class="empty-state" style="padding:30px;"><div>No holdings found.</div></div>';
        return;
      }
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Category</th><th class="text-right">Value</th><th class="text-right">Weight</th><th>Allocation</th></tr></thead><tbody>`;
      const colors=['#2563eb','#7c3aed','#059669','#dc2626','#d97706','#0891b2','#be185d','#4338ca'];
      d.categories.forEach((c,i)=>{
        html+=`<tr><td style="font-weight:600;">${esc(c.category)}</td><td class="text-right wd-num">${formatINR(c.value)}</td><td class="text-right wd-num">${c.pct}%</td>
          <td style="min-width:150px;"><div style="height:8px;background:var(--bg-secondary);border-radius:4px;overflow:hidden;"><div style="width:${c.pct}%;height:100%;background:${colors[i%colors.length]};"></div></div></td></tr>`;
      });
      html+='</tbody></table></div>';
      document.getElementById('hh-categories').innerHTML=html;
    });
  }
};
document.addEventListener('DOMContentLoaded',()=>HH.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
