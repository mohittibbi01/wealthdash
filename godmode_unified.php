<?php
/**
 * WealthDash — GodMode Unified Dashboard
 * Path: wealthdash/godmode_unified.php
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
if (!is_logged_in() || !is_admin()) { header('Location: /wealthdash/login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>GodMode Unified · WealthDash</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#f0f2f7; --bg2:#fff; --bg3:#f7f8fc; --bg4:#eef0f6;
  --border:#e4e7f0; --border2:#cdd2e0;
  --text:#111827; --muted:#6b7280; --muted2:#9ca3af;
  --blue:#2563eb; --blue-bg:#eff6ff; --blue-bdr:#bfdbfe;
  --green:#059669; --green-bg:#ecfdf5; --green-bdr:#a7f3d0;
  --amber:#d97706; --amber-bg:#fffbeb; --amber-bdr:#fcd34d;
  --red:#dc2626; --red-bg:#fef2f2; --red-bdr:#fecaca;
  --purple:#7c3aed; --purple-bg:#f5f3ff; --purple-bdr:#ddd6fe;
  --teal:#0d9488; --teal-bg:#f0fdfa; --teal-bdr:#99f6e4;
  --indigo:#4f46e5; --indigo-bg:#eef2ff;
  --mono:'JetBrains Mono',monospace; --sans:'Sora',sans-serif;
  --r:8px; --rlg:12px; --hdr:46px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;background:var(--bg);color:var(--text);font-family:var(--sans);font-size:13px;line-height:1.5}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:4px}

/* ── HEADER ── */
.hdr{height:var(--hdr);background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;flex-shrink:0;position:relative;z-index:10}
.hdr-logo{width:var(--hdr);height:var(--hdr);display:flex;align-items:center;justify-content:center;font-size:17px;background:linear-gradient(135deg,#2563eb,#7c3aed);flex-shrink:0;border-right:1px solid var(--border)}
.hdr-brand{padding:0 14px;display:flex;flex-direction:column;gap:1px;border-right:1px solid var(--border);height:100%;justify-content:center}
.hdr-brand-name{font-size:13px;font-weight:700;letter-spacing:-.02em}
.hdr-brand-sub{font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.08em}
.hdr-right{margin-left:auto;display:flex;align-items:center;height:100%}
.hdr-pill{padding:0 14px;display:flex;align-items:center;gap:6px;border-left:1px solid var(--border);height:100%;font-size:11px;font-family:var(--mono);color:var(--muted)}
.hdr-pill .dot{width:6px;height:6px;border-radius:50%;background:var(--muted2);transition:all .3s}
.hdr-pill .dot.run{background:var(--green);box-shadow:0 0 0 3px rgba(5,150,105,.2);animation:pulse 1.5s infinite}
#hdrClock{color:var(--blue);font-weight:600}

/* ── OVERVIEW BAR (single source of truth) ── */
.ov{height:36px;background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0;flex-shrink:0}
.ov-seg{display:flex;align-items:center;gap:7px;padding:0 14px;border-right:1px solid var(--border);height:100%;font-size:11px;font-family:var(--mono);white-space:nowrap}
.ov-label{color:var(--muted2);text-transform:uppercase;letter-spacing:.05em;font-size:10px}
.ov-val{font-weight:700}
.ov-bar-area{flex:1;display:flex;align-items:center;gap:10px;padding:0 14px}
.ov-bar{flex:1;height:6px;background:var(--bg4);border-radius:4px;overflow:hidden}
.ov-bar-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--blue),var(--purple));transition:width .7s ease;width:0%}
.ov-pct{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--blue);min-width:40px;text-align:right}
.ov-needs-update{font-size:10px;color:var(--amber);font-family:var(--mono);padding:0 12px;border-left:1px solid var(--border);height:100%;display:flex;align-items:center;gap:4px;white-space:nowrap}

/* ── 3-COL LAYOUT ── */
.main{display:grid;grid-template-columns:240px 1fr 260px;height:calc(100vh - var(--hdr) - 36px);overflow:hidden}

/* ── LEFT PANEL ── */
.left{background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto}
.ps{padding:11px 12px;border-bottom:1px solid var(--border);flex-shrink:0}
.ps-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted2);margin-bottom:9px;display:flex;align-items:center;gap:5px}

