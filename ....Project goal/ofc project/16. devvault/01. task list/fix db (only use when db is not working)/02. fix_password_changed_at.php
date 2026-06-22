<?php
/**
 * Fix Script: Add missing 'password_changed_at' column to users table.
 * Run ONCE via browser: http://localhost:8080/fix_password_changed_at.php
 * DELETE this file after running it.
 */

define('DB_PATH', __DIR__ . '/data/vault.db');

if (!file_exists(DB_PATH)) {
    die("❌ Database not found at: " . DB_PATH);
}

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if column already exists
    $cols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');

    if (in_array('password_changed_at', $colNames)) {
        echo "✅ Column 'password_changed_at' already exists. No changes needed.<br>";
    } else {
        // SQLite ALTER TABLE does NOT support CURRENT_TIMESTAMP as default.
        // Use NULL default, then backfill with datetime('now').
        $db->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME DEFAULT NULL");
        $db->exec("UPDATE users SET password_changed_at = datetime('now') WHERE password_changed_at IS NULL");
        echo "✅ Successfully added 'password_changed_at' column and backfilled existing rows.<br>";
    }

    echo "<br>✅ Done! Delete this file and reload your application.";

} catch (Exception $e) {
    echo "❌ Error: " . htmlspecialchars($e->getMessage());
}
