<?php
/**
 * WealthDash — Admin Panel (Phase 5 — Complete)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_admin();
$pageTitle   = 'Admin Panel';
$activePage  = 'admin';

$db = DB::conn();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Admin Panel</h1>
    <p class="page-subtitle">Users · Settings · NAV · Audit Log</p>
  </div>
</div>

<!-- Tabs -->
<div class="admin-tabs mb-4">
  <button class="admin-tab active" data-tab="overview" onclick="adminSwitchTab('overview',this)">Overview</button>
  <button class="admin-tab" data-tab="users"    onclick="adminSwitchTab('users',this)">Users</button>
  <button class="admin-tab" data-tab="settings" onclick="adminSwitchTab('settings',this)">Settings</button>
  <button class="admin-tab" data-tab="nav"      onclick="adminSwitchTab('nav',this)">NAV &amp; Data</button>
  <button class="admin-tab" data-tab="audit"    onclick="adminSwitchTab('audit',this)">Audit Log</button>
  <button class="admin-tab" data-tab="dbmgr" onclick="adminSwitchTab('dbmgr',this)">🗄️ DB Manager</button>
</div>

<!-- ═══════ TAB: OVERVIEW ═══════ -->
<div id="tab-overview" class="admin-tab-content">
  <div class="cards-grid cards-grid-4 mb-4" id="statsCards">
    <div class="stat-card">
      <div class="stat-label">Active Users</div>
      <div class="stat-value" id="statUsers">—</div>
      <div id="statUsersBreakdown" style="font-size:11px;color:var(--text-muted);margin-top:4px;line-height:1.6;">—</div>
    </div>
    <div class="stat-card"><div class="stat-label">Portfolios</div><div class="stat-value" id="statPortfolios">—</div></div>
    <div class="stat-card"><div class="stat-label">MF Holdings</div><div class="stat-value" id="statMfHoldings">—</div></div>
    <div class="stat-card"><div class="stat-label">Funds in DB</div><div class="stat-value" id="statFunds">—</div></div>
    <div class="stat-card"><div class="stat-label">Stock Holdings</div><div class="stat-value" id="statStocks">—</div></div>
    <div class="stat-card"><div class="stat-label">Active FDs</div><div class="stat-value" id="statFDs">—</div></div>
    <div class="stat-card"><div class="stat-label">Savings Accounts</div><div class="stat-value" id="statSavings">—</div></div>
    <div class="stat-card"><div class="stat-label">NAV Last Updated</div><div class="stat-value text-sm" id="statNav">—</div></div>
  </div>
</div>

<!-- ═══════ TAB: USERS ═══════ -->
<div id="tab-users" class="admin-tab-content" style="display:none">
  <div class="flex-between mb-3">
    <div style="display:flex;gap:.75rem;align-items:center">
      <input type="text" class="form-control" id="userSearchInput" placeholder="Search name or email…" style="width:260px" oninput="filterUsers()">
    </div>
    <button class="btn btn-primary" onclick="openAddUser()">+ Add User</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table class="data-table" id="usersTable">
        <thead>
          <tr>
            <th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th>
            <th>Senior</th><th>Portfolios</th><th>MF Holdings</th>
            <th>Last Login</th><th>Joined</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="usersBody">
          <tr><td colspan="11" class="text-center text-secondary">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════ TAB: SETTINGS ═══════ -->
<div id="tab-settings" class="admin-tab-content" style="display:none">
  <div class="cards-grid cards-grid-2">
    <!-- Tax Settings -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Tax Configuration</h3></div>
      <div class="card-body" id="taxSettingsForm">
        <div class="form-group">
          <label class="form-label">LTCG Exemption Limit (₹)</label>
          <input type="number" class="form-control setting-input" data-key="ltcg_exemption_limit" value="125000">
        </div>
        <div class="form-group">
          <label class="form-label">Equity LTCG Rate (%)</label>
          <input type="number" class="form-control setting-input" data-key="equity_ltcg_rate" value="12.5" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">Equity STCG Rate (%)</label>
          <input type="number" class="form-control setting-input" data-key="equity_stcg_rate" value="20">
        </div>
        <div class="form-group">
          <label class="form-label">Debt LTCG Rate (%)</label>
          <input type="number" class="form-control setting-input" data-key="debt_ltcg_rate" value="20">
        </div>
        <div class="form-group">
          <label class="form-label">FD TDS Rate (%) — Non-Senior</label>
          <input type="number" class="form-control setting-input" data-key="fd_tds_rate" value="10">
        </div>
        <div class="form-group">
          <label class="form-label">FD TDS Threshold (₹) — Non-Senior</label>
          <input type="number" class="form-control setting-input" data-key="fd_tds_threshold" value="40000">
        </div>
        <div class="form-group">
          <label class="form-label">FD TDS Threshold (₹) — Senior Citizen</label>
          <input type="number" class="form-control setting-input" data-key="fd_tds_threshold_senior" value="50000">
        </div>
        <button class="btn btn-primary" onclick="saveSettings(['ltcg_exemption_limit','equity_ltcg_rate','equity_stcg_rate','debt_ltcg_rate','fd_tds_rate','fd_tds_threshold','fd_tds_threshold_senior'])">Save Tax Settings</button>
      </div>
    </div>

    <!-- App Settings -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">App Configuration</h3></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">App Name</label>
          <input type="text" class="form-control setting-input" data-key="app_name" value="WealthDash">
        </div>
        <div class="form-group">
          <label class="form-label">Goal Default Return % (per year)</label>
          <input type="number" class="form-control setting-input" data-key="goal_default_return_pct" value="12" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">SIP Reminder</label>
          <select class="form-select setting-input" data-key="sip_reminder_enabled">
            <option value="1">Enabled</option>
            <option value="0">Disabled</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">SIP Reminder Days Before</label>
          <input type="number" class="form-control setting-input" data-key="sip_reminder_days_before" value="2" min="1" max="7">
        </div>
        <button class="btn btn-primary" onclick="saveSettings(['app_name','goal_default_return_pct','sip_reminder_enabled','sip_reminder_days_before'])">Save App Settings</button>
      </div>
    </div>
  </div>

  <!-- Portfolio Members Management -->
  <div class="card mt-4">
    <div class="card-header">
      <h3 class="card-title">Portfolio Sharing</h3>
      <span class="text-secondary text-sm">Share portfolios across family members</span>
    </div>
    <div class="card-body">
      <div class="form-row" style="align-items:flex-end;flex-wrap:wrap;gap:1rem">
        <div class="form-group mb-0">
          <label class="form-label">Portfolio</label>
          <select class="form-select" id="sharePortfolioId" style="width:240px">
            <option value="">Select portfolio…</option>
          </select>
        </div>
        <div class="form-group mb-0">
          <label class="form-label">Member Email</label>
          <input type="email" class="form-control" id="shareMemberEmail" placeholder="member@email.com" style="width:220px">
        </div>
        <div class="form-group mb-0">
          <label class="form-check">
            <input type="checkbox" id="shareCanEdit"> Can Edit
          </label>
        </div>
        <div class="form-group mb-0">
          <button class="btn btn-primary" onclick="addPortfolioMember()">Add Member</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════ TAB: NAV & DATA ═══════ -->
<div id="tab-nav" class="admin-tab-content" style="display:none">

  <!-- Row 1: NAV Update (AMFI + Peak combined) + Stocks (side by side) -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

    <!-- Combined NAV Update card -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:20px;">📡</span>
          <h3 class="card-title" style="margin:0;">Mutual Fund NAV Update</h3>
        </div>
        <div class="nav-info-btn" data-info="amfi">ℹ</div>
      </div>
      <div class="nav-info-box" id="info-amfi">
        <strong>Update Today's NAV</strong> — AMFI India se aaj ke sabhi mutual funds ke latest NAV fetch karta hai aur <code>funds.latest_nav</code> update karta hai. Holdings ka "Current Value" yahi se aata hai.<br><br>
        <strong>Import Full Fund List</strong> — AMFI se poori fund list download karta hai (14,000+ funds). Nayi fund add hone pe ya fresh install pe run karo. 2-5 min lagta hai.<br><br>
        <strong>Peak NAV Update</strong> — MFAPI.in se har fund ka poora NAV history fetch karta hai aur sabse zyada NAV dhundh ke <code>funds.highest_nav</code> save karta hai. Holdings page pe Drawdown column ke liye.
      </div>
      <div class="card-body">

        <!-- AMFI section -->
        <p class="text-secondary text-sm" style="margin:0 0 10px;font-weight:600;">AMFI — Daily NAV</p>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
          <button class="btn btn-primary" id="btnUpdateNav" onclick="runNavUpdate('nav_only')">
            <span id="navBtnIcon">🔄</span>
            <span id="navBtnText"> Update Today's NAV</span>
          </button>
          <button class="btn btn-outline" id="btnImportAmfi" onclick="confirmImportAmfi()">
            <span id="importBtnIcon">📥</span>
            <span id="importBtnText"> Import Full Fund List</span>
          </button>
        </div>

        <!-- Inline status strip -->
        <div id="navStatusStrip" style="display:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:4px;"></div>

        <hr style="border:none;border-top:1px solid var(--border);margin:4px 0 14px;">

        <!-- Peak NAV section -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
          <p class="text-secondary text-sm" style="margin:0;font-weight:600;">Peak NAV — All-Time High</p>
          <div style="display:flex;gap:8px;align-items:center;">
            <button class="btn btn-primary" id="btnRunPeakNav" onclick="runPeakNavBackground()">
              <span id="btnRunPeakNavIcon">&#9654;</span>
              <span id="btnRunPeakNavText"> Run Peak NAV</span>
            </button>
            <a href="<?= APP_URL ?>/peak_nav/status.php" target="_blank" class="btn btn-ghost btn-sm">↗ Full Tracker</a>
          </div>
        </div>

        <!-- Live status tiles from status.php API -->
        <div id="pnTiles" style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:10px;">
          <div class="pn-tile pn-total">  <div class="pn-num" id="pnTotal">—</div>  <div class="pn-lbl">Total</div></div>
          <div class="pn-tile pn-stale">  <div class="pn-num" id="pnStale">—</div>  <div class="pn-lbl">Needs Update</div></div>
          <div class="pn-tile pn-pending"><div class="pn-num" id="pnPending">—</div><div class="pn-lbl">Pending</div></div>
          <div class="pn-tile pn-done">   <div class="pn-num" id="pnDone">—</div>   <div class="pn-lbl">Completed</div></div>
          <div class="pn-tile pn-err">    <div class="pn-num" id="pnErrors">—</div> <div class="pn-lbl">Errors</div></div>
        </div>

        <!-- Progress bar -->
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="flex:1;background:var(--border);border-radius:99px;height:8px;overflow:hidden;">
            <div id="pnBar" style="height:100%;width:0%;background:var(--accent);border-radius:99px;transition:width .6s;"></div>
          </div>
          <span id="pnPct" style="font-size:12px;font-weight:700;color:var(--accent);min-width:36px;text-align:right;">0%</span>
        </div>

      </div>
    </div>

    <!-- Stock Prices Update -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:20px;">📊</span>
          <h3 class="card-title" style="margin:0;">Stock Prices Update</h3>
        </div>
        <div class="nav-info-btn" data-info="stocks">ℹ</div>
      </div>
      <div class="nav-info-box" id="info-stocks">
        Yahoo Finance se tumhare portfolio ke sabhi stocks ke latest prices fetch karta hai aur <code>stock_master.latest_price</code> update karta hai. Stock holdings ka "Current Value" yahi se calculate hota hai.<br><br>
        <strong>Note:</strong> Market hours ke baad run karo (3:30 PM ke baad) taaki closing price mile.
      </div>
      <div class="card-body">
        <button class="btn btn-primary" id="btnUpdateStocks" onclick="updateStockPrices()">
          Refresh Stock Prices
        </button>
        <div id="stockUpdateResult" style="display:none;margin-top:1rem;padding:.75rem 1rem;border-radius:var(--radius)"></div>
      </div>
    </div>

  </div>

  <!-- Row 2: Holdings Recalc + Cron (side by side) -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

    <!-- Holdings Recalculation -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:20px;">🔄</span>
          <h3 class="card-title" style="margin:0;">Holdings Recalculation</h3>
        </div>
        <div class="nav-info-btn" data-info="recalc">ℹ</div>
      </div>
      <div class="nav-info-box" id="info-recalc">
        <code>mf_transactions</code> table se scratch se sabhi holdings recalculate karta hai — total units, invested amount, avg cost NAV, gain/loss sab.<br><br>
        <strong>Kab use karo:</strong> Agar holdings page pe values galat lag rahi hain, ya CSV import ke baad data inconsistent dikh raha ho.
      </div>
      <div class="card-body">
        <button class="btn btn-outline" id="btnRecalc" onclick="recalcHoldings()">
          <span id="recalcBtnLabel">&#128260; Recalculate All Holdings</span>
        </button>
        <p class="text-xs text-secondary mt-2">This may take 1-2 minutes for large portfolios.</p>
        <div id="recalcStatus" style="margin-top:10px;font-size:13px;display:none;"></div>
      </div>
    </div>

    <!-- Cron Schedule -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:20px;">⏰</span>
          <h3 class="card-title" style="margin:0;">Cron Schedule</h3>
        </div>
        <div class="nav-info-btn" data-info="cron">ℹ</div>
      </div>
      <div class="nav-info-box" id="info-cron">
        Ye sirf reference hai — XAMPP localhost pe cron kaam nahi karta. <strong>Upar ke buttons manually use karo</strong> jab bhi NAV ya stock prices update karni ho.<br><br>
        Agar kabhi server pe host karo tab yeh cron jobs add karna.
      </div>
      <div class="card-body">
        <p class="text-secondary text-sm mb-2">Reference only (server hosting ke liye):</p>
        <div class="code-block">
          <code>
# Daily NAV (10:15 PM)<br>
15 22 * * * php /path/to/wealthdash/cron/update_nav_daily.php<br><br>
# Daily Stocks (6:30 PM)<br>
30 18 * * 1-5 php /path/to/wealthdash/cron/update_stocks_daily.php<br><br>
# FD Maturity Alerts (9 AM)<br>
0 9 * * * php /path/to/wealthdash/cron/fd_maturity_alert.php
          </code>
        </div>
      </div>
    </div>

  </div>

</div>

<!-- ═══════ TAB: AUDIT LOG ═══════ -->
<div id="tab-audit" class="admin-tab-content" style="display:none">
  <div class="flex-between mb-3">
    <input type="text" class="form-control" id="auditFilter" placeholder="Filter by action…" style="width:240px">
    <button class="btn btn-outline" onclick="loadAuditLog()">Refresh</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Time</th></tr>
        </thead>
        <tbody id="auditBody">
          <tr><td colspan="6" class="text-center text-secondary">Click a tab to load…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;padding:.75rem 1rem">
      <span class="text-secondary text-sm" id="auditTotalText">—</span>
      <div style="display:flex;gap:.5rem">
        <button class="btn btn-ghost btn-xs" id="auditPrev" onclick="auditPage(-1)">← Prev</button>
        <span class="text-sm" id="auditPageNum">1</span>
        <button class="btn btn-ghost btn-xs" id="auditNext" onclick="auditPage(1)">Next →</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════ TAB: DB MANAGER ═══════ -->
<div id="tab-dbmgr" class="admin-tab-content" style="display:none">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);">
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:600;">Database Tables</h3>
        <p style="margin:4px 0 0;font-size:12px;color:var(--text-muted);">All records will be deleted permanently. This cannot be undone.</p>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:12px;color:var(--text-muted);" id="dbTotalText">—</span>
        <button class="btn btn-outline btn-sm" onclick="loadDbTables()" style="font-size:12px;padding:6px 12px;">↻ Refresh</button>
        <button class="btn" onclick="deleteAllTables()"
          style="background:#dc2626;color:#fff;font-weight:600;padding:10px 20px;border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;gap:8px;font-size:13px;">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
          </svg>
          Delete ALL Records
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table" style="font-size:13px;">
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>Table Name</th>
            <th class="text-center" style="width:140px;">Records</th>
            <th class="text-center" style="width:160px;">Action</th>
          </tr>
        </thead>
        <tbody id="dbTableBody">
          <tr><td colspan="4" class="text-center" style="padding:40px;">
            <div class="spinner"></div>
          </td></tr>
        </tbody>
      </table>
    </div>


  </div>
</div>
<div class="modal-overlay" id="addUserModal" style="display:none">
  <div class="modal" style="max-width:460px">
    <div class="modal-header">
      <h3 class="modal-title">Add User</h3>
      <button class="modal-close" onclick="closeAddUser()">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Full Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="newUserName" placeholder="Rajesh Kumar">
      </div>
      <div class="form-group">
        <label class="form-label">Email <span class="text-danger">*</span></label>
        <input type="email" class="form-control" id="newUserEmail" placeholder="rajesh@example.com">
      </div>
      <div class="form-group">
        <label class="form-label">Mobile</label>
        <input type="text" class="form-control" id="newUserMobile" placeholder="9876543210">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Password <span class="text-danger">*</span></label>
          <input type="password" class="form-control" id="newUserPassword" placeholder="Min 8 characters">
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select class="form-select" id="newUserRole">
            <option value="member">Member</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-check">
          <input type="checkbox" id="newUserSenior"> Senior Citizen (for FD TDS)
        </label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeAddUser()">Cancel</button>
      <button class="btn btn-primary" onclick="addUser()">Create User</button>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetPwModal" style="display:none">
  <div class="modal" style="max-width:380px">
    <div class="modal-header">
      <h3 class="modal-title">Reset Password</h3>
      <button class="modal-close" onclick="document.getElementById('resetPwModal').style.display='none'">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="resetPwUserId">
      <p class="text-secondary mb-3" id="resetPwUserName"></p>
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input type="password" class="form-control" id="resetPwNew" placeholder="Min 8 characters">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('resetPwModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary" onclick="saveResetPw()">Reset Password</button>
    </div>
  </div>
</div>

<style>
/* Fix: map missing variables to existing ones */
:root {
  --primary:   var(--accent);
  --danger:    var(--loss);
  --hover-bg:  var(--bg-surface-2);
  --radius:    var(--radius-md);
  --input-bg:  var(--bg-surface-2);
}
[data-theme="dark"] {
  --hover-bg:  var(--bg-surface-2);
  --input-bg:  var(--bg-surface-2);
}

