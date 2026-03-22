<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Peak NAV Tracker — WealthDash</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f1f5f9;--surface:#fff;--surf2:#f8fafc;--border:#e2e8f0;
  --text:#1e293b;--muted:#94a3b8;
  --blue:#3b82f6;--green:#16a34a;--yellow:#d97706;--red:#dc2626;--purple:#7c3aed;--orange:#ea580c;
}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',sans-serif;font-size:14px;padding:24px}

.header{display:flex;align-items:center;gap:10px;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid var(--border)}
.header h1{font-size:1.3rem;color:var(--blue);font-weight:700}
.live-dot{width:9px;height:9px;border-radius:50%;background:var(--green);animation:blink 1.5s infinite;flex-shrink:0}
@keyframes blink{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(.6)}}
.ts{color:var(--muted);font-size:.85rem;margin-left:auto;font-family:monospace;font-weight:600}

/* CARDS */
.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px}
.card{background:var(--surface);border-radius:10px;padding:14px 16px;text-align:center;border:1px solid var(--border);border-top:4px solid var(--border);box-shadow:0 1px 4px rgba(0,0,0,.06);position:relative;overflow:hidden}
.card .num{font-size:1.8rem;font-weight:700;line-height:1;margin-bottom:4px;font-variant-numeric:tabular-nums;transition:all .3s}
.card .lbl{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.card .sub{font-size:.68rem;color:var(--muted);margin-top:3px}
.card[data-tip]:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100%+6px);left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:5px 10px;border-radius:6px;font-size:.72rem;white-space:nowrap;z-index:99;pointer-events:none}
.c-total  {border-top-color:var(--blue)}  .c-total  .num{color:var(--blue)}
.c-work   {border-top-color:var(--yellow)}.c-work   .num{color:var(--yellow)}
.c-done   {border-top-color:var(--green)} .c-done   .num{color:var(--green)}
.c-stale  {border-top-color:var(--orange)}.c-stale  .num{color:var(--orange)}
.c-errors {border-top-color:var(--red);cursor:pointer}   .c-errors .num{color:var(--red)}
.c-errors:hover{background:#fff5f5}

/* Number flip animation */
@keyframes numFlip{0%{opacity:0;transform:translateY(40%)}100%{opacity:1;transform:translateY(0)}}
.num-anim{animation:numFlip .6s cubic-bezier(.15,.8,.2,1) both}

/* PROGRESS */
.prog-box{background:var(--surface);border-radius:10px;padding:14px 18px;margin-bottom:14px;border:1px solid var(--border);box-shadow:0 1px 4px rgba(0,0,0,.06)}
.prog-meta{display:flex;justify-content:space-between;font-size:.82rem;color:var(--muted);margin-bottom:8px}
.prog-pct{color:var(--blue);font-weight:700;font-size:1rem;font-variant-numeric:tabular-nums}
.bar-track{background:var(--bg);border-radius:99px;height:14px;overflow:hidden;border:1px solid var(--border)}
.bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--blue),var(--purple));transition:width .8s ease;min-width:3px}
.prog-dates{display:flex;justify-content:space-between;margin-top:8px;font-size:.72rem;color:var(--muted)}
.prog-dates b{color:var(--text)}