.btn-start{width:100%;height:38px;border:none;border-radius:var(--r);cursor:pointer;font-family:var(--sans);font-size:13px;font-weight:600;background:linear-gradient(135deg,var(--blue),var(--purple));color:#fff;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .2s;box-shadow:0 2px 8px rgba(37,99,235,.2)}
.btn-start:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,99,235,.3)}
.btn-start:disabled{opacity:.55;cursor:not-allowed;transform:none}
.btn-stop{width:100%;height:33px;border:1.5px solid var(--red-bdr);border-radius:var(--r);cursor:pointer;font-family:var(--sans);font-size:12px;font-weight:600;background:var(--red-bg);color:var(--red);display:flex;align-items:center;justify-content:center;gap:5px;transition:all .2s;margin-top:5px}
.btn-stop:hover:not(:disabled){background:var(--red);color:#fff}
.btn-stop:disabled{opacity:.45;cursor:not-allowed}
.btn-row{display:flex;gap:5px;margin-top:0}
.btn-sm{flex:1;height:28px;border-radius:6px;border:1px solid var(--border);background:var(--bg3);color:var(--muted);font-size:11px;font-weight:500;font-family:var(--sans);cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:3px}
.btn-sm:hover{background:var(--bg4);color:var(--text);border-color:var(--border2)}

.slider-row{display:flex;align-items:center;gap:8px;margin-top:5px}
.slider-label{font-size:11px;color:var(--muted);flex-shrink:0}
.slider-val{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--blue);min-width:22px;text-align:center}
input[type=range]{flex:1;-webkit-appearance:none;height:4px;border-radius:4px;background:var(--bg4);outline:none}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:13px;height:13px;border-radius:50%;background:var(--blue);cursor:pointer;box-shadow:0 0 0 2px rgba(37,99,235,.2)}

.toast{margin-top:7px;padding:7px 9px;border-radius:6px;font-size:11px;line-height:1.4;display:none}
.toast.ok{background:var(--green-bg);border:1px solid var(--green-bdr);color:var(--green)}
.toast.err{background:var(--red-bg);border:1px solid var(--red-bdr);color:var(--red)}
.toast.inf{background:var(--blue-bg);border:1px solid var(--blue-bdr);color:var(--blue)}

/* ── CENTER ── */
.center{display:flex;flex-direction:column;overflow:hidden;background:var(--bg);gap:0}

.chart-row{display:flex;gap:10px;padding:10px;flex-shrink:0}

/* Donut */
.donut-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--rlg);padding:14px;display:flex;align-items:center;gap:16px;width:220px;flex-shrink:0}
.donut-wrap{position:relative;width:82px;height:82px;flex-shrink:0}
.donut-wrap svg{width:82px;height:82px;transform:rotate(-90deg)}
.donut-bg{fill:none;stroke:var(--bg4);stroke-width:9}
.donut-fill{fill:none;stroke-width:9;stroke-linecap:round;stroke:url(#dg);transition:stroke-dashoffset .8s ease}
.donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.donut-pct{font-size:17px;font-weight:700;font-family:var(--mono);color:var(--blue)}
.donut-sub{font-size:9px;color:var(--muted2);text-transform:uppercase;letter-spacing:.06em}
.donut-legend{display:flex;flex-direction:column;gap:5px}
.leg{display:flex;align-items:center;gap:5px;font-size:11px}
.leg-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}

/* Progress card */
.prog-card{flex:1;background:var(--bg2);border:1px solid var(--border);border-radius:var(--rlg);padding:13px 15px;display:flex;flex-direction:column;gap:10px;justify-content:center}
.prog-header{display:flex;justify-content:space-between;align-items:center}
.prog-title{font-size:12px;font-weight:600}
.prog-pct{font-size:24px;font-weight:700;font-family:var(--mono);color:var(--blue)}
.prog-bar{height:9px;background:var(--bg4);border-radius:5px;overflow:hidden}
.prog-fill{height:100%;border-radius:5px;background:linear-gradient(90deg,var(--blue),var(--purple));transition:width .8s ease;width:0%}
.prog-meta{display:flex;gap:0;border:1px solid var(--border);border-radius:7px;overflow:hidden}
.pm{flex:1;padding:8px 10px;border-right:1px solid var(--border)}
.pm:last-child{border-right:none}
.pm-label{font-size:9px;color:var(--muted2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px}
.pm-val{font-size:15px;font-weight:700;font-family:var(--mono)}

/* Log */
.log-card{flex:1;background:var(--bg2);border:1px solid var(--border);border-radius:var(--rlg);margin:0 10px;display:flex;flex-direction:column;overflow:hidden;min-height:0}
.log-hdr{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid var(--border);flex-shrink:0}
.log-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted)}
.log-badge{font-size:10px;font-family:var(--mono);color:var(--muted2)}
.log-body{flex:1;overflow-y:auto;padding:4px}
.log-entry{display:flex;align-items:flex-start;gap:7px;padding:4px 8px;border-radius:4px}
.log-entry:hover{background:var(--bg3)}
.log-ts{font-size:10px;font-family:var(--mono);color:var(--muted2);flex-shrink:0;margin-top:1px}
.log-icon{font-size:11px;flex-shrink:0}
.log-text{font-size:11px;flex:1}
.log-text .sc{font-family:var(--mono);font-weight:600;font-size:10px;color:var(--blue)}
.log-text .recs{font-family:var(--mono);color:var(--green);font-size:10px}
.log-text .cat{color:var(--muted2);font-size:10px}
.log-empty{color:var(--muted2);font-size:12px;padding:20px;text-align:center}

