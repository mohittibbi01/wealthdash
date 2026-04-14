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
:root {
  --bg:#f0f2f7;--white:#ffffff;--surface:#f7f8fc;
  --border:#e4e7f0;--border2:#cdd2e0;
  --text:#111827;--muted:#6b7280;--muted2:#9ca3af;
  --blue:#2563eb;--blue-bg:#eff6ff;--blue-bdr:#bfdbfe;--blue-dk:#1d4ed8;
  --green:#059669;--green-bg:#ecfdf5;--green-bdr:#a7f3d0;
  --amber:#d97706;--amber-bg:#fffbeb;--amber-bdr:#fcd34d;
  --red:#dc2626;--red-bg:#fef2f2;--red-bdr:#fecaca;
  --purple:#7c3aed;
  --mono:'JetBrains Mono',monospace;
  --sans:'Sora',sans-serif;
  --r:10px;--rsm:6px;--rlg:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:13px;line-height:1.5;display:flex;flex-direction:column;min-height:100vh}

/* HEADER */
.hdr{background:var(--white);border-bottom:1px solid var(--border);padding:0 20px;height:52px;display:flex;align-items:center;gap:12px;flex-shrink:0}
.hdr-logo{width:32px;height:32px;background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:var(--rsm);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.hdr-title{font-size:14px;font-weight:600;letter-spacing:-.02em;color:var(--text)}
.hdr-sep{width:1px;height:18px;background:var(--border);margin:0 2px}
.hdr-sub{font-size:11px;color:var(--muted);font-weight:400}
.hdr-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.pill{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;border:1px solid var(--border);background:var(--surface);font-size:11px;color:var(--muted)}
.dot{width:6px;height:6px;border-radius:50%;background:var(--muted2);flex-shrink:0;transition:all .3s}
.dot.run{background:var(--green);box-shadow:0 0 0 3px rgba(5,150,105,.15);animation:blink 1.5s infinite}
.dot.err{background:var(--red)}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}

/* TILES */
.tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:12px 16px;flex-shrink:0}
.tile{background:var(--white);border:1px solid var(--border);border-radius:var(--rlg);padding:14px 16px;display:flex;flex-direction:column;gap:2px;position:relative;overflow:hidden}
.tile::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:var(--rlg) 0 0 var(--rlg)}
.t-blue::after{background:var(--blue)}.t-amber::after{background:var(--amber)}.t-red::after{background:var(--red)}.t-green::after{background:var(--green)}
.tile-top{display:flex;align-items:center;gap:6px;margin-bottom:7px}
.tile-icon{font-size:13px}
.tile-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
.tile-val{font-family:var(--mono);font-size:26px;font-weight:500;line-height:1;letter-spacing:-.03em}
.t-blue .tile-val{color:var(--blue)}.t-amber .tile-val{color:var(--amber)}.t-red .tile-val{color:var(--red)}.t-green .tile-val{color:var(--green)}
.tile-sub{font-size:11px;color:var(--muted2);margin-top:3px}

/* BODY */
.body{flex:1;display:grid;grid-template-columns:272px 1fr;min-height:0;overflow:hidden}

/* SIDEBAR */
.sidebar{background:var(--white);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
.sb-block{padding:14px 16px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.09em;color:var(--muted2);margin-bottom:10px}

/* Timers */
.timer-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px}
.tbox{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:9px 11px;text-align:center}
.tsub{font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted2);margin-bottom:3px}
.tval{font-family:var(--mono);font-size:15px;font-weight:500;color:var(--text);letter-spacing:.02em}
.tdate{text-align:center;font-size:10px;color:var(--muted2);font-family:var(--mono);margin-top:6px}

