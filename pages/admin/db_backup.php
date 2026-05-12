<?php
/**
 * WealthDash — Admin: DB Backup & Restore UI
 * Task: t211
 * URL: /pages/admin/db_backup.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$user = require_admin();

$pageTitle = 'DB Backup & Restore';
include APP_ROOT . '/templates/header.php';
?>

<style>
.t211-wrap { max-width: 1100px; margin: 0 auto; padding: 20px; }

.t211-section-title {
    font-size: 10px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: .1em;
    margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
}
.t211-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* Stats row */
.t211-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
.t211-stat  { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
.t211-stat-val { font-family: 'JetBrains Mono', monospace; font-size: 22px; font-weight: 700; line-height: 1; }
.t211-stat-lbl { font-size: 10px; color: var(--muted); margin-top: 4px; text-transform: uppercase; letter-spacing: .06em; }

/* Backup controls card */
.t211-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 16px; margin-bottom: 14px;
}
.t211-card-title { font-size: 13px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }

/* Action row */
.t211-action-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* Type select */
.t211-select {
    background: var(--surface2); border: 1px solid var(--border); color: var(--text);
    font-size: 12px; border-radius: 6px; padding: 7px 10px;
    font-family: 'DM Sans', sans-serif; cursor: pointer; min-width: 160px;
}

