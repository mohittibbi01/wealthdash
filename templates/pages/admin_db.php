<?php
/**
 * WealthDash — DB Manager Page [t53]
 * File: templates/pages/admin_db.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
$user = require_admin();
$csrf = csrf_token();
?>

<div class="page-header">
    <div class="ph-left">
        <h1 class="page-title">🗄️ DB Manager</h1>
        <p class="page-sub">Inspect tables, run safe queries, backup — admin only.</p>
    </div>
    <div class="ph-right">
        <button class="btn btn-outline btn-sm" id="btnDbBackup">💾 Backup DB</button>
        <button class="btn btn-outline btn-sm" id="btnIndexReport">📊 Index Report</button>
        <button class="btn btn-outline btn-sm" id="btnDbHistory">📋 Query History</button>
    </div>
</div>

<!-- Summary -->
<div class="summary-cards" id="dbSummaryCards">
    <div class="scard scard-load"><div class="scard-skeleton"></div></div>
</div>

<div class="card mt-12">
    <div class="card-tabs">
        <button class="ctab active" data-tab="dbm-tables">Tables</button>
        <button class="ctab" data-tab="dbm-query">Query Editor</button>
        <button class="ctab" data-tab="dbm-preview">Table Preview</button>
        <button class="ctab" data-tab="dbm-backups">Backups</button>
        <button class="ctab" data-tab="dbm-vars">DB Variables</button>
    </div>

    <!-- Tables -->
    <div id="dbm-tables" class="ctab-pane active">
        <div class="tbl-toolbar">
            <input type="text" id="tableSearch" class="form-control-sm" placeholder="Search table…" style="width:200px">
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr><th>Table</th><th class="text-right">Est. Rows</th><th class="text-right">Size</th>
                        <th>Engine</th><th>Updated</th><th>Actions</th></tr>
                </thead>
                <tbody id="tableListBody"><tr><td colspan="6" class="tbl-loading">Loading…</td></tr></tbody>
                <tfoot><tr id="tableListFoot" style="display:none">
                    <td><strong id="tableCount">—</strong> tables</td>
                    <td class="text-right" colspan="2"><strong id="dbTotalSize">—</strong> total</td>
                    <td colspan="3"></td>
                </tr></tfoot>
            </table>
        </div>
    </div>

    <!-- Query Editor -->
    <div id="dbm-query" class="ctab-pane">
        <div style="padding:14px">
            <div class="form-group">
                <label style="font-size:12px;font-weight:600">SQL Query
                    <span class="pill pill-xs pill-done" style="margin-left:6px">SELECT / SHOW / DESCRIBE only</span>
                    <span id="writeToggle" class="pill pill-xs pill-warn" style="margin-left:4px;cursor:pointer"
                          onclick="WDDb.enableWriteMode()">Enable Write Mode</span>
                </label>
                <textarea id="sqlEditor" class="form-control mono" rows="8"
                          placeholder="SELECT * FROM users LIMIT 10;&#10;SHOW TABLES;&#10;DESCRIBE mf_holdings;"
                          style="font-size:12px;line-height:1.6;resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <button class="btn btn-primary" id="btnRunQuery" onclick="WDDb.runQuery()">▶ Run Query</button>
                <button class="btn btn-outline btn-sm" onclick="document.getElementById('sqlEditor').value=''">Clear</button>
                <div id="writeWarning" style="display:none;color:var(--danger);font-size:12px;font-weight:600">
                    ⚠️ Write mode active — DML queries will execute!
                </div>
                <span id="queryStatus" style="font-size:11px;color:var(--muted);margin-left:auto"></span>
            </div>

            <!-- Quick snippets -->
            <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px">
                <span style="font-size:11px;color:var(--muted);align-self:center">Quick:</span>
                <?php
                $snippets = [
                    ['SHOW TABLES', 'SHOW TABLES;'],
                    ['Show Users', 'SELECT id, name, email, role, status, last_login FROM users ORDER BY created_at DESC LIMIT 25;'],
                    ['DB Size', "SELECT table_name, ROUND((data_length+index_length)/1024,1) AS size_kb\nFROM information_schema.tables\nWHERE table_schema=DATABASE()\nORDER BY size_kb DESC;"],
                    ['Slow Tables', "SHOW STATUS LIKE 'Slow_queries';"],
                    ['Active Sessions', 'SELECT * FROM sessions ORDER BY last_activity DESC LIMIT 20;'],
                    ['Login Fails', "SELECT email, COUNT(*) as fails, MAX(attempted_at) as last_fail\nFROM login_attempts WHERE success=0\nGROUP BY email ORDER BY fails DESC LIMIT 10;"],
                ];
                foreach ($snippets as [$label, $sql]):
                ?>
                <button class="btn btn-outline btn-sm" onclick="WDDb.snippet(<?= json_encode($sql) ?>)">
                    <?= htmlspecialchars($label) ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Results -->
        <div id="queryResults" style="padding:0 14px 14px;display:none">
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px" id="queryMeta"></div>
            <div class="tbl-wrap" style="max-height:480px;overflow:auto">
                <table class="tbl" id="queryResultTable">
                    <thead id="queryResultHead"></thead>
                    <tbody id="queryResultBody"></tbody>
                </table>
            </div>
            <div style="margin-top:8px">
                <button class="btn btn-outline btn-sm" id="btnExportCsv" onclick="WDDb.exportCsv()">⬇ Export CSV</button>
            </div>
        </div>
        <div id="queryError" style="display:none;padding:12px 14px;color:var(--danger);
             background:color-mix(in srgb,var(--danger) 10%,transparent);
             border-top:1px solid var(--border);font-family:monospace;font-size:12px"></div>

        <!-- Write confirmation dialog -->
        <div id="writeConfirmBox" style="display:none;padding:14px;background:color-mix(in srgb,var(--danger) 12%,transparent);
             border:1px solid var(--danger);border-radius:6px;margin:0 14px 14px">
            <div style="font-size:13px;font-weight:600;color:var(--danger);margin-bottom:8px">
                ⚠️ Confirm Write Query
            </div>
            <pre id="writePreview" style="font-size:11px;color:var(--text);background:var(--surface2);
                 padding:8px;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-break:break-all"></pre>
            <div style="display:flex;gap:8px;margin-top:10px">
                <button class="btn btn-danger btn-sm" id="btnConfirmWrite" onclick="WDDb.confirmWrite()">Execute Write Query</button>
                <button class="btn btn-outline btn-sm" onclick="WDDb.cancelWrite()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Table Preview -->
    <div id="dbm-preview" class="ctab-pane">
        <div class="tbl-toolbar">
            <select id="previewTableSelect" class="form-select-sm" style="width:220px">
                <option value="">Select table…</option>
            </select>
            <select id="previewLimit" class="form-select-sm">
                <option value="25">25 rows</option>
                <option value="50" selected>50 rows</option>
                <option value="100">100 rows</option>
                <option value="200">200 rows</option>
            </select>
            <button class="btn btn-primary btn-sm" onclick="WDDb.loadPreview()">Load</button>
            <button class="btn btn-outline btn-sm" onclick="WDDb.describeTable()">📐 Structure</button>
        </div>
        <div id="previewStructure" style="display:none;padding:0 14px 8px">
            <div class="tbl-wrap" style="max-height:280px;overflow:auto">
                <table class="tbl" id="structureTable">
                    <thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
                    <tbody id="structureBody"></tbody>
                </table>
            </div>
        </div>
        <div id="previewMeta" style="font-size:11px;color:var(--muted);padding:0 14px 6px"></div>
        <div class="tbl-wrap" style="max-height:480px;overflow:auto">
            <table class="tbl" id="previewTable">
                <thead id="previewHead"></thead>
                <tbody id="previewBody"><tr><td class="tbl-empty">Select a table and click Load.</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- Backups -->
    <div id="dbm-backups" class="ctab-pane">
        <div id="backupProgress" style="display:none;padding:14px;color:var(--warn)">
            ⏳ Creating backup, please wait…
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Filename</th><th>Size</th><th>Tables</th><th>Method</th><th>Status</th><th>Created</th></tr></thead>
                <tbody id="backupListBody"><tr><td colspan="6" class="tbl-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- DB Variables -->
    <div id="dbm-vars" class="ctab-pane">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Variable</th><th>Value</th></tr></thead>
                <tbody id="dbVarsBody"><tr><td colspan="2" class="tbl-loading">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Query History Modal -->
<div id="modalQueryHistory" class="modal" style="display:none">
    <div class="modal-overlay" onclick="document.getElementById('modalQueryHistory').style.display='none'"></div>
    <div class="modal-box modal-lg">
        <div class="modal-hdr">
            <h3>Query History</h3>
            <button class="modal-close" onclick="document.getElementById('modalQueryHistory').style.display='none'">✕</button>
        </div>
        <div class="modal-body" style="max-height:520px;overflow-y:auto">
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>Time</th><th>User</th><th>SQL</th><th>Rows</th><th>ms</th><th>Result</th></tr></thead>
                    <tbody id="historyBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Index Report Modal -->
<div id="modalIndexReport" class="modal" style="display:none">
    <div class="modal-overlay" onclick="document.getElementById('modalIndexReport').style.display='none'"></div>
    <div class="modal-box modal-md">
        <div class="modal-hdr">
            <h3>Index Report</h3>
            <button class="modal-close" onclick="document.getElementById('modalIndexReport').style.display='none'">✕</button>
        </div>
        <div class="modal-body" id="indexReportBody"></div>
    </div>
</div>

<script>
const WDDb = (() => {
    const API    = '/api/router.php';
    const CSRF   = '<?= $csrf ?>';
    let _tables  = [];
    let _lastResults = [];
    let _lastCols    = [];
    let _writeMode   = false;
    let _pendingSql  = '';
    let _writeToken  = '';

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

    function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function fmtSize(kb) { return kb >= 1024 ? (kb/1024).toFixed(1)+' MB' : kb.toFixed(1)+' KB'; }

    // ── Load Tables ───────────────────────────────────────────────────────────
    async function loadTables() {
        const d = await api('dbm_tables');
        _tables = d.data.tables || [];
        renderTables(_tables);
        renderSummary(d.data);
        populatePreviewSelect();
    }

    function renderTables(tables) {
        const tb = document.getElementById('tableListBody');
        if (!tables.length) { tb.innerHTML = '<tr><td colspan="6" class="tbl-empty">No tables.</td></tr>'; return; }
        tb.innerHTML = tables.map(t => `
            <tr>
                <td class="mono">${esc(t.name)}</td>
                <td class="text-right mono">${(parseInt(t.est_rows)||0).toLocaleString()}</td>
                <td class="text-right mono">${fmtSize(parseFloat(t.size_kb)||0)}</td>
                <td style="font-size:11px;color:var(--muted)">${esc(t.engine)}</td>
                <td style="font-size:11px;color:var(--muted)">${t.updated_at||'—'}</td>
                <td>
                    <div class="tbl-actions">
                        <button class="tbl-btn" title="Preview" onclick="WDDb.quickPreview('${esc(t.name)}')">👁</button>
                        <button class="tbl-btn" title="Structure" onclick="WDDb.quickDescribe('${esc(t.name)}')">📐</button>
                        <button class="tbl-btn" title="SELECT in editor" onclick="WDDb.snippet('SELECT * FROM \`${esc(t.name)}\` LIMIT 50;')">▶</button>
                        <button class="tbl-btn" title="Optimize" onclick="WDDb.optimizeTable('${esc(t.name)}')">⚡</button>
                    </div>
                </td>
            </tr>`).join('');

        document.getElementById('tableListFoot').style.display = '';
        document.getElementById('tableCount').textContent = tables.length;
    }

    function renderSummary(data) {
        document.getElementById('dbTotalSize').textContent = fmtSize((data.db_size_kb||0));
        document.getElementById('dbSummaryCards').innerHTML = `
            <div class="scard"><div class="scard-val">${data.count||0}</div><div class="scard-lbl">Tables</div></div>
            <div class="scard"><div class="scard-val">${fmtSize(data.db_size_kb||0)}</div><div class="scard-lbl">DB Size</div></div>`;
    }

    function populatePreviewSelect() {
        const sel = document.getElementById('previewTableSelect');
        sel.innerHTML = '<option value="">Select table…</option>' +
            _tables.map(t => `<option value="${esc(t.name)}">${esc(t.name)} (${(parseInt(t.est_rows)||0).toLocaleString()} rows)</option>`).join('');
    }

    // ── Table Search ──────────────────────────────────────────────────────────
    function initTableSearch() {
        document.getElementById('tableSearch').addEventListener('input', e => {
            const q = e.target.value.toLowerCase();
            renderTables(_tables.filter(t => t.name.toLowerCase().includes(q)));
        });
    }

    // ── Query Editor ──────────────────────────────────────────────────────────
    async function runQuery() {
        const sql = document.getElementById('sqlEditor').value.trim();
        if (!sql) { alert('Enter a SQL query.'); return; }

        hideQueryResults();
        document.getElementById('writeConfirmBox').style.display = 'none';

        const isWrite = /^(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i.test(sql.trim());

        if (isWrite && _writeMode) {
            _pendingSql = sql;
            document.getElementById('writePreview').textContent = sql;
            document.getElementById('writeConfirmBox').style.display = '';
            return;
        }

        if (isWrite && !_writeMode) {
            document.getElementById('queryError').style.display = '';
            document.getElementById('queryError').textContent = 'Write queries blocked. Enable Write Mode first (use with caution).';
            return;
        }

        await execReadQuery(sql);
    }

    async function execReadQuery(sql) {
        const btn = document.getElementById('btnRunQuery');
        btn.disabled = true; btn.textContent = '⏳ Running…';
        document.getElementById('queryError').style.display = 'none';
        document.getElementById('queryStatus').textContent = '';

        const start = performance.now();
        try {
            const d = await api('dbm_query', { sql }, 'POST');
            const r = d.data;
            _lastResults = r.rows || [];
            _lastCols    = r.columns || [];
            renderQueryResults(r);
        } catch(e) {
            document.getElementById('queryError').style.display = '';
            document.getElementById('queryError').textContent = e.message;
        } finally {
            btn.disabled = false; btn.textContent = '▶ Run Query';
        }
    }

    function renderQueryResults(r) {
        if (!r.rows || !r.rows.length) {
            document.getElementById('queryResults').style.display = '';
            document.getElementById('queryMeta').textContent = `0 rows returned in ${r.exec_ms}ms`;
            document.getElementById('queryResultHead').innerHTML = '';
            document.getElementById('queryResultBody').innerHTML =
                '<tr><td class="tbl-empty">No rows returned.</td></tr>';
            return;
        }

        document.getElementById('queryResults').style.display = '';
        document.getElementById('queryMeta').textContent =
            `${r.count} row(s) returned · ${r.exec_ms}ms`;

        const cols = r.columns;
        document.getElementById('queryResultHead').innerHTML =
            '<tr>' + cols.map(c => `<th>${esc(c)}</th>`).join('') + '</tr>';

        document.getElementById('queryResultBody').innerHTML = r.rows.map(row =>
            '<tr>' + cols.map(c => {
                const v = row[c];
                const s = v === null ? '<span style="color:var(--muted)">NULL</span>' : esc(String(v));
                return `<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(String(v??''))}">${s}</td>`;
            }).join('') + '</tr>'
        ).join('');
    }

    function hideQueryResults() {
        document.getElementById('queryResults').style.display = 'none';
        document.getElementById('queryError').style.display   = 'none';
    }

    // ── Write Mode ────────────────────────────────────────────────────────────
    async function enableWriteMode() {
        if (!confirm('⚠️ Enable write mode? This allows INSERT/UPDATE/DELETE queries.\nAll queries are logged. Use extreme caution.')) return;
        try {
            const d = await api('dbm_confirm_token', {}, 'POST');
            _writeToken = d.data.token;
            _writeMode  = true;
            document.getElementById('writeToggle').textContent = '✓ Write Mode ON';
            document.getElementById('writeToggle').style.background = 'color-mix(in srgb,var(--danger) 20%,transparent)';
            document.getElementById('writeToggle').style.color = 'var(--danger)';
            document.getElementById('writeWarning').style.display = '';
        } catch(e) { alert(e.message); }
    }

    async function confirmWrite() {
        const btn = document.getElementById('btnConfirmWrite');
        btn.disabled = true; btn.textContent = 'Executing…';
        try {
            const d = await api('dbm_execute', { sql: _pendingSql, confirm_token: _writeToken }, 'POST');
            document.getElementById('writeConfirmBox').style.display = 'none';
            document.getElementById('queryStatus').textContent =
                `✓ ${d.data.rows_affected} row(s) affected · ${d.data.exec_ms}ms`;
            document.getElementById('queryStatus').style.color = 'var(--done)';
        } catch(e) {
            document.getElementById('queryError').style.display = '';
            document.getElementById('queryError').textContent = e.message;
            document.getElementById('writeConfirmBox').style.display = 'none';
        } finally { btn.disabled = false; btn.textContent = 'Execute Write Query'; }
    }

    function cancelWrite() {
        document.getElementById('writeConfirmBox').style.display = 'none';
        _pendingSql = '';
    }

    // ── Preview ───────────────────────────────────────────────────────────────
    async function loadPreview() {
        const table = document.getElementById('previewTableSelect').value;
        const limit = document.getElementById('previewLimit').value;
        if (!table) { alert('Select a table.'); return; }
        const d = await api('dbm_preview', { table, limit });
        renderPreview(d.data, table);
    }

    function renderPreview(data, table) {
        document.getElementById('previewMeta').textContent =
            `${table} · showing ${data.showing} of ${(data.total||0).toLocaleString()} rows`;
        const cols = data.columns || [];
        document.getElementById('previewHead').innerHTML =
            '<tr>' + cols.map(c => `<th>${esc(c)}</th>`).join('') + '</tr>';
        document.getElementById('previewBody').innerHTML = (data.rows||[]).map(row =>
            '<tr>' + cols.map(c => {
                const v = row[c];
                const s = v === null ? '<span style="color:var(--muted)">NULL</span>' : esc(String(v));
                return `<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${s}</td>`;
            }).join('') + '</tr>'
        ).join('') || '<tr><td class="tbl-empty" colspan="' + cols.length + '">No rows.</td></tr>';
    }

    async function describeTable() {
        const table = document.getElementById('previewTableSelect').value;
        if (!table) { alert('Select a table.'); return; }
        const d = await api('dbm_describe', { table });
        const cols = d.data.columns || [];
        const box  = document.getElementById('previewStructure');
        box.style.display = '';
        document.getElementById('structureBody').innerHTML = cols.map(c =>
            `<tr>
                <td class="mono">${esc(c.Field)}</td>
                <td class="mono" style="font-size:11px">${esc(c.Type)}</td>
                <td style="font-size:11px">${c.Null}</td>
                <td style="font-size:11px;color:var(--accent2)">${c.Key||'—'}</td>
                <td style="font-size:11px;color:var(--muted)">${c.Default??'NULL'}</td>
                <td style="font-size:11px">${c.Extra||'—'}</td>
            </tr>`).join('');
    }

    function quickPreview(table) {
        document.querySelectorAll('.ctab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ctab-pane').forEach(p => p.classList.remove('active'));
        document.querySelector('[data-tab="dbm-preview"]').classList.add('active');
        document.getElementById('dbm-preview').classList.add('active');
        document.getElementById('previewTableSelect').value = table;
        loadPreview();
    }

    function quickDescribe(table) {
        document.querySelectorAll('.ctab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ctab-pane').forEach(p => p.classList.remove('active'));
        document.querySelector('[data-tab="dbm-preview"]').classList.add('active');
        document.getElementById('dbm-preview').classList.add('active');
        document.getElementById('previewTableSelect').value = table;
        describeTable();
    }

    function snippet(sql) {
        document.getElementById('sqlEditor').value = sql;
        document.querySelectorAll('.ctab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.ctab-pane').forEach(p => p.classList.remove('active'));
        document.querySelector('[data-tab="dbm-query"]').classList.add('active');
        document.getElementById('dbm-query').classList.add('active');
    }

    // ── Backups ───────────────────────────────────────────────────────────────
    async function loadBackups() {
        const d = await api('dbm_backup_list');
        const rows = d.data.backups || [];
        const tb   = document.getElementById('backupListBody');
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="6" class="tbl-empty">No backups yet.</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(b => `
            <tr>
                <td class="mono" style="font-size:11px">${esc(b.filename)}</td>
                <td class="mono">${b.size_bytes ? Math.round(b.size_bytes/1024) + ' KB' : '—'}</td>
                <td>${b.tables||'—'}</td>
                <td>${b.method}</td>
                <td><span class="pill pill-xs ${b.status==='completed'?'pill-done':'pill-danger'}">${b.status}</span></td>
                <td style="font-size:11px;color:var(--muted)">${b.created_at}</td>
            </tr>`).join('');
    }

    async function runBackup() {
        if (!confirm('Create a full database backup via mysqldump?')) return;
        const prog = document.getElementById('backupProgress');
        prog.style.display = '';
        const btn = document.getElementById('btnDbBackup');
        btn.disabled = true; btn.textContent = '⏳ Backing up…';
        try {
            const d = await api('dbm_backup', {}, 'POST');
            prog.style.display = 'none';
            alert(`✓ Backup complete!\nFile: ${d.data.filename}\nSize: ${d.data.size_mb} MB`);
            loadBackups();
        } catch(e) {
            prog.style.display = 'none';
            alert('Backup failed: ' + e.message);
        } finally { btn.disabled = false; btn.textContent = '💾 Backup DB'; }
    }

    // ── DB Variables ──────────────────────────────────────────────────────────
    async function loadDbVars() {
        const d = await api('dbm_variables');
        const rows = d.data.variables || [];
        document.getElementById('dbVarsBody').innerHTML = rows.map(r =>
            `<tr>
                <td class="mono">${esc(r.Variable_name)}</td>
                <td class="mono" style="font-size:11px;color:var(--accent2)">${esc(r.Value)}</td>
            </tr>`).join('') || '<tr><td colspan="2" class="tbl-empty">No variables.</td></tr>';
    }

    // ── Export CSV ────────────────────────────────────────────────────────────
    function exportCsv() {
        if (!_lastResults.length) return;
        const cols = _lastCols;
        const csv  = [cols.join(','), ..._lastResults.map(r =>
            cols.map(c => {
                const v = String(r[c] ?? '');
                return v.includes(',') || v.includes('"') || v.includes('\n')
                    ? '"' + v.replace(/"/g, '""') + '"' : v;
            }).join(',')
        )].join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'wd_query_' + Date.now() + '.csv';
        a.click();
    }

    // ── Optimize ──────────────────────────────────────────────────────────────
    async function optimizeTable(table) {
        if (!confirm(`OPTIMIZE TABLE \`${table}\`?`)) return;
        await api('dbm_optimize', { table }, 'POST');
        alert(`✓ Table \`${table}\` optimized.`);
    }

    // ── Query History ─────────────────────────────────────────────────────────
    async function showHistory() {
        document.getElementById('modalQueryHistory').style.display = 'flex';
        const d = await api('dbm_history');
        const rows = d.data.history || [];
        const tb   = document.getElementById('historyBody');
        if (!rows.length) { tb.innerHTML = '<tr><td colspan="6" class="tbl-empty">No history.</td></tr>'; return; }
        tb.innerHTML = rows.map(r => `
            <tr style="${r.is_success?'':'background:color-mix(in srgb,var(--danger) 8%,transparent)'}">
                <td style="font-size:10px;color:var(--muted)">${r.created_at}</td>
                <td style="font-size:11px">${esc(r.name)}</td>
                <td class="mono" style="font-size:10px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="${esc(r.query_text)}">${esc(r.query_text.substring(0,80))}…</td>
                <td class="text-right">${r.rows_affected??'—'}</td>
                <td class="text-right">${r.exec_ms ? r.exec_ms+'ms' : '—'}</td>
                <td><span class="pill pill-xs ${r.is_success?'pill-done':'pill-danger'}">${r.is_success?'OK':'Error'}</span></td>
            </tr>`).join('');
    }

    // ── Index Report ──────────────────────────────────────────────────────────
    async function showIndexReport() {
        document.getElementById('modalIndexReport').style.display = 'flex';
        const d = await api('dbm_index_report');
        const { no_extra_indexes = [], large_tables = [] } = d.data;
        document.getElementById('indexReportBody').innerHTML = `
            <div class="section-title" style="padding:12px 0 6px">Tables with no secondary indexes</div>
            ${no_extra_indexes.length
                ? `<div class="tbl-wrap"><table class="tbl">
                    <thead><tr><th>Table</th><th>Extra Indexes</th></tr></thead>
                    <tbody>${no_extra_indexes.map(t =>
                        `<tr><td class="mono">${esc(t.TABLE_NAME)}</td><td>${t.index_count}</td></tr>`
                    ).join('')}</tbody></table></div>`
                : '<p style="color:var(--muted);font-size:12px">All tables have indexes. 🎉</p>'}
            <div class="section-title" style="padding:12px 0 6px">Large Tables (&gt;1000 rows)</div>
            ${large_tables.length
                ? `<div class="tbl-wrap"><table class="tbl">
                    <thead><tr><th>Table</th><th class="text-right">Rows</th><th class="text-right">Size</th></tr></thead>
                    <tbody>${large_tables.map(t =>
                        `<tr>
                            <td class="mono">${esc(t.TABLE_NAME)}</td>
                            <td class="text-right">${parseInt(t.TABLE_ROWS).toLocaleString()}</td>
                            <td class="text-right">${t.size_mb} MB</td>
                        </tr>`).join('')}
                    </tbody></table></div>`
                : '<p style="color:var(--muted);font-size:12px">No large tables yet.</p>'}`;
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
                    if (tab.dataset.tab === 'dbm-backups') loadBackups();
                    if (tab.dataset.tab === 'dbm-vars')    loadDbVars();
                }
            });
        });
    }

    async function init() {
        initTabs(); initTableSearch();
        document.getElementById('btnDbBackup').addEventListener('click', runBackup);
        document.getElementById('btnDbHistory').addEventListener('click', showHistory);
        document.getElementById('btnIndexReport').addEventListener('click', showIndexReport);
        await loadTables();
    }

    return { init, loadPreview, describeTable, quickPreview, quickDescribe,
             runQuery, confirmWrite, cancelWrite, enableWriteMode,
             snippet, exportCsv, optimizeTable };
})();

document.addEventListener('DOMContentLoaded', WDDb.init);
</script>
