<?php
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Investment Calendar'; $activePage='tools'; $activeSection='tools';
$currentFY=(date('n')>=4)?date('Y').'-'.substr(date('Y')+1,-2):(date('Y')-1).'-'.substr(date('Y'),-2);
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📅 Investment Calendar</h1><p class="page-subtitle">Important tax & investment dates.</p></div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <select id="cal-fy" class="form-control" style="width:130px;" onchange="CalApp.load()">
      <?php for($y=2024;$y<=2027;$y++){$fy=$y.'-'.substr($y+1,-2);$sel=$fy===$currentFY?'selected':'';echo "<option value=\"$fy\" $sel>FY $fy</option>";}?>
    </select>
    <?php foreach(['tax'=>'🧾 Tax','sip'=>'📈 SIP','emi'=>'🏦 EMI','fd'=>'💰 FD','insurance'=>'🛡 Insurance','review'=>'🔍 Review','goal'=>'🎯 Goals'] as $t=>$l):?>
      <button class="btn btn-secondary btn-sm cal-filter-btn active" data-type="<?=$t?>" onclick="CalApp.toggle('<?=$t?>',this)" style="font-size:11px;padding:4px 8px;"><?=$l?></button>
    <?php endforeach;?>
  </div>
</div>
<div id="cal-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;" class="responsive-grid-1col"></div>
<div class="card" style="margin-top:20px;"><div class="card-header" style="display:flex;align-items:center;justify-content:space-between;"><span class="card-title">⏰ Upcoming 60 Days</span><span id="cal-count" class="badge"></span></div><div class="card-body p-0"><div id="cal-upcoming"></div></div></div>
<style>
.cal-month-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.cal-month-header{padding:10px 14px;font-weight:700;font-size:13px;background:var(--bg-secondary);border-bottom:1px solid var(--border);}
.cal-event{padding:8px 14px;border-bottom:1px solid var(--border);font-size:13px;}
.cal-event:last-child{border-bottom:none;}
.p-danger{border-left:3px solid #dc2626;}.p-warning{border-left:3px solid #f97316;}.p-info{border-left:3px solid #2563eb;}.p-sip{border-left:3px solid #16a34a;}.p-emi{border-left:3px solid #f97316;}
.cal-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;}
.dot-tax{background:#dc2626;}.dot-sip{background:#16a34a;}.dot-emi{background:#f97316;}.dot-fd{background:#2563eb;}.dot-insurance{background:#7c3aed;}.dot-review{background:#0891b2;}.dot-goal{background:#d97706;}
</style>
<script>
const CalApp={_events:[],_hidden:new Set(),
init(){this.load();},
load(){const fy=document.getElementById('cal-fy').value;document.getElementById('cal-grid').innerHTML='<div style="grid-column:1/-1;" class="loading-row">Loading…</div>';apiPost({action:'inv_calendar_events',fy}).then(r=>{if(!r.ok)return;this._events=r.data.events||[];this._render();});},
toggle(t,btn){btn.classList.toggle('active');if(this._hidden.has(t))this._hidden.delete(t);else this._hidden.add(t);this._render();},
_render(){const vis=this._events.filter(e=>!this._hidden.has(e.type));const bm={};for(const e of vis){const m=e.date.substring(0,7);if(!bm[m])bm[m]=[];bm[m].push(e);}const months=Object.keys(bm).sort();let html='';if(!months.length){html='<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon">📅</div><div>No events found.</div></div>';}else{for(const m of months){const label=new Date(m+'-01').toLocaleDateString('en-IN',{month:'long',year:'numeric'});html+=`<div class="cal-month-card"><div class="cal-month-header">📅 ${esc(label)} <span style="float:right;font-weight:400;color:var(--text-muted);">${bm[m].length}</span></div>`;for(const e of bm[m]){html+=`<div class="cal-event p-${esc(e.priority||'info')}"><span class="cal-dot dot-${esc(e.type)}"></span><span style="font-size:11px;color:var(--text-muted);">${esc(e.date)}</span><br><strong>${esc(e.title)}</strong><div style="font-size:11px;color:var(--text-muted);">${esc(e.desc)}</div></div>`;}html+='</div>';}}document.getElementById('cal-grid').innerHTML=html;
const today=new Date().toISOString().substring(0,10);const f60=new Date();f60.setDate(f60.getDate()+60);const ff60=f60.toISOString().substring(0,10);const up=vis.filter(e=>e.date>=today&&e.date<=ff60);document.getElementById('cal-count').textContent=up.length;if(!up.length){document.getElementById('cal-upcoming').innerHTML='<div class="empty-state"><div>No upcoming events in 60 days.</div></div>';return;}let ul=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Date</th><th>Event</th><th>Type</th></tr></thead><tbody>`;for(const e of up){ul+=`<tr><td class="wd-num" style="font-size:13px;">${esc(e.date)}</td><td><div style="font-weight:600;font-size:13px;">${esc(e.title)}</div><div style="font-size:11px;color:var(--text-muted);">${esc(e.desc)}</div></td><td style="text-transform:capitalize;font-size:12px;">${esc(e.category||e.type)}</td></tr>`;}ul+='</tbody></table></div>';document.getElementById('cal-upcoming').innerHTML=ul;}};
document.addEventListener('DOMContentLoaded',()=>CalApp.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
