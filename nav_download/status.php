<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WealthDash — NAV Download Status</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f0f2f8;color:#141c2e;padding:20px}
.card{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:20px;margin-bottom:16px}
h1{font-size:18px;font-weight:800;margin-bottom:4px}
.sub{font-size:12px;color:#5a6882;margin-bottom:16px}
.stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.stat{background:#f8f9fc;border:1.5px solid #e2e6f0;border-radius:10px;padding:12px 16px;text-align:center;flex:1;min-width:100px}
.stat-num{font-size:24px;font-weight:800}
.stat-lbl{font-size:10px;color:#5a6882;font-weight:600}
.bar-wrap{height:14px;background:#e2e6f0;border-radius:7px;overflow:hidden;margin-bottom:8px}
.bar-fill{height:100%;background:linear-gradient(90deg,#4f46e5,#7c3aed);border-radius:7px;transition:width .5s}
.pct{font-size:13px;font-weight:800;color:#4f46e5;text-align:right;margin-bottom:12px}
.btn{padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn-green{background:#0d9f57;color:#fff}
.btn-red{background:#dc2626;color:#fff}
.btn-gray{background:#e2e6f0;color:#141c2e}
.actions{display:flex;gap:8px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{text-align:left;padding:6px 8px;border-bottom:1.5px solid #e2e6f0;font-weight:700;color:#5a6882;font-size:10px;text-transform:uppercase}
td{padding:6px 8px;border-bottom:1px solid #e2e6f0}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700}
.done{background:#edfbf2;color:#0d9f57}
.failed{background:#fff1f2;color:#dc2626}
.pending{background:#f8f9fc;color:#5a6882}
</style>
</head>
<body>

<div class="card">
  <h1>📥 WealthDash — NAV Download Status</h1>
  <div class="sub" id="lastUpdated">Loading...</div>

  <div class="stats">
    <div class="stat"><div class="stat-num" id="statTotal" style="color:#4f46e5">—</div><div class="stat-lbl">Total Funds</div></div>
    <div class="stat"><div class="stat-num" id="statDone" style="color:#0d9f57">—</div><div class="stat-lbl">✅ Done</div></div>
    <div class="stat"><div class="stat-num" id="statPending" style="color:#5a6882">—</div><div class="stat-lbl">⏳ Pending</div></div>
    <div class="stat"><div class="stat-num" id="statInProg" style="color:#b45309">—</div><div class="stat-lbl">⚙️ In Progress</div></div>
    <div class="stat"><div class="stat-num" id="statFailed" style="color:#dc2626">—</div><div class="stat-lbl">❌ Failed</div></div>
  </div>

  <div class="bar-wrap"><div class="bar-fill" id="progressBar" style="width:0%"></div></div>
  <div class="pct" id="progressPct">0%</div>

  <div class="actions">
    <button class="btn btn-green" onclick="startDownload()">▶ Start Download</button>
    <button class="btn btn-red"   onclick="resetFailed()">↺ Reset Failed</button>
    <button class="btn btn-gray"  onclick="loadStatus()">🔄 Refresh</button>
    <select id="workersSelect" style="padding:6px;border-radius:6px;border:1.5px solid #e2e6f0;font-size:12px">
      <option value="2">2 Workers</option>
      <option value="4" selected>4 Workers</option>
      <option value="8">8 Workers</option>
    </select>
  </div>
</div>

<div class="card">
  <h1 style="margin-bottom:12px">📊 NAV Summary</h1>
  <div id="summaryArea" style="font-size:13px;color:#5a6882">Loading...</div>
</div>

<div class="card">
  <h1 style="margin-bottom:12px">🕒 Recently Completed</h1>
  <table>
    <thead><tr><th>Fund</th><th>Scheme Code</th><th>NAV Records</th><th>Status</th></tr></thead>
    <tbody id="recentTable"><tr><td colspan="4" style="color:#5a6882">Loading...</td></tr></tbody>
  </table>
</div>

<div class="card" id="failedCard">
  <h1 style="margin-bottom:12px;color:#dc2626">❌ Failed Funds</h1>
  <table>
    <thead><tr><th>Fund</th><th>Code</th><th>Error</th></tr></thead>
    <tbody id="failedTable"><tr><td colspan="3" style="color:#5a6882">None ✓</td></tr></tbody>
  </table>
</div>

<script>
const API = './api.php';

async function loadStatus() {
  const r = await fetch(API + '?action=ndl_status');
  const d = await r.json();
  document.getElementById('lastUpdated').textContent = '🕒 Last updated: ' + new Date().toLocaleTimeString();
  document.getElementById('statTotal').textContent   = d.total;
  document.getElementById('statDone').textContent    = d.done;
  document.getElementById('statPending').textContent = d.pending;
  document.getElementById('statInProg').textContent  = d.in_progress;
  document.getElementById('statFailed').textContent  = d.failed;
  document.getElementById('progressBar').style.width = d.pct + '%';
  document.getElementById('progressPct').textContent = d.pct + '%';

  // Recent
  const rt = document.getElementById('recentTable');
  rt.innerHTML = (d.recent || []).map(f => `
    <tr>
      <td>${f.fund_name || ''}</td>
      <td style="font-family:monospace;color:#5a6882">${f.scheme_code}</td>
      <td>${f.nav_count}</td>
      <td><span class="badge done">Done</span></td>
    </tr>
  `).join('') || '<tr><td colspan="4" style="color:#5a6882">No data yet</td></tr>';

  // Failed
  const ft = document.getElementById('failedTable');
  ft.innerHTML = (d.failed_list || []).map(f => `
    <tr>
      <td>${f.fund_name || ''}</td>
      <td style="font-family:monospace">${f.scheme_code}</td>
      <td style="color:#dc2626;font-size:11px">${f.error_msg || ''}</td>
    </tr>
  `).join('') || '<tr><td colspan="3" style="color:#0d9f57">None ✓</td></tr>';

  // Auto-refresh if in progress
  if (d.in_progress > 0 || d.pending > 0) setTimeout(loadStatus, 3000);
}

async function loadSummary() {
  const r = await fetch(API + '?action=ndl_summary');
  const d = await r.json();
  document.getElementById('summaryArea').innerHTML = `
    <b>${(d.navTotal || 0).toLocaleString()}</b> total NAV records &nbsp;·&nbsp;
    <b>${d.fundsWithHistory || 0}</b> funds with history &nbsp;·&nbsp;
    Oldest: <b>${d.oldest || '—'}</b> &nbsp;·&nbsp; Latest: <b>${d.latest || '—'}</b>
  `;
}

async function startDownload() {
  const w = document.getElementById('workersSelect').value;
  const r = await fetch(API, {method:'POST', body: new URLSearchParams({action:'ndl_start', workers: w})});
  const d = await r.json();
  alert(d.msg || 'Started');
  setTimeout(loadStatus, 1500);
}

async function resetFailed() {
  await fetch(API, {method:'POST', body: new URLSearchParams({action:'ndl_reset_failed'})});
  loadStatus();
}

loadStatus();
loadSummary();
</script>
</body>
</html>