.admin-tabs { display:flex; gap:.25rem; border-bottom:2px solid var(--border); flex-wrap:wrap; }
.admin-tab  { background:none; border:none; padding:.6rem 1.1rem; cursor:pointer; color:var(--text-secondary); font-size:.9rem; border-bottom:2px solid transparent; margin-bottom:-2px; border-radius:var(--radius-md) var(--radius-md) 0 0; transition:color .2s; }
.admin-tab:hover { color:var(--text-primary); background:var(--bg-surface-2); }
.admin-tab.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
.code-block { background:var(--bg-surface-2); border:1px solid var(--border); border-radius:var(--radius-md); padding:1rem; font-family:monospace; font-size:.8rem; line-height:1.7; overflow-x:auto; }
.nav-info-btn { width:22px;height:22px;border-radius:50%;background:var(--bg-surface-2);border:1.5px solid var(--border);color:var(--text-secondary);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;user-select:none;transition:background .15s,color .15s; }
.nav-info-btn:hover { background:var(--accent);color:#fff;border-color:var(--accent); }
.pn-tile { background:var(--bg-surface-2);border:1px solid var(--border);border-radius:8px;padding:8px 10px;text-align:center; }
.pn-num  { font-size:1.2rem;font-weight:700;line-height:1.2;font-variant-numeric:tabular-nums; }
.pn-lbl  { font-size:10px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.4px;margin-top:2px; }
.pn-total .pn-num  { color:var(--accent); }
.pn-stale .pn-num  { color:#d97706; }
.pn-pending .pn-num{ color:var(--warning,#d97706); }
.pn-done .pn-num   { color:var(--success); }
.pn-err .pn-num    { color:var(--danger); }
.nav-info-box { display:none;padding:12px 20px;background:rgba(37,99,235,.06);border-bottom:1px solid rgba(37,99,235,.15);font-size:13px;line-height:1.6;color:var(--text-secondary); }
.nav-info-box code { background:var(--bg-surface-2);padding:1px 5px;border-radius:4px;font-size:12px;color:var(--accent); }
</style>

<script>
let allUsers = [];
let auditOffset = 0;
const AUDIT_LIMIT = 50;

document.addEventListener('DOMContentLoaded', () => {
  loadStats();
});

function adminSwitchTab(name, btn) {
  document.querySelectorAll('.admin-tab-content').forEach(el => el.style.display='none');
  document.querySelectorAll('.admin-tab').forEach(el => el.classList.remove('active'));
  var tabEl = document.getElementById('tab-'+name);
  if (tabEl) tabEl.style.display='block';
  btn.classList.add('active');

  if (name==='users' && allUsers.length===0) loadUsers();
  if (name==='settings') { loadSettings(); loadPortfolioList(); }
  if (name==='audit') loadAuditLog();
  if (name==='dbmgr') loadDbTables();
}

async function loadStats() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_stats' });
    const s = d.data?.stats || d.stats || {};
    document.getElementById('statUsers').textContent     = s.users          ?? '—';
    const bd = document.getElementById('statUsersBreakdown');
    if (bd && s.admin_count != null) {
      bd.innerHTML = `Admin <strong>${s.admin_count}</strong> + Members <strong>${s.member_count}</strong> = <strong>${s.users}</strong>`;
    }
    document.getElementById('statPortfolios').textContent= s.portfolios     ?? '—';
    document.getElementById('statMfHoldings').textContent= s.mf_holdings    ?? '—';
    document.getElementById('statFunds').textContent     = s.funds          ?? '—';
    document.getElementById('statStocks').textContent    = s.stock_holdings ?? '—';
    document.getElementById('statFDs').textContent       = s.fd_accounts    ?? '—';
    document.getElementById('statSavings').textContent   = s.savings_accs   ?? '—';
    document.getElementById('statNav').textContent       = s.nav_last_updated ? formatDate(s.nav_last_updated) : 'Not updated';
  } catch(e) {
    console.error('loadStats error:', e);
    // Show error visibly on page
    document.getElementById('statsCards').insertAdjacentHTML('beforeend',
      `<div style="grid-column:1/-1;color:red;font-size:12px;padding:8px;background:#fee2e2;border-radius:6px;">
        ⚠ Stats load error: ${e.message} — Check browser Console (F12) for details
      </div>`);
  }
}

async function loadUsers() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_users' });
    allUsers = d.data?.users || d.users || [];
    renderUsers(allUsers);
  } catch(e) { console.error(e); }
}

