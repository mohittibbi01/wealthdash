<?php
/**
 * WealthDash — SIP Add Raw Debug
 * Path: wealthdash/debug_sip2.php
 * DELETE after fixing!
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$userId      = (int) $currentUser['id'];

// ── Get real portfolio and fund ──────────────────────────
$portfolioId = (int) ($_SESSION['selected_portfolio_id'] ?? 0);
if (!$portfolioId) {
    $p = DB::fetchOne("SELECT id FROM portfolios WHERE user_id=? LIMIT 1", [$userId]);
    $portfolioId = $p ? (int)$p['id'] : 0;
}

// Get a real fund from holdings
$fund = DB::fetchOne(
    "SELECT f.id, f.scheme_name, f.scheme_code
     FROM mf_holdings h
     JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
     LIMIT 1",
    [$portfolioId]
);
if (!$fund) {
    $fund = DB::fetchOne("SELECT id, scheme_name, scheme_code FROM funds WHERE is_active=1 LIMIT 1");
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<title>SIP Add Raw Debug</title>
<style>
body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:20px;font-size:13px}
pre{background:#1e293b;padding:12px;border-radius:6px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
h2{color:#38bdf8;margin:16px 0 8px}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}
.box{background:#1e293b;border-radius:8px;padding:14px;margin-bottom:12px;border:1px solid #334155}
</style>
</head>
<body>
<h1 style="color:#f1f5f9">🔍 SIP Add Raw Debug</h1>

<div class="box">
<b>Portfolio ID:</b> <?= $portfolioId ?><br>
<b>Fund:</b> <?= e($fund['scheme_name'] ?? 'none') ?> (ID: <?= $fund['id'] ?? 'n/a' ?>)<br>
<b>CSRF Token:</b> <?= e(csrf_token()) ?>
</div>

<h2>Step 1 — Directly call sip_tracker.php logic</h2>
<div class="box">
<?php

// Capture ALL output including warnings
ob_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

$errors = [];

try {
    $fundId    = (int) ($fund['id'] ?? 0);
    $amount    = 1000.00;
    $frequency = 'monthly';
    $sipDay    = 1;
    $startDate = '2025-01-01';
    $endDate   = null;

    echo "<b>Step 1:</b> can_access_portfolio... ";
    $access = can_access_portfolio($portfolioId, $userId);
    echo $access ? "<span class='ok'>✓ YES</span>" : "<span class='err'>✗ NO — THIS IS THE PROBLEM</span>";
    echo "<br>";

    echo "<b>Step 2:</b> fund lookup... ";
    $fundRow = DB::fetchOne('SELECT id, scheme_name, scheme_code FROM funds WHERE id = ?', [$fundId]);
    echo $fundRow ? "<span class='ok'>✓ Found: {$fundRow['scheme_name']}</span>" : "<span class='err'>✗ NOT FOUND</span>";
    echo "<br>";

    echo "<b>Step 3:</b> nav_history count... ";
    $navCount = (int) DB::fetchVal(
        "SELECT COUNT(*) FROM nav_history WHERE fund_id = ? AND nav_date >= ?",
        [$fundId, $startDate]
    );
    echo "<span class='ok'>✓ {$navCount} records</span><br>";

    echo "<b>Step 4:</b> INSERT sip_schedules (ROLLBACK)... ";
    $pdo = DB::conn();
    $pdo->beginTransaction();
    try {
        DB::run(
            'INSERT INTO sip_schedules
             (portfolio_id, asset_type, fund_id, folio_number, sip_amount, frequency,
              sip_day, start_date, end_date, platform, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$portfolioId, 'mf', $fundId, null, $amount, $frequency,
             $sipDay, $startDate, $endDate, null, null]
        );
        $newId = (int) $pdo->lastInsertId();
        $pdo->rollBack();
        echo "<span class='ok'>✓ Would insert ID={$newId}</span><br>";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "<span class='err'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</span><br>";
        $errors[] = 'INSERT sip_schedules: ' . $e->getMessage();
    }

    echo "<b>Step 5:</b> nav_download_progress INSERT... ";
    if ($navCount === 0) {
        $schemeCode = $fundRow['scheme_code'];
        $pdo->beginTransaction();
        try {
            DB::run(
                "INSERT INTO nav_download_progress (scheme_code, fund_id, status, from_date)
                 VALUES (?, ?, 'pending', ?)
                 ON DUPLICATE KEY UPDATE
                   status = 'pending',
                   from_date = LEAST(from_date, ?),
                   error_message = NULL",
                [$schemeCode, $fundId, $startDate, $startDate]
            );
            $pdo->rollBack();
            echo "<span class='ok'>✓ Would queue download</span><br>";
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "<span class='err'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</span><br>";
            $errors[] = 'INSERT nav_download_progress: ' . $e->getMessage();
        }
    } else {
        echo "<span class='warn'>SKIP (navCount={$navCount} > 0)</span><br>";
    }

    echo "<b>Step 6:</b> json_response simulation... ";
    $simResponse = json_encode([
        'success'     => true,
        'message'     => 'SIP added successfully.',
        'data'        => ['id' => 99, 'nav_status' => 'available', 'nav_count' => $navCount],
    ]);
    echo "<span class='ok'>✓ JSON valid</span> Length=" . strlen($simResponse) . "<br>";

} catch (Throwable $e) {
    echo "<span class='err'>✗ EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</span>";
    $errors[] = $e->getMessage();
}

$output = ob_get_clean();
if ($output) {
    echo "<br><b class='err'>⚠ EXTRA OUTPUT DETECTED (this corrupts JSON!):</b>";
    echo "<pre class='err'>" . htmlspecialchars($output) . "</pre>";
} else {
    echo "<br><span class='ok'>✓ No extra output/warnings</span>";
}

?>
</div>

<h2>Step 2 — Check for BOM / whitespace in PHP files</h2>
<div class="box">
<?php
$filesToCheck = [
    APP_ROOT . '/api/reports/sip_tracker.php',
    APP_ROOT . '/api/router.php',
    APP_ROOT . '/config/config.php',
    APP_ROOT . '/config/database.php',
    APP_ROOT . '/includes/helpers.php',
    APP_ROOT . '/includes/auth_check.php',
];
foreach ($filesToCheck as $f) {
    if (!file_exists($f)) { echo "<span class='warn'>MISSING: {$f}</span><br>"; continue; }
    $content = file_get_contents($f);
    $firstChars = substr($content, 0, 10);
    $hasBom     = substr($content, 0, 3) === "\xEF\xBB\xBF";
    $hasLeadingWhitespace = strlen($content) > 0 && $content[0] !== '<' && $content[0] !== '?';
    $lastChars  = rtrim(substr($content, -20));
    $hasTrailingOutput = !str_ends_with(rtrim($content), '?>') && str_ends_with(rtrim($content), '?>');

    $basename = basename($f);
    if ($hasBom) {
        echo "<span class='err'>✗ BOM DETECTED: {$basename}</span><br>";
    } elseif ($hasLeadingWhitespace) {
        echo "<span class='err'>✗ LEADING WHITESPACE: {$basename} (first char: " . ord($content[0]) . ")</span><br>";
    } else {
        echo "<span class='ok'>✓ {$basename}</span><br>";
    }
}
?>
</div>

<h2>Step 3 — Raw HTTP call to sip_add (with session)</h2>
<div class="box">
<?php
// Make actual HTTP call to sip_add
$postData = http_build_query([
    'action'       => 'sip_add',
    'portfolio_id' => $portfolioId,
    'fund_id'      => $fund['id'] ?? 1,
    'sip_amount'   => 100,
    'frequency'    => 'monthly',
    'sip_day'      => 1,
    'start_date'   => '01-01-2025',
    'csrf_token'   => csrf_token(),
]);

$ch = curl_init(APP_URL . '/api/router.php');
curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_POSTFIELDS      => $postData,
    CURLOPT_COOKIE          => 'PHPSESSID=' . session_id(),
    CURLOPT_SSL_VERIFYPEER  => false,
    CURLOPT_TIMEOUT         => 15,
    CURLOPT_HTTPHEADER      => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_FOLLOWLOCATION  => true,
]);
$rawResponse = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
curl_close($ch);

echo "<b>HTTP Status:</b> {$httpCode}<br>";
if ($curlError) echo "<b class='err'>cURL Error:</b> " . htmlspecialchars($curlError) . "<br>";
echo "<b>Raw Response (" . strlen($rawResponse ?: '') . " bytes):</b>";
echo "<pre>" . htmlspecialchars(substr($rawResponse ?: '(empty)', 0, 3000)) . "</pre>";

// Show hex of first 50 bytes to detect hidden chars
echo "<b>First 100 bytes (hex):</b><pre>";
$bytes = substr($rawResponse ?: '', 0, 100);
for ($i = 0; $i < strlen($bytes); $i++) {
    $c = $bytes[$i];
    $ord = ord($c);
    if ($ord < 32 || $ord > 126) {
        echo "<span class='err'>[0x" . sprintf('%02X', $ord) . "]</span>";
    } else {
        echo htmlspecialchars($c);
    }
}
echo "</pre>";

$decoded = @json_decode($rawResponse, true);
if ($decoded === null) {
    echo "<span class='err'>✗ INVALID JSON — " . json_last_error_msg() . "</span><br>";
    echo "<b class='warn'>↑ This is what causes 'Unexpected end of JSON input' in browser!</b>";
} else {
    echo "<span class='ok'>✓ Valid JSON</span><br>";
    if (!empty($decoded['success'])) {
        echo "<span class='ok'>✓ success=true — SIP add works!</span>";
        // Rollback the test SIP
        DB::run("DELETE FROM sip_schedules WHERE portfolio_id=? AND sip_amount=100 AND notes IS NULL ORDER BY id DESC LIMIT 1", [$portfolioId]);
        echo " (test SIP deleted)";
    } else {
        echo "<span class='err'>✗ success=false: " . htmlspecialchars($decoded['message'] ?? 'unknown') . "</span>";
    }
}
?>
</div>

<p style="color:#475569;margin-top:30px">⚠ DELETE after debugging: <code>wealthdash/debug_sip2.php</code></p>
</body>
</html>
