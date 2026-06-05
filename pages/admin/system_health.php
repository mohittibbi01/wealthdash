<?php
/**
 * WealthDash — t51: System Health Dashboard Page
 * File: pages/admin/system_health.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!is_admin()) { header('Location: ' . APP_URL . '?page=dashboard'); exit; }
$pageTitle    = 'System Health';
$activePage   = 'admin';
$activeSection= 'system_health';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🖥 System Health Dashboard</h1><p class="page-subtitle">Server stats, DB size, user activity, and error logs.</p></div>
  <button class="btn btn-secondary btn-sm" onclick="SH.load()">🔄 Refresh</button>
</div>

<!-- Status cards -->
<div id="sh-cards" class="dashboard-grid" style="margin-bottom:20px;"></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">

  <!-- DB info -->
  <div class="card">
    <div class="card-header"><span class="card-title">🗄 Database</span></div>
    <div class="card-body p-0"><div id="sh-db-table"></div></div>
  </div>

  <!-- PHP / Server -->
  <div class="card">
    <div class="card-header"><span class="card-title">⚙️ PHP & Server</span></div>
    <div class="card-body" id="sh-php-info"></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
  <!-- Recent activity -->
  <div class="card">
    <div class="card-header"><span class="card-title">📋 Recent Activity</span></div>
    <div class="card-body p-0"><div id="sh-activity"></div></div>
  </div>

  <!-- Errors -->
  <div class="card">
    <div class="card-header"><span class="card-title">⚠️ Recent Errors</span></div>
    <div class="card-body" id="sh-errors" style="font-family:monospace;font-size:11px;max-height:300px;overflow-y:auto;"></div>
  </div>
</div>

<script>
const SH = {
  init() { this.load(); },
  load() {
    document.getElementById('sh-cards').innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;">Loading…</div>';
    apiPost({ action: 'admin_system_health' }).then(r => {
      if (!r.ok) return;
      const d = r.data;

      // Cards
      const diskColor = d.disk.used_pct > 90 ? 'wd-loss' : d.disk.used_pct > 75 ? '' : 'wd-gain';
      document.getElementById('sh-cards').innerHTML = `
        <div class="stat-card">
          <div class="stat-label">Total Users</div>
          <div class="stat-value wd-num-xl">${d.users.total}</div>
          <div class="stat-sub">${d.users.active} active · ${d.users.active_7d} last 7d</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">DB Size</div>
          <div class="stat-value wd-num-xl">${d.db.size_mb} MB</div>
          <div class="stat-sub">${d.db.table_count} tables</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Disk Used</div>
          <div class="stat-value wd-num-xl ${diskColor}">${d.disk.used_pct}%</div>
          <div class="stat-sub">${d.disk.free_gb} GB free of ${d.disk.total_gb} GB</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">MF Holdings</div>
          <div class="stat-value wd-num-xl">${d.mf_holdings}</div>
          <div class="stat-sub">${d.mf_sips_active} active SIPs</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Memory Usage</div>
          <div class="stat-value wd-num-xl">${d.php.mem_usage_mb} MB</div>
          <div class="stat-sub">Peak: ${d.php.mem_peak_mb} MB / ${d.php.mem_limit}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Cache</div>
          <div class="stat-value wd-num-xl" style="font-size:14px;">
            ${d.cache.apcu?'✅ APCu':'❌ APCu'} &nbsp;
            ${d.cache.redis?'✅ Redis':'❌ Redis'}
          </div>
          <div class="stat-sub">Last cron: ${d.last_cron ? d.last_cron.substring(0,16) : 'Unknown'}</div>
        </div>`;

      // DB tables
      let dbHtml = `<div class="table-responsive"><table class="data-table">
        <thead><tr><th>Table</th><th class="text-right">Rows (est.)</th><th class="text-right">Size MB</th></tr></thead><tbody>`;
      for (const t of d.db.top_tables) {
        dbHtml += `<tr><td style="font-family:monospace;font-size:12px;">${esc(t.table_name)}</td><td class="text-right wd-num">${Number(t.table_rows).toLocaleString('en-IN')}</td><td class="text-right wd-num">${t.size_mb}</td></tr>`;
      }
      dbHtml += '</tbody></table></div>';
      document.getElementById('sh-db-table').innerHTML = dbHtml;

      // PHP info
      document.getElementById('sh-php-info').innerHTML = `
        <table class="data-table"><tbody>
          <tr><td>PHP Version</td><td style="font-weight:600;">${esc(d.php.version)}</td></tr>
          <tr><td>Memory Limit</td><td>${esc(d.php.mem_limit)}</td></tr>
          <tr><td>Upload Max</td><td>${esc(d.php.upload_limit)}</td></tr>
          <tr><td>APCu Cache</td><td>${d.cache.apcu ? '✅ Available' : '❌ Not available'}</td></tr>
          <tr><td>Redis</td><td>${d.cache.redis ? '✅ Available' : '❌ Not available'}</td></tr>
          <tr><td>OPcache</td><td>${d.cache.opcache ? '✅ Available' : '❌ Not available'}</td></tr>
          <tr><td>Server Time</td><td style="font-family:monospace;">${esc(d.server_time)}</td></tr>
          <tr><td>Users with 2FA</td><td>${d.users.with_2fa} / ${d.users.total}</td></tr>
          <tr><td>Admin Users</td><td>${d.users.admins}</td></tr>
        </tbody></table>`;

      // Activity
      if (!d.recent_activity.length) {
        document.getElementById('sh-activity').innerHTML = '<div class="empty-state"><div>No activity.</div></div>';
      } else {
        let ah = '<div style="font-size:12px;">';
        for (const a of d.recent_activity) {
          ah += `<div style="padding:6px 12px;border-bottom:1px solid var(--border);"><span style="color:var(--text-muted);">${esc(a.created_at?.substring(0,16))}</span> <strong>${esc(a.name||'?')}</strong> — ${esc(a.action)}: ${esc(a.detail||'')}</div>`;
        }
        ah += '</div>';
        document.getElementById('sh-activity').innerHTML = ah;
      }

      // Errors
      const errEl = document.getElementById('sh-errors');
      if (!d.recent_errors.length) {
        errEl.innerHTML = '<div style="color:var(--gain);">✅ No recent errors in log.</div>';
      } else {
        errEl.innerHTML = d.recent_errors.map(l => `<div style="color:var(--loss);margin-bottom:2px;">${esc(l)}</div>`).join('');
      }
    });
  }
};
document.addEventListener('DOMContentLoaded', () => SH.init());
// Auto-refresh every 60s
setInterval(() => SH.load(), 60000);
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
