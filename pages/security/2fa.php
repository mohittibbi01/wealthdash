<?php
/**
 * WealthDash — t386: 2FA Settings Page
 * File: pages/security/2fa.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle    = '2-Factor Authentication';
$activePage   = 'settings';
$activeSection= 'security';
ob_start();
?>
<div class="page-header">
  <h1 class="page-title">🔐 Two-Factor Authentication</h1>
  <p class="page-subtitle">Secure your account with Google Authenticator / any TOTP app.</p>
</div>

<div class="card" style="max-width:560px;">
  <div class="card-body">

    <!-- Status banner -->
    <div id="tfa-status-banner" class="mb-3" style="display:none;"></div>

    <!-- Disabled state -->
    <div id="tfa-disabled-view">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <span style="font-size:2rem;">🔓</span>
        <div>
          <div style="font-weight:600;font-size:15px;">2FA is <span class="wd-loss">disabled</span></div>
          <div class="text-muted" style="font-size:13px;">Your account is protected by password only.</div>
        </div>
      </div>
      <button class="btn btn-primary" onclick="TFA.startSetup()">Enable 2FA</button>
    </div>

    <!-- Setup flow -->
    <div id="tfa-setup-view" style="display:none;">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">Step 1 — Scan QR code</h3>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">
        Open Google Authenticator (or Authy / any TOTP app) and scan this QR code.
      </p>
      <div style="text-align:center;margin-bottom:16px;">
        <img id="tfa-qr-img" src="" alt="QR Code" style="width:180px;height:180px;border:1px solid var(--border);border-radius:8px;">
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;">
        <div style="color:var(--text-muted);margin-bottom:4px;">Manual entry key:</div>
        <div id="tfa-secret-text" style="font-family:monospace;font-size:13px;word-break:break-all;font-weight:600;letter-spacing:1px;"></div>
      </div>
      <h3 style="font-size:15px;font-weight:700;margin-bottom:10px;">Step 2 — Verify</h3>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">Enter the 6-digit code shown in your app.</p>
      <div style="display:flex;gap:10px;align-items:center;">
        <input type="text" id="tfa-verify-code" class="form-control" maxlength="6" placeholder="000000"
               style="width:140px;font-size:18px;letter-spacing:4px;text-align:center;"
               oninput="this.value=this.value.replace(/\D/g,'')">
        <button class="btn btn-primary" onclick="TFA.verifySetup()">Activate 2FA</button>
        <button class="btn btn-ghost" onclick="TFA.cancelSetup()">Cancel</button>
      </div>
    </div>

    <!-- Enabled state -->
    <div id="tfa-enabled-view" style="display:none;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <span style="font-size:2rem;">🔒</span>
        <div>
          <div style="font-weight:600;font-size:15px;">2FA is <span class="wd-gain">enabled</span></div>
          <div class="text-muted" style="font-size:13px;" id="tfa-enabled-since"></div>
        </div>
      </div>
      <button class="btn btn-danger btn-sm" onclick="TFA.showDisable()">Disable 2FA</button>
    </div>

    <!-- Disable form -->
    <div id="tfa-disable-view" style="display:none;margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
      <h3 style="font-size:14px;font-weight:700;margin-bottom:12px;color:var(--loss);">Disable 2FA</h3>
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input type="password" id="tfa-disable-pass" class="form-control" placeholder="Your account password">
      </div>
      <div class="form-group">
        <label class="form-label">Authenticator Code</label>
        <input type="text" id="tfa-disable-code" class="form-control" maxlength="6" placeholder="000000"
               style="width:140px;font-size:18px;letter-spacing:4px;text-align:center;"
               oninput="this.value=this.value.replace(/\D/g,'')">
      </div>
      <div style="display:flex;gap:10px;">
        <button class="btn btn-danger" onclick="TFA.confirmDisable()">Confirm Disable</button>
        <button class="btn btn-ghost" onclick="TFA.cancelDisable()">Cancel</button>
      </div>
    </div>

    <!-- Backup codes display -->
    <div id="tfa-backup-view" style="display:none;margin-top:20px;border-top:1px solid var(--border);padding-top:16px;">
      <h3 style="font-size:14px;font-weight:700;margin-bottom:8px;">⚠️ Save Backup Codes</h3>
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
        Store these in a safe place. Each can be used once if you lose access to your authenticator.
      </p>
      <div id="tfa-backup-codes" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:14px;"></div>
      <button class="btn btn-secondary btn-sm" onclick="TFA.copyBackup()">📋 Copy All Codes</button>
    </div>

  </div>
</div>

<script>
const TFA = {
  _secret: '',
  _backupCodes: [],

  init() {
    apiPost({ action: '2fa_status' }).then(r => {
      if (!r.ok) return;
      if (r.data.enabled) {
        this._showEnabled(r.data.setup_at);
      } else {
        this._showDisabled();
      }
    });
  },

  startSetup() {
    apiPost({ action: '2fa_setup' }).then(r => {
      if (!r.ok) { showToast(r.message || 'Error', 'error'); return; }
      this._secret = r.data.secret;
      document.getElementById('tfa-qr-img').src = r.data.qr_url;
      document.getElementById('tfa-secret-text').textContent = r.data.secret;
      document.getElementById('tfa-disabled-view').style.display = 'none';
      document.getElementById('tfa-setup-view').style.display = '';
      setTimeout(() => document.getElementById('tfa-verify-code').focus(), 100);
    });
  },

  verifySetup() {
    const code = document.getElementById('tfa-verify-code').value.trim();
    if (code.length !== 6) { showToast('Enter 6-digit code', 'warning'); return; }
    apiPost({ action: '2fa_verify_setup', code }).then(r => {
      if (!r.ok) { showToast(r.message || 'Invalid code', 'error'); return; }
      this._backupCodes = r.data.backup_codes || [];
      document.getElementById('tfa-setup-view').style.display = 'none';
      this._showEnabled(null);
      this._showBackupCodes(this._backupCodes);
      showToast('2FA enabled!', 'success');
    });
  },

  cancelSetup() {
    document.getElementById('tfa-setup-view').style.display = 'none';
    document.getElementById('tfa-disabled-view').style.display = '';
  },

  showDisable() {
    document.getElementById('tfa-disable-view').style.display = '';
    document.getElementById('tfa-disable-pass').focus();
  },

  cancelDisable() {
    document.getElementById('tfa-disable-view').style.display = 'none';
  },

  confirmDisable() {
    const code = document.getElementById('tfa-disable-code').value.trim();
    const pass = document.getElementById('tfa-disable-pass').value;
    if (!code || !pass) { showToast('Password and code required', 'warning'); return; }
    apiPost({ action: '2fa_disable', code, password: pass }).then(r => {
      if (!r.ok) { showToast(r.message || 'Error', 'error'); return; }
      document.getElementById('tfa-disable-view').style.display = 'none';
      document.getElementById('tfa-enabled-view').style.display = 'none';
      document.getElementById('tfa-backup-view').style.display = 'none';
      this._showDisabled();
      showToast('2FA disabled', 'info');
    });
  },

  _showDisabled() {
    document.getElementById('tfa-disabled-view').style.display = '';
    document.getElementById('tfa-enabled-view').style.display = 'none';
  },

  _showEnabled(setupAt) {
    document.getElementById('tfa-enabled-view').style.display = '';
    document.getElementById('tfa-disabled-view').style.display = 'none';
    if (setupAt) {
      document.getElementById('tfa-enabled-since').textContent = 'Enabled on ' + setupAt;
    }
  },

  _showBackupCodes(codes) {
    const wrap = document.getElementById('tfa-backup-codes');
    wrap.innerHTML = codes.map(c =>
      `<div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:6px;padding:6px 10px;font-family:monospace;font-size:13px;text-align:center;">${esc(c)}</div>`
    ).join('');
    document.getElementById('tfa-backup-view').style.display = '';
  },

  copyBackup() {
    const codes = [...document.querySelectorAll('#tfa-backup-codes div')].map(d => d.textContent).join('\n');
    navigator.clipboard.writeText(codes).then(() => showToast('Codes copied!', 'success'));
  }
};

document.addEventListener('DOMContentLoaded', () => TFA.init());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