/* Buttons */
.t211-btn {
    padding: 8px 18px; border-radius: 6px; border: none;
    font-size: 12px; font-weight: 600; cursor: pointer;
    font-family: 'DM Sans', sans-serif; transition: .2s; display: inline-flex; align-items: center; gap: 6px;
}
.t211-btn-primary { background: var(--accent); color: #fff; }
.t211-btn-primary:hover { opacity: .85; }
.t211-btn-primary:disabled { opacity: .4; cursor: not-allowed; }
.t211-btn-danger  { background: var(--danger); color: #fff; }
.t211-btn-danger:hover { opacity: .85; }
.t211-btn-outline { background: var(--surface2); border: 1px solid var(--border); color: var(--text); padding: 7px 14px; }
.t211-btn-outline:hover { border-color: var(--accent); color: var(--accent); }
.t211-btn-sm { font-size: 11px; padding: 4px 10px; border-radius: 5px; }

/* Table */
.t211-tbl-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; }
.t211-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.t211-tbl th {
    text-align: left; padding: 8px 12px; font-size: 9px; font-weight: 700;
    color: var(--muted); text-transform: uppercase; letter-spacing: .07em;
    border-bottom: 1px solid var(--border); background: var(--surface2);
}
.t211-tbl td { padding: 9px 12px; border-bottom: 1px solid color-mix(in srgb, var(--border) 55%, transparent); vertical-align: middle; }
.t211-tbl tr:last-child td { border-bottom: none; }
.t211-tbl tr:hover td { background: var(--surface2); }
.t211-tbl td.mono { font-family: 'JetBrains Mono', monospace; font-size: 11px; }

/* Status badges */
.t211-badge {
    font-size: 10px; font-weight: 600; padding: 2px 9px; border-radius: 20px;
    display: inline-block; font-family: 'JetBrains Mono', monospace;
}
.t211-badge-ok   { background: color-mix(in srgb, var(--done) 18%, transparent); color: var(--done); }
.t211-badge-fail { background: color-mix(in srgb, var(--danger) 14%, transparent); color: var(--danger); }
.t211-badge-prog { background: color-mix(in srgb, var(--warn) 16%, transparent); color: var(--warn); }

/* Type badges */
.t211-type {
    font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 3px;
    font-family: 'JetBrains Mono', monospace;
    background: color-mix(in srgb, var(--accent) 16%, transparent);
    color: var(--accent);
}

/* Upload zone */
.t211-drop-zone {
    border: 2px dashed var(--border); border-radius: 10px; padding: 30px;
    text-align: center; cursor: pointer; transition: .2s;
    background: var(--surface2);
}
.t211-drop-zone:hover, .t211-drop-zone.drag-over {
    border-color: var(--accent); background: color-mix(in srgb, var(--accent) 5%, var(--surface2));
}
.t211-drop-zone input { display: none; }
.t211-drop-icon { font-size: 32px; margin-bottom: 8px; }
.t211-drop-text { font-size: 12px; color: var(--muted); }
.t211-drop-text strong { color: var(--text); }

/* Warning box */
.t211-warn {
    background: color-mix(in srgb, var(--danger) 7%, var(--surface));
    border: 1px solid color-mix(in srgb, var(--danger) 22%, var(--border));
    border-radius: 8px; padding: 12px; font-size: 12px; color: var(--text);
    margin-bottom: 12px; line-height: 1.6;
}
.t211-warn strong { color: var(--danger); }

/* Confirm input */
.t211-confirm-input {
    width: 100%; background: var(--surface2); border: 1px solid var(--border);
    color: var(--text); font-size: 13px; font-family: 'JetBrains Mono', monospace;
    border-radius: 6px; padding: 8px 12px; margin-top: 8px; transition: .2s;
}
.t211-confirm-input:focus { outline: none; border-color: var(--danger); }

/* Spinner */
.t211-spin { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: t211-spin .7s linear infinite; }
@keyframes t211-spin { to { transform: rotate(360deg); } }

/* Empty state */
.t211-empty { text-align: center; padding: 32px; color: var(--muted); font-size: 13px; }
.t211-empty-icon { font-size: 36px; margin-bottom: 8px; }

/* Tabs */
.t211-tabs { display: flex; border-bottom: 1px solid var(--border); margin-bottom: 14px; }
.t211-tab { padding: 9px 18px; font-size: 12px; font-weight: 600; color: var(--muted); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: .15s; }
.t211-tab:hover { color: var(--text); }
.t211-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.t211-tab-pane { display: none; }
.t211-tab-pane.active { display: block; }

/* Upload selected file info */
.t211-file-info {
    background: color-mix(in srgb, var(--accent2) 8%, var(--surface2));
    border: 1px solid color-mix(in srgb, var(--accent2) 22%, var(--border));
    border-radius: 8px; padding: 10px 14px; margin-top: 10px;
    font-size: 12px; display: none;
}

@media (max-width: 700px) {
    .t211-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="t211-wrap">

<!-- Stats Row -->
<div class="t211-stats" id="t211-stats">
    <div class="t211-stat">
        <div class="t211-stat-val" id="stat-count">—</div>
        <div class="t211-stat-lbl">Total Backups</div>
    </div>
    <div class="t211-stat">
        <div class="t211-stat-val" id="stat-size">—</div>
        <div class="t211-stat-lbl">Storage Used</div>
    </div>
    <div class="t211-stat">
        <div class="t211-stat-val" id="stat-last" style="font-size:13px">—</div>
        <div class="t211-stat-lbl">Last Backup</div>
    </div>
    <div class="t211-stat">
        <div class="t211-stat-val" id="stat-disk">—</div>
        <div class="t211-stat-lbl">Disk Free</div>
    </div>
</div>

<!-- Tabs: Backup / Restore -->
<div class="t211-tabs">
    <div class="t211-tab active" onclick="t211Tab('backup')">🗄️ Backup</div>
    <div class="t211-tab" onclick="t211Tab('restore')">♻️ Restore</div>
    <div class="t211-tab" onclick="t211Tab('restlog')">📋 Restore Log</div>
</div>

<!-- ── TAB: BACKUP ───────────────────────────────────────── -->
<div class="t211-tab-pane active" id="pane-backup">

    <!-- Create backup -->
    <div class="t211-card">
        <div class="t211-card-title">🗄️ Create New Backup</div>
        <div class="t211-action-row">
            <select class="t211-select" id="backup-type">
                <option value="full">Full Backup (Schema + Data)</option>
                <option value="schema_only">Schema Only</option>
                <option value="data_only">Data Only</option>
            </select>
            <button class="t211-btn t211-btn-primary" id="btn-create-backup" onclick="t211CreateBackup()">
                <span id="create-btn-text">⬇️ Create Backup</span>
            </button>
        </div>
        <div style="margin-top:8px; font-size:11px; color:var(--muted);">
            Backup saved to <code style="font-family:'JetBrains Mono',monospace; font-size:10px;">storage/backups/</code> on server.
            Use Download to get a local copy.
        </div>
    </div>

    <!-- Backup list -->
    <div class="t211-section-title">Backup History</div>
    <div id="backup-list-wrap">
        <div class="t211-empty"><div class="t211-empty-icon">📦</div>Loading...</div>
    </div>
</div>

<!-- ── TAB: RESTORE ──────────────────────────────────────── -->
<div class="t211-tab-pane" id="pane-restore">

    <div class="t211-warn">
        <strong>⚠️ Warning:</strong> Restoring a backup will <strong>overwrite all current data</strong>.
        This action cannot be undone. Make sure you have a fresh backup before restoring.
    </div>

    <div class="t211-card">
        <div class="t211-card-title">📤 Restore from Uploaded File</div>
        <div class="t211-drop-zone" id="drop-zone" onclick="document.getElementById('sql-file-input').click()" ondragover="t211DragOver(event)" ondragleave="t211DragLeave(event)" ondrop="t211Drop(event)">
            <input type="file" id="sql-file-input" accept=".sql,.gz" onchange="t211FileSelected(this)">
            <div class="t211-drop-icon">📁</div>
            <div class="t211-drop-text"><strong>Click to select</strong> or drag & drop a .sql / .sql.gz file here</div>
            <div style="font-size:10px;color:var(--muted);margin-top:6px;">Max 200 MB</div>
        </div>

        <div class="t211-file-info" id="file-info">
            <strong id="fi-name"></strong> &nbsp;·&nbsp; <span id="fi-size"></span>
            <br><span style="font-size:11px;color:var(--muted);">Click "Upload & Restore" to proceed.</span>
        </div>

        <div style="margin-top:14px;" id="restore-confirm-wrap-upload" style="display:none">
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">Type <strong style="color:var(--danger);font-family:'JetBrains Mono',monospace;">RESTORE</strong> to confirm:</div>
            <input type="text" class="t211-confirm-input" id="restore-confirm-input-upload" placeholder="Type RESTORE" oninput="t211CheckConfirm('upload')">
        </div>

        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;" id="restore-upload-btns">
            <button class="t211-btn t211-btn-outline" id="btn-upload-file" onclick="t211UploadFile()" disabled>📤 Upload File</button>
            <button class="t211-btn t211-btn-danger"  id="btn-restore-upload" onclick="t211RestoreUpload()" disabled style="display:none">♻️ Run Restore</button>
        </div>
    </div>

    <div class="t211-card">
        <div class="t211-card-title">📚 Restore from Backup Library</div>
        <div id="restore-list-wrap">
            <div class="t211-empty"><div class="t211-empty-icon">📚</div>Loading backups...</div>
        </div>
    </div>
</div>

<!-- ── TAB: RESTORE LOG ──────────────────────────────────── -->
<div class="t211-tab-pane" id="pane-restlog">
    <div class="t211-section-title">Restore History</div>
    <div id="restlog-wrap">
        <div class="t211-empty"><div class="t211-empty-icon">📋</div>Loading...</div>
    </div>
</div>

</div><!-- /wrap -->

<!-- ── RESTORE CONFIRM MODAL (from library) ──────────────── -->
<div class="overlay" id="restore-modal">
    <div class="modal del">
        <div class="mtitle">♻️ Confirm Database Restore</div>
        <div class="msub">This will overwrite ALL current data with the selected backup.</div>

        <div class="t211-warn">
            <strong>Backup:</strong> <span id="rm-filename" style="font-family:'JetBrains Mono',monospace;font-size:11px;"></span><br>
            <strong>Created:</strong> <span id="rm-date"></span>
        </div>

        <div style="font-size:12px;color:var(--muted);margin-bottom:4px;">
            Type <strong style="color:var(--danger);font-family:'JetBrains Mono',monospace;">RESTORE</strong> to confirm:
        </div>
        <input type="text" class="t211-confirm-input" id="rm-confirm-input" placeholder="Type RESTORE" oninput="t211CheckConfirmModal()">

        <div class="mbtn-row" style="margin-top:14px;">
            <button class="t211-btn t211-btn-danger" id="btn-rm-confirm" onclick="t211DoRestore()" disabled>♻️ Run Restore</button>
            <button class="t211-btn t211-btn-outline" onclick="closeRestoreModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
// ── State ─────────────────────────────────────────────────────────────────
let t211_active_tab   = 'backup';
let t211_upload_temp  = null;
let t211_restore_backup_id = null;

// ── Tab switch ────────────────────────────────────────────────────────────
function t211Tab(tab) {
    t211_active_tab = tab;
    document.querySelectorAll('.t211-tab').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.t211-tab-pane').forEach(el => el.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('pane-' + tab).classList.add('active');

    if (tab === 'backup')   t211LoadBackups();
    if (tab === 'restore')  t211LoadRestoreList();
    if (tab === 'restlog')  t211LoadRestoreLog();
}

// ── Stats ─────────────────────────────────────────────────────────────────
async function t211LoadStats() {
    try {
        const r = await API.get('admin_db_backup_stats');
        if (r.ok) {
            const d = r.data;
            document.getElementById('stat-count').textContent = d.db_backup_count ?? '—';
            document.getElementById('stat-size').textContent  = d.total_size_human ?? '0 B';
            document.getElementById('stat-disk').textContent  = d.disk_free_human  ?? '—';
            if (d.last_backup) {
                document.getElementById('stat-last').textContent = t211FmtDate(d.last_backup);
            } else {
                document.getElementById('stat-last').textContent = 'Never';
            }
        }
    } catch(e) {}
}

// ── Load backup list ──────────────────────────────────────────────────────
async function t211LoadBackups() {
    const wrap = document.getElementById('backup-list-wrap');
    wrap.innerHTML = '<div class="t211-empty"><div class="t211-empty-icon">📦</div>Loading...</div>';
    try {
        const r = await API.get('admin_db_backup_list');
        if (!r.ok) { wrap.innerHTML = `<div class="t211-empty">Error: ${r.message}</div>`; return; }
        const backups = r.data.backups || [];
        if (!backups.length) {
            wrap.innerHTML = '<div class="t211-empty"><div class="t211-empty-icon">📦</div>No backups yet. Create one above.</div>';
            return;
        }
        wrap.innerHTML = `
        <div class="t211-tbl-wrap">
        <table class="t211-tbl">
            <thead><tr>
                <th>#</th><th>Filename</th><th>Type</th><th>Size</th>
                <th>Tables</th><th>Status</th><th>Created</th><th>By</th><th>Actions</th>
            </tr></thead>
            <tbody>
            ${backups.map(b => `
            <tr>
                <td class="mono">${b.id}</td>
                <td class="mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(b.filename)}">${esc(b.filename)}</td>
                <td><span class="t211-type">${esc(b.backup_type)}</span></td>
                <td>${esc(b.file_size_human)}</td>
                <td>${b.tables_count}</td>
                <td>${t211StatusBadge(b.status)}</td>
                <td>${t211FmtDate(b.created_at)}</td>
                <td>${esc(b.created_by_name || '—')}</td>
                <td>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                        ${b.file_exists
                            ? `<a href="api/router.php?action=admin_db_backup_download&id=${b.id}&csrf_token=<?= csrf_token() ?>" class="t211-btn t211-btn-outline t211-btn-sm">⬇ Download</a>`
                            : `<span style="font-size:10px;color:var(--danger);">File missing</span>`
                        }
                        <button class="t211-btn t211-btn-danger t211-btn-sm" onclick="t211DeleteBackup(${b.id}, '${esc(b.filename)}')">🗑</button>
                    </div>
                </td>
            </tr>`).join('')}
            </tbody>
        </table>
        </div>`;
    } catch(e) {
        wrap.innerHTML = `<div class="t211-empty">Failed to load: ${e.message}</div>`;
    }
}

// ── Create backup ─────────────────────────────────────────────────────────
async function t211CreateBackup() {
    const btn  = document.getElementById('btn-create-backup');
    const txt  = document.getElementById('create-btn-text');
    const type = document.getElementById('backup-type').value;

    btn.disabled = true;
    txt.innerHTML = '<span class="t211-spin"></span> Creating...';

    try {
        const r = await API.post('admin_db_backup_create', { backup_type: type });
        if (r.ok) {
            showToast(`✅ Backup created — ${r.data.file_size_human}`);
            t211LoadBackups();
            t211LoadStats();
        } else {
            showToast('❌ ' + r.message, 'err');
        }
    } catch(e) {
        showToast('Error: ' + e.message, 'err');
    } finally {
        btn.disabled = false;
        txt.innerHTML = '⬇️ Create Backup';
    }
}

// ── Delete backup ─────────────────────────────────────────────────────────
async function t211DeleteBackup(id, filename) {
    if (!confirm(`Delete backup: ${filename}?\nThis cannot be undone.`)) return;
    try {
        const r = await API.post('admin_db_backup_delete', { id });
        if (r.ok) {
            showToast('Backup deleted.');
            t211LoadBackups();
            t211LoadStats();
        } else {
            showToast('Error: ' + r.message, 'err');
        }
    } catch(e) {
        showToast('Error: ' + e.message, 'err');
    }
}

// ── File drag/drop ────────────────────────────────────────────────────────
function t211DragOver(e) { e.preventDefault(); document.getElementById('drop-zone').classList.add('drag-over'); }
function t211DragLeave(e) { document.getElementById('drop-zone').classList.remove('drag-over'); }
function t211Drop(e) {
    e.preventDefault();
    document.getElementById('drop-zone').classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) { document.getElementById('sql-file-input').files = e.dataTransfer.files; t211FileSelected({ files: e.dataTransfer.files }); }
}

function t211FileSelected(input) {
    const file = input.files[0];
    if (!file) return;
    const info = document.getElementById('file-info');
    document.getElementById('fi-name').textContent = file.name;
    document.getElementById('fi-size').textContent = t211FmtBytes(file.size);
    info.style.display = 'block';
    document.getElementById('btn-upload-file').disabled = false;
    t211_upload_temp = null;
    document.getElementById('btn-restore-upload').style.display = 'none';
    document.getElementById('restore-confirm-wrap-upload').style.display = 'none';
}

// ── Upload file ───────────────────────────────────────────────────────────
async function t211UploadFile() {
    const fileInput = document.getElementById('sql-file-input');
    const btn = document.getElementById('btn-upload-file');
    if (!fileInput.files[0]) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="t211-spin"></span> Uploading...';

    const fd = new FormData();
    fd.append('action', 'admin_db_restore_upload');
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    fd.append('sql_file', fileInput.files[0]);

    try {
        const resp = await fetch('<?= APP_URL ?>/api/router.php', { method: 'POST', body: fd });
        const r    = await resp.json();
        if (r.ok) {
            t211_upload_temp = r.data.temp_filename;
            showToast('File uploaded. Now confirm and run restore.');
            document.getElementById('restore-confirm-wrap-upload').style.display = 'block';
            document.getElementById('btn-restore-upload').style.display = 'inline-flex';
        } else {
            showToast('Upload failed: ' + r.message, 'err');
        }
    } catch(e) {
        showToast('Upload error: ' + e.message, 'err');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '📤 Upload File';
    }
}

function t211CheckConfirm(type) {
    const val = document.getElementById('restore-confirm-input-' + type).value;
    if (type === 'upload') {
        document.getElementById('btn-restore-upload').disabled = (val !== 'RESTORE');
    }
}

// ── Restore from uploaded file ────────────────────────────────────────────
async function t211RestoreUpload() {
    if (!t211_upload_temp) { showToast('Please upload a file first.', 'err'); return; }
    const btn = document.getElementById('btn-restore-upload');
    btn.disabled = true;
    btn.innerHTML = '<span class="t211-spin"></span> Restoring...';
    try {
        const r = await API.post('admin_db_restore_run', {
            source: 'upload',
            temp_filename: t211_upload_temp,
            confirm: 'RESTORE',
        });
        if (r.ok) {
            showToast(`✅ Restored! Tables: ${r.data.tables_restored}`);
            document.getElementById('restore-confirm-input-upload').value = '';
            document.getElementById('btn-restore-upload').style.display = 'none';
            document.getElementById('restore-confirm-wrap-upload').style.display = 'none';
            document.getElementById('file-info').style.display = 'none';
            t211_upload_temp = null;
        } else {
            showToast('Restore failed: ' + r.message, 'err');
        }
    } catch(e) {
        showToast('Error: ' + e.message, 'err');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '♻️ Run Restore';
    }
}

// ── Load restore list (backup library) ───────────────────────────────────
async function t211LoadRestoreList() {
    const wrap = document.getElementById('restore-list-wrap');
    wrap.innerHTML = '<div class="t211-empty"><div class="t211-empty-icon">📚</div>Loading...</div>';
    try {
        const r = await API.get('admin_db_backup_list');
        if (!r.ok) { wrap.innerHTML = `<div class="t211-empty">Error: ${r.message}</div>`; return; }
        const backups = (r.data.backups || []).filter(b => b.status === 'completed' && b.file_exists);
        if (!backups.length) {
            wrap.innerHTML = '<div class="t211-empty"><div class="t211-empty-icon">📚</div>No completed backups available.</div>';
            return;
        }
        wrap.innerHTML = `
        <div class="t211-tbl-wrap">
        <table class="t211-tbl">
            <thead><tr>
                <th>Filename</th><th>Type</th><th>Size</th><th>Tables</th><th>Created</th><th>Action</th>
            </tr></thead>
            <tbody>
            ${backups.map(b => `
            <tr>
                <td class="mono" style="font-size:10px;">${esc(b.filename)}</td>
                <td><span class="t211-type">${esc(b.backup_type)}</span></td>
                <td>${esc(b.file_size_human)}</td>
                <td>${b.tables_count}</td>
                <td>${t211FmtDate(b.created_at)}</td>
                <td>
                    <button class="t211-btn t211-btn-danger t211-btn-sm"
                        onclick="t211OpenRestoreModal(${b.id}, '${esc(b.filename)}', '${b.created_at}')">
                        ♻️ Restore
                    </button>
                </td>
            </tr>`).join('')}
            </tbody>
        </table>
        </div>`;
    } catch(e) {
        wrap.innerHTML = `<div class="t211-empty">Failed: ${e.message}</div>`;
    }
}

// ── Restore modal ─────────────────────────────────────────────────────────
function t211OpenRestoreModal(id, filename, date) {
    t211_restore_backup_id = id;
    document.getElementById('rm-filename').textContent = filename;
    document.getElementById('rm-date').textContent = t211FmtDate(date);
    document.getElementById('rm-confirm-input').value = '';
    document.getElementById('btn-rm-confirm').disabled = true;
    document.getElementById('restore-modal').classList.add('open');
}

function closeRestoreModal() {
    document.getElementById('restore-modal').classList.remove('open');
    t211_restore_backup_id = null;
}

function t211CheckConfirmModal() {
    const val = document.getElementById('rm-confirm-input').value;
    document.getElementById('btn-rm-confirm').disabled = (val !== 'RESTORE');
}

async function t211DoRestore() {
    if (!t211_restore_backup_id) return;
    const btn = document.getElementById('btn-rm-confirm');
    btn.disabled = true;
    btn.innerHTML = '<span class="t211-spin"></span> Restoring...';
    try {
        const r = await API.post('admin_db_restore_run', {
            source: 'backup',
            backup_id: t211_restore_backup_id,
            confirm: 'RESTORE',
        });
        if (r.ok) {
            showToast(`✅ Restore complete! Tables: ${r.data.tables_restored}`);
            closeRestoreModal();
        } else {
            showToast('Restore failed: ' + r.message, 'err');
            btn.disabled = false;
            btn.innerHTML = '♻️ Run Restore';
        }
    } catch(e) {
        showToast('Error: ' + e.message, 'err');
        btn.disabled = false;
        btn.innerHTML = '♻️ Run Restore';
    }
}

// ── Restore log ───────────────────────────────────────────────────────────
async function t211LoadRestoreLog() {
    const wrap = document.getElementById('restlog-wrap');
    wrap.innerHTML = '<div class="t211-empty"><div class="t211-empty-icon">📋</div>Loading...</div>';
    try {
        const r = await API.get('admin_db_restore_log');
        if (!r.ok) { wrap.innerHTML = `<div class="t211-empty">Error: ${r.message}</div>`; return; }
        const logs = r.data.logs || [];
        if (!logs.length) {
            wrap.innerHTML = '<div class="t211-empty"><div class="t211-empty-icon">📋</div>No restore history yet.</div>';
            return;
        }
        wrap.innerHTML = `
        <div class="t211-tbl-wrap">
        <table class="t211-tbl">
            <thead><tr>
                <th>#</th><th>File</th><th>Tables Restored</th>
                <th>Status</th><th>By</th><th>Date</th><th>Error</th>
            </tr></thead>
            <tbody>
            ${logs.map(l => `
            <tr>
                <td class="mono">${l.id}</td>
                <td class="mono" style="font-size:10px;">${esc(l.filename)}</td>
                <td>${l.tables_restored}</td>
                <td>${t211StatusBadge(l.status)}</td>
                <td>${esc(l.restored_by_name || '—')}</td>
                <td>${t211FmtDate(l.created_at)}</td>
                <td style="font-size:10px;color:var(--danger);max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="${esc(l.error_msg || '')}">
                    ${esc(l.error_msg || '')}
                </td>
            </tr>`).join('')}
            </tbody>
        </table>
        </div>`;
    } catch(e) {
        wrap.innerHTML = `<div class="t211-empty">Failed: ${e.message}</div>`;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────
function t211FmtDate(str) {
    if (!str) return '—';
    const d = new Date(str);
    return d.toLocaleDateString('en-IN') + ' ' + d.toLocaleTimeString('en-IN', { hour:'2-digit', minute:'2-digit' });
}

function t211FmtBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576)    return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024)       return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
}

function t211StatusBadge(status) {
    const map = { completed: 'ok', failed: 'fail', in_progress: 'prog' };
    const label = { completed: '✓ Done', failed: '✗ Failed', in_progress: '… Running' };
    const cls = map[status] || 'prog';
    return `<span class="t211-badge t211-badge-${cls}">${label[status] || status}</span>`;
}

function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────────
t211LoadStats();
t211LoadBackups();
</script>

<?php include APP_ROOT . '/templates/footer.php'; ?>
