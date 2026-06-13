<?php
/**
 * WealthDash — Load Test Runner [t415]
 * File: debug/load_test.php
 * Worker: ID-M
 * Access: IS_LOCAL + admin only
 *
 * Uses PHP's parallel curl_multi for concurrent requests.
 * NOT a replacement for Apache Bench / k6 — use those for serious load tests.
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

if (!IS_LOCAL) { http_response_code(403); die('Load test: local only.'); }

$outputJson = isset($_GET['json']);

// ── Scenarios ────────────────────────────────────────────────────────────────
$scenarios = [
    'light' => [
        'label'       => 'Light (5 concurrent, 3s)',
        'concurrency' => 5,
        'duration'    => 3,
        'endpoints'   => [
            'api/router.php?action=health_ping',
            'api/router.php?action=bank_summary',
            'api/router.php?action=gs_list',
        ],
    ],
    'medium' => [
        'label'       => 'Medium (10 concurrent, 5s)',
        'concurrency' => 10,
        'duration'    => 5,
        'endpoints'   => [
            'api/router.php?action=health_ping',
            'api/router.php?action=fd_list',
            'api/router.php?action=savings_list',
            'api/router.php?action=bank_list',
            'api/router.php?action=bank_summary',
            'api/router.php?action=al_list',
        ],
    ],
    'stress' => [
        'label'       => 'Stress (20 concurrent, 10s)',
        'concurrency' => 20,
        'duration'    => 10,
        'endpoints'   => [
            'api/router.php?action=health_ping',
            'api/router.php?action=fd_list',
            'api/router.php?action=savings_list',
            'api/router.php?action=bank_list',
            'api/router.php?action=bank_summary',
            'api/router.php?action=al_list',
            'api/router.php?action=gs_list',
            'api/router.php?action=dbm_tables',
            'api/router.php?action=perf_live',
            'api/router.php?action=dv_stats',
        ],
    ],
];

// ── Run a scenario ────────────────────────────────────────────────────────────
function run_load_scenario(array $cfg, string $baseUrl, string $sessionCookie): array {
    $concurrency = $cfg['concurrency'];
    $duration    = $cfg['duration'];
    $endpoints   = $cfg['endpoints'];
    $times       = [];
    $errors      = 0;
    $total       = 0;
    $endTime     = microtime(true) + $duration;
    $runId       = sprintf('%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff));

    while (microtime(true) < $endTime) {
        $mh   = curl_multi_init();
        $chs  = [];
        $batch= min($concurrency, 20); // Cap batch size

        for ($i = 0; $i < $batch; $i++) {
            $ep  = $endpoints[array_rand($endpoints)];
            $url = rtrim($baseUrl, '/') . '/' . $ep;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_COOKIE         => $sessionCookie,
                CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_multi_add_handle($mh, $ch);
            $chs[] = ['ch' => $ch, 'start' => microtime(true)];
        }

        $running = null;
        do { curl_multi_exec($mh, $running); curl_multi_select($mh); }
        while ($running > 0);

        foreach ($chs as $item) {
            $ms      = round((microtime(true) - $item['start']) * 1000, 2);
            $info    = curl_getinfo($item['ch']);
            $code    = (int)($info['http_code'] ?? 0);
            $times[] = $ms;
            $total++;
            if ($code >= 400 || $code === 0) $errors++;
            curl_multi_remove_handle($mh, $item['ch']);
            curl_close($item['ch']);
        }
        curl_multi_close($mh);

        if (microtime(true) >= $endTime) break;
    }

    if (empty($times)) {
        return ['run_id'=>$runId,'total'=>0,'errors'=>0,'avg_ms'=>0,'p50_ms'=>0,'p95_ms'=>0,'p99_ms'=>0,'max_ms'=>0,'rps'=>0];
    }

    sort($times);
    $n    = count($times);
    $avg  = round(array_sum($times) / $n, 2);
    $p50  = $times[(int)($n * 0.50)];
    $p95  = $times[max(0, (int)($n * 0.95) - 1)];
    $p99  = $times[max(0, (int)($n * 0.99) - 1)];
    $max  = $times[$n - 1];
    $rps  = round($total / $duration, 2);

    return [
        'run_id'         => $runId,
        'total'          => $total,
        'success'        => $total - $errors,
        'errors'         => $errors,
        'error_rate_pct' => $total ? round($errors / $total * 100, 1) : 0,
        'avg_ms'         => $avg,
        'p50_ms'         => $p50,
        'p95_ms'         => $p95,
        'p99_ms'         => $p99,
        'max_ms'         => $max,
        'rps'            => $rps,
        'times'          => $times,
    ];
}

// ── Determine what to run ─────────────────────────────────────────────────────
$scenarioKey = $_GET['scenario'] ?? null;
$results     = [];
$cookie      = session_name() . '=' . session_id();

if ($scenarioKey && isset($scenarios[$scenarioKey])) {
    $cfg = $scenarios[$scenarioKey];
    $r   = run_load_scenario($cfg, APP_URL, $cookie);
    $results[$scenarioKey] = array_merge(['label' => $cfg['label']], $r);

    // Save to DB
    try {
        DB::run(
            'INSERT INTO load_test_runs
             (run_id, scenario, concurrency, duration_sec, total_requests, success_count, error_count,
              avg_ms, p50_ms, p95_ms, p99_ms, max_ms, rps, triggered_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $r['run_id'], $scenarioKey,
                $cfg['concurrency'], $cfg['duration'],
                $r['total'], $r['success'], $r['errors'],
                $r['avg_ms'], $r['p50_ms'], $r['p95_ms'], $r['p99_ms'], $r['max_ms'], $r['rps'],
                (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]
        );
    } catch (Exception $e) {}
}

// Load previous runs
$history = [];
try {
    $history = DB::fetchAll('SELECT * FROM load_test_runs ORDER BY created_at DESC LIMIT 20');
} catch (Exception $e) {}

if ($outputJson) {
    header('Content-Type: application/json');
    echo json_encode(['results' => $results, 'history' => $history], JSON_PRETTY_PRINT);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WealthDash Load Tests</title>
<style>
:root{--bg:#0d0f18;--s:#161922;--s2:#1e2235;--s3:#252a42;--border:#2a2f4a;--text:#e2e5f5;
      --muted:#7b84a8;--done:#4fc3a1;--danger:#e05c5c;--warn:#e6a817;--accent:#7c6fcd;--r:8px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',monospace;background:var(--bg);color:var(--text);font-size:13px}
.hdr{padding:14px 20px;background:var(--s);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.hdr h1{font-size:16px;font-weight:700}
.wrap{padding:16px 20px;max-width:1100px}
.card{background:var(--s);border:1px solid var(--border);border-radius:var(--r);margin-bottom:12px}
.card-hdr{padding:10px 14px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px}
.card-body{padding:14px}
.scenarios{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-bottom:16px}
.scenario{background:var(--s);border:1px solid var(--border);border-radius:var(--r);padding:14px}
.scenario h3{font-size:13px;margin-bottom:6px}
.scenario p{font-size:11px;color:var(--muted);margin-bottom:10px}
.btn{padding:7px 16px;border-radius:6px;border:none;font-size:12px;font-weight:700;cursor:pointer;
     background:var(--accent);color:#fff;text-decoration:none;transition:.15s;display:inline-block}
.btn:hover{opacity:.85}
.btn-warn{background:var(--warn)}
.btn-danger{background:var(--danger)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
.metric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-bottom:12px}
.metric{background:var(--s2);border:1px solid var(--border);border-radius:6px;padding:8px 10px}
.metric-val{font-size:18px;font-weight:700;font-family:'Courier New',monospace}
.metric-lbl{font-size:9px;color:var(--muted);text-transform:uppercase;margin-top:2px}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px}
th{padding:6px 10px;text-align:left;color:var(--muted);font-size:10px;font-weight:600;
   background:var(--s2);border-bottom:1px solid var(--border)}
td{padding:6px 10px;border-bottom:1px solid color-mix(in srgb,var(--border) 50%,transparent)}
tr:hover td{background:var(--s2)}
.badge{font-size:10px;padding:1px 7px;border-radius:20px;font-weight:600}
.b-ok{background:color-mix(in srgb,var(--done) 15%,transparent);color:var(--done)}
.b-warn{background:color-mix(in srgb,var(--warn) 15%,transparent);color:var(--warn)}
.b-danger{background:color-mix(in srgb,var(--danger) 15%,transparent);color:var(--danger)}
.running{color:var(--warn);animation:pulse 1s infinite alternate}
@keyframes pulse{from{opacity:.5}to{opacity:1}}
.histogram{display:flex;align-items:flex-end;gap:1px;height:60px;margin-top:8px}
.bar{flex:1;background:var(--accent);opacity:.7;min-height:2px;border-radius:1px 1px 0 0}
</style>
</head>
<body>
<div class="hdr">
    <h1>Load Tests <span style="color:var(--accent);font-size:12px">t415</span></h1>
    <span style="color:var(--muted);font-size:11px"><?= APP_URL ?></span>
    <a href="?json" class="btn btn-outline" style="margin-left:auto;font-size:11px">JSON</a>
</div>
<div class="wrap">

<!-- Scenario Buttons -->
<div class="scenarios">
    <?php foreach ($scenarios as $key => $cfg):
        $btnClass = $key === 'stress' ? 'btn-danger' : ($key === 'medium' ? 'btn-warn' : '');
        $isRunning = isset($results[$key]);
    ?>
    <div class="scenario">
        <h3><?= htmlspecialchars($cfg['label']) ?></h3>
        <p><?= $cfg['concurrency'] ?> concurrent · <?= $cfg['duration'] ?>s · <?= count($cfg['endpoints']) ?> endpoints</p>
        <a href="?scenario=<?= $key ?>" class="btn <?= $btnClass ?>"
           onclick="this.textContent='Running…';this.style.opacity='.6'">
            <?= $isRunning ? '↺ Re-run' : '▶ Run' ?>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Results -->
<?php foreach ($results as $key => $r): ?>
<div class="card">
    <div class="card-hdr">
        Results: <?= htmlspecialchars($r['label']) ?>
        <span class="badge <?= $r['error_rate_pct'] > 5 ? 'b-danger' : 'b-ok' ?>" style="margin-left:8px">
            <?= $r['error_rate_pct'] ?>% errors
        </span>
    </div>
    <div class="card-body">
        <div class="metric-grid">
            <div class="metric"><div class="metric-val"><?= $r['rps'] ?></div><div class="metric-lbl">Req/sec</div></div>
            <div class="metric"><div class="metric-val"><?= $r['total'] ?></div><div class="metric-lbl">Total Requests</div></div>
            <div class="metric"><div class="metric-val" style="color:var(--danger)"><?= $r['errors'] ?></div><div class="metric-lbl">Errors</div></div>
            <div class="metric"><div class="metric-val"><?= $r['avg_ms'] ?>ms</div><div class="metric-lbl">Avg Response</div></div>
            <div class="metric"><div class="metric-val"><?= $r['p50_ms'] ?>ms</div><div class="metric-lbl">P50</div></div>
            <div class="metric"><div class="metric-val <?= $r['p95_ms'] > 1000 ? '' : '' ?>"><?= $r['p95_ms'] ?>ms</div><div class="metric-lbl">P95</div></div>
            <div class="metric"><div class="metric-val"><?= $r['p99_ms'] ?>ms</div><div class="metric-lbl">P99</div></div>
            <div class="metric"><div class="metric-val"><?= $r['max_ms'] ?>ms</div><div class="metric-lbl">Max</div></div>
        </div>

        <!-- Histogram -->
        <?php if (!empty($r['times'])): ?>
        <?php
            $times   = $r['times'];
            $min     = min($times); $max2 = max($times);
            $buckets = 30;
            $range   = $max2 - $min ?: 1;
            $hist    = array_fill(0, $buckets, 0);
            foreach ($times as $t) {
                $b = min($buckets-1, (int)(($t - $min) / $range * $buckets));
                $hist[$b]++;
            }
            $hmax = max($hist) ?: 1;
        ?>
        <div style="font-size:10px;color:var(--muted);margin-bottom:4px">Response time distribution (<?= count($times) ?> samples)</div>
        <div class="histogram">
            <?php foreach ($hist as $h): ?>
            <div class="bar" style="height:<?= round($h / $hmax * 100) ?>%" title="<?= $h ?> requests"></div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--muted);margin-top:2px">
            <span><?= $min ?>ms</span><span><?= $max2 ?>ms</span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- History -->
<?php if ($history): ?>
<div class="card">
    <div class="card-hdr">Previous Runs</div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr><th>Scenario</th><th>Concurrency</th><th>Requests</th><th>RPS</th>
                    <th>Avg</th><th>P95</th><th>Errors</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $h):
                $errPct = $h['total_requests'] ? round($h['error_count']/$h['total_requests']*100,1) : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($h['scenario']) ?></td>
                <td><?= $h['concurrency'] ?></td>
                <td><?= number_format($h['total_requests']) ?></td>
                <td class="mono"><?= $h['rps'] ?>/s</td>
                <td class="mono"><?= $h['avg_ms'] ?>ms</td>
                <td class="mono <?= (float)$h['p95_ms'] > 1000 ? '' : '' ?>"><?= $h['p95_ms'] ?>ms</td>
                <td>
                    <span class="badge <?= $errPct > 5 ? 'b-danger' : 'b-ok' ?>"><?= $errPct ?>%</span>
                </td>
                <td style="color:var(--muted);font-size:11px"><?= $h['created_at'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- External Tools Note -->
<div class="card">
    <div class="card-hdr">Production Load Testing Tools</div>
    <div class="card-body" style="color:var(--muted);font-size:12px;line-height:1.8">
        For serious load testing use these tools against a staging/production environment:<br>
        <code style="color:var(--accent)">ab -n 1000 -c 50 <?= APP_URL ?>/api/router.php?action=health_ping</code> — Apache Bench<br>
        <code style="color:var(--accent)">k6 run tests/load/k6_script.js</code> — k6 (see tests/load/)<br>
        <code style="color:var(--accent)">wrk -t4 -c50 -d30s <?= APP_URL ?>/api/router.php?action=health_ping</code> — wrk<br>
        <br>⚠️ Never run stress tests against production without warning users first.
    </div>
</div>

</div>
</body>
</html>
