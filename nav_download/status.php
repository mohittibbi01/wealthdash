<?php
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
if (!is_logged_in() || !is_admin()) { header('Location: /wealthdash/login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>NAV History Downloader — WealthDash</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f0f2f7;--white:#fff;--surface:#f7f8fc;
  --border:#e4e7f0;--border2:#cdd2e0;
  --text:#111827;--muted:#6b7280;--muted2:#9ca3af;
  --blue:#2563eb;--blue-bg:#eff6ff;--blue-bdr:#bfdbfe;--blue-dk:#1d4ed8;
  --green:#059669;--green-bg:#ecfdf5;--green-bdr:#a7f3d0;
  --amber:#d97706;--amber-bg:#fffbeb;--amber-bdr:#fcd34d;
  --red:#dc2626;--red-bg:#fef2f2;--red-bdr:#fecaca;
  --purple:#7c3aed;--purple-bg:#f5f3ff;--purple-bdr:#ddd6fe;
  --teal:#0d9488;--teal-bg:#f0fdfa;--teal-bdr:#99f6e4;
  --mono:'JetBrains Mono',monospace;--sans:'Sora',sans-serif;
  --r:10px;--rsm:6px;--rlg:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}
body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:14px;line-height:1.5;display:flex;flex-direction:column}

/* ── HEADER ── */
.hdr{background:var(--white);border-bottom:1px solid var(--border);padding:0 18px;height:46px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.hdr-logo{width:28px;height:28px;background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.hdr-title{font-size:14px;font-weight:600;letter-spacing:-.02em}
.hdr-sep{width:1px;height:18px;background:var(--border)}
.hdr-sub{font-size:12px;color:var(--muted)}
.hdr-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.pill{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;border:1px solid var(--border);background:var(--surface);font-size:12px;color:var(--muted)}
.dot{width:7px;height:7px;border-radius:50%;background:var(--muted2);flex-shrink:0;transition:all .3s}
.dot.run{background:var(--green);box-shadow:0 0 0 3px rgba(5,150,105,.15);animation:blink 1.5s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}

/* ── 2-COLUMN BODY ── */
.body{flex:1;display:grid;grid-template-columns:280px 1fr;min-height:0;overflow:hidden}

/* ══════════════════════════════════════
   LEFT PANEL — 3 vertical sections
   ══════════════════════════════════════ */
.left-panel{background:var(--white);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}

/* Section A — Stats grid (fixed height) */
.lp-stats{flex-shrink:0;padding:10px 12px;border-bottom:2px solid var(--border)}
.lp-section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted2);margin-bottom:8px;display:flex;align-items:center;gap:5px}

/* 3x3 stat grid */
.stat-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:7px 8px;position:relative;overflow:hidden}
.stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:8px 0 0 8px}
.sc-blue::before{background:var(--blue)}.sc-purple::before{background:var(--purple)}
.sc-amber::before{background:var(--amber)}.sc-green::before{background:var(--green)}
.sc-red::before{background:var(--red)}.sc-teal::before{background:var(--teal)}
.sc-muted::before{background:var(--muted2)}
.stat-lbl{font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stat-val{font-family:var(--mono);font-size:16px;font-weight:700;line-height:1;letter-spacing:-.02em}
.sc-blue .stat-val{color:var(--blue)}.sc-purple .stat-val{color:var(--purple)}
.sc-amber .stat-val{color:var(--amber)}.sc-green .stat-val{color:var(--green)}
.sc-red .stat-val{color:var(--red)}.sc-teal .stat-val{color:var(--teal)}
.sc-muted .stat-val{color:var(--muted)}
.stat-sub{font-size:9px;color:var(--muted2);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stat-bar{height:2px;background:var(--border);border-radius:2px;margin-top:4px;overflow:hidden}
.stat-bar-fill{height:100%;border-radius:2px;background:linear-gradient(90deg,var(--blue-dk),var(--blue));transition:width .5s}

/* Wide card spanning 2 cols */
.stat-card.wide{grid-column:span 2}
.stat-card.wide .stat-val{font-size:18px}

/* Section B — Controls (fixed) */
.lp-controls{flex-shrink:0;padding:10px 12px;border-bottom:2px solid var(--border)}

/* Timer row */
.timer-row{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:7px}
.tbox{background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:6px 9px;text-align:center}
.tsub{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted2);margin-bottom:2px}
.tval{font-family:var(--mono);font-size:13px;font-weight:600;color:var(--text)}
.tdate{text-align:center;font-size:10px;color:var(--muted2);font-family:var(--mono);margin-bottom:7px}

/* Speed+ETA row */
.meta-row{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:8px}
.meta-box{background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:5px 9px;display:flex;align-items:center;justify-content:space-between}
.meta-lbl{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.meta-val{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--blue)}

/* Workers */
.wrow{display:flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:6px 9px;margin-bottom:8px}
.wlbl{font-size:12px;color:var(--muted);flex:1}
.wval{font-family:var(--mono);font-size:14px;font-weight:700;color:var(--blue);width:24px;text-align:center}
.wbtn{width:22px;height:22px;border-radius:5px;border:1px solid var(--border2);background:var(--white);cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;color:var(--text);transition:all .15s;flex-shrink:0}
.wbtn:hover{background:var(--blue-bg);border-color:var(--blue);color:var(--blue)}

/* Buttons */
.btn{display:flex;align-items:center;justify-content:center;gap:5px;width:100%;padding:7px 10px;border-radius:7px;border:none;font-family:var(--sans);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-primary{background:var(--blue);color:#fff;box-shadow:0 1px 4px rgba(37,99,235,.2)}
.btn-primary:hover{background:var(--blue-dk)}
.btn-primary:disabled{background:#93c5fd;cursor:not-allowed;transform:none;box-shadow:none}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border2)}
.btn-outline:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-bg)}
.btn-warn{background:transparent;color:var(--amber);border:1px solid var(--amber-bdr)}
.btn-warn:hover{background:var(--amber-bg)}.btn-warn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.btn-danger{background:transparent;color:var(--red);border:1px solid var(--red-bdr)}
.btn-danger:hover{background:var(--red-bg)}
.brow{display:flex;flex-direction:column;gap:5px}
.bpair{display:flex;gap:5px}.bpair .btn{flex:1}

/* Last processed */
.last-box{background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:6px 9px}
.last-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted2);margin-bottom:3px;font-weight:600}
.last-val{font-size:11px;color:var(--muted);word-break:break-all;line-height:1.4;font-family:var(--mono)}

