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
  <button class="admin-tab" data-tab="fundrules" onclick="adminSwitchTab('fundrules',this)">⚙️ Fund Rules</button>
  <button class="admin-tab" data-tab="audit"    onclick="adminSwitchTab('audit',this)">Audit Log</button>
  <button class="admin-tab" data-tab="dbmgr" onclick="adminSwitchTab('dbmgr',this)">🗄️ DB Manager</button>
  <button class="admin-tab" data-tab="setup" onclick="adminSwitchTab('setup',this)">🚀 Setup &amp; Backup</button>
</div>

<!-- ═══════ TAB: OVERVIEW ═══════ -->
<div id="tab-overview" class="admin-tab-content">
  <div class="cards-grid cards-grid-4 mb-4" id="statsCards">
    <div class="stat-card">
      <div class="stat-label">Active Users</div>
      <div class="stat-value" id="statUsers">—</div>
      <div id="statUsersBreakdown" style="font-size:11px;color:var(--text-muted);margin-top:4px;line-height:1.6;">—</div>
    </div>
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
            <th>Senior</th><th>MF Holdings</th>
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

</div>

<!-- ═══════ TAB: NAV & DATA ═══════ -->
<div id="tab-nav" class="admin-tab-content" style="display:none">

  <!-- ── SECTION: MUTUAL FUNDS ──────────────────────────────── -->
  <div class="nav-section-header">
    <span class="nav-section-icon">📈</span>
    <div>
      <div class="nav-section-title">Mutual Funds</div>
      <div class="nav-section-sub">NAV, fund list, expense ratios, exit loads, peak NAV — sabhi MF-related data operations</div>
    </div>
  </div>

  <div class="nav-ops-grid">

    <!-- 1. Daily NAV Update -->
    <div class="nav-op-card" id="navOpCard_nav">
      <div class="nav-op-left">
        <div class="nav-op-icon">📡</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Update Today's NAV
            <span id="navAutoStatus" class="nav-lastrun-badge nav-badge-idle">⏳ Checking...</span>
          </div>
          <div class="nav-op-desc">AMFI India se aaj ke sabhi 14,000+ mutual funds ke latest NAV fetch karta hai. Holdings ka <strong>Current Value</strong> aur <strong>Gain/Loss</strong> yahi se calculate hota hai.</div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">🔄 Roz chalao — weekdays 10 PM ke baad</span>
            <span class="nav-lastrun" id="lastrun_nav">Last run: —</span>
          </div>
        </div>
      </div>
      <div class="nav-op-actions">
        <button class="btn btn-primary btn-sm" id="btnUpdateNav" onclick="runNavUpdate('nav_only')">
          <span id="navBtnIcon">🔄</span><span id="navBtnText"> Update NAV</span>
        </button>
      </div>
    </div>

    <!-- 2. Import Full Fund List -->
    <div class="nav-op-card" id="navOpCard_import">
      <div class="nav-op-left">
        <div class="nav-op-icon">📥</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Import Full Fund List</div>
          <div class="nav-op-desc">AMFI se poori fund list download karta hai (14,000+ schemes). Fresh install pe ya naye funds add hone pe run karo. <strong>2–5 minute</strong> lagta hai.</div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">📅 Monthly ya fresh install pe</span>
            <span class="nav-lastrun" id="lastrun_import">Last run: —</span>
          </div>
        </div>
      </div>
      <div class="nav-op-actions">
        <button class="btn btn-outline btn-sm" id="btnImportAmfi" onclick="confirmImportAmfi()">
          <span id="importBtnIcon">📥</span><span id="importBtnText"> Import Funds</span>
        </button>
      </div>
    </div>

    <!-- 3. TER / Expense Ratio -->
    <div class="nav-op-card" id="navOpCard_ter">
      <div class="nav-op-left">
        <div class="nav-op-icon">📊</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Expense Ratio (TER) Import</div>
          <div class="nav-op-desc">GitHub (captn3m0/india-mutual-fund-ter-tracker) se AMFI TER data import karta hai. <strong>Screener mein Expense Ratio column</strong> yahi se populate hota hai.</div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">📅 Mahine mein ek baar</span>
            <span class="nav-lastrun" id="lastrun_ter">Last run: —</span>
          </div>
        </div>
      </div>
      <div class="nav-op-actions">
        <button class="btn btn-outline btn-sm" id="btnImportTer" onclick="importTer()">
          <span id="terBtnIcon">📊</span><span id="terBtnText"> Import TER</span>
        </button>
      </div>
    </div>

    <!-- 4. Exit Load Seeder -->
    <div class="nav-op-card" id="navOpCard_el">
      <div class="nav-op-left">
        <div class="nav-op-icon">🚪</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Exit Load Seeder</div>
          <div class="nav-op-desc">SEBI rules ke basis pe har fund ka exit load set karta hai — Equity/Hybrid mein <strong>1% for 1yr</strong>, Debt/Index/ELSS/Liquid mein <strong>Nil</strong>. Nayi funds add hone ke baad run karo.</div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">📅 New funds add hone pe</span>
            <span class="nav-lastrun" id="lastrun_el">Last run: —</span>
          </div>
        </div>
      </div>
      <div class="nav-op-actions">
        <button class="btn btn-outline btn-sm" id="btnImportExitLoad" onclick="importExitLoad()">
          <span id="elBtnIcon">🚪</span><span id="elBtnText"> Seed Exit Loads</span>
        </button>
      </div>
    </div>

    <!-- 5. NPS NAV Update (t99) -->
    <div class="nav-op-card" id="navOpCard_nps">
      <div class="nav-op-left">
        <div class="nav-op-icon">🏛️</div>
        <div class="nav-op-info">
          <div class="nav-op-title">NPS NAV Update
            <span id="npsNavStatus" class="nav-lastrun-badge nav-badge-idle">⏳ Loading...</span>
          </div>
          <div class="nav-op-desc">PFRDA / NPS Trust se aaj ke sabhi NPS schemes ki NAV fetch karta hai. <strong>CAGR aur returns</strong> yahi se calculate hote hain. <em>Backfill</em> se last 5 years ki history ek baar download hogi.</div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">🔄 Roz chalao — shaam 9 PM ke baad</span>
            <span class="nav-lastrun" id="lastrun_nps">Last run: —</span>
          </div>
          <!-- Manual NAV entry for a specific scheme -->
          <div id="npsManualWrap" style="display:none;margin-top:10px;background:var(--bg-secondary);border-radius:8px;padding:10px;border:1px solid var(--border-color)">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:7px">Manual NAV Entry (agar auto-fetch fail ho)</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
              <div>
                <div style="font-size:10px;font-weight:700;color:var(--text-muted);margin-bottom:3px">Scheme</div>
                <select id="npsManualScheme" style="padding:6px 8px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-card);font-size:12px;min-width:200px">
                  <option value="">Select scheme...</option>
                </select>
              </div>
              <div>
                <div style="font-size:10px;font-weight:700;color:var(--text-muted);margin-bottom:3px">NAV (₹)</div>
                <input type="number" id="npsManualNav" step="0.0001" min="1" placeholder="e.g. 28.4512" style="padding:6px 8px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-card);font-size:12px;width:130px">
              </div>
              <div>
                <div style="font-size:10px;font-weight:700;color:var(--text-muted);margin-bottom:3px">Date</div>
                <input type="date" id="npsManualDate" value="<?= date('Y-m-d') ?>" style="padding:6px 8px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-card);font-size:12px">
              </div>
              <button onclick="npsManualSave()" style="padding:6px 14px;background:var(--accent);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer">Save NAV</button>
              <button onclick="document.getElementById('npsManualWrap').style.display='none'" style="padding:6px 10px;background:none;border:1px solid var(--border-color);border-radius:6px;font-size:12px;cursor:pointer;color:var(--text-muted)">✕</button>
            </div>
          </div>
        </div>
      </div>
      <div class="nav-op-actions" style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <button class="btn btn-primary btn-sm" id="btnNpsNavDaily" onclick="npsNavRun('daily')">
          🔄 Daily Update
        </button>
        <button class="btn btn-outline btn-sm" id="btnNpsBackfill" onclick="npsNavRun('backfill')">
          📥 Backfill 5Y History
        </button>
        <button class="btn btn-outline btn-sm" onclick="npsNavRun('returns')">
          📊 Recalc Returns
        </button>
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('npsManualWrap').style.display='block';loadNpsSchemes()">
          ✏️ Manual Entry
        </button>
      </div>
    </div>

    <!-- 5. Peak NAV -->
    <div class="nav-op-card" id="navOpCard_peak">
      <div class="nav-op-left">
        <div class="nav-op-icon">🏔️</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Peak NAV — All-Time High</div>
          <div class="nav-op-desc">MFAPI.in se har fund ka poora NAV history fetch karta hai aur <strong>sabse zyada NAV (ATH)</strong> save karta hai. Holdings page pe <strong>Drawdown column</strong> yahi se calculate hota hai.</div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">📅 Mahine mein ek baar ya naye funds ke baad</span>
            <span class="nav-lastrun" id="lastrun_peak">Last run: —</span>
          </div>
          <!-- Compact progress tiles -->
          <div id="pnTiles" style="display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-top:10px;">
            <div class="pn-tile pn-total">  <div class="pn-num" id="pnTotal">—</div>  <div class="pn-lbl">Total</div></div>
            <div class="pn-tile pn-stale">  <div class="pn-num" id="pnStale">—</div>  <div class="pn-lbl">Needs Update</div></div>
            <div class="pn-tile pn-pending"><div class="pn-num" id="pnPending">—</div><div class="pn-lbl">Pending</div></div>
            <div class="pn-tile pn-done">   <div class="pn-num" id="pnDone">—</div>   <div class="pn-lbl">Done</div></div>
            <div class="pn-tile pn-err">    <div class="pn-num" id="pnErrors">—</div> <div class="pn-lbl">Errors</div></div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
            <div style="flex:1;background:var(--border);border-radius:99px;height:6px;overflow:hidden;">
              <div id="pnBar" style="height:100%;width:0%;background:var(--accent);border-radius:99px;transition:width .6s;"></div>
            </div>
            <span id="pnPct" style="font-size:11px;font-weight:700;color:var(--accent);min-width:32px;text-align:right;">0%</span>
          </div>
        </div>
      </div>
      <div class="nav-op-actions">
        <button class="btn btn-primary btn-sm" id="btnRunPeakNav" onclick="runPeakNavBackground()">
          <span id="btnRunPeakNavIcon">▶</span><span id="btnRunPeakNavText"> Run Peak NAV</span>
        </button>
        <a href="<?= APP_URL ?>/peak_nav/status.php" target="_blank" class="btn btn-ghost btn-sm" style="margin-top:6px;">↗ Full Tracker</a>
      </div>
    </div>

    <!-- 6. Holdings Recalc -->
    <div class="nav-op-card" id="navOpCard_recalc">
      <div class="nav-op-left">
        <div class="nav-op-icon">🔄</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Holdings Recalculation</div>
          <div class="nav-op-desc"><code>mf_transactions</code> table se scratch se sabhi holdings dobara calculate karta hai — total units, invested amount, avg cost NAV, gain/loss sab reset hota hai. <strong>CSV import ke baad ya data inconsistency pe use karo.</strong></div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">⚠️ Zarurat pe hi chalao — 1–2 min lagta hai</span>
            <span class="nav-lastrun" id="lastrun_recalc">Last run: —</span>
          </div>
          <div id="recalcStatus" style="margin-top:8px;font-size:12px;display:none;"></div>
        </div>
      </div>
      <div class="nav-op-actions">
        <button class="btn btn-outline btn-sm" id="btnRecalc" onclick="recalcHoldings()">
          <span id="recalcBtnLabel">🔄 Recalculate</span>
        </button>
      </div>
    </div>

  </div>

  <!-- inline status strips -->
  <div id="navStatusStrip" style="display:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;margin:8px 0 16px;"></div>
  <div id="terResult" style="display:none;font-size:12px;padding:8px 12px;border-radius:8px;margin:8px 0 16px;"></div>
  <div id="elResult" style="display:none;font-size:12px;padding:8px 12px;border-radius:8px;margin:8px 0 16px;"></div>

  <!-- ── SECTION: STOCKS & ETF ───────────────────────────────── -->
  <div class="nav-section-header" style="margin-top:8px;">
    <span class="nav-section-icon">📉</span>
    <div>
      <div class="nav-section-title">Stocks & ETF</div>
      <div class="nav-section-sub">Yahoo Finance se live stock prices refresh karo</div>
    </div>
  </div>

  <div class="nav-ops-grid">
    <div class="nav-op-card" id="navOpCard_stocks">
      <div class="nav-op-left">
        <div class="nav-op-icon">💹</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Refresh Stock Prices</div>
          <div class="nav-op-desc">Yahoo Finance se tumhare portfolio ke sabhi stocks ke latest prices fetch karta hai aur <code>stock_master.latest_price</code> update karta hai. <strong>Market close ke baad (3:30 PM+) run karo</strong> taaki closing price mile.</div>
          <div class="nav-op-meta">
            <span class="nav-op-freq">🔄 Roz — weekdays 3:30 PM ke baad</span>
            <span class="nav-lastrun" id="lastrun_stocks">Last run: —</span>
          </div>
          <div id="stockUpdateResult" style="display:none;margin-top:8px;font-size:12px;"></div>
        </div>
      </div>
      <div class="nav-op-actions">
        <button class="btn btn-primary btn-sm" id="btnUpdateStocks" onclick="updateStockPrices()">
          💹 Refresh Prices
        </button>
      </div>
    </div>
  </div>

  <!-- ── SECTION: SYSTEM ────────────────────────────────────── -->
  <div class="nav-section-header" style="margin-top:8px;">
    <span class="nav-section-icon">⚙️</span>
    <div>
      <div class="nav-section-title">System / Cron Reference</div>
      <div class="nav-section-sub">Server hosting pe cron jobs ka reference — XAMPP pe manually upar ke buttons use karo</div>
    </div>
  </div>

  <div class="nav-ops-grid">
    <div class="nav-op-card">
      <div class="nav-op-left">
        <div class="nav-op-icon">⏰</div>
        <div class="nav-op-info">
          <div class="nav-op-title">Cron Schedule <span style="font-size:11px;font-weight:400;color:var(--text-muted);background:var(--bg-secondary);padding:1px 7px;border-radius:4px;margin-left:4px;">Reference Only</span></div>
          <div class="nav-op-desc" style="margin-bottom:10px;">XAMPP localhost pe cron kaam nahi karta — upar ke buttons manually use karo. Agar kabhi server pe host karo tab yeh add karna:</div>
          <div class="code-block" style="font-size:11px;line-height:1.9;">
            <code>
