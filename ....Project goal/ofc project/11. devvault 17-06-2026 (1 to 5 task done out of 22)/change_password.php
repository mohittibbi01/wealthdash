<?php
require_once __DIR__ . '/auth.php';
require_login();

$db      = get_db();
$error   = '';
$ok      = false;
$force   = isset($_GET['force']) && $_GET['force'] == '1'; // forced change on first login

$accent  = user_pref('accent', '#00d4ff');
$bg      = user_pref('bg_color', '');
$theme   = user_pref('theme', 'dark');
$fsize   = user_pref('font_size', '14');
$ffamily = user_pref('font_family', 'Rajdhani');
// ── Sanitize user preferences at read time (CSS injection prevention) ─────────
$accent  = preg_replace('/[^#a-fA-F0-9]/', '', $accent);
if (empty($accent)) $accent = '#00d4ff';
if (!empty($bg)) {
    $bg = '#' . preg_replace('/[^a-fA-F0-9]/', '', ltrim($bg, '#'));
}
$theme   = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
$fsize   = max(11, min(18, (int)$fsize));
$ffamily = in_array($ffamily, ['Rajdhani', 'Share Tech Mono', 'Orbitron']) ? $ffamily : 'Rajdhani';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $cur = $_POST['cur'] ?? '';
    $new = $_POST['new'] ?? '';
    $con = $_POST['con'] ?? '';

    $st = $db->prepare("SELECT password_hash FROM users WHERE id=?");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();

    if ($force && !$cur) {
        // On force mode, if using default password, verify against known default
        // This handles the edge case where user hasn't set a current password field
        $cur = $_POST['cur'] ?? '';
    }

    if (!password_verify($cur, $u['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $con) {
        $error = 'New password and confirm password do not match.';
    } elseif ($new === $cur) {
        $error = 'New password must be different from current password.';
    } else {
        $db->prepare("UPDATE users SET password_hash=?, password_changed=1 WHERE id=?")
           ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        $_SESSION['password_changed'] = 1;
        log_activity('change_password', null, $force ? 'forced_first_login' : 'voluntary');
        $ok = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — <?= $force ? 'Set New Password' : 'Change Password' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Orbitron:wght@700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?= $accent ?>;
  --user-bg:<?= $bg ? $bg : 'var(--bg)' ?>;
  --bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--border:#1e2d4a;
  --text:#e8edf5;--muted:#5a7a9a;--accent:#00d4ff;--success:#00e676;
  --danger:#ff3d5a;--warning:#ffb300;
}
body{font-family:'Rajdhani',sans-serif;background:var(--user-bg);color:var(--text);
  min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.wrap{width:100%;max-width:460px}
.force-banner{
  background:rgba(255,179,0,.08);border:1px solid rgba(255,179,0,.3);
  color:var(--warning);border-radius:10px;padding:14px 16px;margin-bottom:20px;
  font-family:'Share Tech Mono',monospace;font-size:13px;line-height:1.6}
.force-banner strong{display:block;font-size:14px;margin-bottom:4px}
.back{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:7px;
  font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;
  background:var(--surface2);color:var(--muted);border:1px solid var(--border);
  transition:all .15s;margin-bottom:20px}
.back:hover{color:var(--text)}
h1{font-family:'Orbitron',monospace;font-size:16px;color:var(--accent);margin-bottom:20px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px}
.msg{padding:10px 14px;border-radius:8px;font-size:13px;font-family:'Share Tech Mono',monospace;margin-bottom:14px}
.msg-s{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.25);color:var(--success)}
.msg-e{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);color:var(--danger)}
.field{margin-bottom:14px}
.field label{display:block;font-family:'Share Tech Mono',monospace;font-size:10px;
  text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:6px}
input[type=password]{width:100%;background:var(--surface2);border:1px solid var(--border);
  border-radius:8px;padding:10px 13px;color:var(--text);font-size:14px;
  font-family:'Rajdhani',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
input[type=password]:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,212,255,.08)}
.hint-text{font-size:11px;color:var(--muted);font-family:'Share Tech Mono',monospace;margin-top:5px}
.btn{width:100%;background:var(--accent);color:#000;border:none;border-radius:8px;
  padding:12px;font-size:15px;font-weight:700;font-family:'Rajdhani',sans-serif;
  cursor:pointer;letter-spacing:.5px;transition:opacity .2s;margin-top:4px}
.btn:hover{opacity:.85}
.success-actions{margin-top:14px;display:flex;gap:10px}
.btn-secondary{flex:1;background:var(--surface2);color:var(--text);border:1px solid var(--border);
  border-radius:8px;padding:10px;font-size:14px;font-weight:600;font-family:'Rajdhani',sans-serif;
  cursor:pointer;text-align:center;text-decoration:none;transition:border-color .2s}
.btn-secondary:hover{border-color:var(--accent)}
</style>
</head>
<body>
<div class="wrap">

  <?php if ($force && !$ok): ?>
    <div class="force-banner">
      <strong>⚠ Password Change Required</strong>
      You are using the default password. For security, please set a new password before continuing.
    </div>
  <?php elseif (!$force): ?>
    <a href="index.php" class="back">← Back</a>
  <?php endif; ?>

  <h1>🔑 <?= $force ? 'SET NEW PASSWORD' : 'CHANGE PASSWORD' ?></h1>

  <div class="card">
    <?php if ($ok): ?>
      <div class="msg msg-s">✅ Password changed successfully! You can now access the system.</div>
      <div class="success-actions">
        <a href="index.php" class="btn-secondary">→ Go to Dashboard</a>
      </div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="msg msg-e">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="field">
          <label>Current Password <?= $force ? '(default: admin123)' : '' ?></label>
          <input type="password" name="cur" required autocomplete="current-password"
                 placeholder="<?= $force ? 'admin123' : 'Enter current password' ?>">
        </div>
        <div class="field">
          <label>New Password</label>
          <input type="password" name="new" required autocomplete="new-password"
                 placeholder="Minimum 8 characters">
          <div class="hint-text">Min 8 characters. Use letters, numbers, symbols.</div>
        </div>
        <div class="field">
          <label>Confirm New Password</label>
          <input type="password" name="con" required autocomplete="new-password"
                 placeholder="Re-enter new password">
        </div>
        <button type="submit" class="btn">Update Password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
