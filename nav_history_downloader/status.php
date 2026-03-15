<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NAV History Downloader — WealthDash Admin</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f1f5f9;--surface:#ffffff;--surf2:#f8fafc;--border:#e2e8f0;
  --text:#1e293b;--muted:#64748b;--faint:#94a3b8;
  --blue:#2563eb;--green:#16a34a;--yellow:#d97706;
  --red:#dc2626;--purple:#7c3aed;--cyan:#0891b2;--orange:#ea580c;
  --radius:10px;
}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;padding:20px;min-height:100vh}

/* HEADER */
.header{display:flex;align-items:center;gap:12px;margin-bottom:22px;padding-bottom:16px;border-bottom:2px solid var(--border)}
.header h1{font-size:1.2rem;font-weight:700;color:var(--text)}
.header h1 span{color:var(--blue)}
.live-dot{width:8px;height:8px;border-radius:50%;background:var(--green);animation:blink 1.6s infinite;flex-shrink:0}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
.ts{color:var(--faint);font-size:.8rem;margin-left:auto;font-family:monospace}
.admin-badge{background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.25);color:var(--purple);padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:700}

/* ADMIN PANEL — Date Control */
.admin-panel{background:var(--surface);border:1px solid var(--border);border-left:3px solid var(--purple);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.admin-panel h2{font-size:.85rem;font-weight:700;color:var(--purple);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.date-controls{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.date-group{display:flex;flex-direction:column;gap:5px}
.date-group label{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.date-input{background:var(--surf2);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:7px;font-size:.85rem;outline:none;transition:border-color .15s}
.date-input:focus{border-color:var(--blue)}
.date-presets{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}
.preset-btn{padding:5px 12px;background:var(--surf2);border:1px solid var(--border);border-radius:99px;color:var(--muted);font-size:.72rem;cursor:pointer;transition:all .15s;white-space:nowrap}
.preset-btn:hover{border-color:var(--blue);color:var(--blue);background:#eff6ff}
.preset-btn.active{background:#eff6ff;border-color:var(--blue);color:var(--blue);font-weight:600}
.current-setting{display:flex;align-items:center;gap:8px;padding:8px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;font-size:.82rem}
.current-setting .lbl{color:var(--muted)}
.current-setting .val{color:var(--green);font-weight:700;font-family:monospace}

/* CARDS */
.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
@media(max-width:900px){.cards{grid-template-columns:repeat(3,1fr)}}
@media(max-width:500px){.cards{grid-template-columns:repeat(2,1fr)}}
.card{background:var(--surface);border-radius:var(--radius);padding:14px 16px;text-align:center;border:1px solid var(--border);border-top:3px solid var(--border);position:relative;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.card .num{font-size:1.6rem;font-weight:700;line-height:1;margin-bottom:3px;font-variant-numeric:tabular-nums}
.card .lbl{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.card .sub{font-size:.65rem;color:var(--faint);margin-top:3px}
.c-total  {border-top-color:var(--blue)}  .c-total  .num{color:var(--blue)}
.c-pending{border-top-color:var(--muted)} .c-pending .num{color:var(--muted)}
.c-done   {border-top-color:var(--green)} .c-done   .num{color:var(--green)}
.c-records{border-top-color:var(--cyan)}  .c-records .num{color:var(--cyan);font-size:1.2rem}
.c-errors {border-top-color:var(--red);cursor:pointer}   .c-errors .num{color:var(--red)}
.c-db     {border-top-color:var(--purple)} .c-db .num{color:var(--purple);font-size:1.1rem}
@keyframes numFlip{0%{opacity:0;transform:translateY(30%)}100%{opacity:1;transform:translateY(0)}}
.num-anim{animation:numFlip .5s cubic-bezier(.15,.8,.2,1) both}

/* PROGRESS */
.prog-box{background:var(--surface);border-radius:var(--radius);padding:14px 18px;margin-bottom:12px;border:1px solid var(--border);box-shadow:0 1px 4px rgba(0,0,0,.05)}
.prog-meta{display:flex;justify-content:space-between;font-size:.8rem;color:var(--muted);margin-bottom:8px}
.prog-pct{color:var(--blue);font-weight:700;font-size:.95rem}
.bar-track{background:#e2e8f0;border-radius:99px;height:12px;overflow:hidden}
.bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--blue),var(--cyan));transition:width .8s ease;min-width:4px}
.prog-sub{display:flex;justify-content:space-between;margin-top:7px;font-size:.7rem;color:var(--faint)}
.prog-sub b{color:var(--text)}

/* TIMERS ROW */
.info-row{display:flex;align-items:center;gap:16px;background:var(--surface);border-radius:var(--radius);padding:11px 16px;margin-bottom:12px;border:1px solid var(--border);flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.tbox{display:flex;flex-direction:column;align-items:center;gap:2px}
.tlbl{font-size:.65rem;color:var(--faint);text-transform:uppercase;letter-spacing:.4px}
.tval{font-size:.95rem;font-weight:700;color:var(--blue);font-family:'Courier New',monospace}
.divider{width:1px;height:32px;background:var(--border);flex-shrink:0}
.run-st{font-size:.82rem;font-weight:600}
.st-idle{color:var(--faint)}.st-run{color:var(--green)}.st-stop{color:var(--yellow)}.st-done{color:var(--green)}

/* ALERTS */
.alert{display:none;border-radius:8px;padding:9px 14px;margin-bottom:10px;font-size:.82rem;font-weight:600;align-items:center;gap:10px}
.alert.show{display:flex}
.al-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.al-err {background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.al-done{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.al-btn{margin-left:auto;padding:5px 14px;border:none;border-radius:5px;cursor:pointer;font-weight:700;font-size:.75rem}
.ab-y{background:var(--yellow);color:#fff}.ab-r{background:var(--red);color:#fff}.ab-g{background:var(--green);color:#fff}

/* ACTIONS */
.actions{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.btn{padding:8px 16px;border-radius:7px;border:none;cursor:pointer;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:opacity .15s,transform .1s;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.btn:hover{opacity:.87}.btn:active{transform:scale(.97)}.btn:disabled{opacity:.35;cursor:not-allowed;box-shadow:none}
.b-run   {background:var(--blue);color:#fff}
.b-green {background:var(--green);color:#fff}
.b-retry {background:var(--orange);color:#fff}
.b-reset {background:var(--red);color:#fff}
.b-ghost {background:#fff;color:var(--text);border:1px solid var(--border);box-shadow:0 1px 3px rgba(0,0,0,.06)}

/* PARALLEL */
.par-ctrl{display:flex;align-items:center;border:1px solid var(--border);border-radius:7px;overflow:hidden;height:36px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.par-ctrl label{padding:0 10px;font-size:.7rem;color:var(--muted);font-weight:600;text-transform:uppercase;background:var(--surf2);height:100%;display:flex;align-items:center;border-right:1px solid var(--border);white-space:nowrap}
.pc-btn{width:32px;height:100%;border:none;background:var(--surf2);cursor:pointer;font-size:1.1rem;font-weight:700;color:var(--blue);display:flex;align-items:center;justify-content:center}
.pc-btn:hover{background:#eff6ff}
.pc-num{width:40px;text-align:center;font-size:.9rem;font-weight:700;color:var(--text);background:#fff;border:none;border-left:1px solid var(--border);border-right:1px solid var(--border);height:100%;outline:none}
.pc-hint{font-size:.7rem;font-weight:600;padding-left:6px}

/* TABS */
.tabs{display:flex;background:var(--surface);border:1px solid var(--border);border-bottom:none;border-radius:var(--radius) var(--radius) 0 0;padding:0 6px;box-shadow:0 -1px 4px rgba(0,0,0,.04)}
.tab-btn{padding:10px 18px;background:transparent;border:none;border-bottom:2px solid transparent;color:var(--faint);font-size:.82rem;font-weight:600;cursor:pointer;margin-bottom:-1px;transition:color .15s,border-color .15s}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{border-bottom-color:var(--blue);color:var(--blue)}
.tab-btn.active[data-tab="errors"]{border-bottom-color:var(--red);color:var(--red)}
.tab-btn.active[data-tab="completed"]{border-bottom-color:var(--green);color:var(--green)}
.tc{display:inline-block;font-size:.65rem;padding:1px 6px;border-radius:99px;background:var(--surf2);color:var(--muted);margin-left:4px}

/* TABLE PANEL */
.tpanel{background:var(--surface);border:1px solid var(--border);border-radius:0 0 var(--radius) var(--radius);padding:12px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.tbl-meta{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px}
.tbl-info{color:var(--muted);font-size:.73rem}
table{width:100%;border-collapse:collapse}
th{background:var(--surf2);padding:8px 12px;text-align:left;color:var(--muted);font-size:.7rem;font-weight:600;border-bottom:2px solid var(--border);white-space:nowrap}
td{padding:7px 12px;border-bottom:1px solid var(--border);font-size:.8rem;vertical-align:middle}
tr:hover td{background:#f8fafc}
.cd{color:var(--cyan);font-family:monospace;font-size:.75rem}
.dm{color:var(--muted);font-size:.72rem}
.nv{color:var(--green);font-weight:600}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.66rem;font-weight:700}
.b-pending    {background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
.b-in_progress{background:#fffbeb;color:#92400e;border:1px solid #fcd34d}
.b-completed  {background:#f0fdf4;color:#15803d;border:1px solid #86efac}
.b-error      {background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5}

/* PAGINATION */
.pagination{display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-top:10px}
.pg-btn{padding:4px 9px;background:#fff;border:1px solid var(--border);border-radius:5px;color:var(--muted);font-size:.72rem;cursor:pointer;transition:all .1s}
.pg-btn:hover{border-color:var(--blue);color:var(--blue)}
.pg-btn.active{background:var(--blue);color:#fff;border-color:var(--blue);font-weight:700}
.pg-btn:disabled{opacity:.3;cursor:not-allowed}
.pg-info{color:var(--faint);font-size:.7rem;margin-left:auto}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border:1px solid var(--border);border-radius:12px;padding:22px;max-width:680px;width:92%;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15)}
.modal h2{font-size:1rem;color:var(--text);margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}
.modal-close{cursor:pointer;color:var(--muted);font-size:1.1rem;background:none;border:none}
.err-item{background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:8px 12px;font-size:.78rem;margin-bottom:6px}
.err-item .ec{color:var(--cyan);font-family:monospace;font-weight:600}
.err-item .em{color:#dc2626;margin-top:2px}
.err-item .et{color:var(--faint);font-size:.68rem}

/* LOADING */
.loading-row td{text-align:center;padding:32px;color:var(--muted)}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--blue);border-radius:50%;animation:spin .8s linear infinite;vertical-align:middle;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* DB STATS BOX */
.db-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.db-stat{text-align:center}
.db-stat .dv{font-size:1rem;font-weight:700;color:var(--cyan);font-family:monospace}
.db-stat .dl{font-size:.65rem;color:var(--faint);text-transform:uppercase;letter-spacing:.4px;margin-top:2px}
@media(max-width:500px){.db-stats{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>

<div class="header">
  <div class="live-dot"></div>
  <h1>📥 NAV History <span>Downloader</span></h1>
  <span class="admin-badge">⚙ Admin Only</span>
  <span class="ts" id="ts">--:--:--</span>
</div>

<!-- ══ ADMIN DATE CONTROL PANEL ══ -->
<div class="admin-panel">
  <h2>⚙ Admin Control — Download Range</h2>
  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start">

    <div>
      <div class="date-group" style="margin-bottom:8px">
        <label>Set Download From Date</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="date" id="fromDateInput" class="date-input" value="2025-01-01">
          <button class="btn b-run" onclick="setFromDate(false)" id="btnSetDate">
            ✓ Set & Reset All
          </button>
          <button class="btn" style="background:#f5f3ff;color:var(--purple);border:1px solid rgba(124,58,237,.25)" onclick="setFromDate(true)" id="btnExtendDate">
            ↙ Extend Back (keep completed)
          </button>
        </div>
      </div>
      <div class="date-presets">
        <span style="font-size:.7rem;color:var(--faint);align-self:center">Quick:</span>
        <button class="preset-btn" onclick="setPreset(30)">30 days</button>
        <button class="preset-btn" onclick="setPreset(90)">3 months</button>
        <button class="preset-btn" onclick="setPreset(180)">6 months</button>
        <button class="preset-btn" onclick="setPreset(365)">1 year</button>
        <button class="preset-btn" onclick="setPreset(365*2)">2 years</button>
        <button class="preset-btn" onclick="setPreset(365*5)">5 years</button>
        <button class="preset-btn" onclick="setPreset(365*10)">10 years</button>
        <button class="preset-btn" onclick="setPreset(365*25)">All (25yr)</button>
      </div>
      <p style="font-size:.7rem;color:var(--muted);margin-top:8px">
        ⚡ <b style="color:var(--yellow)">Set & Reset All</b> — sab funds dubara download honge us date se &nbsp;|&nbsp;
        ↙ <b style="color:var(--purple)">Extend Back</b> — sirf jo already complete hain woh bhi re-download honge
      </p>
    </div>

    <div style="flex:1;min-width:200px">
      <div class="current-setting">
        <span class="lbl">Current Setting:</span>
        <span class="val" id="currentFromDate">Loading...</span>
        <span class="lbl" style="margin-left:8px">to Today</span>
      </div>
      <div style="margin-top:8px;font-size:.72rem;color:var(--faint);line-height:1.6">
        📌 <b style="color:var(--text)">How it works:</b><br>
        • Admin sets from_date → all funds queued<br>
        • Processor downloads NAV from that date<br>
        • Saves to <code style="color:var(--cyan)">nav_history</code> table<br>
        • SIP calculations use this data
      </div>
    </div>
  </div>
</div>

<!-- ══ STAT CARDS ══ -->
<div class="cards">
  <div class="card c-total">
    <div class="num" id="c-total">—</div><div class="lbl">Total Funds</div>
  </div>
  <div class="card c-pending">
    <div class="num" id="c-pending">—</div><div class="lbl">Pending</div>
  </div>
  <div class="card c-done">
    <div class="num" id="c-done">—</div><div class="lbl">Completed</div>
    <div class="sub" id="c-done-sub">—</div>
  </div>
  <div class="card c-records">
    <div class="num" id="c-records">—</div><div class="lbl">Records Saved</div>
    <div class="sub">in nav_history</div>
  </div>
  <div class="card c-errors" onclick="showErrors()">
    <div class="num" id="c-errors">—</div><div class="lbl">⚠ Errors</div>
    <div class="sub">Click to view</div>
  </div>
  <div class="card c-db">
    <div class="num" id="c-db-total">—</div><div class="lbl">DB NAV Rows</div>
    <div class="sub" id="c-db-funds">—</div>
  </div>
</div>

<!-- ══ DB STATS ══ -->
<div class="db-stats" id="dbStatsBox">
  <div class="db-stat"><div class="dv" id="db-rows">—</div><div class="dl">Total Rows</div></div>
  <div class="db-stat"><div class="dv" id="db-funds">—</div><div class="dl">Funds with Data</div></div>
  <div class="db-stat"><div class="dv" id="db-oldest">—</div><div class="dl">Oldest Date</div></div>
  <div class="db-stat"><div class="dv" id="db-newest">—</div><div class="dl">Newest Date</div></div>
</div>

<!-- ══ PROGRESS BAR ══ -->
<div class="prog-box">
  <div class="prog-meta">
    <span>Download Progress</span>
    <span class="prog-pct" id="pct-lbl">0%</span>
  </div>
  <div class="bar-track"><div class="bar-fill" id="bar" style="width:0%"></div></div>
  <div class="prog-sub">
    <span>From: <b id="prog-from">—</b></span>
    <span>Oldest downloaded: <b id="prog-oldest">—</b></span>
    <span>Latest downloaded: <b id="prog-latest">—</b></span>
  </div>
</div>

<!-- ══ TIMER ROW ══ -->
<div class="info-row">
  <div class="tbox"><span class="tlbl">⏱ Total Elapsed</span><span class="tval" id="total-timer">00:00:00</span></div>
  <div class="divider"></div>
  <div class="tbox"><span class="tlbl">🔄 This Session</span><span class="tval" id="sess-timer">00:00:00</span></div>
  <div class="divider"></div>
  <div class="tbox"><span class="tlbl">📅 Last Run</span><span class="tval" id="last-run" style="font-size:.75rem">—</span></div>
  <div class="divider"></div>
  <span class="run-st st-idle" id="run-st">● Idle</span>
</div>

<!-- ══ CURRENTLY PROCESSING LIVE PANEL ══ -->
<div id="currentlyProcessingBox" style="display:none;background:#fff;border:1px solid #e2e8f0;border-left:3px solid #2563eb;border-radius:10px;padding:12px 16px;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.05)">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
    <div class="live-dot"></div>
    <span style="font-size:.78rem;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.5px">Currently Processing</span>
    <span style="font-size:.72rem;color:#94a3b8;margin-left:auto" id="batchInfo"></span>
  </div>
  <div id="inProgressFunds" style="display:flex;flex-wrap:wrap;gap:6px"></div>
</div>

<!-- ══ ALERTS ══ -->
<div class="alert al-warn" id="al-stop">
  ⚠ Processor stopped — funds still pending!
  <button class="al-btn ab-y" onclick="runProcessor()">▶ Restart</button>
</div>
<div class="alert al-err" id="al-err">
  <span id="al-err-msg">Some errors occurred.</span>
  <button class="al-btn ab-r" onclick="retryErrors()">↺ Retry</button>
</div>
<div class="alert al-done" id="al-done">
  ✅ All funds downloaded! nav_history is up to date.
  <button class="al-btn ab-g" onclick="g('al-done').classList.remove('show')">✕</button>
</div>

<!-- ══ ACTION BUTTONS ══ -->
<div class="actions">
  <button class="btn b-run" id="btn-run" onclick="runProcessor()">▶ Run Downloader</button>
  <button class="btn" id="btn-stop" onclick="stopProcessor()" style="display:none;background:#dc2626;color:#fff">⏹ Stop</button>
  <div class="par-ctrl">
    <label>⚡ PARALLEL</label>
    <button class="pc-btn" onclick="chgPar(-1)">−</button>
    <input class="pc-num" id="pc-num" type="text" value="8" readonly>
    <button class="pc-btn" onclick="chgPar(+1)">+</button>
  </div>
  <span class="pc-hint" id="pc-hint">8 — Good</span>
  <button class="btn b-ghost" onclick="reseed()">🔄 Add New Funds</button>
  <button class="btn b-retry" id="btn-retry" onclick="retryErrors()">↺ Retry Errors</button>
  <button class="btn" onclick="doExport('completed')" style="background:#f0fdf4;color:var(--green);border:1px solid #86efac">⬇ Export CSV</button>
  <button class="btn b-reset" id="btn-reset" onclick="resetAll()">⟳ Full Reset</button>
</div>

<!-- ══ TABS ══ -->
<div class="tabs">
  <button class="tab-btn active" data-tab="pending"   onclick="switchTab('pending')">  ⏳ Pending   <span class="tc" id="tc-p">—</span></button>
  <button class="tab-btn"        data-tab="working"   onclick="switchTab('working')">  🔄 Working   <span class="tc" id="tc-w">—</span></button>
  <button class="tab-btn"        data-tab="completed" onclick="switchTab('completed')">✅ Completed <span class="tc" id="tc-c">—</span></button>
  <button class="tab-btn"        data-tab="errors"    onclick="switchTab('errors')">   ⚠ Errors    <span class="tc" id="tc-e">—</span></button>
</div>

<!-- ══ TABLE ══ -->
<div class="tpanel">
  <div class="tbl-meta">
    <div class="tbl-info" id="tbl-info">Loading...</div>
    <div style="display:flex;gap:6px">
      <button class="btn b-ghost" style="padding:4px 10px;font-size:.72rem" onclick="fetchTable()">↻ Refresh</button>
      <button class="btn b-ghost" style="padding:4px 10px;font-size:.72rem" onclick="doExport('completed')">⬇ CSV</button>
    </div>
  </div>
  <table>
    <thead><tr>
      <th>#</th>
      <th>Scheme Code</th>
      <th>Fund Name</th>
      <th>From Date</th>
      <th>Last Downloaded</th>
      <th>Records Saved</th>
      <th>Status</th>
    </tr></thead>
    <tbody id="tbody">
      <tr class="loading-row"><td colspan="7"><span class="spinner"></span> Loading...</td></tr>
    </tbody>
  </table>
  <div class="pagination" id="pagination"></div>
</div>

<!-- ══ ERROR MODAL ══ -->
<div class="modal-overlay" id="err-modal">
  <div class="modal">
    <h2>⚠ Error Details <button class="modal-close" onclick="closeModal()">✕</button></h2>
    <div id="err-list">Loading...</div>
  </div>
</div>

<script>
// ── STATE ──────────────────────────────────────────
let activeTab  = 'pending';
let activePage = 1;
let totalStart = null;
let sessStart  = null;
let isRunning  = false;
let userStopped = false;
let pollTimer  = null;
let prevDone   = 0;

// ── INIT ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  totalStart = parseInt(localStorage.getItem('nhTotal') || '0') || null;
  initParallel();
  fetchSummary();
  fetchTable();
  setInterval(fetchSummary, 4000);
  setInterval(() => fetchTable(false), 8000);
  setInterval(tickTimers, 1000);
  setInterval(updateClock, 1000);
  updateClock();
});

function updateClock() {
  const n = new Date();
  g('ts').textContent = pad(n.getHours())+':'+pad(n.getMinutes())+':'+pad(n.getSeconds());
}
function tickTimers() {
  const now = Date.now();
  if (totalStart) g('total-timer').textContent = fmtMs(now - totalStart);
  if (sessStart)  g('sess-timer').textContent  = fmtMs(now - sessStart);
}

// ── PRESET DATES ───────────────────────────────────
function setPreset(days) {
  const d = new Date();
  d.setDate(d.getDate() - days);
  const iso = d.toISOString().slice(0,10);
  g('fromDateInput').value = iso;
  document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');
}

// ── SET FROM DATE ───────────────────────────────────
async function setFromDate(extendOnly) {
  const fromDate = g('fromDateInput').value;
  if (!fromDate) { alert('Please select a date'); return; }

  const action = extendOnly ? 'extend_from_date' : 'set_from_date';
  const msg = extendOnly
    ? `Extend download back to ${fromDate}?\n\nThis will re-queue all completed funds to download from this earlier date.`
    : `Set download from ${fromDate}?\n\nThis will RESET all funds to pending and re-download everything from scratch.`;

  if (!confirm(msg)) return;

  const btnId = extendOnly ? 'btnExtendDate' : 'btnSetDate';
  g(btnId).disabled = true;
  g(btnId).textContent = '⏳ Setting...';

  try {
    const res = await fetch('api.php?action='+action, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({from_date: fromDate})
    }).then(r => r.json());

    alert(res.message || (res.success ? 'Done!' : 'Error'));
    if (res.success) { fetchSummary(); fetchTable(); }
  } catch(e) {
    alert('Error: ' + e.message);
  } finally {
    g(btnId).disabled = false;
    g(btnId).textContent = extendOnly ? '↙ Extend Back (keep completed)' : '✓ Set & Reset All';
  }
}

// ── PROCESSOR CONTROL ──────────────────────────────
function runProcessor() {
  userStopped = false;
  isRunning   = false;
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }

  if (!totalStart) { totalStart = Date.now(); localStorage.setItem('nhTotal', totalStart); }
  sessStart = Date.now();
  prevDone  = 0;

  ['al-stop','al-done','al-err'].forEach(id => g(id).classList.remove('show'));
  setStatus('run', '🟢 Downloading...');
  g('btn-run').textContent = '⏸ Running...';
  g('btn-run').disabled = true;
  g('btn-stop').style.display = 'inline-flex';   // ← Show Stop button

  const parallel = getPar();
  const xhr = new XMLHttpRequest();
  xhr.open('GET', 'processor.php?t=' + Date.now() + '&parallel=' + parallel, true);
  xhr.timeout = 120000;
  xhr.onload    = () => onProcFinished(xhr.responseText);
  xhr.onerror   = () => onProcFinished('');
  xhr.ontimeout = () => onProcFinished('');
  xhr.send();

  pollTimer = setInterval(checkProgress, 4000);
}

// ── STOP ───────────────────────────────────────────
async function stopProcessor() {
  if (!confirm('Stop the downloader?\n\nCurrent batch will finish, then it will stop.\nProgress is saved — you can resume anytime.')) return;

  g('btn-stop').disabled = true;
  g('btn-stop').textContent = '⏳ Stopping...';
  userStopped = true;

  try {
    const res = await fetch('api.php?action=stop', { method: 'POST' }).then(r => r.json());
    setStatus('stop', '⏸ Stop requested — finishing current batch...');
    showToastMsg('⏹ ' + (res.message || 'Stop requested'));
  } catch(e) {
    g('btn-stop').disabled = false;
    g('btn-stop').textContent = '⏹ Stop';
  }
}

function showToastMsg(msg) {
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#1e293b;color:#fff;padding:10px 18px;border-radius:8px;font-size:.82rem;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);animation:fadeInUp .3s ease';
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

async function onProcFinished(responseText) {
  isRunning = false;
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  g('btn-run').textContent = '▶ Run Downloader';
  g('btn-run').disabled = false;
  g('btn-stop').style.display = 'none';      // ← Hide Stop button
  g('btn-stop').disabled = false;
  g('btn-stop').textContent = '⏹ Stop';

  try {
    const d = await fetch('api.php?action=summary&_='+Date.now(), {cache:'no-store'}).then(r=>r.json());
    const c = d.counts;
    const remaining = parseInt(c.pending||0) + parseInt(c.working||0);
    const errors    = parseInt(c.errors||0);

    if (remaining === 0) {
      setStatus('done', '✅ All Downloaded!');
      g('al-done').classList.add('show');
      localStorage.removeItem('nhTotal'); totalStart = null;
    } else if (!userStopped) {
      setStatus('run', '🔄 Next batch in 2s...');
      setTimeout(() => { if (!isRunning && !userStopped) runProcessor(); }, 2000);
    } else {
      setStatus('stop', '⏸ Paused — '+fmt(remaining)+' remaining');
      g('al-stop').classList.add('show');
    }

    if (errors > 0) {
      g('al-err-msg').textContent = fmt(errors)+' funds had errors. Click Retry.';
      g('al-err').classList.add('show');
    }
  } catch(e) {
    setStatus('stop', '⏸ Paused');
    g('al-stop').classList.add('show');
  }
  fetchSummary(); fetchTable(false);
}

async function checkProgress() {
  try {
    const d = await fetch('api.php?action=summary&_='+Date.now(), {cache:'no-store'}).then(r=>r.json());
    const c = d.counts;
    const nowDone   = parseInt(c.completed || 0);
    const remaining = parseInt(c.pending||0) + parseInt(c.working||0);

    if (nowDone > prevDone) {
      const speed = ((nowDone - prevDone) / 4).toFixed(1);
      updateHint(getPar(), '~'+speed+'/s');
      prevDone = nowDone;
    }

    if (remaining === 0) {
      clearInterval(pollTimer); pollTimer = null;
      g('btn-run').textContent = '▶ Run Downloader';
      g('btn-run').disabled = false;
      setStatus('done', '✅ All Done!');
      g('al-done').classList.add('show');
      localStorage.removeItem('nhTotal'); totalStart = null;
      fetchSummary(); fetchTable(false);
    }
  } catch(e){}
}

function setStatus(type, msg) {
  const el = g('run-st');
  el.className = 'run-st st-' + type;
  el.textContent = msg;
}

// ── SUMMARY ────────────────────────────────────────
async function fetchSummary() {
  try {
    const d = await fetch('api.php?action=summary&_='+Date.now(), {cache:'no-store'}).then(r=>r.json());
    if (d.error) return;
    const c = d.counts;
    const nh = d.nav_history || {};

    animNum('c-total',   fmt(c.total));
    animNum('c-pending', fmt(+c.pending + +c.working));
    animNum('c-done',    fmt(c.completed));
    animNum('c-errors',  fmt(c.errors));
    animNum('c-records', fmtK(c.total_records||0));
    animNum('c-db-total', fmtK(nh.total_rows||0));

    g('c-done-sub').textContent = d.pct + '% complete';
    g('c-db-funds').textContent = fmt(nh.funds_with_data||0) + ' funds';
    g('pct-lbl').textContent = d.pct + '%';
    g('bar').style.width = d.pct + '%';
    g('prog-from').textContent    = d.from_date || '—';
    g('prog-oldest').textContent  = c.oldest_date || '—';
    g('prog-latest').textContent  = c.latest_date || '—';
    g('currentFromDate').textContent = d.from_date || '—';
    g('last-run').textContent     = d.last_run || '—';
    g('fromDateInput').value      = d.from_date || '2025-01-01';

    // DB stats row — filtered by from_date (no old data confusion)
    g('db-rows').textContent   = fmtK(nh.total_rows||0);
    g('db-funds').textContent  = fmt(nh.funds_with_data||0);
    g('db-oldest').textContent = nh.oldest  || '—';
    g('db-newest').textContent = nh.newest  || '—';

    g('tc-p').textContent = fmt(c.pending);
    g('tc-w').textContent = fmt(c.working);
    g('tc-c').textContent = fmt(c.completed);
    g('tc-e').textContent = fmt(c.errors);

    // ── Currently Processing Live Panel ──────────────
    const box       = g('currentlyProcessingBox');
    const fundsDiv  = g('inProgressFunds');
    const batchEl   = g('batchInfo');
    const ipFunds   = d.in_progress_funds || [];
    const working   = parseInt(c.working || 0);

    if (working > 0 && ipFunds.length > 0) {
      box.style.display = 'block';
      batchEl.textContent = d.current_batch || '';

      fundsDiv.innerHTML = ipFunds.map(f => `
        <div style="display:inline-flex;align-items:center;gap:6px;
                    background:#eff6ff;border:1px solid #bfdbfe;
                    border-radius:6px;padding:4px 10px;font-size:.75rem;">
          <span style="color:#2563eb;font-family:monospace;font-weight:700">${esc(f.scheme_code)}</span>
          <span style="color:#64748b;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="${esc(f.scheme_name||'')}">${esc((f.scheme_name||'').substring(0,30))}${(f.scheme_name||'').length>30?'…':''}</span>
          <span style="color:#94a3b8;font-size:.68rem">from ${esc(f.from_date||'')}</span>
        </div>`).join('');

      if (working > ipFunds.length) {
        fundsDiv.innerHTML += `<div style="padding:4px 10px;font-size:.73rem;color:#94a3b8;align-self:center">+${fmt(working - ipFunds.length)} more...</div>`;
      }
    } else {
      box.style.display = 'none';
    }

  } catch(e){}
}

function animNum(id, val) {
  const el = g(id);
  if (!el || el.textContent === val) return;
  el.classList.remove('num-anim');
  void el.offsetWidth;
  el.textContent = val;
  el.classList.add('num-anim');
}

// ── TABLE ──────────────────────────────────────────
async function fetchTable(loader = true) {
  if (loader) g('tbody').innerHTML = '<tr class="loading-row"><td colspan="7"><span class="spinner"></span> Loading...</td></tr>';
  try {
    const url = 'api.php?action=table&tab='+activeTab+'&page='+activePage+'&_='+Date.now();
    const d = await fetch(url, {cache:'no-store'}).then(r=>r.json());
    if (d.rows !== undefined) renderTable(d.rows, d.page, d.pages, d.total_rows);
  } catch(e) {}
}

function renderTable(rows, page, pages, total) {
  const off = (page-1)*50;
  g('tbl-info').textContent = 'Showing '+(off+1)+'–'+(off+rows.length)+' of '+total;
  if (!rows.length) {
    g('tbody').innerHTML = '<tr class="loading-row"><td colspan="7">No records in this tab</td></tr>';
    g('pagination').innerHTML = '';
    return;
  }
  g('tbody').innerHTML = rows.map((r,i) => {
    const name = esc(r.scheme_name||'Unknown Fund');
    const short = name.length > 45 ? name.slice(0,45)+'…' : name;
    return `<tr>
      <td class="dm">${off+i+1}</td>
      <td class="cd">${esc(r.scheme_code)}</td>
      <td title="${name}">${short}</td>
      <td class="dm">${r.from_date||'—'}</td>
      <td class="dm">${r.last_downloaded_date||'—'}</td>
      <td class="nv">${fmt(r.records_saved||0)}</td>
      <td>
        ${r.status==='error'
          ? `<span class="badge b-error" title="${esc(r.error_message||'')}">error</span>`
          : `<span class="badge b-${r.status}">${r.status}</span>`}
      </td>
    </tr>`;
  }).join('');
  renderPagination(page, pages);
}

function renderPagination(page, pages) {
  if (pages<=1){g('pagination').innerHTML='';return;}
  let h = `<button class="pg-btn" onclick="goPage(${page-1})" ${page===1?'disabled':''}>◀</button>`;
  let s=Math.max(1,page-3), e=Math.min(pages,page+3);
  if(s>1) h+=`<button class="pg-btn" onclick="goPage(1)">1</button>${s>2?'<span style="color:var(--faint);padding:0 3px">…</span>':''}`;
  for(let p=s;p<=e;p++) h+=`<button class="pg-btn ${p===page?'active':''}" onclick="goPage(${p})">${p}</button>`;
  if(e<pages) h+=`${e<pages-1?'<span style="color:var(--faint);padding:0 3px">…</span>':''}<button class="pg-btn" onclick="goPage(${pages})">${pages}</button>`;
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

// ── ACTIONS ────────────────────────────────────────
async function reseed() {
  g('btnRetry') && (g('btnRetry').disabled=true);
  const res = await fetch('api.php?action=reseed',{method:'POST'}).then(r=>r.json());
  alert(res.message);
  fetchSummary(); fetchTable();
}

async function retryErrors() {
  g('btn-retry').disabled = true;
  const res = await fetch('api.php?action=retry_errors',{method:'POST'}).then(r=>r.json());
  g('al-err').classList.remove('show');
  g('btn-retry').disabled = false;
  alert(res.message);
  fetchSummary(); fetchTable();
}

async function resetAll() {
  userStopped = true;
  if (!confirm('Full reset: ALL funds will re-download from the current from_date. This may take a long time. Continue?')) return;
  isRunning = false;
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  g('btn-reset').disabled = true;
  const res = await fetch('api.php?action=reset_all',{method:'POST'}).then(r=>r.json());
  g('btn-reset').disabled = false;
  g('btn-run').textContent = '▶ Run Downloader';
  g('btn-run').disabled = false;
  totalStart=null; sessStart=null;
  localStorage.removeItem('nhTotal');
  g('total-timer').textContent='00:00:00';
  g('sess-timer').textContent='00:00:00';
  setStatus('idle','● Idle');
  ['al-stop','al-err','al-done'].forEach(id=>g(id).classList.remove('show'));
  alert(res.message);
  activePage=1; fetchSummary(); fetchTable();
}

function doExport(filter){ window.location.href='api.php?action=export&filter='+filter; }

// ── ERRORS MODAL ───────────────────────────────────
async function showErrors() {
  g('err-modal').classList.add('open');
  g('err-list').innerHTML = '<p style="color:var(--muted)"><span class="spinner"></span> Loading...</p>';
  const d = await fetch('api.php?action=errors').then(r=>r.json());
  if (!d.rows.length) { g('err-list').innerHTML = '<p style="color:var(--muted)">No errors — all clear!</p>'; return; }
  g('err-list').innerHTML = d.rows.map(r=>`
    <div class="err-item">
      <div class="ec">${esc(r.scheme_code)} — ${esc(r.scheme_name||'Unknown')}</div>
      <div class="em">⚠ ${esc(r.error_message||'Unknown error')}</div>
      <div class="et">${r.updated_at||''}</div>
    </div>`).join('');
}
function closeModal(){ g('err-modal').classList.remove('open'); }
g('err-modal').addEventListener('click', e => { if(e.target===g('err-modal')) closeModal(); });

// ── PARALLEL ───────────────────────────────────────
function getPar(){ return parseInt(localStorage.getItem('nhPar')||'8'); }
function initParallel(){
  const v = getPar();
  g('pc-num').value = v;
  updateHint(v);
}
function chgPar(d){
  let v = getPar()+d; v=Math.max(1,Math.min(50,v));
  localStorage.setItem('nhPar',v); g('pc-num').value=v; updateHint(v);
}
function updateHint(v, speedStr){
  const labels={1:'Very slow',2:'Slow',4:'Safe',8:'Good',12:'Fast',15:'Fast',20:'Very fast',30:'⚠ Risk',50:'⚠ Max risk'};
  const keys=Object.keys(labels).map(Number).sort((a,b)=>a-b);
  const nearest=keys.reduce((p,c)=>Math.abs(c-v)<Math.abs(p-v)?c:p);
  const lbl=labels[nearest];
  const colors={'Very slow':'#94a3b8','Slow':'#94a3b8','Safe':'#22c55e','Good':'#22c55e','Fast':'#f59e0b','Very fast':'#f59e0b','⚠ Risk':'#ef4444','⚠ Max risk':'#ef4444'};
  const el=g('pc-hint');
  el.textContent = speedStr ? v+' parallel — '+speedStr : v+' — '+lbl;
  el.style.color = colors[lbl]||'#64748b';
}

// ── HELPERS ────────────────────────────────────────
function g(id){return document.getElementById(id);}
function fmt(n){return parseInt(n||0).toLocaleString('en-IN');}
function fmtK(n){
  n=parseInt(n||0);
  if(n>=10000000) return (n/10000000).toFixed(1)+'Cr';
  if(n>=100000)   return (n/100000).toFixed(1)+'L';
  if(n>=1000)     return (n/1000).toFixed(1)+'K';
  return n.toString();
}
function fmtMs(ms){const s=Math.floor(ms/1000),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sc=s%60;return pad(h)+':'+pad(m)+':'+pad(sc);}
function pad(n){return String(n).padStart(2,'0');}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
</body>
</html>