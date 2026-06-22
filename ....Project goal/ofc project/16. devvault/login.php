<?php
require_once __DIR__ . '/config.php';

ini_set('session.gc_maxlifetime', 360);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

// ── Brute-force protection constants ─────────────────────────────────────────
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS', 900); // 15 minutes

$error   = '';
$login_csrf = bin2hex(random_bytes(16));
$_nonce = base64_encode(random_bytes(16));
$remaining_attempts = MAX_LOGIN_ATTEMPTS;
$locked  = false;
$lockout_remaining = 0;

// ── URL error messages ────────────────────────────────────────────────────────
$url_err = $_GET['err'] ?? '';
if ($url_err === 'session_expired') {
    $error = 'Aapka session expire ho gaya hai (8 ghante poore ho gaye). Please dobara login karein.';
}

$db         = get_db();
$client_ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── Fix login_attempts table if missing columns (one-time self-heal) ──────────
try {
    $_lc = array_column($db->query("PRAGMA table_info(login_attempts)")->fetchAll(), 'name');
    if (!empty($_lc) && !in_array('attempts', $_lc)) {
        $db->exec("DROP TABLE IF EXISTS login_attempts");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        ip_address      TEXT PRIMARY KEY,
        attempts        INTEGER DEFAULT 1,
        last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $_e) {}

// ── Check current lockout status ──────────────────────────────────────────────
function get_attempt_info(PDO $db, string $ip): array {
    try {
        $st = $db->prepare(
            "SELECT attempts, last_attempt_at FROM login_attempts
             WHERE ip_address = ? LIMIT 1"
        );
        $st->execute([$ip]);
        return $st->fetch() ?: ['attempts' => 0, 'last_attempt_at' => null];
    } catch (Exception $e) {
        return ['attempts' => 0, 'last_attempt_at' => null];
    }
}

function is_locked_out(array $info): bool {
    if ((int)($info['attempts'] ?? 0) < MAX_LOGIN_ATTEMPTS) return false;
    $last = strtotime($info['last_attempt_at'] ?? '0');
    return (time() - $last) < LOCKOUT_SECONDS;
}

function seconds_remaining(array $info): int {
    $last = strtotime($info['last_attempt_at'] ?? '0');
    return max(0, LOCKOUT_SECONDS - (time() - $last));
}

function record_failed_attempt(PDO $db, string $ip): void {
    $db->prepare(
        "INSERT INTO login_attempts (ip_address, attempts, last_attempt_at)
         VALUES (?, 1, datetime('now'))
         ON CONFLICT(ip_address) DO UPDATE SET
           attempts = attempts + 1,
           last_attempt_at = datetime('now')"
    )->execute([$ip]);
}

function clear_attempts(PDO $db, string $ip): void {
    $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
}

// ── Also clean up expired lockouts (housekeeping) ────────────────────────────
try {
    $db->exec("DELETE FROM login_attempts WHERE
        (attempts >= " . MAX_LOGIN_ATTEMPTS . "
         AND (strftime('%s','now') - strftime('%s', last_attempt_at)) >= " . LOCKOUT_SECONDS . ")
        OR
        (attempts < " . MAX_LOGIN_ATTEMPTS . "
         AND (strftime('%s','now') - strftime('%s', last_attempt_at)) >= 3600)"
    );
} catch (Exception $e) { /* table may not exist yet — handled below */ }

// ── Check current IP status ───────────────────────────────────────────────────
$attempt_info = get_attempt_info($db, $client_ip);

if (is_locked_out($attempt_info)) {
    $locked            = true;
    $lockout_remaining = seconds_remaining($attempt_info);
    $mins              = ceil($lockout_remaining / 60);
    $error = "Bahut zyada failed attempts ({$attempt_info['attempts']}). {$mins} minute baad dobara try karein ya Admin se unlock karwayein.";
}

// ── Process login form ────────────────────────────────────────────────────────
if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';

    if ($u && $p) {
        // Re-check lockout right before processing (race-condition safety)
        $attempt_info = get_attempt_info($db, $client_ip);
        if (is_locked_out($attempt_info)) {
            $locked = true;
            $mins   = ceil(seconds_remaining($attempt_info) / 60);
            $error  = "Bahut zyada failed attempts. {$mins} minute baad dobara try karein.";
        } else {
            $st = $db->prepare("SELECT * FROM users WHERE username=?");
            $st->execute([$u]);
            $user = $st->fetch();

            if ($user && password_verify($p, $user['password_hash']) && ($user['is_active'] ?? 1)) {
                // ── Success: clear attempts, create session ───────────────────
                clear_attempts($db, $client_ip);

                $_SESSION['user_id']          = $user['id'];
                $_SESSION['username']         = $user['username'];
                $_SESSION['role']             = $user['role'];
                $_SESSION['csrf']             = bin2hex(random_bytes(32));
                $_SESSION['login_time']       = time(); // absolute session start
                $_SESSION['password_changed'] = (int)($user['password_changed'] ?? 0);
                $_SESSION['prefs']            = [
                    'theme'       => $user['theme'],
                    'accent'      => $user['accent_color'],
                    'bg_color'    => $user['bg_color'] ?? '',
                    'font_size'   => $user['font_size'],
                    'font_family' => $user['font_family'],
                ];
                log_activity('login');

                if ((int)($user['password_changed'] ?? 0) === 0) {
                    header('Location: change_password.php?force=1');
                } else {
                    header('Location: index.php');
                }
                exit;
            } else {
                // ── Failure: record attempt ───────────────────────────────────
                record_failed_attempt($db, $client_ip);
                // Log failed login attempt for audit trail
                $attempted_user = htmlspecialchars(substr($u, 0, 50));
                log_activity('failed_login', null, "IP: {$client_ip} | Username: {$attempted_user}");
                $attempt_info = get_attempt_info($db, $client_ip);
                $remaining    = MAX_LOGIN_ATTEMPTS - (int)$attempt_info['attempts'];

                if (is_locked_out($attempt_info)) {
                    $locked = true;
                    $error  = "Bahut zyada failed attempts. 15 minute baad dobara try karein ya Admin se unlock karwayein.";
                } elseif ($remaining > 0) {
                    $error = "Invalid username or password. ({$remaining} attempts remaining)";
                } else {
                    $error = "Invalid username or password.";
                }
            }
        }
    } else {
        $error = 'Please fill all fields.';
    }
}
?>
<?php
$_theme_l = 'teal-dark';
$_fs_l    = 14;
// CSRF for login form
if(empty($_SESSION['login_csrf'])) $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
$login_csrf = $_SESSION['login_csrf'];
// CSP nonce
if(empty($_SESSION['csp_nonce'])) $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
$_nonce = $_SESSION['csp_nonce'];
// Remaining attempts
$_att = get_attempt_info($db, $client_ip);
$remaining_attempts = max(0, MAX_LOGIN_ATTEMPTS - (int)($_att['attempts'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=htmlspecialchars($_theme_l)?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Login</title>
<link rel="stylesheet" href="assets/theme.css">
<style nonce="<?= $_nonce ?? '' ?>">
/* Login page specific */
.login-body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:var(--bg);position:relative;overflow:hidden}
.login-body::before{content:'';position:fixed;inset:0;pointer-events:none;background-image:radial-gradient(ellipse at 20% 50%,color-mix(in srgb,var(--acc) 6%,transparent) 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,color-mix(in srgb,var(--pur,#c792ea) 5%,transparent) 0%,transparent 55%);z-index:0}
.login-card{position:relative;z-index:1;width:100%;max-width:400px;background:var(--sur);border:1px solid var(--bdr);border-radius:20px;padding:36px 32px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.login-logo{text-align:center;margin-bottom:28px}
.login-logo-icon{width:60px;height:60px;background:var(--acc-dim);border:2px solid var(--acc);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:14px;box-shadow:0 0 24px var(--acc-dim)}
.login-title{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700;color:var(--tx);letter-spacing:2px;margin-bottom:4px}
.login-sub{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--tx3);letter-spacing:1.5px;text-transform:uppercase}
.login-field{margin-bottom:16px}
.login-field label{display:block;font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1.3px;color:var(--tx2);margin-bottom:6px}
.login-field input{width:100%;height:44px;font-size:14px;padding:0 14px;background:var(--sur2);border:1px solid var(--bdr);border-radius:10px;color:var(--tx);font-family:'Inter',system-ui,sans-serif;outline:none;transition:all .15s}
.login-field input:focus{border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-dim)}
.login-field input::placeholder{color:var(--tx3)}
.pw-wrap{position:relative}
.pw-wrap input{padding-right:42px}
.pw-eye-btn{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--tx3);font-size:15px;padding:4px;transition:color .14s}
.pw-eye-btn:hover{color:var(--acc)}
.login-btn{width:100%;height:46px;background:var(--acc);color:var(--acc-text);border:none;border-radius:10px;font-size:14px;font-weight:700;font-family:'Inter',system-ui,sans-serif;cursor:pointer;transition:all .15s;letter-spacing:.5px;margin-top:8px}
.login-btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
.login-btn:active{transform:scale(.98)}
.login-err{background:var(--err-bg);border:1px solid color-mix(in srgb,var(--err) 30%,transparent);color:var(--err);padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:16px;line-height:1.5;display:flex;align-items:flex-start;gap:8px}
.login-warn{background:var(--warn-bg);border:1px solid color-mix(in srgb,var(--warn) 30%,transparent);color:var(--warn);padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:16px;line-height:1.5}
.login-foot{text-align:center;margin-top:20px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--tx3);letter-spacing:.5px}
.lockout-bar{height:4px;background:var(--bdr);border-radius:2px;margin-top:8px;overflow:hidden}
.lockout-fill{height:100%;background:var(--err);border-radius:2px;transition:width 1s linear}
</style>
</head>
<body class="login-body">

<div class="login-card">
  <div class="login-logo">
    <div class="login-logo-icon">🔐</div>
    <div class="login-title">DEVVAULT PRO</div>
    <div class="login-sub">// Credential &amp; Project Manager</div>
  </div>

  <?php if ($locked): ?>
  <div class="login-err">
    <span>🔒</span>
    <div>
      Account <?=$lockout_remaining?> seconds ke liye locked hai.<br>
      <small>IP: <?=htmlspecialchars($client_ip)?></small>
      <div class="lockout-bar"><div class="lockout-fill" id="lk-bar" style="width:<?=min(100,round($lockout_remaining/9))?>%"></div></div>
    </div>
  </div>
  <?php elseif ($error): ?>
  <div class="login-err"><span>⚠</span><div><?=htmlspecialchars($error)?></div></div>
  <?php endif; ?>

  <?php if ($remaining_attempts > 0 && $remaining_attempts <= 3 && !$locked): ?>
  <div class="login-warn">⚠ <?=$remaining_attempts?> attempt<?=$remaining_attempts!==1?'s':''?> baaki — phir IP lock ho jaayegi.</div>
  <?php endif; ?>

  <?php if (!$locked): ?>
  <form method="POST" id="lf" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $login_csrf ?>">

    <div class="login-field">
      <label for="u">Username</label>
      <input type="text" name="username" id="u" placeholder="enter username" autofocus autocomplete="username" value="<?=htmlspecialchars($_POST['username']??'')?>">
    </div>

    <div class="login-field">
      <label for="pw">Password</label>
      <div class="pw-wrap">
        <input type="password" name="password" id="pw" placeholder="••••••••" autocomplete="current-password">
        <button type="button" class="pw-eye-btn" id="pw-eye" title="Show/hide password">👁</button>
      </div>
    </div>

    <button type="submit" class="login-btn" id="sign-btn">→ SIGN IN</button>
  </form>
  <?php else: ?>
  <div style="text-align:center;padding:12px 0">
    <a href="login.php" class="btn btn-ghost" style="display:inline-flex">🔄 Refresh</a>
  </div>
  <?php endif; ?>

  <div class="login-foot">DevVault Pro — Authorized users only</div>
</div>

<script nonce="<?= $_nonce ?? '' ?>">
// Show/hide password
document.getElementById('pw-eye').addEventListener('click',function(){
  var i=document.getElementById('pw');
  i.type=i.type==='password'?'text':'password';
  this.textContent=i.type==='password'?'👁':'🙈';
});
// Submit loading state
var lf=document.getElementById('lf');
if(lf) lf.addEventListener('submit',function(){
  var b=document.getElementById('sign-btn');
  if(b){b.textContent='⏳ Signing in…';b.disabled=true;}
});
<?php if($locked && $lockout_remaining > 0): ?>
// Lockout countdown
(function(){
  var rem=<?=$lockout_remaining?>;
  var bar=document.getElementById('lk-bar');
  var iv=setInterval(function(){
    rem--;if(rem<=0){clearInterval(iv);location.reload();}
    if(bar)bar.style.width=Math.min(100,Math.round(rem/9))+'%';
  },1000);
})();
<?php endif; ?>
</script>
</body>
</html>