/* NAV Range */
.nav-range{background:var(--blue-bg);border:1px solid var(--blue-bdr);border-radius:var(--r);padding:10px 12px}
.nav-range-row{display:flex;align-items:center;justify-content:space-between}
.nr-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.07em;color:var(--blue);font-weight:600;margin-bottom:2px}
.nr-val{font-family:var(--mono);font-size:12px;font-weight:600;color:var(--blue-dk)}
.nr-arr{font-size:14px;color:var(--blue);opacity:.5}
.irows{margin-top:8px;display:flex;flex-direction:column;gap:0}
.irow{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid var(--border);font-size:11px}
.irow:last-child{border-bottom:none}
.ilbl{color:var(--muted)}.ival{font-family:var(--mono);font-size:10px;color:var(--text);text-align:right}
.ival.acc{color:var(--blue);font-weight:500}

/* Workers */
.wrow{display:flex;align-items:center;gap:7px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:8px 11px;margin-bottom:9px}
.wlbl{font-size:11px;color:var(--muted);flex:1}
.wval{font-family:var(--mono);font-size:15px;font-weight:600;color:var(--blue);width:26px;text-align:center}
.wbtn{width:24px;height:24px;border-radius:5px;border:1px solid var(--border2);background:var(--white);cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;color:var(--text);transition:all .15s;flex-shrink:0}
.wbtn:hover{background:var(--blue-bg);border-color:var(--blue);color:var(--blue)}

/* Buttons */
.btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:9px 14px;border-radius:var(--r);border:none;font-family:var(--sans);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;letter-spacing:.01em}
.btn:active{transform:scale(.97)}
.btn-primary{background:var(--blue);color:#fff;box-shadow:0 1px 4px rgba(37,99,235,.25)}
.btn-primary:hover{background:var(--blue-dk)}
.btn-primary:disabled{background:#93c5fd;cursor:not-allowed;transform:none;box-shadow:none}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--border2)}
.btn-outline:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-bg)}
.btn-warn{background:transparent;color:var(--amber);border:1px solid var(--amber-bdr)}
.btn-warn:hover{background:var(--amber-bg)}
.btn-warn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.btn-danger{background:transparent;color:var(--red);border:1px solid var(--red-bdr)}
.btn-danger:hover{background:var(--red-bg)}
.btn-sm{padding:7px 12px;font-size:11px}
.brow{display:flex;flex-direction:column;gap:6px}
.bpair{display:flex;gap:6px}.bpair .btn{flex:1}

/* Log */
.log-area{flex:1;display:flex;flex-direction:column;overflow:hidden;min-height:0}
.log-head{padding:8px 16px;border-bottom:1px solid var(--border);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--muted2);display:flex;align-items:center;justify-content:space-between;background:var(--surface);flex-shrink:0}
.log-clr{font-size:10px;color:var(--muted2);cursor:pointer;font-weight:400;text-transform:none;letter-spacing:0;transition:color .15s}
.log-clr:hover{color:var(--red)}
.log-body{flex:1;overflow-y:auto;padding:3px 0}
.log-line{padding:2px 16px;font-family:var(--mono);font-size:10px;color:var(--muted);line-height:1.8;border-left:2px solid transparent}
.log-line.ok{color:var(--green);border-color:var(--green-bdr)}
.log-line.er{color:var(--red);border-color:var(--red-bdr)}
.log-line.inf{color:var(--blue);border-color:var(--blue-bdr)}
.log-line.wn{color:var(--amber);border-color:var(--amber-bdr)}

/* MAIN */
.main{overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:12px}

/* Panel */
.panel{background:var(--white);border:1px solid var(--border);border-radius:var(--rlg)}
.phead{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;background:var(--surface);border-radius:var(--rlg) var(--rlg) 0 0}
.ptitle{font-size:12px;font-weight:600;color:var(--text);letter-spacing:-.01em}
.pbadge{margin-left:auto;font-family:var(--mono);font-size:12px;font-weight:600;color:var(--blue)}
.pbody{padding:14px 16px}