/* Queue table */
.queue-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--rlg);margin:0 10px 10px;display:flex;flex-direction:column;overflow:hidden;max-height:220px;flex-shrink:0}
.q-tabs{display:flex;align-items:center;border-bottom:1px solid var(--border);padding:0 8px;flex-shrink:0}
.q-tab{padding:7px 10px;font-size:11px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;color:var(--muted);transition:all .15s;white-space:nowrap;display:flex;align-items:center;gap:4px}
.q-tab.active{color:var(--blue);border-bottom-color:var(--blue)}
.q-cnt{font-family:var(--mono);font-size:10px}
.q-search{margin-left:auto;height:24px;border:1px solid var(--border);border-radius:5px;background:var(--bg3);padding:0 8px;font-size:11px;font-family:var(--sans);color:var(--text);outline:none;width:160px}
.q-search:focus{border-color:var(--blue)}
.q-body{flex:1;overflow-y:auto}
.qt{width:100%;border-collapse:collapse;font-size:11px}
.qt th{text-align:left;padding:5px 9px;font-size:9px;font-weight:700;color:var(--muted2);text-transform:uppercase;letter-spacing:.05em;background:var(--bg3);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1}
.qt td{padding:5px 9px;border-bottom:1px solid var(--border)}
.qt tr:hover td{background:var(--bg3)}
.qt .code-td{font-family:var(--mono);font-size:10px;color:var(--muted)}
.qt .name-td{font-weight:500;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.qt .num-td{font-family:var(--mono);font-size:10px}
.sbadge{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;text-transform:uppercase}
.s-p{background:var(--amber-bg);color:var(--amber)}
.s-w{background:var(--blue-bg);color:var(--blue)}
.s-d{background:var(--green-bg);color:var(--green)}
.s-n{background:var(--indigo-bg);color:var(--indigo)}
.s-e{background:var(--red-bg);color:var(--red)}

/* ── RIGHT PANEL ── */
.right{background:var(--bg2);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto}
.stat-row{padding:11px 13px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.stat-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.si-blue{background:var(--blue-bg);color:var(--blue)}
.si-green{background:var(--green-bg);color:var(--green)}
.si-amber{background:var(--amber-bg);color:var(--amber)}
.si-red{background:var(--red-bg);color:var(--red)}
.si-purple{background:var(--purple-bg);color:var(--purple)}
.si-teal{background:var(--teal-bg);color:var(--teal)}
.si-indigo{background:var(--indigo-bg);color:var(--indigo)}
.stat-body{flex:1;min-width:0}
.stat-label{font-size:10px;color:var(--muted2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:1px}
.stat-value{font-size:20px;font-weight:700;font-family:var(--mono);line-height:1.1}
.stat-sub{font-size:10px;color:var(--muted);margin-top:1px}
.c-blue{color:var(--blue)} .c-green{color:var(--green)} .c-amber{color:var(--amber)}
.c-red{color:var(--red)} .c-purple{color:var(--purple)} .c-teal{color:var(--teal)}
.c-indigo{color:var(--indigo)}

/* Working list */
.wl-block{padding:10px 13px;border-bottom:1px solid var(--border)}
.wl-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted2);margin-bottom:7px}
.wl-item{display:flex;align-items:center;gap:7px;padding:4px 0;border-bottom:1px solid var(--border)}
.wl-item:last-child{border-bottom:none}
.wl-dot{width:5px;height:5px;border-radius:50%;background:var(--blue);flex-shrink:0;animation:pulse 1.5s infinite}
.wl-name{font-size:11px;font-weight:500;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wl-recs{font-size:10px;font-family:var(--mono);color:var(--teal);flex-shrink:0}
.wl-empty{font-size:11px;color:var(--muted2);font-style:italic;padding:6px 0}

/* Session timers */
.timer-block{padding:10px 13px;border-bottom:1px solid var(--border)}
.timer-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted2);margin-bottom:7px}
.timer-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px}
.timer-box{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:7px;text-align:center}
.timer-lbl{font-size:9px;color:var(--muted2);text-transform:uppercase;letter-spacing:.05em}
.timer-val{font-size:15px;font-weight:700;font-family:var(--mono)}

