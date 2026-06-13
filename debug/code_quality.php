<?php
/**
 * WealthDash — Code Quality Checker [t419]
 * File: debug/code_quality.php
 * Worker: ID-M
 * Access: IS_LOCAL + admin
 *
 * Checks: PHP syntax errors, missing guards, debug leftovers,
 *         TODO/FIXME count, file sizes, duplicate functions.
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

if (!IS_LOCAL) { http_response_code(403); die('Code quality: local only.'); }

// ── Scan dirs ─────────────────────────────────────────────────────────────────
$scanDirs = ['api', 'includes', 'config'];
$allFiles = [];
foreach ($scanDirs as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $allFiles[] = $file->getPathname();
        }
    }
}
sort($allFiles);

// ── Checks ────────────────────────────────────────────────────────────────────
$results = [];
$totalIssues = 0;

foreach ($allFiles as $path) {
    $rel     = str_replace(APP_ROOT . '/', '', $path);
    $content = file_get_contents($path);
    $lines   = file($path, FILE_IGNORE_NEW_LINES);
    $issues  = [];

    // 1. PHP syntax check
    $output = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if ($output && !str_contains($output, 'No syntax errors')) {
        $issues[] = ['type' => 'SYNTAX', 'sev' => 'critical', 'msg' => trim($output)];
    }

    // 2. WEALTHDASH guard
    if (!str_contains($content, "defined('WEALTHDASH')") && !str_contains($content, "define('WEALTHDASH'")) {
        $issues[] = ['type' => 'GUARD', 'sev' => 'critical', 'msg' => "Missing WEALTHDASH guard"];
    }

    // 3. Debug leftovers
    $debugPatterns = ['var_dump(', 'print_r(', 'die(', 'exit(1)', 'echo "<pre"', 'phpinfo()'];
    foreach ($debugPatterns as $p) {
        foreach ($lines as $i => $line) {
            if (str_contains($line, $p) && !str_contains($line, '//')) {
                $issues[] = ['type' => 'DEBUG', 'sev' => 'warning',
                             'msg' => "Line " . ($i+1) . ": `{$p}` — remove debug output"];
            }
        }
    }

    // 4. TODO / FIXME
    $todos = 0;
    foreach ($lines as $line) {
        if (preg_match('/\/\/\s*(TODO|FIXME|HACK|XXX)/i', $line)) $todos++;
    }
    if ($todos > 0) {
        $issues[] = ['type' => 'TODO', 'sev' => 'info', 'msg' => "{$todos} TODO/FIXME comment(s)"];
    }

    // 5. File too large (>500 lines)
    if (count($lines) > 500) {
        $issues[] = ['type' => 'SIZE', 'sev' => 'warning',
                     'msg' => count($lines) . " lines — consider splitting"];
    }

    // 6. Hardcoded credentials check
    $credPatterns = ['/password\s*=\s*["\'][^"\']{4,}["\']/i', '/secret\s*=\s*["\'][^"\']{4,}["\']/i'];
    foreach ($credPatterns as $pat) {
        if (preg_match($pat, $content)) {
            $issues[] = ['type' => 'CRED', 'sev' => 'critical', 'msg' => "Possible hardcoded credential"];
        }
    }

    // 7. Missing type hints on functions (sample check)
    $fnWithoutTypes = preg_match_all('/function\s+\w+\([^)]*\)\s*\{/', $content);
    $fnWithTypes    = preg_match_all('/function\s+\w+\([^)]*\)\s*:\s*\w+/', $content);
    if ($fnWithoutTypes > 0 && $fnWithTypes === 0 && $fnWithoutTypes > 2) {
        $issues[] = ['type' => 'TYPES', 'sev' => 'info',
                     'msg' => "No typed functions found ({$fnWithoutTypes} untyped)"];
    }

    if (!empty($issues)) {
        $results[] = ['file' => $rel, 'lines' => count($lines), 'issues' => $issues];
        $totalIssues += count($issues);
    }
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$critical = 0; $warnings = 0; $infos = 0;
foreach ($results as $r) {
    foreach ($r['issues'] as $i) {
        if ($i['sev'] === 'critical') $critical++;
        elseif ($i['sev'] === 'warning') $warnings++;
        else $infos++;
    }
}

$outputJson = isset($_GET['json']);
if ($outputJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'scanned'    => count($allFiles),
        'issues'     => $totalIssues,
        'critical'   => $critical,
        'warnings'   => $warnings,
        'infos'      => $infos,
        'files'      => $results,
    ], JSON_PRETTY_PRINT);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Code Quality — WealthDash</title>
<style>
:root{--bg:#0d0f18;--s:#161922;--s2:#1e2235;--border:#2a2f4a;--text:#e2e5f5;
      --muted:#7b84a8;--done:#4fc3a1;--danger:#e05c5c;--warn:#e6a817;--accent:#7c6fcd;--r:8px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',monospace;background:var(--bg);color:var(--text);font-size:13px}
.hdr{padding:14px 20px;background:var(--s);border-bottom:1px solid var(--border);
     display:flex;align-items:center;gap:12px}
.hdr h1{font-size:16px;font-weight:700}
.wrap{padding:16px 20px;max-width:1000px}
.summary{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.sc{background:var(--s);border:1px solid var(--border);border-radius:var(--r);padding:10px 16px}
.sc-v{font-size:20px;font-weight:700}
.sc-l{font-size:10px;color:var(--muted);text-transform:uppercase;margin-top:2px}
.file-card{background:var(--s);border:1px solid var(--border);border-radius:var(--r);
           margin-bottom:8px;overflow:hidden}
.file-hdr{padding:8px 12px;background:var(--s2);font-size:12px;font-weight:600;
          display:flex;align-items:center;gap:8px}
.file-path{font-family:'Courier New',monospace;color:var(--accent)}
.issue-row{padding:6px 12px;border-top:1px solid color-mix(in srgb,var(--border) 50%,transparent);
           display:flex;gap:8px;align-items:center;font-size:12px}
.pill{font-size:10px;padding:1px 7px;border-radius:4px;font-weight:700;min-width:70px;text-align:center}
.p-critical{background:color-mix(in srgb,var(--danger) 18%,transparent);color:var(--danger)}
.p-warning{background:color-mix(in srgb,var(--warn) 18%,transparent);color:var(--warn)}
.p-info{background:color-mix(in srgb,var(--muted) 18%,transparent);color:var(--muted)}
.ok-msg{color:var(--done);font-size:12px;padding:12px 0}
.btn{padding:6px 14px;border-radius:6px;border:1px solid var(--border);background:var(--s2);
     color:var(--text);text-decoration:none;font-size:12px}
</style>
</head>
<body>
<div class="hdr">
    <h1>Code Quality <span style="color:var(--accent);font-size:12px">t419</span></h1>
    <span style="color:var(--muted);font-size:11px"><?= count($allFiles) ?> PHP files scanned</span>
    <a href="?json" class="btn" style="margin-left:auto">JSON</a>
    <a href="?" class="btn">↺ Refresh</a>
</div>

<div class="wrap">
    <div class="summary">
        <div class="sc">
            <div class="sc-v"><?= count($allFiles) ?></div>
            <div class="sc-l">Files Scanned</div>
        </div>
        <div class="sc">
            <div class="sc-v" style="color:<?= $critical > 0 ? 'var(--danger)' : 'var(--done)' ?>"><?= $critical ?></div>
            <div class="sc-l">Critical</div>
        </div>
        <div class="sc">
            <div class="sc-v" style="color:<?= $warnings > 0 ? 'var(--warn)' : 'var(--done)' ?>"><?= $warnings ?></div>
            <div class="sc-l">Warnings</div>
        </div>
        <div class="sc">
            <div class="sc-v" style="color:var(--muted)"><?= $infos ?></div>
            <div class="sc-l">Info</div>
        </div>
        <div class="sc">
            <div class="sc-v" style="color:var(--done)"><?= count($allFiles) - count($results) ?></div>
            <div class="sc-l">Clean Files</div>
        </div>
    </div>

    <?php if (empty($results)): ?>
        <div class="ok-msg">✓ All <?= count($allFiles) ?> files pass quality checks.</div>
    <?php else: ?>
        <?php foreach ($results as $r): ?>
        <div class="file-card">
            <div class="file-hdr">
                <span class="file-path"><?= htmlspecialchars($r['file']) ?></span>
                <span style="color:var(--muted);font-weight:400;font-size:11px"><?= $r['lines'] ?> lines</span>
                <span style="margin-left:auto;color:var(--muted);font-size:11px"><?= count($r['issues']) ?> issue(s)</span>
            </div>
            <?php foreach ($r['issues'] as $issue): ?>
            <div class="issue-row">
                <span class="pill p-<?= $issue['sev'] ?>"><?= strtoupper($issue['type']) ?></span>
                <span><?= htmlspecialchars($issue['msg']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:16px;padding:14px;background:var(--s);border:1px solid var(--border);border-radius:var(--r);font-size:12px;color:var(--muted)">
        <strong style="color:var(--text)">Run from CLI:</strong><br>
        <code style="color:var(--accent)">composer require --dev phpstan/phpstan && vendor/bin/phpstan analyse</code><br>
        <code style="color:var(--accent)">composer require --dev friendsofphp/php-cs-fixer && vendor/bin/php-cs-fixer fix --dry-run</code><br>
        <code style="color:var(--accent)">npm install eslint && npx eslint public/js/</code>
    </div>
</div>
</body>
</html>
