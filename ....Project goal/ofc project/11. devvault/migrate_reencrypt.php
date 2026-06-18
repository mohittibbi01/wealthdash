<?php
/**
 * DevVault Pro — Password Re-encryption Migration
 * ─────────────────────────────────────────────────
 * Converts all stored passwords from legacy AES format to new PBKDF2-hardened format.
 *
 * HOW TO RUN:
 *   1. Make sure .env has ENCRYPT_SALT set (it was added automatically)
 *   2. Take a DB backup FIRST: copy data/vault.db somewhere safe
 *   3. Visit this file in browser: http://localhost:8080/migrate_reencrypt.php
 *   4. Click "Run Migration"
 *   5. Verify app works (login, open a project, check passwords)
 *   6. DELETE this file immediately after
 *
 * SAFE TO RUN MULTIPLE TIMES — already-migrated values are skipped.
 */

// ── Admin-only: require session ───────────────────────────────────────────────
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('<!DOCTYPE html><html><head><title>403</title></head><body style="font-family:monospace;background:#070b14;color:#ff3d5a;padding:40px">
    <h2>⛔ Admin login required.</h2><p><a href="login.php" style="color:#00d4ff">← Login first</a></p></body></html>');
}

require_once __DIR__ . '/config.php';

// ── Password columns to migrate ───────────────────────────────────────────────
$PW_COLS = [
    'env_local_password', 'env_staging_password', 'env_production_password',
    'env_audit_password', 'env_other_password',
];

$db = get_db();

$ran    = $_POST['run'] ?? '';
$result = [];
$error  = '';

function is_legacy(string $cipher): bool {
    if ($cipher === '') return false;
    $raw = base64_decode($cipher);
    if ($raw === false) return false;
    // v2 format starts with "v2:"
    if (str_starts_with($raw, 'v2:')) return false;
    // legacy format contains "::"
    return str_contains($raw, '::');
}

if ($ran === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $projects = $db->query("SELECT id, " . implode(', ', $PW_COLS) . " FROM projects")->fetchAll();

        $total     = count($projects);
        $migrated  = 0;
        $skipped   = 0;
        $errors    = 0;

        foreach ($projects as $proj) {
            $updates = [];
            $vals    = [];

            foreach ($PW_COLS as $col) {
                $cipher = $proj[$col] ?? '';
                if ($cipher === '') { $skipped++; continue; }

                if (is_legacy($cipher)) {
                    // Decrypt using old method
                    $raw = base64_decode($cipher);
                    if ($raw && str_contains($raw, '::')) {
                        [$iv, $enc] = explode('::', $raw, 2);
                        $plain = openssl_decrypt($enc, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
                        if ($plain !== false && $plain !== '') {
                            // Re-encrypt using new PBKDF2 method
                            $new_cipher = encrypt_val($plain);
                            $updates[]  = "`{$col}`=?";
                            $vals[]     = $new_cipher;
                            $migrated++;
                        } else {
                            $result[] = "⚠ Project ID {$proj['id']} col {$col}: decrypt failed (keeping old value)";
                            $errors++;
                            $skipped++;
                        }
                    }
                } else {
                    $skipped++; // already v2
                }
            }

            if ($updates) {
                $vals[] = $proj['id'];
                $db->prepare("UPDATE projects SET " . implode(', ', $updates) . " WHERE id=?")->execute($vals);
            }
        }

        $result[] = "✅ Migration complete!";
        $result[] = "   Projects scanned : {$total}";
        $result[] = "   Passwords migrated: {$migrated}";
        $result[] = "   Already v2/empty  : {$skipped}";
        $result[] = "   Errors            : {$errors}";
        if ($errors === 0) {
            $result[] = "";
            $result[] = "🔒 All passwords are now PBKDF2-hardened.";
            $result[] = "🗑  DELETE this file now: rm migrate_reencrypt.php";
        }
    } catch (Exception $e) {
        $error = "DB Error: " . $e->getMessage();
    }
}

