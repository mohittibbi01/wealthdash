<?php
// ============================================================
// GA-55A SYSTEM — fix_password.php
// Yeh file sirf ek baar use karo, or vo bhi project start krne k liye, kaam hone ke baad delete karo
// Rakho: C:\xampp\htdocs\ga55-system\fix_password.php
// Browser me kholo: http://localhost/ga55-system/fix_password.php
// ============================================================

require_once 'config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('<h2 style="color:red">DB Connection Failed: ' . $conn->connect_error . '</h2>');
}

$newPassword = 'Admin@123';
$hash        = password_hash($newPassword, PASSWORD_DEFAULT);

// Update admin password
$stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE username = 'admin'");
$stmt->bind_param('s', $hash);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

// Verify
$check = $conn->query("SELECT id, name, username, role, is_active FROM users WHERE username = 'admin'")->fetch_assoc();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Password Fix</title>
    <style>
        body { font-family: Arial; max-width: 600px; margin: 60px auto; padding: 20px; }
        .ok  { background: #e8f5ee; border: 1px solid #2d7a52; padding: 16px; border-radius: 8px; color: #1a5c38; }
        .err { background: #fce8e8; border: 1px solid #9c2020; padding: 16px; border-radius: 8px; color: #9c2020; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        td, th { border: 1px solid #ddd; padding: 8px 12px; font-size: 13px; }
        th { background: #f0f4f0; text-align: left; }
        .warn { background: #fef3e2; border: 1px solid #f0c860; padding: 12px; border-radius: 6px; margin-top: 16px; font-size: 12px; color: #6a3a00; }
        code { background: #f0f0ee; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
    </style>
</head>
<body>

<h2>GA-55A — Password Fix</h2>

<?php if ($affected > 0 && $check): ?>
<div class="ok">
    ✅ <strong>Password successfully update ho gaya!</strong><br><br>
    Generated Hash: <code style="font-size:10px;word-break:break-all"><?= $hash ?></code>
    <br><br>
    Verify: <strong><?= password_verify($newPassword, $hash) ? '✅ Hash correct hai' : '❌ Hash galat' ?></strong>
</div>

<table>
    <tr><th>Field</th><th>Value</th></tr>
    <tr><td>User ID</td><td><?= $check['id'] ?></td></tr>
    <tr><td>Name</td><td><?= $check['name'] ?></td></tr>
    <tr><td>Username</td><td><strong><?= $check['username'] ?></strong></td></tr>
    <tr><td>Role</td><td><?= $check['role'] ?></td></tr>
    <tr><td>Active</td><td><?= $check['is_active'] ? '✅ Yes' : '❌ No' ?></td></tr>
    <tr><td>Password</td><td><code>Admin@123</code></td></tr>
</table>

<div class="warn">
    ⚠️ <strong>Ab yeh karo:</strong><br>
    1. <a href="http://localhost/ga55-system/pages/01_login.php">Login page pe jaao</a><br>
    2. Username: <code>admin</code> | Password: <code>Admin@123</code> se login karo<br>
    3. Login hone ke baad <strong>yeh file delete karo</strong>: <code>C:\xampp\htdocs\ga55-system\fix_password.php</code>
</div>

<?php elseif ($affected === 0 && $check): ?>
<div class="err">
    ⚠️ Row mili lekin update nahi hua (shayad same hash tha). Try karo login.
</div>
<table>
    <tr><th>Username</th><td><?= $check['username'] ?></td></tr>
    <tr><th>Role</th><td><?= $check['role'] ?></td></tr>
    <tr><th>Active</th><td><?= $check['is_active'] ? 'Yes' : 'No' ?></td></tr>
</table>

<?php else: ?>
<div class="err">
    ❌ <strong>Admin user nahi mila!</strong><br><br>
    Naya admin insert karte hain...
</div>

<?php
// Insert fresh admin
$hash2 = password_hash('Admin@123', PASSWORD_DEFAULT);
$ins   = $conn->prepare("INSERT INTO users (name, username, password_hash, role, is_active) VALUES ('Administrator', 'admin', ?, 'admin', 1)");
$ins->bind_param('s', $hash2);
$ins->execute();
$newId = $conn->insert_id;
$ins->close();
?>

<div class="ok" style="margin-top:12px">
    ✅ Naya admin insert ho gaya! ID: <?= $newId ?><br>
    Username: <code>admin</code> | Password: <code>Admin@123</code>
</div>
<?php endif; ?>

</body>
</html>