/* INFO ROW */
.info-row{display:flex;align-items:center;gap:20px;background:var(--surface);border-radius:10px;padding:12px 18px;margin-bottom:14px;border:1px solid var(--border);box-shadow:0 1px 4px rgba(0,0,0,.06);flex-wrap:wrap}
.tbox{display:flex;flex-direction:column;align-items:center;gap:2px}
.tlbl{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.tval{font-size:1.05rem;font-weight:700;color:var(--blue);font-family:'Courier New',monospace}
.divider{width:1px;height:36px;background:var(--border);flex-shrink:0}
.run-st{font-size:.85rem;font-weight:600}
.st-idle{color:var(--muted)}.st-run{color:var(--green)}.st-stop{color:var(--yellow)}.st-done{color:var(--green)}

/* ALERTS */
.alert{display:none;border-radius:8px;padding:10px 16px;margin-bottom:12px;font-size:.85rem;font-weight:600;align-items:center;gap:10px}
.alert.show{display:flex}
.al-warn{background:#fef9c3;border:2px solid var(--yellow);color:#92400e}
.al-err {background:#fee2e2;border:2px solid var(--red);color:#991b1b}
.al-done{background:#dcfce7;border:2px solid var(--green);color:#14532d}
.al-btn{margin-left:auto;padding:6px 16px;border:none;border-radius:5px;cursor:pointer;font-weight:700;font-size:.82rem}
.ab-y{background:var(--yellow);color:#fff}
.ab-r{background:var(--red);color:#fff}
.ab-g{background:var(--green);color:#fff}

/* ACTIONS */
.actions{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center}
.btn{padding:8px 18px;border-radius:7px;border:none;cursor:pointer;font-size:.85rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(0,0,0,.1);transition:opacity .15s,transform .1s}
.btn:hover{opacity:.87}.btn:active{transform:scale(.97)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
.b-run   {background:var(--blue);color:#fff}
.b-export{background:var(--green);color:#fff}
.b-retry {background:var(--orange);color:#fff}
.b-reset {background:var(--red);color:#fff}

/* PARALLEL CTRL */
.par-ctrl{display:flex;align-items:center;gap:0;border:2px solid var(--blue);border-radius:7px;overflow:hidden;height:38px}
.par-ctrl label{padding:0 10px;font-size:.75rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;background:var(--surf2);height:100%;display:flex;align-items:center;border-right:1px solid var(--border);white-space:nowrap}
.pc-btn{width:34px;height:100%;border:none;background:var(--surf2);cursor:pointer;font-size:1.2rem;font-weight:700;color:var(--blue);transition:background .1s;display:flex;align-items:center;justify-content:center}
.pc-btn:hover{background:#dbeafe}.pc-btn:active{background:#bfdbfe}
.pc-num{width:44px;text-align:center;font-size:1rem;font-weight:700;color:var(--text);background:var(--surface);border:none;border-left:1px solid var(--border);border-right:1px solid var(--border);height:100%;outline:none}
.pc-hint{font-size:.72rem;padding-left:6px;min-width:100px;font-weight:600}

/* TABS */
.tabs{display:flex;background:var(--surface);border:1px solid var(--border);border-bottom:none;border-radius:10px 10px 0 0;padding:0 8px}
.tab-btn{padding:11px 20px;background:transparent;border:none;border-bottom:3px solid transparent;color:var(--muted);font-size:.85rem;font-weight:600;cursor:pointer;margin-bottom:-1px;transition:color .15s,border-color .15s}
.tab-btn:hover{color:var(--text)}
.tab-btn.active[data-tab="pending"]  {color:var(--text);  border-bottom-color:var(--text)}
.tab-btn.active[data-tab="working"]  {color:var(--yellow);border-bottom-color:var(--yellow)}
.tab-btn.active[data-tab="completed"]{color:var(--green); border-bottom-color:var(--green)}
.tab-btn.active[data-tab="errors"]   {color:var(--red);   border-bottom-color:var(--red)}
.tc{display:inline-block;font-size:.68rem;padding:1px 7px;border-radius:99px;background:var(--bg);color:var(--muted);margin-left:5px}
.tab-btn.active .tc{background:currentColor;color:#fff}

/* TABLE */
.tpanel{background:var(--surface);border:1px solid var(--border);border-top:2px solid var(--border);border-radius:0 0 10px 10px;padding:14px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.tbl-meta{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.tbl-info{color:var(--muted);font-size:.75rem}
.exp-row{display:flex;gap:6px}
.exp-btn{padding:4px 10px;border:1px solid var(--border);background:var(--surf2);border-radius:5px;cursor:pointer;font-size:.73rem;color:var(--text);transition:all .1s}
.exp-btn:hover{border-color:var(--green);color:var(--green);background:#f0fdf4}

table{width:100%;border-collapse:collapse}
th{background:var(--surf2);padding:9px 12px;text-align:left;color:var(--muted);font-size:.73rem;font-weight:600;border-bottom:2px solid var(--border);white-space:nowrap;position:relative}
th[data-tip]:hover::after{content:attr(data-tip);position:absolute;top:calc(100%+4px);left:0;background:#1e293b;color:#fff;padding:5px 10px;border-radius:5px;font-size:.7rem;white-space:nowrap;z-index:99;font-weight:400}
td{padding:8px 12px;border-bottom:1px solid var(--border);font-size:.82rem;vertical-align:middle}
tr:hover td{background:var(--surf2)}
.nv{color:var(--green);font-weight:600}
.cd{color:var(--blue);font-size:.76rem;font-family:monospace}
.dm{color:var(--muted);font-size:.74rem}
.stale-tag{display:inline-block;padding:1px 6px;background:#fff7ed;border:1px solid #fed7aa;color:#c2410c;border-radius:4px;font-size:.66rem;margin-left:4px}
.badge{display:inline-block;padding:3px 9px;border-radius:99px;font-size:.69rem;font-weight:700}
.b-pending    {background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
.b-in_progress{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}
.b-completed  {background:#dcfce7;color:#15803d;border:1px solid #86efac}
.b-error      {background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}

/* PAGINATION */
.pagination{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-top:12px}
.pg-btn{padding:5px 10px;background:var(--surf2);border:1px solid var(--border);border-radius:5px;color:var(--muted);font-size:.74rem;cursor:pointer;transition:all .1s}
.pg-btn:hover{border-color:var(--blue);color:var(--blue)}
.pg-btn.active{background:var(--blue);color:#fff;border-color:var(--blue);font-weight:700}
.pg-btn:disabled{opacity:.3;cursor:not-allowed}
.pg-info{color:var(--muted);font-size:.72rem;margin-left:auto}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--surface);border-radius:12px;padding:24px;max-width:700px;width:90%;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal h2{font-size:1.1rem;color:var(--red);margin-bottom:14px;display:flex;justify-content:space-between}
.modal-close{cursor:pointer;color:var(--muted);font-size:1.2rem;background:none;border:none}
.err-list{display:flex;flex-direction:column;gap:6px}
.err-item{background:#fff5f5;border:1px solid #fecaca;border-radius:6px;padding:8px 12px;font-size:.8rem}
.err-item .ec{color:var(--blue);font-family:monospace;font-weight:600}
.err-item .em{color:var(--red);margin-top:2px}
.err-item .et{color:var(--muted);font-size:.7rem}

/* LOADING */
.loading-row td{text-align:center;padding:36px;color:var(--muted)}
.spinner{display:inline-block;width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--blue);border-radius:50%;animation:spin .8s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes spin{to{transform:rotate(360deg)}}

@media(max-width:700px){
  .cards{grid-template-columns:repeat(2,1fr)}
  th:nth-child(n+5),td:nth-child(n+5){display:none}
}
</style>
</head>
<body>

<div class="header">
  <div class="live-dot"></div>
  <h1>📈 Peak NAV Tracker</h1>
  <span class="ts" id="ts">--:--:--</span>
</div>

<div class="cards">
  <div class="card c-total"  data-tip="Total active schemes in funds table">
    <div class="num" id="c-total">—</div><div class="lbl">Total Schemes</div>
  </div>
  <div class="card c-stale"  data-tip="Completed but checked before today — incremental update next run">
    <div class="num" id="c-stale">—</div><div class="lbl">Needs Update</div>
    <div class="sub">Old data, incremental next run</div>
  </div>
  <div class="card c-work"   data-tip="Pending + currently processing">
    <div class="num" id="c-work">—</div><div class="lbl">Pending + Working</div>
  </div>
  <div class="card c-done"   data-tip="Schemes with peak NAV data saved">
    <div class="num" id="c-done">—</div><div class="lbl">Completed</div>
    <div class="sub" id="c-sub">—</div>
  </div>
  <div class="card c-errors" data-tip="Click to see error details" onclick="showErrors()">
    <div class="num" id="c-errors">—</div><div class="lbl">⚠ Errors</div>
    <div class="sub">Click to view</div>
  </div>
</div>

<div class="prog-box">
  <div class="prog-meta">
    <span>Overall Progress</span>
    <span class="prog-pct" id="pct-lbl">0%</span>
  </div>
  <div class="bar-track"><div class="bar-fill" id="bar" style="width:0%"></div></div>
  <div class="prog-dates">
    <span>Oldest checked: <b id="oldest-date">—</b></span>
    <span>Latest checked: <b id="latest-date">—</b></span>
  </div>
</div>

<div class="info-row">
  <div class="tbox">
    <span class="tlbl">⏱ Total Elapsed</span>
    <span class="tval" id="total-timer">00:00:00</span>
  </div>
  <div class="divider"></div>
  <div class="tbox">
    <span class="tlbl">🔄 This Session</span>
    <span class="tval" id="sess-timer">00:00:00</span>
  </div>
  <div class="divider"></div>
  <div class="tbox">
    <span class="tlbl">📅 Today</span>
    <span class="tval" id="today-val" style="font-size:.82rem">—</span>
  </div>
  <div class="divider"></div>
  <span class="run-st st-idle" id="run-st">● Idle</span>
</div>

<div class="alert al-warn" id="al-stop">
  ⚠️ Processor stopped — schemes still pending!
  <button class="al-btn ab-y" onclick="runProcessor()">▶ Restart Now</button>
</div>
<div class="alert al-err" id="al-err">
  <span id="al-err-msg">Some errors occurred.</span>
  <button class="al-btn ab-r" onclick="retryErrors()">↺ Retry Errors</button>
</div>
<div class="alert al-done" id="al-done">
  ✅ All schemes up to date! &nbsp;<button class="al-btn ab-g" onclick="hideAlert('al-done')">✕</button>
</div>

<div class="actions">
  <button class="btn b-run" id="btn-run" onclick="runProcessor()">▶ Run Processor</button>
  <button class="btn b-reset" id="btn-stop" onclick="stopProcessor()" style="display:none;background:#dc2626">⏹ Stop</button>
  <div class="par-ctrl" title="Parallel API requests. Higher=faster but may cause errors.">
    <label>⚡ PARALLEL</label>
    <button class="pc-btn" onclick="chgPar(-1)">−</button>
    <input  class="pc-num" id="pc-num" type="text" value="8" readonly>
    <button class="pc-btn" onclick="chgPar(+1)">+</button>
  </div>
  <span class="pc-hint" id="pc-hint">8 — Good</span>
  <button class="btn b-export" onclick="doExport('all')">⬇ Export All</button>
  <button class="btn b-export" style="background:#15803d" onclick="doExport('completed')">⬇ Completed</button>
  <button class="btn b-retry"  id="btn-retry" onclick="retryErrors()">↺ Retry Errors</button>
  <button class="btn b-reset"  id="btn-reset" onclick="resetAll()">⟳ Full Reset</button>
</div>

<div class="tabs">
  <button class="tab-btn active" data-tab="pending"   onclick="switchTab('pending')">  ⏳ Pending   <span class="tc" id="tc-p">—</span></button>
  <button class="tab-btn"        data-tab="working"   onclick="switchTab('working')">  🔄 Working   <span class="tc" id="tc-w">—</span></button>
  <button class="tab-btn"        data-tab="completed" onclick="switchTab('completed')">✅ Completed <span class="tc" id="tc-c">—</span></button>
  <button class="tab-btn"        data-tab="errors"    onclick="switchTab('errors')">   ⚠ Errors    <span class="tc" id="tc-e">—</span></button>
</div>

<div class="tpanel">
  <div class="tbl-meta">
    <div style="display:flex;gap:12px;align-items:center"><div class="tbl-info" id="tbl-info">Loading...</div><span style="font-size:.7rem;color:var(--muted)" id="last-refresh"></span></div>
    <div class="exp-row">
      <button class="exp-btn" onclick="doExport('completed')">⬇ CSV (Done)</button>
      <button class="exp-btn" onclick="doExport('all')">⬇ CSV (All)</button>
    </div>
  </div>
  <table>
    <thead><tr>
      <th>#</th>
      <th data-tip="AMFI code — used by mfapi.in">Scheme Code</th>
      <th>Scheme Name</th>
      <th data-tip="All-time highest NAV across full history">Highest NAV (₹)</th>
      <th data-tip="Date when highest NAV was recorded">Peak Date</th>
      <th data-tip="Checked up to this date. Next run only scans AFTER this date.">Checked Till</th>
      <th>Status</th>
    </tr></thead>
    <tbody id="tbody">
      <tr class="loading-row"><td colspan="7"><span class="spinner"></span> Loading...</td></tr>
    </tbody>
  </table>
  <div class="pagination" id="pagination"></div>
</div>

<div class="modal-overlay" id="err-modal">
  <div class="modal">
    <h2>⚠ Error Details <button class="modal-close" onclick="closeModal()">✕</button></h2>
    <div class="err-list" id="err-list">Loading...</div>
  </div>
</div>

<script>
// ── STATE ─────────────────────────────────────────────
let activeTab  = 'pending';
let activePage = 1;
let totalStart = null;
let sessStart  = null;
let procWin    = null;
let isRunning  = false;
let userStopped = false;
let pollTimer  = null;
let prevDone   = 0;
let stuckSecs  = 0;
let activeXhr  = null;   // keep reference so we can abort on stop

// ── INIT ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    totalStart = parseInt(localStorage.getItem('pkTotal') || '0') || null;
    // Restore stopped state across page refreshes
    userStopped = localStorage.getItem('pkStopped') === '1';
    initParallel();
    fetchSummary();
    fetchTable();
    setInterval(fetchSummary, 3000);
    setInterval(() => fetchTable(false), 6000);
    setInterval(tickTimers, 1000);
    setInterval(updateClock, 1000);
    updateClock();
    // Auto-start if launched from admin panel
    const params = new URLSearchParams(window.location.search);
    if (params.get('autostart') === '1' && !userStopped) {
        setTimeout(runProcessor, 800);
    }
});

// ── CLOCK & TIMERS ────────────────────────────────────
function updateClock() {
    const n = new Date();
    g('ts').textContent = pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds());
}
function tickTimers() {
    const now = Date.now();
    if (totalStart) g('total-timer').textContent = fmtMs(now - totalStart);
    if (sessStart)  g('sess-timer').textContent  = fmtMs(now - sessStart);
}

// ── PROCESSOR CONTROL ─────────────────────────────────
function runProcessor() {
    userStopped = false;
    localStorage.removeItem('pkStopped');
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    if (activeXhr) { activeXhr.abort(); activeXhr = null; }

    // Start timers
    if (!totalStart) { totalStart = Date.now(); localStorage.setItem('pkTotal', totalStart); }
    sessStart = Date.now();
    prevDone  = 0;
    stuckSecs = 0;

    // Hide alerts, lock button immediately
    ['al-stop','al-done','al-err'].forEach(id => g(id).classList.remove('show'));
    setStatus('run', '🟢 Running...');
    g('btn-run').textContent = '⏸ Running...';
    g('btn-run').disabled = true;
    const stopBtn = g('btn-stop');
    if (stopBtn) { stopBtn.style.display = 'inline-flex'; stopBtn.disabled = false; stopBtn.textContent = '⏹ Stop'; }

    // Mark running BEFORE XHR so no gap where button looks idle
    isRunning = true;

    // Clear any old stop flag
    fetch('api.php?action=clear_stop', {method:'POST'}).catch(()=>{});

    const parallel = getPar();
    const url = 'processor.php?t=' + Date.now() + '&parallel=' + parallel;

    const xhr = new XMLHttpRequest();
    activeXhr = xhr;
    xhr.open('GET', url, true);
    xhr.timeout = 150000;
    xhr.onload    = () => { activeXhr = null; onProcFinished(xhr.responseText); };
    xhr.onerror   = () => {
        activeXhr = null;
        if (!userStopped) {
            console.warn('XHR error — will auto-restart in 3s');
            setTimeout(() => { if (!isRunning && !userStopped) runProcessor(); }, 3000);
        }
    };
    xhr.ontimeout = () => {
        activeXhr = null;
        if (!userStopped) {
            console.warn('XHR timeout — checking progress then restarting');
            onProcFinished('');
        }
    };
    xhr.send();

    pollTimer = setInterval(checkProgress, 3000);
}

async function stopProcessor() {
    if (!confirm('⏹ Stop the processor?\n\nCurrent batch will finish then it will halt.\nProgress is saved — run again to continue.')) return;
    const stopBtn = g('btn-stop');
    if (stopBtn) { stopBtn.disabled = true; stopBtn.textContent = '⏳ Stopping...'; }

    // Set stopped state immediately — persisted across refreshes
    userStopped = true;
    localStorage.setItem('pkStopped', '1');
    isRunning = false;

    // Stop polling loop
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }

    // Abort the active XHR so onProcFinished doesn't trigger auto-restart
    if (activeXhr) { activeXhr.abort(); activeXhr = null; }

    // Update UI immediately
    g('btn-run').textContent = '▶ Run Processor';
    g('btn-run').disabled = false;
    if (stopBtn) { stopBtn.style.display = 'none'; }
    setStatus('stop', '⏸ Stop requested — finishing current batch...');

    try {
        // Tell processor.php to halt after current batch via DB flag
        const res = await fetch('api.php?action=stop', {method:'POST'}).then(r=>r.json());
        const t = document.createElement('div');
        t.textContent = '⏹ ' + (res.message || 'Stop requested');
        t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#1e293b;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.25)';
        document.body.appendChild(t);
        setTimeout(()=>t.remove(), 3500);
    } catch(e) {}

    g('al-stop').classList.add('show');
    fetchSummary();
}

async function onProcFinished(responseText) {
    // Called when processor.php finishes (success or timeout)
    isRunning = false;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }

    try {
        const d = await fetch('api.php?action=summary&_='+Date.now(),{cache:'no-store'}).then(r=>r.json());
        const c = d.counts;
        // remaining = ALL unfinished work: pending + working + needs_update + errors
        const remaining = parseInt(d.not_done || 0);
        const errors    = parseInt(c.errors||0);
        if (remaining === 0) {
            // Truly done — enable run button, hide stop
            g('btn-run').textContent = '▶ Run Processor';
            g('btn-run').disabled = false;
            const sb = g('btn-stop'); if (sb) sb.style.display = 'none';
            setStatus('done','✅ All Complete!');
            g('al-done').classList.add('show');
            localStorage.removeItem('pkTotal'); totalStart = null;
        } else {
            if (!userStopped) {
                // Auto-restart — keep button locked, restart immediately
                setStatus('run','🔄 Next batch starting...');
                g('btn-run').textContent = '⏸ Running...';
                g('btn-run').disabled = true;
                setTimeout(() => {
                    if (!isRunning && !userStopped) runProcessor();
                }, 1500);
            } else {
                // User stopped — enable button
                g('btn-run').textContent = '▶ Run Processor';
                g('btn-run').disabled = false;
                const sb = g('btn-stop'); if (sb) sb.style.display = 'none';
                setStatus('stop','⏸ Paused — '+fmt(remaining)+' remaining');
                g('al-stop').classList.add('show');
            }
        }
        if (errors > 0) {
            g('al-err-msg').textContent = fmt(errors)+' schemes had errors. Click Retry Errors.';
            g('al-err').classList.add('show');
        }
    } catch(e) {
        // Fetch failed — keep button available so user can restart manually
        g('btn-run').textContent = '▶ Run Processor';
        g('btn-run').disabled = false;
        const sb = g('btn-stop'); if (sb) sb.style.display = 'none';
        setStatus('stop','⏸ Paused — click Restart');
        g('al-stop').classList.add('show');
    }
    fetchSummary(); fetchTable(false);
}

async function checkProgress() {
    // No popup — XHR handles completion via onProcFinished
    const winClosed = false;

    try {
        const d = await fetch('api.php?action=summary&_='+Date.now(),{cache:'no-store'}).then(r => r.json());
        const c = d.counts;
        const nowDone   = parseInt(c.completed || 0);
        const remaining = parseInt(d.not_done || 0);
        const errors    = parseInt(c.errors||0);

        // Live speed update in hint area
        if (nowDone > prevDone) {
            const delta = nowDone - prevDone;
            const speed = (delta / 3).toFixed(1);
            const p = getPar();
            updateHint(p, '~' + speed + '/s');
            prevDone  = nowDone;
            stuckSecs = 0;
        } else if (isRunning) {
            stuckSecs += 3;
            // If stuck for 30s with pending work and processor not responding — auto restart
            if (stuckSecs >= 30 && remaining > 0 && !userStopped) {
                console.warn('Processor appears stuck — auto-restarting');
                stuckSecs = 0;
                isRunning = false;
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                setTimeout(() => { if (!userStopped) runProcessor(); }, 1000);
                return;
            }
        }

        if (remaining === 0) {
            // All done — pending=0, working=0, needs_update=0, errors=0
            clearInterval(pollTimer); pollTimer = null;
            isRunning = false;
            g('btn-run').textContent = '▶ Run Processor';
            g('btn-run').disabled = false;
            const stopBtn = g('btn-stop');
            if (stopBtn) stopBtn.style.display = 'none';
            setStatus('done', '✅ All Complete!');
            g('al-done').classList.add('show');
            localStorage.removeItem('pkTotal');
            totalStart = null;
            fetchSummary(); fetchTable(false);
            return;
        }
    } catch(e) {}
}

function setStatus(type, msg) {
    const el = g('run-st');
    el.className = 'run-st st-' + type;
    el.textContent = msg;
}

// ── SUMMARY ───────────────────────────────────────────
async function fetchSummary() {
    try {
        const d = await fetch('api.php?action=summary').then(r => r.json());
        if (d.error) return;
        const c = d.counts;

        animNum('c-total',  fmt(c.total));
        animNum('c-work',   fmt(+c.pending + +c.working));
        animNum('c-done',   fmt(c.completed));
        animNum('c-stale',  fmt(c.needs_update||0));
        animNum('c-errors', fmt(c.errors));

        // Progress bar = up_to_date / effective_total
        // effective_total = up_to_date + pending + working + needs_update
        // needs_update wale "old completed" hain — today ka progress mein NOT done count
        g('pct-lbl').textContent = d.pct + '%';
        g('bar').style.width = d.pct + '%';
        g('c-sub').textContent = fmt(c.up_to_date||0) + ' updated today' + (d.effective_total ? ' of ' + fmt(d.effective_total) : '');
        g('oldest-date').textContent = c.oldest_update || '—';
        g('latest-date').textContent = c.latest_update || '—';
        g('today-val').textContent   = d.today;
        // update last-refresh indicator
        const lr = g('last-refresh');
        if (lr) { const n=new Date(); lr.textContent='↻ '+pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds()); }

        g('tc-p').textContent = fmt(c.pending);
        g('tc-w').textContent = fmt(c.working);
        g('tc-c').textContent = fmt(c.completed);
        g('tc-e').textContent = fmt(c.errors);

        // Show/hide Run/Stop buttons based on actual running state
        const stopBtn = g('btn-stop');
        const runBtn  = g('btn-run');
        if (stopBtn && runBtn) {
            if (isRunning) {
                stopBtn.style.display = 'inline-flex';
                runBtn.disabled = true;
                runBtn.textContent = '⏸ Running...';
            } else {
                stopBtn.style.display = 'none';
                runBtn.disabled = false;
                runBtn.textContent = '▶ Run Processor';
            }
        }
    } catch(e) {}
}

// Animate card number change
function animNum(id, newVal) {
    const el = g(id);
    if (!el) return;
    if (el.textContent === newVal) return;
    el.classList.remove('num-anim');
    void el.offsetWidth;
    el.textContent = newVal;
    el.classList.add('num-anim');
}

// ── TABLE ─────────────────────────────────────────────
async function fetchTable(loader = true) {
    if (loader) g('tbody').innerHTML = '<tr class="loading-row"><td colspan="7"><span class="spinner"></span> Loading...</td></tr>';
    try {
        const url = 'api.php?action=table&tab='+activeTab+'&page='+activePage+'&_='+Date.now();
        const d = await fetch(url, {cache:'no-store'}).then(r => r.json());
        if (d.rows !== undefined) renderTable(d.rows, d.page, d.pages, d.total_rows);
    } catch(e) {
        if (loader) g('tbody').innerHTML = '<tr class="loading-row"><td colspan="7">Fetch error — check api.php</td></tr>';
    }
}

function renderTable(rows, page, pages, total) {
    const off = (page-1)*50;
    g('tbl-info').textContent = 'Showing '+(off+1)+'–'+(off+rows.length)+' of '+total+' records';
    if (!rows.length) {
        g('tbody').innerHTML = '<tr class="loading-row"><td colspan="7">No records</td></tr>';
        g('pagination').innerHTML = '';
        return;
    }
    const today = new Date().toISOString().slice(0,10);
    g('tbody').innerHTML = rows.map((r,i) => {
        const nav  = r.highest_nav ? '₹'+parseFloat(r.highest_nav).toFixed(4) : '—';
        const name = esc(r.scheme_name||'—');
        const sn   = name.length>42 ? name.slice(0,42)+'…' : name;
        const lpd  = r.last_processed_date||'—';
        const stale = (r.status==='completed' && lpd!=='—' && lpd<today) ? '<span class="stale-tag">outdated</span>' : '';
        const statusCell = r.status==='error'
            ? `<td style="color:var(--red);font-size:.72rem" title="${esc(r.error_message||'')}">${esc(r.error_message||'error')}</td>`
            : `<td><span class="badge b-${r.status}">${r.status}</span></td>`;
        return `<tr>
            <td class="dm">${off+i+1}</td>
            <td class="cd">${esc(r.scheme_code)}</td>
            <td title="${name}">${sn}</td>
            <td class="nv">${nav}</td>
            <td class="dm">${r.highest_nav_date||'—'}</td>
            <td class="dm">${lpd}${stale}</td>
            ${statusCell}
        </tr>`;
    }).join('');
    renderPagination(page, pages);
}

function renderPagination(page, pages) {
    if (pages<=1){g('pagination').innerHTML='';return;}
    let h = `<button class="pg-btn" onclick="goPage(${page-1})" ${page===1?'disabled':''}>◀</button>`;
    let s=Math.max(1,page-3), e=Math.min(pages,page+3);
    if(s>1) h+=`<button class="pg-btn" onclick="goPage(1)">1</button>${s>2?'<span style="color:var(--muted);padding:0 4px">…</span>':''}`;
    for(let p=s;p<=e;p++) h+=`<button class="pg-btn ${p===page?'active':''}" onclick="goPage(${p})">${p}</button>`;
    if(e<pages) h+=`${e<pages-1?'<span style="color:var(--muted);padding:0 4px">…</span>':''}<button class="pg-btn" onclick="goPage(${pages})">${pages}</button>`;
    h+=`<button class="pg-btn" onclick="goPage(${page+1})" ${page===pages?'disabled':''}>▶</button>`;
    h+=`<span class="pg-info">Page ${page} / ${pages}</span>`;
    g('pagination').innerHTML = h;
}

function goPage(p){activePage=p;fetchTable();}
function switchTab(tab){
    activeTab=tab;activePage=1;
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));
    fetchTable();
}

// ── ERRORS MODAL ──────────────────────────────────────
async function showErrors() {
    g('err-modal').classList.add('open');
    g('err-list').innerHTML = '<span style="color:var(--muted)"><span class="spinner"></span> Loading...</span>';
    const d = await fetch('api.php?action=errors').then(r=>r.json());
    if (!d.rows.length) { g('err-list').innerHTML = '<p style="color:var(--muted)">No errors.</p>'; return; }
    g('err-list').innerHTML = d.rows.map(r=>`
        <div class="err-item">
            <div class="ec">${esc(r.scheme_code)} — ${esc(r.scheme_name||'Unknown')}</div>
            <div class="em">⚠ ${esc(r.error_message||'Unknown error')}</div>
            <div class="et">${r.updated_at||''}</div>
        </div>`).join('');
}
function closeModal(){g('err-modal').classList.remove('open');}
g('err-modal').addEventListener('click', e => { if(e.target===g('err-modal')) closeModal(); });

// ── EXPORT / RETRY / RESET ────────────────────────────
function doExport(filter){ window.location.href='api.php?action=export&filter='+filter; }

async function retryErrors() {
    g('btn-retry').disabled = true;
    await fetch('api.php?action=retry_errors',{method:'POST'});
    g('al-err').classList.remove('show');
    g('btn-retry').disabled = false;
    fetchSummary(); fetchTable();
}

async function resetAll() {
    userStopped = true;
    if (!confirm('Full reset: clears ALL peak NAV data and restarts from scratch?')) return;
    g('btn-reset').disabled = true;
    // XHR-based — no window to close
    isRunning = false;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    await fetch('api.php?action=reset',{method:'POST'});
    g('btn-reset').disabled = false;
    g('btn-run').textContent = '▶ Run Processor';
    totalStart=null; sessStart=null;
    localStorage.removeItem('pkTotal');
    localStorage.removeItem('pkStopped');
    g('total-timer').textContent='00:00:00';
    g('sess-timer').textContent='00:00:00';
    setStatus('idle','● Idle');
    ['al-stop','al-err','al-done'].forEach(id=>g(id).classList.remove('show'));
    activePage=1; fetchSummary(); fetchTable();
}

function hideAlert(id){ g(id).classList.remove('show'); }

// ── PARALLEL CONTROL ──────────────────────────────────
function getPar(){ return parseInt(localStorage.getItem('pkPar')||'8'); }
function initParallel(){
    const v = getPar();
    g('pc-num').value = v;
    updateHint(v);
}
function chgPar(d){
    let v = getPar()+d;
    v = Math.max(1,Math.min(50,v));
    localStorage.setItem('pkPar',v);
    g('pc-num').value=v;
    updateHint(v);
}
function updateHint(v, speedStr){
    const map={1:'Very slow',2:'Slow',3:'Slow',4:'Safe',5:'Safe',
               6:'Good',7:'Good',8:'Good',9:'Good',10:'Good',
               12:'Fast',15:'Fast',20:'Very fast',25:'⚠ Risk errors',
               30:'⚠ High risk',50:'⚠ Max risk'};
    const keys=Object.keys(map).map(Number).sort((a,b)=>a-b);
    let lbl=map[v]||(()=>{const n=keys.reduce((p,c)=>Math.abs(c-v)<Math.abs(p-v)?c:p);return map[n];})();
    const clr={'Very slow':'#94a3b8','Slow':'#94a3b8','Safe':'#16a34a','Good':'#16a34a',
               'Fast':'#d97706','Very fast':'#d97706','⚠ Risk errors':'#dc2626',
               '⚠ High risk':'#dc2626','⚠ Max risk':'#dc2626'};
    const el=g('pc-hint');
    el.textContent = speedStr ? v+' parallel — '+speedStr : v+' — '+lbl;
    el.style.color  = clr[lbl]||'#64748b';
}

// ── HELPERS ───────────────────────────────────────────
function g(id){return document.getElementById(id);}
function fmt(n){return parseInt(n||0).toLocaleString('en-IN');}
function fmtMs(ms){const s=Math.floor(ms/1000),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sc=s%60;return pad(h)+':'+pad(m)+':'+pad(sc);}
function pad(n){return String(n).padStart(2,'0');}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
</body>
</html>