/* Section C — Log (fills remaining space) */
.lp-log{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:80px}
.log-head{padding:6px 12px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted2);display:flex;align-items:center;justify-content:space-between;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
.log-clr{font-size:10px;color:var(--muted2);cursor:pointer;font-weight:400;text-transform:none;letter-spacing:0}
.log-clr:hover{color:var(--red)}
.log-body{flex:1;overflow-y:auto;padding:2px 0}
.log-line{padding:2px 12px;font-family:var(--mono);font-size:10px;color:var(--muted);line-height:1.8;border-left:2px solid transparent}
.log-line.ok{color:var(--green);border-color:var(--green-bdr)}
.log-line.er{color:var(--red);border-color:var(--red-bdr)}
.log-line.inf{color:var(--blue);border-color:var(--blue-bdr)}
.log-line.wn{color:var(--amber);border-color:var(--amber-bdr)}

/* ══════════════════════════════════════
   RIGHT PANEL — main content
   ══════════════════════════════════════ */
.main{overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:9px}

/* Panel */
.panel{background:var(--white);border:1px solid var(--border);border-radius:var(--rlg)}
.phead{padding:9px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;background:var(--surface);border-radius:var(--rlg) var(--rlg) 0 0}
.ptitle{font-size:13px;font-weight:600;color:var(--text)}
.pbadge{margin-left:auto;font-family:var(--mono);font-size:13px;font-weight:700;color:var(--blue)}
.pbody{padding:12px 14px}

/* ── Circular progress ── */
.circ-label{font-size:12px;color:var(--muted);margin-bottom:10px}
.circ-wrap{display:flex;align-items:center;gap:22px}
.circ-svg{flex-shrink:0;filter:drop-shadow(0 2px 8px rgba(37,99,235,.13))}
.circ-track{fill:none;stroke:var(--border);stroke-width:9}
.circ-fill{fill:none;stroke:url(#circGrad);stroke-width:9;stroke-linecap:round;transform:rotate(-90deg);transform-origin:50% 50%;transition:stroke-dashoffset .6s ease}
.circ-pct-text{font-family:var(--mono);font-size:17px;font-weight:700;fill:var(--blue);dominant-baseline:middle;text-anchor:middle}
.circ-sub-text{font-size:9px;fill:var(--muted2);dominant-baseline:middle;text-anchor:middle;font-family:var(--sans)}
.circ-stats{flex:1;display:flex;flex-direction:column;gap:7px}
.circ-stat-row{display:flex;align-items:center;gap:8px}
.circ-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.circ-stat-lbl{font-size:12px;color:var(--muted);flex:1}
.circ-stat-val{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--text)}

/* Segment bar */
.seg-bar{display:flex;height:5px;border-radius:3px;overflow:hidden;margin-top:12px;gap:1px}
.seg-done{background:var(--green);transition:flex .4s}
.seg-work{background:var(--blue);transition:flex .4s}
.seg-pend{background:#e5e7eb;transition:flex .4s}
.seg-err{background:var(--red);transition:flex .4s}

.running .circ-svg{animation:circPulse 2s ease-in-out infinite}
@keyframes circPulse{0%,100%{filter:drop-shadow(0 2px 8px rgba(37,99,235,.13))}50%{filter:drop-shadow(0 2px 18px rgba(37,99,235,.4))}}

/* ── Fund Queue ── */
.queue-panel{flex:1;display:flex;flex-direction:column;min-height:200px}
.qtabs{display:flex;align-items:center;border-bottom:1px solid var(--border);padding:0 10px;background:var(--surface);overflow-x:auto;flex-shrink:0;gap:0}
.qtab{padding:8px 8px;font-size:12px;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;display:flex;align-items:center;gap:4px;transition:all .15s;white-space:nowrap;flex-shrink:0}
.qtab.active{color:var(--blue);border-color:var(--blue)}.qtab:hover:not(.active){color:var(--text)}
.qbdg{background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:1px 6px;font-size:11px}
.qtab.active .qbdg{background:var(--blue-bg);border-color:var(--blue-bdr);color:var(--blue)}
.t-ptc{color:var(--purple)}.t-ptc.active{border-color:var(--purple)}
.t-ptc .qbdg{background:var(--purple-bg);border-color:var(--purple-bdr);color:var(--purple)}
.t-qfd{color:var(--teal)}.t-qfd.active{border-color:var(--teal)}
.t-qfd .qbdg{background:var(--teal-bg);border-color:var(--teal-bdr);color:var(--teal)}
.sbar{padding:7px 12px;border-bottom:1px solid var(--border);background:var(--white);display:flex;align-items:center;gap:6px;flex-shrink:0}
.sin{flex:1;background:var(--surface);border:1px solid var(--border2);border-radius:var(--rsm);padding:6px 10px;font-size:13px;font-family:var(--sans);color:var(--text);outline:none;transition:border-color .15s}
.sin:focus{border-color:var(--blue);background:var(--white)}.sin::placeholder{color:var(--muted2)}
.tbl-wrap{flex:1;overflow-y:auto}
.ftbl{width:100%;border-collapse:collapse;table-layout:fixed}
.ftbl th{background:var(--surface);padding:6px 11px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);text-align:left;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1}
.ftbl td{padding:6px 11px;font-size:12px;border-bottom:1px solid var(--border);vertical-align:middle}
.ftbl tr:last-child td{border-bottom:none}.ftbl tbody tr:hover td{background:var(--blue-bg)}
.fcode{font-family:var(--mono);font-size:11px;color:var(--muted2)}
.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:600}
.bp{background:#f3f4f6;color:var(--muted)}.bw{background:var(--blue-bg);color:var(--blue)}.bd{background:var(--green-bg);color:var(--green)}.be{background:var(--red-bg);color:var(--red)}
.bptc{background:var(--purple-bg);color:var(--purple)}.bqfd{background:var(--teal-bg);color:var(--teal)}
.nodata{text-align:center;color:var(--muted2);padding:28px 16px;font-size:13px}

/* Modal */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;z-index:500;opacity:0;pointer-events:none;transition:opacity .2s}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--white);border:1px solid var(--border);border-radius:var(--rlg);padding:22px;max-width:320px;width:90%;box-shadow:0 8px 28px rgba(0,0,0,.12);transform:translateY(6px);transition:transform .2s}
.overlay.open .modal{transform:none}
.modal h3{font-size:15px;font-weight:600;margin-bottom:7px}
.modal p{font-size:13px;color:var(--muted);line-height:1.7;margin-bottom:14px}
.mbtns{display:flex;gap:6px;justify-content:flex-end}.mbtns .btn{width:auto;padding:7px 16px}

