<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NAV History Downloader — WealthDash</title>
<style>
  :root{
    --bg:#f0f4f8;--card:#fff;--border:#e2e8f0;
    --blue:#2563eb;--green:#16a34a;--red:#dc2626;
    --yellow:#d97706;--orange:#ea580c;--purple:#7c3aed;
    --cyan:#0891b2;--muted:#64748b;--muted2:#cbd5e1;
    --text:#0f172a;--text2:#475569;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);font-size:14px}
  .header{background:#fff;border-bottom:1px solid var(--border);padding:12px 24px;display:flex;align-items:center;justify-content:space-between}
  .header h1{font-size:18px;font-weight:700;color:var(--cyan);display:flex;align-items:center;gap:8px}
  .htime{font-size:12px;color:var(--muted);font-variant-numeric:tabular-nums}
  .wrap{max-width:1400px;margin:0 auto;padding:20px 16px}

  /* STAT CARDS */
  .cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px;border-top:3px solid}
  .card .num{font-size:28px;font-weight:800;line-height:1;margin-bottom:4px}
  .card .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
  .card .sub{font-size:11px;color:var(--muted);margin-top:4px}
  .c-total{border-top-color:var(--blue)}    .c-total .num{color:var(--blue)}
  .c-done{border-top-color:var(--green)}    .c-done .num{color:var(--green)}
  .c-work{border-top-color:var(--yellow)}   .c-work .num{color:var(--yellow)}
  .c-pend{border-top-color:var(--muted2)}   .c-pend .num{color:var(--muted)}
  .c-err{border-top-color:var(--red)}       .c-err .num{color:var(--red)}

  /* PROGRESS */
  .prog-box{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:16px}
  .prog-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
  .prog-label{font-size:13px;font-weight:600;color:var(--text2)}
  .pct-lbl{font-size:20px;font-weight:800;color:var(--cyan)}
  .prog-track{background:#e2e8f0;border-radius:99px;height:12px;overflow:hidden}
  .prog-bar{height:100%;background:linear-gradient(90deg,var(--cyan),var(--blue));border-radius:99px;transition:width .6s ease;min-width:2px}
  .prog-meta{display:flex;justify-content:space-between;margin-top:6px;font-size:11px;color:var(--muted)}

  /* STATUS BREAKDOWN */
  .section{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:16px}
  .section-title{font-size:13px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px}
  .bk-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
  .bk-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
  .bk-name{width:120px;font-size:12px;color:var(--text2)}
  .bk-track{flex:1;height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden}
  .bk-fill{height:100%;border-radius:99px;transition:width .5s}
  .bk-val{width:60px;text-align:right;font-size:12px;font-weight:700}

  /* CURRENT DOWNLOADING */
  .live-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:16px}
  .live-title{font-size:12px;font-weight:700;color:var(--green);margin-bottom:8px;display:flex;align-items:center;gap:6px}
  .live-row{font-size:12px;color:var(--text2);padding:4px 0;border-bottom:1px solid #dcfce7;display:flex;justify-content:space-between}
  .live-row:last-child{border-bottom:none}
  .live-sc{font-weight:600;color:var(--text)}
  .pulse{display:inline-block;width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse 1.2s infinite}
  @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}

  /* TIMER */
  .timer-row{display:flex;gap:20px;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:12px 20px;margin-bottom:16px;align-items:center}
  .timer-item{display:flex;flex-direction:column;gap:2px}
  .timer-item .tval{font-size:18px;font-weight:800;font-variant-numeric:tabular-nums;color:var(--text)}
  .timer-item .tlbl{font-size:10px;color:var(--muted);text-transform:uppercase}
  .st-badge{margin-left:auto;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700}
  .st-running{background:#dcfce7;color:#16a34a}
  .st-idle{background:#f1f5f9;color:#64748b}
  .st-stopped{background:#fef3c7;color:#d97706}

  /* CONTROLS */
  .controls{display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 20px;margin-bottom:16px}
  .tbtn{padding:8px 18px;border:none;border-radius:7px;cursor:pointer;font-size:13px;font-weight:600;transition:opacity .2s}
  .tbtn:hover{opacity:.85}
  .tbtn:disabled{opacity:.4;cursor:not-allowed}
  .tbtn-green{background:#16a34a;color:#fff}
  .tbtn-red{background:#dc2626;color:#fff}
  .tbtn-orange{background:#ea580c;color:#fff}
  .tbtn-blue{background:#2563eb;color:#fff}
  .tbtn-gray{background:#94a3b8;color:#fff}
  .tbtn-cyan{background:#0891b2;color:#fff}
  .par-ctrl{display:flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid var(--border);border-radius:7px;padding:4px 10px}
  .par-ctrl span{font-size:12px;font-weight:600;color:var(--text2)}
  .par-val{font-size:15px;font-weight:800;min-width:28px;text-align:center;color:var(--cyan)}
  .par-btn{width:24px;height:24px;border:1px solid var(--border);background:#fff;border-radius:5px;cursor:pointer;font-size:14px;font-weight:700}

  /* SETUP NOTICE */
  .setup-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:16px 20px;margin-bottom:16px;display:none}
  .setup-box h3{color:var(--orange);font-size:14px;margin-bottom:8px}
  .setup-box p{font-size:12px;color:var(--text2);margin-bottom:10px}

  /* TABS + TABLE */
  .tabs{display:flex;gap:2px;border-bottom:2px solid var(--border);margin-bottom:0}
  .tab{padding:8px 16px;cursor:pointer;font-size:13px;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-2px}
  .tab.active{color:var(--cyan);border-bottom-color:var(--cyan)}
  .tab .tc{background:#e2e8f0;border-radius:99px;padding:1px 7px;font-size:11px;margin-left:4px}
  .tab.active .tc{background:var(--cyan);color:#fff}
  .tbl-wrap{overflow-x:auto}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th{background:#f8fafc;padding:8px 12px;text-align:left;font-weight:700;color:var(--text2);border-bottom:2px solid var(--border);position:sticky;top:0}
  td{padding:7px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
  tr:hover td{background:#f8fafc}
  .badge{padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700}
  .b-completed{background:#dcfce7;color:#16a34a}
  .b-pending{background:#f1f5f9;color:#64748b}
  .b-in_progress{background:#fef3c7;color:#d97706}
  .b-error{background:#fee2e2;color:#dc2626}
  .tbl-meta{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;font-size:11px;color:var(--muted);background:#f8fafc;border-top:1px solid var(--border)}
  .pg-btn{padding:3px 10px;border:1px solid var(--border);background:#fff;border-radius:5px;cursor:pointer;font-size:11px}
  .pg-btn:disabled{opacity:.4}
  .no-data{text-align:center;padding:40px;color:var(--muted);font-size:13px}
  .records-badge{font-size:10px;background:#eff6ff;color:#2563eb;padding:1px 6px;border-radius:99px}
</style>
</head>
<body>

<div class="header">
  <h1>📥 Full NAV History Downloader</h1>
  <div style="display:flex;align-items:center;gap:16px">
    <span id="last-refresh" style="font-size:11px;color:var(--muted)">—</span>
    <span class="htime" id="htime">—</span>
  </div>
</div>

<div class="wrap">

  <!-- SETUP NOTICE (shown when not seeded) -->
  <div class="setup-box" id="setup-box">
    <h3>⚠️ Setup Required</h3>
    <p>nav_download_progress table mein data nahi hai. Pehle "Initialize" button click karo to seed it from funds table.</p>
    <button class="tbtn tbtn-orange" onclick="doSetup()">🔧 Initialize Progress Table</button>
  </div>

  <!-- STAT CARDS -->
  <div class="cards">
    <div class="card c-total">
      <div class="num" id="c-total">—</div>
      <div class="lbl">Total Funds</div>
      <div class="sub" id="c-recs">— NAV records</div>
    </div>
    <div class="card c-pend">
      <div class="num" id="c-pend">—</div>
      <div class="lbl">Pending ⏳</div>
      <div class="sub">Waiting in queue</div>
    </div>
    <div class="card c-work">
      <div class="num" id="c-work">—</div>
      <div class="lbl">In Progress ⚙️</div>
      <div class="sub">Currently fetching</div>
    </div>
    <div class="card c-err">
      <div class="num" id="c-err">—</div>
      <div class="lbl">⚠ Errors</div>
      <div class="sub">Click retry to fix</div>
    </div>
    <div class="card c-done">
      <div class="num" id="c-done">—</div>
      <div class="lbl">Downloaded ✅</div>
      <div class="sub" id="c-done-sub">Latest: —</div>
    </div>
  </div>

  <!-- PROGRESS BAR -->
  <div class="prog-box">
    <div class="prog-top">
      <span class="prog-label">Overall Download Progress</span>
      <span class="pct-lbl" id="pct-lbl">0%</span>
    </div>
    <div class="prog-track"><div class="prog-bar" id="bar" style="width:0%"></div></div>
    <div class="prog-meta">
      <span id="oldest-dl">Oldest: —</span>
      <span id="latest-dl">Latest: —</span>
    </div>
  </div>

  <!-- STATUS BREAKDOWN -->
  <div class="section">
    <div class="section-title">📊 Status Breakdown</div>
    <div class="bk-row"><div class="bk-dot" style="background:var(--green)"></div><div class="bk-name">Downloaded</div><div class="bk-track"><div class="bk-fill" id="bk-bar-done" style="background:var(--green);width:0%"></div></div><div class="bk-val" id="bk-done">0</div></div>
    <div class="bk-row"><div class="bk-dot" style="background:var(--yellow)"></div><div class="bk-name">In Progress</div><div class="bk-track"><div class="bk-fill" id="bk-bar-work" style="background:var(--yellow);width:0%"></div></div><div class="bk-val" id="bk-work">0</div></div>
    <div class="bk-row"><div class="bk-dot" style="background:var(--muted2)"></div><div class="bk-name">Pending</div><div class="bk-track"><div class="bk-fill" id="bk-bar-pend" style="background:var(--muted2);width:0%"></div></div><div class="bk-val" id="bk-pend">0</div></div>
    <div class="bk-row"><div class="bk-dot" style="background:var(--red)"></div><div class="bk-name">Errors</div><div class="bk-track"><div class="bk-fill" id="bk-bar-err" style="background:var(--red);width:0%"></div></div><div class="bk-val" id="bk-err">0</div></div>
    <div style="margin-top:10px;font-size:12px;color:var(--muted2);padding-top:8px;border-top:1px solid var(--border)">
      Effective Total: <strong id="bk-total">—</strong>
    </div>
  </div>

  <!-- CURRENTLY DOWNLOADING -->
  <div class="live-box" id="live-box" style="display:none">
    <div class="live-title"><span class="pulse"></span> Currently Downloading</div>
    <div id="live-funds"></div>
  </div>

  <!-- TIMER -->
  <div class="timer-row">
    <div class="timer-item"><div class="tval" id="t-elapsed">00:00:00</div><div class="tlbl">Total Elapsed</div></div>
    <div class="timer-item"><div class="tval" id="t-session">00:00:00</div><div class="tlbl">This Session</div></div>
    <div class="timer-item"><div class="tval" id="t-today">—</div><div class="tlbl">Today</div></div>
    <span class="st-badge st-idle" id="run-st">● Idle</span>
  </div>

  <!-- CONTROLS -->
  <div class="controls">
    <button class="tbtn tbtn-green" id="btn-run" onclick="startProcessor()">▶ Start Download</button>
    <button class="tbtn tbtn-red"   id="btn-stop" onclick="doStop()" style="display:none">⏹ Stop</button>

    <div class="par-ctrl">
      <span>⚡ PARALLEL</span>
      <button class="par-btn" onclick="adjPar(-5)">−</button>
      <span class="par-val" id="par-val">8</span>
      <button class="par-btn" onclick="adjPar(5)">+</button>
      <span style="font-size:11px;color:var(--red)" id="par-warn"></span>
    </div>

    <button class="tbtn tbtn-orange" onclick="doRetry()">↺ Retry Errors</button>
    <button class="tbtn tbtn-cyan"   onclick="doExport()">📤 Export CSV</button>
    <button class="tbtn tbtn-gray"   onclick="doReset()">🔄 Full Reset</button>
    <span id="ctrl-msg" style="font-size:12px;color:var(--muted);margin-left:auto"></span>
  </div>

  <!-- TABLE SECTION -->
  <div class="section" style="padding:0">
    <div class="tabs" style="padding:0 16px;padding-top:12px">
      <div class="tab active" onclick="switchTab('pending')"   id="tab-pending">   ⏳ Pending   <span class="tc" id="tc-p">0</span></div>
      <div class="tab"        onclick="switchTab('working')"   id="tab-working">   ⚙️ Working   <span class="tc" id="tc-w">0</span></div>
      <div class="tab"        onclick="switchTab('errors')"    id="tab-errors">    ⚠ Errors    <span class="tc" id="tc-e">0</span></div>
      <div class="tab"        onclick="switchTab('completed')" id="tab-completed"> ✅ Completed <span class="tc" id="tc-c">0</span></div>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Scheme Code</th>
            <th>Scheme Name</th>
            <th>Category</th>
            <th>From Date</th>
            <th>Last Downloaded</th>
            <th>Records Saved</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="tbl-body"><tr><td colspan="8" class="no-data">Loading...</td></tr></tbody>
      </table>
    </div>
    <div class="tbl-meta">
      <span id="tbl-info">—</span>
      <div style="display:flex;gap:6px;align-items:center">
        <button class="pg-btn" id="pg-prev" onclick="changePage(-1)">← Prev</button>
        <span id="pg-info" style="font-size:11px">Page 1</span>
        <button class="pg-btn" id="pg-next" onclick="changePage(1)">Next →</button>
      </div>
    </div>
  </div>

</div><!-- /wrap -->

<script>
const API = 'api.php';
let pollTimer    = null;
let clockTimer   = null;
let sessionStart = null;
let totalStart   = null;
let isRunning    = false;
let curTab       = 'pending';
let curPage      = 1;
let parallelSize = 8;
let processorWin = null;

const g = id => document.getElementById(id);
const fmt = n => Number(n||0).toLocaleString('en-IN');
const pad = n => String(n).padStart(2,'0');

function tick() {
    const now = new Date();
    g('htime').textContent = pad(now.getHours())+':'+pad(now.getMinutes())+':'+pad(now.getSeconds());
    if (sessionStart) {
        const s = Math.floor((Date.now()-sessionStart)/1000);
        g('t-session').textContent = pad(Math.floor(s/3600))+':'+pad(Math.floor(s%3600/60))+':'+pad(s%60);
    }
    if (totalStart) {
        const s = Math.floor((Date.now()-totalStart)/1000);
        g('t-elapsed').textContent = pad(Math.floor(s/3600))+':'+pad(Math.floor(s%3600/60))+':'+pad(s%60);
    }
}

function adjPar(delta) {
    parallelSize = Math.max(1, Math.min(50, parallelSize + delta));
    g('par-val').textContent = parallelSize;
    g('par-warn').textContent = parallelSize >= 30 ? '⚠ High risk' : '';
}

function setStatus(type, msg) {
    const el = g('run-st');
    el.className = 'st-badge st-' + type;
    el.textContent = msg;
}

async function fetchSummary() {
    try {
        const d = await fetch(API+'?action=summary&_='+Date.now(),{cache:'no-store'}).then(r=>r.json());
        if (d.error) return;

        // Setup check
        g('setup-box').style.display = d.seeded ? 'none' : 'block';

        // Cards
        animNum('c-total', fmt(d.total));
        animNum('c-done',  fmt(d.completed));
        animNum('c-work',  fmt(d.working));
        animNum('c-pend',  fmt(d.pending));
        animNum('c-err',   fmt(d.errors));
        g('c-recs').textContent     = fmt(d.total_records) + ' NAV records';
        g('c-done-sub').textContent = d.counts?.latest_dl || '—';

        // Progress
        g('pct-lbl').textContent    = d.pct + '%';
        g('bar').style.width        = d.pct + '%';
        g('oldest-dl').textContent  = 'Oldest: ' + (d.counts?.oldest_dl || '—');
        g('latest-dl').textContent  = 'Latest: ' + (d.counts?.latest_dl || '—');

        // Breakdown
        const ef = Math.max(d.total || 1, 1);
        const setBk  = (id,v) => { const e=g('bk-'+id);  if(e) e.textContent=fmt(v); };
        const setBar = (id,v) => { const e=g('bk-bar-'+id); if(e) e.style.width=Math.min(100,Math.round(v/ef*100))+'%'; };
        setBk('done',d.completed); setBar('done',d.completed);
        setBk('work',d.working);   setBar('work',d.working);
        setBk('pend',d.pending);   setBar('pend',d.pending);
        setBk('err', d.errors);    setBar('err', d.errors);
        g('bk-total').textContent = fmt(d.total);

        // Tab counts
        g('tc-p').textContent = fmt(d.pending);
        g('tc-w').textContent = fmt(d.working);
        g('tc-c').textContent = fmt(d.completed);
        g('tc-e').textContent = fmt(d.errors);

        // Current downloading
        if (d.current_funds && d.current_funds.length > 0) {
            g('live-box').style.display = 'block';
            g('live-funds').innerHTML = d.current_funds.map(f => `
                <div class="live-row">
                    <span class="live-sc">${f.scheme_code}</span>
                    <span>${f.scheme_name || '—'}</span>
                    <span>${f.from_date || 'inception'} → ${f.last_downloaded_date || 'downloading...'}</span>
                    <span class="records-badge">${fmt(f.records_saved)} records</span>
                </div>
            `).join('');
        } else {
            g('live-box').style.display = 'none';
        }

        // Today date
        g('t-today').textContent = d.today;

        // Running state
        const running = d.working > 0 || (processorWin && !processorWin.closed);
        if (running && !isRunning) {
            isRunning = true;
            if (!sessionStart) sessionStart = Date.now();
            setStatus('running','● Running');
            g('btn-run').style.display  = 'none';
            g('btn-stop').style.display = 'inline-block';
        } else if (!running && isRunning) {
            isRunning = false;
            setStatus('idle','● Idle');
            g('btn-run').style.display  = 'inline-block';
            g('btn-stop').style.display = 'none';
        }

        // Last refresh
        const n=new Date();
        g('last-refresh').textContent = '↻ '+pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds());

    } catch(e) {}
}

async function loadTable() {
    const res = await fetch(`${API}?action=table&tab=${curTab}&page=${curPage}&_=${Date.now()}`,{cache:'no-store'}).then(r=>r.json());
    const body = g('tbl-body');

    if (!res.rows || res.rows.length === 0) {
        body.innerHTML = `<tr><td colspan="8" class="no-data">No records</td></tr>`;
    } else {
        body.innerHTML = res.rows.map((r,i) => `
            <tr>
                <td>${((curPage-1)*50)+i+1}</td>
                <td><code style="font-size:11px">${r.scheme_code}</code></td>
                <td style="max-width:280px">${r.scheme_name||'—'}</td>
                <td>${r.category||'—'}</td>
                <td style="font-size:11px">${r.from_date||'—'}</td>
                <td style="font-size:11px;font-weight:600">${r.last_downloaded_date||'—'}</td>
                <td><span class="records-badge">${fmt(r.records_saved)}</span></td>
                <td><span class="badge b-${r.status}">${r.status}</span>${r.error_message?`<div style="font-size:10px;color:var(--red);margin-top:2px">${r.error_message}</div>`:''}</td>
            </tr>
        `).join('');
    }

    const total = res.total_rows || 0;
    const pages = res.pages || 1;
    g('tbl-info').textContent     = `Showing ${((curPage-1)*50)+1}–${Math.min(curPage*50, total)} of ${fmt(total)} records`;
    g('pg-info').textContent      = `Page ${curPage} of ${pages}`;
    g('pg-prev').disabled         = curPage <= 1;
    g('pg-next').disabled         = curPage >= pages;
}

function switchTab(tab) {
    curTab  = tab;
    curPage = 1;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    g('tab-'+tab).classList.add('active');
    loadTable();
}

function changePage(delta) {
    curPage += delta;
    loadTable();
}

async function startProcessor() {
    await fetch(API+'?action=clear_stop',{method:'POST'}).catch(()=>{});
    sessionStart = Date.now();
    if (!totalStart) totalStart = Date.now();

    const url = `processor.php?parallel=${parallelSize}`;
    processorWin = window.open(url, '_blank', 'width=900,height=500');

    setStatus('running','● Running');
    g('btn-run').style.display  = 'none';
    g('btn-stop').style.display = 'inline-block';
    isRunning = true;

    startPolling();
    ctrlMsg('⚙️ Processor started in new window...');
}

async function doStop() {
    const res = await fetch(API+'?action=stop',{method:'POST'}).then(r=>r.json());
    ctrlMsg(res.message || 'Stop requested.');
    setStatus('stopped','● Stopping...');
}

async function doRetry() {
    const res = await fetch(API+'?action=retry_errors',{method:'POST'}).then(r=>r.json());
    ctrlMsg(`↺ ${fmt(res.count)} errors reset to pending.`);
    fetchSummary(); loadTable();
}

async function doSetup() {
    const res = await fetch(API+'?action=setup',{method:'POST'}).then(r=>r.json());
    ctrlMsg(`✅ Seeded ${fmt(res.inserted)} funds. Total: ${fmt(res.total)}`);
    fetchSummary(); loadTable();
}

async function doReset() {
    if (!confirm('⚠️ Sab download progress reset ho jayega (nav_history data nahi hatega). Continue?')) return;
    await fetch(API+'?action=reset',{method:'POST'});
    ctrlMsg('🔄 Full reset done.');
    fetchSummary(); loadTable();
}

function doExport() {
    window.location = API + '?action=export';
}

function ctrlMsg(msg) {
    const el = g('ctrl-msg');
    el.textContent = msg;
    setTimeout(() => el.textContent = '', 5000);
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(() => {
        fetchSummary();
        loadTable();
    }, 3000);
}

function animNum(id, val) {
    const el = g(id);
    if (el) el.textContent = val;
}

// Init
clockTimer = setInterval(tick, 1000);
tick();
fetchSummary();
loadTable();
startPolling();
</script>
</body>
</html>
