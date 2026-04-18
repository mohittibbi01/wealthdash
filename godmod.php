<?php
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
<title>GodMod — NAV Pipeline · WealthDash</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════
   TOKENS — matches project light theme
═══════════════════════════════════════════════════ */
:root {
  --bg:       #f0f2f7;
  --bg2:      #fff;
  --bg3:      #f7f8fc;
  --bg4:      #eef0f6;
  --border:   #e4e7f0;
  --border2:  #cdd2e0;
  --text:     #111827;
  --muted:    #6b7280;
  --muted2:   #9ca3af;

  --cyan:     #2563eb;
  --cyan-dim: #eff6ff;
  --green:    #059669;
  --green-dim:#ecfdf5;
  --amber:    #d97706;
  --amber-dim:#fffbeb;
  --red:      #dc2626;
  --red-dim:  #fef2f2;
  --purple:   #7c3aed;
  --purple-dim:#f5f3ff;
  --teal:     #0d9488;

  --mono: 'JetBrains Mono', monospace;
  --sans: 'Sora', sans-serif;
  --r: 8px; --rlg: 10px;
  --hdr: 46px;
}

*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html,body { height:100%; overflow:hidden; background:var(--bg); color:var(--text);
            font-family:var(--sans); font-size:13px; line-height:1.5; }

::-webkit-scrollbar { width:4px; height:4px; }
::-webkit-scrollbar-track { background:transparent; }
::-webkit-scrollbar-thumb { background:var(--border2); border-radius:4px; }

