<?php
/**
 * WealthDash — Global Settings Control Page [t52]
 * File: templates/pages/admin_settings.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
$user = require_admin();
$csrf = csrf_token();
?>

<div class="page-header">
    <div class="ph-left">
        <h1 class="page-title">⚙️ Global Settings</h1>
        <p class="page-sub">Application-wide configuration — auth, display, email, performance, security.</p>
    </div>
    <div class="ph-right">
        <button class="btn btn-outline btn-sm" id="btnMaintToggle">🔧 Maintenance Mode</button>
        <button class="btn btn-outline btn-sm" id="btnSettingsAudit">📋 Audit Log</button>
    </div>
</div>

<!-- Maintenance Banner -->
<div id="maintBanner" style="display:none;background:color-mix(in srgb,var(--danger) 15%,transparent);
     border:1px solid var(--danger);border-radius:8px;padding:10px 16px;margin-bottom:12px;
     font-size:13px;color:var(--danger);font-weight:600">
    ⚠️ Maintenance mode is currently ACTIVE — non-admin users see a maintenance page.
</div>

<!-- Group tabs -->
<div class="card">
    <div class="card-tabs" id="settingGroupTabs">
        <div class="tbl-loading" style="padding:12px">Loading groups…</div>
    </div>

    <div id="settingsTabContent">
        <div class="tbl-loading" style="padding:24px">Loading settings…</div>
    </div>
</div>

<!-- Audit Log Modal -->
<div id="modalAudit" class="modal" style="display:none">
    <div class="modal-overlay" onclick="document.getElementById('modalAudit').style.display='none'"></div>
    <div class="modal-box modal-lg">
        <div class="modal-hdr">
            <h3>Settings Audit Log</h3>
            <button class="modal-close" onclick="document.getElementById('modalAudit').style.display='none'">✕</button>
        </div>
        <div class="modal-body" style="max-height:480px;overflow-y:auto">
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>Time</th><th>Key</th><th>Old Value</th><th>New Value</th></tr></thead>
                    <tbody id="auditLogBody"><tr><td colspan="4" class="tbl-loading">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Test Email Modal -->
<div id="modalTestEmail" class="modal" style="display:none">
    <div class="modal-overlay" onclick="document.getElementById('modalTestEmail').style.display='none'"></div>
    <div class="modal-box modal-sm">
        <div class="modal-hdr">
            <h3>Send Test Email</h3>
            <button class="modal-close" onclick="document.getElementById('modalTestEmail').style.display='none'">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Send test email to</label>
                <input type="email" id="testEmailTo" class="form-control" value="<?= htmlspecialchars($user['email']) ?>">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="document.getElementById('modalTestEmail').style.display='none'">Cancel</button>
            <button class="btn btn-primary" onclick="WDSettings.sendTestEmail()">Send</button>
        </div>
    </div>
</div>

<script>
const WDSettings = (() => {
    const API  = '/api/router.php';
    const CSRF = '<?= $csrf ?>';
    let _grouped = {};
    let _groups  = [];
    let _curGroup = '';

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

    const GROUP_ICONS = {
        general:'🏠', auth:'🔐', market:'📈', display:'🎨',
        performance:'⚡', security:'🛡', email:'✉️',
    };

    // ── Load All Settings ─────────────────────────────────────────────────────
    async function load() {
        const d = await api('gs_list');
        _grouped = d.data.grouped || {};
        _groups  = d.data.groups  || [];
        renderGroupTabs();
        if (_groups.length) showGroup(_groups[0]);
        checkMaintenance();
    }

    function renderGroupTabs() {
        const tabs = document.getElementById('settingGroupTabs');
        tabs.innerHTML = _groups.map(g =>
            `<button class="ctab" data-group="${g}" onclick="WDSettings.showGroup('${g}')">
                ${GROUP_ICONS[g]||'⚙'} ${g.charAt(0).toUpperCase()+g.slice(1)}
             </button>`
        ).join('');
    }

    function showGroup(group) {
        _curGroup = group;
        document.querySelectorAll('#settingGroupTabs .ctab').forEach(t => {
            t.classList.toggle('active', t.dataset.group === group);
        });
        const settings = _grouped[group] || [];
        const content  = document.getElementById('settingsTabContent');

        if (!settings.length) {
            content.innerHTML = '<div class="tbl-empty" style="padding:24px">No settings in this group.</div>';
            return;
        }

        content.innerHTML = `
        <form id="settingsForm_${group}" onsubmit="WDSettings.saveBulk(event,'${group}')">
        <div class="settings-grid">
            ${settings.map(s => renderSetting(s)).join('')}
        </div>
        <div style="padding:14px 16px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:center">
            <button type="submit" class="btn btn-primary">💾 Save ${group.charAt(0).toUpperCase()+group.slice(1)} Settings</button>
            ${group === 'email' ? `<button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('modalTestEmail').style.display='flex'">📧 Test Email</button>` : ''}
            <span id="saveMsg_${group}" style="font-size:12px;color:var(--done);margin-left:8px"></span>
        </div>
        </form>`;
    }

    function renderSetting(s) {
        const locked = s.is_locked;
        const id = `s_${s.setting_key}`;
        let input = '';

        if (locked) {
            input = `<div class="form-static mono">${esc(s.setting_val)} <span style="color:var(--muted);font-size:10px">(locked)</span></div>`;
        } else if (s.setting_type === 'boolean') {
            const checked = s.setting_val === 'true' ? 'checked' : '';
            input = `<label class="toggle-label">
                        <input type="checkbox" name="${s.setting_key}" id="${id}" ${checked}
                               class="setting-toggle" value="true">
                        <span class="toggle-slider"></span>
                     </label>`;
        } else if (s.setting_type === 'textarea') {
            input = `<textarea name="${s.setting_key}" id="${id}" class="form-control" rows="3">${esc(s.setting_val)}</textarea>`;
        } else {
            const t = s.setting_type === 'integer' ? 'number' : 'text';
            input = `<input type="${t}" name="${s.setting_key}" id="${id}"
                            class="form-control" value="${esc(s.setting_val)}"
                            ${s.setting_type==='integer'?'min="0"':''}
                            ${locked?'disabled':''}
                            style="max-width:320px">`;
        }

        return `
        <div class="setting-row ${locked?'setting-locked':''}">
            <div class="setting-info">
                <label for="${id}" class="setting-label">${esc(s.label||s.setting_key)}</label>
                ${s.description ? `<div class="setting-desc">${esc(s.description)}</div>` : ''}
                <div class="setting-key mono">${esc(s.setting_key)}</div>
            </div>
            <div class="setting-control">${input}</div>
        </div>`;
    }

    // ── Save Bulk ─────────────────────────────────────────────────────────────
    async function saveBulk(e, group) {
        e.preventDefault();
        const form     = document.getElementById(`settingsForm_${group}`);
        const settings = {};
        const inputs   = form.querySelectorAll('input[name],select[name],textarea[name]');

        inputs.forEach(el => {
            if (el.disabled) return;
            if (el.type === 'checkbox') {
                settings[el.name] = el.checked ? 'true' : 'false';
            } else {
                settings[el.name] = el.value;
            }
        });

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true; btn.textContent = 'Saving…';
        try {
            await api('gs_save_bulk', { settings }, 'POST');
            const msg = document.getElementById(`saveMsg_${group}`);
            msg.textContent = '✓ Saved';
            setTimeout(() => { msg.textContent = ''; }, 3000);
            // Reload to reflect canonical values
            const d = await api('gs_list');
            _grouped = d.data.grouped || {};
            showGroup(group);
            checkMaintenance();
        } catch(err) { alert(err.message); }
        finally { btn.disabled = false; btn.textContent = `💾 Save ${group.charAt(0).toUpperCase()+group.slice(1)} Settings`; }
    }

    // ── Maintenance Toggle ────────────────────────────────────────────────────
    async function toggleMaintenance() {
        const cur = _grouped['general']?.find(s => s.setting_key === 'maintenance_mode');
        const active = cur?.setting_val === 'true';
        const msg = active
            ? 'Disable maintenance mode? Site will be accessible to all users.'
            : '⚠️ Enable maintenance mode? Non-admin users will be blocked.';
        if (!confirm(msg)) return;
        const d = await api('gs_maintenance_toggle', {}, 'POST');
        alert(d.message);
        await load();
    }

    function checkMaintenance() {
        const cur = _grouped['general']?.find(s => s.setting_key === 'maintenance_mode');
        document.getElementById('maintBanner').style.display =
            cur?.setting_val === 'true' ? '' : 'none';
    }

    // ── Audit Log ─────────────────────────────────────────────────────────────
    async function showAuditLog() {
        document.getElementById('modalAudit').style.display = 'flex';
        const d = await api('gs_audit');
        const rows = d.data.log || [];
        const tb = document.getElementById('auditLogBody');
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="4" class="tbl-empty">No audit log entries.</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(r => {
            const old = r.old_values ? JSON.parse(r.old_values) : {};
            const nw  = r.new_values ? JSON.parse(r.new_values) : {};
            const key = Object.keys({...old,...nw})[0] || '—';
            return `<tr>
                <td style="font-size:11px;color:var(--muted)">${r.created_at}</td>
                <td class="mono">${esc(key)}</td>
                <td style="color:var(--danger);font-size:11px">${esc(old[key]??'—')}</td>
                <td style="color:var(--done);font-size:11px">${esc(nw[key]??'—')}</td>
            </tr>`;
        }).join('');
    }

    // ── Test Email ────────────────────────────────────────────────────────────
    async function sendTestEmail() {
        const to = document.getElementById('testEmailTo').value.trim();
        if (!to) { alert('Enter an email address.'); return; }
        try {
            const d = await api('gs_test_email', { to }, 'POST');
            alert(d.message);
            document.getElementById('modalTestEmail').style.display = 'none';
        } catch(e) { alert(e.message); }
    }

    async function init() {
        document.getElementById('btnMaintToggle').addEventListener('click', toggleMaintenance);
        document.getElementById('btnSettingsAudit').addEventListener('click', showAuditLog);
        await load();
    }

    return { init, showGroup, saveBulk, toggleMaintenance, showAuditLog, sendTestEmail };
})();

document.addEventListener('DOMContentLoaded', WDSettings.init);
</script>

<style>
.settings-grid { padding:0; }
.setting-row { display:grid; grid-template-columns:1fr 360px; gap:16px; padding:14px 16px;
               border-bottom:1px solid var(--border); align-items:start; }
.setting-row:last-child { border-bottom:none; }
.setting-row:hover { background:var(--surface2); }
.setting-locked { opacity:.6; }
.setting-label { font-size:13px; font-weight:600; color:var(--text); }
.setting-desc { font-size:11px; color:var(--muted); margin-top:3px; }
.setting-key { font-size:10px; color:color-mix(in srgb,var(--muted) 70%,transparent);
               font-family:'Courier New',monospace; margin-top:4px; }
.setting-control { display:flex; align-items:center; min-height:36px; }
/* Toggle switch */
.toggle-label { position:relative; display:inline-flex; align-items:center; cursor:pointer; }
.toggle-label input { position:absolute; opacity:0; width:0; height:0; }
.toggle-slider { width:44px; height:24px; background:var(--surface3); border-radius:12px;
                 border:1px solid var(--border); transition:.2s; position:relative; }
.toggle-slider::after { content:''; position:absolute; left:3px; top:3px; width:16px; height:16px;
                        background:var(--muted); border-radius:50%; transition:.2s; }
.toggle-label input:checked + .toggle-slider { background:var(--accent2); border-color:var(--accent2); }
.toggle-label input:checked + .toggle-slider::after { left:23px; background:#fff; }
@media(max-width:700px){ .setting-row{ grid-template-columns:1fr; } }
</style>
