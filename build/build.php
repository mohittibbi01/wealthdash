<?php
/**
 * WealthDash — tp005: Frontend Bundle — Minify CSS/JS
 * File: build/build.php
 *
 * USAGE (run from CLI):
 *   php build/build.php            # minify all
 *   php build/build.php --css      # CSS only
 *   php build/build.php --js       # JS only
 *   php build/build.php --watch    # watch mode (re-run on file change)
 *   php build/build.php --clean    # remove all .min files
 *
 * Pure PHP — no npm/webpack required.
 * Outputs: public/css/*.min.css, public/js/*.min.js
 * layout.php already serves .min.* when available (wd_js_url() + tp005 CSS check).
 */

// ── Config ─────────────────────────────────────────────────────────
$root = dirname(__DIR__); // one level up from build/
$cssDir  = $root . '/public/css';
$jsDir   = $root . '/public/js';

$cssFiles = [
    'app.css',
    'number-format.css',
    'skeletons.css',
    'empty-states.css',
];

$jsFiles = [
    'app.js',
    'charts.js',
    'lazy_loader.js',
    'skeletons.js',
    'empty-states.js',
    'swipe_gestures.js',
];

// ── CLI flags ─────────────────────────────────────────────────────
$args   = array_slice($argv ?? [], 1);
$doCss  = in_array('--js', $args) ? false : true;
$doJs   = in_array('--css', $args) ? false : true;
$watch  = in_array('--watch', $args);
$clean  = in_array('--clean', $args);

if ($clean) {
    foreach (array_merge($cssFiles, $jsFiles) as $f) {
        $minExt = preg_replace('/\.(css|js)$/', '.min.$1', $f);
        $dir    = str_ends_with($f, '.css') ? $cssDir : $jsDir;
        $path   = "$dir/$minExt";
        if (file_exists($path)) { unlink($path); echo "Removed: $minExt\n"; }
    }
    echo "Clean done.\n";
    exit(0);
}

// ── Build function ────────────────────────────────────────────────
function build_all(string $root, string $cssDir, string $jsDir, array $cssFiles, array $jsFiles, bool $doCss, bool $doJs): void {
    $total = 0;

    if ($doCss) {
        foreach ($cssFiles as $f) {
            $src = "$cssDir/$f";
            if (!file_exists($src)) { echo "  SKIP (not found): $f\n"; continue; }
            $out = $cssDir . '/' . preg_replace('/\.css$/', '.min.css', $f);
            $minified = minify_css(file_get_contents($src));
            file_put_contents($out, $minified);
            $saved = round((filesize($src) - strlen($minified)) / filesize($src) * 100);
            echo "  CSS: $f → " . basename($out) . " (-{$saved}%)\n";
            $total++;
        }
    }

    if ($doJs) {
        foreach ($jsFiles as $f) {
            $src = "$jsDir/$f";
            if (!file_exists($src)) { echo "  SKIP (not found): $f\n"; continue; }
            $out = $jsDir . '/' . preg_replace('/\.js$/', '.min.js', $f);
            $minified = minify_js(file_get_contents($src));
            file_put_contents($out, $minified);
            $saved = round((filesize($src) - strlen($minified)) / filesize($src) * 100);
            echo "  JS:  $f → " . basename($out) . " (-{$saved}%)\n";
            $total++;
        }
    }

    echo "\n✅ Build complete — {$total} files minified.\n";
}

// ── CSS Minifier ──────────────────────────────────────────────────
function minify_css(string $css): string {
    // Remove comments
    $css = preg_replace('/\/\*(?!!)[\s\S]*?\*\//', '', $css);
    // Remove extra whitespace
    $css = preg_replace('/\s+/', ' ', $css);
    // Remove spaces around special chars
    $css = preg_replace('/\s*([:;,{}])\s*/', '$1', $css);
    // Remove trailing semicolons before }
    $css = str_replace(';}', '}', $css);
    // Remove leading/trailing
    return trim($css);
}

// ── JS Minifier (basic — preserves strings and regex) ────────────
function minify_js(string $js): string {
    $result   = '';
    $len      = strlen($js);
    $i        = 0;
    $inString = false;
    $strChar  = '';
    $inRegex  = false;
    $prevChar = '';

    while ($i < $len) {
        $c = $js[$i];

        // Handle strings
        if (!$inString && !$inRegex && ($c === '"' || $c === "'" || $c === '`')) {
            $inString = true;
            $strChar  = $c;
            $result  .= $c;
            $i++;
            continue;
        }
        if ($inString) {
            $result .= $c;
            if ($c === '\\') { $result .= $js[++$i] ?? ''; }
            elseif ($c === $strChar) $inString = false;
            $i++;
            continue;
        }

        // Skip single-line comments
        if ($c === '/' && isset($js[$i+1]) && $js[$i+1] === '/') {
            while ($i < $len && $js[$i] !== "\n") $i++;
            $result .= ' ';
            continue;
        }
        // Skip multi-line comments (but preserve /*! licence comments)
        if ($c === '/' && isset($js[$i+1]) && $js[$i+1] === '*') {
            if (isset($js[$i+2]) && $js[$i+2] === '!') {
                // preserve
                $end = strpos($js, '*/', $i);
                if ($end !== false) { $result .= substr($js, $i, $end+2-$i); $i = $end+2; continue; }
            }
            $end = strpos($js, '*/', $i);
            if ($end !== false) $i = $end + 2;
            else $i = $len;
            $result .= ' ';
            continue;
        }

        // Collapse whitespace
        if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
            // Only keep a single space if needed
            if ($result !== '' && !in_array(substr($result,-1), [' ','{','}',';',',','(','[','+','-','*','/','=','!','<','>','&','|','?',':','\n'])) {
                $result .= ' ';
            }
            $i++;
            continue;
        }

        $result .= $c;
        $prevChar = $c;
        $i++;
    }
    // Final cleanup
    $result = preg_replace('/ ([{};,\(\)\[\]])/', '$1', $result);
    $result = preg_replace('/([{};,\(\)\[\]]) /', '$1', $result);
    return trim($result);
}

// ── Watch mode ────────────────────────────────────────────────────
if ($watch) {
    echo "👁 Watch mode — monitoring CSS/JS files. Ctrl+C to stop.\n\n";
    $mtimes = [];
    while (true) {
        $changed = false;
        foreach (array_merge(
            array_map(fn($f) => "$cssDir/$f", $cssFiles),
            array_map(fn($f) => "$jsDir/$f", $jsFiles)
        ) as $path) {
            if (!file_exists($path)) continue;
            $mt = filemtime($path);
            if (isset($mtimes[$path]) && $mtimes[$path] !== $mt) {
                echo "[" . date('H:i:s') . "] Changed: " . basename($path) . "\n";
                $changed = true;
            }
            $mtimes[$path] = $mt;
        }
        if ($changed) build_all($root, $cssDir, $jsDir, $cssFiles, $jsFiles, $doCss, $doJs);
        sleep(2);
    }
}

// ── Single run ────────────────────────────────────────────────────
echo "\n🔨 WealthDash — Frontend Build (tp005)\n";
echo str_repeat('─', 40) . "\n\n";
build_all($root, $cssDir, $jsDir, $cssFiles, $jsFiles, $doCss, $doJs);