function renderUsers(users) {
  const tbody = document.getElementById('usersBody');
  if (!users.length) {
    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-secondary">No users found.</td></tr>';
    return;
  }
  tbody.innerHTML = users.map(u => `
    <tr>
      <td>${u.id}</td>
      <td><strong>${esc(u.name)}</strong></td>
      <td class="text-secondary">${esc(u.email)}</td>
      <td><span class="badge ${u.role==='admin'?'badge-primary':'badge-secondary'}">${u.role}</span></td>
      <td><span class="badge ${u.status==='active'?'badge-success':'badge-danger'}">${u.status}</span></td>
      <td>${u.is_senior_citizen?'✓':'—'}</td>
      <td class="text-center">${u.portfolio_count}</td>
      <td class="text-center">${u.mf_holdings_count}</td>
      <td class="text-secondary text-sm">${u.last_login_at ? formatDate(u.last_login_at) : '—'}</td>
      <td class="text-secondary text-sm">${formatDate(u.created_at)}</td>
      <td>
        <div style="display:flex;gap:.3rem;flex-wrap:wrap">
          ${u.id != <?= $currentUser['id'] ?> ? `
            <button class="btn btn-ghost btn-xs" onclick="toggleUser(${u.id},'${u.status}')">
              ${u.status==='active'?'Disable':'Enable'}
            </button>
            <button class="btn btn-ghost btn-xs" onclick="changeRole(${u.id},'${u.role}','${esc(u.name)}')">
              ${u.role==='admin'?'→ Member':'→ Admin'}
            </button>
            <button class="btn btn-ghost btn-xs" onclick="openResetPw(${u.id},'${esc(u.name)}')">
              Reset PW
            </button>
          ` : '<span class="text-xs text-secondary">(you)</span>'}
        </div>
      </td>
    </tr>`).join('');
}

