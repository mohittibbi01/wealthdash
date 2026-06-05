<?php
/**
 * WealthDash — t50: Multi-User Management Page
 * File: pages/admin/users.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!is_admin()) { header('Location: ' . APP_URL . '?page=dashboard'); exit; }
$pageTitle    = 'User Management';
$activePage   = 'admin';
$activeSection= 'admin_users';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">👥 User Management</h1><p class="page-subtitle">Create, manage, and monitor all WealthDash users.</p></div>
  <button class="btn btn-primary" onclick="UM.openCreate()">+ Create User</button>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <input type="text" id="um-search" class="form-control" style="width:220px;" placeholder="Search name / email…" oninput="UM.debounceLoad()">
    <select id="um-role" class="form-control" style="width:130px;" onchange="UM.load()">
      <option value="">All Roles</option>
      <option value="user">User</option>
      <option value="admin">Admin</option>
    </select>
    <select id="um-status" class="form-control" style="width:140px;" onchange="UM.load()">
      <option value="">All Status</option>
      <option value="active">Active</option>
      <option value="suspended">Suspended</option>
      <option value="deleted">Deleted</option>
    </select>
    <button class="btn btn-ghost btn-sm" onclick="UM.load()">🔄 Refresh</button>
    <span id="um-count" style="font-size:13px;color:var(--text-muted);margin-left:auto;"></span>
  </div>
</div>

<!-- Users Table -->
<div class="card">
  <div class="card-body p-0">
    <div id="um-table"><div class="loading-row" style="padding:40px;text-align:center;">Loading…</div></div>
  </div>
</div>

<!-- Pagination -->
<div id="um-pagination" style="margin-top:12px;display:flex;justify-content:center;gap:8px;"></div>

<!-- Create User Modal -->
<div id="um-create-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)UM.closeCreate()">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><span class="modal-title">Create New User</span><button class="modal-close" onclick="UM.closeCreate()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Full Name *</label><input type="text" id="um-c-name" class="form-control" placeholder="John Doe"></div>
      <div class="form-group"><label class="form-label">Email *</label><input type="email" id="um-c-email" class="form-control" placeholder="john@example.com"></div>
      <div class="form-group"><label class="form-label">Password * (min 8 chars)</label><input type="password" id="um-c-pass" class="form-control"></div>
      <div class="form-group"><label class="form-label">Role</label>
        <select id="um-c-role" class="form-control"><option value="user">User</option><option value="admin">Admin</option></select>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="UM.closeCreate()">Cancel</button><button class="btn btn-primary" onclick="UM.create()">Create User</button></div>
  </div>
</div>

<!-- User Detail Modal -->
<div id="um-detail-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)UM.closeDetail()">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header"><span class="modal-title" id="um-detail-title">User Detail</span><button class="modal-close" onclick="UM.closeDetail()">×</button></div>
    <div class="modal-body" id="um-detail-body"></div>
    <div class="modal-footer" id="um-detail-footer"></div>
  </div>
</div>

<!-- Reset Password Modal -->
<div id="um-reset-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header"><span class="modal-title">Reset Password</span><button class="modal-close" onclick="document.getElementById('um-reset-modal').style.display='none'">×</button></div>
    <div class="modal-body">
      <input type="hidden" id="um-reset-uid">
      <div class="form-group"><label class="form-label">New Password</label><input type="password" id="um-reset-pass" class="form-control" placeholder="Min 8 characters"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="document.getElementById('um-reset-modal').style.display='none'">Cancel</button><button class="btn btn-danger" onclick="UM.doReset()">Reset Password</button></div>
  </div>
</div>

<script>
const UM = {
  _page: 1, _timer: null,

  init() { this.load(); },

  debounceLoad() { clearTimeout(this._timer); this._timer = setTimeout(() => this.load(), 400); },

  load(page) {
    this._page = page || 1;
    const s = document.getElementById('um-search').value;
    const r = document.getElementById('um-role').value;
    const st = document.getElementById('um-status').value;
    document.getElementById('um-table').innerHTML = '<div style="padding:40px;text-align:center;">Loading…</div>';

    apiPost({ action: 'admin_users', page: this._page, search: s, role: r, status: st }).then(d => {
      if (!d.ok) return;
      document.getElementById('um-count').textContent = d.data.total + ' users';
      const rows = d.data.users || [];
      if (!rows.length) {
        document.getElementById('um-table').innerHTML = '<div class="empty-state" style="padding:40px;"><div class="empty-icon">👥</div><div>No users found.</div></div>';
        document.getElementById('um-pagination').innerHTML = '';
        return;
      }
      const statusBadge = s => ({ active:'<span class="badge wd-gain">active</span>', suspended:'<span class="badge wd-loss">suspended</span>', deleted:'<span class="badge" style="background:#f3f4f6;color:#6b7280;">deleted</span>' }[s] || s);
      const roleBadge   = r => r === 'admin' ? '<span class="badge" style="background:#dbeafe;color:#1d4ed8;">admin</span>' : '<span class="badge">user</span>';

      let html = `<div class="table-responsive"><table class="data-table">
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Portfolios</th><th>Last Login</th><th>2FA</th><th></th></tr></thead><tbody>`;
      for (const u of rows) {
        html += `<tr>
          <td style="color:var(--text-muted);font-size:12px;">${u.id}</td>
          <td style="font-weight:600;">${esc(u.name)}</td>
          <td style="font-size:13px;">${esc(u.email)}</td>
          <td>${roleBadge(u.role)}</td>
          <td>${statusBadge(u.status)}</td>
          <td class="text-right">${u.portfolio_count}</td>
          <td style="font-size:12px;color:var(--text-muted);">${u.last_login_at ? u.last_login_at.substring(0,10) : 'Never'}</td>
          <td style="text-align:center;">${u.totp_enabled ? '🔒' : '—'}</td>
          <td>
            <button class="btn btn-ghost btn-sm" onclick="UM.detail(${u.id})">View</button>
            <button class="btn btn-secondary btn-sm" onclick="UM.toggleStatus(${u.id})">${u.status==='active'?'Suspend':'Activate'}</button>
            <button class="btn btn-ghost btn-sm" onclick="UM.openReset(${u.id})" title="Reset password">🔑</button>
          </td>
        </tr>`;
      }
      html += '</tbody></table></div>';
      document.getElementById('um-table').innerHTML = html;

      // Pagination
      let pg = '';
      for (let i = 1; i <= d.data.total_pages; i++) {
        pg += `<button class="btn btn-sm ${i===this._page?'btn-primary':'btn-ghost'}" onclick="UM.load(${i})">${i}</button>`;
      }
      document.getElementById('um-pagination').innerHTML = pg;
    });
  },

  openCreate() {
    ['um-c-name','um-c-email','um-c-pass'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('um-create-modal').style.display = '';
  },
  closeCreate() { document.getElementById('um-create-modal').style.display = 'none'; },

  create() {
    apiPost({
      action: 'admin_user_create',
      name:     document.getElementById('um-c-name').value,
      email:    document.getElementById('um-c-email').value,
      password: document.getElementById('um-c-pass').value,
      role:     document.getElementById('um-c-role').value,
    }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) { this.closeCreate(); this.load(); }
    });
  },

  toggleStatus(uid) {
    if (!confirm('Toggle this user\'s status?')) return;
    apiPost({ action: 'admin_user_toggle', user_id: uid }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) this.load(this._page);
    });
  },

  detail(uid) {
    apiPost({ action: 'admin_user_detail', user_id: uid }).then(r => {
      if (!r.ok) return;
      const u = r.data.user;
      document.getElementById('um-detail-title').textContent = esc(u.name) + ' — Detail';
      document.getElementById('um-detail-body').innerHTML = `
        <table class="data-table" style="margin-bottom:16px;">
          <tbody>
            <tr><td>Email</td><td>${esc(u.email)}</td></tr>
            <tr><td>Role</td><td>${esc(u.role)}</td></tr>
            <tr><td>Status</td><td>${esc(u.status)}</td></tr>
            <tr><td>2FA</td><td>${u.totp_enabled ? '🔒 Enabled' : '—'}</td></tr>
            <tr><td>Created</td><td>${esc(u.created_at?.substring(0,10))}</td></tr>
            <tr><td>Last Login</td><td>${esc(u.last_login_at?.substring(0,10) || 'Never')}</td></tr>
          </tbody>
        </table>
        <div style="font-weight:700;font-size:13px;margin-bottom:8px;">Portfolios (${r.data.portfolios.length})</div>
        ${r.data.portfolios.map(p=>`<span class="badge" style="margin:2px;">${esc(p.name)}${p.is_default?' ★':''}</span>`).join('')}
        ${r.data.audit_log.length ? `<div style="font-weight:700;font-size:13px;margin:12px 0 8px;">Recent Activity</div>
        <div style="max-height:160px;overflow-y:auto;font-size:12px;">${r.data.audit_log.map(l=>`<div style="padding:4px 0;border-bottom:1px solid var(--border);"><span style="color:var(--text-muted);">${esc(l.created_at?.substring(0,16))}</span> — ${esc(l.action)}: ${esc(l.detail)}</div>`).join('')}</div>` : ''}`;
      document.getElementById('um-detail-footer').innerHTML = `
        <button class="btn btn-ghost" onclick="UM.closeDetail()">Close</button>
        <button class="btn btn-danger btn-sm" onclick="UM.deleteUser(${u.id})">🗑 Delete</button>`;
      document.getElementById('um-detail-modal').style.display = '';
    });
  },
  closeDetail() { document.getElementById('um-detail-modal').style.display = 'none'; },

  deleteUser(uid) {
    if (!confirm('Soft-delete this user? They will lose access but data is retained.')) return;
    apiPost({ action: 'admin_user_delete', user_id: uid }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) { this.closeDetail(); this.load(); }
    });
  },

  openReset(uid) {
    document.getElementById('um-reset-uid').value = uid;
    document.getElementById('um-reset-pass').value = '';
    document.getElementById('um-reset-modal').style.display = '';
  },

  doReset() {
    apiPost({
      action: 'admin_user_reset_password',
      user_id: document.getElementById('um-reset-uid').value,
      new_password: document.getElementById('um-reset-pass').value,
    }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) document.getElementById('um-reset-modal').style.display = 'none';
    });
  }
};
document.addEventListener('DOMContentLoaded', () => UM.init());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
