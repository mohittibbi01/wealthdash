<?php
/**
 * DevVault Pro — T-01 Migration Script
 * Run this ONCE on existing installations to apply T-01 changes.
 * Delete this file after running.
 *
 * Usage: Open in browser OR run via CLI: php migration_t01.php
 *
 * IMPORTANT: Run migration_t01.php BEFORE opening any other page.
 * This script adds the password_changed column that config.php needs.
 */

// Step 0: Add password_changed column DIRECTLY before loading config.php
// This prevents the "no column named password_changed" error on first load
define('DB_EARLY_PATH', __DIR__ . '/data/vault.db');
if (file_exists(DB_EARLY_PATH)) {
    try {
        $early_db = new PDO('sqlite:' . DB_EARLY_PATH);
        $early_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $early_db->exec("ALTER TABLE users ADD COLUMN password_changed INTEGER DEFAULT 0");
        // Also add ip_whitelist early so config.php doesn't fail
        $early_db->exec("CREATE TABLE IF NOT EXISTS ip_whitelist (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL UNIQUE,
            label TEXT,
            added_by INTEGER,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active INTEGER DEFAULT 1
        )");
        unset($early_db);
    } catch (Exception $e) {
        // Silent — column may already exist, that's fine
    }
}

require_once __DIR__ . '/config.php';
$db = get_db();

$steps = [];

// 1a. Add password_changed column to users if not exists
try {
    $db->exec("ALTER TABLE users ADD COLUMN password_changed INTEGER DEFAULT 0");
    $steps[] = "✅ Added 'password_changed' column to users table.";
} catch (Exception $e) {
    $steps[] = "ℹ️  'password_changed' column already exists — skipped.";

// 1b. Add updated_at column to users if not exists
try {
    $db->exec("ALTER TABLE users ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    $steps[] = "✅ Added 'updated_at' column to users table.";
} catch (Exception $e) {
    $steps[] = "ℹ️  'updated_at' column already exists — skipped.";
}
}

// 2. Create ip_whitelist table (used by T-02, created here for completeness)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ip_whitelist (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL UNIQUE,
        label TEXT,
        added_by INTEGER,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_active INTEGER DEFAULT 1
    )");
    $steps[] = "✅ Created 'ip_whitelist' table (used by T-02).";
} catch (Exception $e) {
    $steps[] = "⚠️  ip_whitelist table error: " . $e->getMessage();
}

// 3. Mark ALL existing non-admin users as password_changed=1
//    (they were created by admin so they already set their own password)
try {
    $affected = $db->exec("UPDATE users SET password_changed=1 WHERE role != 'admin'");
    $steps[] = "✅ Marked $affected non-admin user(s) as password already changed.";
} catch (Exception $e) {
    $steps[] = "⚠️  Could not update non-admin users: " . $e->getMessage();
}

// 4. The default 'admin' user stays at password_changed=0 ONLY if password is still default
//    Check if admin's current hash matches admin123
try {
    $st = $db->prepare("SELECT password_hash, password_changed FROM users WHERE username='admin'");
    $st->execute();
    $admin = $st->fetch();
    if ($admin) {
        if (password_verify('admin123', $admin['password_hash'])) {
            $db->exec("UPDATE users SET password_changed=0 WHERE username='admin'");
            $steps[] = "⚠️  Admin is still using default password (admin123). Will be forced to change on next login.";
        } else {
            $db->exec("UPDATE users SET password_changed=1 WHERE username='admin'");
            $steps[] = "✅ Admin has already changed password — marked as changed.";
        }
    }
} catch (Exception $e) {
    $steps[] = "⚠️  Admin check error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>T-01 Migration</title>
<style>
body{font-family:monospace;background:#070b14;color:#e8edf5;padding:40px;line-height:2}
h1{color:#00d4ff;margin-bottom:24px}
.step{margin:4px 0;font-size:14px}
.done{color:#00e676;margin-top:24px;font-size:16px;font-weight:bold}
.warn{color:#ffb300;margin-top:12px;font-size:13px}
</style>
</head>
<body>
<h1>🔧 DevVault Pro — T-01 Migration</h1>
<?php foreach ($steps as $s): ?>
  <div class="step"><?= htmlspecialchars($s) ?></div>
<?php endforeach; ?>
<div class="done">✅ Migration complete.</div>
<div class="warn">⚠️  DELETE this file (migration_t01.php) after verifying everything works.</div>
</body>
</html>