function filterUsers() {
  const q = document.getElementById('userSearchInput').value.toLowerCase();
  renderUsers(q ? allUsers.filter(u =>
    u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)
  ) : allUsers);
}

async function loadSettings() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_settings_get' });
    const s = d.data?.settings || d.settings || {};
    document.querySelectorAll('.setting-input').forEach(el => {
      const key = el.dataset.key;
      if (s[key] !== undefined) el.value = s[key];
    });
  } catch(e) { console.error(e); }
}

async function saveSettings(keys) {
  const payload = { action:'admin_settings_save', csrf_token:window.CSRF_TOKEN };
  keys.forEach(key => {
    const el = document.querySelector(`.setting-input[data-key="${key}"]`);
    if (el) payload[key] = el.value;
  });
  try {
    await API.post('/api/router.php', payload);
    showToast('Settings saved!');
  } catch(e) { showToast(e.message,'error'); }
}

async function loadPortfolioList() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_portfolios' });
    const sel = document.getElementById('sharePortfolioId');
    sel.innerHTML = '<option value="">Select portfolio…</option>' +
      (d.data?.portfolios || d.portfolios || []).map(p =>
        `<option value="${p.id}">${esc(p.name)} (${esc(p.owner_name)})</option>`
      ).join('');
  } catch(e) {}
}