/* Toast */
.tc{position:fixed;bottom:14px;right:14px;z-index:600;display:flex;flex-direction:column;gap:5px}
.toast{background:var(--white);border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-size:13px;max-width:280px;display:flex;align-items:center;gap:7px;box-shadow:0 4px 14px rgba(0,0,0,.1);animation:su .18s ease}
@keyframes su{from{transform:translateY(8px);opacity:0}to{transform:none;opacity:1}}
.tok{border-left:3px solid var(--green)}.ter{border-left:3px solid var(--red)}

/* Divider between lp sections */
.lp-divider-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--muted2);padding:5px 12px 4px;background:var(--surface);border-bottom:1px solid var(--border);border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;gap:5px}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:10px}
@media(max-width:900px){.body{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- HEADER -->
<div class="hdr">
  <div class="hdr-logo">📡</div>
  <span class="hdr-title">NAV History Downloader</span>
  <div class="hdr-sep"></div>
  <span class="hdr-sub">WealthDash Admin</span>
  <div class="hdr-right">
    <div class="pill"><span class="dot" id="sdot"></span><span id="stxt">Idle</span></div>
    <div class="pill" style="font-family:var(--mono);font-size:12px" id="clk">--:--:--</div>
  </div>
</div>

<!-- BODY -->
<div class="body">

  <!-- ══ LEFT PANEL ══ -->
  <div class="left-panel">

    <!-- ── SECTION A: STATS GRID ── -->
    <div class="lp-stats">
      <div class="lp-section-title">📊 Stats</div>
      <div class="stat-grid">

        <!-- Total Funds — wide -->
        <div class="stat-card sc-blue wide">
          <div class="stat-lbl">Total Funds</div>
          <div class="stat-val" id="cTotal">—</div>
          <div class="stat-sub">active schemes</div>
        </div>

        <!-- Needs Update -->
        <div class="stat-card sc-amber">
          <div class="stat-lbl">Needs Update</div>
          <div class="stat-val" id="iNeeds">—</div>
          <div class="stat-sub">stale funds</div>
        </div>

        <!-- NAV Records — wide -->
        <div class="stat-card sc-purple wide">
          <div class="stat-lbl">NAV Records</div>
          <div class="stat-val" id="cNav">—</div>
          <div class="stat-sub" id="cNavRange">range: — → —</div>
        </div>

        <!-- Done -->
        <div class="stat-card sc-green">
          <div class="stat-lbl">Done</div>
          <div class="stat-val" id="cDone">—</div>
          <div class="stat-bar"><div class="stat-bar-fill" id="tilePctBar" style="width:0%"></div></div>
        </div>

        <!-- Working -->
        <div class="stat-card sc-blue">
          <div class="stat-lbl">Working</div>
          <div class="stat-val" id="bProg">0</div>
          <div class="stat-sub">active</div>
        </div>

        <!-- Pending -->
        <div class="stat-card sc-muted">
          <div class="stat-lbl">Pending</div>
          <div class="stat-val" id="bPend">0</div>
          <div class="stat-sub">in queue</div>
        </div>

        <!-- Errors — wide -->
        <div class="stat-card sc-red wide">
          <div class="stat-lbl">Errors</div>
          <div class="stat-val" id="bErr">0</div>
          <div class="stat-sub">failed funds</div>
        </div>

        <!-- Speed -->
        <div class="stat-card sc-teal">
          <div class="stat-lbl">Speed</div>
          <div class="stat-val" id="tSpeed">—</div>
          <div class="stat-sub">funds/min</div>
        </div>

      </div>
    </div>

    <!-- ── SECTION B: CONTROLS ── -->
    <div class="lp-divider-label">⚙ Controls</div>
    <div class="lp-controls">

      <!-- Timers -->
      <div class="timer-row">
        <div class="tbox"><div class="tsub">Total</div><div class="tval" id="tTotal">00:00:00</div></div>
        <div class="tbox"><div class="tsub">Session</div><div class="tval" id="tSess">00:00:00</div></div>
      </div>
      <div class="tdate" id="tDate"></div>

      <!-- Speed + ETA -->
      <div class="meta-row">
        <div class="meta-box">
          <span class="meta-lbl">ETA</span>
          <span class="meta-val" id="tEta">—</span>
        </div>
        <div class="meta-box">
          <span class="meta-lbl">Last NAV</span>
          <span class="meta-val" style="color:var(--green);font-size:11px" id="cLatest">—</span>
        </div>
      </div>

      <!-- Workers -->
      <div class="wrow">
        <span class="wlbl">⚡ Workers / batch</span>
        <button class="wbtn" onclick="chgP(-1)">−</button>
        <span class="wval" id="prlV">2</span>
        <button class="wbtn" onclick="chgP(1)">+</button>
      </div>

      <!-- Action buttons -->
      <div class="brow">
        <button class="btn btn-primary" id="btnStart" onclick="doStart()">▶ Start Download</button>
        <div class="bpair">
          <button class="btn btn-warn" id="btnPause" onclick="doPause()" disabled>⏸ Pause</button>
          <button class="btn btn-outline" onclick="doRetry()">🔄 Retry</button>
        </div>
        <div class="bpair">
          <button class="btn btn-outline" onclick="doExport()">📥 Export CSV</button>
          <button class="btn btn-danger" onclick="showMod()">🗑 Clear</button>
        </div>
      </div>

      <!-- Last Processed -->
      <div style="margin-top:7px">
        <div class="last-box">
          <div class="last-lbl">Last Processed</div>
          <div class="last-val" id="iLast">—</div>
        </div>
      </div>

    </div>

    <!-- ── SECTION C: ACTIVITY LOG ── -->
    <div class="lp-log">
      <div class="log-head">
        <span>📋 Activity Log</span>
        <span class="log-clr" onclick="clrLog()">Clear</span>
      </div>
      <div class="log-body" id="logBody">
        <div class="log-line inf">[system] Ready. Click Start Download to begin.</div>
      </div>
    </div>

  </div><!-- /left-panel -->

  <!-- ══ RIGHT: MAIN ══ -->
  <div class="main">

    <!-- Progress Panel (Circular) -->
    <div class="panel" id="progressPanel">
      <div class="phead">
        <span class="ptitle">📈 Overall Progress</span>
        <span class="pbadge" id="pct">0%</span>
      </div>
      <div class="pbody">
        <div class="circ-label" id="pLabel">No active download session</div>
        <div class="circ-wrap">
          <svg class="circ-svg" width="110" height="110" viewBox="0 0 110 110">
            <defs>
              <linearGradient id="circGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#1d4ed8"/>
                <stop offset="100%" stop-color="#7c3aed"/>
              </linearGradient>
            </defs>
            <circle class="circ-track" cx="55" cy="55" r="44"/>
            <circle class="circ-fill" id="circFill" cx="55" cy="55" r="44"
              stroke-dasharray="276.46" stroke-dashoffset="276.46"/>
            <text class="circ-pct-text" x="55" y="51" id="circPctTxt">0%</text>
            <text class="circ-sub-text" x="55" y="64" id="circSubTxt">ready</text>
          </svg>
          <div class="circ-stats">
            <div class="circ-stat-row">
              <span class="circ-dot" style="background:var(--blue)"></span>
              <span class="circ-stat-lbl">Total Funds</span>
              <span class="circ-stat-val" id="covTotal">—</span>
            </div>
            <div class="circ-stat-row">
              <span class="circ-dot" style="background:var(--green)"></span>
              <span class="circ-stat-lbl">Done</span>
              <span class="circ-stat-val" style="color:var(--green)" id="covDone">—</span>
            </div>
            <div class="circ-stat-row">
              <span class="circ-dot" style="background:#d1d5db;border:1px solid var(--border2)"></span>
              <span class="circ-stat-lbl">Pending</span>
              <span class="circ-stat-val" id="covPend">—</span>
            </div>
            <div class="circ-stat-row">
              <span class="circ-dot" style="background:var(--red)"></span>
              <span class="circ-stat-lbl">Errors</span>
              <span class="circ-stat-val" style="color:var(--red)" id="covErr">—</span>
            </div>
            <div class="circ-stat-row">
              <span class="circ-dot" style="background:var(--purple)"></span>
              <span class="circ-stat-lbl">NAV Records</span>
              <span class="circ-stat-val" style="color:var(--purple)" id="covNav">—</span>
            </div>
          </div>
        </div>
        <div class="seg-bar">
          <div class="seg-done" id="segDone" style="flex:0"></div>
          <div class="seg-work" id="segWork" style="flex:0"></div>
          <div class="seg-err"  id="segErr"  style="flex:0"></div>
          <div class="seg-pend" id="segPend" style="flex:1"></div>
        </div>
      </div>
    </div>

    <!-- Fund Queue Panel -->
    <div class="panel queue-panel">
      <div class="phead">
        <span class="ptitle">📋 Fund Queue</span>
      </div>
      <div class="qtabs">
        <div class="qtab active" id="tPend" onclick="stab('pending')">⏳ Pending <span class="qbdg" id="tbPend">0</span></div>
        <div class="qtab" id="tWork" onclick="stab('working')">⚡ Working <span class="qbdg" id="tbWork">0</span></div>
        <div class="qtab" id="tErrs" onclick="stab('errors')">⚠ Errors <span class="qbdg" id="tbErrs">0</span></div>
        <div class="qtab" id="tDone" onclick="stab('done')">✅ Done <span class="qbdg" id="tbDone2">0</span></div>
        <div class="qtab t-ptc" id="tPtc" onclick="stab('ptc')">🔍 To Check <span class="qbdg" id="tbPtc">0</span></div>
        <div class="qtab t-qfd" id="tQfd" onclick="stab('qfd')">📥 Queue DL <span class="qbdg" id="tbQfd">0</span></div>
      </div>
      <div class="sbar">
        <span style="color:var(--muted2);font-size:12px;flex-shrink:0">🔍</span>
        <input class="sin" id="fsearch" placeholder="Search scheme name or code…" oninput="renderTable()">
        <span style="font-size:11px;color:var(--muted2);flex-shrink:0" id="rowCount"></span>
      </div>
      <div class="tbl-wrap">
        <table class="ftbl">
          <colgroup><col style="width:36px"><col><col style="width:72px"><col style="width:90px"><col style="width:88px"><col style="width:52px"><col style="width:52px"></colgroup>
          <thead>
            <tr><th>#</th><th>Scheme Name</th><th>Code</th><th>From Date</th><th>Status</th><th>Records</th><th title="Retry attempts">Retry</th></tr>
          </thead>
          <tbody id="ftbody">
            <tr><td colspan="6" class="nodata">No funds in queue. Click Start Download.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /main -->
</div><!-- /body -->

<!-- Modal -->
<div class="overlay" id="mod">
  <div class="modal">
    <h3>🗑 Clear Queue</h3>
    <p>Queue clear ho jayegi. NAV history data safe rahega. Kabhi bhi naya download start kar sakte hain.</p>
    <div class="mbtns">
      <button class="btn btn-outline" onclick="closeMod()">Cancel</button>
      <button class="btn btn-danger" onclick="doReset()">Clear Queue</button>
    </div>
  </div>
</div>
<div class="tc" id="toastC"></div>

<script>
/* ─── CONFIG ─────────────────────────────── */
const API = 'nav_worker.php';

const S = {
  running:false, paused:false, parallel:2, workers:0,
  sessStart:null, totalSec:0, tab:'pending', stats:{}, queue:[],
  doneCount:0
};

const fmtT = s => [Math.floor(s/3600), Math.floor((s%3600)/60), s%60]
  .map(n=>String(n).padStart(2,'0')).join(':');
const g = id => document.getElementById(id);
const num = n => (+(n||0)).toLocaleString('en-IN');

/* ─── Clock ─────────────────────────────── */
setInterval(() => {
  const n = new Date();
  g('clk').textContent = n.toLocaleTimeString('en-IN',{hour12:false});
  g('tDate').textContent = n.toLocaleDateString('en-IN',{weekday:'long',day:'numeric',month:'short',year:'numeric'});
  const ss = S.sessStart && S.running && !S.paused
    ? Math.floor((Date.now()-S.sessStart)/1000) : 0;
  g('tTotal').textContent = fmtT(S.totalSec + ss);
  if (S.sessStart) g('tSess').textContent = fmtT(Math.floor((Date.now()-S.sessStart)/1000));
  updateEta();
}, 1000);
(()=>{
  const n=new Date();
  g('clk').textContent=n.toLocaleTimeString('en-IN',{hour12:false});
  g('tDate').textContent=n.toLocaleDateString('en-IN',{weekday:'long',day:'numeric',month:'short',year:'numeric'});
})();

/* ─── ETA ────────────────────────────────── */
function updateEta() {
  if (!S.sessStart || !S.running) { g('tEta').textContent='—'; g('tSpeed').textContent='—'; return; }
  const elapsedMin = (Date.now() - S.sessStart) / 60000;
  if (elapsedMin < 0.1) return;
  const speed = S.doneCount / elapsedMin;
  g('tSpeed').textContent = speed.toFixed(1);
  const q = S.stats?.queue ?? {};
  const rem = (q.pending||0) + (q.in_progress||0);
  g('tEta').textContent = (speed > 0 && rem > 0) ? fmtT(Math.round((rem/speed)*60)) : (rem===0?'—':'∞');
}

/* ─── API helper ────────────────────────── */
async function api(action, extra={}) {
  let res;
  try {
    res = await fetch(API, {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action, ...extra})
    });
  } catch(netErr) {
    throw new Error('Network error: ' + netErr.message);
  }
  if (res.status === 403) { showAuthBanner(); throw new Error('Session expire — login karo'); }
  if (res.status === 404) throw new Error('nav_worker.php nahi mila (404)');
  const text = await res.text();
  if (!text || text.trim() === '') throw new Error('Empty response from server');
  if (text.trim().startsWith('<')) {
    if (text.toLowerCase().includes('login')) { showAuthBanner(); return {ok:false,message:'Session expire'}; }
    showAuthBanner();
    throw new Error('Server ne HTML return kiya — PHP session expire ho gayi, page refresh karo');
  }
  let json;
  try { json = JSON.parse(text); }
  catch(e) { throw new Error('Invalid JSON: ' + text.substring(0,100)); }
  if (json.error && !json.ok) { showAuthBanner(); throw new Error('Auth error: ' + json.error); }
  return json;
}

