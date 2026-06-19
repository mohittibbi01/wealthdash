<?php
// DevVault Pro — One-time DB fix script
// Run once: http://localhost:8080/fix_db.php
// DELETE this file after running!

define('DB_PATH', __DIR__ . '/data/vault.db');

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Step 1: Drop the broken table completely
    $db->exec("DROP TABLE IF EXISTS login_attempts");
    echo "✅ Step 1: Old login_attempts table dropped.<br>";

    // Step 2: Recreate with correct schema
    $db->exec("CREATE TABLE login_attempts (
        ip_address      TEXT PRIMARY KEY,
        attempts        INTEGER DEFAULT 1,
        last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Step 2: login_attempts table recreated with correct schema.<br><br>";

    // Verify
    $cols = array_column($db->query("PRAGMA table_info(login_attempts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    echo "✅ Columns now: " . implode(', ', $cols) . "<br><br>";
    echo "<strong style='color:green'>✅ Fix complete! Ab login karo.</strong><br><br>";
    echo "<a href='login.php'>→ Login page</a><br><br>";
    echo "<strong style='color:red'>⚠ Is file ko ABHI delete karo: fix_db.php</strong>";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