/* Progress */
.pmeta{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.plabel{font-size:11px;color:var(--muted)}
.pdates{font-family:var(--mono);font-size:10px;color:var(--muted2)}
.ptrack{background:var(--bg);border-radius:5px;height:7px;overflow:hidden;border:1px solid var(--border)}
.pfill{height:100%;border-radius:5px;background:linear-gradient(90deg,var(--blue-dk),var(--blue),var(--purple));transition:width .5s cubic-bezier(.4,0,.2,1);min-width:3px}

/* Breakdown */
.bkdown{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}
.bk{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;text-align:center;position:relative;overflow:hidden}
.bk::before{content:'';position:absolute;bottom:0;left:0;right:0;height:2px}
.bkd::before{background:var(--green)}.bkp::before{background:var(--blue)}.bkq::before{background:var(--muted2)}.bke::before{background:var(--red)}
.bk-dot{width:7px;height:7px;border-radius:50%;margin:0 auto 7px}
.bk-n{font-family:var(--mono);font-size:20px;font-weight:500;line-height:1}
.bkd .bk-n{color:var(--green)}.bkp .bk-n{color:var(--blue)}.bkq .bk-n{color:var(--muted)}.bke .bk-n{color:var(--red)}
.bk-l{font-size:10px;color:var(--muted2);margin-top:4px;text-transform:uppercase;letter-spacing:.06em}

/* Coverage */
.covbar{display:flex;align-items:stretch;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-top:12px}
.covblk{flex:1;padding:11px 14px;border-right:1px solid var(--border);display:flex;flex-direction:column;gap:3px}
.covblk:last-child{border-right:none}
.covlbl{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);font-weight:600}
.covval{font-family:var(--mono);font-size:13px;font-weight:600;color:var(--text)}
.covval.blue{color:var(--blue)}.covval.purple{color:var(--purple)}.covval.green{color:var(--green)}

/* Queue */
.queue-panel{flex:1;display:flex;flex-direction:column;min-height:260px}
.qtabs{display:flex;align-items:center;border-bottom:1px solid var(--border);padding:0 14px;background:var(--surface)}
.qtab{padding:9px 10px;font-size:11px;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;display:flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap}
.qtab.active{color:var(--blue);border-color:var(--blue)}.qtab:hover:not(.active){color:var(--text)}
.qbadge{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:1px 6px;font-size:10px}
.qtab.active .qbadge{background:var(--blue-bg);border-color:var(--blue-bdr);color:var(--blue)}
.sbar{padding:8px 14px;border-bottom:1px solid var(--border);background:var(--white);display:flex;align-items:center;gap:8px}
.sin{flex:1;background:var(--surface);border:1px solid var(--border2);border-radius:var(--rsm);padding:6px 10px;font-size:12px;font-family:var(--sans);color:var(--text);outline:none;transition:border-color .15s}
.sin:focus{border-color:var(--blue);background:var(--white)}.sin::placeholder{color:var(--muted2)}
.tbl-wrap{flex:1;overflow-y:auto}
.ftbl{width:100%;border-collapse:collapse;table-layout:fixed}
.ftbl th{background:var(--surface);padding:7px 12px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);text-align:left;border-bottom:1px solid var(--border);position:sticky;top:0}
.ftbl td{padding:7px 12px;font-size:11px;border-bottom:1px solid var(--border);vertical-align:middle}
.ftbl tr:last-child td{border-bottom:none}.ftbl tr:hover td{background:var(--blue-bg)}
.fcode{font-family:var(--mono);font-size:10px;color:var(--muted2)}
.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:600}
.bp{background:#f3f4f6;color:var(--muted)}.bw{background:var(--blue-bg);color:var(--blue)}.bd{background:var(--green-bg);color:var(--green)}.be{background:var(--red-bg);color:var(--red)}
.nodata{text-align:center;color:var(--muted2);padding:36px 16px;font-size:12px}

/* Modal */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s}
.overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--white);border:1px solid var(--border);border-radius:var(--rlg);padding:22px;max-width:340px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.12);transform:translateY(6px);transition:transform .2s}
.overlay.open .modal{transform:none}
.modal h3{font-size:14px;font-weight:600;margin-bottom:6px;letter-spacing:-.01em}
.modal p{font-size:12px;color:var(--muted);line-height:1.7;margin-bottom:16px}
.mbtns{display:flex;gap:8px;justify-content:flex-end}.mbtns .btn{width:auto}

