<?php
require_once __DIR__ . '/auth.php';
require_login();

$db = get_db();
$projects = $db->query("SELECT p.*, u.username as creator,
    (SELECT COUNT(*) FROM project_documents WHERE project_id=p.id) as doc_count,
    (SELECT COUNT(*) FROM checklist_responses WHERE project_id=p.id AND checked=1) as chk_done,
    (SELECT COUNT(*) FROM checklist_items) as chk_total
    FROM projects p LEFT JOIN users u ON p.created_by=u.id ORDER BY p.project_name")->fetchAll();

$accent  = user_pref('accent','#00d4ff');
$bg      = user_pref('bg_color','');
$theme   = user_pref('theme','dark');
$fsize   = user_pref('font_size','14');
$ffamily = user_pref('font_family','Rajdhani');

$SL=['live'=>'Live','under_development'=>'Under Dev','redevelopment'=>'Redevelopment',
     'hold_by_department'=>'Hold by Dept','content_updation'=>'Content Updation','closed'=>'Closed'];
$SC=['live'=>'#00e676','under_development'=>'#ffd740','redevelopment'=>'#40c4ff',
     'hold_by_department'=>'#ff6e40','content_updation'=>'#bc8cff','closed'=>'#ff3d5a'];

// Build distinct value lists for dropdown filters
$techs = array_unique(array_filter(array_map(fn($p)=>$p['technology']==='Other'?$p['technology_other']:$p['technology'], $projects)));
$depts = array_unique(array_filter(array_map(fn($p)=>$p['department_name'], $projects)));
sort($techs); sort($depts);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault — One-Page Report</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Orbitron:wght@700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?=$accent?>;--user-bg:<?=$bg?$bg:'var(--bg)'?>;
  --bg:#070b14;--sur:#0d1422;--sur2:#111a2e;--bdr:#1e2d4a;
  --tx:#e8edf5;--mt:#5a7a9a;--ok:#00e676;--err:#ff3d5a;--amb:#ffd740;
}
[data-theme="light"]{--bg:#f0f4f8;--sur:#ffffff;--sur2:#e8edf5;--bdr:#c8d4e0;--tx:#0d1422;--mt:#6b7f96;}
html{font-size:<?=$fsize?>px}
body{font-family:'<?=$ffamily?>',sans-serif;background:var(--user-bg);color:var(--tx);min-height:100vh}
.bar{position:sticky;top:0;z-index:200;height:50px;background:color-mix(in srgb,var(--user-bg) 92%,transparent);
  border-bottom:1px solid var(--bdr);backdrop-filter:blur(14px);display:flex;align-items:center;padding:0 16px;gap:10px}
.logo{font-family:'Orbitron',monospace;font-size:14px;font-weight:900;color:var(--acc);letter-spacing:2px;text-shadow:0 0 14px var(--acc)}
.bar-r{margin-left:auto;display:flex;gap:8px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;font-size:13px;font-weight:700;
  font-family:inherit;cursor:pointer;border:none;text-decoration:none;transition:all .15s}
.btn:active{transform:scale(.97)}
.btn-ghost{background:var(--sur2);color:var(--mt);border:1px solid var(--bdr)}
.btn-ghost:hover{color:var(--tx)}
.btn-acc{background:var(--acc);color:#000}
.btn-acc:hover{opacity:.85}
.result-count{font-family:'Share Tech Mono',monospace;font-size:12px;color:var(--mt)}

.content{padding:12px}
.tbl-wrap{overflow:auto;border:1px solid var(--bdr);border-radius:10px;background:var(--sur);max-height:calc(100vh - 130px)}
table{border-collapse:collapse;width:100%;font-size:12px;font-family:'Share Tech Mono',monospace;white-space:nowrap}
thead th{position:sticky;top:0;background:var(--sur2);z-index:10;padding:0;border-bottom:2px solid var(--bdr);border-right:1px solid var(--bdr)}
th .th-label{display:block;padding:7px 10px;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--mt);
  cursor:pointer;user-select:none}
th .th-label:hover{color:var(--acc)}
th .th-label .arrow{margin-left:4px;opacity:.5}
th .th-filter{padding:5px;border-top:1px solid var(--bdr)}
th .th-filter input,th .th-filter select{width:100%;background:var(--sur);border:1px solid var(--bdr);border-radius:5px;
  padding:4px 6px;color:var(--tx);font-size:11px;font-family:'Share Tech Mono',monospace;outline:none}
th .th-filter input:focus,th .th-filter select:focus{border-color:var(--acc)}
th .th-filter select option{background:var(--sur2)}

tbody td{padding:6px 10px;border-bottom:1px solid color-mix(in srgb,var(--bdr) 55%,transparent);
  border-right:1px solid color-mix(in srgb,var(--bdr) 40%,transparent)}
tbody tr:hover td{background:color-mix(in srgb,var(--acc) 4%,transparent)}
tbody tr.hidden{display:none}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;
  text-transform:uppercase;letter-spacing:.6px;border:1px solid currentColor}
