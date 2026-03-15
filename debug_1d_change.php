<!DOCTYPE html>
<html>
<head>
<title>WealthDash — 1D Change Debug</title>
<style>
body { font-family: monospace; background:#111; color:#eee; padding:20px; }
h2 { color:#facc15; }
h3 { color:#60a5fa; margin-top:20px; }
.ok  { color:#4ade80; }
.err { color:#f87171; }
.warn{ color:#fb923c; }
.box { background:#1f2937; border:1px solid #374151; border-radius:8px; padding:16px; margin:10px 0; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { background:#374151; padding:6px 10px; text-align:left; }
td { padding:5px 10px; border-bottom:1px solid #374151; }
.badge { padding:2px 8px; border-radius:4px; font-size:11px; }
.green { background:#166534; color:#4ade80; }
.red   { background:#7f1d1d; color:#f87171; }
.yellow{ background:#78350f; color:#fbbf24; }
</style>
</head>
<body>
<?php
define('WEALTHDASH', true);
require_once dirname(__FILE__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser = require_auth();

echo "<h2>🔍 WealthDash — 1D Change Debug Panel</h2>";
echo "<p style='color:#9ca3af'>User: <b style='color:#fff'>{$currentUser['name']}</b> | Time: " . date('d-M-Y H:i:s') . "</p>";

$db = DB::conn();

// ══════════════════════════════════════
// SECTION 1: Holdings + nav_history status
// ══════════════════════════════════════
echo "<h3>📊 Section 1: Holdings & nav_history Status</h3>";

$stmt = $db->prepare("
    SELECT
        f.id AS fund_id, f.scheme_code, f.scheme_name,
        f.latest_nav, f.latest_nav_date,
        f.prev_nav, f.prev_nav_date,
        SUM(h.total_units) AS total_units,
        COUNT(nh.id) AS nav_history_count,
        MAX(nh.nav_date) AS nh_latest_date,
        MIN(nh.nav_date) AS nh_oldest_date
    FROM mf_holdings h
    JOIN funds f ON f.id = h.fund_id
    JOIN portfolios p ON p.id = h.portfolio_id
    LEFT JOIN nav_history nh ON nh.fund_id = f.id
    WHERE p.user_id = ? AND h.is_active = 1 AND h.total_units > 0
    GROUP BY f.id
    ORDER BY SUM(h.total_invested) DESC
    LIMIT 10
");
$stmt->execute([$currentUser['id']]);
$holdings = $stmt->fetchAll();

echo "<div class='box'>";
echo "<table>
<tr>
  <th>Fund (scheme_code)</th>
  <th>Units</th>
  <th>latest_nav</th>
  <th>latest_nav_date</th>
  <th>prev_nav</th>
  <th>prev_nav_date</th>
  <th>nav_history rows</th>
  <th>nh latest date</th>
  <th>nh oldest date</th>
  <th>1D Possible?</th>
</tr>";

foreach ($holdings as $h) {
    $canDo = false;
    $reason = '';

    if ($h['nav_history_count'] >= 2) {
        $canDo = true; $reason = '✅ nav_history 2+ rows';
    } elseif ($h['nav_history_count'] == 1 && $h['prev_nav'] && $h['prev_nav_date'] < $h['nh_latest_date']) {
        $canDo = true; $reason = '✅ nav_history(1) + prev_nav';
    } elseif ($h['prev_nav'] && $h['latest_nav'] && $h['prev_nav'] != $h['latest_nav']) {
        $canDo = true; $reason = '⚠️ Only prev_nav (same day risk)';
    } else {
        $reason = '❌ No prev data — need mfapi.in';
    }

    $cls = $canDo ? 'green' : 'red';
    $name = strlen($h['scheme_name']) > 35 ? substr($h['scheme_name'],0,35).'…' : $h['scheme_name'];

    echo "<tr>
      <td>{$name}<br><small style='color:#9ca3af'>{$h['scheme_code']}</small></td>
      <td>{$h['total_units']}</td>
      <td>{$h['latest_nav']}</td>
      <td>{$h['latest_nav_date']}</td>
      <td>" . ($h['prev_nav'] ?: '<span class="err">NULL</span>') . "</td>
      <td>" . ($h['prev_nav_date'] ?: '<span class="err">NULL</span>') . "</td>
      <td style='text-align:center'>{$h['nav_history_count']}</td>
      <td>" . ($h['nh_latest_date'] ?: '—') . "</td>
      <td>" . ($h['nh_oldest_date'] ?: '—') . "</td>
      <td><span class='badge {$cls}'>{$reason}</span></td>
    </tr>";
}
echo "</table></div>";

// ══════════════════════════════════════
// SECTION 2: mfapi.in live test (first fund)
// ══════════════════════════════════════
echo "<h3>🌐 Section 2: mfapi.in Live Test (First Fund)</h3>";

$firstFund = $holdings[0] ?? null;
if ($firstFund) {
    $code = $firstFund['scheme_code'];
    $url  = "https://api.mfapi.in/mf/{$code}";
    echo "<div class='box'>";
    echo "<p>Testing: <a href='{$url}' target='_blank' style='color:#60a5fa'>{$url}</a></p>";

    $ctx = stream_context_create(['http'=>['timeout'=>10,'user_agent'=>'WealthDash/1.0'],'ssl'=>['verify_peer'=>false]]);
    $t1  = microtime(true);
    $raw = @file_get_contents($url, false, $ctx);
    $t2  = microtime(true);
    $ms  = round(($t2-$t1)*1000);

    if ($raw === false) {
        echo "<p class='err'>❌ FAILED to fetch mfapi.in — " . error_get_last()['message'] . "</p>";
        echo "<p class='warn'>⚠️ Possible reasons: No internet on server, firewall blocking outbound, allow_url_fopen=Off</p>";
    } else {
        echo "<p class='ok'>✅ Fetched in {$ms}ms, " . strlen($raw) . " bytes</p>";
        $json = json_decode($raw, true);
        $data = $json['data'] ?? [];
        echo "<p>Total NAV records: <b>" . count($data) . "</b></p>";
        if (count($data) >= 2) {
            echo "<table>
            <tr><th>#</th><th>Date</th><th>NAV</th></tr>";
            foreach (array_slice($data, 0, 5) as $i => $row) {
                echo "<tr><td>{$i}</td><td>{$row['date']}</td><td>{$row['nav']}</td></tr>";
            }
            echo "</table>";

            $nav0 = (float)$data[0]['nav'];
            $nav1 = (float)$data[1]['nav'];
            $diff = $nav0 - $nav1;
            $pct  = round(($diff/$nav1)*100, 3);
            $sign = $diff >= 0 ? '+' : '';
            $cls  = $diff >= 0 ? 'ok' : 'err';
            echo "<p class='{$cls}'>1D Change: {$sign}" . round($diff,4) . " ({$sign}{$pct}%)</p>";
            echo "<p class='ok'>✅ mfapi.in working — 1D data available!</p>";
        } elseif (count($data) == 1) {
            echo "<p class='warn'>⚠️ Only 1 NAV record — prev day not available yet</p>";
        } else {
            echo "<p class='err'>❌ No NAV data in response</p>";
        }
    }
    echo "</div>";
}

// ══════════════════════════════════════
// SECTION 3: PHP Config checks
// ══════════════════════════════════════
echo "<h3>⚙️ Section 3: PHP Config</h3>";
echo "<div class='box'><table>
<tr><th>Setting</th><th>Value</th><th>Status</th></tr>";

$checks = [
    'allow_url_fopen'  => [ini_get('allow_url_fopen'),  '1'],
    'curl extension'   => [extension_loaded('curl') ? 'loaded' : 'missing', 'loaded'],
    'json extension'   => [extension_loaded('json') ? 'loaded' : 'missing', 'loaded'],
    'PHP version'      => [PHP_VERSION, null],
    'max_execution_time' => [ini_get('max_execution_time'), null],
];

foreach ($checks as $k => [$val, $expected]) {
    $ok = $expected === null ? true : ($val == $expected || $val === true);
    $cls = $ok ? 'green' : 'red';
    echo "<tr><td>{$k}</td><td>{$val}</td><td><span class='badge {$cls}'>" . ($ok ? '✅ OK' : '❌ PROBLEM') . "</span></td></tr>";
}
echo "</table></div>";

// ══════════════════════════════════════
// SECTION 4: nav_1d_change.php API response
// ══════════════════════════════════════
echo "<h3>🔌 Section 4: nav_1d_change.php API Test</h3>";
echo "<div class='box'>";

$apiUrl = (defined('APP_URL') ? APP_URL : '') . '/api/nav/nav_1d_change.php';
echo "<p>API URL: <code>{$apiUrl}</code></p>";

// Internal call via curl (with session)
if (extension_loaded('curl')) {
    $ch = curl_init($apiUrl);
    // Forward session cookies
    $cookieStr = '';
    foreach ($_COOKIE as $k => $v) $cookieStr .= "$k=$v; ";
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIE         => $cookieStr,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'WealthDash-Debug/1.0',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo "<p class='err'>❌ cURL error: {$err}</p>";
    } elseif ($code !== 200) {
        echo "<p class='err'>❌ HTTP {$code}</p><pre style='color:#f87171'>" . htmlspecialchars($resp) . "</pre>";
    } else {
        $data = json_decode($resp, true);
        if ($data['success'] ?? false) {
            echo "<p class='ok'>✅ API returned success | Source: <b>{$data['source']}</b> | Funds: <b>{$data['count']}</b></p>";
            echo "<table><tr><th>fund_id</th><th>latest_nav</th><th>latest_date</th><th>prev_nav</th><th>prev_date</th><th>day_change_amt</th><th>day_change_pct</th><th>Status</th></tr>";
            foreach (($data['data'] ?? []) as $fid => $fd) {
                $hasDiff = $fd['day_change_amt'] !== null;
                $cls2 = $hasDiff ? 'green' : 'yellow';
                $status = $hasDiff ? '✅ Has 1D data' : '⚠️ No diff';
                echo "<tr>
                  <td>{$fid}</td>
                  <td>{$fd['latest_nav']}</td>
                  <td>{$fd['latest_date']}</td>
                  <td>" . ($fd['prev_nav'] ?: '<span class="err">null</span>') . "</td>
                  <td>" . ($fd['prev_date'] ?: '<span class="err">null</span>') . "</td>
                  <td>" . ($fd['day_change_amt'] ?? '—') . "</td>
                  <td>" . ($fd['day_change_pct'] ?? '—') . "%</td>
                  <td><span class='badge {$cls2}'>{$status}</span></td>
                </tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='err'>❌ API error: " . htmlspecialchars($data['message'] ?? 'Unknown') . "</p>";
            echo "<pre style='color:#f87171'>" . htmlspecialchars($resp) . "</pre>";
        }
    }
} else {
    echo "<p class='warn'>⚠️ cURL not available — cannot test API internally</p>";
}
echo "</div>";

echo "<hr style='border-color:#374151;margin:30px 0'>";
echo "<p style='color:#6b7280;font-size:12px'>Debug page — delete after use. WealthDash v1</p>";
?>
</body>
</html>