/* Toast */
.tc{position:fixed;bottom:18px;right:18px;z-index:2000;display:flex;flex-direction:column;gap:6px}
.toast{background:var(--white);border:1px solid var(--border);border-radius:var(--r);padding:9px 13px;font-size:12px;max-width:280px;display:flex;align-items:center;gap:7px;box-shadow:0 4px 14px rgba(0,0,0,.1);animation:su .2s ease}
@keyframes su{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}
.tok{border-left:3px solid var(--green)}.ter{border-left:3px solid var(--red)}

/* Scrollbar */
::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:10px}

/* Responsive */
@media(max-width:1100px){.tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:768px){.body{grid-template-columns:1fr}.sidebar{display:none}}
</style>
</head>
<body>

<div class="hdr">
  <div class="hdr-logo">📡</div>
  <span class="hdr-title">NAV History Downloader</span>
  <div class="hdr-sep"></div>
  <span class="hdr-sub">WealthDash Admin</span>
  <div class="hdr-right">
    <div class="pill"><span class="dot" id="sdot"></span><span id="stxt">Idle</span></div>
    <div class="pill" style="font-family:var(--mono);font-size:11px" id="clk">--:--:--</div>
  </div>
</div>

<div class="tiles">
  <div class="tile t-blue">
    <div class="tile-top"><span class="tile-icon">📊</span><span class="tile-label">Total Funds</span></div>
    <div class="tile-val" id="cTotal">—</div>
    <div class="tile-sub" id="cNav">— NAV records</div>
  </div>
  <div class="tile t-amber">
    <div class="tile-top"><span class="tile-icon">⏳</span><span class="tile-label">Needs Update</span></div>
    <div class="tile-val" id="cNeeds">—</div>
    <div class="tile-sub">Funds with outdated NAV</div>
  </div>
  <div class="tile t-red">
    <div class="tile-top"><span class="tile-icon">⚠</span><span class="tile-label">Errors</span></div>
    <div class="tile-val" id="cErr">—</div>
    <div class="tile-sub">Click Retry Errors to fix</div>
  </div>
  <div class="tile t-green">
    <div class="tile-top"><span class="tile-icon">✅</span><span class="tile-label">Downloaded</span></div>
    <div class="tile-val" id="cDone">—</div>
    <div class="tile-sub" id="cLatest">Latest: —</div>
  </div>
</div>

