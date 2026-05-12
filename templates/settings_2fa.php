<?php
/**
 * WealthDash — 2FA Settings Panel (settings page partial)
 * Include inside settings page where 2FA section should appear.
 * t386
 */
defined('WEALTHDASH') or die();
?>

<div class="settings-section" id="section2fa">
  <h3 class="settings-section-title">🔐 Two-Factor Authentication</h3>
  <p class="settings-section-desc">Add an extra layer of security. Use Google Authenticator, Authy, or any TOTP app.</p>

  <div id="tfa2Status">
    <!-- filled by JS -->
    <div class="skeleton-line" style="height:24px;width:60%;"></div>
  </div>

  <!-- Setup wizard (hidden until JS shows it) -->
  <div id="tfa2SetupWrap" style="display:none;">
    <div style="margin:20px 0 12px;">
      <strong>Step 1:</strong> Scan this QR code with your authenticator app.
    </div>
    <div id="tfa2QrWrap" style="text-align:center;margin-bottom:16px;">
      <!-- QR rendered here -->
    </div>
    <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px;text-align:center;">
      Or enter secret manually: <code id="tfa2Secret" style="font-size:.95rem;letter-spacing:.1rem;"></code>
    </div>
    <div style="margin-bottom:8px;"><strong>Step 2:</strong> Enter the 6-digit code to confirm setup.</div>
    <div style="display:flex;gap:10px;max-width:320px;">
      <input id="tfa2SetupCode" type="text" inputmode="numeric" maxlength="6" placeholder="000000"
             class="form-control" style="flex:1;text-align:center;font-size:1.3rem;letter-spacing:.4rem;">
      <button class="btn btn-primary" onclick="TFA2.enable()">Activate</button>
    </div>
    <div id="tfa2SetupErr" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
  </div>

  <!-- Backup codes reveal (after enable) -->
  <div id="tfa2BackupReveal" style="display:none;">
    <div class="alert alert-warning" style="margin-top:16px;">
      ⚠️ Save these backup codes somewhere safe. Each can be used once if you lose your phone.
    </div>
    <div id="tfa2BackupList" style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;"></div>
    <button class="btn btn-sm btn-secondary" onclick="TFA2.copyBackup()">📋 Copy all codes</button>
  </div>

  <!-- Disable form (shown when 2FA is enabled) -->
  <div id="tfa2DisableWrap" style="display:none;margin-top:16px;">
    <div style="display:flex;gap:10px;max-width:320px;">
      <input id="tfa2DisableCode" type="text" inputmode="numeric" maxlength="10" placeholder="Enter code to disable"
             class="form-control" style="flex:1;">
      <button class="btn btn-danger" onclick="TFA2.disable()">Disable 2FA</button>
    </div>
    <div id="tfa2DisableErr" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
  </div>
</div>

<script>
var TFA2 = (function() {
  var backupCodes = [];

  function api(action, extra) {
    return fetch(window.WD.appUrl + '/api/router.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.WD.csrf},
      body: JSON.stringify(Object.assign({action: action}, extra||{}))
    }).then(r => r.json());
  }

  function renderStatus(enabled, backupLeft) {
    var el = document.getElementById('tfa2Status');
    if (enabled) {
      el.innerHTML = '<span style="color:#16a34a;font-weight:600;">✅ 2FA is enabled</span>'
        + ' <span style="color:var(--text-muted);font-size:.85rem;">('+backupLeft+' backup codes left)</span>'
        + '<br><button class="btn btn-sm btn-secondary" style="margin-top:10px;" onclick="TFA2.showDisable()">Disable 2FA</button>';
    } else {
      el.innerHTML = '<span style="color:#dc2626;font-weight:600;">❌ 2FA is not enabled</span>'
        + '<br><button class="btn btn-sm btn-primary" style="margin-top:10px;" onclick="TFA2.startSetup()">Enable 2FA</button>';
    }
  }

  function loadStatus() {
    api('2fa_status').then(d => {
      if (d.success) renderStatus(d.data.enabled, d.data.backup_left);
    });
  }

  function startSetup() {
    document.getElementById('tfa2SetupWrap').style.display = 'block';
    document.getElementById('tfa2DisableWrap').style.display = 'none';
    api('2fa_setup').then(d => {
      if (!d.success) { alert(d.message); return; }
      document.getElementById('tfa2Secret').textContent = d.data.secret;
      // Generate QR using qrserver.com free API
      var uri = encodeURIComponent(d.data.uri);
      document.getElementById('tfa2QrWrap').innerHTML =
        '<img src="https://api.qrserver.com/v1/create-qr-code/?data='+uri+'&size=180x180&ecc=M" '
        + 'alt="QR Code" style="border:8px solid #fff;border-radius:8px;">';
    });
  }

  function enable() {
    var code = document.getElementById('tfa2SetupCode').value.replace(/\D/g,'');
    if (code.length !== 6) { showErr('tfa2SetupErr','Enter a 6-digit code.'); return; }
    api('2fa_enable', {code: code}).then(d => {
      if (!d.success) { showErr('tfa2SetupErr', d.message); return; }
      document.getElementById('tfa2SetupWrap').style.display = 'none';
      backupCodes = d.data.backup_codes;
      var listEl = document.getElementById('tfa2BackupList');
      listEl.innerHTML = backupCodes.map(c =>
        '<code style="background:var(--bg-secondary);padding:6px 10px;border-radius:6px;font-size:.9rem;">'
        + c.match(/.{1,4}/g).join('-') + '</code>'
      ).join('');
      document.getElementById('tfa2BackupReveal').style.display = 'block';
      renderStatus(true, backupCodes.length);
    });
  }

  function showDisable() {
    document.getElementById('tfa2DisableWrap').style.display = 'block';
  }

  function disable() {
    var code = document.getElementById('tfa2DisableCode').value.trim();
    if (!code) { showErr('tfa2DisableErr','Enter TOTP code or backup code.'); return; }
    if (!confirm('Are you sure you want to disable 2FA? Your account will be less secure.')) return;
    api('2fa_disable', {code: code}).then(d => {
      if (!d.success) { showErr('tfa2DisableErr', d.message); return; }
      document.getElementById('tfa2DisableWrap').style.display = 'none';
      renderStatus(false, 0);
      WDToast.show('2FA disabled.', 'info');
    });
  }

  function copyBackup() {
    var text = backupCodes.map(c => c.match(/.{1,4}/g).join('-')).join('\n');
    navigator.clipboard.writeText(text).then(() => WDToast.show('Backup codes copied!','success'));
  }

  function showErr(id, msg) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 5000);
  }

  // Auto-load
  document.addEventListener('DOMContentLoaded', loadStatus);

  return { startSetup, enable, disable, showDisable, copyBackup, loadStatus };
})();
</script>
