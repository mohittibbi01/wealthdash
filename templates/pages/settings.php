<?php
/**
 * WealthDash — User Settings & Profile (t54)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$pageTitle   = 'Settings';
$activePage  = 'settings';
$db          = DB::conn();

// Load user prefs from app_settings (per-user key)
function userPref(int $uid, string $key, $default = '') {
    try {
        $s = DB::conn()->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
        $s->execute(["user_{$uid}_{$key}"]);
        $v = $s->fetchColumn();
        return $v !== false ? $v : $default;
    } catch (Exception $e) { return $default; }
}

$uid = (int)$currentUser['id'];
ob_start();
?>
<style>
.settings-grid { display:grid; grid-template-columns:220px 1fr; gap:24px; padding:20px 0 60px; }
.settings-nav { display:flex; flex-direction:column; gap:2px; position:sticky; top:80px; }
.settings-nav-item {
  padding:9px 14px; border-radius:8px; font-size:13px; font-weight:600;
  color:var(--text-muted); cursor:pointer; transition:all .15s; border:none;
  background:transparent; text-align:left; font-family:inherit;
}
.settings-nav-item:hover { background:var(--bg-secondary); color:var(--text-primary); }
.settings-nav-item.active { background:var(--accent-bg,#eff6ff); color:var(--accent); font-weight:700; }
.settings-section { display:none; }
.settings-section.active { display:block; }
.settings-card {
  background:var(--bg-card); border:1.5px solid var(--border-color);
  border-radius:12px; padding:22px 24px; margin-bottom:16px;
  box-shadow:0 1px 4px rgba(0,0,0,.06);
}
.settings-card-title {
  font-size:14px; font-weight:800; color:var(--text-primary);
  margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid var(--border-color);
  display:flex; align-items:center; gap:8px;
}
.pref-row {
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 0; border-bottom:1px solid var(--border-color);
}
.pref-row:last-child { border-bottom:none; padding-bottom:0; }
.pref-label { font-size:13px; font-weight:600; color:var(--text-primary); }
.pref-desc { font-size:11px; color:var(--text-muted); margin-top:2px; }
.toggle-switch { position:relative; width:42px; height:24px; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider {
  position:absolute; cursor:pointer; inset:0;
  background:#d1d5db; border-radius:24px; transition:.2s;
}
.toggle-slider::before {
  content:''; position:absolute; height:18px; width:18px;
  left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s;
}
.toggle-switch input:checked + .toggle-slider { background:var(--accent); }
.toggle-switch input:checked + .toggle-slider::before { transform:translateX(18px); }
.radio-group { display:flex; gap:8px; flex-wrap:wrap; }
.radio-opt {
  padding:6px 14px; border-radius:8px; font-size:12px; font-weight:600;
  border:1.5px solid var(--border-color); cursor:pointer; transition:all .15s;
  color:var(--text-muted); background:var(--bg-card);
}
.radio-opt.active, .radio-opt:hover { border-color:var(--accent); color:var(--accent); background:var(--accent-bg,#eff6ff); }
.save-btn {
  padding:9px 22px; border-radius:8px; background:var(--accent); color:#fff;
  border:none; cursor:pointer; font-size:13px; font-weight:700;
  font-family:inherit; transition:background .15s; margin-top:16px;
}
.save-btn:hover { opacity:.9; }
.save-success {
  display:none; font-size:12px; color:#16a34a; font-weight:600;
  margin-left:12px; align-items:center; gap:4px;
}
@media(max-width:700px) {
  .settings-grid { grid-template-columns:1fr; }
  .settings-nav { flex-direction:row; flex-wrap:wrap; position:static; }
}
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">⚙️ Settings</h1>
    <p class="page-subtitle">Profile, preferences, notifications & data</p>
  </div>
</div>

<div class="settings-grid">

  <!-- Left Nav -->
  <div class="settings-nav">
    <button class="settings-nav-item active" onclick="showTab('profile',this)">👤 Profile</button>
    <button class="settings-nav-item" onclick="showTab('display',this)">🎨 Display</button>
    <button class="settings-nav-item" onclick="showTab('notifications',this)">🔔 Notifications</button>
    <button class="settings-nav-item" onclick="showTab('data',this)">🗄️ Data & Export</button>
    <button class="settings-nav-item" onclick="showTab('security',this)">🔒 Security</button>
  </div>

  <!-- Right Content -->
  <div>

    <!-- Profile Section -->
    <div class="settings-section active" id="tab-profile">
      <div class="settings-card">
        <div class="settings-card-title">👤 Your Profile</div>

        <div class="form-row" style="margin-bottom:12px;">
          <div class="form-group">
            <label class="form-label">Full Name</label>
            <input type="text" id="pName" class="form-input" value="<?= e($currentUser['name']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" id="pEmail" class="form-input" value="<?= e($currentUser['email']) ?>" readonly style="opacity:.6;">
          </div>
        </div>

        <div class="form-row" style="margin-bottom:12px;">
          <div class="form-group">
            <label class="form-label">Annual Income (approx)</label>
            <select id="pIncome" class="form-select">
              <?php $inc = userPref($uid, 'income_slab', ''); ?>
              <option value="">Prefer not to say</option>
              <?php foreach(['<5L' => 'Under ₹5L', '5-10L' => '₹5–10L', '10-20L' => '₹10–20L', '20-50L' => '₹20–50L', '>50L' => 'Above ₹50L'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= $inc === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Risk Appetite</label>
            <select id="pRisk" class="form-select">
              <?php $risk = userPref($uid, 'risk_appetite', 'moderate'); ?>
              <option value="conservative" <?= $risk === 'conservative' ? 'selected' : '' ?>>🟢 Conservative</option>
              <option value="moderate"     <?= $risk === 'moderate'     ? 'selected' : '' ?>>🟡 Moderate</option>
              <option value="aggressive"   <?= $risk === 'aggressive'   ? 'selected' : '' ?>>🔴 Aggressive</option>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:12px;">
          <label class="form-label">Tax Regime</label>
          <div class="radio-group">
            <?php $regime = userPref($uid, 'tax_regime', 'new'); ?>
            <div class="radio-opt <?= $regime==='new'?'active':'' ?>" onclick="setRadio('pTaxRegime','new',this)">🆕 New Regime</div>
            <div class="radio-opt <?= $regime==='old'?'active':'' ?>" onclick="setRadio('pTaxRegime','old',this)">📋 Old Regime</div>
          </div>
          <input type="hidden" id="pTaxRegime" value="<?= e($regime) ?>">
        </div>

        <button class="save-btn" onclick="saveProfile()">Save Profile</button>
        <span class="save-success" id="profileSaved">✓ Saved!</span>
      </div>

      <div class="settings-card">
        <div class="settings-card-title">🔐 Change Password</div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">New Password</label>
            <input type="password" id="pNewPwd" class="form-input" placeholder="New password" autocomplete="new-password">
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password</label>
            <input type="password" id="pConfirmPwd" class="form-input" placeholder="Confirm" autocomplete="new-password">
          </div>
        </div>
        <div id="pwdErr" style="font-size:12px;color:#dc2626;display:none;margin-bottom:8px;"></div>
        <button class="save-btn" onclick="changePassword()">Update Password</button>
        <span class="save-success" id="pwdSaved">✓ Password updated!</span>
      </div>
    </div>

    <!-- Display Preferences -->
    <div class="settings-section" id="tab-display">
      <div class="settings-card">
        <div class="settings-card-title">🎨 Display Preferences</div>

        <div class="pref-row">
          <div>
            <div class="pref-label">Theme</div>
            <div class="pref-desc">Light ya Dark mode</div>
          </div>
          <div class="radio-group">
            <div class="radio-opt" id="themeLight" onclick="applyTheme('light',this)">☀️ Light</div>
            <div class="radio-opt" id="themeDark"  onclick="applyTheme('dark',this)">🌙 Dark</div>
          </div>
        </div>

        <div class="pref-row">
          <div>
            <div class="pref-label">Number Format</div>
            <div class="pref-desc">₹1.3L (Short) ya ₹1,30,000 (Full)</div>
          </div>
          <div class="radio-group">
            <div class="radio-opt" id="fmtShort" onclick="applyNumFmt('short',this)">1.3L Short</div>
            <div class="radio-opt" id="fmtFull"  onclick="applyNumFmt('full',this)">1,30,000 Full</div>
          </div>
        </div>

        <div class="pref-row">
          <div>
            <div class="pref-label">Dashboard Widgets</div>
            <div class="pref-desc">Kaunse tiles dashboard pe dikhein</div>
          </div>
          <div style="font-size:11px;color:var(--accent);">Coming soon</div>
        </div>

      </div>
    </div>

    <!-- Notifications -->
    <div class="settings-section" id="tab-notifications">
      <div class="settings-card">
        <div class="settings-card-title">🔔 Notification Preferences</div>
        <div id="notifPrefsWrap">
          <div style="color:var(--text-muted);font-size:13px;">⏳ Loading…</div>
        </div>
        <button class="save-btn" onclick="saveNotifPrefs()">Save Preferences</button>
        <span class="save-success" id="notifPrefSaved">✓ Saved!</span>
      </div>
    </div>

    <!-- Data & Export -->
    <div class="settings-section" id="tab-data">
      <div class="settings-card">
        <div class="settings-card-title">🗄️ Data Export</div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div class="pref-row">
            <div>
              <div class="pref-label">MF Transactions CSV</div>
              <div class="pref-desc">Sab mutual fund transactions export karo</div>
            </div>
            <a href="<?= APP_URL ?>/api/mutual_funds/mf_import_csv.php?action=export_csv" class="radio-opt active" style="text-decoration:none;">⬇ Download</a>
          </div>
          <div class="pref-row">
            <div>
              <div class="pref-label">FY Gains Report</div>
              <div class="pref-desc">Capital gains ITR-ready CSV</div>
            </div>
            <a href="<?= APP_URL ?>/templates/pages/report_fy.php" class="radio-opt active" style="text-decoration:none;">View Report</a>
          </div>
          <div class="pref-row">
            <div>
              <div class="pref-label">Capital Gains Summary</div>
              <div class="pref-desc">LTCG/STCG with Schedule 112A</div>
            </div>
            <a href="<?= APP_URL ?>/templates/pages/capital_gains_summary.php" class="radio-opt active" style="text-decoration:none;">View</a>
          </div>
        </div>
      </div>

      <div class="settings-card">
        <div class="settings-card-title">⚠️ Danger Zone</div>
        <div class="pref-row">
          <div>
            <div class="pref-label" style="color:#dc2626;">Clear All Notifications</div>
            <div class="pref-desc">Sab notifications delete ho jaayengi</div>
          </div>
          <button class="radio-opt" style="border-color:#fca5a5;color:#dc2626;" onclick="clearNotifs()">🗑 Clear</button>
        </div>
      </div>
    </div>

    <!-- Security -->
    <div class="settings-section" id="tab-security">
      <div class="settings-card">
        <div class="settings-card-title">🔒 Security Info</div>
        <div class="pref-row">
          <div class="pref-label">Account Role</div>
          <span class="badge <?= $currentUser['role']==='admin' ? 'badge-blue' : 'badge-neutral' ?>"><?= e($currentUser['role']) ?></span>
        </div>
        <div class="pref-row">
          <div class="pref-label">Session</div>
          <span style="font-size:12px;color:var(--text-muted);">Active — expires on browser close</span>
        </div>
        <div class="pref-row">
          <div>
            <div class="pref-label">Sign Out All Sessions</div>
            <div class="pref-desc">Sab devices se logout ho jao</div>
          </div>
          <a href="<?= APP_URL ?>/auth/logout.php" class="radio-opt" style="border-color:#fca5a5;color:#dc2626;text-decoration:none;">Sign Out</a>
        </div>
      </div>
    </div>

  </div><!-- /right -->
</div>

<script>
const APP = '<?= APP_URL ?>';

/* Tab switching */
function showTab(id, btn) {
  document.querySelectorAll('.settings-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.settings-nav-item').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
  if (id === 'notifications') loadNotifPrefs();
  if (id === 'display') initDisplayToggles();
}