/* ─── Poll ──────────────────────────────── */
let pollErrCount = 0;
let queueLoaded = false;
async function poll() {
  try {
    const d = await api('status');
    if (!d||!d.ok) return;
    S.stats = d;
    pollErrCount = 0;
    g('authBanner') && g('authBanner').remove();
    renderUI(d);
    if (!queueLoaded && S.queue.length===0 && (d.queue?.downloaded||0)>0 && S.tab==='done') {
      queueLoaded = true;
      await loadQueueFromServer();
    }
  } catch(e) {
    pollErrCount++;
    const msg = e.message||'';
    const isAuth = /auth|session|login|expire/i.test(msg);
    if (isAuth) { showAuthBanner(); S.running=false; return; }
    if (pollErrCount===1||pollErrCount%10===0) {
      const old=g('logBody').querySelector('.poll-err');
      old&&old.remove();
      const el=document.createElement('div');
      el.className='log-line er poll-err';
      el.textContent='[poll #'+pollErrCount+'] '+msg.substring(0,120);
      g('logBody').appendChild(el);
      g('logBody').scrollTop=g('logBody').scrollHeight;
    }
  }
}

/* ─── Load queue from server ── */
async function loadQueueFromServer() {
  try {
    const d = await api('queue_list');
    if (!d.ok||!d.items) return;
    S.queue = d.items.map(f => ({
      scheme_code: f.scheme_code||'',
      scheme_name: f.scheme_name||'',
      status: f.status||'done',
      from_date: f.from_date||null,
      records: f.records!=null?+f.records:null,
      error_msg: f.error_msg||'',
      retry_count: +(f.retry_count||0),
    }));
    queueLoaded = true;
    renderTable();
    syncBadges();
  } catch(e) {}
}

