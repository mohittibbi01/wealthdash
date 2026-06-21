<?php
/**
 * WealthDash — Multi-user Management Page [t50]
 * File: templates/pages/admin_users.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
$user = require_admin();
$csrf = csrf_token();
?>

<div class="page-header">
    <div class="ph-left">
        <h1 class="page-title">👥 User Management</h1>
        <p class="page-sub">Add, edit, suspend users and manage invitations.</p>
    </div>
    <div class="ph-right">
        <button class="btn btn-outline btn-sm" id="btnInviteUser">✉ Invite User</button>
        <button class="btn btn-primary" id="btnAddUser">+ Add User</button>
    </div>
</div>

<!-- Stats -->
<div class="summary-cards" id="userStatsCards">
    <div class="scard scard-load"><div class="scard-skeleton"></div></div>
</div>

<!-- Filters -->
<div class="card mt-12">
    <div class="card-tabs">
        <button class="ctab active" data-tab="ut-users">Users</button>
        <button class="ctab" data-tab="ut-invitations">Invitations</button>
        <button class="ctab" data-tab="ut-activity">Activity Log</button>
    </div>

    <!-- Users Tab -->
    <div id="ut-users" class="ctab-pane active">
        <div class="tbl-toolbar">
            <input type="text" id="userSearch" class="form-control-sm" placeholder="Search name or email…" style="width:220px">
            <select id="userRoleFilter" class="form-select-sm">
                <option value="">All Roles</option>
                <option value="admin">Admin</option>
                <option value="user">User</option>
            </select>
            <select id="userStatusFilter" class="form-select-sm">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="pending">Pending</option>
            </select>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Portfolio</th>
                        <th>Last Login</th>
                        <th>Sessions</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="userTableBody">
                    <tr><td colspan="8" class="tbl-loading">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="tbl-pagination" id="userPager"></div>
    </div>

    <!-- Invitations Tab -->
    <div id="ut-invitations" class="ctab-pane">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Email</th><th>Role</th><th>Invited By</th>
                        <th>Status</th><th>Expires</th><th>Invite URL</th>
                    </tr>
                </thead>
                <tbody id="inviteTableBody">
                    <tr><td colspan="6" class="tbl-empty">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Activity Tab -->
    <div id="ut-activity" class="ctab-pane">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr><th>Time</th><th>User</th><th>Action</th><th>IP</th></tr>
                </thead>
                <tbody id="activityBody">
                    <tr><td colspan="4" class="tbl-empty">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD / EDIT USER MODAL -->
<div id="modalUser" class="modal" style="display:none">
    <div class="modal-overlay" onclick="WDUsers.closeModal()"></div>
    <div class="modal-box modal-md">
        <div class="modal-hdr">
            <h3 id="modalUserTitle">Add User</h3>
            <button class="modal-close" onclick="WDUsers.closeModal()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="userId">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Full Name <span class="req">*</span></label>
                    <input type="text" id="userName" class="form-control">
                </div>
                <div class="form-group">
                    <label>Email <span class="req">*</span></label>
                    <input type="email" id="userEmail" class="form-control">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Role</label>
                    <select id="userRole" class="form-control">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group" id="userStatusGroup" style="display:none">
                    <label>Status</label>
                    <select id="userStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label id="passLabel">Password <span class="req">*</span></label>
                <input type="password" id="userPassword" class="form-control" placeholder="Min 8 characters">
                <small class="form-hint" id="passHint"></small>
            </div>
            <div class="form-group">
                <label>Notes (Admin only)</label>
                <textarea id="userNotes" class="form-control" rows="2"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="WDUsers.closeModal()">Cancel</button>
            <button class="btn btn-primary" id="btnSaveUser" onclick="WDUsers.saveUser()">Save</button>
        </div>
    </div>
</div>

<!-- INVITE MODAL -->
<div id="modalInvite" class="modal" style="display:none">
    <div class="modal-overlay" onclick="WDUsers.closeInvite()"></div>
    <div class="modal-box modal-sm">
        <div class="modal-hdr">
            <h3>Invite User</h3>
            <button class="modal-close" onclick="WDUsers.closeInvite()">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Email Address <span class="req">*</span></label>
                <input type="email" id="inviteEmail" class="form-control">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select id="inviteRole" class="form-control">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div id="inviteResult" style="display:none">
                <div class="form-group">
                    <label>Invite URL (share with user)</label>
                    <div style="display:flex;gap:6px">
                        <input type="text" id="inviteUrl" class="form-control mono" readonly style="font-size:11px">
                        <button class="btn btn-outline btn-sm" onclick="WDUsers.copyInvite()">Copy</button>
                    </div>
                    <small class="form-hint">Valid for 48 hours.</small>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="WDUsers.closeInvite()">Close</button>
            <button class="btn btn-primary" id="btnSendInvite" onclick="WDUsers.sendInvite()">Generate Invite</button>
        </div>
    </div>
</div>

<!-- USER DETAIL DRAWER -->
<div id="drawerUser" class="drawer" style="display:none">
    <div class="drawer-overlay" onclick="WDUsers.closeDrawer()"></div>
    <div class="drawer-box">
        <div class="drawer-hdr">
            <h3 id="drawerUserName">User Details</h3>
            <button class="modal-close" onclick="WDUsers.closeDrawer()">✕</button>
        </div>
        <div id="drawerUserBody" class="drawer-body"></div>
    </div>
</div>

<script>
const WDUsers = (() => {
    const API  = '/api/router.php';
    const CSRF = '<?= $csrf ?>';
    let _page  = 1;
    let _search = '', _role = '', _status = '';

    async function api(action, params = {}, method = 'GET') {
        let url = API + '?action=' + action;
        const opts = { method, credentials: 'same-origin',
                       headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (method === 'GET') {
            if (Object.keys(params).length) url += '&' + new URLSearchParams(params).toString();
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({ ...params, _csrf_token: CSRF });
        }
        const r = await fetch(url, opts);
        const d = await r.json();
        if (!d.success) throw new Error(d.message || 'API error');
        return d;
    }

    function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

    // ── Stats ────────────────────────────────────────────────────────────────
    async function loadStats() {
        const d = await api('mu_list', { page: 1 });
        const s = d.data.stats || {};
        document.getElementById('userStatsCards').innerHTML = `
            <div class="scard"><div class="scard-val">${s.total||0}</div><div class="scard-lbl">Total Users</div></div>
            <div class="scard"><div class="scard-val">${s.admins||0}</div><div class="scard-lbl">Admins</div></div>
            <div class="scard"><div class="scard-val text-gain">${s.active||0}</div><div class="scard-lbl">Active</div></div>
            <div class="scard"><div class="scard-val text-loss">${s.suspended||0}</div><div class="scard-lbl">Suspended</div></div>
            <div class="scard"><div class="scard-val">${s.active_30d||0}</div><div class="scard-lbl">Active (30d)</div></div>`;
    }

    // ── User List ─────────────────────────────────────────────────────────────
    async function loadUsers(reset = false) {
        if (reset) _page = 1;
        const d = await api('mu_list', { page: _page, search: _search, role: _role, status: _status });
        const { users = [], total, total_pages } = d.data;
        const tb = document.getElementById('userTableBody');

        if (!users.length) {
            tb.innerHTML = '<tr><td colspan="8" class="tbl-empty">No users found.</td></tr>';
            document.getElementById('userPager').innerHTML = '';
            return;
        }

        tb.innerHTML = users.map(u => `
            <tr>
                <td>
                    <div style="font-weight:600">${esc(u.name)}</div>
                    <div class="tbl-sub">${esc(u.email)}</div>
                    ${u.email_verified ? '' : '<span class="pill pill-xs pill-warn">Unverified</span>'}
                </td>
                <td><span class="pill pill-xs ${u.role==='admin'?'pill-accent':'pill-muted'}">${u.role}</span></td>
                <td>
                    <span class="pill pill-xs ${u.status==='active'?'pill-done':u.status==='suspended'?'pill-danger':'pill-warn'}">
                        ${u.status||'active'}
                    </span>
                </td>
                <td class="mono" style="font-size:11px">${u.portfolio_count||0}</td>
                <td style="font-size:11px;color:var(--muted)">${fmtDate(u.last_login)}</td>
                <td>
                    ${(u.active_sessions>0)
                        ? `<span class="pill pill-xs pill-done">● ${u.active_sessions} live</span>`
                        : '<span style="color:var(--muted);font-size:11px">—</span>'}
                </td>
                <td style="font-size:11px;color:var(--muted)">${fmtDate(u.created_at)}</td>
                <td>
                    <div class="tbl-actions">
                        <button class="tbl-btn" title="View" onclick="WDUsers.viewUser(${u.id})">👁</button>
                        <button class="tbl-btn" title="Edit" onclick="WDUsers.openModal(${u.id})">✎</button>
                        <button class="tbl-btn ${u.status==='active'?'tbl-btn-warn':'tbl-btn-ok'}" title="${u.status==='active'?'Suspend':'Activate'}"
                            onclick="WDUsers.toggleStatus(${u.id})">${u.status==='active'?'⏸':'▶'}</button>
                        <button class="tbl-btn tbl-btn-danger" title="Delete" onclick="WDUsers.deleteUser(${u.id})">✕</button>
                    </div>
                </td>
            </tr>`).join('');

        // Pagination
        const pg = document.getElementById('userPager');
        pg.innerHTML = '';
        if (_page > 1) {
            const b = document.createElement('button'); b.className = 'btn btn-outline btn-sm';
            b.textContent = '← Prev'; b.onclick = () => { _page--; loadUsers(); }; pg.appendChild(b);
        }
        const info = document.createElement('span');
        info.style.cssText = 'margin:0 10px;font-size:11px;color:var(--muted)';
        info.textContent = `Page ${_page} of ${total_pages} (${total} users)`;
        pg.appendChild(info);
        if (_page < total_pages) {
            const b = document.createElement('button'); b.className = 'btn btn-outline btn-sm';
            b.textContent = 'Next →'; b.onclick = () => { _page++; loadUsers(); }; pg.appendChild(b);
        }
    }

    // ── View User Drawer ──────────────────────────────────────────────────────
    async function viewUser(id) {
        const d = await api('mu_get', { id });
        const u = d.data.user;
        const sessions = d.data.sessions || [];
        const activity = d.data.activity || [];
        const pf = d.data.portfolio;

        document.getElementById('drawerUserName').textContent = u.name;
        document.getElementById('drawerUserBody').innerHTML = `
            <div class="detail-section">
                <div class="info-grid-2">
                    <div class="info-row"><span class="info-key">Email</span><span class="info-val">${esc(u.email)}</span></div>
                    <div class="info-row"><span class="info-key">Role</span><span class="info-val">${u.role}</span></div>
                    <div class="info-row"><span class="info-key">Status</span><span class="info-val">${u.status||'active'}</span></div>
                    <div class="info-row"><span class="info-key">Theme</span><span class="info-val">${u.theme}</span></div>
                    <div class="info-row"><span class="info-key">Last Login</span><span class="info-val">${fmtDate(u.last_login)}</span></div>
                    <div class="info-row"><span class="info-key">Login Count</span><span class="info-val">${u.login_count||0}</span></div>
                    <div class="info-row"><span class="info-key">Joined</span><span class="info-val">${fmtDate(u.created_at)}</span></div>
                </div>
            </div>
            ${pf ? `<div class="detail-section">
                <div class="section-title">Portfolio</div>
                <div class="info-grid-2">
                    <div class="info-row"><span class="info-key">Name</span><span class="info-val">${esc(pf.name)}</span></div>
                    <div class="info-row"><span class="info-key">MF Holdings</span><span class="info-val">${pf.mf_count}</span></div>
                    <div class="info-row"><span class="info-key">Stocks</span><span class="info-val">${pf.stock_count}</span></div>
                </div></div>` : ''}
            <div class="detail-section">
                <div class="section-title">Active Sessions (${sessions.length})</div>
                ${sessions.length ? sessions.map(s => `
                    <div style="font-size:11px;padding:4px 0;border-bottom:1px solid var(--border)">
                        <span class="mono">${esc(s.ip_address||'—')}</span>
                        <span style="color:var(--muted);margin-left:8px">${fmtDate(s.last_activity)}</span>
                    </div>`).join('') : '<div style="color:var(--muted);font-size:12px">No active sessions</div>'}
                ${sessions.length ? `<button class="btn btn-outline btn-sm mt-8" onclick="WDUsers.killSessions(${u.id})">Kill All Sessions</button>` : ''}
            </div>
            <div class="detail-section">
                <div class="section-title">Recent Activity</div>
                ${activity.map(a => `
                    <div style="font-size:11px;padding:3px 0;color:var(--muted)">
                        ${a.created_at} — ${esc(a.action)}
                        ${a.ip_address ? `<span class="mono" style="margin-left:4px">${esc(a.ip_address)}</span>` : ''}
                    </div>`).join('') || '<div style="color:var(--muted);font-size:12px">No activity logged</div>'}
            </div>
            <div style="padding:12px;border-top:1px solid var(--border);display:flex;gap:8px">
                <button class="btn btn-outline btn-sm" onclick="WDUsers.openModal(${u.id})">✎ Edit</button>
                <button class="btn btn-outline btn-sm" onclick="WDUsers.resetPwPrompt(${u.id})">🔑 Reset Password</button>
                <button class="btn btn-outline btn-sm" onclick="WDUsers.toggleStatus(${u.id})">⏸ Toggle Status</button>
            </div>`;
        document.getElementById('drawerUser').style.display = 'flex';
    }

    function closeDrawer() { document.getElementById('drawerUser').style.display = 'none'; }

    // ── Add / Edit Modal ──────────────────────────────────────────────────────
    async function openModal(id = 0) {
        document.getElementById('userId').value = '';
        document.getElementById('userName').value = '';
        document.getElementById('userEmail').value = '';
        document.getElementById('userEmail').disabled = false;
        document.getElementById('userRole').value = 'user';
        document.getElementById('userPassword').value = '';
        document.getElementById('userNotes').value = '';
        document.getElementById('userStatusGroup').style.display = 'none';
        document.getElementById('passLabel').innerHTML = 'Password <span class="req">*</span>';
        document.getElementById('passHint').textContent = '';
        document.getElementById('modalUserTitle').textContent = 'Add User';

        if (id) {
            document.getElementById('modalUserTitle').textContent = 'Edit User';
            document.getElementById('userStatusGroup').style.display = '';
            document.getElementById('passLabel').innerHTML = 'New Password';
            document.getElementById('passHint').textContent = 'Leave blank to keep current password.';
            const d = await api('mu_get', { id });
            const u = d.data.user;
            document.getElementById('userId').value    = u.id;
            document.getElementById('userName').value  = u.name;
            document.getElementById('userEmail').value = u.email;
            document.getElementById('userEmail').disabled = true;
            document.getElementById('userRole').value   = u.role;
            document.getElementById('userStatus').value = u.status || 'active';
            document.getElementById('userNotes').value  = u.notes || '';
        }
        document.getElementById('modalUser').style.display = 'flex';
    }

    function closeModal() { document.getElementById('modalUser').style.display = 'none'; }

    async function saveUser() {
        const id = document.getElementById('userId').value;
        const action = id ? 'mu_edit' : 'mu_add';
        const params = {
            id:           id,
            name:         document.getElementById('userName').value.trim(),
            email:        document.getElementById('userEmail').value.trim(),
            role:         document.getElementById('userRole').value,
            status:       document.getElementById('userStatus').value,
            password:     document.getElementById('userPassword').value,
            new_password: document.getElementById('userPassword').value,
            notes:        document.getElementById('userNotes').value.trim(),
        };
        if (!params.name || !params.email) { alert('Name and email required.'); return; }
        if (!id && params.password.length < 8) { alert('Password must be at least 8 characters.'); return; }
        const btn = document.getElementById('btnSaveUser');
        btn.disabled = true; btn.textContent = 'Saving…';
        try {
            await api(action, params, 'POST');
            closeModal();
            await Promise.all([loadStats(), loadUsers(true)]);
        } catch(e) { alert(e.message); }
        finally { btn.disabled = false; btn.textContent = 'Save'; }
    }

    // ── Toggle Status ─────────────────────────────────────────────────────────
    async function toggleStatus(id) {
        if (!confirm('Toggle this user\'s active/suspended status?')) return;
        await api('mu_toggle_status', { id }, 'POST');
        await Promise.all([loadStats(), loadUsers()]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    async function deleteUser(id) {
        if (!confirm('Permanently delete this user and ALL their data? This cannot be undone.')) return;
        await api('mu_delete', { id }, 'POST');
        await Promise.all([loadStats(), loadUsers()]);
    }

    // ── Kill Sessions ─────────────────────────────────────────────────────────
    async function killSessions(id) {
        await api('mu_kill_sessions', { id }, 'POST');
        alert('Sessions terminated.');
        closeDrawer();
        loadUsers();
    }

    // ── Reset Password Prompt ─────────────────────────────────────────────────
    async function resetPwPrompt(id) {
        const pw = prompt('Enter new password for this user (min 8 chars):');
        if (!pw) return;
        if (pw.length < 8) { alert('Password too short.'); return; }
        await api('mu_reset_password', { id, new_password: pw }, 'POST');
        alert('Password reset. All sessions terminated.');
    }

    // ── Invitations ───────────────────────────────────────────────────────────
    async function loadInvitations() {
        const d = await api('mu_invitations');
        const rows = d.data.invitations || [];
        const tb = document.getElementById('inviteTableBody');
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="6" class="tbl-empty">No invitations yet.</td></tr>';
            return;
        }
        const now = new Date();
        tb.innerHTML = rows.map(i => {
            const exp = new Date(i.expires_at);
            const accepted = !!i.accepted_at;
            const expired  = exp < now && !accepted;
            return `<tr>
                <td>${esc(i.email)}</td>
                <td><span class="pill pill-xs ${i.role==='admin'?'pill-accent':'pill-muted'}">${i.role}</span></td>
                <td>${esc(i.invited_by_name)}</td>
                <td>
                    <span class="pill pill-xs ${accepted?'pill-done':expired?'pill-danger':'pill-warn'}">
                        ${accepted?'Accepted':expired?'Expired':'Pending'}
                    </span>
                </td>
                <td style="font-size:11px;color:var(--muted)">${i.expires_at}</td>
                <td>
                    ${!accepted && !expired
                        ? `<button class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText('${APP_URL}/auth/register.php?invite=${i.token}');this.textContent='Copied!'">Copy URL</button>`
                        : '—'}
                </td>
            </tr>`;
        }).join('');
    }

    function openInvite() {
        document.getElementById('inviteEmail').value = '';
        document.getElementById('inviteRole').value = 'user';
        document.getElementById('inviteResult').style.display = 'none';
        document.getElementById('modalInvite').style.display = 'flex';
    }
    function closeInvite() { document.getElementById('modalInvite').style.display = 'none'; }

    async function sendInvite() {
        const email = document.getElementById('inviteEmail').value.trim();
        const role  = document.getElementById('inviteRole').value;
        if (!email) { alert('Enter an email address.'); return; }
        const btn = document.getElementById('btnSendInvite');
        btn.disabled = true; btn.textContent = 'Generating…';
        try {
            const d = await api('mu_invite', { email, role }, 'POST');
            document.getElementById('inviteUrl').value = d.data.invite_url;
            document.getElementById('inviteResult').style.display = '';
            loadInvitations();
        } catch(e) { alert(e.message); }
        finally { btn.disabled = false; btn.textContent = 'Generate Invite'; }
    }

    function copyInvite() {
        const url = document.getElementById('inviteUrl').value;
        navigator.clipboard.writeText(url);
    }

    // ── Activity ──────────────────────────────────────────────────────────────
    async function loadActivity() {
        const d = await api('mu_activity');
        const rows = d.data.activity || [];
        const tb = document.getElementById('activityBody');
        if (!rows.length) { tb.innerHTML = '<tr><td colspan="4" class="tbl-empty">No activity.</td></tr>'; return; }
        tb.innerHTML = rows.map(r => `
            <tr>
                <td style="font-size:11px;color:var(--muted)">${r.created_at}</td>
                <td>${esc(r.name)} <span class="tbl-sub">${esc(r.email)}</span></td>
                <td>${esc(r.action)}</td>
                <td class="mono" style="font-size:11px">${esc(r.ip_address||'—')}</td>
            </tr>`).join('');
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
                    if (tab.dataset.tab === 'ut-invitations') loadInvitations();
                    if (tab.dataset.tab === 'ut-activity') loadActivity();
                }
            });
        });
    }

    // ── Search debounce ───────────────────────────────────────────────────────
    let _searchTimer;
    function initSearch() {
        document.getElementById('userSearch').addEventListener('input', e => {
            clearTimeout(_searchTimer);
            _searchTimer = setTimeout(() => { _search = e.target.value.trim(); loadUsers(true); }, 350);
        });
        document.getElementById('userRoleFilter').addEventListener('change', e => { _role = e.target.value; loadUsers(true); });
        document.getElementById('userStatusFilter').addEventListener('change', e => { _status = e.target.value; loadUsers(true); });
    }

    async function init() {
        initTabs(); initSearch();
        document.getElementById('btnAddUser').addEventListener('click', () => openModal());
        document.getElementById('btnInviteUser').addEventListener('click', openInvite);
        await Promise.all([loadStats(), loadUsers()]);
    }

    return { init, openModal, closeModal, saveUser, toggleStatus, deleteUser,
             viewUser, closeDrawer, killSessions, resetPwPrompt,
             openInvite, closeInvite, sendInvite, copyInvite };
})();

const APP_URL = '<?= APP_URL ?>';
document.addEventListener('DOMContentLoaded', WDUsers.init);
</script>
