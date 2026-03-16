<?php
/**
 * WealthDash — SIP Debug File
 * Path: wealthdash/debug_sip.php
 * DELETE after fixing!
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$userId = (int) $currentUser['id'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<title>SIP Debug — WealthDash</title>
<style>
body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 20px; font-size: 13px; }
h2   { color: #38bdf8; margin: 20px 0 8px; }
.ok  { color: #4ade80; } .err { color: #f87171; } .warn { color: #fbbf24; }
pre  { background: #1e293b; padding: 12px; border-radius: 6px; overflow-x: auto; border-left: 3px solid #334155; }
.box { background: #1e293b; border-radius: 8px; padding: 14px; margin-bottom: 12px; border: 1px solid #334155; }
table { border-collapse: collapse; width: 100%; }
td, th { padding: 6px 12px; border: 1px solid #334155; text-align: left; }
th { background: #273548; color: #94a3b8; }
.tag { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; }
.t-ok  { background:#14532d; color:#4ade80; }
.t-err { background:#7f1d1d; color:#f87171; }
.t-warn{ background:#78350f; color:#fbbf24; }
</style>
</head>
<body>
<h1 style="color:#f1f5f9">🔍 SIP Debug — WealthDash</h1>
<p style="color:#64748b">User: <?= e($currentUser['name'] ?? $currentUser['email'] ?? 'Unknown') ?> (ID: <?= $userId ?>)</p>

<?php

function check(string $label, callable $fn): void {
    echo "<div class='box'><strong>{$label}</strong><br>";
    try {
        $result = $fn();
        if ($result === true) {
            echo "<span class='tag t-ok'>✓ PASS</span>";
        } elseif ($result === false) {
            echo "<span class='tag t-err'>✗ FAIL</span>";
        } else {
            echo "<span class='tag t-ok'>✓ OK</span> <span style='color:#94a3b8'>→ " . htmlspecialchars(print_r($result, true)) . "</span>";
        }
    } catch (Throwable $e) {
        echo "<span class='tag t-err'>✗ ERROR</span> <span class='err'>" . htmlspecialchars($e->getMessage()) . "</span>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    echo "</div>";
}

// ── 1. SESSION ────────────────────────────────────────────────────
echo "<h2>1. Session & Portfolio</h2>";

check("Session user_id", fn() => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : false);
check("Session selected_portfolio_id", fn() => isset($_SESSION['selected_portfolio_id'])
    ? (int)$_SESSION['selected_portfolio_id']
    : "NOT SET (this is the problem!)");

// ── 2. PORTFOLIOS ─────────────────────────────────────────────────
echo "<h2>2. User Portfolios</h2>";
$portfolios = DB::fetchAll("SELECT id, name FROM portfolios WHERE user_id = ?", [$userId]);

if (empty($portfolios)) {
    echo "<div class='box'><span class='tag t-err'>✗ NO PORTFOLIOS FOUND</span> — User has no portfolios!</div>";
} else {
    echo "<div class='box'><table><tr><th>ID</th><th>Name</th><th>can_access?</th></tr>";
    foreach ($portfolios as $p) {
        $canAccess = can_access_portfolio($p['id'], $userId) ? 
            "<span class='tag t-ok'>YES</span>" : 
            "<span class='tag t-err'>NO</span>";
        $active = ($p['id'] == ($_SESSION['selected_portfolio_id'] ?? 0)) ? ' ← active' : '';
        echo "<tr><td>{$p['id']}</td><td>{$p['name']}{$active}</td><td>{$canAccess}</td></tr>";
    }
    echo "</table></div>";
}

// ── 3. sip_schedules TABLE ───────────────────────────────────────
echo "<h2>3. sip_schedules Table</h2>";
check("Table exists", function() {
    $r = DB::fetchOne("SHOW TABLES LIKE 'sip_schedules'");
    return $r ? "EXISTS" : false;
});

check("Frequency ENUM includes daily/fortnightly", function() {
    $r = DB::fetchOne("SHOW COLUMNS FROM sip_schedules LIKE 'frequency'");
    if (!$r) return false;
    return $r['Type'];
});

check("Row count", fn() => (int) DB::fetchVal("SELECT COUNT(*) FROM sip_schedules"));

// ── 4. nav_download_progress TABLE ──────────────────────────────
echo "<h2>4. nav_download_progress Table</h2>";
check("Table exists", function() {
    $r = DB::fetchOne("SHOW TABLES LIKE 'nav_download_progress'");
    return $r ? "EXISTS" : false;
});

check("Row count", fn() => (int) DB::fetchVal("SELECT COUNT(*) FROM nav_download_progress"));

// ── 5. SIMULATE sip_add ──────────────────────────────────────────
echo "<h2>5. Simulate sip_add (with first portfolio)</h2>";

$testPortfolioId = !empty($portfolios) ? (int)$portfolios[0]['id'] : 0;
$testFund = DB::fetchOne("SELECT id, scheme_name, scheme_code FROM funds WHERE is_active=1 LIMIT 1");

if (!$testPortfolioId) {
    echo "<div class='box'><span class='tag t-err'>SKIP</span> No portfolio to test with</div>";
} elseif (!$testFund) {
    echo "<div class='box'><span class='tag t-err'>SKIP</span> No funds in database</div>";
} else {
    echo "<div class='box'>Testing with portfolio_id=<b>{$testPortfolioId}</b>, fund=<b>{$testFund['scheme_name']}</b></div>";

    check("can_access_portfolio({$testPortfolioId})", fn() =>
        can_access_portfolio($testPortfolioId, $userId) ? true : false
    );

    check("DB::fetchVal nav_history count", fn() =>
        (int) DB::fetchVal("SELECT COUNT(*) FROM nav_history WHERE fund_id=? AND nav_date >= '2025-01-01'", [$testFund['id']])
    );

    check("DB::fetchOne nav_download_progress", fn() => 
        DB::fetchOne("SELECT status, from_date FROM nav_download_progress WHERE scheme_code=?", [$testFund['scheme_code']])
        ?: "Not in progress table (will be inserted)"
    );

    check("INSERT INTO sip_schedules (dry run — will rollback)", function() use ($testPortfolioId, $testFund) {
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            DB::run(
                "INSERT INTO sip_schedules
                 (portfolio_id, asset_type, fund_id, sip_amount, frequency, sip_day, start_date)
                 VALUES (?, 'mf', ?, 1000.00, 'monthly', 1, '2025-01-01')",
                [$testPortfolioId, $testFund['id']]
            );
            $id = (int) $pdo->lastInsertId();
            $pdo->rollBack();
            return "Would insert with ID ~{$id} — ROLLBACK OK";
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    });

    check("INSERT INTO nav_download_progress (dry run)", function() use ($testFund) {
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            DB::run(
                "INSERT INTO nav_download_progress (scheme_code, fund_id, status, from_date)
                 VALUES (?, ?, 'pending', '2025-01-01')
                 ON DUPLICATE KEY UPDATE
                   status = 'pending',
                   from_date = LEAST(from_date, '2025-01-01'),
                   error_message = NULL",
                [$testFund['scheme_code'], $testFund['id']]
            );
            $pdo->rollBack();
            return "OK — ROLLBACK";
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    });
}

// ── 6. FULL API SIMULATION ───────────────────────────────────────
echo "<h2>6. Full API Response Test</h2>";
echo "<div class='box'>";
echo "<p style='color:#94a3b8'>Simulating what <code>sip_add</code> action returns as JSON:</p>";

ob_start();
$_SESSION['user_id'] = $userId;
if (!isset($_SESSION['selected_portfolio_id']) && !empty($portfolios)) {
    $_SESSION['selected_portfolio_id'] = $portfolios[0]['id'];
}

$simPortfolioId = (int) ($_SESSION['selected_portfolio_id'] ?? 0);
$simFund = DB::fetchOne("SELECT id, scheme_name, scheme_code FROM funds WHERE is_active=1 LIMIT 1");

$simResult = [
    'would_use_portfolio_id' => $simPortfolioId,
    'would_use_fund_id'      => $simFund['id'] ?? null,
    'portfolio_accessible'   => $simPortfolioId ? can_access_portfolio($simPortfolioId, $userId) : false,
    'nav_history_count'      => $simFund ? (int)DB::fetchVal(
        "SELECT COUNT(*) FROM nav_history WHERE fund_id=? AND nav_date >= '2025-01-01'",
        [$simFund['id']]
    ) : 0,
    'env_APP_URL'   => APP_URL,
    'env_APP_KEY_set' => !empty(env('APP_KEY','')),
];
$warnings = ob_get_clean();

if ($warnings) {
    echo "<span class='tag t-warn'>⚠ PHP WARNINGS/OUTPUT DETECTED:</span><pre class='err'>" 
        . htmlspecialchars($warnings) . "</pre>";
    echo "<p class='err'><b>THIS is likely causing the JSON parse error!</b></p>";
}

echo "<pre>" . htmlspecialchars(json_encode($simResult, JSON_PRETTY_PRINT)) . "</pre>";

if (!$simResult['portfolio_accessible']) {
    echo "<span class='tag t-err'>✗ Portfolio not accessible — this is the error!</span>";
} else {
    echo "<span class='tag t-ok'>✓ Portfolio accessible</span>";
}
echo "</div>";

// ── 7. LIVE API CALL TEST ────────────────────────────────────────
echo "<h2>7. Live Test — sip_list API</h2>";
echo "<div class='box'>";
echo "<p style='color:#94a3b8'>Making a real API call to router.php with portfolio_id={$simPortfolioId}:</p>";

$ch = curl_init(APP_URL . '/api/router.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query([
        'action'       => 'sip_list',
        'portfolio_id' => $simPortfolioId,
        'csrf_token'   => csrf_token(),
    ]),
    CURLOPT_COOKIE         => 'PHPSESSID=' . session_id(),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 10,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<b>HTTP Status:</b> {$httpCode}<br>";
echo "<b>Raw Response:</b><pre>" . htmlspecialchars(substr($response ?: '(empty)', 0, 2000)) . "</pre>";

$decoded = @json_decode($response, true);
if ($decoded === null) {
    echo "<span class='tag t-err'>✗ JSON PARSE FAILED — Response is not valid JSON</span>";
    echo "<br><span class='warn'>First 200 chars: " . htmlspecialchars(substr($response ?? '', 0, 200)) . "</span>";
} else {
    echo "<span class='tag t-ok'>✓ Valid JSON response</span>";
}
echo "</div>";

?>

<h2>Summary</h2>
<div class="box">
<?php
$issues = [];
if (empty($portfolios)) $issues[] = "No portfolios found for user";
if (!isset($_SESSION['selected_portfolio_id']) || !$_SESSION['selected_portfolio_id']) $issues[] = "selected_portfolio_id not in session";

$sipTableExists = DB::fetchOne("SHOW TABLES LIKE 'sip_schedules'");
if (!$sipTableExists) $issues[] = "sip_schedules table does not exist";

if (empty($issues)) {
    echo "<span class='tag t-ok'>✓ No obvious issues found</span> — Check section 7 raw response for clues.";
} else {
    echo "<span class='tag t-err'>Issues found:</span><ul>";
    foreach ($issues as $issue) {
        echo "<li class='err'>✗ {$issue}</li>";
    }
    echo "</ul>";
}
?>
</div>

<p style="color:#475569;margin-top:30px">⚠ DELETE this file after debugging: <code>wealthdash/debug_sip.php</code></p>
</body>
</html>
