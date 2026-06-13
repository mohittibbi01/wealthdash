<?php
/**
 * WealthDash — Test Runner [t411]
 * File: debug/runner.php
 * Worker: ID-M
 * Access: IS_LOCAL or ROLE_ADMIN only
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

if (!IS_LOCAL && (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin')) {
    http_response_code(403); die('Test runner: admin only.');
}

// ── Micro Test Framework ──────────────────────────────────────────────────────
class WDTest {
    private static array $results  = [];
    private static array $current  = [];
    private static string $suite   = 'unit';
    private static string $runId   = '';
    private static int $passed     = 0;
    private static int $failed     = 0;
    private static int $skipped    = 0;
    private static float $startAll = 0.0;

    public static function init(string $suite): void {
        self::$suite    = $suite;
        self::$runId    = sprintf('%04x%04x-%04x-%04x-%04x-%012x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffffffffffff));
        self::$passed   = 0;
        self::$failed   = 0;
        self::$skipped  = 0;
        self::$results  = [];
        self::$startAll = microtime(true);
    }

    public static function describe(string $name, callable $fn): void {
        self::$current['group'] = $name;
        try { $fn(); } catch (Throwable $e) {
            self::record('error', $name, 0, $e->getMessage());
        }
    }

    public static function it(string $name, callable $fn): void {
        $start = microtime(true);
        try {
            $fn();
            $ms = round((microtime(true) - $start) * 1000, 2);
            self::record('pass', $name, $ms);
            self::$passed++;
        } catch (AssertionError|WDAssertException $e) {
            $ms = round((microtime(true) - $start) * 1000, 2);
            self::record('fail', $name, $ms, $e->getMessage());
            self::$failed++;
        } catch (Throwable $e) {
            $ms = round((microtime(true) - $start) * 1000, 2);
            self::record('error', $name, $ms, get_class($e) . ': ' . $e->getMessage());
            self::$failed++;
        }
    }

    public static function skip(string $name, string $reason = ''): void {
        self::record('skip', $name, 0, $reason);
        self::$skipped++;
    }

    private static function record(string $status, string $name, float $ms, string $msg = ''): void {
        $entry = [
            'suite'    => self::$suite,
            'group'    => self::$current['group'] ?? '',
            'name'     => $name,
            'status'   => $status,
            'ms'       => $ms,
            'message'  => $msg,
        ];
        self::$results[] = $entry;

        // Persist to DB (non-fatal)
        try {
            DB::run(
                'INSERT INTO test_run_log (run_id, suite, test_name, status, duration_ms, message, triggered_by)
                 VALUES (?,?,?,?,?,?,?)',
                [self::$runId, self::$suite, $name, $status, $ms ?: null,
                 $msg ?: null, (int)($_SESSION['user_id'] ?? 0) ?: null]
            );
        } catch (Exception $e) {}
    }

    public static function summary(): array {
        $total = self::$passed + self::$failed + self::$skipped;
        return [
            'run_id'    => self::$runId,
            'suite'     => self::$suite,
            'total'     => $total,
            'passed'    => self::$passed,
            'failed'    => self::$failed,
            'skipped'   => self::$skipped,
            'duration_ms' => round((microtime(true) - self::$startAll) * 1000, 2),
            'pass_rate' => $total ? round(self::$passed / $total * 100, 1) : 0,
            'results'   => self::$results,
        ];
    }
}

// ── Assertion helpers ─────────────────────────────────────────────────────────
class WDAssertException extends RuntimeException {}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new WDAssertException(
            ($msg ? "$msg: " : '') .
            "Expected " . json_encode($expected) . ", got " . json_encode($actual)
        );
    }
}
function assert_true(mixed $val, string $msg = 'Expected true'): void {
    if (!$val) throw new WDAssertException($msg . ' (got ' . json_encode($val) . ')');
}
function assert_false(mixed $val, string $msg = 'Expected false'): void {
    if ($val) throw new WDAssertException($msg . ' (got ' . json_encode($val) . ')');
}
function assert_contains(string $needle, string $haystack, string $msg = ''): void {
    if (!str_contains($haystack, $needle)) {
        throw new WDAssertException(($msg ?: "String does not contain '$needle'"));
    }
}
function assert_not_empty(mixed $val, string $msg = 'Expected non-empty'): void {
    if (empty($val)) throw new WDAssertException($msg);
}
function assert_count(int $expected, array $arr, string $msg = ''): void {
    if (count($arr) !== $expected) {
        throw new WDAssertException(
            ($msg ?: "Count mismatch") . ": expected {$expected}, got " . count($arr)
        );
    }
}
function assert_keys(array $keys, array $arr, string $msg = ''): void {
    foreach ($keys as $k) {
        if (!array_key_exists($k, $arr)) {
            throw new WDAssertException(($msg ?: "Missing key: '$k'"));
        }
    }
}
function assert_greater(float $expected, float $actual, string $msg = ''): void {
    if ($actual <= $expected) {
        throw new WDAssertException(($msg ?: "{$actual} is not > {$expected}"));
    }
}
function assert_null(mixed $val, string $msg = 'Expected null'): void {
    if ($val !== null) throw new WDAssertException($msg . ' (got ' . json_encode($val) . ')');
}
function assert_not_null(mixed $val, string $msg = 'Expected non-null'): void {
    if ($val === null) throw new WDAssertException($msg);
}
function assert_instanceof(string $class, mixed $obj, string $msg = ''): void {
    if (!($obj instanceof $class)) {
        throw new WDAssertException($msg ?: get_class($obj) . " is not instanceof $class");
    }
}
function assert_json(string $str, string $msg = 'Invalid JSON'): array {
    $d = json_decode($str, true);
    if (!is_array($d)) throw new WDAssertException($msg);
    return $d;
}

// ── Suite selector ────────────────────────────────────────────────────────────
$requestedSuite = $_GET['suite'] ?? 'unit';
$outputJson     = isset($_GET['json']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

$suiteMap = [
    'unit'        => __DIR__ . '/tests/test_db.php',
    'api'         => __DIR__ . '/tests/test_api_responses.php',
    'files'       => __DIR__ . '/tests/test_api_files.php',
    'cron'        => __DIR__ . '/tests/test_cron_health.php',
    'ui'          => __DIR__ . '/tests/test_ui_elements.php',
    'all'         => null,
];

$suitesToRun = ($requestedSuite === 'all')
    ? array_filter(array_keys($suiteMap), fn($k) => $k !== 'all')
    : [$requestedSuite];

$allSummaries = [];
foreach ($suitesToRun as $suite) {
    $file = $suiteMap[$suite] ?? null;
    if (!$file || !file_exists($file)) {
        $allSummaries[] = ['suite' => $suite, 'error' => 'Suite file not found: ' . ($file ?? 'unknown')];
        continue;
    }
    WDTest::init($suite);
    require $file;
    $allSummaries[] = WDTest::summary();
}

// ── Output ────────────────────────────────────────────────────────────────────
if ($outputJson) {
    header('Content-Type: application/json');
    echo json_encode(count($allSummaries) === 1 ? $allSummaries[0] : $allSummaries, JSON_PRETTY_PRINT);
    exit;
}

// HTML output
$totalPass  = array_sum(array_column($allSummaries, 'passed'));
$totalFail  = array_sum(array_column($allSummaries, 'failed'));
$totalTotal = array_sum(array_column($allSummaries, 'total'));
$overallPct = $totalTotal ? round($totalPass / $totalTotal * 100, 1) : 0;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WealthDash Test Runner</title>
<style>
:root{--bg:#0d0f18;--surface:#161922;--s2:#1e2235;--s3:#252a42;--border:#2a2f4a;
      --text:#e2e5f5;--muted:#7b84a8;--accent:#7c6fcd;--done:#4fc3a1;
      --danger:#e05c5c;--warn:#e6a817;--r:8px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',monospace;background:var(--bg);color:var(--text);font-size:13px}
.hdr{padding:14px 20px;background:var(--surface);border-bottom:1px solid var(--border);
     display:flex;align-items:center;gap:12px}
.hdr h1{font-size:16px;font-weight:700}
.badge{font-size:10px;padding:2px 8px;border-radius:20px;background:color-mix(in srgb,var(--accent) 18%,transparent);color:var(--accent)}
.wrap{padding:16px 20px;max-width:1200px}
.suite-tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
.suite-tab{padding:6px 14px;border-radius:6px;border:1px solid var(--border);
           background:var(--s2);color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;transition:.15s}
.suite-tab:hover,.suite-tab.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.summary-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin-bottom:16px}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:10px 14px}
.sc-val{font-size:22px;font-weight:700;font-family:'Courier New',monospace}
.sc-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
.suite-block{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);margin-bottom:12px}
.suite-hdr{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;font-weight:700}
.prog{height:4px;background:var(--s3)}
.prog-inner{height:4px;transition:width .6s}
.test-row{padding:7px 14px;border-bottom:1px solid color-mix(in srgb,var(--border) 40%,transparent);
           display:flex;align-items:center;gap:8px;font-size:12px}
.test-row:last-child{border-bottom:none}
.status{font-size:11px;font-weight:700;width:44px;text-align:center;border-radius:4px;padding:1px 4px}
.s-pass{background:color-mix(in srgb,var(--done) 16%,transparent);color:var(--done)}
.s-fail{background:color-mix(in srgb,var(--danger) 16%,transparent);color:var(--danger)}
.s-skip{background:color-mix(in srgb,var(--warn) 16%,transparent);color:var(--warn)}
.s-error{background:color-mix(in srgb,var(--danger) 24%,transparent);color:var(--danger)}
.test-name{flex:1}
.test-ms{color:var(--muted);font-size:10px;font-family:'Courier New',monospace;min-width:50px;text-align:right}
.test-msg{color:var(--danger);font-size:11px;font-family:'Courier New',monospace;
          padding:4px 14px 6px 64px;background:color-mix(in srgb,var(--danger) 6%,transparent)}
.group-lbl{font-size:10px;color:var(--muted);padding:5px 14px 3px;text-transform:uppercase;letter-spacing:.06em;
           background:var(--s2);border-bottom:1px solid var(--border)}
.pill{font-size:10px;padding:1px 7px;border-radius:20px;font-weight:600}
.run-id{font-size:10px;color:var(--muted);font-family:'Courier New',monospace}
</style>
</head>
<body>
<div class="hdr">
    <div>
        <h1>WealthDash Test Runner <span class="badge">t411</span></h1>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= date('d M Y H:i:s') ?></div>
    </div>
    <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach (array_keys($suiteMap) as $s): ?>
        <a href="?suite=<?= $s ?>" class="suite-tab <?= $requestedSuite === $s ? 'active' : '' ?>"><?= $s ?></a>
        <?php endforeach; ?>
        <a href="?suite=<?= $requestedSuite ?>&json" class="suite-tab" style="background:var(--s3)">JSON</a>
    </div>
</div>

<div class="wrap">
<div class="summary-bar">
    <div class="sc">
        <div class="sc-val" style="color:<?= $overallPct >= 90 ? 'var(--done)' : ($overallPct >= 70 ? 'var(--warn)' : 'var(--danger)') ?>"><?= $overallPct ?>%</div>
        <div class="sc-lbl">Pass Rate</div>
    </div>
    <div class="sc">
        <div class="sc-val"><?= $totalTotal ?></div>
        <div class="sc-lbl">Total Tests</div>
    </div>
    <div class="sc">
        <div class="sc-val" style="color:var(--done)"><?= $totalPass ?></div>
        <div class="sc-lbl">Passed</div>
    </div>
    <div class="sc">
        <div class="sc-val" style="color:var(--danger)"><?= $totalFail ?></div>
        <div class="sc-lbl">Failed</div>
    </div>
</div>

<?php foreach ($allSummaries as $s):
    if (isset($s['error'])): ?>
<div class="suite-block">
    <div class="suite-hdr" style="color:var(--danger)">⚠ <?= htmlspecialchars($s['suite']) ?> — <?= htmlspecialchars($s['error']) ?></div>
</div>
<?php continue; endif;
    $pct   = $s['total'] ? round($s['passed'] / $s['total'] * 100) : 0;
    $color = $pct >= 90 ? '#4fc3a1' : ($pct >= 70 ? '#e6a817' : '#e05c5c');
    $lastGroup = '';
?>
<div class="suite-block">
    <div class="suite-hdr">
        <span style="color:var(--accent)"><?= strtoupper($s['suite']) ?></span>
        <span class="pill" style="background:color-mix(in srgb,<?= $color ?> 16%,transparent);color:<?= $color ?>">
            <?= $s['passed'] ?>/<?= $s['total'] ?> · <?= $pct ?>%
        </span>
        <span style="color:var(--muted);font-size:11px;font-weight:400"><?= $s['duration_ms'] ?>ms</span>
        <span class="run-id" style="margin-left:auto"><?= htmlspecialchars($s['run_id']) ?></span>
    </div>
    <div class="prog"><div class="prog-inner" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
    <?php $curGroup = ''; foreach ($s['results'] as $r):
        if ($r['group'] !== $curGroup): $curGroup = $r['group']; ?>
    <div class="group-lbl"><?= htmlspecialchars($curGroup) ?></div>
    <?php endif; ?>
    <div class="test-row">
        <span class="status s-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span>
        <span class="test-name"><?= htmlspecialchars($r['name']) ?></span>
        <span class="test-ms"><?= $r['ms'] ? $r['ms'] . 'ms' : '' ?></span>
    </div>
    <?php if ($r['message'] && in_array($r['status'], ['fail','error'])): ?>
    <div class="test-msg"><?= htmlspecialchars($r['message']) ?></div>
    <?php endif; endforeach; ?>
</div>
<?php endforeach; ?>
</div>
</body>
</html>