/* Info block */
.info-block{padding:10px 13px;flex:1}
.info-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted2);margin-bottom:7px}
.info-item{font-size:11px;color:var(--muted);line-height:1.7;display:flex;align-items:flex-start;gap:5px}
.info-item::before{content:'•';color:var(--muted2);flex-shrink:0}
.last-done-lbl{font-size:10px;color:var(--muted2);margin-top:8px;text-transform:uppercase;letter-spacing:.05em}
.last-done-val{font-family:var(--mono);font-size:11px;color:var(--green);margin-top:2px}

@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
@keyframes fadeIn{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
</style>
</head>
<body>

<!-- HEADER -->
<header class="hdr">
  <div class="hdr-logo">⚡</div>
  <div class="hdr-brand">
    <span class="hdr-brand-name">GodMode Unified</span>
    <span class="hdr-brand-sub">NAV Pipeline</span>
  </div>
  <div class="hdr-right">
    <div class="hdr-pill">
      <div class="dot" id="statusDot"></div>
      <span id="statusText" style="font-size:11px">Idle</span>
    </div>
    <div class="hdr-pill"><span id="hdrClock">--:--:--</span></div>
  </div>
</header>

<!-- OVERVIEW BAR — single source of truth, no duplicates -->
<div class="ov">
  <div class="ov-seg"><span class="ov-label">Funds</span><span class="ov-val" id="ovTotal">—</span></div>
  <div class="ov-seg"><span class="ov-label">Done</span><span class="ov-val c-green" id="ovDone">—</span></div>
  <div class="ov-seg"><span class="ov-label">Pending</span><span class="ov-val c-amber" id="ovPending">—</span></div>
  <div class="ov-seg"><span class="ov-label">Working</span><span class="ov-val c-blue" id="ovWorking">—</span></div>
  <div class="ov-seg"><span class="ov-label">Errors</span><span class="ov-val c-red" id="ovErrors">—</span></div>
  <div class="ov-needs-update" id="ovNeedsWrap" style="display:none">
    🔄 <span id="ovNeeds">0</span> needs update
  </div>
  <div class="ov-bar-area">
    <div class="ov-bar"><div class="ov-bar-fill" id="ovBarFill"></div></div>
    <span class="ov-pct" id="ovPct">0%</span>
  </div>
</div>

<!-- 3-COL MAIN -->
<div class="main">

  <!-- LEFT: Controls only (no duplicate stats) -->
  <aside class="left">
    <div class="ps">
      <div class="ps-title">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        Pipeline Controls
      </div>
      <button class="btn-start" id="btnStart" onclick="startPipeline()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        Start Download
      </button>
      <button class="btn-stop" id="btnStop" onclick="stopPipeline()" disabled>
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
        Stop Pipeline
      </button>
      <div class="toast" id="toast"></div>
    </div>

    <div class="ps">
      <div class="ps-title">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41"/></svg>
        Worker Config
      </div>
      <div class="slider-row">
        <span class="slider-label">Workers:</span>
        <input type="range" id="wSlider" min="1" max="30" value="8" oninput="document.getElementById('wVal').textContent=this.value">
        <span class="slider-val" id="wVal">8</span>
      </div>
    </div>

    <div class="ps">
      <div class="ps-title">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Actions
      </div>
      <div class="btn-row">
        <button class="btn-sm" onclick="retryErrors()">
          <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.1"/></svg>
          Retry Errors
        </button>
        <button class="btn-sm" onclick="resetAll()" style="color:var(--red)">
          <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
          Full Reset
        </button>
      </div>
    </div>

    <div class="ps" style="flex:1">
      <div class="ps-title">Pipeline Info</div>
      <div class="info-item">Single flow: NAV + Peak together</div>
      <div class="info-item">Auto-resumes from checkpoint</div>
      <div class="info-item">Stop anytime — progress saved</div>
      <div class="info-item">needs_update funds re-processed</div>
      <div class="last-done-lbl">Last completed:</div>
      <div class="last-done-val" id="lastDone">—</div>
    </div>
  </aside>

  <!-- CENTER: Charts + Log + Queue -->
  <main class="center">
    <div class="chart-row">

      <!-- Donut -->
      <div class="donut-card">
        <div class="donut-wrap">
          <svg viewBox="0 0 82 82">
            <defs>
              <linearGradient id="dg" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#2563eb"/>
                <stop offset="100%" stop-color="#7c3aed"/>
              </linearGradient>
            </defs>
            <circle class="donut-bg"   cx="41" cy="41" r="32"/>
            <circle class="donut-fill" cx="41" cy="41" r="32" id="donutRing" stroke-dasharray="0 201" stroke-dashoffset="0"/>
          </svg>
          <div class="donut-center">
            <span class="donut-pct" id="dPct">0%</span>
            <span class="donut-sub">done</span>
          </div>
        </div>
        <div class="donut-legend">
          <div class="leg"><div class="leg-dot" style="background:var(--green)"></div><span id="lgDone">0 Done</span></div>
          <div class="leg"><div class="leg-dot" style="background:var(--indigo)"></div><span id="lgNeeds">0 Stale</span></div>
          <div class="leg"><div class="leg-dot" style="background:var(--amber)"></div><span id="lgPending">0 Pending</span></div>
          <div class="leg"><div class="leg-dot" style="background:var(--blue)"></div><span id="lgWorking">0 Working</span></div>
          <div class="leg"><div class="leg-dot" style="background:var(--red)"></div><span id="lgErrors">0 Errors</span></div>
        </div>
      </div>

      <!-- Progress card -->
      <div class="prog-card">
        <div class="prog-header">
          <span class="prog-title">⚡ NAV + Peak NAV Pipeline</span>
          <span class="prog-pct" id="pPct">0%</span>
        </div>
        <div class="prog-bar"><div class="prog-fill" id="pFill"></div></div>
        <div class="prog-meta">
          <div class="pm">
            <div class="pm-label">NAV Records</div>
            <div class="pm-val c-teal" id="pmRec">—</div>
          </div>
          <div class="pm">
            <div class="pm-label">Peaks Done</div>
            <div class="pm-val c-purple" id="pmPeak">—</div>
          </div>
          <div class="pm">
            <div class="pm-label">Working</div>
            <div class="pm-val c-blue" id="pmWork">—</div>
          </div>
          <div class="pm">
            <div class="pm-label">Updated</div>
            <div class="pm-val" id="pmTs" style="font-size:12px">—</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Live log -->
    <div class="log-card">
      <div class="log-hdr">
        <span class="log-title">⚡ Live Activity Log</span>
        <span class="log-badge" id="logCnt">0 entries</span>
      </div>
      <div class="log-body" id="logBody">
        <div class="log-empty">Start karo — yahan live activity dikhegi...</div>
      </div>
    </div>

    <!-- Queue table -->
    <div class="queue-card">
      <div class="q-tabs">
        <div class="q-tab active" data-tab="pending"  onclick="switchQTab('pending')">⏳ Pending <span class="q-cnt" id="qcPending">0</span></div>
        <div class="q-tab"       data-tab="working"   onclick="switchQTab('working')">⚡ Working <span class="q-cnt" id="qcWorking">0</span></div>
        <div class="q-tab"       data-tab="errors"    onclick="switchQTab('errors')">⚠ Errors  <span class="q-cnt" id="qcErrors">0</span></div>
        <div class="q-tab"       data-tab="done"      onclick="switchQTab('done')">✅ Done+Stale <span class="q-cnt" id="qcDone">0</span></div>
        <input type="text" class="q-search" id="qSearch" placeholder="Search fund..." oninput="debouncedQSearch()">
      </div>
      <div class="q-body">
        <table class="qt">
          <thead>
            <tr>
              <th>Code</th><th>Fund Name</th><th>Category</th>
              <th>Records</th><th>Peak</th><th>Status</th><th>Updated</th>
            </tr>
          </thead>
          <tbody id="qBody"><tr><td colspan="7" style="text-align:center;color:var(--muted2);padding:14px">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>
  </main>

  <!-- RIGHT: Stats (single set, no duplicates) -->
  <aside class="right">
    <div class="stat-row">
      <div class="stat-icon si-blue">📊</div>
      <div class="stat-body">
        <div class="stat-label">Total Funds</div>
        <div class="stat-value c-blue" id="stTotal">—</div>
        <div class="stat-sub">in progress table</div>
      </div>
    </div>
    <div class="stat-row">
      <div class="stat-icon si-green">✅</div>
      <div class="stat-body">
        <div class="stat-label">Completed</div>
        <div class="stat-value c-green" id="stDone">—</div>
        <div class="stat-sub">NAV + Peak done</div>
      </div>
    </div>
    <div class="stat-row">
      <div class="stat-icon si-indigo">🔄</div>
      <div class="stat-body">
        <div class="stat-label">Needs Update</div>
        <div class="stat-value c-indigo" id="stNeeds">—</div>
        <div class="stat-sub">stale — will re-process</div>
      </div>
    </div>
    <div class="stat-row">
      <div class="stat-icon si-amber">⏳</div>
      <div class="stat-body">
        <div class="stat-label">Pending</div>
        <div class="stat-value c-amber" id="stPending">—</div>
        <div class="stat-sub">never downloaded</div>
      </div>
    </div>
    <div class="stat-row">
      <div class="stat-icon si-red">⚠️</div>
      <div class="stat-body">
        <div class="stat-label">Errors</div>
        <div class="stat-value c-red" id="stErrors">—</div>
        <div class="stat-sub">retry available</div>
      </div>
    </div>
    <div class="stat-row">
      <div class="stat-icon si-teal">📈</div>
      <div class="stat-body">
        <div class="stat-label">NAV Records</div>
        <div class="stat-value c-teal" id="stRec">—</div>
        <div class="stat-sub">total in nav_history</div>
      </div>
    </div>
    <div class="stat-row">
      <div class="stat-icon si-purple">🏆</div>
      <div class="stat-body">
        <div class="stat-label">Peak NAV Done</div>
        <div class="stat-value c-purple" id="stPeak">—</div>
        <div class="stat-sub">all-time highs tracked</div>
      </div>
    </div>

    <div class="wl-block">
      <div class="wl-title">🔄 Processing Now</div>
      <div id="wlBody"><div class="wl-empty">Not running</div></div>
    </div>

    <div class="timer-block">
      <div class="timer-title">⏱ Timers</div>
      <div class="timer-grid">
        <div class="timer-box"><div class="timer-lbl">Total</div><div class="timer-val" id="tTotal">00:00:00</div></div>
        <div class="timer-box"><div class="timer-lbl">Session</div><div class="timer-val" id="tSess">00:00:00</div></div>
      </div>
    </div>
  </aside>
</div>

<script>
const API = 'gmu_api.php';
const CIRC = 2 * Math.PI * 32; // r=32
let isRunning = false, pollTimer = null;
let logEntries = [], seenKeys = new Set();
let sessionStart = null, totalStart = null;
let curTab = 'pending', qSearchVal = '', qTimer = null;

// ── Clock ──
setInterval(() => {
  document.getElementById('hdrClock').textContent =
    new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false});
}, 1000);