.pname-cell{font-weight:700;font-family:'Rajdhani',sans-serif;font-size:13px}
.pill-link{color:var(--acc);text-decoration:none}
.pill-link:hover{text-decoration:underline}
.chk-prog{font-family:'Share Tech Mono',monospace;font-size:11px}
.chk-bar{display:inline-block;width:50px;height:6px;background:var(--sur2);border-radius:3px;overflow:hidden;
  vertical-align:middle;margin-right:5px}
.chk-fill{height:100%;background:var(--ok)}

.toolbar{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;padding:0 4px}
.toolbar input[type=text]{background:var(--sur2);border:1px solid var(--bdr);border-radius:7px;padding:7px 12px;
  color:var(--tx);font-size:13px;font-family:'Share Tech Mono',monospace;outline:none;min-width:220px}
.toolbar input:focus{border-color:var(--acc)}
</style>
</head>
<body>
<div class="bar">
  <span class="logo">DEVVAULT</span>
  <span style="color:var(--bdr);font-size:18px">|</span>
  <span style="font-size:14px;font-weight:700">📋 One-Page Report</span>
  <div class="bar-r">
    <span class="result-count" id="rcount"><?=count($projects)?> rows</span>
    <button class="btn btn-ghost" onclick="clearFilters()">✕ Clear Filters</button>
    <button class="btn btn-acc" onclick="exportCSV()">📤 Export Visible (CSV)</button>
    <a href="index.php" class="btn btn-ghost">← Back</a>
  </div>
</div>

<div class="content">
<div class="toolbar">
  <input type="text" id="globalSearch" placeholder="🔍 Search all columns..." oninput="applyFilters()">
</div>

<div class="tbl-wrap">
<table id="reportTbl">
  <thead>
    <tr id="headRow"></tr>
    <tr id="filterRow"></tr>
  </thead>
  <tbody id="tbody">
    <?php foreach($projects as $p):
      $tl = $p['technology']==='Other' ? $p['technology_other'] : $p['technology'];
      $dbTech = $p['db_technology']==='Other' ? $p['db_technology_other'] : $p['db_technology'];
      $sclr = $SC[$p['current_status']] ?? '#5a7a9a';
      $chkPct = $p['chk_total'] ? round($p['chk_done']/$p['chk_total']*100) : 0;
    ?>
    <tr>
      <td class="pname-cell"><?=htmlspecialchars($p['project_name'])?></td>
      <td><?=htmlspecialchars($p['parent_admin_dept']??'')?></td>
      <td><?=htmlspecialchars($p['department_name']??'')?></td>
      <td><?=htmlspecialchars($tl??'')?></td>
      <td><?=htmlspecialchars($p['website_app']??'')?></td>
      <td><span class="badge" style="color:<?=$sclr?>;border-color:<?=$sclr?>50"><?=$SL[$p['current_status']]??$p['current_status']?></span></td>
      <td><?=htmlspecialchars($p['nodal_officer_name']??'')?></td>
      <td><?=htmlspecialchars($p['nodal_contact']??'')?></td>
      <td><?=htmlspecialchars($p['app_ip']??'')?></td>
      <td><?=htmlspecialchars($p['app_os']==='Other'?$p['app_os_other']:$p['app_os']??'')?></td>
      <td><?=htmlspecialchars($p['db_ip']??'')?></td>
      <td><?=htmlspecialchars($dbTech??'')?></td>
      <td><?=$p['env_production_url']?'<a href="'.htmlspecialchars($p['env_production_url']).'" target="_blank" class="pill-link">🟢 prod</a>':''?></td>
      <td><?=$p['env_staging_url']?'<a href="'.htmlspecialchars($p['env_staging_url']).'" target="_blank" class="pill-link">🟡 staging</a>':''?></td>
      <td><?=htmlspecialchars($p['live_date']??'')?></td>
      <td><?=htmlspecialchars($p['last_audit_date']??'')?></td>
      <td><?=htmlspecialchars($p['total_visitor_counter']??'')?></td>
      <td>
        <div class="chk-prog">
          <span class="chk-bar"><span class="chk-fill" style="width:<?=$chkPct?>%"></span></span><?=$p['chk_done']?>/<?=$p['chk_total']?>
        </div>
      </td>
      <td><?=$p['doc_count']?> 📎</td>
      <td><?=htmlspecialchars($p['creator']??'')?></td>
      <td><?=date('d M Y',strtotime($p['updated_at']))?></td>
      <td><a href="project_form.php?id=<?=$p['id']?>" class="pill-link">✏ Edit</a></td>
    </tr>
    <?php endforeach;?>
  </tbody>
</table>
</div>
</div>