async function addPortfolioMember() {
  const pid   = document.getElementById('sharePortfolioId').value;
  const email = document.getElementById('shareMemberEmail').value.trim();
  const canEdit = document.getElementById('shareCanEdit').checked ? 1 : 0;
  if (!pid || !email) { showToast('Select portfolio and enter email','error'); return; }
  try {
    await API.post('/api/router.php', {
      action:'admin_add_portfolio_member', portfolio_id:pid, member_email:email,
      can_edit:canEdit, csrf_token:window.CSRF_TOKEN
    });
    showToast('Member added!');
    document.getElementById('shareMemberEmail').value = '';
  } catch(e) { showToast(e.message,'error'); }
}

// ── Users actions ──────────────────────────────────────────
function openAddUser() { document.getElementById('addUserModal').style.display='flex'; }
function closeAddUser() { document.getElementById('addUserModal').style.display='none'; }

async function addUser() {
  const payload = {
    action: 'admin_add_user',
    name: document.getElementById('newUserName').value,
    email: document.getElementById('newUserEmail').value,
    mobile: document.getElementById('newUserMobile').value,
    password: document.getElementById('newUserPassword').value,
    role: document.getElementById('newUserRole').value,
    is_senior_citizen: document.getElementById('newUserSenior').checked ? 1 : 0,
    csrf_token: window.CSRF_TOKEN,
  };
  try {
    await API.post('/api/router.php', payload);
    showToast('User created!');
    closeAddUser();
    allUsers = [];
    loadUsers();
  } catch(e) { showToast(e.message,'error'); }
}

async function toggleUser(userId, status) {
  const action = status==='active' ? 'Disable' : 'Enable';
  if (!confirm(`${action} this user?`)) return;
  try {
    await API.post('/api/router.php', { action:'admin_toggle_user', user_id:userId, csrf_token:window.CSRF_TOKEN });
    showToast('User updated.');
    allUsers = [];
    loadUsers();
    loadStats();
  } catch(e) { showToast(e.message,'error'); }
}

async function changeRole(userId, currentRole, name) {
  const newRole = currentRole==='admin' ? 'member' : 'admin';
  if (!confirm(`Change ${name}'s role to ${newRole}?`)) return;
  try {
    await API.post('/api/router.php', { action:'admin_change_role', user_id:userId, role:newRole, csrf_token:window.CSRF_TOKEN });
    showToast('Role updated.');
    allUsers = [];
    loadUsers();
  } catch(e) { showToast(e.message,'error'); }
}

function openResetPw(userId, name) {
  document.getElementById('resetPwUserId').value = userId;
  document.getElementById('resetPwUserName').textContent = 'Reset password for: ' + name;
  document.getElementById('resetPwNew').value = '';
  document.getElementById('resetPwModal').style.display = 'flex';
}
async function saveResetPw() {
  const uid = document.getElementById('resetPwUserId').value;
  const pw  = document.getElementById('resetPwNew').value;
  if (pw.length < 8) { showToast('Password must be at least 8 characters','error'); return; }
  try {
    await API.post('/api/router.php', { action:'admin_reset_password', user_id:uid, new_password:pw, csrf_token:window.CSRF_TOKEN });
    showToast('Password reset!');
    document.getElementById('resetPwModal').style.display = 'none';
  } catch(e) { showToast(e.message,'error'); }
}

// ── NAV ───────────────────────────────────────────────────
// ── AMFI NAV Update ────────────────────────────────────────
const navBtnStates = {
  nav: {
    idle:    { icon:'🔄', text:' Update Today\'s NAV',  disabled:false },
    running: { icon:'⏳', text:' Fetching NAV...',       disabled:true  },
    done:    { icon:'✅', text:' NAV Updated!',          disabled:false },
    error:   { icon:'⚠️', text:' Update Today\'s NAV',  disabled:false },
  },
  import: {
    idle:    { icon:'📥', text:' Import Full Fund List', disabled:false },
    running: { icon:'⏳', text:' Importing... (2-5 min)',disabled:true  },
    done:    { icon:'✅', text:' Import Complete!',      disabled:false },
    error:   { icon:'⚠️', text:' Import Full Fund List', disabled:false },
  },
};

function navSetBtn(type, state) {
  const isNav    = type === 'nav';
  const btn      = document.getElementById(isNav ? 'btnUpdateNav' : 'btnImportAmfi');
  const iconEl   = document.getElementById(isNav ? 'navBtnIcon' : 'importBtnIcon');
  const textEl   = document.getElementById(isNav ? 'navBtnText' : 'importBtnText');
  const s        = navBtnStates[type][state];
  if (!btn) return;
  btn.disabled        = s.disabled;
  iconEl.textContent  = s.icon;
  textEl.textContent  = s.text;
}