// ── Timers ──
function fmtT(s){return [Math.floor(s/3600),Math.floor(s%3600/60),s%60].map(n=>String(n).padStart(2,'0')).join(':')}
setInterval(()=>{
  const now=Math.floor(Date.now()/1000);
  if(totalStart)   document.getElementById('tTotal').textContent=fmtT(now-totalStart);
  if(sessionStart) document.getElementById('tSess').textContent=fmtT(now-sessionStart);
},1000);

// ── Toast ──
function toast(msg,type='ok',ms=4000){
  const t=document.getElementById('toast');
  t.textContent=msg; t.className='toast '+type; t.style.display='block';
  clearTimeout(t._t); t._t=setTimeout(()=>t.style.display='none',ms);
}

// ── Number format ──
function fmt(n){return n!=null?Number(n).toLocaleString('en-IN'):'—'}

// ── Update UI ──
function updateUI(d) {
  const pct = d.pct || 0;

  // Overview bar
  document.getElementById('ovTotal').textContent   = fmt(d.total);
  document.getElementById('ovDone').textContent    = fmt(d.completed);
  document.getElementById('ovPending').textContent = fmt(d.pending);
  document.getElementById('ovWorking').textContent = fmt(d.working);
  document.getElementById('ovErrors').textContent  = fmt(d.errors);
  document.getElementById('ovBarFill').style.width = pct + '%';
  document.getElementById('ovPct').textContent     = pct + '%';

  // needs_update pill
  const nu = d.needs_update || 0;
  const nuWrap = document.getElementById('ovNeedsWrap');
  nuWrap.style.display = nu > 0 ? 'flex' : 'none';
  document.getElementById('ovNeeds').textContent = fmt(nu);

  // Donut
  const arc = (pct / 100) * CIRC;
  document.getElementById('donutRing').setAttribute('stroke-dasharray', `${arc.toFixed(1)} ${(CIRC-arc).toFixed(1)}`);
  document.getElementById('dPct').textContent    = pct + '%';
  document.getElementById('lgDone').textContent    = fmt(d.completed)   + ' Done';
  document.getElementById('lgNeeds').textContent   = fmt(nu)            + ' Stale';
  document.getElementById('lgPending').textContent = fmt(d.pending)     + ' Pending';
  document.getElementById('lgWorking').textContent = fmt(d.working)     + ' Working';
  document.getElementById('lgErrors').textContent  = fmt(d.errors)      + ' Errors';

  // Progress card
  document.getElementById('pPct').textContent   = pct + '%';
  document.getElementById('pFill').style.width  = pct + '%';
  document.getElementById('pmRec').textContent  = fmt(d.total_records);
  document.getElementById('pmPeak').textContent = fmt(d.peaks_done);
  document.getElementById('pmWork').textContent = fmt(d.working);
  document.getElementById('pmTs').textContent   = d.timestamp || '—';

  // Tab counts
  document.getElementById('qcPending').textContent = fmt(d.pending);
  document.getElementById('qcWorking').textContent = fmt(d.working);
  document.getElementById('qcErrors').textContent  = fmt(d.errors);
  document.getElementById('qcDone').textContent    = fmt(d.completed);

  // Right stats (single set)
  document.getElementById('stTotal').textContent   = fmt(d.total);
  document.getElementById('stDone').textContent    = fmt(d.completed);
  document.getElementById('stNeeds').textContent   = fmt(nu);
  document.getElementById('stPending').textContent = fmt(d.pending);
  document.getElementById('stErrors').textContent  = fmt(d.errors);
  document.getElementById('stRec').textContent     = fmt(d.total_records);
  document.getElementById('stPeak').textContent    = fmt(d.peaks_done);

  if (d.last_completed) document.getElementById('lastDone').textContent = d.last_completed;

  // Status dot
  const running = d.status === 'running' || d.working > 0;
  document.getElementById('statusDot').className = 'dot' + (running ? ' run' : '');
  document.getElementById('statusText').textContent = running
    ? `Running (${d.working} active)` : (d.completed === d.total && d.total > 0 ? 'Complete ✓' : 'Idle');

  if (running !== isRunning) { isRunning = running; syncBtns(); }

  // Working list
  const wl = document.getElementById('wlBody');
  if (!d.current_funds || d.current_funds.length === 0) {
    wl.innerHTML = '<div class="wl-empty">Not running</div>';
  } else {
    wl.innerHTML = d.current_funds.map(f =>
      `<div class="wl-item"><div class="wl-dot"></div>
       <span class="wl-name" title="${f.scheme_name}">${f.scheme_name||f.scheme_code}</span>
       <span class="wl-recs">${fmt(f.records_saved)}</span></div>`
    ).join('');
  }

  // Log — recent completions
  if (d.recent_done) {
    d.recent_done.forEach(f => {
      const key = f.scheme_code + '|' + (f.updated_at||'');
      if (!seenKeys.has(key)) {
        seenKeys.add(key);
        const pk = f.peak_calculated == 1 ? ' 🏆' : '';
        addLog('✅',
          `<span class="sc">${f.scheme_code}</span> <span class="cat">${f.scheme_name}</span>` +
          ` — <span class="recs">+${fmt(f.records_saved)} recs</span>${pk}`,
          f.updated_at ? String(f.updated_at).split(' ').pop()?.substring(0,8) : null
        );
      }
    });
  }
}

