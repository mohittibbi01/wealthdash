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
    $error = "Bahut zyada failed attempts. {$mins} minute baad dobara try karein.";
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
                $attempt_info = get_attempt_info($db, $client_ip);
                $remaining    = MAX_LOGIN_ATTEMPTS - (int)$attempt_info['attempts'];

                if (is_locked_out($attempt_info)) {
                    $locked = true;
                    $error  = "Bahut zyada failed attempts. 15 minute baad dobara try karein.";
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
  padding:28px;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--accent),transparent)}
.error{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);
  color:var(--danger);padding:10px 14px;border-radius:8px;font-size:13px;
  margin-bottom:18px;font-family:'Share Tech Mono',monospace;display:flex;align-items:center;gap:8px}
.locked-box{background:rgba(255,61,90,.06);border:1px solid rgba(255,61,90,.3);
  border-radius:10px;padding:16px;margin-bottom:18px;text-align:center}
.locked-box .lock-icon{font-size:32px;margin-bottom:8px}
.locked-box p{font-family:'Share Tech Mono',monospace;font-size:12px;color:var(--danger);line-height:1.8}
.locked-box .countdown{font-size:18px;font-weight:700;color:var(--danger);margin-top:6px}
.field{margin-bottom:16px}
.field label{display:block;font-size:12px;font-weight:600;text-transform:uppercase;
  letter-spacing:1.5px;color:var(--muted);margin-bottom:7px;font-family:'Share Tech Mono',monospace}
.field input{width:100%;background:var(--surface2);border:1px solid var(--border);
  border-radius:8px;padding:11px 14px;color:var(--text);font-size:15px;
  font-family:'Rajdhani',sans-serif;font-weight:500;outline:none;
  transition:border-color .2s,box-shadow .2s}
.field input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,212,255,.1)}
.field input::placeholder{color:var(--muted)}
.field input:disabled{opacity:.5;cursor:not-allowed}
.btn-login{width:100%;background:linear-gradient(135deg,var(--accent2),var(--accent));
  color:#000;border:none;border-radius:8px;padding:13px;font-size:16px;font-weight:700;
  font-family:'Rajdhani',sans-serif;cursor:pointer;letter-spacing:1px;
  transition:opacity .2s,transform .15s;margin-top:6px;text-transform:uppercase}
.btn-login:hover:not(:disabled){opacity:.88;transform:translateY(-1px)}
.btn-login:active:not(:disabled){transform:translateY(0)}
.btn-login:disabled{opacity:.4;cursor:not-allowed;background:#333}
.hint{text-align:center;margin-top:16px;font-family:'Share Tech Mono',monospace;
  font-size:11px;color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <div class="logo-box">🔐</div>
    <h1>DEVVAULT PRO</h1>
    <p>// CREDENTIAL & PROJECT MANAGER v<?= defined('APP_VERSION') ? APP_VERSION : '3.0.0' ?></p>
  </div>
  <div class="card">
    <?php if ($locked): ?>
      <div class="locked-box">
        <div class="lock-icon">🔒</div>
        <p><?= htmlspecialchars($error) ?></p>
        <div class="countdown" id="lockdown-timer"></div>
      </div>
      <button class="btn-login" disabled>Access Locked</button>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="POST">
        <div class="field">
          <label>Username</label>
          <input type="text" name="username" placeholder="enter username" autofocus
                 autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" placeholder="••••••••" autocomplete="current-password">
        </div>
        <button type="submit" class="btn-login">→ Sign In</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="hint">DevVault Pro — Authorized users only</div>
</div>
<?php if ($locked && $lockout_remaining > 0): ?>
<script>
(function(){
  var secs = <?= (int)$lockout_remaining ?>;
  var el   = document.getElementById('lockdown-timer');
  function tick(){
    if(secs <= 0){ location.reload(); return; }
    var m = Math.floor(secs/60), s = secs % 60;
    el.textContent = m + ':' + (s < 10 ? '0' : '') + s + ' remaining';
    secs--; setTimeout(tick, 1000);
  }
  tick();
})();
</script>
<?php endif; ?>
</body>
</html>