<script>
const COLS = [
  {key:'project',  label:'Project Name',     type:'text'},
  {key:'parent',   label:'Parent Dept',      type:'text'},
  {key:'dept',     label:'Department',       type:'select', options: <?=json_encode(array_values($depts))?>},
  {key:'tech',     label:'Technology',       type:'select', options: <?=json_encode(array_values($techs))?>},
  {key:'webapp',   label:'Web/App',          type:'select', options: ['Website','Application','Both','Mobile App','Portal','API','Other']},
  {key:'status',   label:'Status',           type:'select', options: <?=json_encode(array_values($SL))?>},
  {key:'officer',  label:'Nodal Officer',    type:'text'},
  {key:'contact',  label:'Contact',          type:'text'},
  {key:'appip',    label:'App IP',           type:'text'},
  {key:'appos',    label:'App OS',           type:'text'},
  {key:'dbip',     label:'DB IP',            type:'text'},
  {key:'dbtech',   label:'DB Technology',    type:'text'},
  {key:'prod',     label:'Production',       type:'text'},
  {key:'staging',  label:'Staging',          type:'text'},
  {key:'live',     label:'Live Date',        type:'text'},
  {key:'audit',    label:'Last Audit',       type:'text'},
  {key:'visitors', label:'Visitors',         type:'text'},
  {key:'checklist',label:'Checklist',        type:'text'},
  {key:'docs',     label:'Docs',             type:'text'},
  {key:'creator',  label:'Creator',          type:'text'},
  {key:'updated',  label:'Updated',          type:'text'},
  {key:'actions',  label:'',                 type:'none'},
];

const headRow = document.getElementById('headRow');
const filterRow = document.getElementById('filterRow');

COLS.forEach((col,i)=>{
  const th = document.createElement('th');
  const lbl = document.createElement('span');
  lbl.className='th-label';
  lbl.innerHTML = col.label + (col.type!=='none'?'<span class="arrow">⇕</span>':'');
  lbl.onclick = ()=>sortByCol(i);
  th.appendChild(lbl);
  headRow.appendChild(th);

  const fth = document.createElement('th');
  if(col.type==='text'){
    const inp=document.createElement('input');
    inp.type='text';inp.placeholder='filter...';inp.dataset.col=i;
    inp.oninput=applyFilters;
    const wrap=document.createElement('div');wrap.className='th-filter';wrap.appendChild(inp);
    fth.appendChild(wrap);
  } else if(col.type==='select'){
    const sel=document.createElement('select');sel.dataset.col=i;sel.onchange=applyFilters;
    sel.innerHTML='<option value="">All</option>'+col.options.map(o=>`<option value="${o}">${o}</option>`).join('');
    const wrap=document.createElement('div');wrap.className='th-filter';wrap.appendChild(sel);
    fth.appendChild(wrap);
  } else {
    const wrap=document.createElement('div');wrap.className='th-filter';
    fth.appendChild(wrap);
  }
  filterRow.appendChild(fth);
});

function applyFilters(){
  const filters = COLS.map((c,i)=>{
    const el = filterRow.children[i].querySelector('input,select');
    return el ? el.value.toLowerCase().trim() : '';
  });
  const global = document.getElementById('globalSearch').value.toLowerCase().trim();
  const rows = document.querySelectorAll('#tbody tr');
  let visible=0;
  rows.forEach(row=>{
    const cells = row.children;
    let ok=true;
    for(let i=0;i<filters.length;i++){
      if(!filters[i]) continue;
      const txt=(cells[i]?.textContent||'').toLowerCase();
      if(!txt.includes(filters[i])){ok=false;break}
    }
    if(ok && global){
      const allTxt = row.textContent.toLowerCase();
      if(!allTxt.includes(global)) ok=false;
    }
    row.classList.toggle('hidden',!ok);
    if(ok)visible++;
  });
  document.getElementById('rcount').textContent = visible+' rows';
}

function clearFilters(){
  filterRow.querySelectorAll('input,select').forEach(el=>el.value='');
  document.getElementById('globalSearch').value='';
  applyFilters();
}

let sortState={col:-1,asc:true};
function sortByCol(i){
  if(COLS[i].type==='none')return;
  const tbody=document.getElementById('tbody');
  const rows=Array.from(tbody.querySelectorAll('tr'));
  sortState.asc = sortState.col===i ? !sortState.asc : true;
  sortState.col=i;
  rows.sort((a,b)=>{
    const av=(a.children[i]?.textContent||'').trim();
    const bv=(b.children[i]?.textContent||'').trim();
    const an=parseFloat(av.replace(/,/g,'')), bn=parseFloat(bv.replace(/,/g,''));
    let cmp;
    if(!isNaN(an)&&!isNaN(bn)&&av!==''&&bv!=='') cmp=an-bn;
    else cmp=av.localeCompare(bv);
    return sortState.asc?cmp:-cmp;
  });
  rows.forEach(r=>tbody.appendChild(r));
}

function exportCSV(){
  const rows=[COLS.filter(c=>c.type!=='none').map(c=>c.label)];
  document.querySelectorAll('#tbody tr:not(.hidden)').forEach(tr=>{
    const cells=Array.from(tr.children).slice(0,-1); // exclude actions
    rows.push(cells.map(td=>'"'+td.textContent.trim().replace(/"/g,'""')+'"'));
  });
  const csv=rows.map(r=>r.join(',')).join('\\n');
  const blob=new Blob(['\\ufeff'+csv],{type:'text/csv;charset=utf-8;'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(blob);
  a.download='devvault_report_'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
}
</script>
</body>
</html>