// ── Log ──
function addLog(icon, html, ts) {
  logEntries.unshift({icon, html, ts: ts || new Date().toLocaleTimeString('en-IN',{hour12:false})});
  if (logEntries.length > 120) logEntries.pop();
  renderLog();
}
function renderLog() {
  const b = document.getElementById('logBody');
  if (!logEntries.length) { b.innerHTML = '<div class="log-empty">Start karo — yahan live activity dikhegi...</div>'; return; }
  b.innerHTML = logEntries.map(e =>
    `<div class="log-entry"><span class="log-ts">${e.ts}</span><span class="log-icon">${e.icon}</span><span class="log-text">${e.html}</span></div>`
  ).join('');
  document.getElementById('logCnt').textContent = logEntries.length + ' entries';
}

// ── Poll ──
async function poll() {
  try {
    const r = await fetch(`${API}?action=summary&_=${Date.now()}`);
    const d = await r.json();
    if (!d.error) updateUI(d);
  } catch(e) { console.warn('Poll fail:', e); }
}
function startPolling() { poll(); pollTimer = setInterval(poll, 2000); }

// ── Actions ──
async function startPipeline() {
  const parallel = document.getElementById('wSlider').value;
  document.getElementById('btnStart').disabled = true;
  toast('Starting pipeline...','inf');
  try {
    const r = await fetch(`${API}?action=start&parallel=${parallel}`, {method:'POST',body:new FormData()});
    const d = await r.json();
    if (d.ok) {
      isRunning = true; sessionStart = Math.floor(Date.now()/1000);
      if (!totalStart) totalStart = sessionStart;
      syncBtns(); addLog('🚀', d.message || 'Pipeline started!');
      toast(d.message || 'Pipeline started!','ok'); poll();
    } else {
      document.getElementById('btnStart').disabled = false;
      toast(d.message || d.error || 'Start failed','err');
      if (d.all_done) addLog('✅','All funds already complete!');
    }
  } catch(e) { document.getElementById('btnStart').disabled=false; toast('Network error','err'); }
}