# Daily NAV — 10:15 PM (after AMFI publishes)<br>
15 22 * * * php /path/to/wealthdash/cron/update_nav_daily.php<br><br>
# Daily Stocks — 6:30 PM weekdays<br>
30 18 * * 1-5 php /path/to/wealthdash/cron/update_stocks_daily.php<br><br>
# FD Maturity Alerts — 9 AM daily<br>
0 9 * * * php /path/to/wealthdash/cron/fd_maturity_alert.php<br><br>
# NPS NAV — 8 PM daily<br>
0 20 * * * php /path/to/wealthdash/cron/nps_nav_scraper.php
            </code>
          </div>
        </div>
      </div>
      <div class="nav-op-actions"></div>
    </div>
  </div>

</div>

<!-- ═══════ TAB: FUND RULES ═══════ -->
<div id="tab-fundrules" class="admin-tab-content" style="display:none">
<div style="display:grid;grid-template-columns:240px 1fr;gap:12px;align-items:start;">

  <!-- LEFT: Category Browser -->
  <div class="card" style="position:sticky;top:16px;">
    <div class="card-header" style="padding:12px 16px;">
      <h3 class="card-title" style="margin:0;font-size:14px;">📂 Browse by Category</h3>
    </div>
    <div id="frCatList" style="max-height:70vh;overflow-y:auto;">
      <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">
        <div class="spinner"></div><br>Loading categories…
      </div>
    </div>
    <div style="padding:10px 14px;border-top:1px solid var(--border-color);">
      <button class="btn btn-ghost btn-sm" onclick="frLoadCategories()" style="width:100%;font-size:12px;">↻ Refresh</button>
    </div>
  </div>

  <!-- RIGHT: Fund List + Bulk Editor -->
  <div>

    <!-- Bulk Action Bar (hidden until selection) -->
    <div id="frBulkBar" style="display:none;margin-bottom:10px;padding:10px 12px;border-radius:8px;
         background:rgba(37,99,235,.07);border:1.5px solid rgba(37,99,235,.25);
         display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <span id="frSelCount" style="font-weight:700;color:var(--accent);font-size:13px;">0 selected</span>
      <span style="color:var(--text-muted);font-size:12px;">→</span>
      <div style="display:flex;align-items:center;gap:4px;">
        <label style="font-size:11px;font-weight:600;white-space:nowrap;">LTCG (d)</label>
        <input type="number" id="frBulkLtcg" class="form-control" style="width:70px;padding:3px 6px;font-size:12px;" value="365" min="1">
        <button class="fr-preset" onclick="document.getElementById('frBulkLtcg').value=365">1yr</button>
        <button class="fr-preset" onclick="document.getElementById('frBulkLtcg').value=730">2yr</button>
        <button class="fr-preset" onclick="document.getElementById('frBulkLtcg').value=1095">3yr</button>
      </div>
      <div style="display:flex;align-items:center;gap:4px;">
        <label style="font-size:11px;font-weight:600;white-space:nowrap;">Lock-in (d)</label>
        <input type="number" id="frBulkLock" class="form-control" style="width:70px;padding:3px 6px;font-size:12px;" value="0" min="0">
        <button class="fr-preset" onclick="document.getElementById('frBulkLock').value=0">None</button>
        <button class="fr-preset" onclick="document.getElementById('frBulkLock').value=1095">3yr</button>
        <button class="fr-preset" onclick="document.getElementById('frBulkLock').value=1825">5yr</button>
      </div>
      <button class="btn btn-primary btn-sm" id="frBulkSaveBtn" onclick="frBulkSave()" style="margin-left:auto;font-size:12px;padding:5px 12px;">
        <span id="frBulkSaveLbl">💾 Apply</span>
      </button>
      <button class="btn btn-ghost btn-sm" onclick="frDeselectAll()" style="font-size:12px;padding:5px 8px;">✕</button>
    </div>

    <!-- Search + Filter row -->
    <div style="display:flex;gap:8px;margin-bottom:12px;align-items:center;">
      <div style="position:relative;flex:1;">
        <input type="text" id="frSearchInput" class="form-control"
          placeholder="Search fund name or code…"
          style="padding-left:34px;"
          oninput="frOnSearch(this.value)">
        <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);opacity:.4;" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <div id="frDropdown" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:300;
             background:var(--bg-card);border:1px solid var(--border-color);border-radius:8px;
             box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;margin-top:4px;"></div>
      </div>
      <span id="frActiveCategory" style="display:none;padding:4px 10px;border-radius:6px;font-size:12px;
            font-weight:600;background:var(--accent);color:#fff;white-space:nowrap;"></span>
      <button id="frSelectAllBtn" class="btn btn-ghost btn-sm" style="display:none;white-space:nowrap;" onclick="frToggleSelectAll()">☐ Select All</button>
    </div>

    <!-- Fund Results Table -->
    <div class="card" style="overflow:hidden;">
      <div id="frTableHeader" style="display:none;padding:8px 16px;background:var(--bg-secondary);
           border-bottom:1px solid var(--border-color);font-size:12px;color:var(--text-muted);">
      </div>
      <div class="table-wrapper" style="max-height:65vh;overflow-y:auto;">
        <table class="table" style="margin:0;font-size:13px;table-layout:fixed;width:100%;" id="frFundsTable">
          <colgroup>
            <col style="width:32px;">
            <col><!-- fund name takes remaining -->
            <col style="width:80px;">
            <col style="width:80px;">
            <col style="width:44px;">
          </colgroup>
          <thead style="position:sticky;top:0;z-index:10;background:var(--bg-card);">
            <tr>
              <th style="width:32px;padding:8px 6px;"><input type="checkbox" id="frCheckAll" onchange="frCheckAllToggle(this)" title="Select all visible"></th>
              <th style="padding:8px 6px;">Fund</th>
              <th class="text-center" style="width:80px;padding:8px 4px;">LTCG</th>
              <th class="text-center" style="width:80px;padding:8px 4px;">Lock-in</th>
              <th class="text-center" style="width:44px;padding:8px 4px;">Edit</th>
            </tr>
          </thead>
          <tbody id="frFundsBody">
            <tr><td colspan="5" style="padding:40px;text-align:center;color:var(--text-muted);">
              ← Select a category from the left, or search above
            </td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Inline single-fund editor (appears below table) -->
    <div id="frEditor" style="display:none;margin-top:12px;">
      <div class="card">
        <div class="card-header" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;">
          <div>
            <div style="font-size:14px;font-weight:600;" id="frFundName">—</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;" id="frFundMeta">—</div>
          </div>
          <button class="btn btn-ghost btn-sm" onclick="frCloseEditor()">✕</button>
        </div>
        <div class="card-body" style="padding:14px 16px;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;">
            <div>
              <label class="form-label" style="font-weight:600;font-size:12px;">LTCG Period (days)</label>
              <input type="number" id="frLtcgDays" class="form-control" min="1" max="3650">
              <div style="margin-top:5px;display:flex;gap:5px;">
                <button class="fr-preset" onclick="frSetLtcg(365)">365d</button>
                <button class="fr-preset" onclick="frSetLtcg(730)">730d</button>
                <button class="fr-preset" onclick="frSetLtcg(1095)">1095d</button>
              </div>
            </div>
            <div>
              <label class="form-label" style="font-weight:600;font-size:12px;">Lock-in (days, 0 = none)</label>
              <input type="number" id="frLockDays" class="form-control" min="0" max="7300">
              <div style="margin-top:5px;display:flex;gap:5px;">
                <button class="fr-preset" onclick="frSetLock(0)">0</button>
                <button class="fr-preset" onclick="frSetLock(1095)">1095d</button>
                <button class="fr-preset" onclick="frSetLock(1825)">1825d</button>
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <button class="btn btn-primary btn-sm" id="frSaveBtn" onclick="frSave()">
              <span id="frSaveBtnLabel">💾 Save</span>
            </button>
            <button class="btn btn-ghost btn-sm" onclick="frCloseEditor()">Cancel</button>
            <span id="frSaveStatus" style="font-size:12px;"></span>
          </div>
          <input type="hidden" id="frFundId">
        </div>
      </div>
    </div>

  </div><!-- end right col -->