function syncBadges() {
  g('tbDone2').textContent = S.queue.filter(f=>f.status==='done').length;
  g('tbErrs').textContent  = S.queue.filter(f=>f.status==='error').length;
  g('tbPend').textContent  = S.queue.filter(f=>f.status==='pending').length;
  g('tbWork').textContent  = S.queue.filter(f=>f.status==='in_progress').length;
  g('tbPtc').textContent   = S.queue.filter(f=>f.status==='ptc').length;
  g('tbQfd').textContent   = S.queue.filter(f=>f.status==='qfd').length;
}

function showAuthBanner() {
  if (g('authBanner')) return;
  S.running=false; setRun(false);
  const b=document.createElement('div');
  b.id='authBanner';
  b.style.cssText='position:fixed;top:46px;left:0;right:0;z-index:400;background:#fef2f2;border-bottom:2px solid #fecaca;padding:8px 18px;display:flex;align-items:center;justify-content:space-between;font-size:13px;color:#dc2626;font-family:var(--sans)';
  b.innerHTML='<span>⚠ Session expire ho gayi — page refresh karein aur dobara login karein.</span><button onclick="location.reload()" style="background:#dc2626;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;font-weight:600">Refresh Page</button>';
  document.body.insertBefore(b, document.body.children[1]);
  log('⚠ Session expire — page refresh karo','wn');
}