async function stopPipeline() {
  document.getElementById('btnStop').disabled = true;
  try {
    const r = await fetch(`${API}?action=stop`,{method:'POST',body:new FormData()});
    const d = await r.json();
    if (d.ok) {
      isRunning=false; sessionStart=null; syncBtns();
      addLog('⛔', d.message||'Pipeline stopped.'); toast(d.message||'Stopped.','ok');
    } else toast(d.error||'Stop failed','err');
  } catch(e){ toast('Network error','err'); }
  finally{ document.getElementById('btnStop').disabled=false; }
}

async function retryErrors() {
  try {
    const r = await fetch(`${API}?action=retry_errors`,{method:'POST',body:new FormData()});
    const d = await r.json();
    if(d.ok){addLog('🔁',d.message||'Errors queued.');toast(d.message||'Done','ok');poll();}
    else toast(d.error||'Failed','err');
  } catch(e){toast('Network error','err');}
}

async function resetAll() {
  if(!confirm('⚠️ Full Reset?\n\nSab funds "pending" ho jayenge.\nNAV history DELETE NAHI hogi.')) return;
  logEntries=[]; seenKeys.clear(); renderLog();
  try {
    const r = await fetch(`${API}?action=reset`,{method:'POST',body:new FormData()});
    const d = await r.json();
    if(d.ok){isRunning=false;sessionStart=null;totalStart=null;syncBtns();addLog('🔄',d.message||'Reset done.');toast(d.message||'Reset complete.','ok');poll();}
    else toast(d.error||'Failed','err');
  } catch(e){toast('Network error','err');}
}

