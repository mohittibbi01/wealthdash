#!/usr/bin/env php
<?php
/**
 * WealthDash — tp005: JS/CSS Minifier Build Script
 *
 * Run from project root:
 *   php build.php          — minify all changed files
 *   php build.php --force  — minify all files regardless of mtime
 *   php build.php --css    — also minify CSS
 *   php build.php --clean  — delete all .min.js / .min.css files
 *
 * Output: public/js/app.min.js, public/css/app.min.css etc.
 * Layout.php automatically serves .min.js files when they exist (see below).
 *
 * No Node.js required — pure PHP regex minification.
 * For production-grade minification, install terser:
 *   npm install -g terser
 *   And this script will use it automatically if found in PATH.
 */

declare(strict_types=1);

$root     = dirname(__FILE__);
$jsDir    = $root . '/public/js';
$cssDir   = $root . '/public/css';
$force    = in_array('--force', $argv ?? []);
$doCss    = in_array('--css',   $argv ?? []);
$clean    = in_array('--clean', $argv ?? []);
$verbose  = !in_array('--quiet', $argv ?? []);

$terser   = trim(shell_exec('which terser 2>/dev/null') ?: '');
$cleanCss = trim(shell_exec('which cleancss 2>/dev/null') ?: '');

// ── Helpers ────────────────────────────────────────────────────────────────

function logMsg(string $msg, bool $verbose = true): void {
    if ($verbose) echo $msg . PHP_EOL;
}

function formatBytes(int $bytes): string {
    return $bytes > 1024 ? round($bytes / 1024, 1) . 'KB' : $bytes . 'B';
}

/**
 * Pure-PHP JS minifier — strips comments, collapses whitespace.
 * Not as good as terser but works without Node.js.
 */
function phpMinifyJs(string $src): string {
    // Remove single-line comments (careful: not inside strings or regex)
    $out = preg_replace('/(?<!["\':])\/\/[^\n\r]*/', '', $src);
    // Remove multi-line comments /* ... */ (non-greedy)
    $out = preg_replace('/\/\*[\s\S]*?\*\//', '', $out);
    // Collapse multiple blank lines
    $out = preg_replace('/\n{3,}/', "\n\n", $out);
    // Trim lines
    $lines = explode("\n", $out);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, fn($l) => $l !== '');
    return implode("\n", $lines);
}

/**
 * Pure-PHP CSS minifier.
 */
function phpMinifyCss(string $src): string {
    $out = preg_replace('/\/\*[\s\S]*?\*\//', '', $src);   // strip comments
    $out = preg_replace('/\s+/', ' ', $out);               // collapse whitespace
    $out = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $out); // remove spaces around operators
    $out = preg_replace('/;}/', '}', $out);                // remove trailing semicolons
    return trim($out);
}

// ── Clean mode ─────────────────────────────────────────────────────────────

if ($clean) {
    $deleted = 0;
    foreach (glob($jsDir  . '/*.min.js')  ?: [] as $f) { unlink($f); $deleted++; }
    foreach (glob($cssDir . '/*.min.css') ?: [] as $f) { unlink($f); $deleted++; }
    logMsg("Cleaned $deleted minified files.", $verbose);
    exit(0);
}

// ── JS Files ───────────────────────────────────────────────────────────────

$jsFiles = glob($jsDir . '/*.js') ?: [];
// Exclude already-minified files
$jsFiles = array_filter($jsFiles, fn($f) => !str_ends_with($f, '.min.js'));

$totalSaved = 0;
$built      = 0;

logMsg($terser ? "Minifier: terser (full)" : "Minifier: PHP regex (basic — install terser for better results)", $verbose);
logMsg(str_repeat('─', 60), $verbose);

foreach ($jsFiles as $src) {
    $base    = basename($src, '.js');
    $minPath = $jsDir . '/' . $base . '.min.js';

    // Skip if .min.js is newer than source (unless --force)
    if (!$force && file_exists($minPath) && filemtime($minPath) >= filemtime($src)) {
        continue;
    }

    $srcSize  = filesize($src);
    $content  = file_get_contents($src);

    if ($terser) {
        // Use terser for production-grade minification
        $tmpIn  = sys_get_temp_dir() . '/wd_build_' . getmypid() . '.js';
        $tmpOut = $tmpIn . '.min.js';
        file_put_contents($tmpIn, $content);
        $cmd = "$terser $tmpIn --compress --mangle -o $tmpOut 2>/dev/null";
        exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($tmpOut)) {
            $minified = file_get_contents($tmpOut);
        } else {
            $minified = phpMinifyJs($content);
        }
        @unlink($tmpIn); @unlink($tmpOut);
    } else {
        $minified = phpMinifyJs($content);
    }

    file_put_contents($minPath, $minified);
    $minSize  = strlen($minified);
    $saving   = $srcSize - $minSize;
    $pct      = $srcSize > 0 ? round($saving / $srcSize * 100) : 0;
    $totalSaved += $saving;
    $built++;

    logMsg(sprintf("  %-40s %s → %s (-%d%%)",
        $base . '.js',
        formatBytes($srcSize),
        formatBytes($minSize),
        $pct
    ), $verbose);
}

// ── CSS Files ─────────────────────────────────────────────────────────────

if ($doCss) {
    $cssFiles = glob($cssDir . '/*.css') ?: [];
    $cssFiles = array_filter($cssFiles, fn($f) => !str_ends_with($f, '.min.css'));

    foreach ($cssFiles as $src) {
        $base    = basename($src, '.css');
        $minPath = $cssDir . '/' . $base . '.min.css';

        if (!$force && file_exists($minPath) && filemtime($minPath) >= filemtime($src)) {
            continue;
        }

        $srcSize  = filesize($src);
        $content  = file_get_contents($src);

        if ($cleanCss) {
            $tmpIn  = sys_get_temp_dir() . '/wd_css_' . getmypid() . '.css';
            $tmpOut = $tmpIn . '.min.css';
            file_put_contents($tmpIn, $content);
            exec("$cleanCss $tmpIn -o $tmpOut 2>/dev/null", $out, $ret);
            $minified = ($ret === 0 && file_exists($tmpOut))
                ? file_get_contents($tmpOut) : phpMinifyCss($content);
            @unlink($tmpIn); @unlink($tmpOut);
        } else {
            $minified = phpMinifyCss($content);
        }

        file_put_contents($minPath, $minified);
        $minSize   = strlen($minified);
        $saving    = $srcSize - $minSize;
        $pct       = $srcSize > 0 ? round($saving / $srcSize * 100) : 0;
        $totalSaved += $saving;
        $built++;

        logMsg(sprintf("  %-40s %s → %s (-%d%%)",
            $base . '.css',
            formatBytes($srcSize),
            formatBytes($minSize),
            $pct
        ), $verbose);
    }
}

logMsg(str_repeat('─', 60), $verbose);
logMsg(sprintf("Built: %d files | Total saved: %s", $built, formatBytes($totalSaved)), $verbose);

if ($built === 0) {
    logMsg("All files up to date. Use --force to rebuild.", $verbose);
}