// ── Preview: count how many need migration ────────────────────────────────────
$preview = ['total' => 0, 'legacy' => 0, 'already_v2' => 0, 'empty' => 0];
try {
    $all = $db->query("SELECT " . implode(', ', $PW_COLS) . " FROM projects")->fetchAll();
    $preview['total'] = count($all);
    foreach ($all as $row) {
        foreach ($PW_COLS as $col) {
            $v = $row[$col] ?? '';
            if ($v === '') { $preview['empty']++;      }
            elseif (is_legacy($v)) { $preview['legacy']++;   }
            else             { $preview['already_v2']++; }
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DevVault — Re-encrypt Migration</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',monospace;background:#070b14;color:#e8edf5;padding:40px;min-height:100vh}
h1{color:#00d4ff;margin-bottom:6px;font-size:22px}
p.sub{color:#5a7a9a;font-size:13px;margin-bottom:30px}
.card{background:#0d1422;border:1px solid #1e2d4a;border-radius:10px;padding:24px;max-width:600px;margin-bottom:20px}
.card h2{font-size:14px;color:#5a7a9a;margin-bottom:14px;text-transform:uppercase;letter-spacing:1px}
.stat{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #1e2d4a;font-size:13px}
.stat:last-child{border-bottom:none}
.stat .lbl{color:#5a7a9a}.stat .val{font-weight:600;font-family:monospace}
.val.ok{color:#00e676}.val.warn{color:#ffd740}.val.bad{color:#ff3d5a}
.result-box{background:#010409;border:1px solid #1e2d4a;border-radius:8px;padding:16px;
  font-family:monospace;font-size:12px;color:#c9d1d9;white-space:pre;line-height:1.8;max-width:600px;margin-bottom:20px}
.btn{display:inline-block;padding:12px 28px;background:#ff3d5a;color:#fff;border:none;border-radius:8px;
  font-size:15px;font-weight:700;cursor:pointer;font-family:'Segoe UI',sans-serif;margin-right:10px}
.btn:hover{opacity:.85}
.btn-back{background:#1e2d4a;color:#e8edf5;text-decoration:none;font-size:13px;padding:10px 20px;border-radius:8px}
.warn-box{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.3);border-radius:8px;
  padding:14px;max-width:600px;margin-bottom:20px;font-size:13px;color:#ff8a80;line-height:1.7}
.ok-box{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.3);border-radius:8px;
  padding:14px;max-width:600px;margin-bottom:20px;font-size:13px;color:#00e676;line-height:1.7}
</style>
</head>
<body>
<h1>🔐 DevVault — Password Re-encryption Migration</h1>
<p class="sub">Converts legacy AES passwords to PBKDF2-hardened format (v2)</p>

<?php if ($error): ?>
<div class="warn-box">❌ Error: <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
<div class="result-box"><?= htmlspecialchars(implode("\n", $result)) ?></div>
<?php if (strpos(implode('', $result), '✅') !== false): ?>
<div class="ok-box">
  ✅ Migration successful. App mein login karke kuch passwords decrypt karke verify karo.<br>
  🗑 Phir <strong>is file ko DELETE karo</strong>: <code>migrate_reencrypt.php</code>
</div>
<?php endif; ?>
<?php else: ?>

<div class="card">
  <h2>Current Status</h2>
  <div class="stat"><span class="lbl">Total projects</span><span class="val"><?= intval($preview['total']) ?></span></div>
  <div class="stat"><span class="lbl">Legacy passwords (need migration)</span><span class="val <?= $preview['legacy']>0?'warn':'ok' ?>"><?= intval($preview['legacy']) ?></span></div>
  <div class="stat"><span class="lbl">Already v2 (PBKDF2)</span><span class="val ok"><?= intval($preview['already_v2']) ?></span></div>
  <div class="stat"><span class="lbl">Empty (no password stored)</span><span class="val"><?= intval($preview['empty']) ?></span></div>
</div>

<?php if ($preview['legacy'] === 0): ?>
<div class="ok-box">
  ✅ Koi migration needed nahi — sab passwords already v2 format mein hain.<br>
  🗑 Is file ko DELETE karo: <code>migrate_reencrypt.php</code>
</div>
<?php else: ?>
<div class="warn-box">
  ⚠ <strong>Pehle data/vault.db ka backup lo!</strong><br>
  Migration ke baad agar koi issue aaye to backup se restore kar sakte ho.<br><br>
  ENCRYPT_KEY aur ENCRYPT_SALT .env mein sahi hone chahiye — migration ke baad inhe change mat karna.
</div>
<form method="POST">
  <input type="hidden" name="run" value="1">
  <button type="submit" class="btn">🔄 Run Migration Now</button>
  <a href="index.php" class="btn-back">← Cancel</a>
</form>
<?php endif; ?>
<?php endif; ?>

<p style="margin-top:30px"><a href="index.php" style="color:#5a7a9a;font-size:12px">← Back to Dashboard</a></p>
</body>
</html>