function navShowStrip(msg, type) {
  const strip = document.getElementById('navStatusStrip');
  if (!strip) return;
  const styles = {
    success: 'background:rgba(34,197,94,.12);color:var(--success);border:1px solid rgba(34,197,94,.3)',
    error:   'background:rgba(239,68,68,.1);color:var(--danger);border:1px solid rgba(239,68,68,.25)',
    info:    'background:rgba(59,130,246,.1);color:var(--accent);border:1px solid rgba(59,130,246,.25)',
  };
  strip.style.cssText = `display:block;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:4px;${styles[type]||styles.info}`;
  strip.textContent = msg;
}

async function runNavUpdate(mode) {
  const isImport = mode === 'full_import';
  const type     = isImport ? 'import' : 'nav';

  navSetBtn(type, 'running');
  navShowStrip(isImport ? '⏳ Downloading full fund list from AMFI — please wait...' : '⏳ Fetching latest NAV from AMFI...', 'info');

  const url = `${window.APP_URL}/api/nav/update_amfi.php?manual=1&mode=${mode}`;

  try {
    let d;
    if (isImport) {
      d = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.timeout = 300000;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload    = () => { try { resolve(JSON.parse(xhr.responseText)); } catch(e) { reject(new Error('Invalid response')); } };
        xhr.onerror   = () => reject(new Error('Network error'));
        xhr.ontimeout = () => reject(new Error('Timed out after 5 min'));
        xhr.send();
      });
    } else {
      const res = await fetch(url, { headers:{'X-Requested-With':'XMLHttpRequest'} });
      d = await res.json();
    }

    if (d.success) {
      navSetBtn(type, 'done');
      navShowStrip('✅ ' + (d.message || (isImport ? 'Fund list imported successfully!' : 'NAV updated successfully!')), 'success');
      setTimeout(() => navSetBtn(type, 'idle'), 4000);
      showToast(isImport ? '✅ Fund list import complete!' : '✅ NAV updated successfully!', 'success');
      loadStats();
    } else {
      throw new Error(d.message || 'Failed');
    }
  } catch(err) {
    navSetBtn(type, 'error');
    navShowStrip('⚠️ ' + err.message, 'error');
    showToast('⚠️ ' + err.message, 'error');
  }
}

function confirmImportAmfi() {
  if (!confirm('📥 Import Full Fund List?\n\nThis downloads ~14,000+ funds from AMFI.\nMay take 2–5 minutes — page will stay open.\n\nProceed?')) return;
  runNavUpdate('full_import');
}

async function updateStockPrices() {
  const btn    = document.getElementById('btnUpdateStocks');
  const result = document.getElementById('stockUpdateResult');
  btn.disabled = true; result.style.display='none';
  try {
    const d = await API.post('/api/router.php', { action:'stocks_refresh_prices' });
    result.style.display='block';
    result.style.background='rgba(34,197,94,.1)';
    result.style.color='var(--success)';
    result.innerHTML = `<strong>${d.message||'Stock prices updated.'}</strong>`;
  } catch(e) {
    result.style.display='block';
    result.style.background='rgba(239,68,68,.1)';
    result.style.color='var(--danger)';
    result.innerHTML = `<strong>Error: ${e.message}</strong>`;
  } finally { btn.disabled=false; }
}

async function recalcHoldings() {
  if (!confirm('Recalculate all holdings from transactions?\nThis may take 1-2 minutes.')) return;

  const btn    = document.getElementById('btnRecalc');
  const label  = document.getElementById('recalcBtnLabel');
  const status = document.getElementById('recalcStatus');

  // Working state
  btn.disabled = true;
  btn.style.background    = '#d97706';
  btn.style.color         = '#fff';
  btn.style.borderColor   = '#d97706';
  label.innerHTML = '&#9696; Working...';
  status.style.display = 'none';

  try {
    const res = await API.post('/api/router.php', { action: 'admin_recalc_holdings', csrf_token: window.CSRF_TOKEN });

    // Done state — revert button
    btn.disabled = false;
    btn.style.background  = '';
    btn.style.color       = '';
    btn.style.borderColor = '';
    label.innerHTML = '&#128260; Recalculate All Holdings';

    // Show result message below button
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const dateStr = now.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    const isErr   = (res.data?.errors || 0) > 0;

    status.style.display = 'block';
    status.innerHTML = `
      <div style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border-radius:8px;
           background:${isErr ? 'rgba(239,68,68,.08)' : 'rgba(34,197,94,.08)'};
           border:1px solid ${isErr ? 'rgba(239,68,68,.25)' : 'rgba(34,197,94,.25)'};">
        <span style="font-size:16px;line-height:1.3;">${isErr ? '⚠️' : '✅'}</span>
        <div>
          <div style="font-weight:600;color:${isErr ? 'var(--danger)' : 'var(--success)'};">
            ${res.message || 'Done'}
          </div>
          <div style="color:var(--text-muted);font-size:12px;margin-top:2px;">
            Last recalculated: ${dateStr} at ${timeStr}
          </div>
        </div>
      </div>`;

    if (!isErr) showToast('Holdings recalculated successfully!', 'success');
    else showToast(res.message, 'error');

  } catch(e) {
    btn.disabled = false;
    btn.style.background  = '';
    btn.style.color       = '';
    btn.style.borderColor = '';
    label.innerHTML = '&#128260; Recalculate All Holdings';
    status.style.display = 'block';
    status.innerHTML = `<div style="color:var(--danger);font-size:13px;">&#9888; Error: ${e.message}</div>`;
    showToast('Recalculation failed: ' + e.message, 'error');
  }
}

