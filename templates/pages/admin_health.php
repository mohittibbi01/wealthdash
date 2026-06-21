<?php
/**
 * WealthDash — System Health Dashboard Page [t51]
 * File: templates/pages/admin_health.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
$user = require_admin();
$csrf = csrf_token();
?>

<div class="page-header">
    <div class="ph-left">
        <h1 class="page-title">🩺 System Health</h1>
        <p class="page-sub">PHP, MySQL, disk, cache, error log — live diagnostics.</p>
    </div>
    <div class="ph-right">
        <span id="healthPingDot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--muted);margin-right:6px;vertical-align:middle"></span>
        <span id="healthPingLabel" style="font-size:12px;color:var(--muted);margin-right:10px">Checking…</span>
        <button class="btn btn-outline btn-sm" id="btnClearLog">🗑 Clear Error Log</button>
        <button class="btn btn-primary btn-sm" id="btnRefreshHealth">↺ Refresh</button>
    </div>
</div>

<!-- Top stat bar -->
<div class="summary-cards" id="healthTopCards">
    <div class="scard scard-load"><div class="scard-skeleton"></div></div>
</div>

<div class="card mt-12">
    <div class="card-tabs">
        <button class="ctab active" data-tab="ht-overview">Overview</button>
        <button class="ctab" data-tab="ht-database">Database</button>
        <button class="ctab" data-tab="ht-php">PHP / Server</button>
        <button class="ctab" data-tab="ht-errors">Error Log</button>
        <button class="ctab" data-tab="ht-trend">Trend</button>
    </div>

    <!-- Overview -->
    <div id="ht-overview" class="ctab-pane active">
        <div class="health-grid" id="healthOverviewGrid">
            <div class="tbl-loading">Running diagnostics…</div>
        </div>
    </div>

    <!-- Database -->
    <div id="ht-database" class="ctab-pane">
        <div id="healthDbContent"><div class="tbl-loading">Loading…</div></div>
    </div>

    <!-- PHP / Server -->
    <div id="ht-php" class="ctab-pane">
        <div id="healthPhpContent"><div class="tbl-loading">Loading…</div></div>
    </div>

    <!-- Error Log -->
    <div id="ht-errors" class="ctab-pane">
        <div class="tbl-toolbar">
            <input type="text" id="logSearch" class="form-control-sm" placeholder="Filter log…" style="width:240px">
        </div>
        <pre id="errorLogPre" style="font-size:11px;line-height:1.6;color:var(--text);background:var(--surface2);
             padding:12px;border-radius:6px;overflow:auto;max-height:480px;white-space:pre-wrap;word-break:break-all">
Loading…</pre>
    </div>

    <!-- Trend -->
    <div id="ht-trend" class="ctab-pane">
        <div class="chart-wrap" style="height:300px;position:relative">
            <canvas id="healthTrendChart"></canvas>
            <div id="healthTrendEmpty" style="display:none" class="chart-empty">No history snapshots yet.</div>
        </div>
    </div>
</div>

<script>
const WDHealth = (() => {
    const API  = '/api/router.php';
    const CSRF = '<?= $csrf ?>';
    let _data  = null;
    let _trendChart = null;

    async function api(action, params = {}, method = 'GET') {
        let url = API + '?action=' + action;
        const opts = { method, credentials: 'same-origin',
                       headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({ ...params, _csrf_token: CSRF });
        }
        const r = await fetch(url, opts);
        const d = await r.json();
        if (!d.success) throw new Error(d.message || 'API error');
        return d;
    }

    function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function pct(v) { const n = parseFloat(v); return n >= 90 ? 'text-loss' : n >= 70 ? 'text-warn' : 'text-gain'; }
    function bar(v, max = 100) {
        const p = Math.min(100, parseFloat(v) / max * 100);
        const c = p >= 90 ? '#e05c5c' : p >= 70 ? '#e6a817' : '#4fc3a1';
        return `<div style="height:5px;background:var(--surface3);border-radius:3px;margin-top:4px">
                    <div style="height:5px;width:${p}%;background:${c};border-radius:3px"></div></div>`;
    }

    // ── Ping ─────────────────────────────────────────────────────────────────
    async function ping() {
        try {
            const d = await api('health_ping');
            const dot   = document.getElementById('healthPingDot');
            const label = document.getElementById('healthPingLabel');
            dot.style.background   = d.data.db ? '#4fc3a1' : '#e05c5c';
            label.textContent = d.data.db ? `DB OK · PHP ${d.data.php}` : 'DB Error';
            label.style.color = d.data.db ? 'var(--done)' : 'var(--danger)';
        } catch(e) {
            document.getElementById('healthPingDot').style.background = '#e05c5c';
            document.getElementById('healthPingLabel').textContent = 'Unreachable';
        }
    }

    // ── Full Load ─────────────────────────────────────────────────────────────
    async function loadFull() {
        const d = await api('health_full');
        _data = d.data;
        renderTopCards();
        renderOverview();
    }

    function renderTopCards() {
        const r  = _data;
        const db = r.database || {};
        const fs = r.filesystem || {};
        document.getElementById('healthTopCards').innerHTML = `
            <div class="scard">
                <div class="scard-val" style="color:var(--done)">PHP ${r.php?.version||'?'}</div>
                <div class="scard-lbl">PHP Version</div>
            </div>
            <div class="scard">
                <div class="scard-val">${db.version||'?'}</div>
                <div class="scard-lbl">MySQL</div>
            </div>
            <div class="scard">
                <div class="scard-val ${pct(r.php?.memory_pct)}">${r.php?.memory_pct||0}%</div>
                <div class="scard-lbl">Memory Used</div>
            </div>
            <div class="scard">
                <div class="scard-val ${pct(db.conn_pct)}">${db.connections||0}</div>
                <div class="scard-lbl">DB Connections</div>
            </div>
            <div class="scard">
                <div class="scard-val ${pct(fs.disk_used_pct)}">${fs.disk_used_pct||0}%</div>
                <div class="scard-lbl">Disk Used</div>
            </div>
            <div class="scard">
                <div class="scard-val">${r.app?.active_sessions||0}</div>
                <div class="scard-lbl">Active Sessions</div>
            </div>`;
    }

    function renderOverview() {
        const r   = _data;
        const php = r.php || {};
        const db  = r.database || {};
        const app = r.app || {};
        const fs  = r.filesystem || {};
        const ext = r.extensions || [];

        const extHtml = ext.map(e =>
            `<span class="pill pill-xs ${e.loaded?'pill-done':'pill-danger'}" style="margin:2px">${e.name}</span>`
        ).join('');

        const writ = (fs.writable || []).map(w =>
            `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px">
                <span class="mono">${esc(w.path)}</span>
                <span class="${w.exists&&w.writable?'text-gain':w.exists?'text-warn':'text-loss'}">
                    ${w.exists ? (w.writable ? '✓ Writable' : '⚠ Locked') : '✗ Missing'}
                </span>
             </div>`
        ).join('');

        document.getElementById('healthOverviewGrid').innerHTML = `
        <div class="hgrid">
            <!-- App Stats -->
            <div class="hcard">
                <div class="hcard-title">Application</div>
                <div class="info-list">
                    <div class="ir"><span>Environment</span><span class="mono">${esc(app.env)}</span></div>
                    <div class="ir"><span>URL</span><span class="mono" style="font-size:10px">${esc(app.url)}</span></div>
                    <div class="ir"><span>Timezone</span><span class="mono">${esc(app.timezone)}</span></div>
                    <div class="ir"><span>Total Users</span><span>${app.total_users}</span></div>
                    <div class="ir"><span>Active Users</span><span>${app.active_users}</span></div>
                    <div class="ir"><span>Active Sessions</span><span>${app.active_sessions}</span></div>
                    <div class="ir"><span>Total Portfolios</span><span>${app.total_portfolios}</span></div>
                    <div class="ir"><span>MF Holdings</span><span>${app.mf_holdings}</span></div>
                    <div class="ir"><span>NAV Records</span><span>${(app.nav_records||0).toLocaleString()}</span></div>
                </div>
            </div>

            <!-- PHP Memory -->
            <div class="hcard">
                <div class="hcard-title">PHP Memory</div>
                <div class="info-list">
                    <div class="ir"><span>Memory Limit</span><span class="mono">${php.memory_limit}</span></div>
                    <div class="ir"><span>Used</span><span class="${pct(php.memory_pct)}">${php.memory_used_mb} MB (${php.memory_pct}%)</span></div>
                    <div class="ir"><span>Peak</span><span>${php.memory_peak_mb} MB</span></div>
                    ${bar(php.memory_pct)}
                    <div class="ir mt-8"><span>Max Exec Time</span><span>${php.max_exec_time}s</span></div>
                    <div class="ir"><span>Upload Max</span><span>${php.upload_max}</span></div>
                    <div class="ir"><span>SAPI</span><span class="mono">${php.sapi}</span></div>
                </div>
            </div>

            <!-- Opcache -->
            <div class="hcard">
                <div class="hcard-title">Opcache</div>
                ${r.opcache?.enabled ? `
                <div class="info-list">
                    <div class="ir"><span>Status</span><span class="text-gain">Enabled</span></div>
                    <div class="ir"><span>Hit Rate</span><span class="${pct(r.opcache.hit_rate)}">${r.opcache.hit_rate}%</span></div>
                    <div class="ir"><span>Used</span><span>${r.opcache.used_mb} MB</span></div>
                    <div class="ir"><span>Free</span><span>${r.opcache.free_mb} MB</span></div>
                    <div class="ir"><span>Cached Scripts</span><span>${r.opcache.cached_scripts}</span></div>
                </div>` : '<div style="color:var(--muted);font-size:12px;padding:8px">Opcache not enabled</div>'}
            </div>

            <!-- Disk -->
            <div class="hcard">
                <div class="hcard-title">Disk / Filesystem</div>
                <div class="info-list">
                    <div class="ir"><span>Total</span><span>${fs.disk_total_gb} GB</span></div>
                    <div class="ir"><span>Free</span><span class="text-gain">${fs.disk_free_gb} GB</span></div>
                    <div class="ir"><span>Used</span><span class="${pct(fs.disk_used_pct)}">${fs.disk_used_pct}%</span></div>
                    ${bar(fs.disk_used_pct)}
                    <div style="margin-top:10px">${writ}</div>
                </div>
            </div>

            <!-- PHP Extensions -->
            <div class="hcard">
                <div class="hcard-title">Required Extensions</div>
                <div style="padding:6px 0">${extHtml}</div>
            </div>

            <!-- Login Trend mini -->
            <div class="hcard" style="grid-column:span 2">
                <div class="hcard-title">Login Activity (7 days)</div>
                <div style="height:120px;position:relative">
                    <canvas id="loginTrendMini"></canvas>
                </div>
            </div>
        </div>`;

        // Mini login chart
        const lt = r.login_trend || [];
        if (lt.length) {
            const ctx = document.getElementById('loginTrendMini').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: lt.map(l => l.date),
                    datasets: [
                        { label: 'Success', data: lt.map(l => l.success), backgroundColor: '#4fc3a1' },
                        { label: 'Failed',  data: lt.map(l => l.failed),  backgroundColor: '#e05c5c' },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { labels: { color:'var(--muted)', font:{size:10} } } },
                    scales: {
                        x: { ticks: { color:'var(--muted)', font:{size:9} }, grid: { color:'var(--border)' } },
                        y: { ticks: { color:'var(--muted)' }, grid: { color:'var(--border)' } },
                    }
                }
            });
        }

        // Also populate DB and PHP tabs immediately
        renderDb();
        renderPhp();
        renderErrorLog();
    }

    function renderDb() {
        const db = _data?.database || {};
        const tables = _data?.top_tables || [];
        document.getElementById('healthDbContent').innerHTML = `
        <div class="hgrid">
            <div class="hcard">
                <div class="hcard-title">Connection Stats</div>
                <div class="info-list">
                    <div class="ir"><span>Version</span><span class="mono">${esc(db.version)}</span></div>
                    <div class="ir"><span>DB Size</span><span>${db.size_mb} MB</span></div>
                    <div class="ir"><span>Tables</span><span>${db.table_count}</span></div>
                    <div class="ir"><span>Uptime</span><span>${db.uptime_hours}h</span></div>
                    <div class="ir"><span>Connections</span><span class="${pct(db.conn_pct)}">${db.connections} / ${db.max_connections}</span></div>
                    ${bar(db.conn_pct, 100)}
                    <div class="ir mt-8"><span>Slow Queries</span>
                        <span class="${(db.slow_queries||0)>0?'text-warn':'text-gain'}">${db.slow_queries}</span></div>
                    <div class="ir"><span>Total Queries</span><span>${(db.total_queries||0).toLocaleString()}</span></div>
                </div>
            </div>
            <div class="hcard" style="grid-column:span 2">
                <div class="hcard-title">Top 10 Tables by Size</div>
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>Table</th><th class="text-right">Est. Rows</th><th class="text-right">Size (KB)</th></tr></thead>
                        <tbody>
                        ${tables.map(t => `
                            <tr>
                                <td class="mono">${esc(t.table_name)}</td>
                                <td class="text-right mono">${(parseInt(t.table_rows)||0).toLocaleString()}</td>
                                <td class="text-right mono">${parseFloat(t.size_mb)*1024|0} KB</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>`;
    }

    function renderPhp() {
        const php = _data?.php || {};
        const ext = (_data?.extensions || []);
        document.getElementById('healthPhpContent').innerHTML = `
        <div class="hgrid">
            <div class="hcard" style="grid-column:span 2">
                <div class="hcard-title">PHP Configuration</div>
                <div class="info-list">
                    ${Object.entries(php).map(([k,v]) =>
                        `<div class="ir"><span>${esc(k)}</span><span class="mono">${esc(String(v))}</span></div>`
                    ).join('')}
                </div>
            </div>
            <div class="hcard">
                <div class="hcard-title">All Required Extensions</div>
                <div style="padding:6px 0">
                    ${ext.map(e => `
                        <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border);font-size:12px">
                            <span class="mono">${e.name}</span>
                            <span class="${e.loaded?'text-gain':'text-loss'}">${e.loaded?'✓ Loaded':'✗ Missing'}</span>
                        </div>`).join('')}
                </div>
            </div>
        </div>`;
    }

    function renderErrorLog() {
        const lines = _data?.error_log || [];
        const pre   = document.getElementById('errorLogPre');
        if (!lines.length) {
            pre.textContent = 'No errors logged. 🎉';
            return;
        }
        // Colour-code by severity
        pre.innerHTML = lines.map(l => {
            const s = String(l);
            const cls = s.includes('Fatal') || s.includes('Error') ? 'color:#e05c5c' :
                        s.includes('Warning') ? 'color:#e6a817' :
                        s.includes('Notice') ? 'color:#7c6fcd' : '';
            return `<span style="${cls}">${esc(s)}</span>`;
        }).join('');
        pre.scrollTop = pre.scrollHeight;

        // Log search
        document.getElementById('logSearch').addEventListener('input', e => {
            const q = e.target.value.toLowerCase();
            pre.innerHTML = lines
                .filter(l => l.toLowerCase().includes(q))
                .map(l => `<span>${esc(l)}</span>`).join('');
        });
    }

    // ── Trend Chart ───────────────────────────────────────────────────────────
    async function loadTrend() {
        const d = await api('health_history');
        const h = d.data.history || [];
        const el = document.getElementById('healthTrendEmpty');
        if (!h.length) { el.style.display = 'flex'; return; }
        el.style.display = 'none';

        const ctx = document.getElementById('healthTrendChart').getContext('2d');
        if (_trendChart) _trendChart.destroy();
        _trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: h.map(r => r.snap_at),
                datasets: [
                    { label: 'Memory (MB)', data: h.map(r => r.php_memory ? Math.round(r.php_memory/1048576) : null),
                      borderColor:'#7c6fcd', fill:false, tension:0.3 },
                    { label: 'DB Size (MB)', data: h.map(r => r.db_size_mb),
                      borderColor:'#4fc3a1', fill:false, tension:0.3 },
                    { label: 'Active Sessions', data: h.map(r => r.active_users),
                      borderColor:'#e6a817', fill:false, tension:0.3 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { color:'var(--muted)' } } },
                scales: {
                    x: { ticks: { color:'var(--muted)', maxTicksLimit:10 }, grid:{ color:'var(--border)' } },
                    y: { ticks: { color:'var(--muted)' }, grid:{ color:'var(--border)' } },
                }
            }
        });
    }

    // ── Clear Log ─────────────────────────────────────────────────────────────
    async function clearLog() {
        if (!confirm('Clear error log file?')) return;
        await api('health_clear_log', {}, 'POST');
        _data.error_log = [];
        document.getElementById('errorLogPre').textContent = 'Log cleared.';
    }

    // ── Tabs ──────────────────────────────────────────────────────────────────
    function initTabs() {
        document.querySelectorAll('.ctab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.ctab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.ctab-pane').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                const pane = document.getElementById(tab.dataset.tab);
                if (pane) {
                    pane.classList.add('active');
                    if (tab.dataset.tab === 'ht-trend') loadTrend();
                }
            });
        });
    }

    async function init() {
        initTabs();
        document.getElementById('btnRefreshHealth').addEventListener('click', loadFull);
        document.getElementById('btnClearLog').addEventListener('click', clearLog);
        await ping();
        await loadFull();
        setInterval(ping, 30000);
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', WDHealth.init);
</script>

<style>
.hgrid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; padding:14px; }
.hcard { background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:14px; }
.hcard-title { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase;
               letter-spacing:.07em; margin-bottom:10px; }
.info-list .ir { display:flex; justify-content:space-between; padding:4px 0;
                 border-bottom:1px solid color-mix(in srgb,var(--border) 50%,transparent);
                 font-size:12px; gap:8px; }
.info-list .ir:last-child { border-bottom:none; }
.mt-8 { margin-top:8px; }
@media(max-width:900px){ .hgrid{ grid-template-columns:1fr 1fr; } }
@media(max-width:600px){ .hgrid{ grid-template-columns:1fr; } }
</style>
