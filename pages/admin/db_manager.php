<?php
/**
 * WealthDash — t53: DB Manager Page
 * File: pages/admin/db_manager.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!is_admin()) { header('Location: ' . APP_URL . '?page=dashboard'); exit; }
$pageTitle    = 'DB Manager';
$activePage   = 'admin';
$activeSection= 'admin_db';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🗄 DB Manager</h1><p class="page-subtitle">Table browser, query runner, and optimizer.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-secondary btn-sm" onclick="DBM.optimizeAll()">⚡ Optimize All</button>
    <a href="?page=admin_db&action=admin_db_backup_sql" class="btn btn-ghost btn-sm" target="_blank">📥 Export SQL</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start;" class="responsive-grid-1col">

  <!-- Tables list -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <span class="card-title">Tables</span>
      <span id="dbm-total-size" style="font-size:12px;color:var(--text-muted);"></span>
    </div>
    <div style="padding:8px;">
      <input type="text" id="dbm-search" class="form-control form-control-sm" placeholder="Filter tables…" oninput="DBM.filterTables(this.value)">
    </div>
    <div id="dbm-tables-list" style="max-height:500px;overflow-y:auto;"></div>
  </div>

  <!-- Right panel -->
  <div>
    <!-- Table detail -->
    <div id="dbm-table-detail" class="card" style="margin-bottom:20px;display:none;">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span class="card-title" id="dbm-table-title">Table Detail</span>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-secondary btn-sm" id="dbm-optimize-btn" onclick="DBM.optimizeTable()">⚡ Optimize</button>
          <button class="btn btn-ghost btn-sm" id="dbm-export-btn" onclick="DBM.exportTable()">📥 Export</button>
        </div>
      </div>
      <div class="card-body p-0">
        <!-- Tabs -->
        <div style="display:flex;gap:0;border-bottom:1px solid var(--border);">
          <button class="btn btn-ghost" id="tab-cols" onclick="DBM.showTab('cols')" style="border-radius:0;border-bottom:2px solid var(--accent);">Columns</button>
          <button class="btn btn-ghost" id="tab-idxs" onclick="DBM.showTab('idxs')" style="border-radius:0;">Indexes</button>
          <button class="btn btn-ghost" id="tab-data" onclick="DBM.showTab('data')" style="border-radius:0;">Sample Data</button>
        </div>
        <div id="dbm-tab-cols"></div>
        <div id="dbm-tab-idxs" style="display:none;"></div>
        <div id="dbm-tab-data" style="display:none;"></div>
      </div>
    </div>

    <!-- SQL Query Runner -->
    <div class="card">
      <div class="card-header"><span class="card-title">🔍 SQL Query Runner</span></div>
      <div class="card-body">
        <div class="alert alert-warning" style="font-size:12px;margin-bottom:12px;">⚠️ Read-only: SELECT, SHOW, EXPLAIN, DESCRIBE only. Max 200 rows.</div>
        <textarea id="dbm-sql" class="form-control" rows="4"
                  style="font-family:monospace;font-size:13px;"
                  placeholder="SELECT * FROM users LIMIT 10;"></textarea>
        <div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
          <button class="btn btn-primary" onclick="DBM.runQuery()">▶ Run Query</button>
          <span id="dbm-query-meta" style="font-size:12px;color:var(--text-muted);"></span>
        </div>
      </div>
      <div id="dbm-query-result" style="display:none;" class="card-body p-0"></div>
    </div>
  </div>
</div>

<style>
.dbm-table-row{padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px;transition:background .15s;}
.dbm-table-row:hover,.dbm-table-row.active{background:var(--bg-secondary);}
.dbm-table-row.active{border-left:3px solid var(--accent);}
</style>

<script>
const DBM = {
  _tables: [],
  _activeTable: null,
  _activeTab: 'cols',

  init() {
    apiPost({ action: 'admin_db_list' }).then(r => {
      if (!r.ok) return;
      this._tables = r.data.tables || [];
      document.getElementById('dbm-total-size').textContent = r.data.total_mb + ' MB';
      this._renderTableList(this._tables);
    });
  },

  _renderTableList(tables) {
    const wrap = document.getElementById('dbm-tables-list');
    if (!tables.length) { wrap.innerHTML = '<div class="empty-state" style="padding:20px;"><div>No tables.</div></div>'; return; }
    wrap.innerHTML = tables.map(t =>
      `<div class="dbm-table-row ${this._activeTable===t.table_name?'active':''}"
            onclick="DBM.selectTable('${esc(t.table_name)}')">
        <div style="font-weight:600;">${esc(t.table_name)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${Number(t.table_rows).toLocaleString('en-IN')} rows · ${t.total_mb} MB</div>
      </div>`
    ).join('');
  },

  filterTables(q) {
    const filtered = this._tables.filter(t => t.table_name.toLowerCase().includes(q.toLowerCase()));
    this._renderTableList(filtered);
  },

  selectTable(name) {
    this._activeTable = name;
    this._renderTableList(this._tables);
    document.getElementById('dbm-table-detail').style.display = '';
    document.getElementById('dbm-table-title').textContent = '📋 ' + name;
    document.getElementById('dbm-optimize-btn').setAttribute('data-table', name);
    document.getElementById('dbm-export-btn').setAttribute('data-table', name);

    apiPost({ action: 'admin_db_table_info', table: name }).then(r => {
      if (!r.ok) { showToast(r.message, 'error'); return; }

      // Columns tab
      let colHtml = `<div class="table-responsive"><table class="data-table">
        <thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>`;
      for (const c of r.data.columns) {
        colHtml += `<tr>
          <td style="font-family:monospace;font-weight:600;">${esc(c.Field)}</td>
          <td style="font-family:monospace;font-size:12px;">${esc(c.Type)}</td>
          <td>${esc(c.Null)}</td>
          <td>${c.Key ? `<span class="badge">${esc(c.Key)}</span>` : ''}</td>
          <td style="font-size:12px;color:var(--text-muted);">${esc(c.Default ?? 'NULL')}</td>
          <td style="font-size:12px;color:var(--text-muted);">${esc(c.Extra)}</td>
        </tr>`;
      }
      colHtml += `</tbody></table></div>
        <div style="padding:8px 16px;font-size:12px;color:var(--text-muted);">${r.data.count.toLocaleString('en-IN')} total rows</div>`;
      document.getElementById('dbm-tab-cols').innerHTML = colHtml;

      // Indexes tab
      const idxGroups = {};
      for (const idx of r.data.indexes) {
        if (!idxGroups[idx.Key_name]) idxGroups[idx.Key_name] = [];
        idxGroups[idx.Key_name].push(idx);
      }
      let idxHtml = `<div class="table-responsive"><table class="data-table">
        <thead><tr><th>Key Name</th><th>Columns</th><th>Type</th><th>Unique</th></tr></thead><tbody>`;
      for (const [kname, idxs] of Object.entries(idxGroups)) {
        idxHtml += `<tr>
          <td style="font-family:monospace;font-weight:600;">${esc(kname)}</td>
          <td style="font-family:monospace;">${idxs.map(i=>esc(i.Column_name)).join(', ')}</td>
          <td>${esc(idxs[0].Index_type)}</td>
          <td>${idxs[0].Non_unique==='0'?'✅ Yes':'—'}</td>
        </tr>`;
      }
      idxHtml += '</tbody></table></div>';
      document.getElementById('dbm-tab-idxs').innerHTML = idxHtml;

      // Sample data tab
      if (!r.data.sample.length) {
        document.getElementById('dbm-tab-data').innerHTML = '<div class="empty-state" style="padding:20px;"><div>No data.</div></div>';
      } else {
        const cols = Object.keys(r.data.sample[0]);
        let dataHtml = `<div class="table-responsive"><table class="data-table"><thead><tr>${cols.map(c=>`<th>${esc(c)}</th>`).join('')}</tr></thead><tbody>`;
        for (const row of r.data.sample) {
          dataHtml += '<tr>' + cols.map(c => `<td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(String(row[c]??''))}">${esc(String(row[c]??''))}</td>`).join('') + '</tr>';
        }
        dataHtml += '</tbody></table></div><div style="padding:8px 16px;font-size:11px;color:var(--text-muted);">Showing last 5 rows</div>';
        document.getElementById('dbm-tab-data').innerHTML = dataHtml;
      }

      this.showTab('cols');
      document.getElementById('dbm-table-detail').scrollIntoView({ behavior: 'smooth' });
    });
  },

  showTab(tab) {
    this._activeTab = tab;
    ['cols','idxs','data'].forEach(t => {
      document.getElementById('dbm-tab-'+t).style.display = t === tab ? '' : 'none';
      const btn = document.getElementById('tab-'+t);
      if (btn) btn.style.borderBottom = t === tab ? '2px solid var(--accent)' : '2px solid transparent';
    });
  },

  optimizeTable() {
    const table = document.getElementById('dbm-optimize-btn').dataset.table;
    if (!confirm(`Optimize table \`${table}\`?`)) return;
    apiPost({ action: 'admin_db_optimize', table }).then(r => showToast(r.message, r.ok ? 'success' : 'error'));
  },

  optimizeAll() {
    if (!confirm('Optimize ALL InnoDB tables? This may take a moment.')) return;
    apiPost({ action: 'admin_db_optimize' }).then(r => showToast(r.message, r.ok ? 'success' : 'error'));
  },

  exportTable() {
    const table = document.getElementById('dbm-export-btn').dataset.table;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.WD.appUrl + '/api/router.php';
    const fields = { action: 'admin_db_backup_sql', table, [window.CSRF_TOKEN]: '1' };
    for (const [k,v] of Object.entries(fields)) {
      const inp = document.createElement('input'); inp.type='hidden'; inp.name=k; inp.value=v; form.appendChild(inp);
    }
    document.body.appendChild(form); form.submit(); document.body.removeChild(form);
  },

  runQuery() {
    const sql = document.getElementById('dbm-sql').value.trim();
    if (!sql) return;
    document.getElementById('dbm-query-meta').textContent = 'Running…';
    const t0 = Date.now();
    apiPost({ action: 'admin_db_query', sql }).then(r => {
      const elapsed = Date.now() - t0;
      if (!r.ok) {
        document.getElementById('dbm-query-meta').textContent = '';
        showToast(r.message, 'error');
        document.getElementById('dbm-query-result').style.display = 'none';
        return;
      }
      document.getElementById('dbm-query-meta').textContent = `${r.data.count} rows · ${elapsed}ms`;
      const result = document.getElementById('dbm-query-result');
      result.style.display = '';
      if (!r.data.rows.length) {
        result.innerHTML = '<div class="empty-state" style="padding:20px;"><div>No results.</div></div>';
        return;
      }
      let html = `<div class="table-responsive"><table class="data-table"><thead><tr>${r.data.cols.map(c=>`<th>${esc(c)}</th>`).join('')}</tr></thead><tbody>`;
      for (const row of r.data.rows) {
        html += '<tr>' + r.data.cols.map(c => `<td style="font-size:12px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(String(row[c]??''))}</td>`).join('') + '</tr>';
      }
      html += '</tbody></table></div>';
      result.innerHTML = html;
    });
  }
};
document.addEventListener('DOMContentLoaded', () => DBM.init());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