/* ─── Render UI ─────────────────────────── */
function renderUI(d) {
  const q   = d.queue??{};
  const tot = (q.pending||0)+(q.in_progress||0)+(q.downloaded||0)+(q.errors||0);
  const done = q.downloaded||0;
  const pct  = tot>0?Math.round((done/tot)*100):0;

  // Left panel stats
  g('cTotal').textContent = num(d.total_funds);
  g('cNav').textContent   = num(d.nav_records);
  const ol = d.date_range?.oldest??'—', la = d.date_range?.latest??'—';
  g('cNavRange').textContent = ol+' → '+la;
  g('iNeeds').textContent = num(d.funds_needing_update);
  g('cDone').textContent  = num(q.downloaded);
  g('cLatest').textContent = la!=='—' ? la.substring(0,10) : '—';
  g('tilePctBar').style.width = pct+'%';
  g('bProg').textContent  = num(q.in_progress);
  g('bPend').textContent  = num(q.pending);
  g('bErr').textContent   = num(q.errors);

  // Circular progress (r=44 → circumference = 2π×44 ≈ 276.46)
  const circ = 276.46;
  const offset = pct > 0 ? circ - (pct/100)*circ : circ;
  const cf = g('circFill');
  if (cf) cf.style.strokeDashoffset = offset;
  g('circPctTxt') && (g('circPctTxt').textContent = pct+'%');
  g('circSubTxt') && (g('circSubTxt').textContent = tot>0 ? (done+'/'+tot) : 'ready');
  g('pct').textContent    = pct+'%';
  g('pLabel').textContent = tot>0 ? `${num(done)} of ${num(tot)} funds processed` : 'No active download session';
  g('progressPanel').classList.toggle('running', S.running&&!S.paused);

  // Coverage stats beside circle
  g('covTotal').textContent = num(d.total_funds);
  g('covDone').textContent  = num(q.downloaded);
  g('covPend').textContent  = num(q.pending);
  g('covErr').textContent   = num(q.errors);
  g('covNav').textContent   = num(d.nav_records);

  // Segment bar
  if (tot>0) {
    g('segDone').style.flex = String(q.downloaded||0);
    g('segWork').style.flex = String(q.in_progress||0);
    g('segErr').style.flex  = String(q.errors||0);
    g('segPend').style.flex = String(q.pending||0);
  }

  // Last processed
  if (d.last_done) {
    g('iLast').textContent = (d.last_done.scheme_name||d.last_done.scheme_code||'—')
      + '\n@ '+(d.last_done.updated_at||'').substring(0,16);
  }

  // Tab badges — server is source of truth for server-tracked statuses;
  // ptc/qfd are client-only so always come from local queue
  const serverHasQueue = (q.pending||0)+(q.in_progress||0)+(q.errors||0)+(q.downloaded||0) > 0;
  g('tbPend').textContent  = serverHasQueue ? (q.pending     ?? 0) : S.queue.filter(f=>f.status==='pending').length;
  g('tbWork').textContent  = serverHasQueue ? (q.in_progress ?? 0) : S.queue.filter(f=>f.status==='in_progress').length;
  g('tbErrs').textContent  = serverHasQueue ? (q.errors      ?? 0) : S.queue.filter(f=>f.status==='error').length;
  g('tbDone2').textContent = serverHasQueue ? (q.downloaded  ?? 0) : S.queue.filter(f=>f.status==='done').length;
  g('tbPtc').textContent   = S.queue.filter(f=>f.status==='ptc').length;
  g('tbQfd').textContent   = S.queue.filter(f=>f.status==='qfd').length;

  S.totalSec = d.total_elapsed_sec||0;
  S.paused   = !!d.paused;
  const pb = g('btnPause');
  pb.textContent = S.paused?'▶ Resume':'⏸ Pause';
  pb.className   = S.paused?'btn btn-primary':'btn btn-warn';
}

