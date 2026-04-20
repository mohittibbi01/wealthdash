<?php
// WealthDash Connection Test - Delete after use
echo "<h2>WealthDash Diagnostic</h2>";
echo "<b>PHP:</b> " . PHP_VERSION . "<br>";
echo "<b>APP_ENV check:</b> ";

if (file_exists(__DIR__ . '/.env')) {
    echo "✅ .env found<br>";
} else {
    echo "❌ .env MISSING — copy .env.example to .env<br>";
}

echo "<b>mod_rewrite:</b> ";
if (function_exists('apache_get_modules')) {
    $mods = apache_get_modules();
    echo in_array('mod_rewrite', $mods) ? "✅ Enabled<br>" : "❌ DISABLED — enable in httpd.conf<br>";
} else {
    echo "⚠️ Cannot check (use phpinfo())<br>";
}

echo "<b>DB test:</b> ";
try {
    $pdo = new PDO('mysql:host=localhost;dbname=wealthdash;charset=utf8mb4', 'root', '');
    echo "✅ Connected to wealthdash DB<br>";
} catch (Exception $e) {
    echo "❌ " . $e->getMessage() . "<br>";
}

echo "<b>Logs dir:</b> ";
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
    echo "✅ Created logs/ folder<br>";
} else {
    echo "✅ logs/ exists<br>";
}

echo "<br><a href='/wealthdash/'>Go to WealthDash →</a>";