<div class="body">
  <div class="sidebar">

    <div class="sb-block">
      <div class="sb-label">Session Timers</div>
      <div class="timer-grid">
        <div class="tbox"><div class="tsub">Total Elapsed</div><div class="tval" id="tTotal">00:00:00</div></div>
        <div class="tbox"><div class="tsub">This Session</div><div class="tval" id="tSess">00:00:00</div></div>
      </div>
      <div class="tdate" id="tDate"></div>
    </div>

    <div class="sb-block">
      <div class="sb-label">NAV Data Info</div>
      <div class="nav-range">
        <div class="nav-range-row">
          <div><div class="nr-lbl">From</div><div class="nr-val" id="iOldest">—</div></div>
          <div class="nr-arr">→</div>
          <div style="text-align:right"><div class="nr-lbl" style="text-align:right">To</div><div class="nr-val" id="iLatest">—</div></div>
        </div>
      </div>
      <div class="irows">
        <div class="irow"><span class="ilbl">Needs Update</span><span class="ival" id="iNeeds">—</span></div>
        <div class="irow"><span class="ilbl">Total Records</span><span class="ival acc" id="iNavTotal">—</span></div>
        <div class="irow"><span class="ilbl">Last Processed</span><span class="ival" id="iLast" style="font-size:9px">—</span></div>
      </div>
    </div>

    <div class="sb-block">
      <div class="sb-label">Parallel Workers</div>
      <div class="wrow">
        <span class="wlbl">⚡ Workers per batch</span>
        <button class="wbtn" onclick="chgP(-1)">−</button>
        <span class="wval" id="prlV">4</span>
        <button class="wbtn" onclick="chgP(1)">+</button>
      </div>
      <div class="sb-label">Actions</div>
      <div class="brow">
        <button class="btn btn-primary" id="btnStart" onclick="doStart()">▶ Start Download</button>
        <div class="bpair">
          <button class="btn btn-warn btn-sm" id="btnPause" onclick="doPause()" disabled>⏸ Pause</button>
          <button class="btn btn-outline btn-sm" onclick="doRetry()">🔄 Retry Errors</button>
        </div>
        <div class="bpair">
          <button class="btn btn-outline btn-sm" onclick="doExport()">📥 Export CSV</button>
          <button class="btn btn-danger btn-sm" onclick="showMod()">🗑 Clear Queue</button>
        </div>
      </div>
    </div>

    <div class="log-area">
      <div class="log-head">Activity Log <span class="log-clr" onclick="clrLog()">Clear</span></div>
      <div class="log-body" id="logBody">
        <div class="log-line inf">[system] Ready. Click Start Download to begin.</div>
      </div>
    </div>

  </div>

  <div class="main">

    <div class="panel">
      <div class="phead">
        <span style="font-size:14px">📈</span>
        <span class="ptitle">Overall Progress</span>
        <span class="pbadge" id="pct">0%</span>
      </div>
      <div class="pbody">
        <div class="pmeta">
          <span class="plabel" id="pLabel">No active download session</span>
          <span class="pdates" id="pDates">Oldest: — &nbsp; Latest: —</span>
        </div>
        <div class="ptrack"><div class="pfill" id="pFill" style="width:0%"></div></div>
        <div class="bkdown">
          <div class="bk bkd"><div class="bk-dot" style="background:var(--green)"></div><div class="bk-n" id="bDone">0</div><div class="bk-l">Downloaded</div></div>
          <div class="bk bkp"><div class="bk-dot" style="background:var(--blue)"></div><div class="bk-n" id="bProg">0</div><div class="bk-l">In Progress</div></div>
          <div class="bk bkq"><div class="bk-dot" style="background:var(--muted2)"></div><div class="bk-n" id="bPend">0</div><div class="bk-l">Pending</div></div>
          <div class="bk bke"><div class="bk-dot" style="background:var(--red)"></div><div class="bk-n" id="bErr">0</div><div class="bk-l">Errors</div></div>
        </div>
        <div class="covbar">
          <div class="covblk"><div class="covlbl">Oldest Date</div><div class="covval blue" id="rOldest">—</div></div>
          <div class="covblk"><div class="covlbl">Latest Date</div><div class="covval blue" id="rLatest">—</div></div>
          <div class="covblk"><div class="covlbl">Total Records</div><div class="covval purple" id="rNavCount">—</div></div>
          <div class="covblk"><div class="covlbl">Active Funds</div><div class="covval green" id="rFundCount">—</div></div>
          <div class="covblk"><div class="covlbl">Effective Total</div><div class="covval" id="rEff" style="color:var(--text)">—</div></div>
        </div>
      </div>
    </div>

    <div class="panel queue-panel">
      <div class="phead">
        <span style="font-size:14px">📋</span>
        <span class="ptitle">Fund Queue</span>
      </div>
      <div class="qtabs">
        <div class="qtab active" id="tPend" onclick="stab('pending')">⏳ Pending <span class="qbadge" id="tbPend">0</span></div>
        <div class="qtab" id="tWork" onclick="stab('working')">⚡ Working <span class="qbadge" id="tbWork">0</span></div>
        <div class="qtab" id="tErrs" onclick="stab('errors')">⚠ Errors <span class="qbadge" id="tbErrs">0</span></div>
        <div class="qtab" id="tDone" onclick="stab('done')">✅ Done <span class="qbadge" id="tbDone2">0</span></div>
      </div>
      <div class="sbar">
        <span style="color:var(--muted2);font-size:12px;flex-shrink:0">🔍</span>
        <input class="sin" id="fsearch" placeholder="Search scheme name or code…" oninput="renderTable()">
      </div>
      <div class="tbl-wrap">
        <table class="ftbl">
          <colgroup><col style="width:42px"><col style="width:auto"><col style="width:80px"><col style="width:100px"><col style="width:95px"><col style="width:82px"></colgroup>
          <thead><tr><th>#</th><th>Scheme Name</th><th>Code</th><th>From Date</th><th>Status</th><th>Records</th></tr></thead>
          <tbody id="ftbody"><tr><td colspan="6" class="nodata">No funds in queue. Click Start Download.</td></tr></tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<div class="overlay" id="mod">
  <div class="modal">
    <h3>🗑 Clear Queue</h3>
    <p>Queue clear ho jayegi. Existing NAV history data safe rahega. Kabhi bhi naya download start kar sakte hain.</p>
    <div class="mbtns">
      <button class="btn btn-outline btn-sm" onclick="closeMod()">Cancel</button>
      <button class="btn btn-danger btn-sm" onclick="doReset()">Clear Queue</button>
    </div>
  </div>