/* ─── Table render ──────────────────────── */
function renderTable() {
  const tbody=g('ftbody');
  const q=(g('fsearch').value||'').toLowerCase();
  const stMap={pending:'pending',working:'in_progress',errors:'error',done:'done',ptc:'ptc',qfd:'qfd'};
  const want=stMap[S.tab]||S.tab;
  const rows=S.queue.filter(f=>{
    if(f.status!==want)return false;
    if(q&&!f.scheme_name.toLowerCase().includes(q)&&!f.scheme_code.toLowerCase().includes(q))return false;
    return true;
  });
  g('rowCount').textContent = rows.length?`${rows.length} fund${rows.length!==1?'s':''}`:''
  if(!rows.length){
    const msg=S.queue.length===0
      ?'No funds in queue. Click Start Download.'
      :(S.tab==='done'?'No completed funds yet.':'No results match filter.');
    tbody.innerHTML=`<tr><td colspan="6" class="nodata">${msg}</td></tr>`;
    return;
  }
  const cls={pending:'bp',in_progress:'bw',done:'bd',error:'be',ptc:'bptc',qfd:'bqfd'};
  const lbl={pending:'⏳ Pending',in_progress:'⚡ Working',done:'✅ Done',error:'⚠ Error',ptc:'🔍 To Check',qfd:'📥 Queue DL'};
  tbody.innerHTML=rows.map((f,i)=>`<tr>
    <td style="color:var(--muted2);font-family:var(--mono);font-size:11px">${i+1}</td>
    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:0" title="${escHtml(f.scheme_name+(f.error_msg?' | ERR: '+f.error_msg:''))}">${escHtml(f.scheme_name)}</td>
    <td><span class="fcode">${escHtml(f.scheme_code)}</span></td>
    <td style="font-family:var(--mono);font-size:11px;color:var(--muted)">${f.from_date||'—'}</td>
    <td><span class="badge ${cls[f.status]||'bp'}">${lbl[f.status]||f.status}</span></td>
    <td style="font-family:var(--mono);font-size:12px;color:var(--blue)">${f.records!=null?num(f.records):'—'}</td>
    <td style="font-family:var(--mono);font-size:11px;color:${f.retry_count>0?'var(--amber)':'var(--muted2)'}">${f.retry_count||0}</td>
  </tr>`).join('');
}

function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

/* ─── Worker loop ───────────────────────── */
async function procOne() {
  try {
    const d=await api('process_next');
    if(!d.ok)return 'err';
    if(d.status==='idle')return 'idle';
    if(d.status==='paused')return 'paused';
    if(d.status==='processed'){
      log(`✓ ${d.name||d.scheme} — ${num(d.inserted)} records`,'ok');
      const idx=S.queue.findIndex(f=>String(f.scheme_code)===String(d.scheme));
      if(idx>=0){S.queue[idx].status='done';S.queue[idx].records=d.inserted;S.queue[idx].retry_count=0;}
      S.doneCount++;
      renderTable();
    } else if(d.status==='error'){
      log(`✗ ${d.scheme}: ${d.error}`,'er');
      const idx=S.queue.findIndex(f=>String(f.scheme_code)===String(d.scheme));
      if(idx>=0){S.queue[idx].status='error';S.queue[idx].retry_count=(S.queue[idx].retry_count||0)+1;}
      renderTable();
    }
    return d.status;
  } catch(e){
    log('Worker error: '+e.message.substring(0,80),'er');
    return 'err';
  }
}

async function wLoop(){
  while(S.running&&!S.paused){
    const r=await procOne();
    if(r==='idle'||r==='paused')break;
    await new Promise(r2=>setTimeout(r2,400));
  }
  S.workers--;
  if(S.workers===0&&S.running&&!S.paused){
    const q2=S.stats?.queue??{};
    if((q2.pending||0)===0){
      S.running=false;setRun(false);
      log('✓ All downloads complete!','ok');
      toast('Download complete!',true);
      stab('done');
      await poll();
    }
  }
}