/* ═══════════════════════════════════════════════════
   HEADER
═══════════════════════════════════════════════════ */
.hdr {
  height:var(--hdr); background:var(--bg2); border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:0; padding:0; flex-shrink:0; position:relative; z-index:10;
}
.hdr-logo {
  width:var(--hdr); height:var(--hdr); display:flex; align-items:center; justify-content:center;
  font-size:16px; background:linear-gradient(135deg,#2563eb,#7c3aed);
  flex-shrink:0; border-right:1px solid var(--border);
}
.hdr-brand {
  padding:0 16px; display:flex; flex-direction:column; gap:1px; border-right:1px solid var(--border);
  height:100%; justify-content:center;
}
.hdr-brand-name { font-size:14px; font-weight:700; letter-spacing:-.02em; color:var(--text); }
.hdr-brand-sub  { font-size:10px; color:var(--muted); font-family:var(--mono); text-transform:uppercase; letter-spacing:.08em; }

/* Pipeline Phase Tabs */
.hdr-phases { display:flex; height:100%; flex:1; }
.phase-tab {
  display:flex; align-items:center; gap:10px; padding:0 20px; cursor:pointer;
  border-right:1px solid var(--border); border-bottom:2px solid transparent;
  transition:all .2s; position:relative; user-select:none; min-width:195px;
}
.phase-tab:hover { background:var(--bg3); }
.phase-tab.active { border-bottom-color:var(--ph-color,var(--cyan)); background:var(--bg3); }
.phase-dot { width:7px; height:7px; border-radius:50%; background:var(--muted2); flex-shrink:0; }
.phase-tab.p1.active .phase-dot { background:var(--cyan); box-shadow:0 0 0 3px #bfdbfe; animation:pulseDot 2s infinite; }
.phase-tab.p2.active .phase-dot { background:var(--green); box-shadow:0 0 0 3px #a7f3d0; animation:pulseDot 2s infinite; }
@keyframes pulseDot { 0%,100%{opacity:1} 50%{opacity:.4} }
.phase-info { display:flex; flex-direction:column; gap:1px; }
.phase-label { font-size:12px; font-weight:600; color:var(--text); }
.phase-stat  { font-size:10px; font-family:var(--mono); color:var(--muted); }

/* Right side of header */
.hdr-right { margin-left:auto; display:flex; align-items:center; gap:0; height:100%; }
.hdr-pill { padding:0 14px; display:flex; align-items:center; gap:6px; border-left:1px solid var(--border); height:100%; font-size:12px; font-family:var(--mono); color:var(--muted); }
.hdr-pill .dot { width:6px; height:6px; border-radius:50%; background:var(--muted2); }
.hdr-pill .dot.run { background:var(--green); box-shadow:0 0 0 3px #a7f3d0; animation:pulseDot 1.5s infinite; }
#hdrClock { color:var(--cyan); font-weight:600; }

/* ═══════════════════════════════════════════════════
   PIPELINE OVERVIEW BAR
═══════════════════════════════════════════════════ */
.pipeline-bar {
  background:var(--bg2); border-bottom:1px solid var(--border);
  padding:8px 16px; display:flex; align-items:center; gap:12px; flex-shrink:0;
}
.pip-phase { display:flex; align-items:center; gap:8px; flex:1; }
.pip-label { font-size:10px; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); font-weight:700; white-space:nowrap; }
.pip-track { flex:1; height:4px; background:var(--bg4); border-radius:2px; overflow:hidden; border:1px solid var(--border); }
.pip-fill  { height:100%; border-radius:2px; transition:width .8s ease; }
.pip-pct   { font-family:var(--mono); font-size:11px; font-weight:700; min-width:36px; text-align:right; }
.pip-arrow { color:var(--muted2); font-size:14px; flex-shrink:0; }
.pip-stats { display:flex; gap:16px; margin-left:12px; border-left:1px solid var(--border); padding-left:12px; }
.pip-stat  { display:flex; flex-direction:column; gap:2px; align-items:center; }
.pip-stat-v { font-family:var(--mono); font-size:13px; font-weight:700; }
.pip-stat-l { font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; }

/* ═══════════════════════════════════════════════════
   MAIN LAYOUT
═══════════════════════════════════════════════════ */
.app { display:flex; flex-direction:column; height:100vh; overflow:hidden; }
.body { flex:1; display:grid; grid-template-columns:268px 1fr; min-height:0; overflow:hidden; }

/* Phase panels */
.phase-panel { display:none; contents; }
.phase-panel.active { display:contents; }

/* ═══ LEFT SIDEBAR ═══ */
.sidebar {
  background:var(--bg2); border-right:1px solid var(--border);
  display:flex; flex-direction:column; overflow:hidden;
}
.sb-section { border-bottom:1px solid var(--border); flex-shrink:0; }
.sb-title {
  padding:6px 12px; font-size:9px; font-weight:700; text-transform:uppercase;
  letter-spacing:.1em; color:var(--muted2); background:var(--bg3); display:flex; align-items:center; gap:5px;
}

/* Stat grid */
.stat-grid { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border); }
.stat-cell {
  background:var(--bg2); padding:8px 11px; cursor:default;
  transition:background .15s; position:relative; overflow:hidden;
}
.stat-cell::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; }
.stat-cell.cv::before { background:#2563eb; } .stat-cell.gv::before { background:#059669; }
.stat-cell.av::before { background:#d97706; } .stat-cell.rv::before { background:#dc2626; }
.stat-cell.pv::before { background:#7c3aed; } .stat-cell.mv::before { background:#9ca3af; }
.stat-cell:hover { background:var(--bg3); }
.stat-cell.wide { grid-column:span 2; }
.sc-label { font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:3px; font-weight:600; }
.sc-value { font-family:var(--mono); font-size:18px; font-weight:700; line-height:1; letter-spacing:-.02em; }
.sc-sub   { font-size:9px; color:var(--muted2); margin-top:2px; font-family:var(--mono); }
.sc-bar   { height:2px; background:var(--border); border-radius:1px; margin-top:5px; overflow:hidden; }
.sc-bar-f { height:100%; border-radius:1px; transition:width .5s; }

.cv { color:#2563eb; }  .gv { color:#059669; }  .av { color:#d97706; }
.rv { color:#dc2626; }  .pv { color:#7c3aed; }  .mv { color:#6b7280; }

/* Controls */
.ctrl-section { padding:10px 12px; }
.timers { display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-bottom:8px; }
.timer-box { background:var(--bg3); border:1px solid var(--border); border-radius:var(--r); padding:6px 8px; text-align:center; }
.timer-lbl { font-size:8px; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; margin-bottom:2px; }
.timer-val { font-family:var(--mono); font-size:13px; font-weight:600; color:var(--text); }

.meta-row { display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-bottom:8px; }
.meta-box { background:var(--bg3); border:1px solid var(--border); border-radius:var(--r); padding:5px 8px; display:flex; justify-content:space-between; align-items:center; }
.meta-l { font-size:9px; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
.meta-v { font-family:var(--mono); font-size:12px; font-weight:700; color:#2563eb; }

/* Workers control */
.worker-row {
  background:var(--bg3); border:1px solid var(--border); border-radius:var(--r);
  display:flex; align-items:center; gap:6px; padding:6px 9px; margin-bottom:8px;
}
.wlbl { font-size:11px; color:var(--muted); flex:1; }
.wval { font-family:var(--mono); font-size:14px; font-weight:700; color:#2563eb; width:26px; text-align:center; }
.wbtn {
  width:22px; height:22px; border-radius:5px; border:1px solid var(--border2);
  background:var(--bg2); cursor:pointer; font-size:14px; display:flex; align-items:center;
  justify-content:center; color:var(--text); transition:all .15s; flex-shrink:0;
}
.wbtn:hover { background:#eff6ff; border-color:#2563eb; color:#2563eb; }

/* Buttons */
.btn {
  display:flex; align-items:center; justify-content:center; gap:5px;
  padding:7px 12px; border-radius:var(--r); border:none; font-family:var(--sans);
  font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; width:100%;
}
.btn:active { transform:scale(.97); }
.btn:disabled { opacity:.4; cursor:not-allowed; transform:none !important; }
.btn-run    { background:#2563eb; color:#fff; box-shadow:0 1px 4px rgba(37,99,235,.2); }
.btn-run:hover:not(:disabled) { background:#1d4ed8; }
.btn-stop   { background:#dc2626; color:#fff; }
.btn-stop:hover:not(:disabled) { background:#b91c1c; }
.btn-warn   { background:transparent; color:#d97706; border:1px solid #fcd34d; }
.btn-warn:hover:not(:disabled) { background:#fffbeb; }
.btn-ghost  { background:transparent; color:var(--muted); border:1px solid var(--border2); }
.btn-ghost:hover:not(:disabled) { border-color:#2563eb; color:#2563eb; background:#eff6ff; }
.btn-danger { background:transparent; color:#dc2626; border:1px solid #fecaca; }
.btn-danger:hover:not(:disabled) { background:#fef2f2; }
.btn-green  { background:#059669; color:#fff; box-shadow:0 1px 4px rgba(5,150,105,.2); }
.btn-green:hover:not(:disabled) { background:#047857; }

.brow { display:flex; flex-direction:column; gap:5px; }
.bpair { display:flex; gap:5px; }
.bpair .btn { flex:1; }

/* Last processed */
.last-box { background:var(--bg3); border:1px solid var(--border); border-radius:var(--r); padding:6px 9px; margin-top:7px; }
.last-lbl { font-size:9px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:3px; font-weight:600; }
.last-val { font-size:11px; color:var(--muted); word-break:break-all; line-height:1.4; font-family:var(--mono); }

/* Log */
.log-wrap { flex:1; display:flex; flex-direction:column; overflow:hidden; min-height:80px; }
.log-head { padding:6px 12px; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); background:var(--bg3); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
.log-clr  { font-size:10px; color:var(--muted); cursor:pointer; font-weight:400; text-transform:none; letter-spacing:0; }
.log-clr:hover { color:var(--red); }
.log-body { flex:1; overflow-y:auto; padding:2px 0; }
.log-line { padding:2px 12px; font-family:var(--mono); font-size:10px; color:var(--muted); line-height:1.8; border-left:2px solid transparent; }
.log-line.ok { color:#059669; border-color:#a7f3d0; }
.log-line.er { color:#dc2626; border-color:#fecaca; }
.log-line.inf { color:#2563eb; border-color:#bfdbfe; }
.log-line.wn { color:#d97706; border-color:#fcd34d; }

/* ═══════════════════════════════════════════════════
   MAIN CONTENT
═══════════════════════════════════════════════════ */
.main { overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:10px; background:var(--bg); }

/* Card */
.card { background:var(--bg2); border:1px solid var(--border); border-radius:var(--rlg); box-shadow:0 1px 4px rgba(0,0,0,.05); }
.card-head {
  padding:9px 14px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px;
  background:var(--bg3); border-radius:var(--rlg) var(--rlg) 0 0;
}
.card-title { font-size:13px; font-weight:600; }
.card-badge { margin-left:auto; font-family:var(--mono); font-size:14px; font-weight:700; color:#2563eb; }
.card-body  { padding:12px 14px; }

/* Circular progress */
.circ-wrap { display:flex; align-items:center; gap:20px; }
.circ-svg { flex-shrink:0; filter:drop-shadow(0 2px 6px rgba(37,99,235,.12)); }
.circ-track { fill:none; stroke:#e4e7f0; stroke-width:8; }
.circ-fill  { fill:none; stroke:url(#cGrad); stroke-width:8; stroke-linecap:round; transform:rotate(-90deg); transform-origin:50% 50%; transition:stroke-dashoffset .8s ease; }
.circ-pct  { font-family:var(--mono); font-size:16px; font-weight:700; fill:#2563eb; dominant-baseline:middle; text-anchor:middle; }
.circ-sub  { font-size:9px; fill:#9ca3af; dominant-baseline:middle; text-anchor:middle; font-family:sans-serif; }
.circ-stats { flex:1; display:flex; flex-direction:column; gap:7px; }
.circ-row  { display:flex; align-items:center; gap:8px; }
.circ-dot  { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.circ-lbl  { font-size:12px; color:var(--muted); flex:1; }
.circ-val  { font-family:var(--mono); font-size:12px; font-weight:700; }
.seg-bar   { display:flex; height:4px; border-radius:2px; overflow:hidden; margin-top:12px; gap:1px; }
.seg-d { background:#059669; transition:flex .4s; }
.seg-w { background:#2563eb; transition:flex .4s; }
.seg-e { background:#dc2626; transition:flex .4s; }
.seg-p { background:#e4e7f0; transition:flex .4s; }

/* Queue Table */
.queue-card { display:flex; flex-direction:column; min-height:280px; }
.qtabs { display:flex; background:var(--bg3); border-bottom:1px solid var(--border); padding:0 8px; overflow-x:auto; gap:0; flex-shrink:0; }
.qtab  { padding:9px 10px; font-size:12px; font-weight:600; color:var(--muted); cursor:pointer; border-bottom:2px solid transparent; display:flex; align-items:center; gap:4px; transition:all .15s; white-space:nowrap; }
.qtab.active { color:#2563eb; border-color:#2563eb; }
.qtab.active.t-g { color:#059669; border-color:#059669; }
.qtab.active.t-a { color:#d97706; border-color:#d97706; }
.qtab.active.t-r { color:#dc2626; border-color:#dc2626; }
.qbdg { background:var(--bg4); border:1px solid var(--border2); border-radius:9px; padding:1px 6px; font-size:10px; color:var(--muted); }
.qtab.active .qbdg { background:#eff6ff; border-color:#bfdbfe; color:#2563eb; }

.sbar { padding:7px 10px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:6px; flex-shrink:0; }
.sin  { flex:1; background:var(--bg3); border:1px solid var(--border2); border-radius:var(--r); padding:6px 10px; font-size:12px; font-family:var(--sans); color:var(--text); outline:none; transition:border-color .15s; }
.sin:focus { border-color:#2563eb; background:var(--bg2); }
.sin::placeholder { color:var(--muted2); }
.rowc { font-size:10px; color:var(--muted); flex-shrink:0; }

.tbl-wrap { flex:1; overflow-y:auto; }
.dtbl { width:100%; border-collapse:collapse; table-layout:fixed; }
.dtbl th { background:var(--bg3); padding:6px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); text-align:left; border-bottom:1px solid var(--border); position:sticky; top:0; z-index:1; }
.dtbl td { padding:6px 10px; font-size:12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.dtbl tr:last-child td { border-bottom:none; }
.dtbl tbody tr:hover td { background:#eff6ff; }
.fcode { font-family:var(--mono); font-size:10px; color:var(--muted); }
.nav-val { color:#059669; font-weight:600; font-family:var(--mono); }
.date-val { color:var(--muted); font-size:11px; font-family:var(--mono); }
.nodata { text-align:center; color:var(--muted2); padding:32px 16px; font-size:12px; }

.badge { display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:6px; font-size:10px; font-weight:700; }
.bp { background:#f3f4f6; color:#6b7280; border:1px solid #e5e7eb; }
.bw { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
.bd { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
.be { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.bi { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
.bc { background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; }
.stale-tag { display:inline-block; padding:1px 5px; background:#fffbeb; border:1px solid #fcd34d; color:#d97706; border-radius:4px; font-size:9px; margin-left:4px; }

/* Pagination */
.pag { display:flex; align-items:center; gap:4px; flex-wrap:wrap; padding:8px 10px; border-top:1px solid var(--border); flex-shrink:0; }
.pg-btn { padding:4px 9px; background:var(--bg3); border:1px solid var(--border2); border-radius:5px; color:var(--muted); font-size:11px; cursor:pointer; transition:all .1s; font-family:var(--mono); }
.pg-btn:hover { border-color:#2563eb; color:#2563eb; background:#eff6ff; }
.pg-btn.active { background:#2563eb; color:#fff; border-color:#2563eb; font-weight:700; }
.pg-btn:disabled { opacity:.3; cursor:not-allowed; }
.pg-info { color:var(--muted); font-size:10px; margin-left:auto; font-family:var(--mono); }

/* Modal */
.overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); display:flex; align-items:center; justify-content:center; z-index:500; opacity:0; pointer-events:none; transition:opacity .2s; backdrop-filter:blur(2px); }
.overlay.open { opacity:1; pointer-events:all; }
.modal { background:var(--bg2); border:1px solid var(--border); border-radius:var(--rlg); padding:22px; max-width:340px; width:90%; box-shadow:0 8px 28px rgba(0,0,0,.12); transform:translateY(6px); transition:transform .2s; }
.overlay.open .modal { transform:none; }
.modal h3 { font-size:14px; font-weight:700; margin-bottom:8px; }
.modal p { font-size:12px; color:var(--muted); line-height:1.7; margin-bottom:16px; }
.mbtns { display:flex; gap:6px; justify-content:flex-end; }
.mbtns .btn { width:auto; padding:7px 16px; }

/* Toast */
.tc { position:fixed; bottom:14px; right:14px; z-index:600; display:flex; flex-direction:column; gap:5px; }
.toast { background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); padding:9px 12px; font-size:12px; max-width:290px; display:flex; align-items:center; gap:7px; box-shadow:0 4px 14px rgba(0,0,0,.1); animation:tsIn .18s ease; }
@keyframes tsIn { from{transform:translateY(8px);opacity:0} to{transform:none;opacity:1} }
.tok { border-left:3px solid #059669; }
.ter { border-left:3px solid #dc2626; }

/* Spinner */
.spin { display:inline-block; width:14px; height:14px; border:2px solid var(--border2); border-top-color:#2563eb; border-radius:50%; animation:spinAnim .7s linear infinite; vertical-align:middle; margin-right:5px; }
@keyframes spinAnim { to{transform:rotate(360deg)} }

/* Breakdown table (Phase 2) */
.bk-table { width:100%; border-collapse:collapse; }
.bk-table td { padding:5px 4px; font-size:12px; vertical-align:middle; border-bottom:1px solid var(--border); }
.bk-table tr:last-child td { border-bottom:none; }
.bk-lbl-c { color:var(--text); display:flex; align-items:center; gap:6px; }
.bk-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; display:inline-block; }
.bk-cnt { text-align:right; font-family:var(--mono); font-size:13px; font-weight:700; min-width:70px; }
.bk-bar-c { width:130px; padding:5px 8px; }
.bk-track { background:var(--bg4); border-radius:99px; height:4px; overflow:hidden; border:1px solid var(--border); }
.bk-fill  { height:100%; border-radius:99px; transition:width .6s ease; }
.bk-total td { font-weight:800; border-top:2px solid var(--border2) !important; padding-top:8px; }

/* Alert bars */
.alert-bar { display:none; align-items:center; gap:10px; padding:8px 14px; border-radius:var(--r); margin-bottom:6px; font-size:12px; font-weight:600; }
.alert-bar.show { display:flex; }
.al-warn { background:#fffbeb; border:1px solid #fcd34d; color:#92400e; }
.al-err  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.al-done { background:#ecfdf5; border:1px solid #a7f3d0; color:#14532d; }
.al-btn { margin-left:auto; padding:5px 12px; border:1px solid currentColor; border-radius:5px; cursor:pointer; font-weight:700; font-size:11px; background:transparent; color:inherit; transition:all .15s; }
.al-btn:hover { background:rgba(0,0,0,.05); }

@media(max-width:860px) { .body{grid-template-columns:1fr} .sidebar{display:none} }
</style>
</head>
<body>
<div class="app">

<!-- ═══ HEADER ═══ -->
<div class="hdr">
  <div class="hdr-logo">⚡</div>
  <div class="hdr-brand">
    <span class="hdr-brand-name">GodMod</span>
    <span class="hdr-brand-sub">NAV Pipeline</span>
  </div>

  <div class="hdr-phases">
    <!-- Phase 1 Tab -->
    <div class="phase-tab p1 active" id="phTab1" onclick="switchPhase(1)">
      <div class="phase-dot" id="ph1dot"></div>
      <div class="phase-info">
        <span class="phase-label">Phase 1 · NAV History</span>
        <span class="phase-stat" id="ph1stat">Loading…</span>
      </div>
    </div>
    <!-- Phase 2 Tab -->
    <div class="phase-tab p2" id="phTab2" onclick="switchPhase(2)">
      <div class="phase-dot" style="background:var(--green)" id="ph2dot"></div>
      <div class="phase-info">
        <span class="phase-label">Phase 2 · Peak NAV</span>
        <span class="phase-stat" id="ph2stat">Loading…</span>
      </div>
    </div>
  </div>

  <div class="hdr-right">
    <div class="hdr-pill"><span class="dot" id="runDot"></span><span id="runTxt">Idle</span></div>
    <div class="hdr-pill"><span id="hdrClock">--:--:--</span></div>
  </div>
</div>

<!-- ═══ PIPELINE OVERVIEW BAR ═══ -->
<div class="pipeline-bar">
  <div class="pip-phase">
    <span class="pip-label">📥 Phase 1</span>
    <div class="pip-track"><div class="pip-fill" id="pip1fill" style="background:var(--cyan);width:0%"></div></div>
    <span class="pip-pct cv" id="pip1pct">0%</span>
  </div>
  <div class="pip-arrow">›</div>
  <div class="pip-phase">
    <span class="pip-label">📈 Phase 2</span>
    <div class="pip-track"><div class="pip-fill" id="pip2fill" style="background:var(--green);width:0%"></div></div>
    <span class="pip-pct gv" id="pip2pct">0%</span>
  </div>
  <div class="pip-stats">
    <div class="pip-stat"><span class="pip-stat-v cv" id="pipNavRec">—</span><span class="pip-stat-l">NAV Records</span></div>
    <div class="pip-stat"><span class="pip-stat-v gv" id="pipPeakDone">—</span><span class="pip-stat-l">Peaks Done</span></div>
    <div class="pip-stat"><span class="pip-stat-v av" id="pipErrors">—</span><span class="pip-stat-l">Total Errors</span></div>
  </div>
</div>

<!-- ═══ BODY ═══ -->
<div class="body">

<!-- ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
     PHASE 1 — NAV HISTORY DOWNLOAD
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ -->
<div class="phase-panel active" id="panel1">

  <!-- SIDEBAR -->
  <div class="sidebar">

    <!-- Stats -->
    <div class="sb-section">
      <div class="sb-title">📊 NAV Download Stats</div>
      <div class="stat-grid">
        <div class="stat-cell wide">
          <div class="sc-label">Total Funds</div>
          <div class="sc-value cv" id="p1Total">—</div>
          <div class="sc-sub">active schemes</div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Done</div>
          <div class="sc-value gv" id="p1Done">—</div>
          <div class="sc-bar"><div class="sc-bar-f" id="p1DoneBar" style="background:var(--green);width:0%"></div></div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Needs Update</div>
          <div class="sc-value av" id="p1Needs">—</div>
          <div class="sc-sub">stale</div>
        </div>
        <div class="stat-cell wide">
          <div class="sc-label">NAV Records in DB</div>
          <div class="sc-value pv" id="p1NavRec">—</div>
          <div class="sc-sub" id="p1NavRange">—</div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Working</div>
          <div class="sc-value cv" id="p1Working">0</div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Pending</div>
          <div class="sc-value mv" id="p1Pending">0</div>
        </div>
        <div class="stat-cell wide">
          <div class="sc-label">Errors</div>
          <div class="sc-value rv" id="p1Errors">0</div>
        </div>
      </div>
    </div>

    <!-- Controls -->
    <div class="sb-section">
      <div class="sb-title">⚙ Controls</div>
      <div class="ctrl-section">
        <div class="timers">
          <div class="timer-box"><div class="timer-lbl">Total</div><div class="timer-val" id="p1TTotal">00:00:00</div></div>
          <div class="timer-box"><div class="timer-lbl">Session</div><div class="timer-val" id="p1TSess">00:00:00</div></div>
        </div>
        <div class="meta-row">
          <div class="meta-box"><span class="meta-l">ETA</span><span class="meta-v" id="p1Eta">—</span></div>
          <div class="meta-box"><span class="meta-l">Speed</span><span class="meta-v" id="p1Speed">—</span></div>
        </div>
        <div class="worker-row">
          <span class="wlbl">⚡ Workers</span>
          <button class="wbtn" onclick="p1ChgW(-1)">−</button>
          <span class="wval" id="p1WVal">2</span>
          <button class="wbtn" onclick="p1ChgW(1)">+</button>
        </div>
        <div class="brow">
          <button class="btn btn-run" id="p1BtnStart" onclick="p1Start()">▶ Start Download</button>
          <div class="bpair">
            <button class="btn btn-warn" id="p1BtnPause" onclick="p1Pause()" disabled>⏸ Pause</button>
            <button class="btn btn-ghost" onclick="p1Retry()">↺ Retry</button>
          </div>
          <div class="bpair">
            <button class="btn btn-ghost" onclick="p1Export()">📥 CSV</button>
            <button class="btn btn-danger" onclick="showMod('p1mod')">🗑 Clear</button>
          </div>
        </div>
        <div class="last-box">
          <div class="last-lbl">Last Processed</div>
          <div class="last-val" id="p1Last">—</div>
        </div>
      </div>
    </div>

    <!-- Log -->
    <div class="log-wrap">
      <div class="log-head">📋 Activity Log <span class="log-clr" onclick="clrLog('p1log')">Clear</span></div>
      <div class="log-body" id="p1log">
        <div class="log-line inf">[system] Ready. Click Start Download.</div>
      </div>
    </div>

  </div><!-- /sidebar -->

  <!-- MAIN -->
  <div class="main">

    <!-- Progress -->
    <div class="card">
      <div class="card-head">
        <span class="card-title">📈 Overall Progress — NAV History</span>
        <span class="card-badge" id="p1PctBadge">0%</span>
      </div>
      <div class="card-body">
        <div class="circ-wrap">
          <svg class="circ-svg" width="100" height="100" viewBox="0 0 100 100">
            <defs>
              <linearGradient id="cGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#00d4ff"/>
                <stop offset="100%" stop-color="#b36aff"/>
              </linearGradient>
            </defs>
            <circle class="circ-track" cx="50" cy="50" r="42"/>
            <circle class="circ-fill" id="p1CircFill" cx="50" cy="50" r="42" stroke-dasharray="263.9" stroke-dashoffset="263.9"/>
            <text class="circ-pct" x="50" y="47" id="p1CircPct">0%</text>
            <text class="circ-sub" x="50" y="59" id="p1CircSub">ready</text>
          </svg>
          <div class="circ-stats">
            <div class="circ-row"><span class="circ-dot" style="background:var(--cyan)"></span><span class="circ-lbl">Total</span><span class="circ-val cv" id="p1CtTotal">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--green)"></span><span class="circ-lbl">Done</span><span class="circ-val gv" id="p1CtDone">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--bg4);border:1px solid var(--border2)"></span><span class="circ-lbl">Pending</span><span class="circ-val mv" id="p1CtPend">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--red)"></span><span class="circ-lbl">Errors</span><span class="circ-val rv" id="p1CtErr">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--purple)"></span><span class="circ-lbl">NAV Records</span><span class="circ-val pv" id="p1CtNav">—</span></div>
          </div>
        </div>
        <div class="seg-bar">
          <div class="seg-d" id="p1SegD" style="flex:0"></div>
          <div class="seg-w" id="p1SegW" style="flex:0"></div>
          <div class="seg-e" id="p1SegE" style="flex:0"></div>
          <div class="seg-p" id="p1SegP" style="flex:1"></div>
        </div>
      </div>
    </div>

    <!-- Fund Queue -->
    <div class="card queue-card">
      <div class="card-head"><span class="card-title">📋 Fund Queue</span></div>
      <div class="qtabs">
        <div class="qtab active" id="qt1p" onclick="p1Tab('pending')">⏳ Pending <span class="qbdg" id="qb1p">0</span></div>
        <div class="qtab" id="qt1w" onclick="p1Tab('working')">⚡ Working <span class="qbdg" id="qb1w">0</span></div>
        <div class="qtab t-r" id="qt1e" onclick="p1Tab('errors')">⚠ Errors <span class="qbdg" id="qb1e">0</span></div>
        <div class="qtab t-g" id="qt1d" onclick="p1Tab('done')">✅ Done <span class="qbdg" id="qb1d">0</span></div>
      </div>
      <div class="sbar">
        <span style="color:var(--muted);font-size:12px;flex-shrink:0">⌕</span>
        <input class="sin" id="p1Search" placeholder="Search scheme name or code…" oninput="p1RenderTable()">
        <span class="rowc" id="p1RowC"></span>
      </div>
      <div class="tbl-wrap">
        <table class="dtbl">
          <colgroup><col style="width:32px"><col><col style="width:68px"><col style="width:86px"><col style="width:76px"><col style="width:56px"><col style="width:46px"></colgroup>
          <thead><tr><th>#</th><th>Scheme Name</th><th>Code</th><th>From</th><th>Status</th><th>Records</th><th>Retry</th></tr></thead>
          <tbody id="p1tbody"><tr><td colspan="7" class="nodata">Click Start Download to begin.</td></tr></tbody>
        </table>
      </div>
    </div>

  </div><!-- /main -->
</div><!-- /panel1 -->


<!-- ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
     PHASE 2 — PEAK NAV TRACKER
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░ -->
<div class="phase-panel" id="panel2">

  <!-- SIDEBAR -->
  <div class="sidebar">

    <!-- Stats -->
    <div class="sb-section">
      <div class="sb-title">📊 Peak NAV Stats</div>
      <div class="stat-grid">
        <div class="stat-cell wide">
          <div class="sc-label">Total Schemes</div>
          <div class="sc-value gv" id="p2Total">—</div>
          <div class="sc-sub">active funds</div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Done Today</div>
          <div class="sc-value gv" id="p2UpToDate">—</div>
          <div class="sc-bar"><div class="sc-bar-f" id="p2DoneBar" style="background:var(--green);width:0%"></div></div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Needs Update</div>
          <div class="sc-value av" id="p2NeedsUpd">—</div>
          <div class="sc-sub">stale</div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Pending</div>
          <div class="sc-value mv" id="p2Pending">—</div>
        </div>
        <div class="stat-cell">
          <div class="sc-label">Working</div>
          <div class="sc-value cv" id="p2Working">—</div>
        </div>
        <div class="stat-cell wide">
          <div class="sc-label">Errors</div>
          <div class="sc-value rv" id="p2Errors">—</div>
        </div>
      </div>
    </div>

    <!-- Controls -->
    <div class="sb-section">
      <div class="sb-title">⚙ Controls</div>
      <div class="ctrl-section">
        <div class="timers">
          <div class="timer-box"><div class="timer-lbl">Total</div><div class="timer-val" id="p2TTotal">00:00:00</div></div>
          <div class="timer-box"><div class="timer-lbl">Session</div><div class="timer-val" id="p2TSess">00:00:00</div></div>
        </div>
        <div class="meta-row">
          <div class="meta-box"><span class="meta-l">Oldest</span><span class="meta-v" style="font-size:10px" id="p2Oldest">—</span></div>
          <div class="meta-box"><span class="meta-l">Latest</span><span class="meta-v" style="font-size:10px" id="p2Latest">—</span></div>
        </div>
        <div class="worker-row">
          <span class="wlbl">⚡ Parallel</span>
          <button class="wbtn" onclick="p2ChgPar(-1)">−</button>
          <span class="wval" id="p2ParVal">8</span>
          <button class="wbtn" onclick="p2ChgPar(1)">+</button>
        </div>
        <div class="brow">
          <button class="btn btn-green" id="p2BtnRun" onclick="p2RunProc()">▶ Run Processor</button>
          <button class="btn btn-stop" id="p2BtnStop" onclick="p2StopProc()" style="display:none">⏹ Stop</button>
          <div class="bpair">
            <button class="btn btn-ghost" onclick="p2Export('completed')">📥 Done CSV</button>
            <button class="btn btn-ghost" onclick="p2Export('all')">📥 All CSV</button>
          </div>
          <div class="bpair">
            <button class="btn btn-warn" id="p2BtnRetry" onclick="p2RetryErrors()">↺ Retry</button>
            <button class="btn btn-danger" id="p2BtnReset" onclick="p2Reset()">⟳ Reset</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Status Breakdown -->
    <div class="sb-section">
      <div class="sb-title">📊 Status Breakdown</div>
      <div style="padding:8px 12px">
        <table class="bk-table">
          <tr>
            <td><div class="bk-lbl-c"><span class="bk-dot" style="background:var(--green)"></span>Done Today</div></td>
            <td class="bk-bar-c"><div class="bk-track"><div class="bk-fill" id="bkBarDone" style="background:var(--green);width:0%"></div></div></td>
            <td class="bk-cnt gv" id="bkDone">—</td>
          </tr>
          <tr>
            <td><div class="bk-lbl-c"><span class="bk-dot" style="background:var(--amber)"></span>Needs Update</div></td>
            <td class="bk-bar-c"><div class="bk-track"><div class="bk-fill" id="bkBarUpd" style="background:var(--amber);width:0%"></div></div></td>
            <td class="bk-cnt av" id="bkUpd">—</td>
          </tr>
          <tr>
            <td><div class="bk-lbl-c"><span class="bk-dot" style="background:var(--muted)"></span>Pending</div></td>
            <td class="bk-bar-c"><div class="bk-track"><div class="bk-fill" id="bkBarPend" style="background:var(--muted2);width:0%"></div></div></td>
            <td class="bk-cnt mv" id="bkPend">—</td>
          </tr>
          <tr>
            <td><div class="bk-lbl-c"><span class="bk-dot" style="background:var(--cyan)"></span>Working</div></td>
            <td class="bk-bar-c"><div class="bk-track"><div class="bk-fill" id="bkBarWork" style="background:var(--cyan);width:0%"></div></div></td>
            <td class="bk-cnt cv" id="bkWork">—</td>
          </tr>
          <tr>
            <td><div class="bk-lbl-c"><span class="bk-dot" style="background:var(--red)"></span>Errors</div></td>
            <td class="bk-bar-c"><div class="bk-track"><div class="bk-fill" id="bkBarErr" style="background:var(--red);width:0%"></div></div></td>
            <td class="bk-cnt rv" id="bkErr">—</td>
          </tr>
          <tr class="bk-total">
            <td colspan="2" style="color:var(--text);font-weight:800">Effective Total</td>
            <td class="bk-cnt cv" id="bkTotal">—</td>
          </tr>
        </table>
      </div>
    </div>

    <!-- Log -->
    <div class="log-wrap">
      <div class="log-head">📋 Activity Log <span class="log-clr" onclick="clrLog('p2log')">Clear</span></div>
      <div class="log-body" id="p2log">
        <div class="log-line inf">[system] Peak NAV processor ready.</div>
      </div>
    </div>

  </div><!-- /sidebar -->

  <!-- MAIN -->
  <div class="main">

    <!-- Alerts -->
    <div id="p2AlStop" class="alert-bar al-warn">
      ⚠ Processor stopped — schemes still pending!
      <button class="al-btn" onclick="p2RunProc()">▶ Restart</button>
    </div>
    <div id="p2AlErr" class="alert-bar al-err">
      <span id="p2AlErrMsg">Some errors occurred.</span>
      <button class="al-btn" onclick="p2RetryErrors()">↺ Retry</button>
    </div>
    <div id="p2AlDone" class="alert-bar al-done">
      ✅ All schemes up to date!
      <button class="al-btn" onclick="hideAlert('p2AlDone')">✕</button>
    </div>

    <!-- Progress -->
    <div class="card">
      <div class="card-head">
        <span class="card-title">📈 Overall Progress — Peak NAV</span>
        <span class="card-badge" style="color:var(--green)" id="p2PctBadge">0%</span>
      </div>
      <div class="card-body">
        <div class="circ-wrap">
          <svg class="circ-svg" width="100" height="100" viewBox="0 0 100 100">
            <defs>
              <linearGradient id="cGrad2" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#00e676"/>
                <stop offset="100%" stop-color="#00bcd4"/>
              </linearGradient>
            </defs>
            <circle class="circ-track" cx="50" cy="50" r="42"/>
            <circle class="circ-fill" id="p2CircFill" cx="50" cy="50" r="42" stroke="url(#cGrad2)" stroke-dasharray="263.9" stroke-dashoffset="263.9"/>
            <text class="circ-pct" x="50" y="47" id="p2CircPct" fill="var(--green)">0%</text>
            <text class="circ-sub" x="50" y="59" id="p2CircSub">ready</text>
          </svg>
          <div class="circ-stats">
            <div class="circ-row"><span class="circ-dot" style="background:var(--green)"></span><span class="circ-lbl">Total Schemes</span><span class="circ-val gv" id="p2CtTotal">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--green)"></span><span class="circ-lbl">Done Today</span><span class="circ-val gv" id="p2CtDone">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--amber)"></span><span class="circ-lbl">Needs Update</span><span class="circ-val av" id="p2CtUpd">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--bg4);border:1px solid var(--border2)"></span><span class="circ-lbl">Pending</span><span class="circ-val mv" id="p2CtPend">—</span></div>
            <div class="circ-row"><span class="circ-dot" style="background:var(--red)"></span><span class="circ-lbl">Errors</span><span class="circ-val rv" id="p2CtErr">—</span></div>
          </div>
        </div>
        <div style="margin-top:12px">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:5px">
            <span>Oldest: <b id="p2OldestDate" style="color:var(--text)">—</b></span>
            <span>Latest: <b id="p2LatestDate" style="color:var(--text)">—</b></span>
          </div>
          <div style="background:var(--bg4);border-radius:3px;height:5px;overflow:hidden">
            <div id="p2Bar" style="background:linear-gradient(90deg,var(--green),var(--teal));height:100%;border-radius:3px;transition:width .8s ease;width:0%"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Data Table -->
    <div class="card queue-card">
      <div class="card-head"><span class="card-title">📋 Schemes Data</span><span style="margin-left:auto;font-size:11px;color:var(--muted)" id="p2LastRefresh"></span></div>
      <div class="qtabs">
        <div class="qtab active" id="qt2p" data-tab="pending"   onclick="p2Tab('pending')">  ⏳ Pending   <span class="qbdg" id="qb2p">—</span></div>
        <div class="qtab"        id="qt2w" data-tab="working"   onclick="p2Tab('working')">  ⚡ Working   <span class="qbdg" id="qb2w">—</span></div>
        <div class="qtab t-g"   id="qt2c" data-tab="completed" onclick="p2Tab('completed')">✅ Completed <span class="qbdg" id="qb2c">—</span></div>
        <div class="qtab t-r"   id="qt2e" data-tab="errors"    onclick="p2Tab('errors')">   ⚠ Errors    <span class="qbdg" id="qb2e">—</span></div>
      </div>
      <div class="sbar" style="display:none"><!-- no search for peak nav, pagination handles it --></div>
      <div class="tbl-wrap">
        <div style="padding:6px 10px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);background:var(--bg3)">
          <span style="font-size:11px;color:var(--muted)" id="p2TblInfo">Loading…</span>
          <div style="display:flex;gap:5px">
            <button class="pg-btn" onclick="p2Export('completed')">⬇ Done CSV</button>
            <button class="pg-btn" onclick="p2Export('all')">⬇ All CSV</button>
          </div>
        </div>
        <table class="dtbl">
          <colgroup><col style="width:36px"><col style="width:72px"><col><col style="width:100px"><col style="width:92px"><col style="width:92px"><col style="width:82px"></colgroup>
          <thead><tr><th>#</th><th>Code</th><th>Scheme Name</th><th>Peak NAV (₹)</th><th>Peak Date</th><th>Checked Till</th><th>Status</th></tr></thead>
          <tbody id="p2tbody"><tr><td colspan="7" class="nodata"><span class="spin"></span> Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="pag" id="p2pag"></div>
    </div>

  </div><!-- /main -->
</div><!-- /panel2 -->

</div><!-- /body -->
</div><!-- /app -->

<!-- Modals -->
<div class="overlay" id="p1mod">
  <div class="modal">
    <h3>🗑 Clear NAV Download Queue</h3>
    <p>Queue clear ho jayegi. NAV history data safe rahega. Aage se naya download start kar sakte hain.</p>
    <div class="mbtns">
      <button class="btn btn-ghost mbtns" style="width:auto;padding:7px 14px" onclick="closeMod('p1mod')">Cancel</button>
      <button class="btn btn-danger" style="width:auto;padding:7px 14px" onclick="p1Reset()">Clear Queue</button>
    </div>
  </div>
</div>
<div class="tc" id="toastC"></div>

<!-- Error modal (Peak NAV) -->
<div class="overlay" id="p2ErrMod">
  <div class="modal" style="max-width:620px">
    <h3 style="display:flex;justify-content:space-between">⚠ Peak NAV Errors <button onclick="closeMod('p2ErrMod')" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px">✕</button></h3>
    <div id="p2ErrList" style="display:flex;flex-direction:column;gap:6px;margin-top:10px">Loading…</div>
  </div>
</div>

<script>
/* ══════════════════════════════════════════════════════
   GLOBALS
══════════════════════════════════════════════════════ */
const API1 = '/wealthdash/nav_download/nav_worker.php';
const API2 = '/wealthdash/peak_nav/api.php';
const PROC2 = '/wealthdash/peak_nav/processor.php';

const g = id => document.getElementById(id);
const num = n => (+(n||0)).toLocaleString('en-IN');
const fmtT = s => [Math.floor(s/3600),Math.floor((s%3600)/60),s%60].map(n=>String(n).padStart(2,'0')).join(':');
const fmtMs = ms => fmtT(Math.floor(ms/1000));
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

/* ── Phase Switch ── */
let activePhase = 1;
function switchPhase(p) {
  activePhase = p;
  g('phTab1').classList.toggle('active', p===1);
  g('phTab2').classList.toggle('active', p===2);
  g('panel1').classList.toggle('active', p===1);
  g('panel2').classList.toggle('active', p===2);
  // update run indicator
  updateRunDot();
}

/* ── Clock ── */
setInterval(() => {
  const n = new Date();
  g('hdrClock').textContent = n.toLocaleTimeString('en-IN',{hour12:false});
  // P1 timers
  const p1ss = P1.sessStart && P1.running && !P1.paused ? Math.floor((Date.now()-P1.sessStart)/1000) : 0;
  g('p1TTotal').textContent = fmtT(P1.totalSec + p1ss);
  if (P1.sessStart) g('p1TSess').textContent = fmtT(Math.floor((Date.now()-P1.sessStart)/1000));
  // P2 timers
  if (P2.totalStart) g('p2TTotal').textContent = fmtMs(Date.now()-P2.totalStart);
  if (P2.sessStart)  g('p2TSess').textContent  = fmtMs(Date.now()-P2.sessStart);
  p1UpdateEta();
}, 1000);

function updateRunDot() {
  const isRun = (activePhase===1 && P1.running) || (activePhase===2 && P2.isRunning);
  g('runDot').className = 'dot' + (isRun?' run':'');
  g('runTxt').textContent = isRun ? (activePhase===1?'Downloading…':'Calculating…') : 'Idle';
}

/* ══════════════════════════════════════════════════════
   ██████  PHASE 1 — NAV DOWNLOAD
══════════════════════════════════════════════════════ */
const P1 = {
  running:false, paused:false, parallel:2, workers:0,
  sessStart:null, totalSec:0, tab:'pending', stats:{}, queue:[],
  doneCount:0, queueLoaded:false
};

async function p1Api(action, extra={}) {
  const res = await fetch(API1, {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action,...extra})
  });
  if (res.status===403) throw new Error('Session expire');
  const text = await res.text();
  if (!text.trim()) throw new Error('Empty response');
  if (text.trim().startsWith('<')) throw new Error('PHP session expire — refresh page');
  return JSON.parse(text);
}

function p1UpdateEta() {
  if (!P1.sessStart || !P1.running) { g('p1Eta').textContent='—'; g('p1Speed').textContent='—'; return; }
  const elMin = (Date.now()-P1.sessStart)/60000;
  if (elMin<0.1) return;
  const spd = P1.doneCount/elMin;
  g('p1Speed').textContent = spd.toFixed(1)+'/m';
  const q = P1.stats?.queue??{};
  const rem = (q.pending||0)+(q.in_progress||0);
  g('p1Eta').textContent = spd>0&&rem>0 ? fmtT(Math.round((rem/spd)*60)) : (rem===0?'Done':'∞');
}

let p1PollErr=0;
async function p1Poll() {
  try {
    const d = await p1Api('status');
    if (!d||!d.ok) return;
    P1.stats=d; p1PollErr=0;
    p1RenderUI(d);
    if (!P1.queueLoaded && P1.queue.length===0 && (d.queue?.downloaded||0)>0 && P1.tab==='done') {
      P1.queueLoaded=true; await p1LoadQueue();
    }
  } catch(e) {
    p1PollErr++;
    if (p1PollErr===1||p1PollErr%10===0) p1Log('Poll error: '+e.message.substring(0,80),'er');
  }
}

async function p1LoadQueue() {
  try {
    const d = await p1Api('queue_list');
    if (!d.ok||!d.items) return;
    P1.queue = d.items.map(f=>({scheme_code:f.scheme_code||'',scheme_name:f.scheme_name||'',status:f.status||'done',from_date:f.from_date||null,records:f.records!=null?+f.records:null,error_msg:f.error_msg||'',retry_count:+(f.retry_count||0)}));
    P1.queueLoaded=true; p1RenderTable();
  } catch(e){}
}

function p1RenderUI(d) {
  const q=d.queue??{};
  const tot=(q.pending||0)+(q.in_progress||0)+(q.downloaded||0)+(q.errors||0);
  const done=q.downloaded||0;
  const pct=tot>0?Math.round((done/tot)*100):0;

  g('p1Total').textContent=num(d.total_funds);
  g('p1NavRec').textContent=num(d.nav_records);
  const ol=d.date_range?.oldest??'—', la=d.date_range?.latest??'—';
  g('p1NavRange').textContent=ol+' → '+la;
  g('p1Needs').textContent=num(d.funds_needing_update);
  g('p1Done').textContent=num(q.downloaded); g('p1DoneBar').style.width=pct+'%';
  g('p1Working').textContent=num(q.in_progress); g('p1Pending').textContent=num(q.pending);
  g('p1Errors').textContent=num(q.errors);

  const circ=263.9, offset=circ-((pct/100)*circ);
  g('p1CircFill').style.strokeDashoffset=offset;
  g('p1CircPct').textContent=pct+'%'; g('p1CircSub').textContent=tot>0?done+'/'+tot:'ready';
  g('p1PctBadge').textContent=pct+'%';
  g('p1CtTotal').textContent=num(d.total_funds); g('p1CtDone').textContent=num(q.downloaded);
  g('p1CtPend').textContent=num(q.pending); g('p1CtErr').textContent=num(q.errors);
  g('p1CtNav').textContent=num(d.nav_records);

  if (tot>0) {
    g('p1SegD').style.flex=String(q.downloaded||0); g('p1SegW').style.flex=String(q.in_progress||0);
    g('p1SegE').style.flex=String(q.errors||0); g('p1SegP').style.flex=String(q.pending||0);
  }
  if (d.last_done) g('p1Last').textContent=(d.last_done.scheme_name||d.last_done.scheme_code||'—')+'\n@ '+(d.last_done.updated_at||'').substring(0,16);

  P1.totalSec=d.total_elapsed_sec||0; P1.paused=!!d.paused;
  const pb=g('p1BtnPause'); pb.textContent=P1.paused?'▶ Resume':'⏸ Pause';
  pb.className=P1.paused?'btn btn-green':'btn btn-warn';

  // badges
  const sh=(q.pending||0)+(q.in_progress||0)+(q.errors||0)+(q.downloaded||0)>0;
  g('qb1p').textContent=sh?(q.pending??0):P1.queue.filter(f=>f.status==='pending').length;
  g('qb1w').textContent=sh?(q.in_progress??0):P1.queue.filter(f=>f.status==='in_progress').length;
  g('qb1e').textContent=sh?(q.errors??0):P1.queue.filter(f=>f.status==='error').length;
  g('qb1d').textContent=sh?(q.downloaded??0):P1.queue.filter(f=>f.status==='done').length;

  // pipeline bar
  g('pip1fill').style.width=pct+'%'; g('pip1pct').textContent=pct+'%';
  g('pipNavRec').textContent=num(d.nav_records);

  // phase header stat
  g('ph1stat').textContent=num(done)+'/'+num(tot)+' ('+pct+'%)';
  updateRunDot();
}

function p1RenderTable() {
  const tbody=g('p1tbody'), q=(g('p1Search').value||'').toLowerCase();
  const stMap={pending:'pending',working:'in_progress',errors:'error',done:'done'};
  const want=stMap[P1.tab]||P1.tab;
  const rows=P1.queue.filter(f=>{
    if(f.status!==want)return false;
    if(q&&!f.scheme_name.toLowerCase().includes(q)&&!f.scheme_code.toLowerCase().includes(q))return false;
    return true;
  });
  g('p1RowC').textContent=rows.length?rows.length+' fund'+( rows.length!==1?'s':''):'';
  if(!rows.length){tbody.innerHTML=`<tr><td colspan="7" class="nodata">${P1.queue.length===0?'No funds in queue.':'No results.'}</td></tr>`;return;}
  const cls={pending:'bp',in_progress:'bw',done:'bd',error:'be'};
  const lbl={pending:'⏳ Pending',in_progress:'⚡ Working',done:'✅ Done',error:'⚠ Error'};
  tbody.innerHTML=rows.map((f,i)=>`<tr>
    <td style="color:var(--muted);font-family:var(--mono);font-size:10px">${i+1}</td>
    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:0" title="${esc(f.scheme_name)}">${esc(f.scheme_name)}</td>
    <td><span class="fcode">${esc(f.scheme_code)}</span></td>
    <td class="date-val">${f.from_date||'—'}</td>
    <td><span class="badge ${cls[f.status]||'bp'}">${lbl[f.status]||f.status}</span></td>
    <td style="font-family:var(--mono);font-size:11px;color:var(--cyan)">${f.records!=null?num(f.records):'—'}</td>
    <td style="font-family:var(--mono);font-size:10px;color:${f.retry_count>0?'var(--amber)':'var(--muted)'}">${f.retry_count||0}</td>
  </tr>`).join('');
}

async function p1ProcOne() {
  try {
    const d=await p1Api('process_next'); if(!d.ok)return 'err';
    if(d.status==='idle')return 'idle'; if(d.status==='paused')return 'paused';
    if(d.status==='processed'){
      p1Log('✓ '+(d.name||d.scheme)+' — '+num(d.inserted)+' records','ok');
      const idx=P1.queue.findIndex(f=>String(f.scheme_code)===String(d.scheme));
      if(idx>=0){P1.queue[idx].status='done';P1.queue[idx].records=d.inserted;}
      P1.doneCount++; p1RenderTable();
    } else if(d.status==='error'){
      p1Log('✗ '+d.scheme+': '+d.error,'er');
      const idx=P1.queue.findIndex(f=>String(f.scheme_code)===String(d.scheme));
      if(idx>=0){P1.queue[idx].status='error';P1.queue[idx].retry_count=(P1.queue[idx].retry_count||0)+1;}
      p1RenderTable();
    }
    return d.status;
  } catch(e){p1Log('Worker: '+e.message,'er');return 'err';}
}

async function p1WLoop(){
  while(P1.running&&!P1.paused){const r=await p1ProcOne();if(r==='idle'||r==='paused')break;await new Promise(r2=>setTimeout(r2,400));}
  P1.workers--;
  if(P1.workers===0&&P1.running&&!P1.paused){const q2=P1.stats?.queue??{};if((q2.pending||0)===0){P1.running=false;p1SetRun(false);p1Log('✓ All downloads complete!','ok');toast('Download complete!',true);p1Tab('done');await p1Poll();}}
}

function p1SetRun(r){
  const s=g('p1BtnStart'),p=g('p1BtnPause');
  if(r){s.disabled=true;p.disabled=false;}else{s.disabled=false;p.disabled=!P1.paused;}
  updateRunDot();
}

async function p1Start(){
  g('p1BtnStart').disabled=true; P1.queueLoaded=false; P1.doneCount=0;
  p1Log('Starting download queue…','inf');
  try {
    const d=await p1Api('start');
    if(!d.ok){toast(d.message||'Start failed',false);p1Log('✗ '+(d.message||''),'er');g('p1BtnStart').disabled=false;return;}
    if(!d.queued){toast(d.message||'All up-to-date!',true);p1Log('✓ '+(d.message||''),'ok');g('p1BtnStart').disabled=false;return;}
    toast(d.queued+' funds queued',true); p1Log(d.queued+' funds queued — starting '+P1.parallel+' workers','inf');
    P1.queue=Array.from({length:d.queued},(_,i)=>({scheme_code:'…',scheme_name:'Fund #'+(i+1),status:'pending',from_date:null,records:null,retry_count:0}));
    p1RenderTable(); P1.sessStart=Date.now();P1.running=true;P1.paused=false;
    g('p1TSess').textContent='00:00:00'; p1SetRun(true);
    P1.workers=P1.parallel; for(let i=0;i<P1.parallel;i++)p1WLoop(); await p1Poll();
  } catch(e){p1Log('✗ '+e.message,'er');toast('Start failed',false);g('p1BtnStart').disabled=false;}
}

async function p1Pause(){
  try{const d=await p1Api('pause');if(!d.ok)return;P1.paused=!!d.paused;
  if(P1.paused){P1.running=false;p1SetRun(false);p1Log('⏸ Paused','wn');toast('Paused',true);}
  else{P1.running=true;p1SetRun(true);p1Log('▶ Resumed','inf');P1.workers=P1.parallel;for(let i=0;i<P1.parallel;i++)p1WLoop();}}catch(e){}
}

async function p1Retry(){
  try{const d=await p1Api('retry_errors');toast(d.message,d.ok);p1Log((d.ok?'↺':'✗')+' '+d.message,d.ok?'inf':'er');
  if(d.ok&&d.retried>0&&!P1.running){P1.queue.forEach(f=>{if(f.status==='error')f.status='pending';});p1RenderTable();P1.running=true;P1.paused=false;p1SetRun(true);P1.workers=P1.parallel;for(let i=0;i<P1.parallel;i++)p1WLoop();}
  await p1Poll();}catch(e){}
}

function p1Export(){window.open(API1+'?action=export_csv','_blank');p1Log('📥 CSV export started','inf');}

async function p1Reset(){
  closeMod('p1mod');
  try{const d=await p1Api('reset',{confirm:true});toast(d.message,d.ok);p1Log((d.ok?'✓':'✗')+' '+d.message,d.ok?'ok':'er');P1.running=false;P1.queue=[];P1.doneCount=0;p1RenderTable();p1SetRun(false);await p1Poll();}catch(e){}
}

function p1Tab(tab){P1.tab=tab;['pending','working','errors','done'].forEach(t=>{g('qt1'+t[0]).classList.toggle('active',t===tab);});
  if(tab==='done'&&!P1.queueLoaded&&P1.queue.length===0)p1LoadQueue();p1RenderTable();}

function p1ChgW(d){P1.parallel=Math.max(1,Math.min(16,P1.parallel+d));g('p1WVal').textContent=P1.parallel;}

function p1Log(msg,type=''){const f=g('p1log'),t=new Date().toLocaleTimeString('en-IN',{hour12:false}),el=document.createElement('div');el.className='log-line '+type;el.textContent='['+t+'] '+msg;f.appendChild(el);f.scrollTop=f.scrollHeight;while(f.children.length>300)f.removeChild(f.firstChild);}

/* ══════════════════════════════════════════════════════
   ██████  PHASE 2 — PEAK NAV
══════════════════════════════════════════════════════ */
const P2 = {
  isRunning:false, userStopped:false, totalStart:null, sessStart:null,
  activeTab:'pending', activePage:1, prevDone:0, stuckSecs:0,
  activeXhr:null, pollTimer:null
};

// Init P2 from localStorage
P2.totalStart = parseInt(localStorage.getItem('pkTotal')||'0')||null;
P2.userStopped = localStorage.getItem('pkStopped')==='1';
P2.parallel = parseInt(localStorage.getItem('pkPar')||'8');
g('p2ParVal').value=P2.parallel; updateP2ParHint();

async function p2Api(action, method='GET') {
  const url = API2+'?action='+action+'&_='+Date.now();
  const res = await fetch(url,{method,cache:'no-store'});
  return res.json();
}

function updateP2ParHint(){
  const v=P2.parallel;
  const clr=v<=5?'var(--green)':v<=10?'var(--amber)':'var(--red)';
  g('p2ParVal').textContent=v;
  g('p2ParVal').style.color=clr;
}

async function p2FetchSummary() {
  try {
    const d = await p2Api('summary');
    if (d.error) return;
    const c=d.counts;
    const tot=parseInt(c.total)||0, done=parseInt(c.up_to_date||0), upd=parseInt(c.needs_update||0);
    const pend=parseInt(c.pending||0), work=parseInt(c.working||0), err=parseInt(c.errors||0);
    const ef=Math.max(d.effective_total||1,1);

    g('p2Total').textContent=num(c.total); g('p2UpToDate').textContent=num(done); g('p2DoneBar').style.width=d.pct+'%';
    g('p2NeedsUpd').textContent=num(upd); g('p2Pending').textContent=num(pend);
    g('p2Working').textContent=num(work); g('p2Errors').textContent=num(err);

    const circ=263.9,offset=circ-((d.pct/100)*circ);
    g('p2CircFill').style.strokeDashoffset=offset; g('p2CircPct').textContent=d.pct+'%';
    g('p2CircSub').textContent=ef>0?(done+'/'+ef):'ready';
    g('p2PctBadge').textContent=d.pct+'%';
    g('p2CtTotal').textContent=num(c.total); g('p2CtDone').textContent=num(done);
    g('p2CtUpd').textContent=num(upd); g('p2CtPend').textContent=num(pend); g('p2CtErr').textContent=num(err);
    g('p2Bar').style.width=d.pct+'%';
    g('p2OldestDate').textContent=c.oldest_update||'—'; g('p2LatestDate').textContent=c.latest_update||'—';
    g('p2Oldest').textContent=(c.oldest_update||'—').substring(0,10);
    g('p2Latest').textContent=(c.latest_update||'—').substring(0,10);

    // breakdown
    const bd=(id,v)=>{const e=g('bk'+id);if(e)e.textContent=num(v);};
    const bb=(id,v)=>{const e=g('bkBar'+id);if(e)e.style.width=Math.min(100,Math.round((v/ef)*100))+'%';};
    bd('Done',done);bb('Done',done);bd('Upd',upd);bb('Upd',upd);
    bd('Pend',pend);bb('Pend',pend);bd('Work',work);bb('Work',work);bd('Err',err);bb('Err',err);
    const bkt=g('bkTotal');if(bkt)bkt.textContent=num(ef);

    // tab badges
    g('qb2p').textContent=num(pend); g('qb2w').textContent=num(work);
    g('qb2c').textContent=num(parseInt(c.completed||0)); g('qb2e').textContent=num(err);

    // pipeline bar
    g('pip2fill').style.width=d.pct+'%'; g('pip2pct').textContent=d.pct+'%';
    g('pipPeakDone').textContent=num(done); g('pipErrors').textContent=num((P1.stats?.queue?.errors||0)+err);

    // phase header
    const n=new Date(); g('p2LastRefresh').textContent='↻ '+String(n.getHours()).padStart(2,'0')+':'+String(n.getMinutes()).padStart(2,'0')+':'+String(n.getSeconds()).padStart(2,'0');
    g('ph2stat').textContent=num(done)+'/'+num(ef)+' ('+d.pct+'%)';
    updateRunDot();
  } catch(e){}
}

async function p2FetchTable(loader=true) {
  if(loader)g('p2tbody').innerHTML='<tr><td colspan="7" class="nodata"><span class="spin"></span> Loading…</td></tr>';
  try {
    const url=API2+'?action=table&tab='+P2.activeTab+'&page='+P2.activePage+'&_='+Date.now();
    const d=await fetch(url,{cache:'no-store'}).then(r=>r.json());
    if(d.rows!==undefined)p2RenderTable(d.rows,d.page,d.pages,d.total_rows);
  }catch(e){if(loader)g('p2tbody').innerHTML='<tr><td colspan="7" class="nodata">Error loading data</td></tr>';}
}

function p2RenderTable(rows,page,pages,total) {
  const off=(page-1)*50;
  g('p2TblInfo').textContent='Showing '+(off+1)+'–'+(off+rows.length)+' of '+total+' records';
  if(!rows.length){g('p2tbody').innerHTML='<tr><td colspan="7" class="nodata">No records</td></tr>';g('p2pag').innerHTML='';return;}
  const today=new Date().toISOString().slice(0,10);
  g('p2tbody').innerHTML=rows.map((r,i)=>{
    const nav=r.highest_nav?'₹'+parseFloat(r.highest_nav).toFixed(4):'—';
    const nm=esc(r.scheme_name||'—'); const sn=nm.length>44?nm.slice(0,44)+'…':nm;
    const lpd=r.last_processed_date||'—';
    const stale=(r.status==='completed'&&lpd!=='—'&&lpd<today)?'<span class="stale-tag">outdated</span>':'';
    const statCell=r.status==='error'
      ?`<td style="color:var(--red);font-size:10px" title="${esc(r.error_message||'')}">${esc(r.error_message||'error').substring(0,30)}</td>`
      :`<td><span class="badge b${r.status[0]}">${r.status}</span></td>`;
    return `<tr>
      <td style="color:var(--muted);font-family:var(--mono);font-size:10px">${off+i+1}</td>
      <td><span class="fcode">${esc(r.scheme_code)}</span></td>
      <td title="${nm}">${sn}</td>
      <td class="nav-val">${nav}</td>
      <td class="date-val">${r.highest_nav_date||'—'}</td>
      <td class="date-val">${lpd}${stale}</td>
      ${statCell}
    </tr>`;
  }).join('');
  p2RenderPag(page,pages);
}

function p2RenderPag(page,pages){
  if(pages<=1){g('p2pag').innerHTML='';return;}
  let h=`<button class="pg-btn" onclick="p2GoPage(${page-1})" ${page===1?'disabled':''}>◀</button>`;
  let s=Math.max(1,page-3),e=Math.min(pages,page+3);
  if(s>1)h+=`<button class="pg-btn" onclick="p2GoPage(1)">1</button>${s>2?'<span style="color:var(--muted);padding:0 4px">…</span>':''}`;
  for(let p=s;p<=e;p++)h+=`<button class="pg-btn ${p===page?'active':''}" onclick="p2GoPage(${p})">${p}</button>`;
  if(e<pages)h+=`${e<pages-1?'<span style="color:var(--muted);padding:0 4px">…</span>':''}<button class="pg-btn" onclick="p2GoPage(${pages})">${pages}</button>`;
  h+=`<button class="pg-btn" onclick="p2GoPage(${page+1})" ${page===pages?'disabled':''}>▶</button>`;
  h+=`<span class="pg-info">Page ${page} / ${pages}</span>`;
  g('p2pag').innerHTML=h;
}

function p2GoPage(p){P2.activePage=p;p2FetchTable();}

function p2Tab(tab){
  P2.activeTab=tab;P2.activePage=1;
  document.querySelectorAll('#panel2 .qtab').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));
  p2FetchTable();
}

function p2RunProc(){
  P2.userStopped=false; localStorage.removeItem('pkStopped');
  if(P2.pollTimer){clearInterval(P2.pollTimer);P2.pollTimer=null;}
  if(P2.activeXhr){P2.activeXhr.abort();P2.activeXhr=null;}
  if(!P2.totalStart){P2.totalStart=Date.now();localStorage.setItem('pkTotal',P2.totalStart);}
  P2.sessStart=Date.now(); P2.prevDone=0; P2.stuckSecs=0;
  ['p2AlStop','p2AlDone','p2AlErr'].forEach(id=>g(id).classList.remove('show'));
  g('p2BtnRun').textContent='⏸ Running…'; g('p2BtnRun').disabled=true;
  g('p2BtnStop').style.display='inline-flex'; g('p2BtnStop').disabled=false;
  P2.isRunning=true; updateRunDot();
  fetch(API2+'?action=clear_stop',{method:'POST'}).catch(()=>{});
  const url=PROC2+'?t='+Date.now()+'&parallel='+P2.parallel;
  const xhr=new XMLHttpRequest(); P2.activeXhr=xhr;
  xhr.open('GET',url,true); xhr.timeout=150000;
  xhr.onload=()=>{P2.activeXhr=null;p2OnFinished(xhr.responseText);};
  xhr.onerror=()=>{P2.activeXhr=null;if(!P2.userStopped)setTimeout(()=>{if(!P2.isRunning&&!P2.userStopped)p2RunProc();},3000);};
  xhr.ontimeout=()=>{P2.activeXhr=null;if(!P2.userStopped)p2OnFinished('');};
  xhr.send(); P2.pollTimer=setInterval(p2CheckProg,3000);
  p2Log('▶ Processor started ('+P2.parallel+' parallel)','inf');
}

async function p2StopProc(){
  if(!confirm('⏹ Stop processor?'))return;
  P2.userStopped=true; localStorage.setItem('pkStopped','1');
  P2.isRunning=false; if(P2.pollTimer){clearInterval(P2.pollTimer);P2.pollTimer=null;}
  if(P2.activeXhr){P2.activeXhr.abort();P2.activeXhr=null;}
  g('p2BtnRun').textContent='▶ Run Processor'; g('p2BtnRun').disabled=false;
  g('p2BtnStop').style.display='none'; updateRunDot();
  try{const r=await fetch(API2+'?action=stop',{method:'POST'}).then(r=>r.json());toast(r.message||'Stopped',true);}catch(e){}
  g('p2AlStop').classList.add('show'); p2FetchSummary();
  p2Log('⏸ Processor stopped','wn');
}

async function p2OnFinished(resp){
  P2.isRunning=false; if(P2.pollTimer){clearInterval(P2.pollTimer);P2.pollTimer=null;}
  try{
    const d=await p2Api('summary'); const c=d.counts;
    const rem=parseInt(d.not_done||0), errs=parseInt(c.errors||0);
    if(rem===0){g('p2BtnRun').textContent='▶ Run Processor';g('p2BtnRun').disabled=false;g('p2BtnStop').style.display='none';g('p2AlDone').classList.add('show');localStorage.removeItem('pkTotal');P2.totalStart=null;p2Log('✅ All complete!','ok');toast('Peak NAV complete!',true);}
    else if(!P2.userStopped){g('p2BtnRun').textContent='⏸ Running…';g('p2BtnRun').disabled=true;setTimeout(()=>{if(!P2.isRunning&&!P2.userStopped)p2RunProc();},1500);}
    else{g('p2BtnRun').textContent='▶ Run Processor';g('p2BtnRun').disabled=false;g('p2BtnStop').style.display='none';g('p2AlStop').classList.add('show');}
    if(errs>0){g('p2AlErrMsg').textContent=num(errs)+' schemes failed.';g('p2AlErr').classList.add('show');}
  }catch(e){g('p2BtnRun').textContent='▶ Run Processor';g('p2BtnRun').disabled=false;g('p2BtnStop').style.display='none';g('p2AlStop').classList.add('show');}
  updateRunDot(); p2FetchSummary(); p2FetchTable(false);
}

async function p2CheckProg(){
  try{
    const d=await p2Api('summary'); const c=d.counts;
    const nowDone=parseInt(c.completed||0), rem=parseInt(d.not_done||0);
    if(nowDone>P2.prevDone){P2.prevDone=nowDone;P2.stuckSecs=0;}
    else if(P2.isRunning){P2.stuckSecs+=3;if(P2.stuckSecs>=30&&rem>0&&!P2.userStopped){P2.stuckSecs=0;P2.isRunning=false;if(P2.pollTimer){clearInterval(P2.pollTimer);P2.pollTimer=null;}setTimeout(()=>{if(!P2.userStopped)p2RunProc();},1000);return;}}
    if(rem===0){clearInterval(P2.pollTimer);P2.pollTimer=null;P2.isRunning=false;g('p2BtnRun').textContent='▶ Run Processor';g('p2BtnRun').disabled=false;g('p2BtnStop').style.display='none';g('p2AlDone').classList.add('show');localStorage.removeItem('pkTotal');P2.totalStart=null;updateRunDot();p2FetchSummary();p2FetchTable(false);}
  }catch(e){}
}

async function p2RetryErrors(){
  g('p2BtnRetry').disabled=true;
  await fetch(API2+'?action=retry_errors',{method:'POST'});
  g('p2AlErr').classList.remove('show'); g('p2BtnRetry').disabled=false;
  p2Log('↺ Retrying errors','wn'); p2FetchSummary(); p2FetchTable();
}

async function p2Reset(){
  P2.userStopped=true;
  if(!confirm('Full reset: clears ALL peak NAV data?'))return;
  g('p2BtnReset').disabled=true; P2.isRunning=false;
  if(P2.pollTimer){clearInterval(P2.pollTimer);P2.pollTimer=null;}
  await fetch(API2+'?action=reset',{method:'POST'});
  g('p2BtnReset').disabled=false; g('p2BtnRun').textContent='▶ Run Processor';
  P2.totalStart=null;P2.sessStart=null;
  localStorage.removeItem('pkTotal');localStorage.removeItem('pkStopped');
  g('p2TTotal').textContent='00:00:00';g('p2TSess').textContent='00:00:00';
  ['p2AlStop','p2AlErr','p2AlDone'].forEach(id=>g(id).classList.remove('show'));
  P2.activePage=1; p2Log('⟳ Full reset done','wn'); toast('Reset complete',true);
  p2FetchSummary(); p2FetchTable();
}

function p2Export(f){window.location.href=API2+'?action=export&filter='+f;p2Log('📥 Exporting '+f,'inf');}

function p2ChgPar(d){P2.parallel=Math.max(1,Math.min(50,P2.parallel+d));localStorage.setItem('pkPar',P2.parallel);updateP2ParHint();}

function p2Log(msg,type=''){const f=g('p2log'),t=new Date().toLocaleTimeString('en-IN',{hour12:false}),el=document.createElement('div');el.className='log-line '+type;el.textContent='['+t+'] '+msg;f.appendChild(el);f.scrollTop=f.scrollHeight;while(f.children.length>300)f.removeChild(f.firstChild);}

function hideAlert(id){g(id).classList.remove('show');}

/* ══════════════════════════════════════════════════════
   SHARED UTILS
══════════════════════════════════════════════════════ */
function clrLog(id){g(id).innerHTML='';}
function showMod(id){g(id).classList.add('open');}
function closeMod(id){g(id).classList.remove('open');}
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open');}));

function toast(msg,ok=true){
  const t=document.createElement('div'); t.className='toast '+(ok?'tok':'ter');
  t.innerHTML=`<span>${ok?'✅':'❌'}</span><span>${esc(String(msg))}</span>`;
  g('toastC').appendChild(t);setTimeout(()=>t.remove(),4000);
}

/* ══════════════════════════════════════════════════════
   INIT
══════════════════════════════════════════════════════ */
p1Poll();
p2FetchSummary();
p2FetchTable();

// Auto-start check
const urlParams = new URLSearchParams(window.location.search);
const initPhase = urlParams.get('phase');
if (initPhase === '2') switchPhase(2);
if (urlParams.get('autostart')==='2' && !P2.userStopped) {
  switchPhase(2); setTimeout(p2RunProc, 800);
}

setInterval(p1Poll, 10000);
setInterval(p2FetchSummary, 5000);
setInterval(()=>p2FetchTable(false), 8000);
</script>
</body>
</html>