/* Radio helper */
function setRadio(inputId, val, el) {
  document.getElementById(inputId).value = val;
  el.closest('.radio-group').querySelectorAll('.radio-opt').forEach(o => o.classList.remove('active'));
  el.classList.add('active');
}

/* Display toggles */
function initDisplayToggles() {
  const theme = localStorage.getItem('wd_theme') || 'light';
  document.getElementById(theme === 'dark' ? 'themeDark' : 'themeLight').classList.add('active');
  const fmt = localStorage.getItem('wd_num_format') || 'short';
  document.getElementById(fmt === 'full' ? 'fmtFull' : 'fmtShort').classList.add('active');
}
initDisplayToggles();

function applyTheme(t, el) {
  document.getElementById('themeLight').classList.remove('active');
  document.getElementById('themeDark').classList.remove('active');
  el.classList.add('active');
  document.documentElement.setAttribute('data-theme', t);
  localStorage.setItem('wd_theme', t);
}

function applyNumFmt(f, el) {
  document.getElementById('fmtShort').classList.remove('active');
  document.getElementById('fmtFull').classList.remove('active');
  el.classList.add('active');
  window.WD_NUM_SHORT = f === 'short';
  localStorage.setItem('wd_num_format', f);
  if (typeof toggleNumFormat === 'function' && window.WD_NUM_SHORT !== (f === 'short')) toggleNumFormat();
}