function setRun(r){
  const dot=g('sdot'),txt=g('stxt'),sb=g('btnStart'),pb=g('btnPause');
  if(r){dot.className='dot run';txt.textContent='Running';sb.disabled=true;pb.disabled=false;}
  else{dot.className='dot';txt.textContent=S.paused?'Paused':'Idle';sb.disabled=false;pb.disabled=!S.paused;}
  g('progressPanel').classList.toggle('running',r&&!S.paused);
}

/* ─── doStart ───────────────────────────── */
async function doStart(){
  const btn=g('btnStart');
  btn.disabled=true;
  queueLoaded=false;
  S.doneCount=0;
  log('Starting download queue…','inf');
  try {
    const d = await api('start');
    if (!d.ok) {
      toast(d.message||'Start failed',false);
      log('✗ '+(d.message||'unknown error'),'er');
      btn.disabled=false;
      return;
    }
    if (!d.queued) {
      toast(d.message||'All up-to-date!',true);
      log('✓ '+(d.message||'nothing to queue'),'ok');
      btn.disabled=false;
      return;
    }
    toast(`${d.queued} funds queued`,true);
    log(`${d.queued} funds queued — starting ${S.parallel} workers`,'inf');
    S.queue = Array.from({length:d.queued},(_,i)=>({
      scheme_code:'…', scheme_name:`Fund #${i+1} — fetching…`,
      status:'pending', from_date:null, records:null, retry_count:0
    }));
    renderTable();
    S.sessStart=Date.now(); S.running=true; S.paused=false;
    g('tSess').textContent='00:00:00';
    setRun(true);
    S.workers=S.parallel;
    for(let i=0;i<S.parallel;i++) wLoop();
    await poll();
  } catch(e) {
    const msg = e.message||'unknown error';
    if (/html|session|auth|expire|login/i.test(msg)) {
      log('✗ Session expire ho gayi — page refresh karo aur dobara login karo','er');
      showAuthBanner();
    } else {
      log('✗ Start error: '+msg.substring(0,150),'er');
    }
    toast('Start failed — Activity Log dekho',false);
    btn.disabled=false;
  }
}

/* ─── doPause ───────────────────────────── */
async function doPause(){
  try{
    const d=await api('pause'); if(!d.ok)return;
    S.paused=!!d.paused;
    if(S.paused){S.running=false;setRun(false);log('⏸ Paused','wn');toast('Paused',true);}
    else{S.running=true;setRun(true);log('▶ Resumed','inf');toast('Resumed',true);S.workers=S.parallel;for(let i=0;i<S.parallel;i++)wLoop();}
  }catch(e){log('Pause error: '+e.message,'er');}
}

/* ─── doRetry ───────────────────────────── */
async function doRetry(){
  try{
    const d=await api('retry_errors');
    toast(d.message||'Retrying',d.ok);
    log((d.ok?'🔄':'✗')+' '+(d.message||''),d.ok?'inf':'er');
    if(d.ok&&d.retried>0&&!S.running){
      S.queue.forEach(f=>{if(f.status==='error')f.status='pending';});
      renderTable();S.running=true;S.paused=false;setRun(true);S.workers=S.parallel;
      for(let i=0;i<S.parallel;i++)wLoop();
    }
    await poll();
  }catch(e){log('Retry error: '+e.message,'er');}
}

function doExport(){window.open(API+'?action=export_csv','_blank');log('📥 Export CSV started','inf');}

/* ─── doReset ───────────────────────────── */
async function doReset(){
  closeMod();
  try{
    const d=await api('reset',{confirm:true});
    toast(d.message||'Queue cleared',d.ok);
    log((d.ok?'✓':'✗')+' '+(d.message||''),d.ok?'ok':'er');
    S.running=false;S.queue=[];S.doneCount=0;renderTable();setRun(false);await poll();
  }catch(e){log('Reset error: '+e.message,'er');}
}

function chgP(delta){S.parallel=Math.max(1,Math.min(16,S.parallel+delta));g('prlV').textContent=S.parallel;}

/* ─── Tab switch ────────────────────────── */
async function stab(tab){
  S.tab=tab;
  document.querySelectorAll('.qtab').forEach(t=>t.classList.remove('active'));
  const idMap={pending:'tPend',working:'tWork',errors:'tErrs',done:'tDone',ptc:'tPtc',qfd:'tQfd'};
  g(idMap[tab])?.classList.add('active');
  if(tab==='done'&&!queueLoaded&&S.queue.length===0) await loadQueueFromServer();
  renderTable();
}

/* ─── Log ───────────────────────────────── */
function log(msg,type=''){
  const f=g('logBody');
  const t=new Date().toLocaleTimeString('en-IN',{hour12:false});
  const el=document.createElement('div');
  el.className='log-line '+type;
  el.textContent=`[${t}] ${msg}`;
  f.appendChild(el);f.scrollTop=f.scrollHeight;
  while(f.children.length>300)f.removeChild(f.firstChild);
}
function clrLog(){g('logBody').innerHTML='';}

/* ─── Modal ─────────────────────────────── */
function showMod(){g('mod').classList.add('open');}
function closeMod(){g('mod').classList.remove('open');}
g('mod').addEventListener('click',e=>{if(e.target===g('mod'))closeMod();});

/* ─── Toast ─────────────────────────────── */
function toast(msg,ok=true){
  const t=document.createElement('div');
  t.className='toast '+(ok?'tok':'ter');
  t.innerHTML=`<span>${ok?'✅':'❌'}</span><span>${escHtml(String(msg))}</span>`;
  g('toastC').appendChild(t);setTimeout(()=>t.remove(),4000);
}

/* ─── Init ──────────────────────────────── */
poll();
setInterval(poll,10000);
</script>
</body>
</html>
