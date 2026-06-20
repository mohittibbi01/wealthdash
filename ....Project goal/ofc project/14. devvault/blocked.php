<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Denied — DevVault Pro</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#070b14;--surface:#0d1422;--border:#1e2d4a;--text:#e8edf5;
  --muted:#5a7a9a;--danger:#ff3d5a;}
body{font-family:'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--text);
  min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}
.box{background:var(--surface);border:1px solid rgba(255,61,90,.25);border-radius:16px;
  padding:40px 32px;max-width:420px;width:100%}
.icon{font-size:48px;margin-bottom:16px}
h1{font-size:22px;font-weight:700;color:var(--danger);margin-bottom:10px;letter-spacing:1px}
p{font-size:14px;color:var(--muted);line-height:1.7;margin-bottom:6px}
.ip{font-family:'Courier New',monospace;font-size:13px;color:var(--danger);
  background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.2);
  padding:6px 12px;border-radius:6px;display:inline-block;margin:12px 0}
.note{font-size:12px;color:var(--muted);margin-top:16px;padding-top:16px;
  border-top:1px solid var(--border)}
</style>
</head>
<body>
<div class="box">
  <div class="icon">🚫</div>
  <h1>ACCESS DENIED</h1>
  <p>Your IP address is not authorised to access this system.</p>
  <div class="ip"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?></div>
  <p>This system is restricted to approved office network IPs only.</p>
  <div class="note">Contact your system administrator to get your IP whitelisted.<br>DevVault Pro — Internal LAN System</div>
</div>
</body>
</html>