/* Save profile */
async function saveProfile() {
  const name       = document.getElementById('pName').value.trim();
  const income     = document.getElementById('pIncome').value;
  const risk       = document.getElementById('pRisk').value;
  const taxRegime  = document.getElementById('pTaxRegime').value;
  if (!name) { alert('Name required'); return; }
  try {
    await fetch(`${APP}/api/router.php`, {
      method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({action:'user_profile_save', name, income_slab: income, risk_appetite: risk, tax_regime: taxRegime})
    });
    const el = document.getElementById('profileSaved');
    el.style.display = 'inline-flex';
    setTimeout(() => el.style.display = 'none', 2500);
    if (typeof showToast === 'function') showToast('Profile saved!', 'success');
  } catch(e) { alert('Save failed'); }
}

/* Change password */
async function changePassword() {
  const pwd  = document.getElementById('pNewPwd').value;
  const conf = document.getElementById('pConfirmPwd').value;
  const err  = document.getElementById('pwdErr');
  err.style.display = 'none';
  if (!pwd || pwd.length < 8) { err.textContent = 'Password must be at least 8 characters'; err.style.display = 'block'; return; }
  if (pwd !== conf)           { err.textContent = 'Passwords do not match';                  err.style.display = 'block'; return; }
  try {
    const r = await fetch(`${APP}/api/router.php`, {
      method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({action:'user_change_password', password: pwd})
    });
    const d = await r.json();
    if (d.success) {
      document.getElementById('pNewPwd').value = ''; document.getElementById('pConfirmPwd').value = '';
      const el = document.getElementById('pwdSaved'); el.style.display = 'inline-flex';
      setTimeout(() => el.style.display = 'none', 2500);
    } else { err.textContent = d.message || 'Failed'; err.style.display = 'block'; }
  } catch(e) { err.textContent = 'Request failed'; err.style.display = 'block'; }
}