</div><!-- end grid -->
</div><!-- end tab-fundrules -->

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
<!-- ═══════ TAB: SETUP & BACKUP ═══════ -->
<div id="tab-setup" class="admin-tab-content" style="display:none">

  <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

    <!-- ── LEFT: Setup Wizard ──────────────────────────────────── -->
    <div>

      <!-- Progress -->
      <div class="card" style="margin-bottom:16px;padding:16px 20px;" id="setupProgressCard">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
          <span style="font-size:13px;font-weight:700;color:var(--text-primary);">🚀 Setup Progress</span>
          <span style="font-size:13px;font-weight:700;color:var(--accent);font-family:monospace;" id="setupPct">0%</span>
        </div>
        <div style="height:6px;background:var(--border);border-radius:99px;overflow:hidden;">
          <div id="setupFill" style="height:100%;width:0%;background:linear-gradient(90deg,var(--accent),#7dd3fc);border-radius:99px;transition:width .5s ease;"></div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:6px;" id="setupSubtitle">Nayi machine pe ye steps follow karo — ek baar mein sab sahi chal jayega.</div>
      </div>

      <!-- Steps -->
      <div style="display:flex;flex-direction:column;border:1px solid var(--border);border-radius:12px;overflow:hidden;" id="setupStepsList">

        <!-- Step 1 -->
        <div class="setup-step-card" id="ss-1" data-step="1">
          <div class="ss-num" id="ssnum-1">1</div>
          <div class="ss-body">
            <div class="ss-title">
              XAMPP Download &amp; Install karo
              <span class="ss-badge ss-req">Required</span>
            </div>
            <p class="ss-desc">
              XAMPP install karo aur <code>Apache</code> + <code>MySQL</code> dono services start karo.
              PHP 8.0+ hona chahiye. phpMyAdmin accessible hoga: <code>localhost/phpmyadmin</code>
            </p>
            <div class="ss-actions">
              <a class="btn btn-primary btn-sm" href="https://www.apachefriends.org/download.html" target="_blank" style="font-size:12px;">⬇ XAMPP Download</a>
              <button class="btn btn-sm ss-mark" onclick="ssMarkDone(1)">✓ Done</button>
              <span id="sstag-1"></span>
            </div>
          </div>
        </div>

        <!-- Step 2 -->
        <div class="setup-step-card ss-locked" id="ss-2" data-step="2">
          <div class="ss-num" id="ssnum-2">2</div>
          <div class="ss-body">
            <div class="ss-title">
              Schema SQL Download &amp; Import karo
              <span class="ss-badge ss-req">Required</span>
            </div>
            <p class="ss-desc">
              Schema download karo. phpMyAdmin open karo → <code>New</code> →
              Database name: <code>wealthdash</code> → Create → <code>Import</code> tab →
              downloaded <code>.sql</code> select karo → Go.
              Saari tables automatically ban jayengi.
            </p>
            <div class="ss-actions">
              <button class="btn btn-primary btn-sm ss-dl" onclick="setupDownload('schema')" style="font-size:12px;">⬇ Schema Download</button>
              <button class="btn btn-sm ss-mark" onclick="ssMarkDone(2)">✓ Done</button>
              <span id="sstag-2"></span>
            </div>
          </div>
        </div>

        <!-- Step 3 -->
        <div class="setup-step-card ss-locked" id="ss-3" data-step="3">
          <div class="ss-num" id="ssnum-3">3</div>
          <div class="ss-body">
            <div class="ss-title">
              Seed Data Import karo
              <span class="ss-badge ss-opt">Optional</span>
            </div>
            <p class="ss-desc">
              Fund houses (36 AMCs), NPS schemes, aur common stocks ka default data.
              Schema import ke <em>baad</em> same database mein import karo.
              Iske bina fund screener aur NPS kaam nahi karega.
            </p>
            <div class="ss-actions">
              <button class="btn btn-primary btn-sm ss-dl" onclick="setupDownload('seed')" style="font-size:12px;">⬇ Seed Data Download</button>
              <button class="btn btn-sm ss-mark" onclick="ssMarkDone(3)">✓ Done</button>
              <span id="sstag-3"></span>
            </div>
          </div>
        </div>

        <!-- Step 4 -->
        <div class="setup-step-card ss-locked" id="ss-4" data-step="4">
          <div class="ss-num" id="ssnum-4">4</div>
          <div class="ss-body">
            <div class="ss-title">
              .env File Configure karo
              <span class="ss-badge ss-req">Required</span>
            </div>
            <p class="ss-desc">
              Template download karo → copy karke <code>.env</code> naam do →
              project root mein rakho → DB credentials set karo.
              XAMPP default: <code>DB_HOST=localhost</code>, <code>DB_USER=root</code>, <code>DB_PASS=</code> (empty).
              <code>APP_URL</code> apna localhost path set karo.
            </p>
            <div class="ss-actions">
              <button class="btn btn-primary btn-sm ss-dl" onclick="setupDownload('env')" style="font-size:12px;">⬇ .env Template</button>
              <button class="btn btn-sm ss-mark" onclick="ssMarkDone(4)">✓ Done</button>
              <span id="sstag-4"></span>
            </div>
          </div>
        </div>

        <!-- Step 5 -->
        <div class="setup-step-card ss-locked" id="ss-5" data-step="5">
          <div class="ss-num" id="ssnum-5">5</div>
          <div class="ss-body">
            <div class="ss-title">
              Project Files htdocs mein Copy karo
              <span class="ss-badge ss-req">Required</span>
            </div>
            <p class="ss-desc">
              WealthDash folder XAMPP ke <code>htdocs/</code> mein rakho.
              Windows: <code>C:\xampp\htdocs\wealthdash\</code> &nbsp;|&nbsp;
              Mac: <code>/Applications/XAMPP/htdocs/wealthdash/</code><br>
              Files copy karne ke baad Apache restart karo.
            </p>
            <div class="ss-actions">
              <button class="btn btn-sm ss-mark" onclick="ssMarkDone(5)">✓ Done</button>
              <span id="sstag-5"></span>
            </div>
          </div>
        </div>

        <!-- Step 6 -->
        <div class="setup-step-card ss-locked" id="ss-6" data-step="6">
          <div class="ss-num" id="ssnum-6">6</div>
          <div class="ss-body">
            <div class="ss-title">
              Connection Test &amp; First Login
              <span class="ss-badge ss-auto">Verify</span>
            </div>
            <p class="ss-desc">
              Browser mein <code>localhost/wealthdash</code> open karo.
              DB status check karo → agar <span style="color:var(--gain,#22c55e);font-weight:600;">Connected</span> dikh raha hai
              toh account register karo. Setup complete! 🎉
            </p>
            <div class="ss-actions">
              <button class="btn btn-sm" style="background:rgba(79,142,247,.1);color:var(--accent);border:1px solid rgba(79,142,247,.3);font-size:12px;" onclick="refreshDbStatus()">🔌 DB Status Check</button>
              <button class="btn btn-sm ss-mark" onclick="ssMarkDone(6)">✓ Complete!</button>
              <span id="sstag-6"></span>
            </div>
          </div>
        </div>

      </div><!-- /steps -->

      <div style="text-align:right;margin-top:10px;">
        <button onclick="ssResetAll()" style="background:none;border:none;color:var(--text-muted);font-size:11.5px;cursor:pointer;text-decoration:underline;">↺ Progress reset karo</button>
      </div>
    </div><!-- /left -->

    <!-- ── RIGHT: Sidebar ─────────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:14px;">

      <!-- Quick Downloads -->
      <div class="card" style="padding:16px 18px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:12px;">⬇ Quick Downloads</div>

        <div style="display:flex;flex-direction:column;gap:8px;">
          <button class="ss-dl-big" onclick="setupDownload('schema')">
            <div class="ss-dl-icon" style="background:rgba(79,142,247,.12);">🗄️</div>
            <div>
              <div class="ss-dl-name">Schema Backup</div>
              <div class="ss-dl-hint">CREATE TABLE · live DB se generate</div>
            </div>
          </button>
          <button class="ss-dl-big" onclick="setupDownload('seed')">
            <div class="ss-dl-icon" style="background:rgba(34,197,94,.1);">🌱</div>
            <div>
              <div class="ss-dl-name">Seed Data</div>
              <div class="ss-dl-hint">Fund houses, NPS, Stocks · INSERT SQL</div>
            </div>
          </button>
          <button class="ss-dl-big" onclick="setupDownload('env')">
            <div class="ss-dl-icon" style="background:rgba(245,158,11,.1);">⚙️</div>
            <div>
              <div class="ss-dl-name">.env Template</div>
              <div class="ss-dl-hint">DB config, App URL, credentials</div>
            </div>
          </button>
        </div>
      </div>

      <!-- DB Status -->
      <div class="card" style="padding:16px 18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);">🔌 DB Status</div>
          <button onclick="refreshDbStatus()" id="dbRefreshBtn" style="background:none;border:1px solid var(--border);color:var(--text-muted);border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;" title="Refresh">↻</button>
        </div>
        <div id="dbStatusRows">
          <div class="ss-status-row"><span>Connection</span><span id="dbs-conn" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>Database</span><span id="dbs-name" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>Tables</span><span id="dbs-tables" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>Users</span><span id="dbs-users" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>MF Holdings</span><span id="dbs-mfh" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>Transactions</span><span id="dbs-txn" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>Seed: Fund Houses</span><span id="dbs-fh" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>Seed: NPS Schemes</span><span id="dbs-nps" class="ss-status-val">—</span></div>
          <div class="ss-status-row"><span>Seed: Stocks</span><span id="dbs-stocks" class="ss-status-val">—</span></div>
        </div>
      </div>

      <!-- Tips -->
      <div class="card" style="padding:16px 18px;">
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);margin-bottom:10px;">💡 Important Tips</div>
        <div class="ss-tip">Schema <strong>pehle</strong> import karo, seed <strong>baad mein</strong> — order matter karta hai.</div>
        <div class="ss-tip">Import fail ho toh phpMyAdmin mein <code>max_allowed_packet</code> badao.</div>
        <div class="ss-tip">MySQL default port <code>3306</code> — conflict ho toh <code>.env</code> mein <code>DB_PORT</code> change karo.</div>
        <div class="ss-tip">Setup complete hone ke baad admin ka password zaroor change karo.</div>
        <div class="ss-tip">Naya PC? Sirf <code>htdocs/wealthdash/</code> copy karo + schema + seed — bas.</div>
      </div>

    </div><!-- /right -->
  </div>