// ── Audit Log ─────────────────────────────────────────────
async function loadAuditLog() {
  const filter = document.getElementById('auditFilter')?.value || '';
  try {
    const d = await API.get(`/api/router.php?action=admin_audit_log&limit=${AUDIT_LIMIT}&offset=${auditOffset}&filter=${encodeURIComponent(filter)}`);
    const logs  = d.data?.logs  || d.logs  || [];
    const total = d.data?.total || d.total || 0;
    document.getElementById('auditTotalText').textContent = `Showing ${auditOffset+1}–${Math.min(auditOffset+AUDIT_LIMIT, total)} of ${total} entries`;
    document.getElementById('auditPageNum').textContent = Math.floor(auditOffset/AUDIT_LIMIT)+1;
    document.getElementById('auditPrev').disabled = auditOffset===0;
    document.getElementById('auditNext').disabled = auditOffset+AUDIT_LIMIT >= total;

    const tbody = document.getElementById('auditBody');
    if (!logs.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary">No audit records.</td></tr>';
      return;
    }
    tbody.innerHTML = logs.map(l => `
      <tr>
        <td class="text-secondary text-xs">${l.id}</td>
        <td>${esc(l.user_name||'System')}<br><small class="text-secondary">${esc(l.user_email||'')}</small></td>
        <td><span class="badge badge-secondary text-xs">${esc(l.action)}</span></td>
        <td class="text-secondary text-xs">${esc(l.entity_type||'—')} ${l.entity_id ? '#'+l.entity_id : ''}</td>
        <td class="text-secondary text-xs">${esc(l.ip_address||'—')}</td>
        <td class="text-secondary text-xs">${l.created_at}</td>
      </tr>`).join('');
  } catch(e) { console.error(e); }
}

function auditPage(dir) {
  auditOffset = Math.max(0, auditOffset + dir*AUDIT_LIMIT);
  loadAuditLog();
}

// ── DB Manager ────────────────────────────────────────────
async function loadDbTables() {
  const body = document.getElementById('dbTableBody');
  const info = document.getElementById('dbTotalText');
  if (!body) return;
  body.innerHTML = `<tr><td colspan="4" class="text-center" style="padding:40px;"><div class="spinner"></div></td></tr>`;

  try {
    const d = await API.post('/api/router.php', { action: 'admin_db_list' });
    const tables = d.data?.tables || d.data || [];
    const totalRows = tables.reduce((s, t) => s + (t.rows || 0), 0);
    if (info) info.textContent = `${tables.length} tables · ${totalRows.toLocaleString()} total records`;

    if (tables.length === 0) {
      body.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted);">No tables found.</td></tr>`;
      return;
    }

    body.innerHTML = tables.map((t, i) => {
      const isProtected = t.protected;
      const rowCount = Number(t.rows).toLocaleString('en-IN');
      return `
      <tr id="dbrow-${t.name}">
        <td style="color:var(--text-muted);font-size:12px;">${i+1}</td>
        <td>
          <span style="font-weight:500;font-family:monospace;">${t.name}</span>
          ${isProtected ? `<span style="font-size:10px;margin-left:6px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;font-weight:600;">PROTECTED</span>` : ''}
        </td>
        <td class="text-center">
          <span id="dbcount-${t.name}" style="font-weight:600;font-size:14px;color:${t.rows > 0 ? 'var(--text-primary)' : 'var(--text-muted)'};">${rowCount}</span>
        </td>
        <td class="text-center">
          ${isProtected
            ? `<span style="font-size:12px;color:var(--text-muted);">🔒 Protected</span>`
            : `<button onclick="deleteTableRecords('${t.name}')"
                style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;font-size:12px;font-weight:500;padding:4px 12px;border-radius:6px;cursor:pointer;">
                🗑 Clear
              </button>`
          }
        </td>
      </tr>`;
    }).join('');
  } catch(e) {
    console.error('loadDbTables error:', e);
    body.innerHTML = `<tr><td colspan="4" style="color:#dc2626;text-align:center;padding:20px;font-size:13px;">
      ⚠️ Error: ${e.message}<br>
      <small style="color:var(--text-muted);">Check browser console (F12) for details</small>
    </td></tr>`;
    if (info) info.textContent = 'Failed to load';
  }
}

async function deleteTableRecords(tableName) {
  showConfirm({
    title: `Clear "${tableName}"?`,
    message: `All records from <strong>${tableName}</strong> will be permanently deleted. This cannot be undone.`,
    okText: 'Yes, Clear',
    okClass: 'btn-danger',
    onConfirm: async () => {
      await API.post('/api/router.php', { action: 'admin_db_truncate_one', table: tableName });
      document.getElementById(`dbcount-${tableName}`).textContent = '0';
      showToast(`"${tableName}" cleared.`, 'success');
      loadDbTables();
    }
  });
}