function syncBtns(){
  document.getElementById('btnStart').disabled = isRunning;
  document.getElementById('btnStop').disabled  = !isRunning;
}

// ── Queue table ──
function switchQTab(tab){
  curTab = tab;
  document.querySelectorAll('.q-tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===tab));
  loadQTable();
}

async function loadQTable(page=1){
  const body = document.getElementById('qBody');
  const s    = document.getElementById('qSearch').value.trim();
  try {
    const r = await fetch(`${API}?action=table&tab=${curTab}&page=${page}&search=${encodeURIComponent(s)}`);
    const d = await r.json();
    if(d.error||!d.rows){body.innerHTML=`<tr><td colspan="7" style="color:var(--red);padding:10px;text-align:center">${d.error||'Error'}</td></tr>`;return;}
    if(!d.rows.length){body.innerHTML=`<tr><td colspan="7" style="color:var(--muted2);padding:14px;text-align:center">Koi fund nahi mila</td></tr>`;return;}
    const statusMap = {
      pending:{cls:'s-p',lbl:'Pending'},
      in_progress:{cls:'s-w',lbl:'Working'},
      completed:{cls:'s-d',lbl:'Done'},
      needs_update:{cls:'s-n',lbl:'Stale'},
      error:{cls:'s-e',lbl:'Error'},
    };
    body.innerHTML = d.rows.map(r=>{
      const st = statusMap[r.status]||{cls:'s-p',lbl:r.status};
      const pk = r.peak_calculated==1 ? '<span style="color:var(--teal)">🏆</span>' : '—';
      return `<tr>
        <td class="code-td">${r.scheme_code||'—'}</td>
        <td class="name-td" title="${r.scheme_name}">${r.scheme_name}</td>
        <td>${r.category}</td>
        <td class="num-td">${fmt(r.records_saved)}</td>
        <td style="text-align:center">${pk}</td>
        <td><span class="sbadge ${st.cls}">${st.lbl}</span></td>
        <td class="num-td" style="color:var(--muted2)">${r.updated_at||'—'}</td>
      </tr>`;
    }).join('');
  } catch(e){body.innerHTML=`<tr><td colspan="7" style="color:var(--red);padding:10px;text-align:center">Load error</td></tr>`;}
}

function debouncedQSearch(){clearTimeout(qTimer);qTimer=setTimeout(()=>loadQTable(1),350);}

// ── Init ──
startPolling();
loadQTable();
setInterval(()=>{if(isRunning)loadQTable();},5000);
addLog('ℹ️','GodMode Unified ready. Start karo!');
</script>
</body>
</html>