</div>

<style>
/* ── Setup Tab Styles ──────────────────────────────────────────── */
.setup-step-card {
  display: flex; gap: 14px; padding: 16px 18px;
  border-bottom: 1px solid var(--border);
  background: var(--bg-surface);
  transition: background .2s;
}
.setup-step-card:last-child { border-bottom: none; }
.setup-step-card.ss-active { background: rgba(var(--accent-rgb,79,142,247),.04); }
.setup-step-card.ss-done   { background: rgba(34,197,94,.035); }
.setup-step-card.ss-locked { opacity: .45; pointer-events: none; }

.ss-num {
  flex-shrink: 0; width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; font-family: monospace;
  background: var(--bg-surface-2); color: var(--text-muted);
  border: 2px solid var(--border); transition: all .2s;
}
.ss-done .ss-num   { background:rgba(34,197,94,.12); border-color:#22c55e; color:#22c55e; }
.ss-active .ss-num { background:rgba(79,142,247,.1);  border-color:var(--accent); color:var(--accent); }

.ss-body { flex: 1; min-width: 0; }
.ss-title {
  font-size: 13.5px; font-weight: 600; color: var(--text-primary);
  margin-bottom: 4px; display: flex; align-items: center; gap: 7px; flex-wrap: wrap;
}
.ss-badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; }
.ss-req  { background: rgba(239,68,68,.12); color: #ef4444; }
.ss-opt  { background: rgba(107,114,128,.12); color: #9ca3af; }
.ss-auto { background: rgba(79,142,247,.12); color: var(--accent); }
.ss-desc { font-size: 12.5px; color: var(--text-secondary); line-height: 1.55; margin: 0 0 10px; }
.ss-desc code { background: var(--bg-surface-2); padding: 1px 5px; border-radius: 4px; font-size: 11.5px; color: var(--accent); border: 1px solid var(--border); }
.ss-actions { display: flex; gap: 7px; align-items: center; flex-wrap: wrap; }
.ss-mark { background: rgba(34,197,94,.1); color: #22c55e; border: 1px solid rgba(34,197,94,.25) !important; font-size: 12px !important; }
.ss-mark:hover { background: rgba(34,197,94,.2) !important; }
.ss-tag-done { font-size: 11px; font-weight: 600; color: #22c55e; background: rgba(34,197,94,.1); padding: 2px 8px; border-radius: 99px; }
.ss-tag-pend { font-size: 11px; font-weight: 600; color: #d97706; background: rgba(245,158,11,.1); padding: 2px 8px; border-radius: 99px; }
.ss-undo { background: none !important; border: 1px solid var(--border) !important; color: var(--text-muted) !important; font-size: 11px !important; padding: 3px 8px !important; }
.ss-undo:hover { border-color: var(--danger) !important; color: var(--danger) !important; }

/* Download buttons */
.ss-dl-big {
  display: flex; align-items: center; gap: 10px; padding: 10px 12px;
  border-radius: 8px; cursor: pointer; border: 1px solid var(--border);
  background: transparent; transition: all .2s; text-align: left; width: 100%;
}
.ss-dl-big:hover { border-color: var(--accent); background: rgba(79,142,247,.05); }
.ss-dl-icon { width: 32px; height: 32px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.ss-dl-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.ss-dl-hint { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

/* Status rows */
.ss-status-row { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 1px solid rgba(255,255,255,.04); font-size: 12.5px; color: var(--text-secondary); }
.ss-status-row:last-child { border-bottom: none; }
.ss-status-val { font-weight: 600; font-family: monospace; font-size: 12px; color: var(--text-primary); }
.ss-val-ok  { color: #22c55e !important; }
.ss-val-err { color: var(--danger,#ef4444) !important; }
.ss-val-warn{ color: #d97706 !important; }

/* Tips */
.ss-tip { font-size: 12px; color: var(--text-secondary); padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,.04); line-height: 1.5; }
.ss-tip:last-child { border-bottom: none; }
.ss-tip code { background: var(--bg-surface-2); padding: 1px 4px; border-radius: 3px; font-size: 11px; color: var(--accent); }
</style>

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

.fr-preset {
  padding: 3px 10px; border-radius: 5px; font-size: 11px; font-weight: 600;
  border: 1px solid var(--border-color); background: var(--bg-secondary);
  color: var(--text-muted); cursor: pointer; transition: background .15s, color .15s;
}
.fr-preset:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

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

/* ── NAV & Data Tab redesign ── */
.nav-section-header { display:flex;align-items:center;gap:12px;margin:0 0 10px;padding:10px 16px;background:var(--bg-surface-2);border:1px solid var(--border);border-radius:10px; }
.nav-section-icon { font-size:22px;flex-shrink:0; }
.nav-section-title { font-size:14px;font-weight:700;color:var(--text-primary); }
.nav-section-sub { font-size:12px;color:var(--text-secondary);margin-top:1px; }
.nav-ops-grid { display:flex;flex-direction:column;gap:8px;margin-bottom:16px; }
.nav-op-card { display:flex;align-items:flex-start;gap:16px;background:var(--bg-surface);border:1px solid var(--border);border-radius:10px;padding:14px 16px;transition:box-shadow .15s; }
.nav-op-card:hover { box-shadow:0 2px 8px rgba(0,0,0,.07); }
.nav-op-left { display:flex;align-items:flex-start;gap:12px;flex:1;min-width:0; }
.nav-op-icon { font-size:22px;flex-shrink:0;margin-top:1px; }
.nav-op-info { flex:1;min-width:0; }
.nav-op-title { font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px;display:flex;align-items:center;flex-wrap:wrap;gap:6px; }
.nav-op-desc { font-size:12px;color:var(--text-secondary);line-height:1.55;margin-bottom:6px; }
.nav-op-desc code { background:var(--bg-surface-2);padding:1px 5px;border-radius:4px;font-size:11px;color:var(--accent);border:1px solid var(--border); }
.nav-op-meta { display:flex;align-items:center;flex-wrap:wrap;gap:10px; }
.nav-op-freq { font-size:11px;color:var(--text-muted);font-style:italic; }
.nav-lastrun { font-size:11px;font-weight:600;color:var(--text-muted); }
.nav-op-actions { display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;padding-top:2px; }
/* last-run badges */
.nav-lastrun-badge { font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;border:1px solid;white-space:nowrap; }
.nav-badge-idle    { background:var(--bg-surface-2);color:var(--text-muted);border-color:var(--border); }
.nav-badge-ok      { background:rgba(34,197,94,.1);color:#15803d;border-color:rgba(34,197,94,.3); }
.nav-badge-updating{ background:rgba(59,130,246,.1);color:var(--accent);border-color:rgba(59,130,246,.3); }
.nav-badge-error   { background:rgba(239,68,68,.1);color:var(--danger);border-color:rgba(239,68,68,.25); }
.nav-badge-warn    { background:rgba(234,179,8,.1);color:#92400e;border-color:rgba(234,179,8,.3); }
.nav-badge-running { background:rgba(59,130,246,.12);color:#1d4ed8;border-color:rgba(59,130,246,.35); animation:pulse-badge 1.5s ease-in-out infinite; }
@keyframes pulse-badge { 0%,100%{opacity:1} 50%{opacity:.6} }
</style>

<script>
function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
function escAttr(s) {
  if (!s) return '';
  return String(s).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

let allUsers = [];
let auditOffset = 0;
const AUDIT_LIMIT = 50;

document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  setTimeout(npsNavLoadStatus, 800);
});

function adminSwitchTab(name, btn) {
  document.querySelectorAll('.admin-tab-content').forEach(el => el.style.display='none');
  document.querySelectorAll('.admin-tab').forEach(el => el.classList.remove('active'));
  var tabEl = document.getElementById('tab-'+name);
  if (tabEl) tabEl.style.display='block';
  btn.classList.add('active');

  if (name==='users' && allUsers.length===0) loadUsers();
  if (name==='settings') { loadSettings(); }
  if (name==='fundrules') { frLoadCategories(); }
  if (name==='audit') loadAuditLog();
  if (name==='dbmgr') loadDbTables();
  if (name==='setup') { ssRenderAll(); refreshDbStatus(); }
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
    document.getElementById('statMfHoldings').textContent= s.mf_holdings    ?? '—';
    document.getElementById('statFunds').textContent     = s.funds          ?? '—';
    document.getElementById('statStocks').textContent    = s.stock_holdings ?? '—';
    document.getElementById('statFDs').textContent       = s.fd_accounts    ?? '—';
    document.getElementById('statSavings').textContent   = s.savings_accs   ?? '—';
    document.getElementById('statNav').textContent       = s.nav_last_updated ? formatDate(s.nav_last_updated) : 'Not updated';

    // Populate last-run text for each operation
    const fmtRun = (val) => val ? 'Last run: ' + formatDateTime(val) : 'Last run: Never';
    const setLastRun = (id, val) => { const el=document.getElementById(id); if(el) el.textContent = fmtRun(val); };
    setLastRun('lastrun_nav',    s.nav_last_updated);
    setLastRun('lastrun_import', s.nav_last_updated);  // same key — import updates same setting
    setLastRun('lastrun_ter',    s.ter_last_updated);
    setLastRun('lastrun_el',     s.exit_load_last_updated);
    setLastRun('lastrun_stocks', s.stocks_last_updated);
    setLastRun('lastrun_recalc', s.last_recalc_holdings);
    // Peak NAV has its own live tiles via loadPeakNavStatus()

    // Auto-update check: trigger NAV update if not done today
    checkNavAutoUpdate(s.nav_last_updated);

  } catch(e) {
    console.error('loadStats error:', e);
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

// ── NAV Auto-Update Status Badge ──────────────────────────────────────────
// Called by loadStats() with the stored nav_last_updated date.
// If not updated today → silently trigger update in background.
// Badge: "Updated Today ✅" | "Updating... ⏳" | "Failed ❌" | "Not updated today ⚠️"
let _navAutoTriggered = false; // prevent double-trigger on repeated loadStats calls

function navSetAutoStatus(text, style) {
  const el = document.getElementById('navAutoStatus');
  if (!el) return;
  const styles = {
    ok:       'background:rgba(34,197,94,.1);color:var(--success);border-color:rgba(34,197,94,.3)',
    updating: 'background:rgba(59,130,246,.1);color:var(--accent);border-color:rgba(59,130,246,.3)',
    error:    'background:rgba(239,68,68,.1);color:var(--danger);border-color:rgba(239,68,68,.3)',
    warn:     'background:rgba(234,179,8,.1);color:#b45309;border-color:rgba(234,179,8,.3)',
    idle:     'background:var(--bg-secondary);color:var(--text-muted);border-color:var(--border)',
  };
  el.style.cssText = `font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;border:1px solid;${styles[style]||styles.idle}`;
  el.textContent = text;
}

async function checkNavAutoUpdate(navLastUpdated) {
  const today = new Date().toISOString().slice(0, 10); // "YYYY-MM-DD"
  const badge = document.getElementById('navAutoStatus');
  if (!badge) return;

  // Already updated today
  if (navLastUpdated === today) {
    navSetAutoStatus('Updated Today ✅', 'ok');
    return;
  }

  // Weekend: AMFI doesn't publish on Sat/Sun — show info
  const dow = new Date().getDay(); // 0=Sun, 6=Sat
  if (dow === 0 || dow === 6) {
    const lastDate = navLastUpdated ? navLastUpdated : 'Never';
    navSetAutoStatus(`Weekend — Last: ${lastDate}`, 'warn');
    return;
  }

  // Not updated today (weekday) — but only auto-trigger once per page load
  if (_navAutoTriggered) return;
  _navAutoTriggered = true;

  // Show "Updating" status immediately
  navSetAutoStatus('Updating... ⏳', 'updating');
  navShowStrip('🤖 Auto-updating NAV — page load pe pata chala aaj update nahi tha...', 'info');

  // Trigger silently — same as manual button but no confirm
  const url = `${window.APP_URL}/api/nav/update_amfi.php`;
  try {
    const res = await fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      signal: AbortSignal.timeout ? AbortSignal.timeout(60000) : undefined,
    });
    const d = await res.json();

    if (d.success) {
      navSetAutoStatus('Updated Now ✅', 'ok');
      navShowStrip('✅ Auto-update complete — ' + (d.message || 'NAV updated!'), 'success');
      // Refresh statNav tile to show today's date
      const statNavEl = document.getElementById('statNav');
      if (statNavEl) statNavEl.textContent = formatDate(today);
    } else {
      // Could be "already updated" message (race condition) — treat as OK
      if (d.message && d.message.toLowerCase().includes('already')) {
        navSetAutoStatus('Updated Today ✅', 'ok');
        navShowStrip('✅ ' + d.message, 'success');
      } else {
        navSetAutoStatus('Auto-update Failed ❌', 'error');
        navShowStrip('⚠️ Auto-update failed: ' + (d.message || 'Unknown error') + ' — click Update button to retry.', 'error');
      }
    }
  } catch (err) {
    navSetAutoStatus('Failed ❌', 'error');
    navShowStrip('⚠️ Auto-update error: ' + err.message + ' — click Update button to retry.', 'error');
  }
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
      // Update auto-status badge if this was a NAV update
      if (!isImport) {
        navSetAutoStatus('Updated Now ✅', 'ok');
        const lr = document.getElementById('lastrun_nav');
        if (lr) lr.textContent = 'Last run: ' + formatDateTime(new Date().toISOString().replace('T',' ').substring(0,19));
      }
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
    const lr = document.getElementById('lastrun_stocks');
    if (lr) lr.textContent = 'Last run: ' + formatDateTime(new Date().toISOString().replace('T',' ').substring(0,19));
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

    if (!isErr) {
      showToast('Holdings recalculated successfully!', 'success');
      const lr = document.getElementById('lastrun_recalc');
      if (lr) lr.textContent = 'Last run: ' + formatDateTime(new Date().toISOString().replace('T',' ').substring(0,19));
    }
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

/* ═══════════════════════════════════════════════════════════════════════════
   FUND RULES — LTCG / Lock-in Admin (Category + Bulk + Single)
═══════════════════════════════════════════════════════════════════════════ */
const FR = {
  selectedIds:     new Set(),   // checked fund IDs
  currentCategory: '',          // active category filter
  currentFunds:    [],          // funds currently in table
  searchTimer:     null,
  allVisibleSelected: false,
};

// ── Categories ─────────────────────────────────────────────────────────────
async function frLoadCategories() {
  const list = document.getElementById('frCatList');
  list.innerHTML = '<div style="padding:16px;text-align:center;"><div class="spinner"></div></div>';
  try {
    const d = await API.get('/api/router.php?action=admin_fund_rules_categories');
    const cats = d.data?.categories || [];
    if (!cats.length) { list.innerHTML = '<div style="padding:16px;color:var(--text-muted);font-size:13px;">No categories found.</div>'; return; }

    list.innerHTML = cats.map(c => {
      const ltcgLabel = c.ltcg_days_min === c.ltcg_days_max
        ? frDaysLabel(c.ltcg_days_min)
        : frDaysLabel(c.ltcg_days_min) + '–' + frDaysLabel(c.ltcg_days_max);
      const lockBadge = c.lock_days_max > 0
        ? `<span style="font-size:10px;color:#b45309;font-weight:700;">🔒</span>` : '';
      const uniformDot = c.is_uniform
        ? `<span title="All funds same rules" style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#16a34a;margin-left:4px;vertical-align:middle;"></span>` : '';

      // Shorten category display name
      const displayName = c.category
        .replace('Open Ended Schemes(', '').replace('Close Ended Schemes(', 'CE: ')
        .replace('Interval Schemes(', 'Int: ').replace(')', '').trim();

    return `<div class="fr-cat-item" data-cat="${escAttr(c.category)}" onclick="frSelectCategory('${escAttr(c.category)}')"
        style="padding:7px 12px;cursor:pointer;border-bottom:1px solid var(--border-color);
               transition:background .1s;" title="${escAttr(c.category)}"
        onmouseover="this.style.background='var(--bg-secondary)'"
        onmouseout="if(FR.currentCategory!==this.dataset.cat) this.style.background=''">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:12px;font-weight:500;line-height:1.3;">${esc(displayName)}${uniformDot}</span>
          ${lockBadge}
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:1px;">
          <span style="font-size:10px;color:var(--text-muted);">${c.fund_count} funds</span>
          <span style="font-size:10px;color:#15803d;font-weight:600;">${ltcgLabel}</span>
        </div>
      </div>`;
    }).join('');
  } catch(e) {
    list.innerHTML = `<div style="padding:16px;color:var(--danger);font-size:13px;">Error: ${esc(e.message)}</div>`;
  }
}

async function frSelectCategory(category) {
  FR.currentCategory = category;
  FR.selectedIds.clear();
  frUpdateBulkBar();

  // Highlight active category in left panel
  document.querySelectorAll('.fr-cat-item').forEach(el => {
    el.style.background = el.dataset.cat === category ? 'rgba(37,99,235,.08)' : '';
  });

  // Show category badge
  const badge = document.getElementById('frActiveCategory');
  const shortName = category.replace('Open Ended Schemes(','').replace('Close Ended Schemes(','').replace(')','').trim();
  badge.textContent = shortName;
  badge.style.display = 'inline-block';

  document.getElementById('frSearchInput').value = '';
  document.getElementById('frDropdown').style.display = 'none';
  document.getElementById('frSelectAllBtn').style.display = '';
  document.getElementById('frEditor').style.display = 'none';
  document.getElementById('frTableHeader').style.display = '';
  document.getElementById('frTableHeader').textContent = `Loading funds in: ${shortName}…`;

  try {
    const d = await API.get(`/api/router.php?action=admin_fund_rules_search&category=${encodeURIComponent(category)}&limit=500`);
    FR.currentFunds = d.data?.funds || [];
    frRenderTable(FR.currentFunds);
    document.getElementById('frTableHeader').textContent =
      `${FR.currentFunds.length} funds in: ${shortName}`;
  } catch(e) {
    document.getElementById('frFundsBody').innerHTML =
      `<tr><td colspan="5" style="color:var(--danger);padding:16px;">${esc(e.message)}</td></tr>`;
  }
}

// ── Table render ───────────────────────────────────────────────────────────
function frRenderTable(funds) {
  const tbody = document.getElementById('frFundsBody');
  if (!funds.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="padding:30px;text-align:center;color:var(--text-muted);">No funds found.</td></tr>';
    return;
  }

  tbody.innerHTML = funds.map(f => {
    const ltcgLbl = frDaysLabel(f.min_ltcg_days);
    const lockLbl = f.lock_in_days > 0
      ? `<span style="color:#b45309;font-weight:700;">🔒 ${frDaysLabel(f.lock_in_days)}</span>`
      : `<span style="color:var(--text-muted);">—</span>`;
    const checked = FR.selectedIds.has(f.id) ? 'checked' : '';

    return `<tr id="fr-row-${f.id}">
      <td style="padding:6px;width:32px;"><input type="checkbox" class="fr-chk" data-id="${f.id}" ${checked} onchange="frOnCheck(this,${f.id})"></td>
      <td style="padding:6px 4px;">
        <div style="font-size:12px;font-weight:500;line-height:1.3;word-break:break-word;">${esc(f.scheme_name)}</div>
        <div style="font-size:10px;color:var(--text-muted);">${esc(f.fund_house)}</div>
      </td>
      <td class="text-center" id="fr-ltcg-${f.id}" style="font-weight:600;font-size:11px;padding:6px 4px;white-space:nowrap;">${ltcgLbl}</td>
      <td class="text-center" id="fr-lock-${f.id}" style="font-size:11px;padding:6px 4px;white-space:nowrap;">${lockLbl}</td>
      <td class="text-center" style="padding:6px 4px;">
        <button class="btn btn-ghost btn-xs" style="padding:2px 6px;font-size:11px;"
          onclick="frOpenEditor(${f.id},'${escAttr(f.scheme_name)}','${escAttr(f.fund_house)}','${escAttr(f.category||'')}',${f.min_ltcg_days},${f.lock_in_days})">
          ✏️
        </button>
      </td>
    </tr>`;
  }).join('');

  document.getElementById('frCheckAll').checked = false;
  FR.allVisibleSelected = false;
}

// ── Checkbox logic ─────────────────────────────────────────────────────────
function frOnCheck(el, fundId) {
  if (el.checked) FR.selectedIds.add(fundId);
  else FR.selectedIds.delete(fundId);
  frUpdateBulkBar();
}

function frCheckAllToggle(masterCb) {
  const chks = document.querySelectorAll('.fr-chk');
  chks.forEach(cb => {
    cb.checked = masterCb.checked;
    const id = parseInt(cb.dataset.id);
    if (masterCb.checked) FR.selectedIds.add(id);
    else FR.selectedIds.delete(id);
  });
  frUpdateBulkBar();
}

function frToggleSelectAll() {
  const masterCb = document.getElementById('frCheckAll');
  masterCb.checked = !masterCb.checked;
  frCheckAllToggle(masterCb);
}

function frDeselectAll() {
  FR.selectedIds.clear();
  document.querySelectorAll('.fr-chk').forEach(cb => cb.checked = false);
  document.getElementById('frCheckAll').checked = false;
  frUpdateBulkBar();
}

function frUpdateBulkBar() {
  const bar = document.getElementById('frBulkBar');
  const cnt = document.getElementById('frSelCount');
  const n   = FR.selectedIds.size;
  bar.style.display = n > 0 ? 'flex' : 'none';
  cnt.textContent   = `${n} fund${n !== 1 ? 's' : ''} selected`;
}

// ── Bulk Save ──────────────────────────────────────────────────────────────
async function frBulkSave() {
  const ltcg = parseInt(document.getElementById('frBulkLtcg').value);
  const lock = parseInt(document.getElementById('frBulkLock').value);
  const n    = FR.selectedIds.size;

  if (!n)       { showToast('No funds selected','error'); return; }
  if (ltcg < 1) { showToast('LTCG days must be ≥ 1','error'); return; }
  if (!confirm(`Apply LTCG=${ltcg}d, Lock-in=${lock}d to ${n} fund${n!==1?'s':''}?`)) return;

  const btn = document.getElementById('frBulkSaveBtn');
  const lbl = document.getElementById('frBulkSaveLbl');
  btn.disabled = true; lbl.textContent = 'Saving…';

  try {
    const res = await API.post('/api/router.php', {
      action:        'admin_fund_rules_bulk_update',
      fund_ids:      [...FR.selectedIds],
      min_ltcg_days: ltcg,
      lock_in_days:  lock,
      csrf_token:    window.CSRF_TOKEN,
    });

    // Update table cells in-place
    FR.selectedIds.forEach(id => {
      const ltcgEl = document.getElementById(`fr-ltcg-${id}`);
      const lockEl = document.getElementById(`fr-lock-${id}`);
      if (ltcgEl) ltcgEl.textContent = frDaysLabel(ltcg);
      if (lockEl) lockEl.innerHTML = lock > 0
        ? `<span style="color:#b45309;font-weight:700;">🔒 ${frDaysLabel(lock)}</span>`
        : `<span style="color:var(--text-muted);">—</span>`;
    });

    showToast(`✅ ${res.message}`, 'success');
    frDeselectAll();
  } catch(e) {
    showToast('⚠️ ' + e.message, 'error');
  } finally {
    btn.disabled = false; lbl.textContent = '💾 Apply to Selected';
  }
}

// ── Single Fund Editor ─────────────────────────────────────────────────────
function frOpenEditor(id, name, house, category, ltcgDays, lockDays) {
  document.getElementById('frFundId').value    = id;
  document.getElementById('frFundName').textContent = name;
  document.getElementById('frFundMeta').textContent = house + (category ? ' · ' + category : '');
  document.getElementById('frLtcgDays').value  = ltcgDays;
  document.getElementById('frLockDays').value  = lockDays;
  document.getElementById('frSaveStatus').textContent = '';
  document.getElementById('frEditor').style.display = 'block';
  document.getElementById('frEditor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function frCloseEditor() {
  document.getElementById('frEditor').style.display = 'none';
}

function frSetLtcg(d) { document.getElementById('frLtcgDays').value = d; }
function frSetLock(d) { document.getElementById('frLockDays').value = d; }

async function frSave() {
  const fundId = parseInt(document.getElementById('frFundId').value);
  const ltcg   = parseInt(document.getElementById('frLtcgDays').value);
  const lock   = parseInt(document.getElementById('frLockDays').value);
  const status = document.getElementById('frSaveStatus');
  const btn    = document.getElementById('frSaveBtn');

  if (!fundId || isNaN(ltcg) || isNaN(lock)) { status.innerHTML = '<span style="color:var(--danger);">⚠️ All fields required.</span>'; return; }
  if (ltcg < 1) { status.innerHTML = '<span style="color:var(--danger);">⚠️ LTCG days ≥ 1.</span>'; return; }

  btn.disabled = true;
  document.getElementById('frSaveBtnLabel').textContent = 'Saving…';
  status.textContent = '';

  try {
    const res = await API.post('/api/router.php', {
      action: 'admin_fund_rules_update', fund_id: fundId,
      min_ltcg_days: ltcg, lock_in_days: lock, csrf_token: window.CSRF_TOKEN,
    });

    // Update table row in-place
    const ltcgEl = document.getElementById(`fr-ltcg-${fundId}`);
    const lockEl = document.getElementById(`fr-lock-${fundId}`);
    if (ltcgEl) ltcgEl.textContent = frDaysLabel(ltcg);
    if (lockEl) lockEl.innerHTML = lock > 0
      ? `<span style="color:#b45309;font-weight:700;">🔒 ${frDaysLabel(lock)}</span>`
      : `<span style="color:var(--text-muted);">—</span>`;

    status.innerHTML = `<span style="color:var(--success);">✅ Saved!</span>`;
    showToast('Fund rules updated!', 'success');
    setTimeout(frCloseEditor, 1200);
  } catch(e) {
    status.innerHTML = `<span style="color:var(--danger);">⚠️ ${esc(e.message)}</span>`;
  } finally {
    btn.disabled = false;
    document.getElementById('frSaveBtnLabel').textContent = '💾 Save';
  }
}

// ── Search (text) ──────────────────────────────────────────────────────────
function frOnSearch(q) {
  clearTimeout(FR.searchTimer);
  const dd = document.getElementById('frDropdown');
  if (q.trim().length < 2) { dd.style.display = 'none'; return; }
  FR.searchTimer = setTimeout(() => frDoSearch(q.trim()), 300);
}

async function frDoSearch(q) {
  const dd  = document.getElementById('frDropdown');
  dd.innerHTML = '<div style="padding:10px 14px;color:var(--text-muted);font-size:13px;">Searching…</div>';
  dd.style.display = 'block';
  try {
    const d = await API.get(`/api/router.php?action=admin_fund_rules_search&q=${encodeURIComponent(q)}&limit=20`);
    const funds = d.data?.funds || [];
    if (!funds.length) {
      dd.innerHTML = `<div style="padding:10px 14px;color:var(--text-muted);font-size:13px;">No funds found.</div>`;
      return;
    }
    dd.innerHTML = funds.map(f => `
      <div onmousedown="frPickFromSearch(${f.id},'${escAttr(f.scheme_name)}','${escAttr(f.fund_house)}','${escAttr(f.category||'')}',${f.min_ltcg_days},${f.lock_in_days})"
        style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);"
        onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''">
        <div style="font-size:13px;font-weight:500;">${esc(f.scheme_name)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${esc(f.fund_house)} · ${esc(f.category||'—')}</div>
        <div style="margin-top:3px;display:flex;gap:5px;">
          <span style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px;background:rgba(22,163,74,.1);color:#15803d;">LTCG ${frDaysLabel(f.min_ltcg_days)}</span>
          ${f.lock_in_days > 0 ? `<span style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px;background:rgba(234,179,8,.1);color:#b45309;">🔒 ${frDaysLabel(f.lock_in_days)}</span>` : ''}
        </div>
      </div>`).join('');

    // Also show in table
    FR.currentFunds = funds;
    FR.currentCategory = '';
    document.getElementById('frActiveCategory').style.display = 'none';
    document.getElementById('frSelectAllBtn').style.display = '';
    document.getElementById('frTableHeader').style.display = '';
    document.getElementById('frTableHeader').textContent = `${funds.length} search results`;
    frRenderTable(funds);
  } catch(e) {
    dd.innerHTML = `<div style="padding:10px;color:var(--danger);">${esc(e.message)}</div>`;
  }
}

function frPickFromSearch(id, name, house, category, ltcg, lock) {
  document.getElementById('frDropdown').style.display = 'none';
  document.getElementById('frSearchInput').value = name;
  frOpenEditor(id, name, house, category, ltcg, lock);
}

// ── Helpers ────────────────────────────────────────────────────────────────
function frDaysLabel(d) {
  if (d === 365)  return '1yr';
  if (d === 730)  return '2yr';
  if (d === 1095) return '3yr';
  if (d === 1825) return '5yr';
  return d + 'd';
}

// Close dropdown on outside click
document.addEventListener('click', e => {
  if (!e.target.closest('#frSearchInput') && !e.target.closest('#frDropdown')) {
    const dd = document.getElementById('frDropdown');
    if (dd) dd.style.display = 'none';
  }
});

// ── TER Import ────────────────────────────────────────────
async function importTer() {
  const btn = document.getElementById('btnImportTer');
  const icon = document.getElementById('terBtnIcon');
  const text = document.getElementById('terBtnText');
  const res = document.getElementById('terResult');
  
  btn.disabled = true;
  icon.textContent = '⏳';
  text.textContent = ' Importing TER data...';
  res.style.display = 'none';

  try {
    const d = await API.get('/api/router.php?action=admin_import_ter');
    res.style.display = 'block';
    res.style.color = d.success ? 'var(--success)' : 'var(--danger)';
    res.innerHTML = d.success
      ? `✅ <strong>${d.message}</strong><br><small style="color:var(--text-muted);">Not found: ${d.not_found || 0} · Skipped: ${d.skipped || 0}</small>`
      : `⚠️ ${d.message}`;
    if (d.success) {
      showToast('TER import complete!', 'success');
      const el = document.getElementById('lastrun_ter');
      if (el) el.textContent = 'Last run: ' + formatDateTime(new Date().toISOString().replace('T',' ').substring(0,19));
    }
    else showToast(d.message, 'error');
  } catch(e) {
    res.style.display = 'block';
    res.style.color = 'var(--danger)';
    res.textContent = '⚠️ Error: ' + e.message;
  } finally {
    btn.disabled = false;
    icon.textContent = '📥';
    text.textContent = ' Import TER Data';
  }
}

// ── Exit Load Seeder ──────────────────────────────────────
async function importExitLoad() {
  const btn  = document.getElementById('btnImportExitLoad');
  const icon = document.getElementById('elBtnIcon');
  const text = document.getElementById('elBtnText');
  const res  = document.getElementById('elResult');

  btn.disabled = true;
  icon.textContent = '⏳';
  text.textContent = ' Seeding exit loads...';
  res.style.display = 'none';

  try {
    const d = await API.get('/api/router.php?action=admin_import_exit_load');
    res.style.display = 'block';
    res.style.color = d.success ? 'var(--success)' : 'var(--danger)';
    res.innerHTML = d.success
      ? `✅ <strong>${d.message}</strong><br><small style="color:var(--text-muted);">With load: ${d.with_load || 0} &nbsp;|&nbsp; Nil: ${d.nil_load || 0} &nbsp;|&nbsp; Total: ${d.total || 0}</small>`
      : `⚠️ ${d.message}`;
    if (d.success) {
      showToast('Exit load seeded!', 'success');
      const el = document.getElementById('lastrun_el');
      if (el) el.textContent = 'Last run: ' + formatDateTime(new Date().toISOString().replace('T',' ').substring(0,19));
    }
    else showToast(d.message, 'error');
  } catch(e) {
    res.style.display = 'block';
    res.style.color = 'var(--danger)';
    res.textContent = '⚠️ Error: ' + e.message;
  } finally {
    btn.disabled = false;
    icon.textContent = '🚪';
    text.textContent = ' Seed Exit Load Data';
  }
}

// ── NPS NAV — Admin Functions (t99) ────────────────────────
async function npsNavRun(mode) {
  const btnMap = { daily: 'btnNpsNavDaily', backfill: 'btnNpsBackfill' };
  const btn = btnMap[mode] ? document.getElementById(btnMap[mode]) : null;
  const origText = btn?.innerHTML;
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-sm"></span> Running...'; }

  try {
    const res  = await fetch(APP_URL + '/api/router.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'admin_nps_nav_trigger', mode })
    });
    const data = await res.json();
    if (data.success) {
      showToast('✅ ' + data.message, 'success');
      document.getElementById('npsNavStatus').textContent = mode === 'backfill' ? '⏳ Backfilling...' : '⏳ Running...';
      document.getElementById('npsNavStatus').className = 'nav-lastrun-badge nav-badge-running';
      // Poll status after 3 sec
      setTimeout(npsNavLoadStatus, 3000);
      if (mode === 'backfill') setTimeout(npsNavLoadStatus, 15000); // check again after 15s
    } else {
      showToast('⚠️ ' + (data.message || 'Error'), 'error');
    }
  } catch (e) {
    showToast('⚠️ ' + e.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = origText; }
  }
}

async function npsNavLoadStatus() {
  try {
    const res  = await fetch(APP_URL + '/api/router.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'admin_nps_nav_trigger', mode: 'status' })
    });
    const data = await res.json();
    if (!data.success) return;
    const d = data.data;

    // Status badge
    const badge = document.getElementById('npsNavStatus');
    if (badge) {
      const st = d.last_status || 'unknown';
      if (st.startsWith('success')) {
        badge.textContent = '✅ Up to date';
        badge.className = 'nav-lastrun-badge nav-badge-ok';
      } else if (st.startsWith('partial')) {
        badge.textContent = '⚠️ Partial';
        badge.className = 'nav-lastrun-badge nav-badge-warn';
      } else if (st.startsWith('running') || st.startsWith('triggered')) {
        badge.textContent = '⏳ Running...';
        badge.className = 'nav-lastrun-badge nav-badge-running';
        setTimeout(npsNavLoadStatus, 5000); // keep polling
      } else if (st.startsWith('error') || st.startsWith('failed')) {
        badge.textContent = '❌ Failed';
        badge.className = 'nav-lastrun-badge nav-badge-error';
      } else if (st === 'never_run' || st === 'no_schemes') {
        badge.textContent = '⚪ Not run yet';
        badge.className = 'nav-lastrun-badge nav-badge-idle';
      } else {
        badge.textContent = st.slice(0, 30);
        badge.className = 'nav-lastrun-badge nav-badge-idle';
      }
    }

    // Last run time
    const lr = document.getElementById('lastrun_nps');
    if (lr && d.last_run) {
      const dt = new Date(d.last_run.replace(' ', 'T'));
      lr.textContent = 'Last run: ' + dt.toLocaleString('en-IN', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' });
    }

    // Stats line under the card
    const schCard = document.getElementById('navOpCard_nps');
    if (schCard && d.scheme_count !== undefined) {
      let statsEl = schCard.querySelector('.nps-nav-stats');
      if (!statsEl) {
        statsEl = document.createElement('div');
        statsEl.className = 'nps-nav-stats';
        statsEl.style.cssText = 'font-size:11px;color:var(--text-muted);margin-top:6px;display:flex;gap:12px;flex-wrap:wrap';
        schCard.querySelector('.nav-op-meta').appendChild(statsEl);
      }
      const navOk = d.missing_today === 0;
      statsEl.innerHTML = `
        <span>📌 ${d.scheme_count} active schemes</span>
        <span>📊 ${d.history_count.toLocaleString('en-IN')} NAV records</span>
        <span style="color:${navOk ? '#16a34a' : '#dc2626'}">${navOk ? '✅ All NAVs today' : '⚠️ ' + d.missing_today + ' missing today'}</span>
      `;
    }
  } catch (e) {
    console.warn('NPS status check failed:', e.message);
  }
}

async function loadNpsSchemes() {
  const sel = document.getElementById('npsManualScheme');
  if (!sel || sel.options.length > 1) return; // already loaded
  try {
    const res  = await fetch(APP_URL + '/api/nps/nps_screener.php?per_page=200&sort=name');
    const data = await res.json();
    if (!data.schemes) return;
    data.schemes.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.id;
      opt.textContent = s.pfm_name + ' — ' + s.scheme_name + ' (' + (s.tier === 'tier1' ? 'T1' : 'T2') + '/' + s.asset_class + ')';
      sel.appendChild(opt);
    });
  } catch (e) { console.warn('loadNpsSchemes:', e.message); }
}

async function npsManualSave() {
  const schemeId  = document.getElementById('npsManualScheme')?.value;
  const manualNav = document.getElementById('npsManualNav')?.value;
  const navDate   = document.getElementById('npsManualDate')?.value;

  if (!schemeId) { showToast('Scheme select karo', 'error'); return; }
  if (!manualNav || parseFloat(manualNav) <= 0) { showToast('Valid NAV daalo', 'error'); return; }

  try {
    const res  = await fetch(APP_URL + '/api/router.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'admin_nps_nav_trigger', mode: 'manual', scheme_id: parseInt(schemeId), manual_nav: parseFloat(manualNav), nav_date: navDate })
    });
    const data = await res.json();
    if (data.success) {
      showToast('✅ ' + data.message, 'success');
      document.getElementById('npsManualNav').value = '';
      document.getElementById('npsManualWrap').style.display = 'none';
      npsNavLoadStatus();
    } else {
      showToast('⚠️ ' + data.message, 'error');
    }
  } catch (e) {
    showToast('⚠️ ' + e.message, 'error');
  }
}


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

function formatDateTime(d) {
  if (!d) return '—';
  // "2026-03-22 22:30:00" → "22-03-2026, 10:30 PM"
  const dt = new Date(d.replace(' ', 'T'));
  if (isNaN(dt)) return d.substring(0,16);
  const dd   = String(dt.getDate()).padStart(2,'0');
  const mm   = String(dt.getMonth()+1).padStart(2,'0');
  const yyyy = dt.getFullYear();
  const h    = dt.getHours();
  const min  = String(dt.getMinutes()).padStart(2,'0');
  const ampm = h >= 12 ? 'PM' : 'AM';
  const h12  = String(h % 12 || 12);
  return `${dd}-${mm}-${yyyy}, ${h12}:${min} ${ampm}`;
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

// ═══════════════════════════════════════════════════════════════════
// SETUP & BACKUP TAB — Step Wizard + DB Status + Downloads
// ═══════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  const TOTAL   = 6;
  const SS_KEY  = 'wd_setup_v2';
  let   _state  = {};

  // ── Persist ────────────────────────────────────────────────────
  function ssLoad() {
    try { _state = JSON.parse(localStorage.getItem(SS_KEY) || '{}'); }
    catch { _state = {}; }
  }
  function ssSave() {
    try { localStorage.setItem(SS_KEY, JSON.stringify(_state)); } catch {}
  }

  // ── Render all steps ───────────────────────────────────────────
  window.ssRenderAll = function () {
    ssLoad();
    let done = 0;
    for (let i = 1; i <= TOTAL; i++) {
      const card   = document.getElementById('ss-' + i);
      const numEl  = document.getElementById('ssnum-' + i);
      const tagEl  = document.getElementById('sstag-' + i);
      if (!card) continue;

      const isDone = !!_state[i];
      const prevOk = i === 1 || !!_state[i - 1];
      if (isDone) done++;

      card.classList.remove('ss-active', 'ss-done', 'ss-locked');

      if (isDone) {
        card.classList.add('ss-done');
        if (numEl) numEl.textContent = '✓';
        if (tagEl) tagEl.innerHTML =
          `<span class="ss-tag-done">✓ Done</span>
           <button class="btn btn-sm ss-undo" onclick="ssUnmark(${i})">Undo</button>`;
      } else if (prevOk) {
        card.classList.add('ss-active');
        if (numEl) numEl.textContent = i;
        if (tagEl) tagEl.innerHTML = `<span class="ss-tag-pend">⏳ Pending</span>`;
      } else {
        card.classList.add('ss-locked');
        if (numEl) numEl.textContent = i;
        if (tagEl) tagEl.innerHTML = '';
      }
    }

    // Progress bar
    const pct  = Math.round((done / TOTAL) * 100);
    const fill = document.getElementById('setupFill');
    const pctEl = document.getElementById('setupPct');
    const sub   = document.getElementById('setupSubtitle');
    if (fill)  fill.style.width = pct + '%';
    if (pctEl) pctEl.textContent = pct + '%';
    if (pct === 100 && sub) {
      sub.textContent = '🎉 Setup complete! App ready hai.';
      if (fill) fill.style.background = 'linear-gradient(90deg,#22c55e,#86efac)';
    } else if (sub) {
      sub.textContent = 'Nayi machine pe ye steps follow karo — ek baar mein sab sahi chal jayega.';
      if (fill) fill.style.background = 'linear-gradient(90deg,var(--accent),#7dd3fc)';
    }
  };

  // ── Mark / unmark ──────────────────────────────────────────────
  window.ssMarkDone = function (n) {
    ssLoad();
    _state[n] = true;
    ssSave();
    ssRenderAll();
    showToast('Step ' + n + ' complete! ✓', 'success');
  };
  window.ssUnmark = function (n) {
    ssLoad();
    for (let i = n; i <= TOTAL; i++) delete _state[i];
    ssSave();
    ssRenderAll();
    showToast('Step ' + n + ' reset.', 'warning');
  };
  window.ssResetAll = function () {
    if (!confirm('Saara setup progress reset karna chahte ho?')) return;
    _state = {};
    ssSave();
    ssRenderAll();
    showToast('Progress reset ho gaya.', 'warning');
  };

  // ── Download trigger ───────────────────────────────────────────
  window.setupDownload = function (type) {
    showToast('Download shuru ho rahi hai…', 'success');
    // Direct file download — not via router (router returns JSON only)
    window.location.href = window.APP_URL + '/api/admin/db_setup_download.php?type=' + type;
  };

  // ── DB Status refresh ──────────────────────────────────────────
  window.refreshDbStatus = async function () {
    const btn = document.getElementById('dbRefreshBtn');
    if (btn) { btn.textContent = '⏳'; btn.disabled = true; }

    function setVal(id, text, cls) {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent  = text;
      el.className    = 'ss-status-val' + (cls ? ' ' + cls : '');
    }

    try {
      // Direct endpoint — bypass router JSON envelope
      const res  = await fetch(window.APP_URL + '/api/admin/db_setup_download.php?type=db_status');
      const data = await res.json();

      if (data.ok) {
        setVal('dbs-conn',   '● Connected',                    'ss-val-ok');
        setVal('dbs-name',   data.db_name  || '—',            '');
        setVal('dbs-tables', (data.table_count || 0) + ' tables',
               data.table_count > 0 ? 'ss-val-ok' : 'ss-val-warn');
        setVal('dbs-users',  (data.row_counts?.users           ?? '—') + ' rows', '');
        setVal('dbs-mfh',    (data.row_counts?.mf_holdings     ?? '—') + ' rows', '');
        setVal('dbs-txn',    (data.row_counts?.mf_transactions ?? '—') + ' rows', '');

        const ss = data.seed_status || {};
        setVal('dbs-fh',     ss.fund_houses  ? '✓ Seeded' : '✗ Empty',
               ss.fund_houses  ? 'ss-val-ok' : 'ss-val-warn');
        setVal('dbs-nps',    ss.nps_schemes  ? '✓ Seeded' : '✗ Empty',
               ss.nps_schemes  ? 'ss-val-ok' : 'ss-val-warn');
        setVal('dbs-stocks', ss.stock_master ? '✓ Seeded' : '✗ Empty',
               ss.stock_master ? 'ss-val-ok' : 'ss-val-warn');
      } else {
        setVal('dbs-conn', '✗ Error: ' + (data.error || '?'), 'ss-val-err');
        ['dbs-name','dbs-tables','dbs-users','dbs-mfh','dbs-txn',
         'dbs-fh','dbs-nps','dbs-stocks'].forEach(id => setVal(id, '—', ''));
      }
    } catch (e) {
      setVal('dbs-conn', '✗ Network error', 'ss-val-err');
    } finally {
      if (btn) { btn.textContent = '↻'; btn.disabled = false; }
    }
  };

})(); // end setup IIFE
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>