async function deleteAllTables() {
  // Two-step: first confirm with typed input
  const overlay = document.createElement('div');
  overlay.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;display:flex;align-items:center;justify-content:center;`;
  overlay.innerHTML = `
    <div style="background:var(--bg-surface);border-radius:14px;padding:32px;max-width:440px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.3);">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <span style="font-size:28px;">⚠️</span>
        <h3 style="margin:0;font-size:18px;font-weight:700;color:#dc2626;">Delete ALL Records?</h3>
      </div>
      <p style="margin:0 0 8px;color:var(--text-secondary);font-size:14px;">This will permanently delete <strong>all user data</strong> from every table (protected tables like users/funds will be kept safe).</p>
      <p style="margin:0 0 16px;color:var(--text-secondary);font-size:14px;">Type <strong style="color:#dc2626;">DELETE ALL</strong> below to confirm:</p>
      <input id="deleteAllInput" type="text" placeholder="Type DELETE ALL"
        style="width:100%;box-sizing:border-box;padding:10px 14px;border:2px solid var(--border);border-radius:8px;font-size:14px;margin-bottom:16px;background:var(--bg-input,#fff);color:var(--text-primary);">
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button id="deleteAllCancelBtn" style="padding:9px 20px;border:1px solid var(--border);border-radius:8px;background:transparent;cursor:pointer;font-size:13px;color:var(--text-primary);">Cancel</button>
        <button id="deleteAllConfirmBtn" disabled
          style="padding:9px 20px;border:none;border-radius:8px;background:#dc2626;color:#fff;font-weight:600;font-size:13px;cursor:pointer;opacity:.4;transition:opacity .2s;">
          🗑 Delete Everything
        </button>
      </div>
    </div>`;

  document.body.appendChild(overlay);

  const input   = overlay.querySelector('#deleteAllInput');
  const confirmBtn = overlay.querySelector('#deleteAllConfirmBtn');
  const cancelBtn  = overlay.querySelector('#deleteAllCancelBtn');

  input.addEventListener('input', () => {
    const match = input.value.trim() === 'DELETE ALL';
    confirmBtn.disabled = !match;
    confirmBtn.style.opacity = match ? '1' : '.4';
    confirmBtn.style.cursor  = match ? 'pointer' : 'default';
  });

  cancelBtn.addEventListener('click', () => overlay.remove());
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

  confirmBtn.addEventListener('click', async () => {
    confirmBtn.textContent = 'Deleting…';
    confirmBtn.disabled = true;
    try {
      const d = await API.post('/api/router.php', { action: 'admin_db_truncate_all' });
      overlay.remove();
      showToast(`✅ Done! ${d.data?.count || ''} tables cleared.`, 'success');
      loadDbTables();
    } catch(e) {
      showToast(`Error: ${e.message}`, 'error');
      overlay.remove();
    }
  });

  input.focus();
}

document.getElementById('auditFilter')?.addEventListener('input', () => { auditOffset=0; loadAuditLog(); });

// ── Peak NAV — Background Processor ───────────────────────
let pnRunning   = false;
let pnStopped   = false;
let pnXhr       = null;
let pnPollTimer = null;

function pnSetBtn(state) {
  const btn  = document.getElementById('btnRunPeakNav');
  const icon = document.getElementById('btnRunPeakNavIcon');
  const txt  = document.getElementById('btnRunPeakNavText');
  if (!btn) return;
  const states = {
    idle:     { label:' Run Peak NAV',         icon:'▶',  disabled:false, cls:'btn-primary' },
    running:  { label:' Processing...',         icon:'⏳', disabled:true,  cls:'btn-primary' },
    waiting:  { label:' Next batch soon...',    icon:'🔄', disabled:true,  cls:'btn-primary' },
    done:     { label:' All Up to Date!',       icon:'✅', disabled:false, cls:'btn-success'  },
    error:    { label:' Run Peak NAV',          icon:'▶',  disabled:false, cls:'btn-primary' },
  };
  const s = states[state] || states.idle;
  txt.textContent  = s.label;
  icon.textContent = s.icon;
  btn.disabled     = s.disabled;
}

async function runPeakNavBackground() {
  if (pnRunning) return;
  pnRunning = true;
  pnStopped = false;

  // Clear old stop flag in DB
  await fetch(window.APP_URL + '/peak_nav/api.php?action=clear_stop', {method:'POST'}).catch(()=>{});

  pnSetBtn('running');

  // Start polling tiles every 2s while running
  if (pnPollTimer) clearInterval(pnPollTimer);
  pnPollTimer = setInterval(loadPeakNavStatus, 2000);

  pnFireBatch();
}

function pnFireBatch() {
  const parallel = 8;
  const url = window.APP_URL + '/peak_nav/processor.php?parallel=' + parallel + '&t=' + Date.now();

  pnXhr = new XMLHttpRequest();
  pnXhr.open('GET', url, true);
  pnXhr.timeout = 150000;

  pnXhr.onload = () => { pnOnBatchDone(); };
  pnXhr.onerror = () => {
    if (!pnStopped) setTimeout(pnOnBatchDone, 2000);
  };
  pnXhr.ontimeout = () => {
    if (!pnStopped) pnOnBatchDone();
  };
  pnXhr.send();
}

async function pnOnBatchDone() {
  pnXhr = null;
  if (pnStopped) { pnRunning = false; pnSetBtn('idle'); return; }

  // Check if more work remains
  try {
    const d = await fetch(window.APP_URL + '/peak_nav/api.php?action=summary&_=' + Date.now(), {cache:'no-store'}).then(r=>r.json());
    const remaining = parseInt(d.not_done || 0);
    loadPeakNavStatus();

    if (remaining === 0) {
      // All done
      pnRunning = false;
      if (pnPollTimer) { clearInterval(pnPollTimer); pnPollTimer = null; }
      pnSetBtn('done');
      showToast('✅ Peak NAV — All schemes up to date!', 'success');
      setTimeout(() => pnSetBtn('idle'), 4000);
    } else {
      // More batches needed — auto-continue after 1.5s
      pnSetBtn('waiting');
      setTimeout(() => {
        if (!pnStopped) { pnSetBtn('running'); pnFireBatch(); }
      }, 1500);
    }
  } catch(e) {
    pnRunning = false;
    pnSetBtn('error');
    if (pnPollTimer) { clearInterval(pnPollTimer); pnPollTimer = null; }
  }
}

// Live tile refresh
async function loadPeakNavStatus() {
  try {
    const d = await fetch(window.APP_URL + '/peak_nav/api.php?action=summary&_=' + Date.now(), { cache: 'no-store' }).then(r => r.json());
    const c = d.counts || {};
    const pct = d.pct || 0;
    const fmt = n => (+n||0).toLocaleString('en-IN');
    const el = id => document.getElementById(id);
    if (el('pnTotal'))   el('pnTotal').textContent   = fmt(c.total);
    if (el('pnStale'))   el('pnStale').textContent   = fmt(c.needs_update);
    if (el('pnPending')) el('pnPending').textContent = fmt(+c.pending + +c.working);
    if (el('pnDone'))    el('pnDone').textContent    = fmt(c.completed);
    if (el('pnErrors'))  el('pnErrors').textContent  = fmt(c.errors);
    if (el('pnPct'))     el('pnPct').textContent     = pct + '%';
    if (el('pnBar')) {
      el('pnBar').style.width = pct + '%';
      el('pnBar').style.background = pct >= 100 ? 'var(--success)' : 'var(--accent)';
    }
  } catch(e) {}
}

// Initial load + idle poll every 10s
loadPeakNavStatus();
setInterval(() => { if (!pnRunning) loadPeakNavStatus(); }, 10000);

function formatDate(d) {
  if (!d) return '—';
  return d.substring(0,10).split('-').reverse().join('-');
}

// Info button toggle
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.nav-info-btn');
  if (!btn) return;
  const id  = 'info-' + btn.dataset.info;
  const box = document.getElementById(id);
  if (!box) return;
  const isOpen = box.style.display === 'block';
  // Close all others
  document.querySelectorAll('.nav-info-box').forEach(b => b.style.display = 'none');
  document.querySelectorAll('.nav-info-btn').forEach(b => b.style.background = '');
  if (!isOpen) {
    box.style.display = 'block';
    btn.style.background = 'var(--accent)';
    btn.style.color = '#fff';
    btn.style.borderColor = 'var(--accent)';
  }
});
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>