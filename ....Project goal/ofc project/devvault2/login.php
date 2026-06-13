<?php
require_once __DIR__ . '/config.php';
session_start();
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u && $p) {
        $db = get_db();
        $st = $db->prepare("SELECT * FROM users WHERE username=?");
        $st->execute([$u]);
        $user = $st->fetch();
        if ($user && password_verify($p, $user['password_hash']) && ($user['is_active'] ?? 1)) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $_SESSION['csrf']     = bin2hex(random_bytes(32));
            $_SESSION['prefs']    = [
                'theme'       => $user['theme'],
                'accent'      => $user['accent_color'],
                'bg_color'    => $user['bg_color'] ?? '',
                'font_size'   => $user['font_size'],
                'font_family' => $user['font_family'],
            ];
            log_activity('login');
            header('Location: index.php'); exit;
        }
        $error = 'Invalid username or password.';
    } else { $error = 'Please fill all fields.'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--border:#1e2d4a;
  --text:#e8edf5;--muted:#5a7a9a;--accent:#00d4ff;--accent2:#0066ff;
  --success:#00e676;--danger:#ff3d5a;
}
body{font-family:'Rajdhani',sans-serif;background:var(--bg);color:var(--text);
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  overflow:hidden;position:relative}

/* Animated grid bg */
body::before{
  content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(0,212,255,.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(0,212,255,.03) 1px,transparent 1px);
  background-size:40px 40px;pointer-events:none;
  animation:gridMove 20s linear infinite}
@keyframes gridMove{from{background-position:0 0}to{background-position:40px 40px}}

body::after{
  content:'';position:fixed;inset:0;
  background:radial-gradient(ellipse at 30% 50%,rgba(0,102,255,.08) 0%,transparent 60%),
    radial-gradient(ellipse at 70% 50%,rgba(0,212,255,.06) 0%,transparent 60%);
  pointer-events:none}

.wrap{width:100%;max-width:400px;padding:20px;position:relative;z-index:1}

.logo{text-align:center;margin-bottom:32px}
.logo-box{
  width:64px;height:64px;margin:0 auto 14px;
  background:linear-gradient(135deg,var(--accent2),var(--accent));
  border-radius:16px;display:flex;align-items:center;justify-content:center;
  font-size:28px;box-shadow:0 0 40px rgba(0,212,255,.3),0 0 80px rgba(0,102,255,.15);
  animation:pulse 3s ease-in-out infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 40px rgba(0,212,255,.3),0 0 80px rgba(0,102,255,.15)}
  50%{box-shadow:0 0 60px rgba(0,212,255,.5),0 0 100px rgba(0,102,255,.25)}}

.logo h1{font-size:30px;font-weight:700;letter-spacing:2px;
  background:linear-gradient(135deg,var(--accent),#fff);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.logo p{font-family:'Share Tech Mono',monospace;font-size:12px;color:var(--muted);
  margin-top:4px;letter-spacing:1px}

.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;
  padding:28px;backdrop-filter:blur(10px);position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--accent),transparent)}

.error{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);
  color:var(--danger);padding:10px 14px;border-radius:8px;font-size:13px;
  margin-bottom:18px;font-family:'Share Tech Mono',monospace;display:flex;align-items:center;gap:8px}

.field{margin-bottom:16px}
.field label{display:block;font-size:12px;font-weight:600;text-transform:uppercase;
  letter-spacing:1.5px;color:var(--muted);margin-bottom:7px;font-family:'Share Tech Mono',monospace}
.field input{width:100%;background:var(--surface2);border:1px solid var(--border);
  border-radius:8px;padding:11px 14px;color:var(--text);font-size:15px;
  font-family:'Rajdhani',sans-serif;font-weight:500;outline:none;
  transition:border-color .2s,box-shadow .2s}
.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,212,255,.1)}
.field input::placeholder{color:var(--muted)}

.btn-login{width:100%;background:linear-gradient(135deg,var(--accent2),var(--accent));
  color:#000;border:none;border-radius:8px;padding:13px;font-size:16px;font-weight:700;
  font-family:'Rajdhani',sans-serif;cursor:pointer;letter-spacing:1px;
  transition:opacity .2s,transform .15s;margin-top:6px;text-transform:uppercase}
.btn-login:hover{opacity:.88;transform:translateY(-1px)}
.btn-login:active{transform:translateY(0)}

.hint{text-align:center;margin-top:16px;font-family:'Share Tech Mono',monospace;
  font-size:11px;color:var(--muted)}
.hint span{color:var(--accent)}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <div class="logo-box">🔐</div>
    <h1>DEVVAULT PRO</h1>
    <p>// CREDENTIAL & PROJECT MANAGER v2.0</p>
  </div>
  <div class="card">
    <?php if($error):?><div class="error">⚠ <?=htmlspecialchars($error)?></div><?php endif;?>
    <form method="POST">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" placeholder="enter username" autofocus autocomplete="username" value="<?=htmlspecialchars($_POST['username']??'')?>">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password">
      </div>
      <button type="submit" class="btn-login">→ Sign In</button>
    </form>
  </div>
  <div class="hint">Default: <span>admin</span> / <span>admin123</span> — pehle login ke baad badlo</div>
</div>
</body>
</html>
