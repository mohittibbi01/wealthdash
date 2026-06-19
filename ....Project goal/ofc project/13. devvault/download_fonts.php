<?php
/**
 * DevVault Pro — Google Fonts Self-Host Setup (Task 19)
 * ───────────────────────────────────────────────────────
 * Run once to download fonts locally — then DELETE this file.
 *
 * HOW TO USE:
 *   1. Make sure server has internet access
 *   2. Visit: http://localhost:8080/download_fonts.php
 *   3. Fonts download to assets/fonts/
 *   4. Replace Google Fonts <link> tags with local CSS (shown at end)
 *   5. DELETE this file
 */
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die('<h2>Admin login required.</h2><a href="login.php">Login</a>');
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Download Fonts</title>
<style>
body{font-family:monospace;background:#070b14;color:#e8edf5;padding:32px}
h1{color:#00d4ff;margin-bottom:20px}
pre{background:#0d1422;border:1px solid #1e2d4a;padding:16px;border-radius:8px;font-size:12px;white-space:pre-wrap;line-height:1.7}
.ok{color:#00e676}.warn{color:#ffd740}.err{color:#ff3d5a}
.btn{background:#00d4ff;color:#000;border:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;margin-top:16px}
</style>
</head>
<body>
<h1>🔤 Google Fonts Self-Host Downloader</h1>

<?php

$font_dir = __DIR__ . '/assets/fonts';

// Fonts used by DevVault Pro
$fonts = [
    'rajdhani-400' => [
        'url'  => 'https://fonts.gstatic.com/s/rajdhani/v15/LDI2apCSOBg7S-QT7q4AOeekWPrP.woff2',
        'file' => 'Rajdhani-Regular.woff2',
        'family' => 'Rajdhani', 'weight' => '400',
    ],
    'rajdhani-500' => [
        'url'  => 'https://fonts.gstatic.com/s/rajdhani/v15/LDI2apCSOBg7S-QT7q4AOe-kWfbP.woff2',
        'file' => 'Rajdhani-Medium.woff2',
        'family' => 'Rajdhani', 'weight' => '500',
    ],
    'rajdhani-600' => [
        'url'  => 'https://fonts.gstatic.com/s/rajdhani/v15/LDI2apCSOBg7S-QT7q4AOeakW_bP.woff2',
        'file' => 'Rajdhani-SemiBold.woff2',
        'family' => 'Rajdhani', 'weight' => '600',
    ],
    'rajdhani-700' => [
        'url'  => 'https://fonts.gstatic.com/s/rajdhani/v15/LDI2apCSOBg7S-QT7q4AOeKkW_bP.woff2',
        'file' => 'Rajdhani-Bold.woff2',
        'family' => 'Rajdhani', 'weight' => '700',
    ],
    'share-tech-mono' => [
        'url'  => 'https://fonts.gstatic.com/s/sharetechmono/v15/J7aHnp1uDWRBEqV98dVQztYldFcLowEFA87Heg.woff2',
        'file' => 'ShareTechMono-Regular.woff2',
        'family' => 'Share Tech Mono', 'weight' => '400',
    ],
    'orbitron-700' => [
        'url'  => 'https://fonts.gstatic.com/s/orbitron/v29/yMJMMIlzdpvBhQQL_SC3X9yhF25-T1nysimBoWgz.woff2',
        'file' => 'Orbitron-Bold.woff2',
        'family' => 'Orbitron', 'weight' => '700',
    ],
    'orbitron-900' => [
        'url'  => 'https://fonts.gstatic.com/s/orbitron/v29/yMJMMIlzdpvBhQQL_SC3X9yhF25-T1nyoimBoWgz.woff2',
        'file' => 'Orbitron-Black.woff2',
        'family' => 'Orbitron', 'weight' => '900',
    ],
];

$run = $_POST['run'] ?? '';
echo '<pre>';

if ($run === '1') {
    if (!is_dir($font_dir)) {
        mkdir($font_dir, 0755, true);
        echo "📁 Created: assets/fonts/\n\n";
    }

    $ok = 0; $fail = 0;
    foreach ($fonts as $key => $f) {
        $dest = $font_dir . '/' . $f['file'];
        if (file_exists($dest)) {
            echo "<span class='ok'>✅ Already exists: {$f['file']}</span>\n";
            $ok++; continue;
        }
        $data = @file_get_contents($f['url']);
        if ($data && strlen($data) > 1000) {
            file_put_contents($dest, $data);
            echo "<span class='ok'>✅ Downloaded: {$f['file']} (" . strlen($data) . " bytes)</span>\n";
            $ok++;
        } else {
            echo "<span class='err'>❌ Failed: {$f['file']} — check internet access</span>\n";
            $fail++;
        }
    }

    echo "\n";
    if ($ok > 0 && $fail === 0) {
        echo "<span class='ok'>🎉 All {$ok} fonts downloaded successfully!</span>\n\n";
        echo "Now add this to your main CSS (in assets/fonts/fonts.css):\n";
        echo "─────────────────────────────────────────────────────────\n\n";
        $css = '';
        foreach ($fonts as $f) {
            $css .= "@font-face {\n";
            $css .= "  font-family: '{$f['family']}';\n";
            $css .= "  font-weight: {$f['weight']};\n";
            $css .= "  font-style: normal;\n";
            $css .= "  src: url('../assets/fonts/{$f['file']}') format('woff2');\n";
            $css .= "  font-display: swap;\n";
            $css .= "}\n\n";
        }
        echo htmlspecialchars($css);
        echo "─────────────────────────────────────────────────────────\n";
        echo "Replace Google Fonts &lt;link&gt; tags with:\n";
        echo "&lt;link rel=\"stylesheet\" href=\"assets/fonts/fonts.css\"&gt;\n\n";
        // Write the CSS file automatically
        file_put_contents($font_dir . '/fonts.css', $css);
        echo "<span class='ok'>✅ CSS file written: assets/fonts/fonts.css</span>\n";
        echo "<span class='warn'>🗑  DELETE this file after: download_fonts.php</span>\n";
    } elseif ($fail > 0) {
        echo "<span class='warn'>⚠ {$fail} fonts failed. Check internet access and retry.</span>\n";
    }
} else {
    echo "Fonts to download:\n\n";
    foreach ($fonts as $f) {
        $exists = file_exists($font_dir . '/' . $f['file']) ? '<span class="ok">✅ exists</span>' : '<span class="warn">⬇ needed</span>';
        echo "  {$f['family']} {$f['weight']} — {$f['file']} {$exists}\n";
    }
    echo "\n";
    echo "Target folder: <strong>assets/fonts/</strong>\n";
    echo "\nClick Download to fetch all fonts from Google servers.";
}
echo '</pre>';

if ($run !== '1'):
?>
<form method="POST">
  <input type="hidden" name="run" value="1">
  <button type="submit" class="btn">⬇ Download All Fonts</button>
</form>
<?php endif; ?>

<p style="margin-top:24px"><a href="index.php" style="color:#5a7a9a;font-size:12px">← Back to Dashboard</a></p>
</body>
</html>