/* Notification Prefs */
const NOTIF_LABELS = {
  nav_alerts: {icon:'🔔', label:'NAV Price Alerts', desc:'When a fund hits your target NAV'},
  fd_maturity: {icon:'💰', label:'FD Maturity Reminders', desc:'30/7/1 day before maturity'},
  sip_reminder: {icon:'🔄', label:'SIP Due Reminders', desc:'3 days before SIP debit'},
  drawdown_alerts: {icon:'📉', label:'Drawdown Alerts', desc:'When fund drops 10%+ from ATH'},
  nfo_alerts: {icon:'🆕', label:'NFO Closing Alerts', desc:'2 days before NFO closes'},
};
let _notifPrefs = {};

async function loadNotifPrefs() {
  const wrap = document.getElementById('notifPrefsWrap');
  try {
    const r = await fetch(`${APP}/api/router.php?action=notif_prefs_get`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d = await r.json();
    _notifPrefs = d.prefs || {};
    wrap.innerHTML = Object.entries(NOTIF_LABELS).map(([key, info]) => `
      <div class="pref-row">
        <div>
          <div class="pref-label">${info.icon} ${info.label}</div>
          <div class="pref-desc">${info.desc}</div>
        </div>
        <label class="toggle-switch">
          <input type="checkbox" id="np_${key}" ${_notifPrefs[key] != 0 ? 'checked' : ''}>
          <span class="toggle-slider"></span>
        </label>
      </div>
    `).join('');
  } catch(e) { wrap.innerHTML = '<div style="color:var(--text-muted);font-size:12px;">⚠️ Could not load preferences</div>'; }
}

async function saveNotifPrefs() {
  const body = {action:'notif_prefs_save'};
  Object.keys(NOTIF_LABELS).forEach(k => { body[k] = document.getElementById('np_'+k)?.checked ? 1 : 0; });
  try {
    await fetch(`${APP}/api/router.php`, {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify(body)});
    const el = document.getElementById('notifPrefSaved'); el.style.display='inline-flex';
    setTimeout(() => el.style.display='none', 2500);
  } catch(e) {}
}

async function clearNotifs() {
  if (!confirm('Sab notifications delete karo?')) return;
  await fetch(`${APP}/api/router.php`, {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({action:'notif_clear_all'})});
  if (typeof showToast === 'function') showToast('All notifications cleared', 'info');
}
</script>

<?php $pageContent = ob_get_clean(); require_once APP_ROOT . '/templates/layout.php'; ?>