</div>
<div class="tc" id="toastC"></div>

<script>
const API='nav_worker.php';
const S={running:false,paused:false,parallel:4,workers:0,sessStart:null,totalSec:0,tab:'pending',stats:{},queue:[]};
const fmtT=s=>[Math.floor(s/3600),Math.floor((s%3600)/60),s%60].map(n=>String(n).padStart(2,'0')).join(':');
const g=id=>document.getElementById(id);
const num=n=>(n??0).toLocaleString('en-IN');

setInterval(()=>{
  const n=new Date();
  g('clk').textContent=n.toLocaleTimeString('en-IN',{hour12:false});
  g('tDate').textContent=n.toLocaleDateString('en-IN',{weekday:'long',day:'numeric',month:'short',year:'numeric'});
  const ss=S.sessStart&&S.running&&!S.paused?Math.floor((Date.now()-S.sessStart)/1000):0;
  g('tTotal').textContent=fmtT(S.totalSec+ss);
  if(S.sessStart)g('tSess').textContent=fmtT(Math.floor((Date.now()-S.sessStart)/1000));
},1000);
(()=>{const n=new Date();g('clk').textContent=n.toLocaleTimeString('en-IN',{hour12:false});g('tDate').textContent=n.toLocaleDateString('en-IN',{weekday:'long',day:'numeric',month:'short',year:'numeric'});})();

