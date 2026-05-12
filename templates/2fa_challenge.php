<?php
/**
 * WealthDash — 2FA Login Challenge Page
 * Shown after password auth if totp_enabled = 1
 * t386
 */
defined('WEALTHDASH') or die();

require_once APP_ROOT . '/includes/totp.php';

// Challenge token from POST (set by login handler before redirect)
$challengeToken = clean($_GET['t'] ?? $_SESSION['2fa_challenge'] ?? '');
if (!$challengeToken) {
    header('Location: ' . APP_URL . '?page=login');
    exit;
}

// Validate token exists & not expired
$row = DB::fetchRow(
    'SELECT user_id, expires_at FROM totp_sessions WHERE token = ? AND expires_at > NOW()',
    [$challengeToken]
);
if (!$row) {
    flash_set('error', 'Session expired. Please log in again.');
    header('Location: ' . APP_URL . '?page=login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>2FA Verification — WealthDash</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css?v=<?= filemtime(APP_ROOT.'/public/css/app.css') ?>">
  <style>
    .tfa-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-secondary);}
    .tfa-card{background:var(--bg);border:1px solid var(--border);border-radius:16px;padding:40px 36px;width:100%;max-width:400px;box-shadow:var(--shadow-md);}
    .tfa-icon{font-size:2.5rem;text-align:center;margin-bottom:8px;}
    .tfa-title{font-size:1.3rem;font-weight:700;text-align:center;margin-bottom:4px;}
    .tfa-sub{color:var(--text-muted);font-size:.9rem;text-align:center;margin-bottom:28px;}
    .tfa-input{width:100%;text-align:center;font-size:2rem;letter-spacing:.5rem;font-weight:700;padding:14px;border:2px solid var(--border);border-radius:10px;background:var(--bg-secondary);color:var(--text);outline:none;box-sizing:border-box;}
    .tfa-input:focus{border-color:var(--accent);}
    .tfa-btn{width:100%;margin-top:16px;padding:14px;border-radius:10px;border:none;background:var(--accent);color:#fff;font-size:1rem;font-weight:700;cursor:pointer;}
    .tfa-btn:disabled{opacity:.6;cursor:default;}
    .tfa-err{margin-top:12px;padding:10px 14px;border-radius:8px;background:#fee2e2;color:#991b1b;font-size:.88rem;display:none;}
    .tfa-backup-link{display:block;text-align:center;margin-top:16px;font-size:.88rem;color:var(--accent);cursor:pointer;}
    .tfa-backup-wrap{display:none;margin-top:16px;}
  </style>
</head>
<body>
<div class="tfa-wrap">
  <div class="tfa-card">
    <div class="tfa-icon">🔐</div>
    <h1 class="tfa-title">Two-Factor Authentication</h1>
    <p class="tfa-sub">Enter the 6-digit code from your authenticator app.</p>

    <input class="tfa-input" id="totpCode" type="text" inputmode="numeric" pattern="[0-9]*"
           maxlength="6" autocomplete="one-time-code" placeholder="000000" autofocus>
    <div class="tfa-err" id="totpErr"></div>
    <button class="tfa-btn" id="totpBtn" onclick="verifyTOTP()">Verify</button>

    <a class="tfa-backup-link" onclick="toggleBackup()">Use a backup code instead</a>

    <div class="tfa-backup-wrap" id="backupWrap">
      <input class="tfa-input" id="backupCode" type="text" maxlength="10" placeholder="XXXXXXXX"
             style="font-size:1.3rem;letter-spacing:.2rem;">
      <button class="tfa-btn" id="backupBtn" onclick="verifyBackup()" style="background:#6b7280;">
        Use Backup Code
      </button>
    </div>
  </div>
</div>

<script>
var CHALLENGE = '<?= e($challengeToken) ?>';
var APP_URL   = '<?= e(APP_URL) ?>';

function showErr(msg) {
  var el = document.getElementById('totpErr');
  el.textContent = msg;
  el.style.display = 'block';
}

function verifyTOTP() {
  var code = document.getElementById('totpCode').value.replace(/\D/g,'');
  if (code.length !== 6) { showErr('Enter a 6-digit code.'); return; }
  var btn = document.getElementById('totpBtn');
  btn.disabled = true; btn.textContent = 'Verifying…';
  fetch(APP_URL + '/api/router.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'2fa_verify_login', challenge_token: CHALLENGE, code: code})
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) { window.location.href = d.data.redirect || APP_URL + '?page=dashboard'; }
    else { showErr(d.message || 'Invalid code.'); btn.disabled=false; btn.textContent='Verify'; }
  })
  .catch(() => { showErr('Network error.'); btn.disabled=false; btn.textContent='Verify'; });
}

function verifyBackup() {
  var code = document.getElementById('backupCode').value.trim().toUpperCase();
  if (!code) { showErr('Enter your backup code.'); return; }
  var btn = document.getElementById('backupBtn');
  btn.disabled = true; btn.textContent = 'Verifying…';
  fetch(APP_URL + '/api/router.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'2fa_backup_login', challenge_token: CHALLENGE, code: code})
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) { window.location.href = d.data.redirect || APP_URL + '?page=dashboard'; }
    else { showErr(d.message || 'Invalid backup code.'); btn.disabled=false; btn.textContent='Use Backup Code'; }
  })
  .catch(() => { showErr('Network error.'); btn.disabled=false; btn.textContent='Use Backup Code'; });
}

function toggleBackup() {
  var w = document.getElementById('backupWrap');
  w.style.display = w.style.display === 'none' ? 'block' : 'none';
}

// Allow Enter key
document.getElementById('totpCode').addEventListener('keydown', function(e){
  if (e.key === 'Enter') verifyTOTP();
});
</script>
</body>
</html>
