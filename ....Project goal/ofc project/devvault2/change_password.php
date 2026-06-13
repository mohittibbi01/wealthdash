<?php
require_once __DIR__ . '/auth.php';
require_login();
$db=$db=get_db(); $error=''; $ok=false;
$accent  = user_pref('accent','#00d4ff');
$bg      = user_pref('bg_color','');
$theme   = user_pref('theme','dark');
$fsize   = user_pref('font_size','14');
$ffamily = user_pref('font_family','Rajdhani');

if($_SERVER['REQUEST_METHOD']==='POST'&&verify_csrf()){
    $cur=$_POST['cur']??''; $new=$_POST['new']??''; $con=$_POST['con']??'';
    $st=$db->prepare("SELECT password_hash FROM users WHERE id=?");$st->execute([$_SESSION['user_id']]);$u=$st->fetch();
    if(!password_verify($cur,$u['password_hash']))$error='Current password incorrect.';
    elseif(strlen($new)<6)$error='New password min 6 characters.';
    elseif($new!==$con)$error='Passwords do not match.';
    else{$db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$_SESSION['user_id']]);log_activity('change_password');$ok=true;}
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Change Password</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Orbitron:wght@700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--acc:<?=$accent?>;
  --user-bg:<?=$bg?$bg:'var(--bg)'?>;
  --bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--border:#1e2d4a;--text:#e8edf5;--muted:#5a7a9a;--accent:#00d4ff;--success:#00e676;--danger:#ff3d5a}
body{font-family:'Rajdhani',sans-serif;background:var(--user-bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.wrap{width:100%;max-width:420px}
.back{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:7px;font-size:13px;font-weight:600;font-family:'Rajdhani',sans-serif;cursor:pointer;border:none;text-decoration:none;background:var(--surface2);color:var(--muted);border:1px solid var(--border);transition:all .15s;margin-bottom:20px}
.back:hover{color:var(--text)}
h1{font-family:'Orbitron',monospace;font-size:16px;color:var(--accent);margin-bottom:20px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px}
.msg{padding:10px 14px;border-radius:8px;font-size:13px;font-family:'Share Tech Mono',monospace;margin-bottom:14px}
.msg-s{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.25);color:var(--success)}
.msg-e{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);color:var(--danger)}
.field{margin-bottom:14px}
.field label{display:block;font-family:'Share Tech Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:6px}
input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 13px;color:var(--text);font-size:14px;font-family:'Rajdhani',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,212,255,.08)}
.btn{width:100%;background:var(--accent);color:#000;border:none;border-radius:8px;padding:12px;font-size:15px;font-weight:700;font-family:'Rajdhani',sans-serif;cursor:pointer;letter-spacing:.5px;transition:opacity .2s;margin-top:4px}
.btn:hover{opacity:.85}
</style></head>
<body><div class="wrap">
<a href="index.php" class="back">← Back</a>
<h1>🔑 CHANGE PASSWORD</h1>
<div class="card">
<?php if($ok):?>
<div class="msg msg-s">✅ Password changed successfully!</div>
<a href="index.php" style="color:var(--accent);font-family:'Share Tech Mono',monospace;font-size:13px">← Go to Dashboard</a>
<?php else:?>
<?php if($error):?><div class="msg msg-e">⚠ <?=htmlspecialchars($error)?></div><?php endif;?>
<form method="POST">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">
<div class="field"><label>Current Password</label><input type="password" name="cur" required autocomplete="current-password"></div>
<div class="field"><label>New Password</label><input type="password" name="new" required autocomplete="new-password"></div>
<div class="field"><label>Confirm New Password</label><input type="password" name="con" required autocomplete="new-password"></div>
<button type="submit" class="btn">Update Password</button>
</form>
<?php endif;?>
</div></div></body></html>