async function api(action,extra={}){
  const r=await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,...extra})});
  return r.json();
}
async function poll(){
  try{const d=await api('status');if(!d.ok)return;S.stats=d;renderUI(d);}catch(e){}
}
function renderUI(d){
  const q=d.queue??{};
  const tot=(q.pending||0)+(q.in_progress||0)+(q.downloaded||0)+(q.errors||0);
  const done=q.downloaded||0;
  const pct=tot>0?Math.round((done/tot)*100):0;
  g('cTotal').textContent=num(d.total_funds);
  g('cNav').textContent=num(d.nav_records)+' NAV records';
  g('cNeeds').textContent=num(d.funds_needing_update);
  g('cErr').textContent=num(q.errors);
  g('cDone').textContent=num(q.downloaded);
  g('cLatest').textContent='Latest: '+(d.date_range?.latest??'—');
  g('pct').textContent=pct+'%';
  g('pFill').style.width=pct+'%';
  g('pLabel').textContent=tot>0?`${num(done)} of ${num(tot)} funds processed`:'No active download session';
  g('pDates').textContent=`Oldest: ${d.date_range?.oldest??'—'}   Latest: ${d.date_range?.latest??'—'}`;
  g('bDone').textContent=num(q.downloaded);
  g('bProg').textContent=num(q.in_progress);
  g('bPend').textContent=num(q.pending);
  g('bErr').textContent=num(q.errors);
  const ol=d.date_range?.oldest??'—',la=d.date_range?.latest??'—';
  g('rOldest').textContent=ol;g('rLatest').textContent=la;
  g('rNavCount').textContent=num(d.nav_records);
  g('rFundCount').textContent=num(d.total_funds);
  g('rEff').textContent=tot>0?num(tot):'—';
  g('iOldest').textContent=ol;g('iLatest').textContent=la;
  g('iNeeds').textContent=num(d.funds_needing_update)+' funds';
  g('iNavTotal').textContent=num(d.nav_records);
  if(d.last_done)g('iLast').textContent=(d.last_done.scheme_code||'')+' @ '+(d.last_done.updated_at||'').substring(0,16);
  g('tbPend').textContent=q.pending||0;
  g('tbWork').textContent=q.in_progress||0;
  g('tbErrs').textContent=q.errors||0;
  g('tbDone2').textContent=q.downloaded||0;
  S.totalSec=d.total_elapsed_sec||0;S.paused=d.paused;
  const pb=g('btnPause');
  pb.textContent=S.paused?'▶ Resume':'⏸ Pause';
  pb.className=S.paused?'btn btn-primary btn-sm':'btn btn-warn btn-sm';
}
function renderTable(){
  const tbody=g('ftbody');
  const q=g('fsearch').value.toLowerCase();
  const filtered=S.queue.filter(f=>{
    if(S.tab==='pending'&&f.status!=='pending')return false;
    if(S.tab==='working'&&f.status!=='in_progress')return false;
    if(S.tab==='errors'&&f.status!=='error')return false;
    if(S.tab==='done'&&f.status!=='done')return false;
    if(q&&!f.scheme_name.toLowerCase().includes(q)&&!f.scheme_code.toLowerCase().includes(q))return false;
    return true;
  });
  if(!filtered.length){tbody.innerHTML=`<tr><td colspan="6" class="nodata">${S.queue.length===0?'No funds in queue. Click Start Download.':'No results match filter.'}</td></tr>`;return;}
  const cls={pending:'bp',in_progress:'bw',done:'bd',error:'be'};
  const lbl={pending:'⏳ Pending',in_progress:'⚡ Working',done:'✅ Done',error:'⚠ Error'};
  tbody.innerHTML=filtered.map((f,i)=>`<tr>
    <td style="color:var(--muted2);font-family:var(--mono);font-size:10px">${i+1}</td>
    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:0" title="${f.scheme_name}">${f.scheme_name}</td>
    <td><span class="fcode">${f.scheme_code}</span></td>
    <td style="font-family:var(--mono);font-size:10px;color:var(--muted)">${f.from_date||'—'}</td>
    <td><span class="badge ${cls[f.status]||'bp'}">${lbl[f.status]||f.status}</span></td>
    <td style="font-family:var(--mono);font-size:11px;color:var(--blue)">${f.records!=null?num(f.records):'—'}</td>
  </tr>`).join('');
}
async function procOne(){
  try{
    const d=await api('process_next');
    if(!d.ok)return'err';
    if(d.status==='idle')return'idle';
    if(d.status==='paused')return'paused';
    if(d.status==='processed'){
      log(`✓ ${d.name||d.scheme} — ${num(d.inserted)} records`,'ok');
      const i=S.queue.findIndex(f=>f.scheme_code==d.scheme);
      if(i>=0){S.queue[i].status='done';S.queue[i].records=d.inserted;}
    }else if(d.status==='error'){
      log(`✗ ${d.scheme}: ${d.error}`,'er');
      const i=S.queue.findIndex(f=>f.scheme_code==d.scheme);
      if(i>=0)S.queue[i].status='error';
    }
    renderTable();return d.status;
  }catch(e){return'err';}
}
async function wLoop(){
  while(S.running&&!S.paused){
    const r=await procOne();
    if(r==='idle'||r==='paused')break;
    await new Promise(r2=>setTimeout(r2,80));
  }
  S.workers--;
  if(S.workers===0&&S.running&&!S.paused){
    const q=S.stats?.queue??{};
    if((q.pending||0)===0){S.running=false;setRun(false);log('✓ All downloads complete!','ok');toast('Download complete!',true);}
  }
}
function setRun(r){
  const dot=g('sdot'),txt=g('stxt'),sb=g('btnStart'),pb=g('btnPause');
  if(r){dot.className='dot run';txt.textContent='Running';sb.disabled=true;pb.disabled=false;}
  else{dot.className='dot';txt.textContent=S.paused?'Paused':'Idle';sb.disabled=false;pb.disabled=!S.paused;}
}
async function doStart(){
  log('Starting — missing NAV funds queue ho rahe hain…','inf');
  const d=await api('start');
  if(!d.ok){toast(d.message,false);log('✗ '+d.message,'er');return;}
  if(!d.queued){toast(d.message,true);log('✓ '+d.message,'ok');return;}
  log(`${d.queued} funds queued — ${S.parallel} workers starting…`,'inf');
  toast(`${d.queued} funds queued`,true);
  S.queue=Array.from({length:d.queued},()=>({scheme_code:'…',scheme_name:'Fetching fund data…',status:'pending',from_date:null,records:null}));
  renderTable();
  S.sessStart=Date.now();S.running=true;S.paused=false;
  g('tSess').textContent='00:00:00';setRun(true);
  S.workers=S.parallel;
  for(let i=0;i<S.parallel;i++)wLoop();
  await poll();
}
async function doPause(){
  const d=await api('pause');if(!d.ok)return;
  S.paused=d.paused;
  if(S.paused){S.running=false;setRun(false);log('⏸ Paused','wn');toast('Paused',true);}
  else{S.running=true;setRun(true);log('▶ Resumed','inf');toast('Resumed',true);S.workers=S.parallel;for(let i=0;i<S.parallel;i++)wLoop();}
}
async function doRetry(){
  const d=await api('retry_errors');toast(d.message,d.ok);log((d.ok?'🔄':'✗')+' '+d.message,d.ok?'inf':'er');
  if(d.ok&&d.retried>0&&!S.running){
    S.queue.forEach(f=>{if(f.status==='error')f.status='pending';});
    renderTable();S.running=true;S.paused=false;setRun(true);S.workers=S.parallel;for(let i=0;i<S.parallel;i++)wLoop();
  }
  await poll();
}
function doExport(){window.open(API+'?action=export_csv','_blank');log('📥 CSV export started','inf');}
async function doReset(){
  closeMod();const d=await api('reset',{confirm:true});
  toast(d.message,d.ok);log((d.ok?'✓':'✗')+' '+d.message,d.ok?'ok':'er');
  S.running=false;S.queue=[];renderTable();setRun(false);await poll();
}
function chgP(delta){S.parallel=Math.max(1,Math.min(16,S.parallel+delta));g('prlV').textContent=S.parallel;}
function stab(tab){
  S.tab=tab;
  ['Pend','Work','Errs','Done'].forEach(t=>g('t'+t)?.classList.remove('active'));
  const m={pending:'Pend',working:'Work',errors:'Errs',done:'Done'};
  g('t'+m[tab])?.classList.add('active');renderTable();
}
function log(msg,type=''){
  const f=g('logBody');
  const t=new Date().toLocaleTimeString('en-IN',{hour12:false});
  const el=document.createElement('div');el.className='log-line '+type;
  el.textContent=`[${t}] ${msg}`;f.appendChild(el);f.scrollTop=f.scrollHeight;
  while(f.children.length>200)f.removeChild(f.firstChild);
}
function clrLog(){g('logBody').innerHTML='';}
function showMod(){g('mod').classList.add('open');}
function closeMod(){g('mod').classList.remove('open');}
g('mod').addEventListener('click',e=>{if(e.target===g('mod'))closeMod();});
function toast(msg,ok=true){
  const t=document.createElement('div');t.className='toast '+(ok?'tok':'ter');
  t.innerHTML=`<span>${ok?'✅':'❌'}</span><span>${msg}</span>`;
  g('toastC').appendChild(t);setTimeout(()=>t.remove(),4000);
}
poll();setInterval(poll,4000);
</script>
</body>
</html>
