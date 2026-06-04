<?php
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Annual Financial Review'; $activePage='tools'; $activeSection='tools';
$curYear=(int)date('Y');
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">📋 Annual Financial Review</h1><p class="page-subtitle">Year-end checklist to ensure your finances are in order.</p></div>
  <div style="display:flex;gap:10px;align-items:center;">
    <select id="ar-year" class="form-control" style="width:110px;" onchange="AR.load()">
      <?php for($y=$curYear;$y>=$curYear-3;$y--):?><option value="<?=$y?>" <?=$y===$curYear?'selected':''?>>FY <?=$y?></option><?php endfor;?>
    </select>
    <button class="btn btn-secondary btn-sm" onclick="AR.save(false)">💾 Save</button>
    <button class="btn btn-primary btn-sm" onclick="AR.save(true)">✅ Complete</button>
  </div>
</div>
<div class="card" style="margin-bottom:20px;"><div class="card-body" style="padding:16px 20px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><span style="font-weight:700;font-size:15px;">Progress</span><span id="ar-prog-label" style="font-weight:800;font-size:18px;"></span></div>
  <div style="height:12px;background:var(--bg-secondary);border-radius:6px;overflow:hidden;"><div id="ar-prog-bar" style="height:100%;background:var(--accent);border-radius:6px;transition:width .4s;width:0%;"></div></div>
  <div id="ar-done-badge" style="display:none;margin-top:10px;" class="alert alert-success">🎉 Annual review completed!</div>
</div></div>
<div id="ar-checklist"></div>
<div class="card" style="margin-top:20px;"><div class="card-header"><span class="card-title">📝 Notes</span></div><div class="card-body"><textarea id="ar-notes" class="form-control" rows="4" placeholder="Action items, reminders..."></textarea></div></div>
<div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end;">
  <button class="btn btn-secondary" onclick="AR.save(false)">💾 Save Progress</button>
  <button class="btn btn-primary" onclick="AR.save(true)">✅ Mark Complete</button>
</div>
<style>
.ar-sec{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;margin-bottom:16px;overflow:hidden;}
.ar-sec-hdr{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;background:var(--bg-secondary);}
.ar-item{display:flex;align-items:flex-start;gap:12px;padding:10px 18px;border-bottom:1px solid var(--border);cursor:pointer;}
.ar-item:last-child{border-bottom:none;}.ar-item:hover{background:var(--bg-secondary);}
.ar-item.checked .ar-item-lbl{text-decoration:line-through;color:var(--text-muted);}
.ar-cb{width:18px;height:18px;flex-shrink:0;margin-top:1px;accent-color:var(--accent);}
.ar-item-lbl{font-size:13px;line-height:1.5;}
</style>
<script>
const AR={_data:null,_stimer:null,
init(){this.load();},
load(){const year=document.getElementById('ar-year').value;apiPost({action:'annual_review_checklist',year}).then(r=>{if(!r.ok)return;this._data=r.data;document.getElementById('ar-notes').value=r.data.notes||'';if(r.data.completed_at)document.getElementById('ar-done-badge').style.display='';this._render();this._upd(r.data.progress_pct,r.data.checked_count,r.data.total_items);});},
_render(){if(!this._data)return;const d=this._data;let html='';for(const[sk,sec]of Object.entries(d.checklist)){const sc=sec.items.filter(i=>i.checked).length;const st=sec.items.length;const sp=Math.round((sc/st)*100);html+=`<div class="ar-sec"><div class="ar-sec-hdr" onclick="AR._toggleSec('${esc(sk)}')"><span style="font-weight:700;font-size:14px;">${esc(sec.label)}</span><span style="font-size:12px;color:var(--text-muted);">${sc}/${st} — ${sp}% ▾</span></div><div class="ar-sec-body" id="arb-${esc(sk)}">`;for(const item of sec.items){html+=`<div class="ar-item ${item.checked?'checked':''}" onclick="AR.toggle('${esc(item.id)}')"><input type="checkbox" class="ar-cb" id="arcb-${esc(item.id)}" ${item.checked?'checked':''} onclick="event.stopPropagation();AR.toggle('${esc(item.id)}')"><label class="ar-item-lbl" for="arcb-${esc(item.id)}" onclick="event.stopPropagation();">${esc(item.label)}</label></div>`;}html+='</div></div>';}document.getElementById('ar-checklist').innerHTML=html;},
toggle(id){for(const sec of Object.values(this._data.checklist)){for(const item of sec.items){if(item.id===id){item.checked=!item.checked;break;}}}let checked=0,total=0;for(const sec of Object.values(this._data.checklist)){for(const item of sec.items){total++;if(item.checked)checked++;}}const pct=Math.round((checked/total)*100);this._data.checked_count=checked;this._data.total_items=total;this._data.progress_pct=pct;this._render();this._upd(pct,checked,total);clearTimeout(this._stimer);this._stimer=setTimeout(()=>this.save(false,true),1500);},
_toggleSec(k){const b=document.getElementById('arb-'+k);if(b)b.style.display=b.style.display==='none'?'':'none';},
_upd(pct,checked,total){document.getElementById('ar-prog-bar').style.width=pct+'%';document.getElementById('ar-prog-bar').style.background=pct>=80?'#16a34a':pct>=50?'#eab308':'#2563eb';document.getElementById('ar-prog-label').textContent=checked+'/'+total+' ('+pct+'%)';},
_ids(){const ids=[];for(const sec of Object.values(this._data.checklist)){for(const item of sec.items){if(item.checked)ids.push(item.id);}}return ids;},
save(complete=false,silent=false){if(!this._data)return;apiPost({action:'annual_review_save',year:document.getElementById('ar-year').value,checked_items:JSON.stringify(this._ids()),notes:document.getElementById('ar-notes').value,mark_complete:complete?1:0}).then(r=>{if(!silent)showToast(r.message,r.ok?'success':'error');if(complete&&r.ok)document.getElementById('ar-done-badge').style.display='';}); }};
document.addEventListener('DOMContentLoaded',()=>AR.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
