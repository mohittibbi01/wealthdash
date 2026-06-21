<?php
/**
 * WealthDash — t386: 2FA Login Verification Page
 * File: auth/2fa_login.php
 * Shown after password-verified but before session granted.
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

// Must have pending_2fa_userid in session
if (empty($_SESSION['pending_2fa_userid'])) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}
$pageTitle = 'Verify 2FA — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
  <style>
    .tfa-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg);}
    .tfa-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:16px;padding:40px 36px;max-width:400px;width:90%;text-align:center;}
    .tfa-icon{font-size:3rem;margin-bottom:16px;}
    .tfa-title{font-size:20px;font-weight:700;margin-bottom:8px;}
    .tfa-sub{font-size:13px;color:var(--text-muted);margin-bottom:28px;line-height:1.5;}
    .tfa-input{text-align:center;letter-spacing:8px;font-size:24px;font-weight:700;width:100%;padding:14px;border:2px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);outline:none;}
    .tfa-input:focus{border-color:var(--accent);}
    .tfa-btn{width:100%;margin-top:16px;padding:13px;font-size:15px;font-weight:600;border-radius:10px;background:var(--accent);color:#fff;border:none;cursor:pointer;}
    .tfa-btn:hover{opacity:.9;}
    .tfa-error{color:var(--loss);font-size:13px;margin-top:10px;min-height:18px;}
    .tfa-backup{margin-top:20px;font-size:12px;color:var(--text-muted);}
    .tfa-backup a{color:var(--accent);text-decoration:none;cursor:pointer;}
  </style>
</head>
<body>
<div class="tfa-wrap">
  <div class="tfa-card">
    <div class="tfa-icon">🔐</div>
    <div class="tfa-title">Two-Factor Authentication</div>
    <div class="tfa-sub">Enter the 6-digit code from your authenticator app.</div>
    <input type="text" id="tfaCode" class="tfa-input" maxlength="6" placeholder="000000"
           autocomplete="one-time-code" inputmode="numeric" autofocus
           oninput="this.value=this.value.replace(/\D/g,'');if(this.value.length===6)doVerify()">
    <button class="tfa-btn" onclick="doVerify()">Verify & Login</button>
    <div class="tfa-error" id="tfaErr"></div>
    <div class="tfa-backup">
      Lost your phone? <a onclick="toggleBackup()">Use a backup code</a>
    </div>
    <div id="backupWrap" style="display:none;margin-top:12px;">
      <input type="text" id="backupCode" class="tfa-input" maxlength="8" placeholder="XXXXXXXX"
             style="letter-spacing:3px;font-size:18px;">
      <button class="tfa-btn" style="background:var(--text-muted);" onclick="doVerify(true)">Use Backup Code</button>
    </div>
  </div>
</div>
<script>
const _appUrl = '<?= e(APP_URL) ?>';
function toggleBackup() {
  const w = document.getElementById('backupWrap');
  w.style.display = w.style.display === 'none' ? '' : 'none';
}
function doVerify(isBackup = false) {
  const code = isBackup
    ? document.getElementById('backupCode').value.trim()
    : document.getElementById('tfaCode').value.trim();
  document.getElementById('tfaErr').textContent = '';
  if (!code) return;
  fetch(_appUrl + '/api/router.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: '2fa_verify_login', code })
  })
  .then(r => r.json())
  .then(r => {
    if (r.ok) {
      window.location.href = r.data?.redirect || _appUrl + '?page=dashboard';
    } else {
      document.getElementById('tfaErr').textContent = r.message || 'Invalid code.';
      document.getElementById('tfaCode').value = '';
      document.getElementById('tfaCode').focus();
    }
  })
  .catch(() => { document.getElementById('tfaErr').textContent = 'Network error. Try again.'; });
}
</script>
</body>
</html>
